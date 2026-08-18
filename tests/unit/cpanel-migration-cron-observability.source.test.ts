import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const worker = readFileSync(
  resolve(process.cwd(), 'api/bin/cpanel-migration-cron.php'),
  'utf8',
);
const migrate = readFileSync(resolve(process.cwd(), 'api/bin/migrate.php'), 'utf8');

describe('cPanel migration worker observability contract', () => {
  it('captures bounded child stdout/stderr and exposes structured results', () => {
    expect(worker).toContain('MIGRATION_WORKER_OUTPUT_LIMIT');
    expect(worker).toContain("'stdout'");
    expect(worker).toContain("'stderr'");
    expect(worker).toContain('readBoundedOutput');
    expect(worker).toContain('proc_open');
    expect(worker).toContain("'detail'");
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
    expect(migrate).toContain('reason_code=');
  });

  it('redacts sensitive diagnostics and preserves control-plane boundaries', () => {
    expect(worker).toMatch(/password\|passwd\|secret\|token\|dsn/i);
    expect(worker).not.toMatch(/getenv\([^)]*(?:PASSWORD|DB_|SECRET|TOKEN)/i);
    expect(worker).not.toContain('068');
    expect(worker).not.toMatch(/\b(?:ssh|curl|wget)\b/i);
    expect(worker).not.toMatch(/\b(?:mysql|psql|sqlite3)\b/i);
  });
});
