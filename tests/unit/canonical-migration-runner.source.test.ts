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

  it('keeps the runner generic and migration tip data-driven', () => {
    expect(migrations.at(-1)).toBe('070_offline_mutation_idempotency.sql');
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
    expect(deployWorkflow).toContain('mirror -R --verbose api/bin api/bin');
    expect(deployWorkflow).toContain('mirror -R --verbose api/migrations api/migrations');
    expect(deployWorkflow).toContain('put -O api ${RUNNER_TEMP}/.deploy-sha');
    expect(deployWorkflow).toContain('put -O api/runtime api/runtime/.htaccess');
    expect(deployWorkflow).toContain('rm api/public/_migration_*.php');
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
