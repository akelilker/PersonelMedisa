<?php

declare(strict_types=1);

/**
 * Focused SubeReadModel display-name unit checks (no database).
 *
 * php tests/php/SubeReadModelUnitTestRunner.php
 */

require_once __DIR__ . '/../../api/src/Services/Organizasyon/SubeReadModel.php';

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

echo "verify-sube-read-model-unit: OK" . PHP_EOL;
