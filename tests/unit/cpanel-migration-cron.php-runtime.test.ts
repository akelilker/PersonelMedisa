import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

const root = resolve(process.cwd());
const worker = resolve(root, 'api/bin/cpanel-migration-cron.php');
const generator = resolve(root, 'scripts/generate-canonical-migration-bundle.mjs');
const bundleDirectory = resolve(root, 'api/runtime-build');

function runWorker(controlDirectory: string, deployShaPath: string, bundlePath: string): number {
  try {
    execFileSync('php', [worker], {
      cwd: root,
      env: {
        ...process.env,
        MEDISA_MIGRATION_CONTROL_DIR: controlDirectory,
        MEDISA_DEPLOY_SHA_PATH: deployShaPath,
        MEDISA_MIGRATION_BUNDLE_PATH: bundlePath,
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
    bundlePath: join(directory, 'canonical-migrations.php'),
  };
}

function writeBundle(bundlePath: string) {
  const sql = '-- test ledger bootstrap\n';
  const checksum = createHash('sha256').update(sql).digest('hex');
  writeFileSync(
    bundlePath,
    `<?php declare(strict_types=1); return [[
      'version' => '000',
      'name' => 'migration_ledger.sql',
      'checksum' => '${checksum}',
      'sql_base64' => '${Buffer.from(sql).toString('base64')}',
    ]];`,
  );
}

function writeRequest(
  controlDirectory: string,
  requestId: string,
  deployedSha: string,
  mode?: 'APPLY' | 'READ_ONLY_PREFLIGHT' | 'PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT',
) {
  writeFileSync(
    join(controlDirectory, `request.pending.${requestId}.json`),
    JSON.stringify({
      schema_version: 1,
      request_id: requestId,
      deployed_sha: deployedSha,
      requested_at: '2026-08-18T05:00:00Z',
      ...(mode ? { mode } : {}),
    }),
  );
}

describe('cPanel migration cron worker runtime', () => {
  beforeAll(() => {
    execFileSync(process.execPath, [generator, root], { stdio: 'pipe' });
  });

  afterAll(() => {
    rmSync(bundleDirectory, { recursive: true, force: true });
  });

  it('is a cheap no-op without a request', () => {
    const fixture = makeFixture();
    try {
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(0);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('publishes a heartbeat on every tick without a request and never mutates data', () => {
    const fixture = makeFixture();
    const deployedSha = 'd'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, deployedSha);
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(0);

      const heartbeatPath = join(fixture.controlDirectory, 'worker-heartbeat.json');
      const heartbeat = JSON.parse(readFileSync(heartbeatPath, 'utf8'));
      expect(heartbeat.schema_version).toBe('1');
      expect(heartbeat.deployed_sha).toBe(deployedSha);
      expect(heartbeat.updated_at).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
      expect(Object.keys(heartbeat).sort()).toEqual(['deployed_sha', 'schema_version', 'updated_at']);

      // A heartbeat tick must not create status or request lifecycle artifacts.
      const entries = readdirSync(fixture.controlDirectory);
      expect(entries).not.toContain('status.json');
      expect(entries.some((name) => name.startsWith('request.'))).toBe(false);
      expect(entries.some((name) => name.endsWith('.tmp'))).toBe(false);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('reports an unknown deploy sha in the heartbeat instead of failing the tick', () => {
    const fixture = makeFixture();
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(0);
      const heartbeat = JSON.parse(
        readFileSync(join(fixture.controlDirectory, 'worker-heartbeat.json'), 'utf8'),
      );
      expect(heartbeat.deployed_sha).toBe('unknown');
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('claims malformed requests and fails closed without retrying', () => {
    const fixture = makeFixture();
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(join(fixture.controlDirectory, 'request.pending.bad.json'), '{bad');
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(1);
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
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.reason).toBe('DEPLOY_SHA_MISMATCH');
      expect(status.stage).toBe('DEPLOY_SHA_CHECK');
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  // The database is first reached while resolving which single migration the
  // request owns, so a broken connection surfaces there — still before the
  // mandatory backup, and far before apply.
  it('executes the request in-process, classifies the failure safely, and archives once', () => {
    const fixture = makeFixture();
    const deployedSha = 'c'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeBundle(fixture.bundlePath);
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeRequest(fixture.controlDirectory, 'in-process-1', deployedSha);
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.state).toBe('FAILED');
      expect(status.reason).toBe('DB_CONNECTION_FAILED');
      expect(status.stage).toBe('TARGET_RESOLVE');
      expect(status.exit_code).toBe(1);
      expect(JSON.stringify(status)).not.toMatch(/password|dsn|stack trace/i);
      expect(readdirSync(fixture.controlDirectory).filter((name) => name.startsWith('request.failed.'))).toHaveLength(1);
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(0);
      expect(readdirSync(fixture.controlDirectory).filter((name) => name.startsWith('request.failed.'))).toHaveLength(1);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('stops an apply request before the dump exists, so apply is unreachable without one', () => {
    const fixture = makeFixture();
    const deployedSha = 'e'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeBundle(fixture.bundlePath);
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeRequest(fixture.controlDirectory, 'backup-gate', deployedSha, 'APPLY');
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.state).toBe('FAILED');
      expect(status.stage).toBe('TARGET_RESOLVE');
      expect(status.mode).toBe('APPLY');
      expect(status.backup_readback).toBeUndefined();
      expect(status.applied_versions).toBeUndefined();
      expect(JSON.stringify(status)).not.toMatch(/password|dsn|stack trace/i);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('never applies or backs anything up in preflight mode and publishes no partial report', () => {
    const fixture = makeFixture();
    const deployedSha = 'f'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeBundle(fixture.bundlePath);
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeRequest(fixture.controlDirectory, 'preflight-1', deployedSha, 'READ_ONLY_PREFLIGHT');
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.stage).toBe('PREFLIGHT');
      expect(status.mode).toBe('READ_ONLY_PREFLIGHT');
      expect(readdirSync(fixture.controlDirectory)).not.toContain('preflight.json');
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('reaches the personel first-login preflight stage and writes no report without a database', () => {
    const fixture = makeFixture();
    const deployedSha = '1'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeBundle(fixture.bundlePath);
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeRequest(
        fixture.controlDirectory,
        'personel-preflight-1',
        deployedSha,
        'PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT',
      );
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.state).toBe('FAILED');
      expect(status.stage).toBe('PERSONEL_FIRST_LOGIN_PREFLIGHT');
      expect(status.mode).toBe('PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT');
      expect(status.exit_code).toBe(1);

      // The mode is read-only: a failed read must leave no report behind, and it
      // must not create any apply or backup artifact on the way out.
      const entries = readdirSync(fixture.controlDirectory);
      expect(entries).not.toContain('personel-first-login-preflight.json');
      expect(entries).not.toContain('preflight.json');
      expect(status.applied_versions).toBeUndefined();
      expect(status.backup_readback).toBeUndefined();
      expect(status.backup_file).toBeUndefined();
      expect(JSON.stringify(status)).not.toMatch(/password|dsn|stack trace/i);
      expect(readdirSync(fixture.controlDirectory).filter((name) => name.startsWith('request.failed.'))).toHaveLength(1);
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('has no apply counterpart for the personel first-login mode', () => {
    const fixture = makeFixture();
    const deployedSha = '2'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeFileSync(
        join(fixture.controlDirectory, 'request.pending.personel-apply.json'),
        JSON.stringify({
          schema_version: 1,
          request_id: 'personel-apply',
          deployed_sha: deployedSha,
          requested_at: '2026-08-18T05:00:00Z',
          mode: 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY',
        }),
      );
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.reason).toBe('REQUEST_INVALID');
      expect(status.stage).toBe('REQUEST_PARSE');
      expect(readdirSync(fixture.controlDirectory)).not.toContain('personel-first-login-preflight.json');
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });

  it('rejects an unknown request mode instead of guessing apply', () => {
    const fixture = makeFixture();
    const deployedSha = '9'.repeat(40);
    try {
      mkdirSync(fixture.controlDirectory, { recursive: true });
      writeFileSync(fixture.deployShaPath, deployedSha);
      writeFileSync(
        join(fixture.controlDirectory, 'request.pending.bad-mode.json'),
        JSON.stringify({
          schema_version: 1,
          request_id: 'bad-mode',
          deployed_sha: deployedSha,
          requested_at: '2026-08-18T05:00:00Z',
          mode: 'APPLY_EVERYTHING',
        }),
      );
      expect(runWorker(fixture.controlDirectory, fixture.deployShaPath, fixture.bundlePath)).toBe(1);
      const status = JSON.parse(readFileSync(join(fixture.controlDirectory, 'status.json'), 'utf8'));
      expect(status.reason).toBe('REQUEST_INVALID');
      expect(status.stage).toBe('REQUEST_PARSE');
    } finally {
      rmSync(fixture.directory, { recursive: true, force: true });
    }
  });
});
