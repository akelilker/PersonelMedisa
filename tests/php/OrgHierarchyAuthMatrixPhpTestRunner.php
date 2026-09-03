<?php

declare(strict_types=1);

/**
 * In-process OrgScope security matrix (no MariaDB).
 * Exit 0 = PASS; non-zero = FAIL.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;

function ohFail($msg)
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function ohOk($msg)
{
    echo "OK: {$msg}\n";
}

function ohUser($rol, array $sube = [], array $bolum = [], array $birim = [], $personelId = null, array $sirket = [], array $sgk = [])
{
    $u = [
        'id' => 10,
        'rol' => $rol,
        'sube_ids' => $sube,
        'bolum_ids' => $bolum,
        'birim_ids' => $birim,
        'sirket_ids' => $sirket,
        'sgk_isveren_ids' => $sgk,
    ];
    if ($personelId !== null) {
        $u['personel_id'] = $personelId;
    }

    return $u;
}

function ohFilterSql(array $user, $activeSube = null)
{
    $where = [];
    $params = [];
    OrgScope::appendPersonelOrgFilter($where, $params, $user, $activeSube, 'p');

    return ['where' => $where, 'params' => $params];
}

function ohRunAssertChild(array $user, $personelOrg)
{
    $child = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'org-hierarchy-assert-child.php';
    $payload = base64_encode(json_encode([
        'user' => $user,
        'org' => $personelOrg,
    ], JSON_UNESCAPED_UNICODE));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' ' . escapeshellarg($payload);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);

    return ['code' => $code, 'out' => implode("\n", $out)];
}

function ohAssertAllows(array $user, $personelOrg, $label)
{
    $r = ohRunAssertChild($user, $personelOrg);
    if ($r['code'] !== 0 || strpos($r['out'], 'ALLOW') === false) {
        ohFail("{$label}: expected ALLOW, code={$r['code']} out={$r['out']}");
    }
    ohOk($label);
}

function ohAssertDenies(array $user, $personelOrg, $label)
{
    $r = ohRunAssertChild($user, $personelOrg);
    if (strpos($r['out'], 'ALLOW') !== false) {
        ohFail("{$label}: expected DENY but ALLOWED");
    }
    if (strpos($r['out'], 'FORBIDDEN') === false && $r['code'] === 0) {
        ohFail("{$label}: expected FORBIDDEN/exit, code={$r['code']} out={$r['out']}");
    }
    ohOk($label);
}

// --- Filter matrix (no exit) ---
$f = ohFilterSql(ohUser('GENEL_YONETICI', []));
if ($f['where'] !== []) {
    ohFail('GENEL empty filter should be unrestricted');
}
ohOk('GENEL_EMPTY_SCOPE=global');

$f = ohFilterSql(ohUser('SISTEM_YONETICISI', []));
if ($f['where'] !== []) {
    ohFail('SISTEM empty filter should be unrestricted');
}
ohOk('SISTEM_EMPTY_SCOPE=global');

$f = ohFilterSql(ohUser('IK_SORUMLUSU', []));
if ($f['where'] !== []) {
    ohFail('IK empty filter should be organisation-global (no branch predicate)');
}
ohOk('IK_EMPTY_SCOPE=global_read');

$f = ohFilterSql(ohUser('IK_PERSONELI', [1], [], [], null, [9]));
if ($f['where'] !== []) {
    ohFail('IK_PERSONELI must ignore materialised company/branch grants on read');
}
ohOk('IK_PERSONELI_COMPANY_GRANT_IGNORED_ON_READ');

$f = ohFilterSql(ohUser('MUHASEBE', []));
if (!in_array('1=0', $f['where'], true)) {
    ohFail('MUHASEBE empty must deny filter');
}
ohOk('MUHASEBE_EMPTY_SCOPE=DENY');

$f = ohFilterSql(ohUser('MUHASEBE', [1, 2]));
if (strpos(implode(' ', $f['where']), 'sube_id') === false) {
    ohFail('MUHASEBE branch-only must filter sube');
}
ohOk('MUHASEBE_BRANCH_ONLY=ALLOW_FILTER');

$f = ohFilterSql(ohUser('MUHASEBE', [], [], [], null, [], [77]));
$pred = implode(' ', $f['where']);
if (strpos($pred, 'sgk_isveren_id') === false) {
    ohFail('MUHASEBE SGK-only must filter personeller.sgk_isveren_id');
}
if (strpos($pred, 'sube_id IN') !== false && strpos($pred, 'sgk_isveren_id') === false) {
    ohFail('SGK grant must not be rewritten as a physical branch IN list');
}
ohOk('MUHASEBE_SGK_ONLY=PAYROLL_AXIS');

$f = ohFilterSql(ohUser('MUHASEBE', [1], [], [], null, [], [77]));
$pred = implode(' ', $f['where']);
if (strpos($pred, 'sube_id') === false || strpos($pred, 'sgk_isveren_id') === false || strpos($pred, ' OR ') === false) {
    ohFail('MUHASEBE mixed branch+SGK must OR the axes');
}
ohOk('MUHASEBE_BRANCH_OR_SGK=UNION');

$f = ohFilterSql(ohUser('SUBE_YONETICISI', []));
if (!in_array('1=0', $f['where'], true)) {
    ohFail('SUBE zero must deny');
}
ohOk('SUBE_ZERO=DENY');

$f = ohFilterSql(ohUser('SUBE_YONETICISI', [1]));
if (count($f['where']) !== 1 || strpos($f['where'][0], 'sube_id') === false) {
    ohFail('SUBE one must filter sube');
}
ohOk('SUBE_ONE=ALLOW_FILTER');

$f = ohFilterSql(ohUser('SUBE_YONETICISI', [1, 2]));
if (strpos(implode(' ', $f['where']), 'IN') === false) {
    ohFail('SUBE multi must IN filter');
}
ohOk('SUBE_MULTI=ASSIGNED_ONLY');

$f = ohFilterSql(ohUser('BOLUM_YONETICISI', []));
if (!in_array('1=0', $f['where'], true)) {
    ohFail('BOLUM zero unit must deny');
}
ohOk('BOLUM_ZERO=DENY');

$f = ohFilterSql(ohUser('BOLUM_YONETICISI', [], [10]));
if (strpos(implode(' ', $f['where']), 'bolum_id') === false) {
    ohFail('BOLUM own must bolum filter');
}
ohOk('BOLUM_OWN=ALLOW_FILTER');

$f = ohFilterSql(ohUser('BOLUM_YONETICISI', [1], []));
if (!in_array('1=0', $f['where'], true)) {
    ohFail('BOLUM sube-only must deny (no legacy fallback)');
}
ohOk('BOLUM_SUBE_ONLY=DENY');

$f = ohFilterSql(ohUser('BIRIM_AMIRI', []));
if (!in_array('1=0', $f['where'], true)) {
    ohFail('BIRIM zero must deny');
}
ohOk('BIRIM_ZERO=DENY');

$f = ohFilterSql(ohUser('BIRIM_AMIRI', [], [], [20]));
if (strpos(implode(' ', $f['where']), 'birim_id') === false) {
    ohFail('BIRIM own must birim filter');
}
ohOk('BIRIM_OWN=ALLOW_FILTER');

$f = ohFilterSql(ohUser('BIRIM_AMIRI', [1], [], []));
if (!in_array('1=0', $f['where'], true)) {
    ohFail('BIRIM sube-only must deny (no legacy fallback)');
}
ohOk('BIRIM_SUBE_ONLY=DENY');

$f = ohFilterSql(ohUser('PERSONEL', [], [], [], 5));
if (strpos(implode(' ', $f['where']), 'id =') === false) {
    ohFail('PERSONEL must self filter');
}
ohOk('PERSONEL_SELF=ALLOW_FILTER');

$f = ohFilterSql(ohUser('PERSONEL', [], [], [], 0));
if (!in_array('1=0', $f['where'], true)) {
    ohFail('PERSONEL unbound must deny');
}
ohOk('PERSONEL_UNBOUND=DENY');

// Production-shape: 8 canonical BY + 6 canonical BA assignment shapes remain filterable
$byShapes = [[1], [2], [3], [9], [10], [11], [13], [17]];
foreach ($byShapes as $bolumIds) {
    $f = ohFilterSql(ohUser('BOLUM_YONETICISI', [], $bolumIds));
    if (strpos(implode(' ', $f['where']), 'bolum_id') === false) {
        ohFail('production-shape BY bolum=[' . implode(',', $bolumIds) . '] must bolum filter');
    }
}
ohOk('PRODUCTION_SHAPE_BY_CANONICAL=8/8');

$baShapes = [[3], [9], [11], [25], [26], [29]];
foreach ($baShapes as $birimIds) {
    $f = ohFilterSql(ohUser('BIRIM_AMIRI', [], [], $birimIds));
    if (strpos(implode(' ', $f['where']), 'birim_id') === false) {
        ohFail('production-shape BA birim=[' . implode(',', $birimIds) . '] must birim filter');
    }
}
ohOk('PRODUCTION_SHAPE_BA_CANONICAL=6/6');

// --- assertPersonelAccess matrix via child process ---
ohAssertDenies(ohUser('SUBE_YONETICISI', []), ['id' => 1, 'sube_id' => 1], 'SUBE_ZERO_ASSERT');
ohAssertAllows(ohUser('SUBE_YONETICISI', [1]), ['id' => 1, 'sube_id' => 1], 'SUBE_ONE_ASSERT');
ohAssertDenies(ohUser('SUBE_YONETICISI', [1]), ['id' => 2, 'sube_id' => 2], 'SUBE_CROSS_ASSERT');
ohAssertAllows(ohUser('SUBE_YONETICISI', [1, 2]), ['id' => 2, 'sube_id' => 2], 'SUBE_MULTI_OWN_ASSERT');
ohAssertDenies(ohUser('SUBE_YONETICISI', [1, 2]), ['id' => 3, 'sube_id' => 3], 'SUBE_MULTI_CROSS_ASSERT');

ohAssertDenies(ohUser('BOLUM_YONETICISI', []), ['id' => 1, 'sube_id' => 1, 'bolum_id' => 10], 'BOLUM_ZERO_ASSERT');
ohAssertAllows(ohUser('BOLUM_YONETICISI', [], [10]), ['id' => 1, 'sube_id' => 1, 'bolum_id' => 10], 'BOLUM_OWN_ASSERT');
ohAssertDenies(ohUser('BOLUM_YONETICISI', [], [10]), ['id' => 2, 'sube_id' => 1, 'bolum_id' => 11], 'BOLUM_SIBLING_ASSERT');
// bolumler are catalog (⊂ departman), not branch-parented — same bolum_id on another sube remains in unit scope.
ohAssertAllows(ohUser('BOLUM_YONETICISI', [], [10]), ['id' => 3, 'sube_id' => 2, 'bolum_id' => 10], 'BOLUM_SAME_UNIT_OTHER_BRANCH_ASSERT');
ohAssertDenies(ohUser('BOLUM_YONETICISI', [], [10]), ['id' => 4, 'sube_id' => 2, 'bolum_id' => 99], 'BOLUM_CROSS_BRANCH_OTHER_UNIT_ASSERT');
ohAssertDenies(ohUser('BOLUM_YONETICISI', [1], []), ['id' => 1, 'sube_id' => 1, 'bolum_id' => 99], 'BOLUM_SUBE_ONLY_ASSERT');
ohAssertDenies(ohUser('BOLUM_YONETICISI', [1], []), ['id' => 2, 'sube_id' => 2, 'bolum_id' => 99], 'BOLUM_SUBE_ONLY_CROSS_ASSERT');

ohAssertDenies(ohUser('BIRIM_AMIRI', []), ['id' => 1, 'sube_id' => 1, 'birim_id' => 20], 'BIRIM_ZERO_ASSERT');
ohAssertAllows(ohUser('BIRIM_AMIRI', [], [], [20]), ['id' => 1, 'sube_id' => 1, 'birim_id' => 20], 'BIRIM_OWN_ASSERT');
ohAssertDenies(ohUser('BIRIM_AMIRI', [], [], [20]), ['id' => 2, 'sube_id' => 1, 'birim_id' => 21], 'BIRIM_SIBLING_ASSERT');
ohAssertDenies(ohUser('BIRIM_AMIRI', [1], [], []), ['id' => 1, 'sube_id' => 1, 'birim_id' => 20], 'BIRIM_SUBE_ONLY_ASSERT');

ohAssertAllows(ohUser('GENEL_YONETICI', []), ['id' => 1, 'sube_id' => 9], 'GENEL_GLOBAL_ASSERT');
ohAssertAllows(ohUser('SISTEM_YONETICISI', []), ['id' => 1, 'sube_id' => 9], 'SISTEM_GLOBAL_ASSERT');
ohAssertAllows(ohUser('IK_SORUMLUSU', []), ['id' => 1, 'sube_id' => 1], 'IK_GLOBAL_READ_ASSERT');
ohAssertAllows(ohUser('IK_PERSONELI', []), ['id' => 2, 'sube_id' => 2], 'IK_PERSONELI_GLOBAL_READ_ASSERT');
ohAssertDenies(ohUser('MUHASEBE', []), ['id' => 1, 'sube_id' => 1], 'MUHASEBE_EMPTY_ASSERT');
ohAssertAllows(ohUser('MUHASEBE', [1]), ['id' => 1, 'sube_id' => 1], 'MUHASEBE_BRANCH_OWN_ASSERT');
ohAssertDenies(ohUser('MUHASEBE', [1]), ['id' => 2, 'sube_id' => 2], 'MUHASEBE_BRANCH_CROSS_ASSERT');
ohAssertAllows(
    ohUser('MUHASEBE', [], [], [], null, [], [77]),
    ['id' => 9, 'sube_id' => 99, 'sgk_isveren_id' => 77],
    'MUHASEBE_SGK_OWN_ASSERT'
);
ohAssertDenies(
    ohUser('MUHASEBE', [], [], [], null, [], [77]),
    ['id' => 10, 'sube_id' => 99, 'sgk_isveren_id' => 88],
    'MUHASEBE_SGK_CROSS_ASSERT'
);
ohAssertDenies(
    ohUser('MUHASEBE', [], [], [], null, [], [77]),
    ['id' => 11, 'sube_id' => 1],
    'MUHASEBE_SGK_DOES_NOT_GRANT_BRANCH_ASSERT'
);

ohAssertAllows(ohUser('PERSONEL', [], [], [], 5), ['id' => 5, 'sube_id' => 1], 'PERSONEL_SELF_ASSERT');
ohAssertDenies(ohUser('PERSONEL', [], [], [], 5), ['id' => 6, 'sube_id' => 1], 'PERSONEL_OTHER_ASSERT');
// Manager binding is identity-only: a SUBE_YONETICISI with personel_id still uses branch scope.
ohAssertAllows(ohUser('SUBE_YONETICISI', [1], [], [], 5), ['id' => 99, 'sube_id' => 1], 'MANAGER_PERSONEL_BINDING_NOT_AUTHZ');
ohAssertDenies(ohUser('SUBE_YONETICISI', [1], [], [], 5), ['id' => 5, 'sube_id' => 2], 'MANAGER_BINDING_NO_CROSS_BRANCH');

echo "ORG_HIERARCHY_AUTH_MATRIX=PASS\n";
exit(0);
