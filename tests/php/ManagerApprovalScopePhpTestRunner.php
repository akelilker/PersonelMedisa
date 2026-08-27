<?php

declare(strict_types=1);

/**
 * ManagerApprovalScope + puantaj etki report unit-scope matrix (no MariaDB).
 * Exit 0 = PASS.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Scope\ManagerApprovalScope;
use Medisa\Api\Services\BildirimPuantajEtkiRaporQueryService;
use Medisa\Api\Services\Personel\PersonelOrgStructureSchema;

function masFail($msg)
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function masOk($msg)
{
    echo "OK: {$msg}\n";
}

function masScopeReadyPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE personeller (
            id INTEGER PRIMARY KEY,
            sube_id INTEGER NOT NULL,
            bolum_id INTEGER NULL,
            birim_id INTEGER NULL
        )'
    );
    PersonelOrgStructureSchema::clearReadyCache();
    if (!PersonelOrgStructureSchema::hasPersonelScopeColumns($pdo)) {
        masFail('fixture PDO must expose personel scope columns');
    }

    return $pdo;
}

function masUser($rol, array $sube = [], array $bolum = [], array $birim = [], $id = 10)
{
    return [
        'id' => (int) $id,
        'rol' => $rol,
        'sube_ids' => $sube,
        'bolum_ids' => $bolum,
        'birim_ids' => $birim,
    ];
}

function masRunActorAssert(array $user, $subeId, $amirId, array $context)
{
    $child = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'manager-approval-scope-assert-child.php';
    $payload = base64_encode(json_encode([
        'user' => $user,
        'sube_id' => (int) $subeId,
        'amir_id' => (int) $amirId,
        'context' => $context,
    ], JSON_UNESCAPED_UNICODE));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($child) . ' ' . escapeshellarg($payload);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);

    return ['code' => $code, 'out' => implode("\n", $out)];
}

function masAssertAllows(array $user, $subeId, $amirId, array $context, $label)
{
    $r = masRunActorAssert($user, $subeId, $amirId, $context);
    if ($r['code'] !== 0 || strpos($r['out'], 'ALLOW') === false) {
        masFail("{$label}: expected ALLOW, code={$r['code']} out={$r['out']}");
    }
    masOk($label);
}

function masAssertDenies(array $user, $subeId, $amirId, array $context, $label)
{
    $r = masRunActorAssert($user, $subeId, $amirId, $context);
    if (strpos($r['out'], 'ALLOW') !== false) {
        masFail("{$label}: expected DENY but ALLOWED");
    }
    if (strpos($r['out'], 'FORBIDDEN') === false && $r['code'] === 0) {
        masFail("{$label}: expected FORBIDDEN/exit, code={$r['code']} out={$r['out']}");
    }
    masOk($label);
}

// O) GY selected BA context: actor global allows any valid BA context shape
masAssertAllows(
    masUser('GENEL_YONETICI', [], [], [], 1),
    1,
    91,
    ['birim_ids' => [3], 'bolum_ids' => [1]],
    'O_GY_SELECTED_BA_CONTEXT_ALLOW'
);

// P) BY same bolum chain ALLOW
masAssertAllows(
    masUser('BOLUM_YONETICISI', [], [1]),
    1,
    91,
    ['birim_ids' => [3], 'bolum_ids' => [1]],
    'P_PUANTAJ_ETKI_BY_SAME_BOLUM_ALLOW'
);

// Q) BY other bolum same branch DENY
masAssertDenies(
    masUser('BOLUM_YONETICISI', [], [1]),
    1,
    91,
    ['birim_ids' => [9], 'bolum_ids' => [2]],
    'Q_PUANTAJ_ETKI_BY_OTHER_BOLUM_SAME_BRANCH_DENY'
);

// BA self chain ALLOW
masAssertAllows(
    masUser('BIRIM_AMIRI', [], [], [3], 91),
    1,
    91,
    ['birim_ids' => [3], 'bolum_ids' => [1]],
    'S_BA_SELF_CHAIN_ALLOW'
);

// BA other amir DENY
masAssertDenies(
    masUser('BIRIM_AMIRI', [], [], [3], 91),
    1,
    99,
    ['birim_ids' => [9], 'bolum_ids' => [2]],
    'S_BA_OTHER_CHAIN_DENY'
);

// Report filter: BY bolum_ids → p.bolum_id IN (scope columns ready)
$scopePdo = masScopeReadyPdo();
$ref = new ReflectionClass(BildirimPuantajEtkiRaporQueryService::class);
$method = $ref->getMethod('buildFilterClause');
$method->setAccessible(true);
[$whereSql, $params] = $method->invoke(null, $scopePdo, [
    'sube_id' => 1,
    'donem' => '2026-08',
    'bolum_ids' => [1],
], null);
if (strpos($whereSql, 'p.bolum_id IN') === false) {
    masFail('R_REPORT_BY_BOLUM_FILTER missing p.bolum_id IN');
}
if (!isset($params['bolum_id_0']) || (int) $params['bolum_id_0'] !== 1) {
    masFail('R_REPORT_BY_BOLUM_FILTER missing bolum param');
}
masOk('R_PUANTAJ_ETKI_REPORT_BY_BOLUM_FILTER');

[$whereSql2] = $method->invoke(null, $scopePdo, [
    'sube_id' => 1,
    'donem' => '2026-08',
    'bolum_ids' => [1],
    'birim_ids' => [3],
], null);
if (strpos($whereSql2, 'p.birim_id IN') === false) {
    masFail('R_REPORT_BA_BIRIM_FILTER missing p.birim_id IN');
}
masOk('R_PUANTAJ_ETKI_REPORT_BA_BIRIM_FILTER');

// enrichReportFiltersForActor
$byFilters = ManagerApprovalScope::enrichReportFiltersForActor(
    masUser('BOLUM_YONETICISI', [], [1]),
    ['sube_id' => 1, 'donem' => '2026-08']
);
if (!isset($byFilters['bolum_ids']) || $byFilters['bolum_ids'] !== [1]) {
    masFail('enrichReportFilters BY bolum_ids');
}
masOk('ENRICH_REPORT_BY_BOLUM');

$baFilters = ManagerApprovalScope::enrichReportFiltersForActor(
    masUser('BIRIM_AMIRI', [], [], [3]),
    ['sube_id' => 1, 'donem' => '2026-08']
);
if (!isset($baFilters['birim_ids']) || $baFilters['birim_ids'] !== [3]) {
    masFail('enrichReportFilters BA birim_ids');
}
masOk('ENRICH_REPORT_BA_BIRIM');

// T) runtime controllers must not authorize BA via user_subeler join
$controllers = [
    'BildirimPuantajEtkiAdaylariController.php',
    'GenelYoneticiBildirimOnaylariController.php',
    'HaftalikBildirimMutabakatlariController.php',
    'AylikBildirimOnaylariController.php',
    'BildirimlerController.php',
];
foreach ($controllers as $file) {
    $path = $root . '/api/src/Controllers/' . $file;
    $src = file_get_contents($path);
    if ($src === false) {
        masFail("missing {$file}");
    }
    if (preg_match('/INNER\s+JOIN\s+user_subeler/i', $src)) {
        masFail("T_NO_BA_USER_SUBELER: {$file} still JOINs user_subeler");
    }
    if (strpos($src, 'function assertAmirScope') !== false) {
        masFail("T_NO_LEGACY_ASSERT_AMIR: {$file} still has assertAmirScope");
    }
}
$owner = file_get_contents($root . '/api/src/Scope/ManagerApprovalScope.php');
if ($owner === false || strpos($owner, 'user_birimler') === false) {
    masFail('ManagerApprovalScope missing user_birimler');
}
if (preg_match('/INNER\s+JOIN\s+user_subeler/i', $owner)) {
    masFail('ManagerApprovalScope must not JOIN user_subeler');
}
masOk('T_NO_RUNTIME_BA_USER_SUBELER_AUTH');

// Source: report controller enriches filters
$etki = file_get_contents($root . '/api/src/Controllers/BildirimPuantajEtkiAdaylariController.php');
if (strpos($etki, 'enrichReportFiltersForActor') === false) {
    masFail('etki report missing enrichReportFiltersForActor');
}
if (strpos($etki, 'assertActorCanAccessPersonelId') === false) {
    masFail('etki detail missing personel org assert');
}
masOk('ETKI_READ_PATHS_CANONICAL');

echo "MANAGER_APPROVAL_SCOPE_MATRIX=PASS\n";
exit(0);
