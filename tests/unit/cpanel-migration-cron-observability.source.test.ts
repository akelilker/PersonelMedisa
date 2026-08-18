import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const worker = readFileSync(
  resolve(process.cwd(), 'api/bin/cpanel-migration-cron.php'),
  'utf8',
);
const migrate = readFileSync(resolve(process.cwd(), 'api/bin/migrate.php'), 'utf8');
const executor = readFileSync(
  resolve(process.cwd(), 'api/src/Database/MigrationExecutionService.php'),
  'utf8',
);

describe('cPanel migration worker observability contract', () => {
  it('runs canonical apply and verify in-process without child execution', () => {
    expect(worker).toContain('Connection::get()');
    expect(worker).toContain('MigrationExecutionService::apply');
    expect(worker).toContain('MigrationExecutionService::verify');
    expect(worker).not.toMatch(/\b(?:proc_open|shell_exec|exec|system|passthru|tempnam)\b/);
    expect(worker).not.toContain('MEDISA_PHP_CLI_PATH');
    expect(worker).not.toContain('MEDISA_MIGRATION_SCRIPT');
    expect(worker).not.toContain("'/bin/migrate.php'");
    expect(executor).toContain('MigrationRunner::run');
    expect(executor).toContain('MigrationRunner::verify');
  });

  it('publishes safe failure stage and exit-code fields', () => {
    for (const stage of [
      'REQUEST_PARSE',
      'DEPLOY_SHA_CHECK',
      'STATUS_WRITE',
      'APPLY',
      'VERIFY',
      'REQUEST_ARCHIVE',
    ]) {
      expect(worker).toContain(`'${stage}'`);
    }
    expect(worker).toContain("'exit_code'");
    expect(worker).toContain('UNKNOWN_MIGRATION_FAILURE');
    expect(worker).toContain('MigrationWorkerFailure::fromThrowable');
    for (const reason of [
      'DB_CONNECTION_FAILED',
      'PHP_EXTENSION_MISSING',
      'MIGRATION_LEDGER_BOOTSTRAP_FAILED',
      'MIGRATION_BASELINE_REQUIRED',
      'MIGRATION_BASELINE_INVALID',
      'MIGRATION_CHECKSUM_MISMATCH',
      'MIGRATION_LEDGER_GAP',
      'MIGRATION_SOURCE_MISSING',
      'MIGRATION_LOCK_FAILED',
      'MIGRATION_APPLY_FAILED',
      'SCHEMA_VERIFY_FAILED',
      'UNKNOWN_MIGRATION_FAILURE',
    ]) {
      expect(executor).toContain(reason);
    }
    expect(migrate).toContain('MigrationExecutionService::classify');
  });

  it('redacts sensitive diagnostics and preserves control-plane boundaries', () => {
    for (const term of ['password', 'passwd', 'secret', 'token', 'dsn']) {
      expect(executor.toLowerCase()).toContain(term);
    }
    expect(executor).toContain('return null');
    expect(worker).not.toContain('068');
    expect(worker).not.toMatch(/\b(?:ssh|curl|wget)\b/i);
    expect(worker).not.toMatch(/\b(?:mysql|psql|sqlite3)\b/i);
  });

  it('keeps the request and archive control plane in the worker', () => {
    expect(worker).toContain('rename($pendingPath, $processingPath)');
    expect(worker).toContain('hash_equals($publishedSha, $deployedSha)');
    expect(worker).toContain('request.failed.');
    expect(worker).toContain('request.completed.');
    expect(worker).not.toContain('MEDISA_MIGRATION_BASELINE=068');
  });
});
