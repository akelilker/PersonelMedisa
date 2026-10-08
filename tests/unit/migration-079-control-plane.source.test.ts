import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (relativePath: string) => readFileSync(resolve(process.cwd(), relativePath), 'utf8');

const MIGRATION_NAME = '079_sirket_sube_hiyerarsisi.sql';
const WITHDRAWN_NAME = '079_aylik_kapanis_sube_scope_and_actor.sql';

const migration = read(`api/migrations/${MIGRATION_NAME}`);
const worker = read('api/bin/cpanel-migration-cron.php');
const preflightOwner = read('api/src/Database/MigrationPreflightReport.php');
const backupOwner = read('api/src/Database/MigrationBackupService.php');
const diagnostics = read('.github/workflows/ops-migration-worker-diagnostics.yml');
const apply = read('.github/workflows/apply-cpanel-migrations.yml');
const runner = read('api/src/Database/MigrationRunner.php');

describe('migration 079 slot', () => {
  it('holds exactly one 079 and no trace of the withdrawn monthly-close migration', () => {
    const migrations = readdirSync(resolve(process.cwd(), 'api/migrations')).filter((name) =>
      name.endsWith('.sql'),
    );
    const slot079 = migrations.filter((name) => name.startsWith('079'));

    expect(slot079).toEqual([MIGRATION_NAME]);
    expect(migrations).not.toContain(WITHDRAWN_NAME);
  });

  it('creates the company root and the four hierarchy relations additively', () => {
    expect(migration).toContain('CREATE TABLE IF NOT EXISTS sirketler');
    expect(migration).toContain('UNIQUE KEY uq_sirketler_kod (kod)');
    expect(migration).toContain('UNIQUE KEY uq_sirketler_ad (ad)');
    expect(migration).toContain('ALTER TABLE subeler ADD COLUMN sirket_id INT UNSIGNED NULL');
    expect(migration).toContain('ALTER TABLE sgk_isverenler ADD COLUMN sirket_id INT UNSIGNED NULL');
    expect(migration).toContain('ALTER TABLE calisma_lokasyonlari ADD COLUMN sube_id INT UNSIGNED NULL');
    expect(migration).toContain('CREATE TABLE IF NOT EXISTS user_sirketler');
    expect(migration).toContain('CREATE TABLE IF NOT EXISTS user_sgk_isverenler');
  });

  it('keeps every organisation reference RESTRICT and only cascades the user link', () => {
    for (const fk of [
      'fk_subeler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT',
      'fk_sgk_isverenler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT',
      'fk_calisma_lokasyonlari_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT',
      'fk_user_sirketler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT',
      'fk_user_sgk_isverenler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id) ON DELETE RESTRICT',
    ]) {
      expect(migration).toContain(fk);
    }
    expect(migration).toContain(
      'fk_user_sirketler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
    );
    expect(migration).toContain(
      'fk_user_sgk_isverenler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE',
    );
  });

  it('scopes user grants by composite primary key so an assignment cannot duplicate', () => {
    expect(migration).toContain('PRIMARY KEY (user_id, sirket_id)');
    expect(migration).toContain('PRIMARY KEY (user_id, sgk_isveren_id)');
  });

  it('writes no data at all: no seed, no mapping, no backfill, no rename', () => {
    expect(migration).not.toMatch(/\bINSERT\s+INTO\b/i);
    expect(migration).not.toMatch(/\bUPDATE\s+\w+\s+SET\b/i);
    expect(migration).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migration).not.toMatch(/\bTRUNCATE\b/i);
    expect(migration).not.toMatch(/\bDROP\s+TABLE\b/i);
    expect(migration).not.toMatch(/\bDROP\s+INDEX\b/i);
    expect(migration).not.toMatch(/\bRENAME\s+(?:TABLE|COLUMN)\b/i);
  });

  it('leaves the monthly-closing owners and personeller.sirket_id untouched', () => {
    expect(migration).not.toContain('aylik_kapanis_state');
    expect(migration).not.toContain('aylik_ozet_satirlari');
    expect(migration).not.toMatch(/ALTER TABLE personeller/i);
    // `tam_ad` stays a derived read-model value; it must never become a column.
    expect(migration).not.toMatch(/(?:ADD\s+COLUMN\s+|^\s*)tam_ad\b/im);
  });

  it('defers the branch-name hardening that needs mapped production rows', () => {
    expect(migration).not.toContain('uq_subeler_sirket_ad');
    expect(migration).not.toMatch(/ADD UNIQUE KEY[^\n]*subeler/i);
  });

  it('fails closed on incompatible drift instead of reporting success', () => {
    for (const blocker of [
      'PACK079_BLOCKER: organisation owner tables missing',
      'PACK079_BLOCKER: incompatible hierarchy column already present',
      'PACK079_BLOCKER: hierarchy foreign key points at the wrong parent',
      'PACK079_BLOCKER: incompatible sirketler table already present',
      'PACK079_BLOCKER: sirket-sube hierarchy readback failed',
    ]) {
      expect(migration).toContain(blocker);
    }
  });

  it('keeps every step guarded by information_schema so a rerun resumes', () => {
    const guards = migration.match(/SET @p079_sql := IF\(/g) ?? [];
    expect(guards.length).toBeGreaterThanOrEqual(7);
    expect(migration.match(/PREPARE p079_stmt FROM @p079_sql;/g)?.length).toBe(guards.length);
    expect(migration.match(/DEALLOCATE PREPARE p079_stmt;/g)?.length).toBe(guards.length);
  });
});

describe('migration worker control-plane stages', () => {
  it('supports exactly the canonical request modes and still defaults to apply', () => {
    expect(worker).toContain("'/^(APPLY|READ_ONLY_PREFLIGHT|READ_ONLY_ORGANIZATION_INVENTORY'");
    expect(worker).toContain("|ORGANIZATION_MAPPING_PREFLIGHT|ORGANIZATION_MAPPING_APPLY|FINAL_CLOSE_PREFLIGHT|FINAL_CLOSE_APPLY'");
    expect(worker).toContain("|PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT|PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY|KALICI_SIL_MIGRATION_PREFLIGHT|KALICI_SIL_MIGRATION_APPLY)$/'");
    expect(worker).toContain(": 'APPLY';");
  });

  it('runs the mandatory backup stage before apply and never the other way round', () => {
    // Scoped to the migration apply path: the worker also owns the organisation
    // mapping stages, which have their own backup call earlier in the file.
    const backupStage = worker.indexOf("$stage = 'BACKUP';");
    expect(backupStage).toBeGreaterThan(0);

    const migrationApply = worker.slice(backupStage);
    const backupCall = migrationApply.indexOf('MigrationBackupService::create($pdo');
    const applyStage = migrationApply.indexOf("$stage = 'APPLY';");
    const applyCall = migrationApply.indexOf('MigrationExecutionService::apply');

    expect(backupCall).toBeGreaterThan(0);
    expect(applyStage).toBeGreaterThan(backupCall);
    expect(applyCall).toBeGreaterThan(applyStage);
  });

  it('never applies or backs up on the preflight path', () => {
    const preflightBlock = worker.slice(
      worker.indexOf("if ($mode === 'READ_ONLY_PREFLIGHT') {"),
      worker.indexOf("if ($mode === 'READ_ONLY_ORGANIZATION_INVENTORY') {"),
    );
    expect(preflightBlock).toContain('MigrationPreflightReport::collect');
    expect(preflightBlock).not.toContain('MigrationExecutionService::apply');
    expect(preflightBlock).not.toContain('MigrationBackupService');
  });

  it('publishes backup evidence on a successful apply', () => {
    expect(worker).toContain("'backup_file' => (string) $backup['file']");
    expect(worker).toContain("'backup_sha256' => (string) $backup['sha256']");
    expect(worker).toContain("'backup_readback' => (string) $backup['readback']");
  });

  it('applies exactly the authorized migration and refuses to skip ahead', () => {
    expect(worker).toContain("requireString($request, 'target_version', '/^\\d{3}$/')");
    expect(worker).toContain("$stage = 'TARGET_RESOLVE';");
    expect(worker).toContain("throw new RuntimeException('TARGET_NOT_NEXT_PENDING');");
    expect(worker).toContain("throw new RuntimeException('TARGET_ALREADY_APPLIED');");
    expect(worker).toContain(
      'MigrationExecutionService::apply($pdo, $migrationSource, $baseline, $targetVersion)',
    );
    expect(worker).toContain(
      'MigrationExecutionService::verify($pdo, $migrationSource, $targetVersion)',
    );
    // Each targeted request names its own dump, so a round keeps one backup per
    // migration instead of a single dump covering both applies.
    expect(worker).toContain('$backupLabel = $targetVersion ?? $migrationTip;');
    expect(worker).toContain(
      'MigrationBackupService::create($pdo, $apiDirectory, $requestId, $backupLabel)',
    );
  });

  it('keeps the flock handle and never deletes worker.lock', () => {
    expect(worker).toContain('flock($lockHandle, LOCK_UN)');
    expect(worker).not.toMatch(/unlink\(\$lockPath\)/);
  });
});

describe('canonical runner target contract', () => {
  it('stops after the authorized version and leaves the rest pending', () => {
    expect(runner).toContain('?string $applyThroughVersion = null');
    expect(runner).toContain(
      "if (\$applyThroughVersion !== null && (int) \$version > (int) \$applyThroughVersion) {",
    );
    expect(runner).toContain('Migration target is not in the canonical chain');
  });

  it('still applies each migration in its own transaction', () => {
    const applyOne = runner.slice(runner.indexOf('private static function applyOne'));
    expect(applyOne).toContain('$pdo->beginTransaction();');
    expect(applyOne).toContain('$pdo->commit();');
    expect(applyOne).toContain('$pdo->rollBack();');
  });

  it('verify refuses both a missing and an over-applied migration', () => {
    expect(runner).toContain('Authorized migration was not applied: ');
    expect(runner).toContain('Migration applied beyond the authorized target: ');
    // A full-chain verify keeps demanding a drained chain.
    expect(runner).toContain(
      "if (\$expectedThroughVersion === null && \$pending !== []) {",
    );
  });
});

describe('read-only preflight owner', () => {
  it('only reads: no write statement reaches the production database', () => {
    expect(preflightOwner).not.toMatch(
      /\b(INSERT INTO|UPDATE |DELETE FROM|ALTER TABLE|DROP |TRUNCATE|CREATE TABLE)\b/,
    );
    expect(preflightOwner).toContain('information_schema.COLUMNS');
    expect(preflightOwner).toContain('information_schema.STATISTICS');
  });

  it('derives canonical migration facts and rejects the withdrawn migration', () => {
    expect(preflightOwner).toContain("array_column($bundle['migrations'], 'version')");
    expect(preflightOwner).not.toContain('EXPECTED_APPLIED_TIP');
    expect(preflightOwner).not.toContain('ROUND_MIGRATIONS');
    expect(preflightOwner).toContain(`WITHDRAWN_MIGRATION_NAME = '${WITHDRAWN_NAME}'`);
    expect(preflightOwner).toContain('WITHDRAWN_079_PRESENT_IN_SOURCE');
    expect(preflightOwner).toContain('WITHDRAWN_079_STRUCTURE_PRESENT');
  });

  it('drops the monthly-close specific guards it no longer owns', () => {
    for (const retired of [
      'ozet_sube_null_rows',
      'ozet_duplicate_ay_sube_personel',
      'STATE_DUPLICATE_AY_BLOCKS_COMPOSITE_KEY',
      'PREIMAGE_ACTOR_COLUMNS_ALREADY_PRESENT',
      'PREIMAGE_LEGACY_UNIQUE_MISSING',
    ]) {
      expect(preflightOwner).not.toContain(retired);
    }
  });

  it('reports aggregates and never selects a personal column', () => {
    expect(preflightOwner).not.toContain('ad_soyad');
    expect(preflightOwner).not.toContain('sicil_no');
    expect(preflightOwner).not.toMatch(/SELECT \*/);
    for (const guard of [
      'sube_rows_expected_after_round',
      'user_sube_assignment_rows_expected_after_round',
      'user_rows_expected_after_round',
    ]) {
      expect(preflightOwner).toContain(guard);
    }
  });

  it('fails closed on an unevaluable guard instead of reporting zero', () => {
    expect(preflightOwner).toContain('return -1;');
    expect(preflightOwner).toContain('BRANCH_TABLE_UNRESOLVED');
  });

  it('locks the expected chain shape', () => {
    for (const reason of [
      'NO_PENDING_MIGRATIONS',
      'MIGRATION_CHECKSUM_MISMATCH',
      'MIGRATION_LEDGER_GAP',
      'MIGRATION_LEDGER_UNKNOWN_VERSION',
      'PREIMAGE_OWNER_TABLE_MISSING',
      'PREIMAGE_RELATION_COLUMN_INCOMPATIBLE',
      'PREIMAGE_HIERARCHY_TABLE_MISSING',
    ]) {
      expect(preflightOwner).toContain(reason);
    }
  });

  it('treats an already-created audit table or role as resumable, not as success', () => {
    expect(preflightOwner).toContain('PREIMAGE_PARTIAL_ROUND_AUDIT_TABLE_PRESENT');
    expect(preflightOwner).toContain('PREIMAGE_PREDECESSOR_AUDIT_TABLE_MISSING');
    expect(preflightOwner).toContain("PREDECESSOR_ROLE = 'IK_PERSONELI'");
  });
});

describe('backup owner', () => {
  it('backs up exactly the rollback scope of the hierarchy migration', () => {
    for (const table of [
      "'subeler'",
      "'sgk_isverenler'",
      "'calisma_lokasyonlari'",
      "'user_subeler'",
      "'medisa_schema_migrations'",
    ]) {
      expect(backupOwner).toContain(table);
    }
    expect(backupOwner).not.toContain("'aylik_ozet_satirlari'");
  });

  it('refuses any webroot-reachable or unresolvable location', () => {
    for (const reason of [
      'BACKUP_LOCATION_UNRESOLVED',
      'BACKUP_LOCATION_INSIDE_WEBROOT',
      'BACKUP_LOCATION_NOT_WRITABLE',
      'BACKUP_WRITE_FAILED',
      'BACKUP_READBACK_INCOMPLETE',
      'BACKUP_CHECKSUM_MISMATCH',
      'BACKUP_SOURCE_INCOMPLETE',
    ]) {
      expect(backupOwner).toContain(reason);
    }
    expect(backupOwner).toContain("basename($current) === 'public_html'");
  });

  it('never deletes a dump and keeps the server path out of published metadata', () => {
    expect(backupOwner).not.toMatch(/unlink\([^)]*\$path/);
    expect(backupOwner).toContain("'absolute_path' => $path");
    expect(backupOwner).toContain("'readback' => 'VERIFIED'");
  });
});

describe('control-plane workflow gates', () => {
  it('adds a read-only preflight mode to the existing diagnostics owner', () => {
    expect(diagnostics).toContain('READ_ONLY_MIGRATION_PREFLIGHT');
    expect(diagnostics).toContain('test "$CONFIRMATION" = "$MODE"');
    expect(diagnostics).toContain('mode: "READ_ONLY_PREFLIGHT"');
    expect(diagnostics).toContain("if: inputs.mode == 'READ_ONLY_MIGRATION_PREFLIGHT'");
    expect(diagnostics).toContain('PREFLIGHT_APPLY_READY=NO');
    expect(diagnostics).not.toMatch(/\b(?:mysql|psql|sqlite3)\b/i);
  });

  it('treats worker.lock existence as evidence-free instead of as an error', () => {
    expect(diagnostics).toContain('WORKER_LOCK_IS_ERROR=NO');
    expect(diagnostics).toContain('HEARTBEAT_DEPLOY_SHA_MATCH=');
    expect(diagnostics).toContain('WORKER_BUSY=');
    expect(apply).toContain('worker.lock existence is deliberately not a gate');
  });

  it('bounds every preflight field it prints', () => {
    expect(diagnostics).toContain('^[0-9a-f]{40}$');
    expect(diagnostics).toContain('^[0-9a-f]{64}$');
    expect(diagnostics).toContain('^[0-9]{3}$');
    expect(diagnostics).toContain('UNPRINTABLE');
    expect(diagnostics).toContain(
      "emit_scalar SUBE_SORUMLU_YONETICILER_TABLE_EXISTS '.schema.sube_sorumlu_yoneticiler.exists' '^(true|false)$'",
    );
    expect(diagnostics).not.toContain('cat "$report"');
  });

  it('blocks the apply request until every piece of evidence is proven', () => {
    for (const reason of [
      'DEPLOY_SHA_MISMATCH',
      'HEARTBEAT_DEPLOY_SHA_MISMATCH',
      'HEARTBEAT_STALE',
      'REMOTE_WORKER_PARITY_MISMATCH',
      'WORKER_BACKUP_STAGE_MISSING',
      'STALE_PROCESSING_REQUEST',
      'MIGRATION_CONTROL_PLANE_BUSY',
      'WORKER_BUSY',
      'PREFLIGHT_NOT_PASS',
      'PREFLIGHT_SHA_MISMATCH',
      'PREFLIGHT_STALE',
      'NO_PENDING_MIGRATIONS',
      'MIGRATION_LEDGER_UNKNOWN_VERSION',
      'TARGET_NOT_NEXT_PENDING',
      'PENDING_CHECKSUM_MISMATCH',
      'APPLIED_MIGRATION_MODIFIED',
      'MIGRATION_LEDGER_GAP',
      'PREFLIGHT_BLOCKERS_PRESENT',
      'WITHDRAWN_079_STILL_IN_SOURCE',
      'WITHDRAWN_079_PENDING',
      'DATA_GUARD_SUBE_ROW_DELTA',
      'DATA_GUARD_ASSIGNMENT_ROW_DELTA',
      'DATA_GUARD_USER_ROW_DELTA',
    ]) {
      expect(apply).toContain(reason);
    }
  });

  it('runs the gate before the request is ever written', () => {
    const gateIndex = apply.indexOf('Verify read-only preflight evidence');
    const uploadIndex = apply.indexOf('Upload one atomic migration request');
    expect(gateIndex).toBeGreaterThan(0);
    expect(uploadIndex).toBeGreaterThan(gateIndex);
    expect(apply).not.toContain('ROUND_MIGRATIONS');
    expect(apply).not.toContain('PRE_ROUND_TIP');
  });

  it('authorizes exactly one migration per request and carries it into the payload', () => {
    expect(apply).toContain('target_migration:');
    expect(apply).toContain('TARGET_MIGRATION: ${{ inputs.target_migration }}');
    expect(apply).toContain('--arg target_version "${TARGET_MIGRATION:0:3}"');
    expect(apply).toContain('target_version: $target_version');
    expect(apply).toContain('[[ "$next_pending" == "$TARGET_MIGRATION" ]] || block TARGET_NOT_NEXT_PENDING');
  });

  it('never turns into bulk migration, direct SQL or manual FTP editing', () => {
    expect(apply).not.toMatch(/\b(?:mysql|psql|sqlite3|phpmyadmin)\b/i);
    expect(apply).not.toMatch(/put[^\n]*api\/migrations/);
    expect(diagnostics).not.toMatch(/put[^\n]*api\/migrations/);
    expect(apply).toContain('assert_backup_evidence');
    expect(apply).toContain('MIGRATION_BACKUP_EVIDENCE=VERIFIED');
  });
});
