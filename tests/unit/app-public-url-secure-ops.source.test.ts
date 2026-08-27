import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

const workflowPath = resolve(process.cwd(), '.github/workflows/set-cpanel-app-public-url.yml');
const helperPath = resolve(process.cwd(), 'scripts/ops/app-public-url-config-ops.php');
const workflow = readFileSync(workflowPath, 'utf8');
const helper = readFileSync(helperPath, 'utf8');
const lines = workflow.split(/\r?\n/);

describe('set-cpanel-app-public-url workflow security contract', () => {
  it('is workflow_dispatch only with confirmation + URL inputs (no generic key/path)', () => {
    expect(workflow).toMatch(/^on:\s*$/m);
    expect(workflow).toContain('workflow_dispatch:');
    expect(workflow).not.toMatch(/^\s+push:/m);
    expect(workflow).not.toMatch(/^\s+pull_request:/m);
    expect(workflow).not.toMatch(/workflow_run:/);
    expect(workflow).toContain('confirmation:');
    expect(workflow).toContain('app_public_url:');
    expect(workflow).toContain('test "$CONFIRMATION" = "SET_APP_PUBLIC_URL"');
    expect(workflow).not.toMatch(/\binputs\.(?:key|config_key|path|remote_path|file)\b/);
    expect(workflow).not.toMatch(/description:.*\b(key|path)\b/i);
  });

  it('hard-locks the remote config path and reuses FTP_* secrets only', () => {
    expect(workflow).toContain('REMOTE_CONFIG_PATH: api/config.local.php');
    expect(workflow).toContain('test "$REMOTE_CONFIG_PATH" = "api/config.local.php"');
    expect(workflow).toContain('public_html/personelmedisa/api/config.local.php');
    for (const secret of ['FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD', 'FTP_PORT']) {
      expect(workflow).toContain(secret + ': ${{ secrets.' + secret + ' }}');
    }
    expect(workflow).toContain('test "$FTP_PORT" = "21"');
    const secretRefs = workflow.match(/\$\{\{\s*secrets\.([A-Z0-9_]+)\s*\}\}/g) || [];
    expect(secretRefs.length).toBeGreaterThan(0);
    for (const ref of secretRefs) {
      expect(ref).toMatch(/secrets\.FTP_/);
    }
  });

  it('never logs full config, never uploads artifacts, and never uses SSH/public config endpoints', () => {
    const executable = lines
      .filter((line) => !/^\s*#/.test(line))
      .join('\n');
    expect(executable).not.toMatch(/\bactions\/upload-artifact\b/);
    expect(executable).not.toMatch(/\bcat\s+\$\{?(?:BEFORE|AFTER|BACKUP|PATCHED)/);
    expect(executable).not.toMatch(/\bcat\s+"?\$WORK/);
    expect(executable).not.toMatch(/\btee\b[^\n]*config\.local/);
    const unsafeSecretEchoes = lines.filter((line) =>
      /\becho\b[^\n]*\$\{?(FTP_SERVER|FTP_USERNAME|FTP_PASSWORD|FTP_PORT)\}?/.test(line),
    );
    expect(unsafeSecretEchoes).toEqual([]);
    expect(executable).not.toMatch(/(?:^|[\s"'`])ssh(?:[\s"'`]|$)/i);
    expect(executable).not.toMatch(/(?:^|[\s"'`])scp(?:[\s"'`]|$)/i);
    expect(executable).not.toMatch(/https?:\/\/[^\s"]*config\.local\.php/i);
    expect(executable).not.toMatch(/curl[^\n]*config\.local/i);
  });

  it('owns privacy-safe get → patch → put → readback with rollback', () => {
    expect(workflow).toContain('scripts/ops/app-public-url-config-ops.php');
    expect(workflow).toContain('php "$OPS_HELPER" get --file=');
    expect(workflow).toContain('php "$OPS_HELPER" patch');
    expect(workflow).toContain('php "$OPS_HELPER" assert-unrelated-equal');
    expect(workflow).toContain('php "$OPS_HELPER" validate-url');
    expect(workflow).toContain(`get \${REMOTE_CONFIG_PATH} -o \${BEFORE}`);
    expect(workflow).toContain('put patched.config.local.php -o ${REMOTE_TMP}');
    expect(workflow).toContain('mv ${REMOTE_TMP} ${REMOTE_CONFIG_PATH}');
    expect(workflow).toContain(`get \${REMOTE_CONFIG_PATH} -o \${AFTER}`);
    expect(workflow).toContain('attempt_rollback');
    expect(workflow).toContain('put backup.config.local.php -o ${REMOTE_CONFIG_PATH}');
    expect(workflow).toContain('READBACK_MATCH=YES');
    expect(workflow).toContain('FULL_CONFIG_EXPOSED=NO');
    expect(workflow).toContain('CONFIG_ARTIFACT_UPLOADED=NO');
    expect(workflow).toContain('rm -rf "$WORK"');
  });

  it('matches deploy FTPS compatibility semantics and concurrency lock', () => {
    expect(workflow).toContain('cancel-in-progress: false');
    expect(workflow).toContain('group: cpanel-app-public-url-ops');
    expect(workflow).toContain('run_ftp_mode "explicit-ftps" "true"');
    expect(workflow).toContain('run_ftp_mode "plain-ftp" "false"');
    for (const setting of [
      'set ssl:verify-certificate no;',
      'set ssl:check-hostname no;',
      'set ftp:passive-mode on;',
      'set ftp:ssl-allow ${use_ftps};',
      'set ftp:ssl-force ${use_ftps};',
      'set ftp:ssl-protect-data ${use_ftps};',
    ]) {
      expect(workflow).toContain(setting);
    }
  });
});

describe('app-public-url-config-ops helper lock', () => {
  it('hardcodes only app_public_url and refuses generic key mutation surface', () => {
    expect(helper).toContain("const APP_PUBLIC_URL_TARGET_KEY = 'app_public_url'");
    expect(helper).toContain('case \'validate-url\':');
    expect(helper).toContain('case \'get\':');
    expect(helper).toContain('case \'patch\':');
    expect(helper).toContain('case \'assert-unrelated-equal\':');
    expect(helper).not.toMatch(/\$opts\[['\"]key['\"]\]/);
    expect(helper).not.toMatch(/argv.*config_key/);
    expect(helper).toContain('UNRELATED_CONFIG_KEYS_CHANGED');
    expect(helper).toContain('token_get_all');
  });

  it('passes embedded self-test without leaking sibling secrets to stdout labels', () => {
    const result = spawnSync('php', [helperPath, 'self-test'], { encoding: 'utf8' });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain('SELF_TEST=PASS');
    expect(result.stdout).not.toContain('super-secret-db-password-do-not-leak');
    expect(result.stdout).not.toContain('jwt_secret');
    expect(result.stdout).not.toContain('db_password');
  });
});
