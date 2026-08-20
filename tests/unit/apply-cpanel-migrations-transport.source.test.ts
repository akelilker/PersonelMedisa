import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const migrationPath = resolve(process.cwd(), '.github/workflows/apply-cpanel-migrations.yml');
const deployPath = resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml');
const migration = readFileSync(migrationPath, 'utf8');
const deploy = readFileSync(deployPath, 'utf8');

function workflowNumber(name: string): number {
  const match = migration.match(new RegExp(`^\\s+${name}: "?([0-9]+)"?$`, 'm'));
  if (!match) {
    throw new Error(`Missing numeric workflow contract: ${name}`);
  }
  return Number(match[1]);
}

type ObservedStatus = {
  atSeconds: number;
  requestId: string;
  state: 'RUNNING' | 'SUCCEEDED' | 'FAILED';
};

function observeTerminalState(events: ObservedStatus[], requestId: string): ObservedStatus | null {
  const attempts = workflowNumber('POLL_ATTEMPTS');
  const intervalSeconds = workflowNumber('POLL_INTERVAL_SECONDS');

  for (let attempt = 0; attempt < attempts; attempt += 1) {
    const observedAt = attempt * intervalSeconds;
    const latest = events
      .filter((event) => event.atSeconds <= observedAt)
      .sort((left, right) => right.atSeconds - left.atSeconds)[0];
    if (
      latest &&
      latest.requestId === requestId &&
      (latest.state === 'SUCCEEDED' || latest.state === 'FAILED')
    ) {
      return latest;
    }
  }
  return null;
}

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
    const deployLib = readFileSync(
      resolve(process.cwd(), 'scripts/deploy/cpanel-ftp-readback-lib.sh'),
      'utf8',
    );

    expect(ftpsIndex).toBeGreaterThanOrEqual(0);
    expect(fallbackIndex).toBeGreaterThan(ftpsIndex);
    expect(deploy).toContain('deploy_with_ftp_mode');
    expect(deployLib).toContain('deploy_with_ftp_mode "explicit-ftps" "true"');
    expect(deployLib).toContain('deploy_with_ftp_mode "plain-ftp" "false"');
    expect(deployLib.indexOf('explicit-ftps')).toBeLessThan(deployLib.indexOf('plain-ftp'));
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

  it('exposes only bounded safe diagnostics for a matching failed worker status', () => {
    expect(migration).toContain('MIGRATION_WORKER_STATE=FAILED');
    expect(migration).toContain('MIGRATION_REASON=${status_reason}');
    expect(migration).toContain('MIGRATION_STAGE=${status_stage}');
    expect(migration).toContain('MIGRATION_EXIT_CODE=${status_exit_code}');
    expect(migration).toContain('MIGRATION_SAFE_DETAIL=${status_detail}');
    expect(migration).toContain('cut -c1-512');
    expect(migration).toContain('status_request" == "$REQUEST_ID"');
    expect(migration).not.toContain('cat "$status_file"');
    expect(migration).not.toContain('echo "$status_file"');
  });

  it('keeps stale status ignored, success unchanged, and failure fail-closed', () => {
    expect(migration).toMatch(
      /if \[\[ "\$status_request" == "\$REQUEST_ID" && "\$status_state" == "SUCCEEDED" \]\]; then[\s\S]*?exit 0/,
    );
    expect(migration).toMatch(
      /if \[\[ "\$status_request" == "\$REQUEST_ID" && "\$status_state" == "FAILED" \]\]; then[\s\S]*?exit 1/,
    );
    expect(migration).toMatch(
      /if \[\[ "\$failed_match" == "YES" \]\]; then[\s\S]*?emit_failed_archive_failure[\s\S]*?exit 1/,
    );
    expect(migration).toContain('if run_cpanel_ftp "$status_commands" >/dev/null 2>&1; then');
  });

  it('defensively suppresses secret-like diagnostic detail without changing FTP transport', () => {
    expect(migration).toContain('status_detail" == "NONE"');
    expect(migration).toContain('[Pp][Aa][Ss][Ss][Ww][Oo][Rr][Dd]');
    expect(migration).toContain('[Ss][Ee][Cc][Rr][Ee][Tt]');
    expect(migration).toContain('[Tt][Oo][Kk][Ee][Nn]');
    expect(migration).toContain('[Dd][Ss][Nn]');
    expect(migration).toContain('FTP_PASSWORD');
    expect(migration).toContain('run_ftp_mode "explicit-ftps" "true"');
    expect(migration).toContain('run_ftp_mode "plain-ftp" "false"');
  });

  it('covers the 15-minute Cron worst case with a bounded 25-minute observation window', () => {
    const jobTimeoutSeconds = workflowNumber('timeout-minutes') * 60;
    const maximumScheduledPollSeconds =
      (workflowNumber('POLL_ATTEMPTS') - 1) * workflowNumber('POLL_INTERVAL_SECONDS');
    const pollWindowSeconds = workflowNumber('TOTAL_POLL_WINDOW_SECONDS');
    const cronWorstCaseWaitSeconds = workflowNumber('CRON_INTERVAL_MINUTES') * 60;
    const reasonableWorkerSeconds = 5 * 60;
    const ftpJitterSeconds = 5 * 60;

    expect(workflowNumber('timeout-minutes')).toBe(35);
    expect(cronWorstCaseWaitSeconds).toBe(15 * 60);
    expect(pollWindowSeconds).toBe(25 * 60);
    expect(maximumScheduledPollSeconds).toBeGreaterThanOrEqual(pollWindowSeconds);
    expect(pollWindowSeconds).toBeGreaterThanOrEqual(
      cronWorstCaseWaitSeconds + reasonableWorkerSeconds + ftpJitterSeconds,
    );
    expect(jobTimeoutSeconds - pollWindowSeconds).toBeGreaterThanOrEqual(10 * 60);
    expect(migration).toContain('poll_deadline_epoch="$(( poll_started_epoch + TOTAL_POLL_WINDOW_SECONDS ))"');
  });

  it('recognizes the real-world late success after the next Cron tick', () => {
    const requestId = 'current-request';
    const result = observeTerminalState(
      [
        { atSeconds: 14 * 60, requestId: 'older-request', state: 'SUCCEEDED' },
        { atSeconds: 15 * 60, requestId, state: 'RUNNING' },
        { atSeconds: 16 * 60, requestId, state: 'SUCCEEDED' },
      ],
      requestId,
    );

    expect(result).toMatchObject({ atSeconds: 16 * 60, requestId, state: 'SUCCEEDED' });
  });

  it('fails immediately for a matching failure and ignores another request status', () => {
    const requestId = 'current-request';
    const result = observeTerminalState(
      [
        { atSeconds: 0, requestId: 'older-request', state: 'FAILED' },
        { atSeconds: 20, requestId, state: 'FAILED' },
      ],
      requestId,
    );

    expect(result).toMatchObject({ atSeconds: 20, requestId, state: 'FAILED' });
    expect(migration).toMatch(
      /status_request" == "\$REQUEST_ID" && "\$status_state" == "FAILED"[\s\S]*?emit_matching_failure[\s\S]*?exit 1/,
    );
  });

  it('performs one final read-only diagnostic and recognizes canonical archives', () => {
    expect(migration).toContain('One final read-only inspection');
    expect(migration).toContain('mirror --verbose=0');
    expect(migration).toContain('request.processing.*.json');
    expect(migration).toContain('request.completed.${REQUEST_ID}.json');
    expect(migration).toContain('request.failed.${REQUEST_ID}.json');
    expect(migration).toContain('MIGRATION_DIAG_PENDING_MATCH=${pending_match}');
    expect(migration).toContain('MIGRATION_DIAG_PROCESSING_MATCH=${processing_match}');
    expect(migration).toContain('MIGRATION_DIAG_COMPLETED_MATCH=${completed_match}');
    expect(migration).toContain('MIGRATION_DIAG_FAILED_MATCH=${failed_match}');
    expect(migration).toContain('MIGRATION_DIAG_STATUS_EXISTS=${status_exists}');
    expect(migration).toContain('MIGRATION_DIAG_STATUS_REQUEST_ID=${diagnostic_status_request}');
    expect(migration).toContain('MIGRATION_DIAG_STATUS_STATE=${diagnostic_status_state}');
    expect(migration).toContain('MIGRATION_DIAG_STATUS_UPDATED_AT=${diagnostic_status_updated_at}');
    expect(migration).toContain('MIGRATION_WORKER_TIMEOUT');
    expect(migration).toContain('WORKER_RUNNING_TIMEOUT');
    expect(migration).toContain('REQUEST_NOT_CLAIMED');
    expect(migration).toContain('STATUS_TERMINAL_NOT_OBSERVED');
    expect(migration).toContain('UNKNOWN_CONTROL_PLANE_TIMEOUT');
    expect(migration).not.toMatch(/diagnostic_commands="[\s\S]*?\b(?:put|mput|mv|rm)\b/);
  });

  it('keeps one queued control-plane owner and never creates an automatic retry request', () => {
    expect(migration).toContain('group: cpanel-canonical-migration-control');
    expect(migration).toContain('cancel-in-progress: false');
    const preflightIndex = migration.indexOf('preflight_commands="mirror --verbose=0');
    const uploadIndex = migration.indexOf('put -O api/runtime/migration-control request.${REQUEST_ID}.tmp');
    expect(preflightIndex).toBeGreaterThanOrEqual(0);
    expect(uploadIndex).toBeGreaterThan(preflightIndex);
    expect(migration).toContain('--include-glob status.json');
    expect(migration).toContain('--include-glob request.pending.*.json');
    expect(migration).toContain('--include-glob request.processing.*.json');
    expect(migration).toContain('MIGRATION_CONTROL_PLANE_BUSY');
    expect(migration).toContain('MIGRATION_DIAG_RUNNING_STATUS_EXISTS=${preflight_running}');
    expect(migration.match(/put -O api\/runtime\/migration-control request\.\$\{REQUEST_ID\}\.tmp/g)).toHaveLength(1);
    expect(migration.match(/request\.pending\.\$\{REQUEST_ID\}\.json/g)?.length).toBeGreaterThanOrEqual(1);
    expect(migration).not.toMatch(/request\.failed\.[^\n]*request\.pending/);
    expect(migration).not.toContain('gh workflow run');
    expect(migration).not.toContain('workflow_run:');
  });
});
