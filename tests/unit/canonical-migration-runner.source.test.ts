import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = resolve(process.cwd());
const deployWorkflow = readFileSync(resolve(root, '.github/workflows/deploy-cpanel.yml'), 'utf8');
const controlWorkflow = readFileSync(resolve(root, '.github/workflows/apply-cpanel-migrations.yml'), 'utf8');
const runner = readFileSync(resolve(root, 'api/src/Database/MigrationRunner.php'), 'utf8');
const executor = readFileSync(resolve(root, 'api/src/Database/MigrationExecutionService.php'), 'utf8');
const cli = readFileSync(resolve(root, 'api/bin/migrate.php'), 'utf8');
const worker = readFileSync(resolve(root, 'api/bin/cpanel-migration-cron.php'), 'utf8');
const apiHtaccess = readFileSync(resolve(root, 'api/.htaccess'), 'utf8');
const runtimeHtaccess = readFileSync(resolve(root, 'api/runtime/.htaccess'), 'utf8');
const migrations = readdirSync(resolve(root, 'api/migrations'))
  .filter((name) => /^\d+_[A-Za-z0-9_-]+\.sql$/.test(name))
  .sort();

describe('canonical migration runner contract', () => {
  it('discovers ordered SQL files and records a checksum ledger', () => {
    expect(runner).toContain('MigrationSourceProvider');
    expect(runner).toContain('FilesystemMigrationSourceProvider');
    expect(runner).toContain('medisa_schema_migrations');
    expect(runner).toContain('hash_equals(');
    expect(runner).toContain('beginTransaction()');
    expect(runner).toContain('rollBack()');
    expect(runner).toContain('GET_LOCK');
  });

  it('owns the contiguous 001→092 filesystem migration chain', () => {
    const numbers = migrations.map((name) => Number.parseInt(name.slice(0, 3), 10));
    expect(migrations[0]).toBe('001_initial_schema.sql');
    expect(migrations.at(-1)).toBe('092_personel_self_service_product.sql');
    expect(migrations).toHaveLength(92);
    expect(new Set(numbers).size).toBe(92);
    expect(numbers).toEqual(Array.from({ length: 92 }, (_, index) => index + 1));
    expect(migrations).toContain('074_qr_attendance_correction_and_inbox.sql');
    expect(migrations.indexOf('074_qr_attendance_correction_and_inbox.sql')).toBe(
      migrations.indexOf('075_personel_account_activation.sql') - 1,
    );
    expect(migrations.indexOf('075_personel_account_activation.sql')).toBe(
      migrations.indexOf('076_dis_kaynak_gecici_gorevlendirme.sql') - 1,
    );
    expect(migrations.indexOf('076_dis_kaynak_gecici_gorevlendirme.sql')).toBe(
      migrations.indexOf('077_legacy_role_enum_shrink.sql') - 1,
    );
    expect(migrations.indexOf('077_legacy_role_enum_shrink.sql')).toBe(
      migrations.indexOf('078_personel_sicil_sequence.sql') - 1,
    );
    expect(migrations.indexOf('078_personel_sicil_sequence.sql')).toBe(
      migrations.indexOf('079_sirket_sube_hiyerarsisi.sql') - 1,
    );
    expect(migrations.indexOf('079_sirket_sube_hiyerarsisi.sql')).toBe(
      migrations.indexOf('080_organizasyon_audit_owners.sql') - 1,
    );
    expect(migrations.indexOf('083_personel_organizasyon_degisiklik_auditleri.sql')).toBe(
      migrations.indexOf('084_gunluk_bildirim_tamamlama_header_summary.sql') - 1,
    );
    expect(migrations.indexOf('084_gunluk_bildirim_tamamlama_header_summary.sql')).toBe(
      migrations.indexOf('085_gunluk_bildirim_duzeltme_auditleri.sql') - 1,
    );
    expect(migrations.indexOf('085_gunluk_bildirim_duzeltme_auditleri.sql')).toBe(
      migrations.indexOf('086_personel_historical_exit_date_correction_auditleri.sql') - 1,
    );
    expect(migrations.indexOf('086_personel_historical_exit_date_correction_auditleri.sql')).toBe(
      migrations.indexOf('087_sube_muhasebe_yetkilileri.sql') - 1,
    );
    expect(migrations.indexOf('087_sube_muhasebe_yetkilileri.sql')).toBe(
      migrations.indexOf('088_sube_sorumlu_yoneticiler.sql') - 1,
    );
    expect(migrations.indexOf('088_sube_sorumlu_yoneticiler.sql')).toBe(
      migrations.indexOf('089_personel_legacy_account_activation.sql') - 1,
    );
    expect(migrations.indexOf('089_personel_legacy_account_activation.sql')).toBe(
      migrations.indexOf('090_sgk_isveren_bildirim_donemi_owner.sql') - 1,
    );
    expect(migrations.indexOf('090_sgk_isveren_bildirim_donemi_owner.sql')).toBe(
      migrations.indexOf('091_sgk_isveren_bildirim_donemi_reconcile.sql') - 1,
    );
    expect(migrations.indexOf('091_sgk_isveren_bildirim_donemi_reconcile.sql')).toBe(
      migrations.indexOf('092_personel_self_service_product.sql') - 1,
    );
    expect(migrations.filter((name) => name.startsWith('075_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('076_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('077_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('078_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('079_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('080_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('084_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('085_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('086_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('087_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('088_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('089_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('090_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('091_'))).toHaveLength(1);
    expect(migrations.filter((name) => name.startsWith('092_'))).toHaveLength(1);
  });

  it('keeps the runner generic without hardcoded migration version pins', () => {
    expect(runner).not.toContain('068');
    expect(runner).not.toContain('069');
    expect(cli).not.toContain('068');
    expect(cli).not.toContain('069');
    expect(worker).not.toContain('068');
    expect(worker).not.toContain('069');
    expect(controlWorkflow).not.toContain('068');
    expect(controlWorkflow).not.toContain('069');
  });

  it('supports pending-only apply and a separate schema-ready verify call', () => {
    expect(executor).toContain('MigrationRunner::run');
    expect(executor).toContain('MigrationRunner::verify');
    expect(cli).toContain('MigrationExecutionService::apply');
    expect(cli).toContain('MigrationExecutionService::verify');
    expect(runner).toContain('ensureLedgerOrder');
    expect(runner).toContain('Applied migration checksum mismatch');
  });

  it('keeps filesystem and bundled source contracts separate from runner semantics', () => {
    const filesystemProvider = readFileSync(
      resolve(root, 'api/src/Database/FilesystemMigrationSourceProvider.php'),
      'utf8',
    );
    const bundledProvider = readFileSync(
      resolve(root, 'api/src/Database/BundledMigrationSourceProvider.php'),
      'utf8',
    );
    expect(filesystemProvider).toContain("hash('sha256'");
    expect(bundledProvider).toContain('base64_decode');
    expect(bundledProvider).toContain("hash('sha256'");
    expect(runner).not.toContain('file_get_contents($migration');
  });
});

describe('SSHless cPanel cron control contract', () => {
  it('removes SSH from normal deploy and uploads the CLI worker assets', () => {
    const planner = readFileSync(
      resolve(root, 'scripts/deploy/plan-cpanel-incremental.mjs'),
      'utf8',
    );
    expect(deployWorkflow).toContain('plan-cpanel-incremental.mjs');
    expect(planner).toContain('mirror -R --verbose api/bin api/bin');
    expect(planner).toContain('mirror -R --verbose api/migrations api/migrations');
    expect(planner).toContain('put -O api/runtime api/runtime/.htaccess');
    expect(planner).toContain('glob -a rm api/public/_migration_*.php');
    expect(deployWorkflow).toContain('api/.deploy-sha');
    expect(deployWorkflow).not.toMatch(/CPANEL_SSH_/);
    expect(deployWorkflow).not.toMatch(/\bssh\b/i);
    expect(deployWorkflow).not.toContain('run-production-migrations');
    expect(deployWorkflow.indexOf('Upload dist and PHP API')).toBeLessThan(
      deployWorkflow.indexOf('Verify deployed app with anonymous'),
    );
  });

  it('uses explicit confirmation, exact SHA, atomic FTP request, and protected result polling', () => {
    expect(controlWorkflow).toContain('APPLY_CANONICAL_MIGRATIONS');
    expect(controlWorkflow).toContain('deployed_sha');
    expect(controlWorkflow).toContain('mv api/runtime/migration-control/request.');
    expect(controlWorkflow).toContain('request.pending.${REQUEST_ID}.json');
    expect(controlWorkflow).toContain('status.json');
    expect(controlWorkflow).not.toMatch(/\b(?:curl|wget)\b[^\n]*(?:migrat|schema)/i);
    expect(controlWorkflow).not.toMatch(/\b(?:mysql|mariadb|PDO)\b/i);
    expect(controlWorkflow).not.toMatch(/\b(?:SELECT|INSERT|DELETE|ALTER|CREATE TABLE)\s/i);
  });

  it('fails closed for web access, malformed requests, SHA drift, and failed retries', () => {
    expect(apiHtaccess).toMatch(/runtime/);
    expect(apiHtaccess).toContain('\\.deploy-sha');
    expect(runtimeHtaccess).toMatch(/Require all denied/);
    expect(worker).toContain("PHP_SAPI !== 'cli'");
    expect(worker).toContain('JSON_THROW_ON_ERROR');
    expect(worker).toContain('REQUEST_INVALID');
    expect(worker).toContain('DEPLOY_SHA_MISMATCH');
    expect(worker).toContain('flock(');
    expect(worker).toContain('LOCK_EX | LOCK_NB');
    expect(worker).toContain('rename($pendingPath, $processingPath)');
    expect(worker).toContain('request.pending.*.json');
    expect(worker).toContain('request.failed.');
    expect(worker.slice(worker.indexOf('request.failed.'))).not.toContain('request.pending.json');
    expect(worker).not.toMatch(/->(?:exec|query|prepare)\s*\(/i);
  });
});
