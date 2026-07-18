<?php

declare(strict_types=1);

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

function createDepartmanPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE departmanlar (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ad VARCHAR(120) NOT NULL,
            durum TEXT NOT NULL DEFAULT \'AKTIF\',
            created_at TEXT,
            updated_at TEXT
        )'
    );

    return $pdo;
}

$controllerSource = file_get_contents(__DIR__ . '/../../api/src/Controllers/ReferansController.php');
$routerSource = file_get_contents(__DIR__ . '/../../api/src/Router.php');

departmanAssert(
    is_string($routerSource) && strpos($routerSource, "ReferansController::createDepartman") !== false,
    'router registers POST createDepartman owner'
);
departmanAssert(
    is_string($controllerSource)
        && strpos($controllerSource, "RolePermissions::assert(\$user, 'yonetim-paneli.manage')") !== false,
    'createDepartman requires yonetim-paneli.manage'
);
departmanAssert(
    is_string($controllerSource) && strpos($controllerSource, 'JsonResponse::success($created, [], 201)') !== false,
    'createDepartman returns HTTP 201 on success'
);
departmanAssert(
    is_string($controllerSource) && strpos($controllerSource, 'DEPARTMAN_ZATEN_VAR') !== false,
    'createDepartman uses DEPARTMAN_ZATEN_VAR duplicate code'
);

departmanAssert(
    RolePermissions::has(['rol' => 'GENEL_YONETICI'], 'yonetim-paneli.manage'),
    'GENEL_YONETICI has departman manage permission'
);
departmanAssert(
    !RolePermissions::has(['rol' => 'BOLUM_YONETICISI'], 'yonetim-paneli.manage'),
    'BOLUM_YONETICISI lacks departman manage permission (403 path)'
);
departmanAssert(
    !RolePermissions::has(['rol' => 'BIRIM_AMIRI'], 'yonetim-paneli.manage'),
    'BIRIM_AMIRI lacks departman manage permission (403 path)'
);
departmanAssert(
    !RolePermissions::has(['rol' => 'MUHASEBE'], 'yonetim-paneli.manage'),
    'MUHASEBE lacks departman manage permission (403 path)'
);

$pdo = createDepartmanPdo();

$created = ReferansController::createDepartmanRecord($pdo, ['ad' => '  Kalite  ']);
departmanAssert((int) $created['id'] > 0, 'authorized create returns positive id');
departmanAssert($created['ad'] === 'Kalite', 'create trims department name');
departmanAssert(
    (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn() === 1,
    'authorized create writes exactly one row'
);

$row = $pdo->query('SELECT * FROM departmanlar WHERE id = ' . (int) $created['id'])->fetch();
departmanAssert(is_array($row) && $row['ad'] === 'Kalite', 'DB stores trimmed name');
departmanAssert(is_array($row) && $row['durum'] === 'AKTIF', 'DB stores AKTIF durum');
departmanAssert(is_array($row) && !array_key_exists('sube_id', $row), 'departmanlar table has no sube_id (global model)');

ReferansController::createDepartmanRecord($pdo, [
    'ad' => 'Depo',
    'sube_id' => 999,
    'durum' => 'PASIF',
    'id' => 42,
]);
$depo = $pdo->query("SELECT * FROM departmanlar WHERE ad = 'Depo'")->fetch();
departmanAssert(is_array($depo) && (int) $depo['id'] !== 42, 'client-supplied id is ignored');
departmanAssert(is_array($depo) && $depo['durum'] === 'AKTIF', 'unexpected durum payload is not written');
departmanAssert(
    (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn() === 2,
    'unexpected payload fields do not create extra rows'
);

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, []);
}, 'DEPARTMAN_NAME_REQUIRED', 'missing ad is rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => '']);
}, 'DEPARTMAN_NAME_REQUIRED', 'empty ad is rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => "  \t  "]);
}, 'DEPARTMAN_NAME_REQUIRED', 'whitespace-only ad is rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => ['x']]);
}, 'DEPARTMAN_NAME_TYPE', 'non-string ad is rejected');

$long = str_repeat('A', 121);
departmanExpectCode(function () use ($pdo, $long): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => $long]);
}, 'DEPARTMAN_NAME_TOO_LONG', 'ad longer than 120 is rejected');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => 'kalite']);
}, 'DEPARTMAN_ZATEN_VAR', 'duplicate name is rejected (case-insensitive)');

departmanExpectCode(function () use ($pdo): void {
    ReferansController::createDepartmanRecord($pdo, ['ad' => '  KALITE  ']);
}, 'DEPARTMAN_ZATEN_VAR', 'duplicate name is rejected after trim');

$countAfterDup = (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn();
departmanAssert($countAfterDup === 2, 'duplicate attempts do not insert rows');

echo 'verify-referans-departman-create: OK' . PHP_EOL;
