<?php

declare(strict_types=1);

/**
 * Focused final-close approved-preimage drift report — no DB access, no mutation.
 *
 * Every approved comparison used to fail closed on the first mismatch, so a failed
 * production preflight could surface at most one problem per run. This runner proves
 * the bounded replacement contract on the real owners
 * (FinalCloseSnapshot::approvedPreimageDrifts / assertApprovedPreimage / matches):
 * one read-only run reports every mismatch at once, each as the bounded token
 * FINAL_CLOSE_PREIMAGE_DRIFT_<CATEGORY>_<ID>_<FIELD>_<CLASS>, while the mutation path
 * keeps its first-mismatch fail-closed guard, the generic prefix, the approved values
 * and the policy axis stay exactly as they are.
 *
 * php tests/php/FinalClosePreimageDriftAttributionTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Operations\FinalClosePackage;
use Medisa\Api\Services\Operations\FinalCloseSnapshot;

/** The generic prefix every drift token keeps. */
const FCPDA_PREFIX = 'FINAL_CLOSE_PREIMAGE_DRIFT';
/** The policy axis keeps its own pre-existing bounded code. */
const FCPDA_POLICY = 'FINAL_CLOSE_POLICY_PREIMAGE_DRIFT';
/** The bounded single-token contract of the worker/transport classifier. */
const FCPDA_TOKEN = '/^[A-Z][A-Z0-9_]{2,100}$/D';
/** The most tokens one whole-run report may carry (the transport bound). */
const FCPDA_MAX_TOKENS = 100;
/** Every canonical drift axis the collector is allowed to attribute. */
const FCPDA_CATEGORIES = ['USER', 'PERSONNEL', 'SCOPE', 'ACTOR', 'BRANCH', 'POLICY'];

$GLOBALS['fcpdaPassed'] = 0;
$GLOBALS['fcpdaTokens'] = [];

function fcpdaAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    $GLOBALS['fcpdaPassed']++;
    echo '[PASS] ' . $name . PHP_EOL;
}

/** One produced token must always satisfy the bounded contract. */
function fcpdaTokenIsBounded(string $code): bool
{
    return strlen($code) <= 100 && preg_match(FCPDA_TOKEN, $code) === 1;
}

/** Records every token the owner hands out, for the bounded-shape sweep at the end. */
function fcpdaRecord(array $codes): array
{
    foreach ($codes as $code) {
        $GLOBALS['fcpdaTokens'][] = (string) $code;
    }

    return $codes;
}

/** Runs one read-only whole-preimage report. Collecting must never throw. */
function fcpdaDrifts(array $s): array
{
    return fcpdaRecord(FinalCloseSnapshot::approvedPreimageDrifts($s));
}

/** Order-independent view of one report. */
function fcpdaSorted(array $drifts): array
{
    $sorted = $drifts;
    sort($sorted);

    return $sorted;
}

/** @return array<string, mixed> the approved preimage, exactly as the package compiles it */
function fcpdaPreimage(): array
{
    $s = ['personnel' => [], 'users' => [], 'actors' => [], 'branches' => [], 'policies' => [12 => [], 13 => []]];
    foreach (FinalClosePackage::USERS as $id => $identity) {
        $s['users'][$id] = $identity + ['id' => $id, 'durum' => 'AKTIF'];
    }
    foreach (FinalClosePackage::SCOPES_BEFORE as $id => $scope) {
        $s['users'][$id]['sube_ids'] = $scope;
    }
    $s['users'][50]['sube_ids'] = [2];
    foreach (FinalClosePackage::PERSONNEL as $id) {
        $s['personnel'][$id] = ['id' => $id, 'calisma_lokasyonu_id' => null];
    }
    $s['personnel'][203] = $s['personnel'][203] + ['ad' => 'MUHAMMED IRAKLI', 'soyad' => null, 'aktif_durum' => 'AKTIF', 'sube_id' => 1, 'sirket_id' => 1];
    $s['personnel'][210] = $s['personnel'][210] + ['sube_id' => 6];
    $s['personnel'][212] = $s['personnel'][212] + ['sube_id' => null, 'sirket_id' => null];
    $s['actors'][110] = ['actor_identity_id' => null, 'actor_status' => null];
    $s['actors'][11] = ['actor_status' => 'VERIFIED'];
    foreach (array_keys(FinalClosePackage::MANAGERS) as $id) {
        $s['branches'][$id] = ['id' => $id, 'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => []];
    }

    return $s;
}

/** Reads one private owner method for the bounded-shape assertions. */
function fcpdaPrivate(string $method)
{
    $reflection = new ReflectionMethod(FinalCloseSnapshot::class, $method);
    $reflection->setAccessible(true);

    return $reflection;
}

// 1) The approved preimage is still exactly the approved preimage: no drift, and the
//    fail-closed guard the mutation path uses still accepts it.
$clean = fcpdaPreimage();
$cleanDrifts = fcpdaDrifts($clean);
fcpdaAssert($cleanDrifts === [], 'the approved preimage reports no drift at all');
$cleanGuard = true;
try {
    FinalCloseSnapshot::assertApprovedPreimage($clean);
} catch (Throwable $error) {
    $cleanGuard = false;
}
fcpdaAssert($cleanGuard, 'the fail-closed guard still accepts the approved preimage');

// 2) One drifted axis in a late position is still reported: the report is not
//    short-circuited by the first comparison that happens to pass.
$late = fcpdaPreimage();
$late['branches'][13]['sorumlu_yonetici_user_ids'] = [50];
$lateDrifts = fcpdaDrifts($late);
fcpdaAssert(
    $lateDrifts === [FCPDA_PREFIX . '_BRANCH_13_SORUMLU_YONETICI_USER_IDS_COUNT'],
    'a late, single drift is reported on its own'
);

// 3) Six independent axes in one run: every mismatch is reported at once instead of
//    only the first one, and the policy axis stays deduplicated.
$drifting = fcpdaPreimage();
$drifting['users'][110]['rol'] = 'BOLUM_YONETICISI';
$drifting['personnel'][203]['sube_id'] = 9;
$drifting['users'][11]['sube_ids'] = array_merge(FinalClosePackage::SCOPES_BEFORE[11], [12]);
$drifting['actors'][11]['actor_status'] = 'PENDING';
$drifting['branches'][12]['sorumlu_yonetici_user_ids'] = [110];
$drifting['policies'][12] = [['policy_id' => 1]];
$drifting['policies'][13] = [['policy_id' => 2]];
$all = fcpdaDrifts($drifting);
$expected = [
    FCPDA_PREFIX . '_USER_110_ROL_VALUE',
    FCPDA_PREFIX . '_PERSONNEL_203_SUBE_ID_VALUE',
    FCPDA_PREFIX . '_SCOPE_11_SUBE_IDS_COUNT',
    FCPDA_PREFIX . '_ACTOR_11_ACTOR_STATUS_VALUE',
    FCPDA_PREFIX . '_BRANCH_12_SORUMLU_YONETICI_USER_IDS_COUNT',
    FCPDA_POLICY,
];
fcpdaAssert(count($all) === 6, 'one run reports every drifted axis, not just the first (6 drifts)');
fcpdaAssert(
    fcpdaSorted($all) === fcpdaSorted($expected),
    'each axis answers with its own category, id, field and mismatch class'
);
fcpdaAssert(
    count(array_unique($all)) === 6,
    'the whole-run report holds each drift exactly once (both drifted policies collapse to one code)'
);

// 4) The mutation path is unchanged: it still stops at the first mismatch, with the
//    unchanged bounded family, before any canonical write may happen.
$guardDrift = '';
try {
    FinalCloseSnapshot::assertApprovedPreimage($drifting);
} catch (Throwable $error) {
    $guardDrift = (string) $error->getMessage();
}
fcpdaRecord([$guardDrift]);
fcpdaAssert(
    $guardDrift === FCPDA_PREFIX . '_USER_110_ROL_VALUE',
    'the mutation guard still stops at the first mismatch and still throws one bounded token'
);

// 5) A mismatch class is shape-only: an absent field, a null side, a different type, a
//    different list size, a same-size list with other members and a different scalar
//    are all told apart without ever naming the value.
$shapes = fcpdaPreimage();
$shapes['personnel'][212]['sube_id'] = 3;
$shapes['users'][10]['username'] = 12345;
$shapes['users'][50]['sube_ids'] = [3];
unset($shapes['actors'][110]['actor_status']);
$shapes['personnel'][200]['id'] = 201;
$shapes['personnel'][210]['sube_id'] = '6';
$shapeDrifts = fcpdaDrifts($shapes);
fcpdaAssert(
    fcpdaSorted($shapeDrifts) === fcpdaSorted([
        FCPDA_PREFIX . '_PERSONNEL_212_SUBE_ID_NULL',
        FCPDA_PREFIX . '_USER_10_USERNAME_TYPE',
        FCPDA_PREFIX . '_SCOPE_50_SUBE_IDS_IDS',
        FCPDA_PREFIX . '_ACTOR_110_ACTOR_STATUS_MISSING',
        FCPDA_PREFIX . '_PERSONNEL_200_ID_VALUE',
        FCPDA_PREFIX . '_PERSONNEL_210_SUBE_ID_TYPE',
    ]),
    'MISSING, NULL, TYPE, COUNT, IDS and VALUE are told apart as shape-only classes'
);

// 6) Anything outside the compiled allowlist degrades to the bare generic prefix
//    instead of widening the token with caller text.
$reason = fcpdaPrivate('driftReason');
$validReason = $reason->invoke(null, 'USER', 10, 'ROL', 'VALUE');
fcpdaRecord([$validReason]);
fcpdaAssert(
    $validReason === FCPDA_PREFIX . '_USER_10_ROL_VALUE'
        && $reason->invoke(null, 'SUBELER', 10, 'ROL', 'VALUE') === FCPDA_PREFIX
        && $reason->invoke(null, 'USER', 999, 'ROL', 'VALUE') === FCPDA_PREFIX
        && $reason->invoke(null, 'USER', 10, 'personel_maas', 'VALUE') === FCPDA_PREFIX
        && $reason->invoke(null, 'USER', 10, 'ROL', 'PDO_EXCEPTION') === FCPDA_PREFIX
        && $reason->invoke(null, 'USER', 10, '', 'VALUE') === FCPDA_PREFIX
        && $reason->invoke(null, "USER'; DROP TABLE users; --", 10, 'ROL', 'VALUE') === FCPDA_PREFIX,
    'an unknown category, target, field or class degrades to the generic prefix'
);

// 7) The target set is the compiled package, never a caller value.
$targets = fcpdaPrivate('driftTargets');
$map = $targets->invoke(null);
fcpdaAssert(
    is_array($map) && array_keys($map) === FCPDA_CATEGORIES
        && $map['USER'] === array_keys(FinalClosePackage::USERS)
        && $map['PERSONNEL'] === FinalClosePackage::PERSONNEL
        && $map['SCOPE'] === array_values(array_unique(array_merge(array_keys(FinalClosePackage::SCOPES_BEFORE), [50])))
        && $map['ACTOR'] === array_keys(FinalClosePackage::USERS)
        && $map['BRANCH'] === array_keys(FinalClosePackage::MANAGERS)
        && $map['POLICY'] === [12, 13],
    'every drift target is a compiled package id, never a caller value'
);

// 8) Poisoned rows: a personnel name, a username, a manager id list, a policy version
//    and SQL text must not reach a single token.
$poison = fcpdaPreimage();
$poison['personnel'][203]['ad'] = "MUHAMMED'; DROP TABLE personeller; --";
$poison['users'][110]['username'] = 'gizli-kullanici';
$poison['branches'][12]['sorumlu_yonetici_user_ids'] = [987654];
$poison['policies'][13] = [['surum_kodu' => 'S98-R1-POL-13', 'politika_hash' => str_repeat('a', 64)]];
$poisonDrifts = fcpdaDrifts($poison);
$poisonText = implode("\n", $poisonDrifts);
$poisonLeak = false;
foreach (['MUHAMMED', 'IRAKLI', 'gizli-kullanici', '987654', 'S98-R1-POL', 'DROP', 'TABLE', 'SELECT', 'UPDATE', 'PDO', '--', ';', "'", '"', ' ', '('] as $leak) {
    $poisonLeak = $poisonLeak || strpos($poisonText, $leak) !== false;
}
fcpdaAssert(
    count($poisonDrifts) === 4 && !$poisonLeak
        && fcpdaSorted($poisonDrifts) === fcpdaSorted([
            FCPDA_PREFIX . '_PERSONNEL_203_AD_VALUE',
            FCPDA_PREFIX . '_USER_110_USERNAME_VALUE',
            FCPDA_PREFIX . '_BRANCH_12_SORUMLU_YONETICI_USER_IDS_COUNT',
            FCPDA_POLICY,
        ]),
    'a poisoned row yields categories only: no value, no id list, no SQL, no exception text'
);

// 9) Worst case: a fully unusable snapshot reports every expected field once, and the
//    whole list still fits inside the transport bound that the control plane accepts.
$emptyDrifts = fcpdaDrifts([]);
$emptyUnique = array_values(array_unique($emptyDrifts));
$emptyBounded = true;
foreach ($emptyDrifts as $token) {
    $emptyBounded = $emptyBounded && fcpdaTokenIsBounded($token);
}
fcpdaAssert(
    count($emptyDrifts) === 73 && count($emptyUnique) === 73 && $emptyBounded
        && count($emptyDrifts) <= FCPDA_MAX_TOKENS,
    'an unusable snapshot reports all 73 expected fields once, inside the 100-token transport bound'
);

// 10) The generic single-token contract the worker/transport classifier depends on is
//     untouched: a scope-less caller still gets one bounded FINAL_CLOSE code.
$matchValue = '';
$matchMissing = '';
try {
    FinalCloseSnapshot::matches(['sube_id' => 1], ['sube_id' => 2]);
} catch (Throwable $error) {
    $matchValue = (string) $error->getMessage();
}
try {
    FinalCloseSnapshot::matches(['id' => 1], ['id' => 1, 'durum' => 'AKTIF']);
} catch (Throwable $error) {
    $matchMissing = (string) $error->getMessage();
}
fcpdaRecord([$matchValue, $matchMissing]);
fcpdaAssert(
    $matchValue === FCPDA_PREFIX && $matchMissing === FCPDA_PREFIX,
    'a scope-less match keeps the untouched generic single-token contract'
);

// 11) The read-only snapshot path is no longer gated by the identity frame guard, and
//     the mutation path still verifies the identity frame before any canonical write.
$ownersSource = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Operations/FinalCloseOwners.php');
$snapshotBranch = strpos($ownersSource, "if (\$op === 'snapshot') {");
$frameGuard = strpos($ownersSource, "FinalCloseSnapshot::matches(\$snapshot['users'][\$id]");
fcpdaAssert(
    $snapshotBranch !== false && $frameGuard !== false && $snapshotBranch < $frameGuard
        && strpos($ownersSource, "'FRAME_USER_' . \$id") === false,
    'the read-only snapshot path is released while the frame guard stays on the mutation path'
);
fcpdaAssert(
    strpos($ownersSource, "throw new RuntimeException('FINAL_CLOSE_PREIMAGE_DRIFT');") !== false
        && strpos($ownersSource, "FinalCloseSnapshot::matches(\$authenticated,") !== false
        && strpos($ownersSource, "\$identity + ['id' => \$id, 'durum' => 'AKTIF']") !== false,
    'the mutation path still checks the captured frame and the approved preimage'
);

// 12) A failed preflight publishes a bounded FAIL report without an apply checksum, the
//     apply path still cannot run without a 64-char preflight checksum, and the failure
//     report carries no snapshot, no SQL and no exception text.
$serviceSource = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Operations/FinalCloseService.php');
$reportStart = strpos($serviceSource, 'private static function preflightDriftReport');
$reportEnd = strpos($serviceSource, 'private static function dependencyBlocker');
$reportBlock = $reportStart === false || $reportEnd === false ? '' : substr($serviceSource, $reportStart, $reportEnd - $reportStart);
fcpdaAssert(
    strpos($serviceSource, "\$request['mode'] === 'FINAL_CLOSE_PREFLIGHT' && \$drifts !== []") !== false
        && strpos($serviceSource, "'preflight_checksum' => null, 'result' => 'FAIL',") !== false
        && strpos($serviceSource, "'preimage_drift_count' => count(\$drifts),") !== false
        && strpos($serviceSource, "'preimage_drifts' => array_values(\$drifts),") !== false
        && strpos($serviceSource, "'production_mutation_count' => 0,") !== false
        && strpos($serviceSource, 'FinalCloseSnapshot::assertApprovedPreimage($snapshot);') !== false,
    'a failed preflight publishes a bounded FAIL report with no apply checksum and no mutation'
);
fcpdaAssert(
    $reportBlock !== ''
        && strpos($reportBlock, 'snapshot') === false
        && strpos($reportBlock, 'PDO') === false
        && strpos($reportBlock, 'getMessage') === false,
    'the failure report carries no snapshot, no SQL and no exception text'
);
$packageSource = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Operations/FinalClosePackage.php');
fcpdaAssert(
    strpos($packageSource, "\$request['mode'] === 'FINAL_CLOSE_APPLY'") !== false
        && strpos($packageSource, "!preg_match('/^[a-f0-9]{64}\$/D', \$request['preflight_checksum'])") !== false
        && strpos($packageSource, "throw new RuntimeException('FINAL_CLOSE_PREFLIGHT_REQUIRED');") !== false,
    'an apply request still cannot run without a 64-char preflight checksum'
);

// 13) The control plane accepts a drift list only when it describes the exact request
//     it is waiting for and the list itself is bounded.
$controlSource = (string) file_get_contents(__DIR__ . '/../../scripts/ops/final-close-control.py');
fcpdaAssert(
    strpos($controlSource, 'def preflight_drift_items(report, request_id, sha):') !== false
        && strpos($controlSource, "require(report.get('request_id') == request_id and report.get('deployed_sha') == sha") !== false
        && strpos($controlSource, "'FINAL_CLOSE_PREIMAGE_REPORT_INVALID'") !== false
        && strpos($controlSource, "'FINAL_CLOSE_PREIMAGE_DRIFT_INVALID'") !== false
        && strpos($controlSource, 'and isinstance(count, int) and not isinstance(count, bool)') !== false
        && strpos($controlSource, 'and isinstance(drifts, list) and 0 < count <= 100 and count == len(drifts)') !== false
        && strpos($controlSource, "and all(isinstance(item, str) and re.fullmatch('[A-Z0-9_]{1,100}', item) for item in drifts),") !== false,
    'the control plane trusts a drift list only when it is correlated and bounded'
);

// 14) A failed preflight logs the correlated bounded tokens and nothing else: the read
//     happens before the failure is raised and no raw report content is printed.
$readReport = strpos($controlSource, "drift_report = json.loads(get(base + 'final-close-report.json', 'report'))");
$logCount = strpos($controlSource, "print('FINAL_CLOSE_PREIMAGE_DRIFT_COUNT=' + str(len(drifts)))");
$logItem = strpos($controlSource, "print('FINAL_CLOSE_PREIMAGE_DRIFT_ITEM=' + item)");
$raiseWorker = strpos($controlSource, "raise RuntimeError('FINAL_CLOSE_WORKER_FAILED')");
fcpdaAssert(
    $readReport !== false && $logCount !== false && $logItem !== false && $raiseWorker !== false
        && $readReport < $logCount && $logCount < $logItem && $logItem < $raiseWorker
        && strpos($controlSource, "if mode == 'FINAL_CLOSE_PREFLIGHT':") !== false
        && strpos($controlSource, 'print(drift_report)') === false
        && strpos($controlSource, 'print(drifts)') === false,
    'a failed preflight logs its correlated bounded tokens only, never raw report content'
);

// 15) One owner, no second drift system, and the diagnostic block never serializes a
//     value, a row or an exception: the closed allowlists carry the whole reason. The
//     approved expectation itself stays in one place, inside the comparison table.
$snapshotSource = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Operations/FinalCloseSnapshot.php');
$driftStart = strpos($snapshotSource, 'public static function approvedPreimageDrifts');
$driftEnd = strpos($snapshotSource, 'public static function checksum');
$driftBlock = $driftStart === false || $driftEnd === false ? '' : substr($snapshotSource, $driftStart, $driftEnd - $driftStart);
$driftLeak = false;
foreach (['json_encode', 'var_export', 'print_r', 'serialize', 'implode', 'getMessage', 'PDO', 'SELECT', 'sinemH', 'ilkerA'] as $needle) {
    $driftLeak = $driftLeak || strpos($driftBlock, $needle) !== false;
}
fcpdaAssert(
    $driftBlock !== '' && !$driftLeak
        && substr_count($snapshotSource, 'public static function approvedPreimageDrifts(') === 1
        && substr_count($snapshotSource, 'private static function comparisonDrifts(') === 1
        && substr_count($snapshotSource, 'public static function matches(') === 1
        && substr_count($snapshotSource, 'function assertApprovedPreimage(') === 1
        && substr_count($snapshotSource, "'FINAL_CLOSE_PREIMAGE_DRIFT'") === 1
        && substr_count($snapshotSource, "'FINAL_CLOSE_POLICY_PREIMAGE_DRIFT'") === 1
        && substr_count($snapshotSource, 'private static function driftReason(') === 1
        && substr_count($snapshotSource, 'private static function driftTargets(') === 1
        && substr_count($snapshotSource, 'private static function driftField(') === 1
        && substr_count($snapshotSource, "'MUHAMMED IRAKLI'") === 1,
    'one comparison owner, and its diagnostic block never serializes a value or an exception'
);

// 16) Personel 203 is the single approved expectation whose live shape was re-verified:
//     the canonical row keeps a NULL surname, so the approved contract expects NULL and
//     a stale empty string stays a strict drift. NULL and '' are never treated as
//     equivalent — not by the collector, not by the mutation guard and not by the
//     generic comparator the apply-phase postcheck uses.
$nullSurname = fcpdaPreimage();
fcpdaAssert(
    array_key_exists('soyad', $nullSurname['personnel'][203])
        && $nullSurname['personnel'][203]['soyad'] === null,
    'the approved personel 203 preimage carries the canonical NULL surname'
);
fcpdaAssert(
    fcpdaDrifts($nullSurname) === [],
    'a canonical NULL surname passes the approved personel 203 preimage'
);

$staleEmptySurname = fcpdaPreimage();
$staleEmptySurname['personnel'][203]['soyad'] = '';
$staleDrifts = fcpdaDrifts($staleEmptySurname);
$staleGuard = '';
try {
    FinalCloseSnapshot::assertApprovedPreimage($staleEmptySurname);
} catch (Throwable $error) {
    $staleGuard = (string) $error->getMessage();
}
fcpdaRecord([$staleGuard]);
fcpdaAssert(
    $staleDrifts === [FCPDA_PREFIX . '_PERSONNEL_203_SOYAD_NULL']
        && $staleGuard === FCPDA_PREFIX . '_PERSONNEL_203_SOYAD_NULL',
    'a stale empty-string surname is a strict drift, never an equivalent of NULL'
);

$nullVersusEmpty = '';
$emptyVersusNull = '';
try {
    FinalCloseSnapshot::matches(['soyad' => null], ['soyad' => '']);
} catch (Throwable $error) {
    $nullVersusEmpty = (string) $error->getMessage();
}
try {
    FinalCloseSnapshot::matches(['soyad' => ''], ['soyad' => null]);
} catch (Throwable $error) {
    $emptyVersusNull = (string) $error->getMessage();
}
fcpdaRecord([$nullVersusEmpty, $emptyVersusNull]);
fcpdaAssert(
    $nullVersusEmpty === FCPDA_PREFIX && $emptyVersusNull === FCPDA_PREFIX,
    'the generic comparator keeps NULL and an empty string strictly distinct'
);

// 17) The write side of personel 203 is untouched: the approved target correction still
//     writes Muhammed / Mahmud and the preflight still publishes zero mutations.
$nameOwnerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Operations/FinalCloseOwners.php');
$namePostcheckSource = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Operations/FinalClosePostcheck.php');
fcpdaAssert(
    strpos($nameOwnerSource, "\$body = ['ad' => 'Muhammed', 'soyad' => 'Mahmud'];") !== false
        && strpos($namePostcheckSource, "\$expected['personnel'][203]['ad'] = 'Muhammed';") !== false
        && strpos($namePostcheckSource, "\$expected['personnel'][203]['soyad'] = 'Mahmud';") !== false
        && strpos($serviceSource, "'production_mutation_count' => 0,") !== false,
    'the personel 203 target name correction stays Muhammed / Mahmud'
);

// 18) Final sweep: every token this run produced stays inside the bounded contract the
//     worker, the transport and the control plane all apply.
$allBounded = count($GLOBALS['fcpdaTokens']) > 0;
$longest = 0;
foreach ($GLOBALS['fcpdaTokens'] as $token) {
    $allBounded = $allBounded && fcpdaTokenIsBounded($token) && strpos($token, 'FINAL_CLOSE_') === 0;
    $longest = max($longest, strlen($token));
}
fcpdaAssert(
    $allBounded && $longest <= FCPDA_MAX_TOKENS,
    'every produced token is bounded, prefixed, token-only and <= 100 chars (longest ' . $longest . ')'
);

echo 'FINAL_CLOSE_PREIMAGE_DRIFT_ATTRIBUTION: OK checks=' . $GLOBALS['fcpdaPassed']
    . ' tokens=' . count($GLOBALS['fcpdaTokens']) . ' longest=' . $longest . PHP_EOL;
