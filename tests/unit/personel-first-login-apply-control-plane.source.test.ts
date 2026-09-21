import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (relativePath: string) => readFileSync(resolve(process.cwd(), relativePath), 'utf8');

const worker = read('api/bin/cpanel-migration-cron.php');
const applyOwner = read('api/src/Services/Auth/PersonelFirstLoginCredentialsApplyReport.php');
const preflightOwner = read('api/src/Services/Auth/PersonelFirstLoginCredentialsPreflightReport.php');
const service = read('api/src/Services/Auth/PersonelAccountOnboardingService.php');
const workflow = read('.github/workflows/ops-personel-first-login-apply.yml');
const preflightWorkflow = read('.github/workflows/ops-personel-first-login-preflight.yml');
const heavyRunner = read('tests/php/PersonelFirstLoginCredentialsMysqlTestRunner.php');
const cliWorker = read('api/bin/personel-first-login-credentials.php');

/**
 * Negative boundary assertions must judge the executable code, not the docblock: the
 * comments deliberately name the things the code refuses to do, and matching those names is
 * the opposite of a leak.
 */
function executablePhp(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^[ \t]*\/\/.*$/gm, '');
}

const applyOwnerCode = executablePhp(applyOwner);
const serviceCode = executablePhp(service);

const APPLY_BRANCH = "if ($mode === 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY') {";
const READ_ONLY_BRANCH = "if ($mode === 'READ_ONLY_PREFLIGHT') {";
const GENERIC_APPLY_ANCHOR = "$stage = 'TARGET_RESOLVE';";

const applyBlock = worker.slice(worker.indexOf(APPLY_BRANCH), worker.indexOf(READ_ONLY_BRANCH));
const applyBlockCode = executablePhp(applyBlock);

const EXPECTED_USERNAMES = ['abdullah', 'fahriM', 'hakanAc', 'hakanAt', 'muqtadaK', 'oktayE', 'raedF', 'saifA'];

describe('personel first-login apply control-plane mode', () => {
  it('is allowlisted as its own mode beside the read-only preflight', () => {
    expect(worker).toContain("|PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT|PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY)$/'");
    expect(worker).toContain(APPLY_BRANCH);
    expect(applyBlockCode.length).toBeGreaterThan(0);
  });

  it('runs before the migration path so no migration or backup stage is reachable', () => {
    const applyIndex = worker.indexOf(APPLY_BRANCH);
    const readOnlyIndex = worker.indexOf(READ_ONLY_BRANCH);
    const genericIndex = worker.indexOf(GENERIC_APPLY_ANCHOR);
    expect(applyIndex).toBeGreaterThan(0);
    expect(readOnlyIndex).toBeGreaterThan(applyIndex);
    expect(genericIndex).toBeGreaterThan(readOnlyIndex);
    expect(applyBlockCode).not.toContain('MigrationBackupService');
    expect(applyBlockCode).not.toContain('MigrationRunner');
    expect(applyBlockCode).not.toContain('MigrationExecutionService');
    expect(applyBlockCode).not.toContain('MigrationPreflightReport');
  });

  it('requires the pinned plan fingerprint and delegates the write to the canonical owner', () => {
    // Request-shape validation (including the pin) lives in the parse stage, so the apply
    // branch can never be entered unpinned and no request field is read inside it.
    const parseIndex = worker.indexOf(
      "$expectedPlanFingerprint = $mode === 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY'",
    );
    expect(parseIndex).toBeGreaterThan(0);
    expect(parseIndex).toBeLessThan(worker.indexOf(APPLY_BRANCH));
    expect(worker).toContain("requireString($request, 'expected_plan_fingerprint', '/^[a-f0-9]{64}$/')");
    expect(applyBlockCode).toContain('PersonelFirstLoginCredentialsApplyReport::run(');
    expect(applyBlockCode).toContain('$expectedPlanFingerprint,');
    expect(applyBlockCode).not.toContain('requireString(');
    expect(applyBlockCode).toContain('writeJsonAtomically($personelFirstLoginApplyPath, $report)');
    expect(worker).toContain("'/personel-first-login-apply.json'");
  });

  it('takes no request field that could select a cohort or supply a credential', () => {
    // The branch reads no request field at all: the pinned fingerprint is handed to it
    // from the parse stage, so an operator cannot ask for a different cohort, a different
    // personel, a different username or a different password.
    expect(applyBlockCode).not.toContain('$request[');
    expect(applyBlockCode).not.toContain('$request,');
    expect(applyBlockCode).not.toContain('requireString(');
    for (const forbidden of [
      'personel_id',
      'username',
      'password',
      'actor_user_id',
      'target_version',
      'mapping_spec',
      'migration_version',
      'filter',
    ]) {
      expect(applyBlockCode).not.toContain(forbidden);
    }
  });

  it('exposes no HTTP surface for the write', () => {
    expect(applyBlockCode).not.toContain('Router');
    expect(applyBlockCode).not.toContain('header(');
    expect(applyBlockCode).not.toContain('$_GET');
    expect(applyBlockCode).not.toContain('$_POST');
  });

  it('persists the recovery preimage outside the publish path and never uploads it', () => {
    expect(applyBlockCode).toContain("'/personel-first-login-apply-preimage.json'");
    expect(applyBlockCode).toContain("hash_file('sha256', $preimagePath)");
    expect(applyBlockCode).toContain('writeJsonAtomically($preimagePath, $preimage)');
    // The preimage is written by the worker; no artifact upload path exists in this step.
    expect(applyBlockCode).not.toContain('upload-artifact');
  });

  it('fails the request closed when the canonical owner blocks the write', () => {
    expect(applyBlockCode).toContain("($report['result'] ?? 'BLOCKED') !== 'PASS'");
    expect(applyBlockCode).toContain('new MigrationWorkerFailure(');
    expect(applyBlockCode).toContain("'personel_first_login_apply_result' =>");
    expect(applyBlockCode).toContain("'ilkera_touched' =>");
    // The status record publishes counts and the fingerprint, never a credential.
    expect(applyBlockCode).not.toContain('password');
  });
});

describe('personel first-login apply report owner', () => {
  it('delegates every decision to the canonical owner and performs no write itself', () => {
    expect(applyOwner).toContain('PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials');
    expect(applyOwner).toContain('PersonelAccountOnboardingService::firstLoginRolloutApplied');
    expect(applyOwner).toContain('PersonelAccountOnboardingService::resolvePersonelInitialPasswordMaterial');
    // The report owner never writes: the canonical owner owns the mutation and the
    // transaction, and a second write path here would be a second credential implementation.
    expect(applyOwnerCode).not.toContain('UPDATE ');
    expect(applyOwnerCode).not.toContain('INSERT ');
    expect(applyOwnerCode).not.toContain('beginTransaction');
    // It must not re-own the credential rules either.
    expect(applyOwnerCode).not.toContain('PERSONEL_CREDENTIAL_OVERRIDES');
    expect(applyOwnerCode).not.toContain('PERSONEL_NAME_CORRECTIONS');
    expect(applyOwnerCode).not.toContain('buildPersonelUsernameFromNames');
    expect(applyOwnerCode).not.toContain('buildPersonelInitialPasswordFromNames');
    expect(applyOwnerCode).not.toContain('password_hash(');
  });

  it('pins the same-cohort gate on the canonical fingerprint', () => {
    expect(applyOwnerCode).toContain('$planFingerprint !== $expected');
    expect(applyOwnerCode).toContain('PersonelAccountOnboardingService::ERR_PLAN_FINGERPRINT_MISMATCH');
    expect(applyOwnerCode).toMatch(/\$pdo,\s*\n\s*null,\s*\n\s*true,\s*\n\s*\$expected/);
    // A malformed pin refuses before anything is read.
    expect(applyOwnerCode).toContain("preg_match('/^[a-f0-9]{64}$/', $expected)");
  });

  it('fails closed on replay before any write', () => {
    expect(applyOwnerCode).toContain('firstLoginRolloutApplied($pdo)');
    expect(applyOwnerCode).toContain('PersonelAccountOnboardingService::ERR_ROLLOUT_ALREADY_APPLIED');
    // The cohort one-shot is checked before the preimage is persisted, which is checked
    // before the write, so a replay can never reach the mutation.
    const oneShotIndex = applyOwnerCode.indexOf('firstLoginRolloutApplied($pdo)');
    const preimageIndex = applyOwnerCode.indexOf('self::buildPreimage(');
    const writeIndex = applyOwnerCode.indexOf('true,', preimageIndex);
    expect(oneShotIndex).toBeGreaterThan(0);
    expect(preimageIndex).toBeGreaterThan(oneShotIndex);
    expect(writeIndex).toBeGreaterThan(preimageIndex);
  });

  it('persists the recovery preimage before the mutation and never publishes it', () => {
    expect(applyOwnerCode).toContain('$persistPreimage($preimage)');
    expect(applyOwnerCode).toContain("'preimage_contains_password_hash' => true");
    expect(applyOwnerCode).toContain("'preimage_published' => false");
    expect(applyOwnerCode).toContain("'password_hash' => (string) $row['password_hash']");
    // Only the secret-free field projection of the preimage reaches the report; the
    // secret-bearing array itself is never merged in or re-exposed.
    expect(applyOwnerCode).toContain('self::preimageFields($preimage');
    expect(applyOwnerCode).not.toMatch(/\$report\s*(\[[^\]]+\])?\s*=\s*\$preimage/);
    expect(applyOwnerCode).not.toContain("$report['targets']");
    expect(applyOwnerCode).not.toContain("$report['name_corrections']");
    expect(applyOwnerCode).not.toContain("$report['excluded']");
    // A preimage persistence failure stops the operation instead of writing anyway.
    expect(applyOwnerCode).toContain('BLOCKER_PREIMAGE_PERSIST_FAILED');
  });

  it('publishes only counts, bounded plan rows and reason codes', () => {
    expect(applyOwner).toContain('PLAN_ROW_FIELDS');
    for (const field of [
      'user_id',
      'personel_id',
      'old_username',
      'new_username',
      'username_changed',
      'business_override',
      'name_correction_present',
      'name_correction_preimage_match',
    ]) {
      expect(applyOwner).toContain(`'${field}'`);
    }
    // The canonical name-correction preimage (ad/soyad) is dropped from the report;
    // only its presence and preimage match flag are published.
    expect(applyOwner).not.toMatch(/PLAN_ROW_FIELDS = \[[^\]]*'(ad|soyad)'/);
    expect(applyOwnerCode).not.toContain('ad_soyad');
    expect(applyOwnerCode).not.toContain('secrets.token');
  });

  it('proves the post-apply state and the excluded cohort instead of trusting the writer', () => {
    for (const field of [
      'credential_targets_reconciled',
      'credential_target_mismatch_count',
      'name_corrections_reconciled',
      'name_correction_mismatch_count',
      'excluded_unchanged',
      'excluded_changed_count',
      'ilkera_unchanged',
    ]) {
      expect(applyOwner).toContain(`'${field}'`);
    }
    expect(applyOwnerCode).toContain('PasswordHasher::verify(');
    expect(applyOwnerCode).toContain('rollout_excluded_in_plan_count');
    expect(applyOwnerCode).toContain('BLOCKER_POSTCHECK_FAILED');
    // The postcheck proves the exact end state the rollout promises.
    expect(applyOwnerCode).toContain("(int) $row['activation_required'] === 0");
    expect(applyOwnerCode).toContain("(int) $row['must_change_password'] === 1");
    expect(applyOwnerCode).toContain("(string) $row['rol'] === 'PERSONEL'");
  });

  it('fails closed on an empty cohort, drifted totals or a reserved username in the plan', () => {
    for (const blocker of [
      'PERSONEL_FIRST_LOGIN_APPLY_EMPTY_COHORT',
      'PERSONEL_FIRST_LOGIN_APPLY_COHORT_RECONCILIATION_FAILED',
      'PERSONEL_FIRST_LOGIN_APPLY_PLAN_USERNAME_NOT_UNIQUE',
      'PERSONEL_FIRST_LOGIN_APPLY_PROTECTED_USERNAME_IN_PLAN',
      'PERSONEL_FIRST_LOGIN_APPLY_FINGERPRINT_INVALID',
      'PERSONEL_FIRST_LOGIN_APPLY_PREIMAGE_PERSIST_FAILED',
      'PERSONEL_FIRST_LOGIN_APPLY_OWNER_FAILED',
      'PERSONEL_FIRST_LOGIN_APPLY_DECISION_REFUSED',
      'PERSONEL_FIRST_LOGIN_APPLY_DECISION_UNREADABLE',
    ]) {
      expect(applyOwner).toContain(blocker);
    }
    expect(applyOwnerCode).toContain("SELECT COUNT(*) FROM users WHERE rol = 'PERSONEL'");
    expect(applyOwnerCode).toContain('PROTECTED_USERNAMES');
    expect(applyOwnerCode).toContain('ROLLOUT_EXCLUDED_PERSONEL_IDS');
  });

  it('maps the canonical owner refusal codes instead of inventing new ones', () => {
    for (const code of [
      'ERR_CANONICAL_USERNAME_COLLISION',
      'ERR_NAME_CORRECTION_PREIMAGE',
      'ERR_ROLLOUT_SCOPE_VIOLATION',
      'ERR_PLAN_FINGERPRINT_MISMATCH',
      'ERR_ROLLOUT_ALREADY_APPLIED',
    ]) {
      expect(applyOwnerCode).toContain(`PersonelAccountOnboardingService::${code}`);
    }
  });

  it('is not reachable from the read-only preflight owner', () => {
    const preflightOwnerCode = executablePhp(preflightOwner);
    // The read-only owner publishes a plan preimage MATCH flag (its own bounded field)
    // but never the recovery preimage, never the apply owner and never a hash.
    expect(preflightOwner).not.toContain('PersonelFirstLoginCredentialsApplyReport');
    expect(preflightOwnerCode).toContain("'name_correction_preimage_match'");
    expect(preflightOwnerCode).not.toContain('password_hash');
    expect(preflightOwnerCode).not.toContain('$persistPreimage');
    expect(preflightOwnerCode).not.toContain('beginTransaction');
    expect(preflightOwnerCode).not.toContain('firstLoginRolloutApplied');
  });
});

describe('personel first-login canonical owner apply contract', () => {
  it('gates the fingerprint, replay and scope before opening a transaction', () => {
    expect(service).toContain('ERR_PLAN_FINGERPRINT_MISMATCH');
    expect(service).toContain('ERR_ROLLOUT_ALREADY_APPLIED');
    expect(service).toContain('ERR_ROLLOUT_SCOPE_VIOLATION');
    expect(service).toContain('EVENT_FIRST_LOGIN_ROLLOUT_APPLIED');
    expect(service).toContain('firstLoginRolloutApplied');
    expect(service).toContain('firstLoginPlanFingerprint');

    const fingerprintGate = service.indexOf('self::ERR_PLAN_FINGERPRINT_MISMATCH,');
    const rolloutGate = service.indexOf('self::firstLoginRolloutApplied($pdo)', fingerprintGate);
    const transaction = service.indexOf('$pdo->beginTransaction();', rolloutGate);
    expect(fingerprintGate).toBeGreaterThan(0);
    expect(rolloutGate).toBeGreaterThan(fingerprintGate);
    expect(transaction).toBeGreaterThan(rolloutGate);
  });

  it('writes the one-shot ledger record inside the same transaction as the mutations', () => {
    const applyScopeStart = service.indexOf('self::ERR_PLAN_FINGERPRINT_MISMATCH,');
    const transaction = service.indexOf('$pdo->beginTransaction();', applyScopeStart);
    const ledger = service.indexOf('self::EVENT_FIRST_LOGIN_ROLLOUT_APPLIED, null, null', transaction);
    const commit = service.indexOf('$pdo->commit();', ledger);
    expect(transaction).toBeGreaterThan(applyScopeStart);
    expect(ledger).toBeGreaterThan(transaction);
    expect(commit).toBeGreaterThan(ledger);
    // Secret-free ledger detail: fingerprint and counts only.
    expect(service).toContain("'plan_fingerprint' => $planFingerprint");
    expect(service).toContain("'actor_source' => $actorAuditId === null ? 'control_plane' : 'actor_user_id'");
  });

  it('keeps every mutation inside the single rollback-guarded transaction', () => {
    const applySection = service.slice(
      service.lastIndexOf('$pdo->beginTransaction();'),
      service.indexOf("'rollout_ledger_event' => self::EVENT_FIRST_LOGIN_ROLLOUT_APPLIED"),
    );
    expect(applySection).toContain('UPDATE users');
    expect(applySection).toContain('UPDATE personeller');
    expect(applySection).toContain('$pdo->commit();');
    expect(applySection).toContain('$pdo->rollBack();');
    // Any single row mismatch rolls the whole cohort back; a partial rollout is impossible.
    expect(applySection).toContain("if ($update->rowCount() !== 1) {");
    expect(applySection).toContain("if ($correctionUpdate->rowCount() !== 1) {");
    expect(applySection).toContain("throw new \\RuntimeException(self::ERR_NAME_CORRECTION_PREIMAGE)");
  });

  it('keeps the row-level preimage guard so a rerun cannot silently re-apply', () => {
    expect(service).toContain('AND username = :old_username');
    expect(service).toContain('firstLoginCredentialAppliedUserIds');
    expect(service).toContain("'replayed_user_ids' => $replayedUserIds");
    expect(service).toContain('AND ad <=> :old_ad');
    expect(service).toContain('AND soyad <=> :old_soyad');
  });

  it('keeps the rollout scope exclusion locked to 219 while doguA is not created here', () => {
    expect(service).toContain('ROLLOUT_EXCLUDED_PERSONEL_IDS = [219]');
    expect(service).toContain('rolloutScopeViolations');
    // No doguA creation path is added by this build. The only occurrences in the service
    // are the doc comments that explain why 219 is out of scope.
    expect(serviceCode).not.toContain('doguA');
    expect(worker).not.toContain('doguA');
    expect(applyOwnerCode).not.toContain('doguA');
    expect(cliWorker).not.toContain('doguA');
  });

  it('does not hardcode the one-time production pins in PHP', () => {
    expect(service).not.toMatch(/\b(125|134)\b/);
    expect(applyOwner).not.toMatch(/EXPECTED_PERSONEL_TOTAL|EXPECTED_TARGET_COUNT|EXPECTED_EXCLUDED_TOTAL/);
  });
});

describe('personel first-login apply workflow', () => {
  it('is a single fixed apply mode behind an exact confirmation token', () => {
    expect(workflow).toContain('name: HISTORICAL — Ops personel first-login APPLY (2026-09-19 rollout; NOT headcount)');
    expect(workflow).toContain('MODE: PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY');
    expect(workflow).toContain('APPLY_CONFIRMATION_TOKEN: APPLY_PERSONEL_FIRST_LOGIN_CREDENTIALS');
    expect(workflow).toContain('test "$CONFIRMATION" = "$APPLY_CONFIRMATION_TOKEN"');
    expect(workflow).toContain('CONFIRMATION_MISMATCH');
    // No mode choice list: an operator cannot select anything but this apply mode.
    expect(workflow).not.toContain('options: [');
    expect(workflow).toContain('group: cpanel-canonical-migration-control');
    expect(workflow).toContain('cancel-in-progress: false');
    expect(workflow).toContain('permissions:\n  contents: read');
  });

  it('blocks a public repository before any FTP contact', () => {
    const boundaryIndex = workflow.indexOf('PUBLIC_REPOSITORY_ARTIFACT_EXPOSURE');
    expect(boundaryIndex).toBeGreaterThan(0);
    expect(boundaryIndex).toBeLessThan(workflow.indexOf('ftp://${FTP_SERVER}'));
    expect(workflow).toContain('if [ "${REPOSITORY_PRIVATE:-}" != "true" ]; then');
    expect(workflow).toContain('if: always() && github.event.repository.private == true');
  });

  it('pins the SHA, the worker and the plan fingerprint before writing a request', () => {
    expect(workflow).toContain('DEPLOY_SHA_MISMATCH');
    expect(workflow).toContain('REMOTE_WORKER_PARITY_MISMATCH');
    expect(workflow).toContain('PLAN_FINGERPRINT_INPUT_INVALID');
    expect(workflow).toContain('MIGRATION_CONTROL_PLANE_BUSY');
    expect(workflow).toContain('WORKER_BUSY');
    expect(workflow).toContain('APPLY_REPORT_REQUEST_MISMATCH');
    expect(workflow).toContain('APPLY_REPORT_SHA_MISMATCH');
    expect(workflow).toContain('APPLY_REPORT_MODE_MISMATCH');
    expect(workflow).toContain('APPLY_REPORT_PLAN_FINGERPRINT_MISMATCH');
  });

  it('writes a request with no field that could select a cohort or supply a credential', () => {
    const requestBlock = workflow.slice(
      workflow.indexOf('jq -n \\'),
      workflow.indexOf('> "$request_dir/request.${REQUEST_ID}.tmp"'),
    );
    expect(requestBlock).toContain('mode: "PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY"');
    expect(requestBlock).toContain('expected_plan_fingerprint: $expected_plan_fingerprint');
    for (const forbidden of [
      'personel_id',
      'username',
      'password',
      'actor_user_id',
      'target_version',
      'mapping_spec',
      'sql',
    ]) {
      expect(requestBlock).not.toContain(forbidden);
    }
  });

  it('never fetches or publishes the recovery preimage', () => {
    // The preimage filename now appears in exactly one place: the control-plane delta
    // allowlist, which only DECLARES that the worker persisted it. The preimage is still
    // never read over FTP, never copied and never uploaded, so no preimage value — the old
    // password_hash — can leave the control plane.
    expect(workflow.match(/personel-first-login-apply-preimage\.json/g) ?? []).toHaveLength(1);
    const allowlistBlock = workflow.slice(
      workflow.indexOf('expected_extra=('),
      workflow.indexOf(')', workflow.indexOf('expected_extra=(')),
    );
    expect(allowlistBlock).toContain('"personel-first-login-apply-preimage.json"');
    expect(workflow).not.toMatch(/get\s+\S*preimage/i);
    expect(workflow).not.toMatch(/put\s+\S*preimage/i);
    expect(workflow).not.toMatch(/cp\s+\S*preimage/i);
    // The only uploaded path is the secret-free report artifact.
    expect(workflow).toContain('path: apply-artifact/');
    expect(workflow).not.toContain('path: ${{');
    expect(workflow).toContain('cp "$report" "$GITHUB_WORKSPACE/apply-artifact/personel-first-login-apply.json"');
  });

  it('gates the one-time production apply expectations hard fail-closed', () => {
    for (const pin of [
      'EXPECTED_PERSONEL_TOTAL: "134"',
      'EXPECTED_TARGET_COUNT: "125"',
      'EXPECTED_EXCLUDED_TOTAL: "9"',
      'EXPECTED_EXCLUDED_USER_NOT_ACTIVE: "4"',
      'EXPECTED_EXCLUDED_BOUND_PERSONEL_NOT_ACTIVE: "5"',
      'EXPECTED_EXCLUDED_PROTECTED_USERNAME: "0"',
      'EXPECTED_EXCLUDED_BINDING_MISSING: "0"',
      'EXPECTED_EXCLUDED_BOUND_PERSONEL_MISSING: "0"',
      'EXPECTED_NAME_UNRESOLVED: "0"',
      'EXPECTED_NAME_CORRECTION_COUNT: "5"',
      'EXPECTED_BUSINESS_OVERRIDE_COUNT: "3"',
      'EXPECTED_COLLISION_COUNT: "0"',
      'EXPECTED_PROTECTED_IN_PLAN: "0"',
      'EXPECTED_ROLLOUT_EXCLUDED_IN_PLAN: "0"',
      'EXPECTED_APPLY_COUNT: "125"',
      'EXPECTED_USER_MUTATION_COUNT: "125"',
      'EXPECTED_NAME_CORRECTION_MUTATION_COUNT: "5"',
      'EXPECTED_PRODUCTION_MUTATION_COUNT: "130"',
    ]) {
      expect(workflow).toContain(pin);
    }
    expect(workflow).toContain(
      'EXPECTED_USERNAME_PLAN: "abdullah,fahriM,hakanAc,hakanAt,muqtadaK,oktayE,raedF,saifA"',
    );
    for (const gate of [
      'gate PERSONEL_TOTAL',
      'gate TARGET_COUNT',
      'gate EXCLUDED_TOTAL',
      'gate EXCLUDED_USER_NOT_ACTIVE',
      'gate EXCLUDED_BOUND_PERSONEL_NOT_ACTIVE',
      'gate EXCLUDED_PROTECTED_USERNAME',
      'gate EXCLUDED_BINDING_MISSING',
      'gate EXCLUDED_BOUND_PERSONEL_MISSING',
      'gate NAME_UNRESOLVED_COUNT',
      'gate COLLISION_COUNT',
      'gate NAME_CORRECTION_COUNT',
      'gate NAME_CORRECTION_PREIMAGE_MISMATCH_COUNT',
      'gate BUSINESS_OVERRIDE_COUNT',
      'gate PROTECTED_USERNAME_IN_PLAN_COUNT',
      'gate ROLLOUT_EXCLUDED_IN_PLAN_COUNT',
      'gate COHORT_RECONCILED',
      'gate ILKERA_TOUCHED',
      'gate DECISION_APPLY',
      'gate APPLIED_COUNT',
      'gate USER_MUTATION_COUNT',
      'gate NAME_CORRECTION_MUTATION_COUNT',
      'gate PRODUCTION_MUTATION_COUNT',
      'gate CREDENTIAL_TARGETS_RECONCILED',
      'gate EXCLUDED_UNCHANGED',
      'gate ILKERA_UNCHANGED',
      'gate ROLLOUT_LEDGER_RECORDED',
      'gate PREIMAGE_WRITTEN',
      'gate PLAN_FINGERPRINT',
      'gate APPLY_RESULT',
      'gate BLOCKER_COUNT',
    ]) {
      expect(workflow).toContain(gate);
    }
    for (const username of EXPECTED_USERNAMES) {
      expect(workflow).toContain(`EXPECTED_GATE_USERNAME_\${username}`);
    }
    expect(workflow).toContain('PLAN_USERNAME_NOT_UNIQUE');
    expect(workflow).toContain('PRODUCTION_EXPECTATION_MISMATCH_');
    expect(workflow).toContain('APPLY_RESULT=PASS');
  });

  it('never invokes a CLI credential worker from CI', () => {
    expect(workflow).not.toMatch(/php\s+(?:-[a-z]+\s+)*api\//);
    expect(workflow).not.toContain('--apply');
    expect(workflow).not.toContain('--dry-run');
    expect(cliWorker).toContain('migrateCanonicalFirstLoginCredentials');
  });

  it('judges only this run\u2019s own request, never the archived control-plane history', () => {
    const step = workflow.slice(workflow.indexOf('Verify control plane is left clean'));
    expect(step.length).toBeGreaterThan(0);
    expect(step).toContain(
      'source "$GITHUB_WORKSPACE/scripts/deploy/cpanel-control-plane-cleanliness-lib.sh"',
    );
    expect(step).toContain(
      'assert_control_plane_clean_for_request "$before_listing" "$after_listing" "$request_id" "${expected_extra[@]}"',
    );
    // The first-ever APPLY legitimately persists its own two control-plane outputs. They are
    // declared as an EXACT allowlist of two filenames — never a wildcard, never a
    // personel-first-login-* prefix — so an unrelated new file still blocks.
    expect(step).toContain('expected_extra=(');
    expect(step).toContain('"personel-first-login-apply.json"');
    expect(step).toContain('"personel-first-login-apply-preimage.json"');
    expect(step).not.toMatch(/personel-first-login-\*/);
    expect(step).toContain('before_listing="$RUNNER_TEMP/personel-first-login-apply/control-listing.txt"');
    expect(step).toContain('APPLY_CONTROL_PLANE_CLEAN=YES');
    // A historical failed request must never be re-judged by a global pattern match.
    expect(step).not.toContain("grep -q 'request");
    expect(step).not.toContain('request.failed.');

    const baselineIndex = workflow.indexOf('APPLY_CONTROL_PLANE_BASELINE=');
    expect(baselineIndex).toBeGreaterThan(0);
    expect(baselineIndex).toBeLessThan(workflow.indexOf('request.pending.${REQUEST_ID}.json'));
  });

  it('shares the control-plane group and request-id contract with the read-only preflight', () => {
    expect(preflightWorkflow).toContain('group: cpanel-canonical-migration-control');
    expect(workflow).toContain('group: cpanel-canonical-migration-control');
    expect(workflow).toContain('REQUEST_ID="pflcapply-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"');
    expect(preflightWorkflow).toContain('REQUEST_ID="pflcpre-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"');
  });
});

describe('personel first-login apply heavy acceptance coverage', () => {
  it('drives the apply owner on a disposable database', () => {
    expect(heavyRunner).toContain('PersonelFirstLoginCredentialsApplyReport');
    expect(heavyRunner).toContain('PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY');
    expect(heavyRunner).toContain('ERR_ROLLOUT_ALREADY_APPLIED');
    expect(heavyRunner).toContain('preimage');
  });
});
