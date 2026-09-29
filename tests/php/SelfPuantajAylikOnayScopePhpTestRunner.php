<?php

declare(strict_types=1);

/**
 * SQLite: self-service aylik_onayli_mi must match personel birim → BIRIM_AMIRI scope.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Services\SelfService\SelfPuantajReadService;

function spasFail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function spasOk(string $msg): void
{
    echo "OK: {$msg}\n";
}

function spasAssert(bool $condition, string $msg): void
{
    if (!$condition) {
        spasFail($msg);
    }
    spasOk($msg);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    "CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        rol TEXT NOT NULL,
        durum TEXT NOT NULL DEFAULT 'AKTIF'
    )"
);
$pdo->exec(
    "CREATE TABLE user_birimler (
        user_id INTEGER NOT NULL,
        birim_id INTEGER NOT NULL
    )"
);
$pdo->exec(
    "CREATE TABLE aylik_bildirim_onaylari (
        id INTEGER PRIMARY KEY,
        sube_id INTEGER NOT NULL,
        birim_amiri_user_id INTEGER NOT NULL,
        ay TEXT NOT NULL,
        state TEXT NOT NULL
    )"
);

// Amir A → birim 10; Amir B → birim 20 (same şube 1).
$pdo->exec("INSERT INTO users (id, rol, durum) VALUES (100, 'BIRIM_AMIRI', 'AKTIF')");
$pdo->exec("INSERT INTO users (id, rol, durum) VALUES (200, 'BIRIM_AMIRI', 'AKTIF')");
$pdo->exec("INSERT INTO user_birimler (user_id, birim_id) VALUES (100, 10)");
$pdo->exec("INSERT INTO user_birimler (user_id, birim_id) VALUES (200, 20)");
$pdo->exec(
    "INSERT INTO aylik_bildirim_onaylari (id, sube_id, birim_amiri_user_id, ay, state)
     VALUES (1, 1, 100, '2026-04', 'TAMAMLANDI')"
);

spasAssert(
    SelfPuantajReadService::resolveBirimAmiriUserIdForPersonelScope($pdo, 10) === 100,
    'birim 10 resolves to amir 100'
);
spasAssert(
    SelfPuantajReadService::resolveBirimAmiriUserIdForPersonelScope($pdo, 20) === 200,
    'birim 20 resolves to amir 200'
);

spasAssert(
    SelfPuantajReadService::isAylikOnayli($pdo, 1, 10, '2026-04-15') === true,
    'personel under amir A sees approved month when only A completed'
);
spasAssert(
    SelfPuantajReadService::isAylikOnayli($pdo, 1, 20, '2026-04-15') === false,
    'personel under amir B not marked approved when only A completed same sube+ay'
);

$pdo->exec(
    "INSERT INTO aylik_bildirim_onaylari (id, sube_id, birim_amiri_user_id, ay, state)
     VALUES (2, 1, 200, '2026-04', 'TAMAMLANDI')"
);
spasAssert(
    SelfPuantajReadService::isAylikOnayli($pdo, 1, 20, '2026-04-01') === true,
    'personel under amir B approved after B completes'
);

spasAssert(
    SelfPuantajReadService::isAylikOnayli($pdo, 1, 0, '2026-04-01') === false,
    'missing birim_id fails closed'
);

echo "ALL_PASS self-puantaj-aylik-onay-scope\n";
exit(0);
