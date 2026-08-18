import { execFileSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = resolve(process.cwd());
const worker = resolve(root, 'api/bin/cpanel-migration-cron.php');

function runWorker(controlDirectory: string, deployShaPath: string): number {
  try {
    execFileSync('php', [worker], {
      cwd: root,
      env: {
        ...process.env,
        MEDISA_MIGRATION_CONTROL_DIR: controlDirectory,
        MEDISA_DEPLOY_SHA_PATH: deployShaPath,
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
  return {
    directory,
    controlDirectory: join(directory, 'migration-control'),
    deployShaPath: join(directory, '.deploy-sha'),
  };
}

function writeRequest(controlDirectory: string, requestId: string, deployedSha: string) {
  writeFileSync(
    join(controlDirectory, `request.pending.${requestId}.json`),
    JSON.stringify({
      schema_version: 1,
      request_id: requestId,
      deployed_sha: deployedSha,
      requested_at: '2026-08-18T05:00:00Z',
    }),
  );
}

describe('cPanel migration cron worker runtime', () => {
  it('is a cheap no-op without a request', () => {
    const fixture = makeFixture();
    try {
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath)).toBe(0);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('claims malformed requests and fails closed without retrying', () => {
    const fixture = makeFixture();
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(join(fixture.controlDirectory, 'request.pending.bad.json'), '{bad');
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.reason).toBe('REQUEST_INVALID');
      expect(status.stage).toBe('REQUEST_PARSE');
      expect(readdirSync(fixture.controlDirectory).some((name) => name.startsWith('request.failed.'))).toBe(true);
      expect(readdirSync(fixture.controlDirectory).some((name) => name.startsWith('request.pending.'))).toBe(false);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('rejects deployed SHA drift before in-process migration execution', () => {
    const fixture = makeFixture();
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, 'b'.repeat(40));
      writeRequest(fixture.controlDirectory, 'sha-drift', 'a'.repeat(40));
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.reason).toBe('DEPLOY_SHA_MISMATCH');
      expect(status.stage).toBe('DEPLOY_SHA_CHECK');
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('executes apply in-process, classifies the failure safely, and archives once', () => {
    const fixture = makeFixture();
    const deployedSha = 'c'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeRequest(fixture.controlDirectory, 'in-process-1', deployedSha);
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.state).toBe('FAILED');
      expect(status.reason).toBe('DB_CONNECTION_FAILED');
      expect(status.stage).toBe('APPLY');
      expect(status.exit_code).toBe(1);
      expect(JSON.stringify(status)).not.toMatch(/password|dsn|stack trace/i);
      expect(readdirSync(fixture.controlDirectory).filter((name) => name.startsWith('request.failed.'))).toHaveLength(1);
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath)).toBe(0);
      expect(readdirSync(fixture.controlDirectory).filter((name) => name.startsWith('request.failed.'))).toHaveLength(1);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });
});
