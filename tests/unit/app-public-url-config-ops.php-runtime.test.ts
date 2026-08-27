import { mkdtempSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

const helperPath = resolve(process.cwd(), 'scripts/ops/app-public-url-config-ops.php');
const CANONICAL = 'https://www.karmotors.com.tr/personelmedisa';

function runHelper(args: string[]): { status: number | null; stdout: string; stderr: string } {
  const result = spawnSync('php', [helperPath, ...args], { encoding: 'utf8' });
  return {
    status: result.status,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
  };
}

describe('app-public-url-config-ops.php runtime', () => {
  it('prints and accepts only the immutable PersonelMedisa canonical URL', () => {
    const canonical = runHelper(['canonical']);
    expect(canonical.status).toBe(0);
    expect(canonical.stdout).toContain(`CANONICAL_APP_PUBLIC_URL=${CANONICAL}`);

    expect(runHelper(['validate-url', `--value=${CANONICAL}`]).status).toBe(0);

    const rejects = [
      'https://www.example.com/personelmedisa',
      'https://www.karmotors.com.tr',
      'https://karmotors.com.tr/personelmedisa',
      'https://www.karmotors.com.tr/other',
      'http://www.karmotors.com.tr/personelmedisa',
      'https://localhost/personelmedisa',
      'https://www.karmotors.com.tr/personelmedisa/',
      'https://www.karmotors.com.tr/personelmedisa?x=1',
      'https://www.karmotors.com.tr/personelmedisa#x',
      'https://user:pass@www.karmotors.com.tr/personelmedisa',
    ];
    for (const value of rejects) {
      const result = runHelper(['validate-url', `--value=${value}`]);
      expect(result.status, value).not.toBe(0);
      expect(result.stderr).toContain('URL_NOT_CANONICAL');
    }
  });

  it('gets and patches only app_public_url while preserving sibling secrets', () => {
    const dir = mkdtempSync(join(tmpdir(), 'app-public-url-ops-'));
    try {
      const before = join(dir, 'before.php');
      const after = join(dir, 'after.php');
      const secret = 'runtime-secret-must-remain-' + Date.now();
      writeFileSync(
        before,
        `<?php\ndeclare(strict_types=1);\nreturn [\n    'db_password' => '${secret}',\n    'jwt_secret' => 'abcdefghijklmnopqrstuvwxyz012345',\n    'app_public_url' => '',\n];\n`,
      );

      const getBefore = runHelper(['get', `--file=${before}`]);
      expect(getBefore.status).toBe(0);
      expect(getBefore.stdout).toContain('APP_PUBLIC_URL=');
      expect(getBefore.stdout).toContain('VALUE_EMPTY=YES');
      expect(getBefore.stdout).not.toContain(secret);
      expect(getBefore.stdout).not.toContain('jwt_secret');

      const patch = runHelper(['patch', `--file=${before}`, `--out=${after}`]);
      expect(patch.status, patch.stderr || patch.stdout).toBe(0);
      expect(patch.stdout).toContain('PATCH_OK=YES');
      expect(patch.stdout).toContain(`APP_PUBLIC_URL=${CANONICAL}`);
      expect(patch.stdout).toContain('UNRELATED_CONFIG_KEYS_CHANGED=0');
      expect(patch.stdout).not.toContain(secret);

      const getAfter = runHelper(['get', `--file=${after}`]);
      expect(getAfter.status).toBe(0);
      expect(getAfter.stdout).toContain(`APP_PUBLIC_URL=${CANONICAL}`);
      expect(getAfter.stdout).not.toContain(secret);

      const equal = runHelper(['assert-unrelated-equal', `--before=${before}`, `--after=${after}`]);
      expect(equal.status).toBe(0);
      expect(equal.stdout).toContain('UNRELATED_CONFIG_KEYS_CHANGED=0');

      const afterSource = readFileSync(after, 'utf8');
      expect(afterSource).toContain(secret);
      expect(afterSource).toContain(CANONICAL);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });

  it('inserts missing app_public_url without unrelated delta and rejects generic patch values', () => {
    const dir = mkdtempSync(join(tmpdir(), 'app-public-url-ops-missing-'));
    try {
      const before = join(dir, 'before.php');
      const after = join(dir, 'after.php');
      writeFileSync(before, `<?php\nreturn [\n    'db_password' => 'keep-me',\n];\n`);
      const patch = runHelper(['patch', `--file=${before}`, `--out=${after}`]);
      expect(patch.status, patch.stderr || patch.stdout).toBe(0);
      const getAfter = runHelper(['get', `--file=${after}`]);
      expect(getAfter.stdout).toContain(`APP_PUBLIC_URL=${CANONICAL}`);
      const equal = runHelper(['assert-unrelated-equal', `--before=${before}`, `--after=${after}`]);
      expect(equal.status).toBe(0);

      // --value is no longer a patch surface; leftover args must not open a generic write path.
      const withValue = runHelper([
        'patch',
        `--file=${before}`,
        `--out=${join(dir, 'evil.php')}`,
        '--value=https://www.example.com/personelmedisa',
      ]);
      expect(withValue.status).toBe(0);
      const evil = runHelper(['get', `--file=${join(dir, 'evil.php')}`]);
      expect(evil.stdout).toContain(`APP_PUBLIC_URL=${CANONICAL}`);
      expect(evil.stdout).not.toContain('example.com');
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});
