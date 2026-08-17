import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const migrationPath = resolve(process.cwd(), '.github/workflows/apply-cpanel-migrations.yml');
const deployPath = resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml');
const migration = readFileSync(migrationPath, 'utf8');
const deploy = readFileSync(deployPath, 'utf8');

describe('canonical cPanel migration FTP transport contract', () => {
  it('matches deploy FTPS compatibility semantics', () => {
    for (const setting of [
      'set ssl:verify-certificate no;',
      'set ssl:check-hostname no;',
      'set ftp:passive-mode on;',
      'set ftp:ssl-allow ${use_ftps};',
      'set ftp:ssl-force ${use_ftps};',
      'set ftp:ssl-protect-data ${use_ftps};',
    ]) {
      expect(migration).toContain(setting);
      expect(deploy).toContain(setting);
    }
    expect(migration).toContain('set net:reconnect-interval-base 5;');
    expect(migration).toContain('set net:reconnect-interval-max 10;');
  });

  it('uses one canonical transport owner for request and status operations', () => {
    expect(migration).toMatch(/run_ftp_mode\(\)/);
    expect(migration).toMatch(/run_cpanel_ftp\(\)/);
    expect(migration.match(/run_cpanel_ftp "\$request_commands"/g)).toHaveLength(1);
    expect(migration.match(/run_cpanel_ftp "\$status_commands"/g)).toHaveLength(1);
    expect(migration).not.toContain('set ssl:verify-certificate yes;');
  });

  it('tries explicit FTPS before the deploy-compatible plain FTP fallback', () => {
    const ftpsIndex = migration.indexOf('run_ftp_mode "explicit-ftps" "true"');
    const fallbackIndex = migration.indexOf('run_ftp_mode "plain-ftp" "false"');

    expect(ftpsIndex).toBeGreaterThanOrEqual(0);
    expect(fallbackIndex).toBeGreaterThan(ftpsIndex);
    expect(deploy).toContain('deploy_with_ftp_mode "explicit-ftps" "true"');
    expect(deploy).toContain('deploy_with_ftp_mode "plain-ftp" "false"');
  });

  it('keeps the existing FTP secret names and never logs the password', () => {
    for (const secret of ['FTP_SERVER', 'FTP_USERNAME', 'FTP_PASSWORD', 'FTP_PORT']) {
      expect(migration).toContain(secret + ': ${{ secrets.' + secret + ' }}');
    }
    expect(migration).not.toMatch(/echo[^\n]*\$\{?(?:FTP_SERVER|FTP_USERNAME|FTP_PASSWORD|FTP_PORT)\}?/);
  });

  it('preserves the migration control-plane safety contract', () => {
    expect(migration).toContain('workflow_dispatch:');
    expect(migration).toContain('test "$CONFIRMATION" = "APPLY_CANONICAL_MIGRATIONS"');
    expect(migration).toMatch(/\[\[ "\$DEPLOYED_SHA" =~ \^\[0-9a-fA-F\]\{40\}\$ \]\]/);
    expect(migration).toContain('api/runtime/migration-control');
    expect(migration).toContain('put -O api/runtime/migration-control request.${REQUEST_ID}.tmp');
    expect(migration).toContain('request.pending.${REQUEST_ID}.json');
    expect(migration).toContain('status_state" == "FAILED"');
    expect(migration).not.toMatch(/\bssh\b/i);
    expect(migration).not.toMatch(/https?:\/\/[^\s"]*migration/i);
    expect(migration).not.toMatch(/\b(?:mysql|psql|sqlite3)\b/i);
    expect(migration).not.toContain('068');
  });
});
