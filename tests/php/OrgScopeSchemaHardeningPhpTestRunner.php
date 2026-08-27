<?php

declare(strict_types=1);

/**
 * Org scope schema hardening: Login derive + bildirim rapor filters (SQLite, no MariaDB).
 * Exit 0 = PASS.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Auth\LoginController;
use Medisa\Api\Services\BildirimPuantajEtkiRaporQueryService;
use Medisa\Api\Services\Personel\PersonelOrgStructureSchema;

function oshFail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function oshOk(string $msg): void
{
    echo "OK: {$msg}\n";
}

function oshPdoWithoutScopeColumns(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE personeller (
            id INTEGER PRIMARY KEY,
            sube_id INTEGER NOT NULL
        )'
    );
    $pdo->exec('INSERT INTO personeller (id, sube_id) VALUES (1, 99)');
    PersonelOrgStructureSchema::clearReadyCache();

    return $pdo;
}

function oshPdoWithScopeColumns(): PDO
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
    $pdo->exec('INSERT INTO personeller (id, sube_id, bolum_id, birim_id) VALUES (1, 7, 10, 20)');
    $pdo->exec('INSERT INTO personeller (id, sube_id, bolum_id, birim_id) VALUES (2, 8, 11, 21)');
    PersonelOrgStructureSchema::clearReadyCache();

    return $pdo;
}

/** @return array<int, int> */
function oshDerive(PDO $pdo, array $bolumIds, array $birimIds): array
{
    $ref = new ReflectionClass(LoginController::class);
    $method = $ref->getMethod('deriveSubeIdsFromOrgAssignments');
    $method->setAccessible(true);

    /** @var array<int, int> $ids */
    $ids = $method->invoke(null, $pdo, $bolumIds, $birimIds);

    return $ids;
}

/**
 * @return array{0: string, 1: array<string, mixed>, 2: string}
 */
function oshBuildFilter(PDO $pdo, array $filters): array
{
    $ref = new ReflectionClass(BildirimPuantajEtkiRaporQueryService::class);
    $method = $ref->getMethod('buildFilterClause');
    $method->setAccessible(true);

    return $method->invoke(null, $pdo, $filters, null);
}

// ---- A/B: Login derive on legacy schema (BOLUM / BIRIM assignment present, columns absent) ----
$legacy = oshPdoWithoutScopeColumns();
if (PersonelOrgStructureSchema::hasPersonelScopeColumns($legacy)) {
    oshFail('legacy fixture unexpectedly has scope columns');
}

try {
    $bolumDerived = oshDerive($legacy, [10], []);
} catch (Throwable $e) {
    oshFail('LOGIN_BOLUM_ESKI_SEMA threw: ' . $e->getMessage());
}
if ($bolumDerived !== []) {
    oshFail('LOGIN_BOLUM_ESKI_SEMA must return empty (fail-closed, no authority widen)');
}
oshOk('LOGIN_BOLUM_ESKI_SEMA');

try {
    $birimDerived = oshDerive($legacy, [], [20]);
} catch (Throwable $e) {
    oshFail('LOGIN_BIRIM_ESKI_SEMA threw: ' . $e->getMessage());
}
if ($birimDerived !== []) {
    oshFail('LOGIN_BIRIM_ESKI_SEMA must return empty (fail-closed, no authority widen)');
}
oshOk('LOGIN_BIRIM_ESKI_SEMA');

// ---- C/D: Bildirim rapor filters fail-closed without scope columns ----
[$whereBolum] = oshBuildFilter($legacy, [
    'sube_id' => 1,
    'donem' => '2026-08',
    'bolum_ids' => [10],
]);
if (strpos($whereBolum, 'p.bolum_id') !== false) {
    oshFail('BILDIRIM_RAPOR_BOLUM_ESKI_SEMA must not emit p.bolum_id');
}
if (strpos($whereBolum, '1=0') === false) {
    oshFail('BILDIRIM_RAPOR_BOLUM_ESKI_SEMA must fail-closed with 1=0');
}
oshOk('BILDIRIM_RAPOR_BOLUM_ESKI_SEMA');

[$whereBirim] = oshBuildFilter($legacy, [
    'sube_id' => 1,
    'donem' => '2026-08',
    'birim_ids' => [20],
]);
if (strpos($whereBirim, 'p.birim_id') !== false) {
    oshFail('BILDIRIM_RAPOR_BIRIM_ESKI_SEMA must not emit p.birim_id');
}
if (strpos($whereBirim, '1=0') === false) {
    oshFail('BILDIRIM_RAPOR_BIRIM_ESKI_SEMA must fail-closed with 1=0');
}
oshOk('BILDIRIM_RAPOR_BIRIM_ESKI_SEMA');

// ---- E: Ready schema keeps real filters / derive working ----
$ready = oshPdoWithScopeColumns();
if (!PersonelOrgStructureSchema::hasPersonelScopeColumns($ready)) {
    oshFail('ready fixture missing scope columns');
}

$derivedReady = oshDerive($ready, [10], [20]);
sort($derivedReady);
if ($derivedReady !== [7]) {
    oshFail('READY_DERIVE expected [7], got ' . json_encode($derivedReady));
}
oshOk('LOGIN_READY_DERIVE');

[$whereReady, $paramsReady] = oshBuildFilter($ready, [
    'sube_id' => 1,
    'donem' => '2026-08',
    'bolum_ids' => [10],
    'birim_ids' => [20],
]);
if (strpos($whereReady, 'p.bolum_id IN') === false || strpos($whereReady, 'p.birim_id IN') === false) {
    oshFail('READY_REPORT_FILTER missing column filters');
}
if (strpos($whereReady, '1=0') !== false) {
    oshFail('READY_REPORT_FILTER must not deny when columns exist');
}
if ((int) ($paramsReady['bolum_id_0'] ?? 0) !== 10 || (int) ($paramsReady['birim_id_0'] ?? 0) !== 20) {
    oshFail('READY_REPORT_FILTER missing bound ids');
}
oshOk('BILDIRIM_RAPOR_READY_FILTER');

echo "ORG_SCOPE_SCHEMA_HARDENING=PASS\n";
