<?php

declare(strict_types=1);

/**
 * Cinsiyet persistence regression — normal personel update write path.
 *
 * Exercises PersonellerController::updatePersonelRow (the exact owner used by
 * PUT /personeller/{id}) against an in-memory SQLite DB, then reads the row back
 * to prove the value persisted. This verifies real write + readback behavior;
 * it never searches source for a keyword.
 *
 * php tests/php/PersonelCinsiyetUpdateTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Controllers\PersonellerController;

function cinsiyetAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function cinsiyetSqlitePdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE personeller (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ad TEXT NOT NULL,
            soyad TEXT NULL,
            cinsiyet TEXT NULL
        )'
    );
    $pdo->exec("INSERT INTO personeller (id, ad, soyad, cinsiyet) VALUES (1, 'Test', 'Kisi', NULL)");

    return $pdo;
}

/** @param array<string, mixed> $payload */
function cinsiyetInvokeUpdatePersonelRow(PDO $pdo, int $personelId, array $payload): void
{
    $method = new ReflectionMethod(PersonellerController::class, 'updatePersonelRow');
    $method->invoke(null, $pdo, $personelId, $payload);
}

$pdo = cinsiyetSqlitePdo();

// Baseline: cinsiyet is NULL.
$before = $pdo->query('SELECT cinsiyet FROM personeller WHERE id = 1')->fetchColumn();
cinsiyetAssert($before === null || $before === '', 'baslangicta cinsiyet bos');

// Normal update: change cinsiyet to Kadın.
cinsiyetInvokeUpdatePersonelRow($pdo, 1, ['cinsiyet' => 'Kadın']);
$afterKadin = $pdo->query('SELECT cinsiyet FROM personeller WHERE id = 1')->fetchColumn();
cinsiyetAssert($afterKadin === 'Kadın', 'cinsiyet Kadın olarak kalici yazildi');

// Second round-trip: change to Erkek.
cinsiyetInvokeUpdatePersonelRow($pdo, 1, ['cinsiyet' => 'Erkek']);
$afterErkek = $pdo->query('SELECT cinsiyet FROM personeller WHERE id = 1')->fetchColumn();
cinsiyetAssert($afterErkek === 'Erkek', 'cinsiyet Erkek olarak guncellendi');

// cinsiyet must persist alongside other basic fields in one call (no silent drop).
cinsiyetInvokeUpdatePersonelRow($pdo, 1, ['ad' => 'Yeni', 'cinsiyet' => 'Kadın']);
$row = $pdo->query('SELECT ad, cinsiyet FROM personeller WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
cinsiyetAssert(
    ($row['ad'] ?? '') === 'Yeni' && ($row['cinsiyet'] ?? '') === 'Kadın',
    'cinsiyet + temel alan birlikte yazildi'
);

echo 'verify-personel-cinsiyet-update: OK' . PHP_EOL;
