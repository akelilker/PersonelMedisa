import { readFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = resolve(process.cwd());
const workflow = readFileSync(resolve(root, '.github/workflows/deploy-cpanel.yml'), 'utf8');
const runner = readFileSync(resolve(root, 'api/src/Database/MigrationRunner.php'), 'utf8');
const cli = readFileSync(resolve(root, 'api/bin/migrate.php'), 'utf8');
const remoteRunner = readFileSync(resolve(root, 'api/bin/run-production-migrations.sh'), 'utf8');
const migrations = readdirSync(resolve(root, 'api/migrations'))
  .filter((name) => /^\d+_[A-Za-z0-9_-]+\.sql$/.test(name))
  .sort();

describe('canonical migration runner contract', () => {
  it('discovers ordered SQL files and records a checksum ledger', () => {
    expect(runner).toContain("glob(rtrim($migrationDirectory");
    expect(runner).toContain('usort(');
    expect(runner).toContain('medisa_schema_migrations');
    expect(runner).toContain("hash_file('sha256'");
    expect(runner).toContain('hash_equals(');
    expect(runner).toContain('beginTransaction()');
    expect(runner).toContain('rollBack()');
    expect(runner).toContain('GET_LOCK');
  });

  it('keeps migration 068 generic and present without changing historical files', () => {
    expect(readFileSync(resolve(root, 'api/src/Database/migration_ledger.sql'), 'utf8')).toContain(
      'medisa_schema_migrations',
    );
    expect(migrations).toContain('068_sgk_actor_identity_lifecycle_audit.sql');
    expect(runner).not.toContain('068');
    expect(cli).not.toContain('068');
    expect(remoteRunner).not.toContain('068');
  });

  it('provides a CLI apply and schema-ready verification path', () => {
    expect(cli).toContain('MigrationRunner::run');
    expect(cli).toContain('MigrationRunner::verify');
    expect(cli).toContain("exit(1)");
    expect(remoteRunner).toContain('api/bin/migrate.php');
    expect(remoteRunner).toContain('--verify');
  });
});

describe('secure cPanel migration deploy contract', () => {
  it('uploads migrations and runner before invoking SSH migration stage', () => {
    expect(workflow).toContain('mirror -R --verbose api/bin api/bin');
    expect(workflow).toContain('mirror -R --verbose api/migrations api/migrations');
    const uploadIndex = workflow.indexOf('mirror -R --verbose api/migrations api/migrations');
    const sshIndex = workflow.indexOf('Apply pending migrations over pinned SSH');
    const smokeIndex = workflow.indexOf('Verify deployed app with anonymous');
    expect(uploadIndex).toBeGreaterThanOrEqual(0);
    expect(sshIndex).toBeGreaterThan(uploadIndex);
    expect(smokeIndex).toBeGreaterThan(sshIndex);
  });

  it('requires key-based SSH and pinned host verification', () => {
    for (const name of [
      'CPANEL_SSH_HOST',
      'CPANEL_SSH_PORT',
      'CPANEL_SSH_USER',
      'CPANEL_SSH_PRIVATE_KEY',
      'CPANEL_SSH_KNOWN_HOSTS',
    ]) {
      expect(workflow).toContain(`secrets.${name}`);
    }
    expect(workflow).toContain('StrictHostKeyChecking=yes');
    expect(workflow).toContain('UserKnownHostsFile=');
    expect(workflow).toContain('IdentitiesOnly=yes');
    expect(workflow).not.toContain('StrictHostKeyChecking=no');
    expect(workflow).not.toContain('sshpass');
    expect(workflow).not.toContain('migration_068');
    expect(workflow).not.toMatch(/curl[^\n]*(?:migrat|schema)/i);
  });
});
