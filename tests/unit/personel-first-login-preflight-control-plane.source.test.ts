import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { describe, expect, it } from 'vitest';

const read = (relativePath: string) => readFileSync(resolve(process.cwd(), relativePath), 'utf8');

const worker = read('api/bin/cpanel-migration-cron.php');
const reportOwner = read('api/src/Services/Auth/PersonelFirstLoginCredentialsPreflightReport.php');
const service = read('api/src/Services/Auth/PersonelAccountOnboardingService.php');
const heavyRunner = read('tests/php/PersonelFirstLoginCredentialsMysqlTestRunner.php');
const cliWorker = read('api/bin/personel-first-login-credentials.php');
const cleanlinessLib = read('scripts/deploy/cpanel-control-plane-cleanliness-lib.sh');

/**
 * The negative boundary assertions must judge the executable code, not the
 * docblock: the comments deliberately name the things the code refuses to do
 * (plaintext, password_hash, the CLI worker), and matching those names is the
 * opposite of a leak.
 */
function executablePhp(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
}

const reportOwnerCode = executablePhp(reportOwner);

const PERSONEL_BRANCH = "if ($mode === 'PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT') {";
const APPLY_BRANCH = "if ($mode === 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY') {";
const READ_ONLY_BRANCH = "if ($mode === 'READ_ONLY_PREFLIGHT') {";

const personelBlock = worker.slice(worker.indexOf(PERSONEL_BRANCH), worker.indexOf(APPLY_BRANCH));
const applyBlock = worker.slice(worker.indexOf(APPLY_BRANCH), worker.indexOf(READ_ONLY_BRANCH));

const EXPECTED_USERNAMES = [
  'abdullah',
  'fahriM',
  'hakanAc',
  'hakanAt',
  'muqtadaK',
  'oktayE',
  'raedF',
  'saifA',
];

describe('personel first-login read-only control-plane mode', () => {
  it('is allowlisted as its own read-only mode with a separate apply sibling', () => {
    expect(worker).toContain("|PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT|PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY)$/'");
    // The apply sibling exists but is its own branch and its own owner; the read-only
    // block below never reaches it and never calls apply = true.
    expect(worker).toContain(APPLY_BRANCH);
    expect(applyBlock).not.toContain('PersonelFirstLoginCredentialsPreflightReport');
    expect(personelBlock).not.toContain('PersonelFirstLoginCredentialsApplyReport');
    expect(worker).not.toContain('PERSONEL_FIRST_LOGIN_CREDENTIALS_DRY_RUN');
  });

  it('runs before the migration preflight so no migration or backup stage is reachable', () => {
    const personelIndex = worker.indexOf(PERSONEL_BRANCH);
    const readOnlyIndex = worker.indexOf(READ_ONLY_BRANCH);
    expect(personelIndex).toBeGreaterThan(0);
    expect(readOnlyIndex).toBeGreaterThan(personelIndex);
    expect(personelBlock.length).toBeGreaterThan(0);
    // A migration stage must not be reachable from this branch, at any point.
    expect(personelBlock).not.toContain('MigrationBackupService');
    expect(personelBlock).not.toContain('MigrationRunner');
    expect(personelBlock).not.toContain('MigrationExecutionService');
    expect(personelBlock).not.toContain('MigrationPreflightReport');
    expect(personelBlock).not.toContain('beginTransaction');
  });

  it('only reads and publishes one bounded report plus status and archive', () => {
    expect(personelBlock).toContain('Connection::get()');
    expect(personelBlock).toContain(
      'PersonelFirstLoginCredentialsPreflightReport::collect($pdo, $deployedSha)',
    );
    expect(personelBlock).toContain('writeJsonAtomically($personelFirstLoginPreflightPath, $report)');
    expect(worker).toContain("'/personel-first-login-preflight.json'");
    expect(personelBlock).toContain('archiveRequest(');
    expect(personelBlock).toContain('exit(0);');
    expect(personelBlock).toContain("'PERSONEL_FIRST_LOGIN_PREFLIGHT'");
    // No write statement and no credential field may exist in this branch.
    expect(personelBlock).not.toMatch(/\b(INSERT INTO|UPDATE |DELETE FROM|ALTER TABLE|DROP |TRUNCATE)\b/);
    expect(personelBlock).not.toContain('--apply');
    expect(personelBlock).not.toContain('actor_user_id');
    expect(personelBlock).not.toContain('password');
    expect(personelBlock).not.toContain('username =');
  });

  it('reports a failure through the canonical worker failure contract', () => {
    expect(personelBlock).toContain('MigrationWorkerFailure::fromThrowable($stage, $exception)');
    // Status carries aggregates only, so the operator can triage without the report.
    for (const field of [
      'personel_first_login_result',
      'personel_total',
      'target_count',
      'collision_count',
      'name_unresolved_count',
      'ilkera_touched',
    ]) {
      expect(personelBlock).toContain(`'${field}' =>`);
    }
  });
});

describe('personel first-login preflight report owner', () => {
  it('delegates the decision and never reimplements it', () => {
    expect(reportOwner).toContain(
      'PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials($pdo, null, false)',
    );
    // This owner must not own the roll-out rules; it only reads the canonical ones.
    expect(reportOwner).not.toContain('PERSONEL_CREDENTIAL_OVERRIDES');
    expect(reportOwner).not.toContain('PERSONEL_NAME_CORRECTIONS');
    expect(reportOwner).not.toContain('buildPersonelUsernameFromNames');
    expect(reportOwner).not.toContain('buildPersonelInitialPasswordFromNames');
    // It publishes the reservation list so the operator can see what is protected.
    expect(reportOwner).toContain('PROTECTED_USERNAMES');
  });

  it('is read-only: one independent count and no write statement', () => {
    expect(reportOwner).not.toMatch(
      /\b(INSERT INTO|UPDATE |DELETE FROM|ALTER TABLE|DROP |TRUNCATE|CREATE TABLE|REPLACE INTO)\b/,
    );
    expect(reportOwner).not.toContain('beginTransaction');
    expect(reportOwner).toContain("SELECT COUNT(*) FROM users WHERE rol = 'PERSONEL'");
    // The cohort total is read independently of the plan it is meant to verify.
    expect(reportOwner).toContain('if ($statement === false) {');
    expect(reportOwner).toContain('return -1;');
  });

  it('captures the canonical response instead of letting it exit the worker', () => {
    expect(reportOwner).toContain('JsonResponse::beginCapture()');
    expect(reportOwner).toContain('JsonResponse::capturedResponse()');
    expect(reportOwner).toContain('JsonResponse::endCapture()');
    expect(reportOwner).toContain('catch (ResponseCaptured $refusal)');
  });

  it('never publishes a name preimage, credential or secret', () => {
    expect(reportOwnerCode).not.toMatch(/password_hash|initial_password|PasswordHasher|plaintext/);
    expect(reportOwnerCode).not.toContain('ad_soyad');
    // The correction preimage is dropped: only presence and match travel.
    expect(reportOwnerCode).not.toMatch(/\['from'\]|\['to'\]/);
    expect(reportOwnerCode).not.toContain("'ad' =>");
    expect(reportOwnerCode).not.toContain("'soyad' =>");
    expect(reportOwner).toContain("'name_correction_present' =>");
    expect(reportOwner).toContain("'name_correction_preimage_match' =>");
  });

  it('fails closed on drifted expectations instead of reporting a smaller cohort', () => {
    for (const blocker of [
      'PERSONEL_FIRST_LOGIN_DECISION_REFUSED',
      'PERSONEL_FIRST_LOGIN_DECISION_UNREADABLE',
      'PERSONEL_FIRST_LOGIN_COHORT_RECONCILIATION_FAILED',
      'PERSONEL_FIRST_LOGIN_CANONICAL_USERNAME_COLLISION',
      'PERSONEL_FIRST_LOGIN_NAME_CORRECTION_PREIMAGE_MISMATCH',
      'PERSONEL_FIRST_LOGIN_NAME_CORRECTION_PREIMAGE_UNVERIFIED',
      'PERSONEL_FIRST_LOGIN_PROTECTED_USERNAME_IN_PLAN',
      'PERSONEL_FIRST_LOGIN_READ_ONLY_VIOLATION',
    ]) {
      expect(reportOwner).toContain(blocker);
    }
    expect(reportOwner).toContain("'production_mutation_count' => 0");
    expect(reportOwner).toContain("'ilkera_touched'");
    // The reservation guard is keyed on the banned usernames, not on a user id.
    expect(reportOwnerCode).toContain('isset($protected[strtolower($oldUsername)])');
    expect(reportOwnerCode).toContain('isset($protected[strtolower($newUsername)])');
    expect(reportOwner).toContain("'result' => $blockers === [] ? 'PASS' : 'BLOCKED'");
  });

  it('does not hardcode the one-time production pins', () => {
    expect(reportOwner).not.toMatch(/\b(125|134)\b/);
    expect(service).not.toMatch(/EXPECTED_PERSONEL_TOTAL|EXPECTED_TARGET_COUNT|EXPECTED_EXCLUDED_TOTAL/);
    expect(worker).not.toMatch(/EXPECTED_PERSONEL_TOTAL|EXPECTED_TARGET_COUNT|EXPECTED_EXCLUDED_TOTAL/);
  });

  it('publishes the canonical plan fingerprint so apply can be pinned to it', () => {
    // The value is produced by the canonical owner, never recomputed here, and stays a
    // secret-free sha256 hex.
    expect(service).toContain('firstLoginPlanFingerprint');
    expect(service).toContain("hash('sha256'");
    expect(reportOwner).toContain("'plan_fingerprint' =>");
    expect(reportOwnerCode).toContain("$result['plan_fingerprint']");
    expect(reportOwnerCode).not.toContain('hash(\'sha256\'');
  });

  it('does not take over the canonical CLI worker', () => {
    expect(reportOwnerCode).not.toContain('personel-first-login-credentials.php');
    expect(cliWorker).toContain('migrateCanonicalFirstLoginCredentials');
  });
});

describe('personel first-login control-plane cleanliness shell owner', () => {
  it('keeps the current-request contract in one owner that never deletes anything', () => {
    expect(cleanlinessLib).toContain('assert_control_plane_clean_for_request()');
    expect(cleanlinessLib).toContain('normalize_control_plane_listing()');
    for (const reason of [
      'CONTROL_PLANE_LEFTOVER_PENDING_REQUEST',
      'CONTROL_PLANE_LEFTOVER_PROCESSING_REQUEST',
      'CONTROL_PLANE_FAILED_REQUEST_PRESENT',
      'CONTROL_PLANE_REQUEST_NOT_ARCHIVED',
      'CONTROL_PLANE_UNEXPECTED_DELTA',
    ]) {
      expect(cleanlinessLib).toContain(reason);
    }
    // A historical failure is only ever judged through the delta, never by a
    // global pattern match over the whole listing.
    expect(cleanlinessLib).not.toMatch(/grep -q 'request\\?\.failed/);
    expect(cleanlinessLib).toContain('grep -vxF -f "$work/before.txt"');
    // Read-only by construction: the contract never removes a remote file.
    expect(cleanlinessLib).not.toMatch(/^\s*(rm|mv|put|mput|mrm)\s+.*migration-control/m);
  });

  it('accepts extra delta entries only through an exact opt-in allowlist', () => {
    // The allowlist is opt-in: absent by default, so the preflight caller is unchanged.
    expect(cleanlinessLib).toContain('local -a expected_extra=("$@")');
    expect(cleanlinessLib).toContain('CONTROL_PLANE_ALLOWLIST_ENTRY_INVALID');
    // A glob, a request.* entry or a path separator is refused instead of widening the list.
    expect(cleanlinessLib).toContain("== *'*'*");
    expect(cleanlinessLib).toContain("== *'/'*");
    expect(cleanlinessLib).toContain('== request.*');
    // This run's own request state is judged BEFORE the allowlist, so an allowlist entry
    // can never excuse a pending/failed/processing request file.
    const requestStateIndex = cleanlinessLib.indexOf('request.processing.*)');
    const allowlistMatchIndex = cleanlinessLib.indexOf('for candidate in "${expected_extra[@]:-}"');
    expect(requestStateIndex).toBeGreaterThan(0);
    expect(allowlistMatchIndex).toBeGreaterThan(requestStateIndex);
    // The exact allowlist is an equality test, never a pattern match.
    expect(cleanlinessLib).toContain('"$entry" == "$candidate"');
    expect(cleanlinessLib).not.toMatch(/\[\[\s*"\$entry"\s*==\s*\$candidate\s*\]\]/);
  });
});

describe('personel first-login heavy acceptance coverage', () => {
  it('drives the read-only report owner on a disposable database', () => {
    expect(heavyRunner).toContain('PersonelFirstLoginCredentialsPreflightReport');
    expect(heavyRunner).toContain('PERSONEL_FIRST_LOGIN_PREFLIGHT');
    // Zero mutation proof: the whole users/personeller tables are compared.
    expect(heavyRunner).toContain('SELECT id, username, password_hash, activation_required, must_change_password, rol, durum, personel_id FROM users ORDER BY id ASC');
    expect(heavyRunner).toContain('SELECT id, ad, soyad, aktif_durum FROM personeller ORDER BY id ASC');
  });
});

describe('personel first-login control-plane cleanliness runtime', () => {
  /**
   * The production run failed with PREFLIGHT_REASON=FAILED_REQUEST_PRESENT while
   * its own payload reported PASS and 90 pre-existing request.* files sat in the
   * control plane. This drives the real shell contract against fixtures: the
   * historical failures must pass, and this run's own states must still block.
   */
  it('separates this run\u2019s request state from archived control-plane history', () => {
    const script = resolve(process.cwd(), 'scripts/deploy/test-cpanel-control-plane-cleanliness-lib.sh');
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
      // Historical failed requests + this run completed cleanly => PASS.
      'HISTORICAL_FAILED_CURRENT_COMPLETED_RC=PASS',
      'FALSE_FAILURE_FIXTURE_HAS_HISTORICAL_FAILED=PASS',
      // This run's own states => BLOCKED.
      'CURRENT_FAILED_RC=PASS',
      'CURRENT_PENDING_RC=PASS',
      'CURRENT_PROCESSING_LEFTOVER_RC=PASS',
      'CURRENT_NOT_ARCHIVED_RC=PASS',
      'UNEXPECTED_DELTA_RC=PASS',
      'FOREIGN_COMPLETION_ONLY_RC=PASS',
      // A fresh control plane with a clean completion still passes.
      'EMPTY_BEFORE_COMPLETED_RC=PASS',
      // The first-ever APPLY's own two outputs: PASS only with the exact allowlist,
      // and still BLOCKED without it.
      'APPLY_OWN_OUTPUTS_ALLOWLISTED_RC=PASS',
      'APPLY_OWN_OUTPUTS_NO_ALLOWLIST_RC=PASS',
      'ALLOWLIST_PLUS_UNKNOWN_DELTA_RC=PASS',
      // Request state and pattern entries are never allowlistable.
      'CURRENT_PENDING_WITH_ALLOWLIST_RC=PASS',
      'CURRENT_FAILED_WITH_ALLOWLIST_RC=PASS',
      'CURRENT_PROCESSING_LEAK_WITH_ALLOWLIST_RC=PASS',
      'ALLOWLIST_GLOB_REFUSED_RC=PASS',
      'ALLOWLIST_REQUEST_ENTRY_REFUSED_RC=PASS',
      'ALLOWLIST_PATH_REFUSED_RC=PASS',
      // The preflight caller keeps the original contract.
      'PREFLIGHT_CALLER_NO_EXTRAS_RC=PASS',
      'HARNESS_FAIL=0',
    ]) {
      expect(output).toContain(assertion);
    }
  });
});
