import { execFileSync } from 'node:child_process';
import {
  mkdtempSync,
  mkdirSync,
  readFileSync,
  readdirSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = resolve(process.cwd());
const worker = resolve(root, 'api/bin/cpanel-migration-cron.php');

function runWorker(
  controlDirectory: string,
  deployShaPath: string,
  migrationScript: string,
  extra: Record<string, string> = {},
): number {
  try {
    execFileSync('php', [worker], {
      cwd: root,
      env: {
        ...process.env,
        MEDISA_MIGRATION_CONTROL_DIR: controlDirectory,
        MEDISA_DEPLOY_SHA_PATH: deployShaPath,
        MEDISA_MIGRATION_SCRIPT: migrationScript,
        ...extra,
      },
      stdio: 'ignore',
    });
    return 0;
  } catch (error) {
    const status = (error as { status?: number }).status;
    return typeof status === 'number' ? status : 1;
  }
}

function makeFixture() {
  const directory = mkdtempSync(join(tmpdir(), 'medisa-cron-'));
  const controlDirectory = join(directory, 'migration-control');
  const deployShaPath = join(directory, '.deploy-sha');
  const migrationScript = join(directory, 'fake-migrate.php');
  writeFileSync(
    migrationScript,
    `<?php
$log = getenv('MEDISA_FAKE_MIGRATION_LOG');
file_put_contents($log, ($argv[1] ?? 'apply') . PHP_EOL, FILE_APPEND);
if (getenv('MEDISA_FAKE_MIGRATION_STDOUT') !== false) {
  fwrite(STDOUT, getenv('MEDISA_FAKE_MIGRATION_STDOUT'));
}
if (getenv('MEDISA_FAKE_MIGRATION_STDERR') !== false) {
  fwrite(STDERR, getenv('MEDISA_FAKE_MIGRATION_STDERR'));
}
if (getenv('MEDISA_FAKE_MIGRATION_FAIL') === '1') { exit(1); }
`,
  );
  return { directory, controlDirectory, deployShaPath, migrationScript };
}

function writeRequest(controlDirectory: string, requestId: string, deployedSha: string) {
  writeFileSync(
    join(controlDirectory, `request.pending.${requestId}.json`),
    JSON.stringify({
      schema_version: 1,
      request_id: requestId,
      deployed_sha: deployedSha,
      requested_at: '2026-08-17T20:00:00Z',
    }),
  );
}

describe('cPanel migration cron worker runtime', () => {
  it('is a cheap no-op without a request', () => {
    const fixture = makeFixture();
    try {
      expect(
        runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.migrationScript),
      ).toBe(0);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('claims malformed requests and fails closed without retrying', () => {
    const fixture = makeFixture();
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(join(fixture.controlDirectory, 'request.pending.bad.json'), '{bad');
      expect(
        runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.migrationScript),
      ).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.state).toBe('FAILED');
      expect(status.reason).toBe('REQUEST_INVALID');
      expect(status.stage).toBe('REQUEST_PARSE');
      expect(status.exit_code).toBe(1);
      expect(readdirSync(fixture.controlDirectory).some((name) => name.startsWith('request.failed.'))).toBe(
        true,
      );
      expect(readdirSync(fixture.controlDirectory).some((name) => name.startsWith('request.pending.'))).toBe(
        false,
      );
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('rejects deployed SHA drift before invoking the generic runner', () => {
    const fixture = makeFixture();
    const deployedSha = 'a'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, 'b'.repeat(40));
      writeRequest(fixture.controlDirectory, 'sha-drift', deployedSha);
      const logPath = join(fixture.directory, 'calls.log');
      expect(
        runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.migrationScript, {
          MEDISA_FAKE_MIGRATION_LOG: logPath,
        }),
      ).toBe(1);
      expect(() => readFileSync(logPath, 'utf8')).toThrow();
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.reason).toBe('DEPLOY_SHA_MISMATCH');
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('runs apply and verify once, then reruns as a no-op', () => {
    const fixture = makeFixture();
    const deployedSha = 'c'.repeat(40);
    const logPath = join(fixture.directory, 'calls.log');
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeRequest(fixture.controlDirectory, 'success-1', deployedSha);
      expect(
        runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.migrationScript, {
          MEDISA_FAKE_MIGRATION_LOG: logPath,
        }),
      ).toBe(0);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.state).toBe('SUCCEEDED');
      expect(readFileSync(logPath, 'utf8').trim().split(/\r?\n/)).toEqual(['apply', '--verify']);
      expect(
        runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.migrationScript, {
          MEDISA_FAKE_MIGRATION_LOG: logPath,
        }),
      ).toBe(0);
      expect(readFileSync(logPath, 'utf8').trim().split(/\r?\n/)).toEqual(['apply', '--verify']);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('archives a failed runner request without blind retry', () => {
    const fixture = makeFixture();
    const deployedSha = 'd'.repeat(40);
    const logPath = join(fixture.directory, 'calls.log');
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeRequest(fixture.controlDirectory, 'failed-1', deployedSha);
      expect(
        runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.migrationScript, {
          MEDISA_FAKE_MIGRATION_LOG: logPath,
          MEDISA_FAKE_MIGRATION_FAIL: '1',
          MEDISA_FAKE_MIGRATION_STDERR: 'migration_apply=failed reason_code=MIGRATION_APPLY_FAILED\n',
        }),
      ).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.state).toBe('FAILED');
      expect(status.reason).toBe('MIGRATION_APPLY_FAILED');
      expect(status.stage).toBe('APPLY');
      expect(status.exit_code).toBe(1);
      expect(status.detail).toContain('reason_code=MIGRATION_APPLY_FAILED');
      expect(readdirSync(fixture.controlDirectory).some((name) => name.startsWith('request.failed.'))).toBe(
        true,
      );
      expect(readdirSync(fixture.controlDirectory).some((name) => name.startsWith('request.pending.'))).toBe(
        false,
      );
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('captures bounded safe child output and classifies unknown failures', () => {
    const fixture = makeFixture();
    const deployedSha = 'e'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeRequest(fixture.controlDirectory, 'unknown-1', deployedSha);
      expect(
        runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.migrationScript, {
          MEDISA_FAKE_MIGRATION_LOG: join(fixture.directory, 'calls.log'),
          MEDISA_FAKE_MIGRATION_FAIL: '1',
          MEDISA_FAKE_MIGRATION_STDOUT: 'safe stdout\n',
          MEDISA_FAKE_MIGRATION_STDERR: `${'x'.repeat(12000)}\n`,
        }),
      ).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.reason).toBe('UNKNOWN_MIGRATION_FAILURE');
      expect(status.stage).toBe('APPLY');
      expect(status.detail ?? '').not.toContain('x'.repeat(12000));
      expect(JSON.stringify(status)).not.toMatch(/password|dsn|stack trace/i);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });
});
