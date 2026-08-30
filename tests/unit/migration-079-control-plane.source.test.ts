import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (relativePath: string) => readFileSync(resolve(process.cwd(), relativePath), 'utf8');

const migration = read('api/migrations/079_aylik_kapanis_sube_scope_and_actor.sql');
const worker = read('api/bin/cpanel-migration-cron.php');
const preflightOwner = read('api/src/Database/MigrationPreflightReport.php');
const backupOwner = read('api/src/Database/MigrationBackupService.php');
const diagnostics = read('.github/workflows/ops-migration-worker-diagnostics.yml');
const apply = read('.github/workflows/apply-cpanel-migrations.yml');

describe('migration 079 key transition order', () => {
  it('creates and asserts the composite unique key before dropping the legacy one', () => {
    const addComposite = migration.indexOf('ADD UNIQUE KEY uq_aylik_kapanis_state_ay_sube (ay, sube_id)');
    const assertComposite = migration.indexOf('composite closing state key missing before legacy drop');
    const dropLegacy = migration.indexOf('DROP INDEX uq_aylik_kapanis_state_ay');

    expect(addComposite).toBeGreaterThan(0);
    expect(assertComposite).toBeGreaterThan(addComposite);
    expect(dropLegacy).toBeGreaterThan(assertComposite);
  });

  it('requires the composite key to be really unique on really both columns', () => {
    expect(migration).toContain("INDEX_NAME = 'uq_aylik_kapanis_state_ay_sube'\n    AND NON_UNIQUE = 0");
    expect(migration).toContain("AND COLUMN_NAME IN ('ay', 'sube_id')");
    expect(migration).toContain('@p079_composite_cols <> 2');
    expect(migration).toContain('@p079_cols <> 7 OR @p079_uq <> 2 OR @p079_legacy_uq <> 0');
  });

  it('stays additive and never writes a closing, approval or personel row', () => {
    expect(migration).toContain('sube_id INT UNSIGNED NOT NULL DEFAULT 0');
    for (const column of [
      'bolum_onay_actor_user_id INT UNSIGNED NULL',
      'bolum_onay_actor_identity_id INT UNSIGNED NULL',
      'bolum_onay_at DATETIME(3) NULL',
      'kapanis_actor_user_id INT UNSIGNED NULL',
      'kapanis_actor_identity_id INT UNSIGNED NULL',
      'kapanis_at DATETIME(3) NULL',
    ]) {
      expect(migration).toContain(column);
    }
    expect(migration).not.toMatch(/\bINSERT\s+INTO\b/i);
    expect(migration).not.toMatch(/\bUPDATE\s+aylik_/i);
    expect(migration).not.toMatch(/\bDELETE\s+FROM\b/i);
    expect(migration).not.toMatch(/\bTRUNCATE\b/i);
    expect(migration).not.toMatch(/\bDROP\s+TABLE\b/i);
  });

  it('keeps every step guarded by information_schema so a rerun resumes', () => {
    const guards = migration.match(/SET @p079_sql := IF\(/g) ?? [];
    expect(guards.length).toBeGreaterThanOrEqual(7);
    expect(migration.match(/PREPARE p079_stmt FROM @p079_sql;/g)?.length).toBe(guards.length);
    expect(migration.match(/DEALLOCATE PREPARE p079_stmt;/g)?.length).toBe(guards.length);
  });
});

describe('migration worker control-plane stages', () => {
  it('supports exactly the apply and read-only preflight request modes', () => {
    expect(worker).toContain("'/^(APPLY|READ_ONLY_PREFLIGHT)$/'");
    expect(worker).toContain(": 'APPLY';");
  });

  it('runs the mandatory backup stage before apply and never the other way round', () => {
    const backupStage = worker.indexOf("$stage = 'BACKUP';");
    const backupCall = worker.indexOf('MigrationBackupService::create');
    const applyStage = worker.indexOf("$stage = 'APPLY';");
    const applyCall = worker.indexOf('MigrationExecutionService::apply');

    expect(backupStage).toBeGreaterThan(0);
    expect(backupCall).toBeGreaterThan(backupStage);
    expect(applyStage).toBeGreaterThan(backupCall);
    expect(applyCall).toBeGreaterThan(applyStage);
  });

  it('never applies or backs up on the preflight path', () => {
    const preflightBlock = worker.slice(
      worker.indexOf("if ($mode === 'READ_ONLY_PREFLIGHT') {"),
      worker.indexOf("$stage = 'BACKUP';"),
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

  it('keeps the flock handle and never deletes worker.lock', () => {
    expect(worker).toContain('flock($lockHandle, LOCK_UN)');
    expect(worker).not.toMatch(/unlink\(\$lockPath\)/);
  });
});

describe('read-only preflight owner', () => {
  it('only reads: no write statement reaches the production database', () => {
    expect(preflightOwner).not.toMatch(/\b(INSERT INTO|UPDATE |DELETE FROM|ALTER TABLE|DROP |TRUNCATE|CREATE TABLE)\b/);
    expect(preflightOwner).toContain('information_schema.COLUMNS');
    expect(preflightOwner).toContain('information_schema.STATISTICS');
  });

  it('reports aggregates and never selects a personal column', () => {
    expect(preflightOwner).not.toContain('ad_soyad');
    expect(preflightOwner).not.toContain('sicil_no');
    expect(preflightOwner).not.toMatch(/SELECT \*/);
    for (const guard of [
      'ozet_sube_null_rows',
      'ozet_sube_orphan_rows',
      'ozet_duplicate_ay_sube_personel',
      'state_duplicate_ay',
      'state_rows_expected_after_079',
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
      'APPLIED_TIP_NOT_078',
      'PENDING_NOT_ONLY_079',
      'MIGRATION_CHECKSUM_MISMATCH',
      'MIGRATION_LEDGER_GAP',
      'PREIMAGE_ACTOR_COLUMNS_ALREADY_PRESENT',
      'PREIMAGE_LEGACY_UNIQUE_MISSING',
      'STATE_DUPLICATE_AY_BLOCKS_COMPOSITE_KEY',
    ]) {
      expect(preflightOwner).toContain(reason);
    }
  });
});

describe('backup owner', () => {
  it('backs up exactly the rollback scope', () => {
    expect(backupOwner).toContain("'aylik_kapanis_state'");
    expect(backupOwner).toContain("'aylik_ozet_satirlari'");
    expect(backupOwner).toContain("'medisa_schema_migrations'");
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
      'PROD_TIP_UNEXPECTED',
      'PENDING_NOT_ONLY_079',
      'PENDING_CHECKSUM_MISMATCH',
      'APPLIED_MIGRATION_MODIFIED',
      'MIGRATION_LEDGER_GAP',
      'PREFLIGHT_BLOCKERS_PRESENT',
      'DATA_GUARD_ORPHAN_SUBE_ROWS',
      'DATA_GUARD_DUPLICATE_ROWS',
      'DATA_GUARD_DUPLICATE_STATE_MONTHS',
    ]) {
      expect(apply).toContain(reason);
    }
  });

  it('runs the gate before the request is ever written', () => {
    const gateIndex = apply.indexOf('Verify read-only preflight evidence');
    const uploadIndex = apply.indexOf('Upload one atomic migration request');
    expect(gateIndex).toBeGreaterThan(0);
    expect(uploadIndex).toBeGreaterThan(gateIndex);
    expect(apply).toContain('EXPECTED_PENDING: "079_aylik_kapanis_sube_scope_and_actor.sql"');
    expect(apply).toContain('EXPECTED_PROD_TIP: "078"');
  });

  it('never turns into bulk migration, direct SQL or manual FTP editing', () => {
    expect(apply).not.toMatch(/\b(?:mysql|psql|sqlite3|phpmyadmin)\b/i);
    expect(apply).not.toMatch(/put[^\n]*api\/migrations/);
    expect(diagnostics).not.toMatch(/put[^\n]*api\/migrations/);
    expect(apply).toContain('assert_backup_evidence');
    expect(apply).toContain('MIGRATION_BACKUP_EVIDENCE=VERIFIED');
  });
});
