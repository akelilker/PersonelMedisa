import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const read = (relativePath: string) => readFileSync(resolve(process.cwd(), relativePath), 'utf8');

const worker = read('api/bin/cpanel-migration-cron.php');
const reportOwner = read('api/src/Services/Auth/PersonelFirstLoginCredentialsPreflightReport.php');
const service = read('api/src/Services/Auth/PersonelAccountOnboardingService.php');
const workflow = read('.github/workflows/ops-personel-first-login-preflight.yml');
const heavyRunner = read('tests/php/PersonelFirstLoginCredentialsMysqlTestRunner.php');
const cliWorker = read('api/bin/personel-first-login-credentials.php');

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
const READ_ONLY_BRANCH = "if ($mode === 'READ_ONLY_PREFLIGHT') {";

const personelBlock = worker.slice(worker.indexOf(PERSONEL_BRANCH), worker.indexOf(READ_ONLY_BRANCH));

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
  it('is allowlisted as its own mode with no apply counterpart', () => {
    expect(worker).toContain("|PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT)$/'");
    // The mode is read-only by construction: there is nothing to authorize a write with.
    expect(worker).not.toContain('PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY');
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

  it('does not take over the canonical CLI worker', () => {
    expect(reportOwnerCode).not.toContain('personel-first-login-credentials.php');
    expect(cliWorker).toContain('migrateCanonicalFirstLoginCredentials');
  });
});

describe('personel first-login preflight workflow', () => {
  it('is a single fixed read-only mode behind an explicit confirmation', () => {
    expect(workflow).toContain('name: Ops personel first-login preflight (read-only)');
    expect(workflow).toContain('MODE: PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT');
    expect(workflow).toContain('test "$CONFIRMATION" = "$MODE"');
    // No mode choice list: an operator cannot select anything but the read-only mode.
    expect(workflow).not.toContain('options: [');
    // The apply literal exists exactly once, and only as the deployed-worker guard
    // that refuses a worker carrying an apply sibling for this mode.
    expect(workflow.match(/PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY/g) ?? []).toHaveLength(1);
    expect(workflow).toContain('UNEXPECTED_PERSONEL_FIRST_LOGIN_APPLY_MODE');
    expect(workflow).toContain('group: cpanel-canonical-migration-control');
    expect(workflow).toContain('permissions:\n  contents: read');
  });

  it('blocks a public repository before any FTP contact', () => {
    const boundaryIndex = workflow.indexOf('PUBLIC_REPOSITORY_ARTIFACT_EXPOSURE');
    expect(boundaryIndex).toBeGreaterThan(0);
    expect(boundaryIndex).toBeLessThan(workflow.indexOf('ftp://${FTP_SERVER}'));
    expect(workflow).toContain('if [ "${REPOSITORY_PRIVATE:-}" != "true" ]; then');
    expect(workflow).toContain('if: always() && github.event.repository.private == true');
  });

  it('gates the one-time production expectations hard fail-closed', () => {
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
      'gate COHORT_RECONCILED',
      'gate ILKERA_TOUCHED',
      'gate DECISION_APPLY',
      'gate DECISION_APPLIED_COUNT',
      'gate PRODUCTION_MUTATION_COUNT',
      'gate PREFLIGHT_RESULT',
      'gate BLOCKER_COUNT',
    ]) {
      expect(workflow).toContain(gate);
    }

    for (const username of EXPECTED_USERNAMES) {
      expect(workflow).toContain(`EXPECTED_GATE_USERNAME_\${username}`);
    }
    expect(workflow).toContain('PLAN_USERNAME_NOT_UNIQUE');
    expect(workflow).toContain('PRODUCTION_EXPECTATION_MISMATCH_');
  });

  it('proves read-only before the expectation gate can pass', () => {
    const readOnlyIndex = workflow.indexOf('READ_ONLY_PROOF=APPLY_FALSE_APPLIED_COUNT_ZERO_MUTATION_COUNT_ZERO');
    const expectationIndex = workflow.indexOf('Enforce one-time production expectations');
    expect(readOnlyIndex).toBeGreaterThan(0);
    expect(expectationIndex).toBeGreaterThan(readOnlyIndex);
    expect(workflow).toContain('READ_ONLY_PROOF_DECISION_APPLY');
    expect(workflow).toContain('READ_ONLY_PROOF_APPLIED_COUNT');
    expect(workflow).toContain('READ_ONLY_PROOF_MUTATION_COUNT');
    expect(workflow).toContain('PREFLIGHT_REPORT_REQUEST_MISMATCH');
    expect(workflow).toContain('PREFLIGHT_REPORT_SHA_MISMATCH');
    expect(workflow).toContain('REMOTE_WORKER_PARITY_MISMATCH');
    expect(workflow).toContain('UNEXPECTED_PERSONEL_FIRST_LOGIN_APPLY_MODE');
  });

  it('writes a request with no field that could ask for a mutation', () => {
    const requestBlock = workflow.slice(
      workflow.indexOf('jq -n \\'),
      workflow.indexOf('run_cpanel_ftp "\n            lcd ${request_dir};'),
    );
    expect(requestBlock).toContain('mode: "PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT"');
    expect(requestBlock).not.toContain('"APPLY"');
    expect(requestBlock).not.toContain('target_version');
    expect(requestBlock).not.toContain('personel_id');
    expect(requestBlock).not.toContain('username');
  });

  it('never invokes a CLI credential worker from CI', () => {
    // The header comment names the canonical CLI worker to say it is NOT used here.
    expect(workflow).not.toMatch(/php\s+(?:-[a-z]+\s+)*api\//);
    expect(workflow).not.toContain('--apply');
    expect(workflow).not.toContain('--dry-run');
  });

  it('verifies the control plane is left clean after the read', () => {
    expect(workflow).toContain('LEFTOVER_PENDING_REQUEST');
    expect(workflow).toContain('LEFTOVER_PROCESSING_REQUEST');
    expect(workflow).toContain('FAILED_REQUEST_PRESENT');
    expect(workflow).toContain('REQUEST_NOT_ARCHIVED');
    expect(workflow).toContain('PREFLIGHT_CONTROL_PLANE_CLEAN=YES');
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
