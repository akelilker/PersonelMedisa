import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

const helperPath = resolve(process.cwd(), 'scripts/deploy/cpanel-ftp-directory-lib.sh');
const migrationPath = resolve(process.cwd(), '.github/workflows/apply-cpanel-migrations.yml');
const diagnosticsPath = resolve(process.cwd(), '.github/workflows/ops-migration-worker-diagnostics.yml');

describe('cPanel FTP directory ensure contract', () => {
  it('passes the fail-closed FTP behavior matrix', () => {
    const script = resolve(process.cwd(), 'scripts/deploy/test-cpanel-ftp-directory-lib.sh');
    const bashCandidates = ['C:/Program Files/Git/bin/bash.exe', '/usr/bin/bash', 'bash'];
    let result: ReturnType<typeof spawnSync> | null = null;
    let output = '';
    for (const bin of bashCandidates) {
      const attempt = spawnSync(bin, [script], {
        cwd: process.cwd(),
        encoding: 'utf8',
        env: process.env,
      });
      if (attempt.error && (attempt.error as NodeJS.ErrnoException).code === 'ENOENT') {
        continue;
      }
      output = `${attempt.stdout ?? ''}\n${attempt.stderr ?? ''}`;
      result = attempt;
      if (attempt.status === 0 && output.includes('HARNESS_FAIL=0')) {
        break;
      }
    }

    expect(result).not.toBeNull();
    expect(result?.status, output).toBe(0);
    for (const assertion of [
      'MISSING_DIRECTORY_RC=PASS',
      'EXISTING_DIRECTORY_RC=PASS',
      'NESTED_EXISTING_PARENTS_RC=PASS',
      'EXACT_FILE_EXISTS_RACE_RC=PASS',
      'PERMISSION_DENIED_550_RC=PASS',
      'AUTH_530_RC=PASS',
      'WRONG_REMOTE_ROOT_RC=PASS',
      'OTHER_FTP_ERROR_RC=PASS',
      'REMOTE_FILE_NOT_DIRECTORY_RC=PASS',
      'UNREADABLE_EXISTING_DIRECTORY_RC=PASS',
      'INCREMENTAL_EXISTING_API_RC=PASS',
      'FULL_MIRROR_EXISTING_API_RC=PASS',
      'HARNESS_FAIL=0',
    ]) {
      expect(output).toContain(assertion);
    }
  });

  it('keeps directory creation isolated from request and production-data writes', () => {
    const helper = readFileSync(helperPath, 'utf8');

    expect(helper).toContain('ensure_cpanel_remote_directory()');
    expect(helper).toContain('ensure_cpanel_remote_directory_at_root()');
    expect(helper).toContain('cd ${remote_root}; cd ${remote_directory}; cls -la;');
    expect(helper).toContain('cd ${remote_root}; mkdir -p ${remote_directory};');
    expect(helper).toContain("550 Can't create directory: File exists");
    expect(helper).toContain('probe_cpanel_remote_directory_at_root "$remote_root" "$remote_directory"');
    expect(helper).not.toMatch(/\b(?:put|mput|mv|rm -r|mysql|psql|sqlite3|curl|php)\b/);
    expect(helper).not.toContain('cmd:fail-exit false');
    expect(helper).not.toContain('|| true');
  });

  it('blocks both request workflows when directory ensure fails', () => {
    const migration = readFileSync(migrationPath, 'utf8');
    const diagnostics = readFileSync(diagnosticsPath, 'utf8');

    for (const workflow of [migration, diagnostics]) {
      expect(workflow).toContain('source "$GITHUB_WORKSPACE/scripts/deploy/cpanel-ftp-directory-lib.sh"');
      expect(workflow).toMatch(
        /if ! ensure_cpanel_remote_directory "api\/runtime\/migration-control"; then[\s\S]*?exit 1[\s\S]*?cls -1 api\/runtime\/migration-control/,
      );
      expect(workflow).not.toContain('set cmd:fail-exit false;');
    }
  });
});
