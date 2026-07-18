<?php

declare(strict_types=1);

/**
 * Validation-only helper runner (SQLite). Persistence/duplicate/concurrency
 * acceptance lives in ReferansDepartmanCreateMysqlTestRunner.php.
 */

require_once __DIR__ . '/../../api/src/Http/JsonResponse.php';
require_once __DIR__ . '/../../api/src/Auth/RolePermissions.php';
require_once __DIR__ . '/../../api/src/Controllers/ReferansController.php';

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Controllers\ReferansController;

function departmanAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function departmanExpectCode(callable $callback, string $code, string $name): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        departmanAssert($e->getMessage() === $code, $name);
        return;
    } catch (DomainException $e) {
        departmanAssert($e->getMessage() === $code, $name);
        return;
    }
    throw new RuntimeException('[FAIL] ' . $name . ' (no exception)');
}

function createDepartmanSqlitePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE departmanlar (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ad VARCHAR(120) NOT NULL,
            durum TEXT NOT NULL DEFAULT \'AKTIF\'
        )'
    );
    $pdo->exec('CREATE UNIQUE INDEX uq_departmanlar_ad ON departmanlar (ad)');

    return $pdo;
}

$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/ReferansController.php');
$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/025_departmanlar_ad_unique.sql');

departmanAssert(strpos($routerSource, 'ReferansController::createDepartman') !== false, 'router registers POST createDepartman');
departmanAssert(
    strpos($controllerSource, "RolePermissions::assert(\$user, 'yonetim-paneli.manage')") !== false,
    'createDepartman requires yonetim-paneli.manage'
);
departmanAssert(strpos($controllerSource, 'SELECT id, ad FROM departmanlar') === false, 'no SELECT-all duplicate scan');
departmanAssert(strpos($controllerSource, 'normalizeDepartmanAdForCompare') === false, 'no PHP normalize helper');
departmanAssert(strpos($controllerSource, 'WHERE ad = :ad') !== false, 'early unique check uses WHERE ad = :ad');
departmanAssert(strpos($migrationSource, 'uq_departmanlar_ad') !== false, 'additive unique migration present');
departmanAssert(
    strpos($controllerSource, '!is_string($body[\'ad\'])') !== false
        && strpos($controllerSource, 'is_numeric($body[\'ad\'])') === false,
    'ad accepts only JSON string type'
);

departmanAssert(RolePermissions::has(['rol' => 'GENEL_YONETICI'], 'yonetim-paneli.manage'), 'GENEL_YONETICI manage');
departmanAssert(!RolePermissions::has(['rol' => 'BIRIM_AMIRI'], 'yonetim-paneli.manage'), 'BIRIM_AMIRI forbidden');
departmanAssert(!RolePermissions::has(['rol' => 'MUHASEBE'], 'yonetim-paneli.manage'), 'MUHASEBE forbidden');
departmanAssert(!RolePermissions::has(['rol' => 'IK'], 'yonetim-paneli.manage'), 'IK forbidden');
departmanAssert(!RolePermissions::has(['rol' => 'PATRON'], 'yonetim-paneli.manage'), 'PATRON forbidden');

$pdo = createDepartmanSqlitePdo();

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => 123]);
}, 'DEPARTMAN_NAME_TYPE', 'numeric int ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => 12.5]);
}, 'DEPARTMAN_NAME_TYPE', 'numeric float ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => true]);
}, 'DEPARTMAN_NAME_TYPE', 'boolean ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => null]);
}, 'DEPARTMAN_NAME_TYPE', 'null ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => []]);
}, 'DEPARTMAN_NAME_TYPE', 'array ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => ['x' => 1]]);
}, 'DEPARTMAN_NAME_TYPE', 'object-like array ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, []);
}, 'DEPARTMAN_NAME_REQUIRED', 'missing ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => '']);
}, 'DEPARTMAN_NAME_REQUIRED', 'empty ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => "  \t  "]);
}, 'DEPARTMAN_NAME_REQUIRED', 'whitespace ad rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => str_repeat('A', 121)]);
}, 'DEPARTMAN_NAME_TOO_LONG', 'too long ad rejected');

$created = ReferansController::createDepartmanRecord($pdo, ['ad' => '  Kalite  ']);
departmanAssert($created['ad'] === 'Kalite', 'trim accepted on string ad');
departmanAssert((int) $created['id'] > 0, 'insert returns id');

ReferansController::createDepartmanRecord($pdo, [
    'ad' => 'Depo',
    'sube_id' => 999,
    'durum' => 'PASIF',
    'id' => 42,
]);
$depo = $pdo->query("SELECT * FROM departmanlar WHERE ad = 'Depo'")->fetch();
departmanAssert(is_array($depo) && (int) $depo['id'] !== 42, 'client id ignored');
departmanAssert(is_array($depo) && $depo['durum'] === 'AKTIF', 'unexpected durum ignored (allowlist)');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => 'Kalite']);
}, 'DEPARTMAN_ZATEN_VAR', 'exact duplicate rejected');

echo 'verify-referans-departman-create: OK' . PHP_EOL;
