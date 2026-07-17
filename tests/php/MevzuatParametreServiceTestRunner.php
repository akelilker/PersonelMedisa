<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/MevzuatParametreException.php';
require_once __DIR__ . '/../../api/src/Services/MevzuatParametreService.php';

use Medisa\Api\Services\MevzuatParametreException;
use Medisa\Api\Services\MevzuatParametreService;

function legalAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function legalException(callable $callback, string $code, string $name): void
{
    try {
        $callback();
    } catch (MevzuatParametreException $e) {
        legalAssert($e->getCodeString() === $code, $name);
        return;
    }
    throw new RuntimeException('[FAIL] ' . $name);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE mevzuat_parametreleri (
    id INTEGER PRIMARY KEY AUTOINCREMENT, parametre_kodu TEXT NOT NULL, deger_tipi TEXT NOT NULL,
    sayisal_deger REAL, metin_deger TEXT, gecerlilik_baslangic TEXT NOT NULL, gecerlilik_bitis TEXT,
    birim TEXT, aciklama TEXT, kaynak_referansi TEXT, state TEXT NOT NULL,
    created_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_by INTEGER,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP, revision_no INTEGER NOT NULL DEFAULT 1
)');
$pdo->exec('CREATE TABLE mevzuat_parametre_auditleri (
    id INTEGER PRIMARY KEY AUTOINCREMENT, parametre_kodu TEXT NOT NULL, parametre_kaydi_id INTEGER,
    aksiyon TEXT NOT NULL, onceki_snapshot TEXT, sonraki_snapshot TEXT,
    actor_id INTEGER, actor_rol TEXT, request_hash TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP
)');

$first = MevzuatParametreService::createParameter($pdo, [
    'parametre_kodu' => 'TEST_ORAN',
    'deger_tipi' => 'SAYISAL',
    'sayisal_deger' => '1.25',
    'gecerlilik_baslangic' => '2028-01-01',
    'birim' => 'ORAN',
]);
$second = MevzuatParametreService::createParameter($pdo, [
    'parametre_kodu' => 'TEST_ORAN',
    'deger_tipi' => 'SAYISAL',
    'sayisal_deger' => '2.5',
    'gecerlilik_baslangic' => '2030-01-01',
]);
$rows = MevzuatParametreService::listParameters($pdo, 'test_oran');
legalAssert(count($rows) === 2, 'legal parameters are versioned by date');
legalAssert($rows[1]['gecerlilik_bitis'] === '2029-12-31', 'new legal parameter closes previous open range');
legalAssert((int) MevzuatParametreService::resolveForDate($pdo, 'TEST_ORAN', '2029-12-31')['id'] === (int) $first['id'], 'legal parameter end is inclusive');
legalAssert((int) MevzuatParametreService::resolveForDate($pdo, 'TEST_ORAN', '2030-01-01')['id'] === (int) $second['id'], 'legal parameter start is inclusive');

legalException(function () use ($pdo): void {
    MevzuatParametreService::validateNoOverlap($pdo, 'TEST_ORAN', '2029-12-31', '2029-12-31');
}, 'LEGAL_PARAMETER_OVERLAP', 'inclusive legal parameter overlap is rejected');

$updated = MevzuatParametreService::updateFutureParameter($pdo, (int) $second['id'], [
    'sayisal_deger' => '3.75',
]);
legalAssert((float) $updated['sayisal_deger'] === 3.75, 'future legal parameter can be updated');
MevzuatParametreService::cancelParameter($pdo, (int) $second['id']);
legalException(function () use ($pdo): void {
    MevzuatParametreService::resolveForDate($pdo, 'TEST_ORAN', '2030-01-01');
}, 'LEGAL_PARAMETER_MISSING', 'cancelled legal parameter does not resolve');

legalException(function () use ($pdo): void {
    MevzuatParametreService::createParameter($pdo, [
        'parametre_kodu' => 'BAD',
        'deger_tipi' => 'METIN',
        'metin_deger' => null,
        'gecerlilik_baslangic' => '2031-01-01',
    ]);
}, 'VALIDATION_ERROR', 'value type requires exactly one matching value');
legalAssert((int) $pdo->query('SELECT COUNT(*) FROM mevzuat_parametre_auditleri')->fetchColumn() >= 4, 'legal parameter mutations write audit rows');

echo 'verify-mevzuat-parametre-service: OK' . PHP_EOL;
