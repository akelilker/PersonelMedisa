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

function ohUser($rol, array $sube = [], array $bolum = [], array $birim = [], $personelId = null)
{
    $u = [
        'id' => 10,
        'rol' => $rol,
        'sube_ids' => $sube,
        'bolum_ids' => $bolum,
        'birim_ids' => $birim,
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
if (!in_array('1=0', $f['where'], true)) {
    ohFail('IK empty must deny filter');
}
ohOk('IK_EMPTY_SCOPE=DENY');

$f = ohFilterSql(ohUser('MUHASEBE', []));
if (!in_array('1=0', $f['where'], true)) {
    ohFail('MUHASEBE empty must deny filter');
}
ohOk('MUHASEBE_EMPTY_SCOPE=DENY');

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
    ohFail('BOLUM zero unit+branch must deny');
}
ohOk('BOLUM_ZERO=DENY');

$f = ohFilterSql(ohUser('BOLUM_YONETICISI', [], [10]));
if (strpos(implode(' ', $f['where']), 'bolum_id') === false) {
    ohFail('BOLUM own must bolum filter');
}
ohOk('BOLUM_OWN=ALLOW_FILTER');

$f = ohFilterSql(ohUser('BOLUM_YONETICISI', [1], []));
if (strpos(implode(' ', $f['where']), 'sube_id') === false) {
    ohFail('BOLUM legacy sube fallback must sube filter');
}
ohOk('BOLUM_LEGACY_SUBE=ALLOW_FILTER');

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

if (!OrgScope::usesLegacySubeFallback(ohUser('BOLUM_YONETICISI', [1], []))) {
    ohFail('usesLegacySubeFallback BOLUM expected true');
}
if (OrgScope::usesLegacySubeFallback(ohUser('BOLUM_YONETICISI', [1], [9]))) {
    ohFail('usesLegacySubeFallback BOLUM with unit must be false');
}
ohOk('LEGACY_FALLBACK_FLAG');

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
ohAssertAllows(ohUser('BOLUM_YONETICISI', [1], []), ['id' => 1, 'sube_id' => 1, 'bolum_id' => 99], 'BOLUM_LEGACY_SUBE_ASSERT');
ohAssertDenies(ohUser('BOLUM_YONETICISI', [1], []), ['id' => 2, 'sube_id' => 2, 'bolum_id' => 99], 'BOLUM_LEGACY_CROSS_ASSERT');

ohAssertDenies(ohUser('BIRIM_AMIRI', []), ['id' => 1, 'sube_id' => 1, 'birim_id' => 20], 'BIRIM_ZERO_ASSERT');
ohAssertAllows(ohUser('BIRIM_AMIRI', [], [], [20]), ['id' => 1, 'sube_id' => 1, 'birim_id' => 20], 'BIRIM_OWN_ASSERT');
ohAssertDenies(ohUser('BIRIM_AMIRI', [], [], [20]), ['id' => 2, 'sube_id' => 1, 'birim_id' => 21], 'BIRIM_SIBLING_ASSERT');

ohAssertAllows(ohUser('GENEL_YONETICI', []), ['id' => 1, 'sube_id' => 9], 'GENEL_GLOBAL_ASSERT');
ohAssertAllows(ohUser('SISTEM_YONETICISI', []), ['id' => 1, 'sube_id' => 9], 'SISTEM_GLOBAL_ASSERT');
ohAssertDenies(ohUser('IK_SORUMLUSU', []), ['id' => 1, 'sube_id' => 1], 'IK_EMPTY_ASSERT');
ohAssertDenies(ohUser('MUHASEBE', []), ['id' => 1, 'sube_id' => 1], 'MUHASEBE_EMPTY_ASSERT');

ohAssertAllows(ohUser('PERSONEL', [], [], [], 5), ['id' => 5, 'sube_id' => 1], 'PERSONEL_SELF_ASSERT');
ohAssertDenies(ohUser('PERSONEL', [], [], [], 5), ['id' => 6, 'sube_id' => 1], 'PERSONEL_OTHER_ASSERT');

echo "ORG_HIERARCHY_AUTH_MATRIX=PASS\n";
exit(0);
