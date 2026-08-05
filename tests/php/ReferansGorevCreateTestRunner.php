<?php

declare(strict_types=1);

/**
 * Validation-only helper runner (SQLite) for POST /referans/gorevler.
 */

require_once __DIR__ . '/../../api/src/Http/JsonResponse.php';
require_once __DIR__ . '/../../api/src/Auth/RolePermissions.php';
require_once __DIR__ . '/../../api/src/Controllers/ReferansController.php';

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Controllers\ReferansController;

function gorevAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function gorevExpectCode(callable $callback, string $code, string $name): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $e) {
        gorevAssert($e->getMessage() === $code, $name);
        return;
    } catch (DomainException $e) {
        gorevAssert($e->getMessage() === $code, $name);
        return;
    }
    throw new RuntimeException('[FAIL] ' . $name . ' (no exception)');
}

function createGorevSqlitePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE gorevler (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ad VARCHAR(120) NOT NULL,
            durum TEXT NOT NULL DEFAULT \'AKTIF\'
        )'
    );
    $pdo->exec('CREATE UNIQUE INDEX uq_gorevler_ad ON gorevler (ad)');

    return $pdo;
}

$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/ReferansController.php');
$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/050_gorevler_ad_unique.sql');

gorevAssert(strpos($routerSource, 'ReferansController::createGorev') !== false, 'router registers POST createGorev');
gorevAssert(
    strpos($controllerSource, "RolePermissions::assert(\$user, 'yonetim-paneli.manage')") !== false,
    'createGorev requires yonetim-paneli.manage'
);
gorevAssert(strpos($controllerSource, 'createGorevRecord') !== false, 'createGorevRecord owner present');
gorevAssert(strpos($controllerSource, 'WHERE ad = :ad') !== false, 'early unique check uses WHERE ad = :ad');
gorevAssert(strpos($migrationSource, 'uq_gorevler_ad') !== false, 'additive unique migration present');
gorevAssert(strpos($controllerSource, 'GOREV_ZATEN_VAR') !== false, 'duplicate code present');

gorevAssert(RolePermissions::has(['rol' => 'GENEL_YONETICI'], 'yonetim-paneli.manage'), 'GENEL_YONETICI manage');
gorevAssert(!RolePermissions::has(['rol' => 'BIRIM_AMIRI'], 'yonetim-paneli.manage'), 'BIRIM_AMIRI forbidden');

$pdo = createGorevSqlitePdo();

gorevExpectCode(function () use ($pdo): void {
    ReferansController::createGorevRecord($pdo, ['ad' => 123]);
}, 'GOREV_NAME_TYPE', 'numeric int ad rejected');

gorevExpectCode(function () use ($pdo): void {
    ReferansController::createGorevRecord($pdo, []);
}, 'GOREV_NAME_REQUIRED', 'missing ad rejected');

gorevExpectCode(function () use ($pdo): void {
    ReferansController::createGorevRecord($pdo, ['ad' => '']);
}, 'GOREV_NAME_REQUIRED', 'empty ad rejected');

gorevExpectCode(function () use ($pdo): void {
    ReferansController::createGorevRecord($pdo, ['ad' => str_repeat('A', 121)]);
}, 'GOREV_NAME_TOO_LONG', 'too long ad rejected');

$created = ReferansController::createGorevRecord($pdo, ['ad' => '  Operator Yardimcisi  ']);
gorevAssert($created['ad'] === 'Operator Yardimcisi', 'trim accepted on string ad');
gorevAssert((int) $created['id'] > 0, 'insert returns id');

gorevExpectCode(function () use ($pdo): void {
    ReferansController::createGorevRecord($pdo, ['ad' => 'Operator Yardimcisi']);
}, 'GOREV_ZATEN_VAR', 'exact duplicate rejected');

echo 'verify-referans-gorev-create: OK' . PHP_EOL;
