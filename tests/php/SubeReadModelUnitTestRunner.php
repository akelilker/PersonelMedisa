<?php

declare(strict_types=1);

/**
 * Focused SubeReadModel display-name unit checks (no database).
 *
 * php tests/php/SubeReadModelUnitTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Organizasyon\SubeReadModel;

function srmAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
        exit(1);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

srmAssert(SubeReadModel::tamAd('Medisa', 'Ankara') === 'Medisa Ankara', 'Medisa Ankara');
srmAssert(SubeReadModel::tamAd('Medisa', 'Fabrika') === 'Medisa Fabrika', 'Medisa Fabrika');
srmAssert(SubeReadModel::tamAd('Karyapı', 'Karyapı') === 'Karyapı', 'Karyapı no duplication');
srmAssert(SubeReadModel::tamAd('Şenay Mobilya', 'Şenay Mobilya') === 'Şenay Mobilya', 'Şenay no duplication');
srmAssert(SubeReadModel::tamAd(null, 'Ankara') === 'Ankara', 'legacy short-only');
srmAssert(
    SubeReadModel::normalizeName('İSTANBUL') === SubeReadModel::normalizeName('istanbul'),
    'Turkish I fold'
);

// Minimal fixture schema (id/kod/ad only) must not crash findById.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, kod TEXT, ad TEXT)');
$pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (5, 'ANK', 'Ankara')");
$mapped = SubeReadModel::findById($pdo, 5);
srmAssert(
    $mapped !== null
        && $mapped['ad'] === 'Ankara'
        && $mapped['tam_ad'] === 'Ankara'
        && $mapped['sirket'] === null,
    'findById works on minimal subeler without durum/sirket columns'
);

echo "verify-sube-read-model-unit: OK" . PHP_EOL;
