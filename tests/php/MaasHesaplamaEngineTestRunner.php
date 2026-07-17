<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Money\Money;
use Medisa\Api\Services\Money\Rate;
use Medisa\Api\Services\Payroll\FinanceKalemCatalog;
use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use Medisa\Api\Services\Payroll\MaasHesaplamaLegalParameterCatalog;

function engineAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function decimalKurus(string $value): int
{
    return Money::fromDecimalString($value)->kurus();
}

/** @return array<string, array<string, mixed>> */
function mevzuatFixture(): array
{
    $values = [
        'ASGARI_UCRET_BRUT' => '26005.74',
        'ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI' => '20000.00',
        'ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI' => '26005.74',
        'SGK_ISCI_PRIM_ORANI' => '0.14',
        'ISSIZLIK_ISCI_PRIM_ORANI' => '0.01',
        'SGK_GUNLUK_TABAN' => '866.86',
        'SGK_GUNLUK_TAVAN' => '6501.45',
        'DAMGA_VERGISI_ORANI' => '0.00759',
        'GELIR_VERGISI_DILIM_1_LIMIT' => '110000.00',
        'GELIR_VERGISI_DILIM_1_ORAN' => '0.15',
        'GELIR_VERGISI_DILIM_2_LIMIT' => '230000.00',
        'GELIR_VERGISI_DILIM_2_ORAN' => '0.20',
        'GELIR_VERGISI_DILIM_3_LIMIT' => '580000.00',
        'GELIR_VERGISI_DILIM_3_ORAN' => '0.27',
        'GELIR_VERGISI_DILIM_4_LIMIT' => '3000000.00',
        'GELIR_VERGISI_DILIM_4_ORAN' => '0.35',
        'GELIR_VERGISI_DILIM_5_ORAN' => '0.40',
        'NORMAL_AY_GUN_SAYISI' => '30',
        'GUNLUK_CALISMA_SAATI' => '8',
        'AYLIK_NORMAL_CALISMA_SAATI' => '240',
        'FAZLA_MESAI_CARPANI' => '1.5',
        'HAFTA_TATILI_CARPANI' => '2',
        'UBGT_CARPANI' => '2',
    ];

    $fixture = [];
    foreach ($values as $code => $value) {
        $meta = MaasHesaplamaLegalParameterCatalog::meta($code);
        $fixture[$code] = [
            'parametre_kodu' => $code,
            'sayisal_deger' => $value,
            'deger_tipi' => $meta ? $meta['deger_tipi'] : 'SAYISAL',
            'birim' => $meta ? $meta['birim'] : null,
        ];
    }

    return $fixture;
}

/** @return array<string, mixed> */
function engineInput(string $ucretTuru, string $tutar, array $overrides = []): array
{
    return array_replace_recursive([
        'personel' => ['personel_id' => 1, 'ad_soyad' => 'Test'],
        'ucret_segmentleri' => [[
            'id' => 1,
            'ucret_tutari' => $tutar,
            'ucret_turu' => $ucretTuru,
            'para_birimi' => 'TRY',
            'etki_baslangic' => '2026-03-01',
            'etki_bitis' => '2026-03-30',
        ]],
        'puantajlar' => [],
        'izinler' => [],
        'etki_adaylari' => [],
        'finanslar' => [],
        'mevzuat' => mevzuatFixture(),
        'carryover' => [
            'onceki_kumulatif_gelir_vergisi_matrahi' => '0.00',
            'onceki_kumulatif_gelir_vergisi' => '0.00',
        ],
        'donem_baslangic' => '2026-03-01',
        'donem_bitis' => '2026-03-31',
    ], $overrides);
}

function assertKalemIntegrity(array $result, string $name): void
{
    engineAssert(!empty($result['ok']), $name . ' ok=true');
    engineAssert(count($result['kalemler']) > 0, $name . ' kalem uretildi');
    $expectedSira = 1;
    foreach ($result['kalemler'] as $kalem) {
        engineAssert((int) $kalem['sira_no'] === $expectedSira, $name . ' kalem sirasi deterministik');
        engineAssert(preg_match('/^[a-f0-9]{64}$/', (string) $kalem['payload_hash']) === 1, $name . ' kalem hash SHA-256');
        $expectedSira++;
    }
}

// Money
engineAssert(Money::fromDecimalString('1234.565')->toDecimalString() === '1234.57', 'Money fromDecimalString half-up');
engineAssert(Money::fromDecimalString('1234,5')->toDecimalString() === '1234.50', 'Money comma decimal string');
engineAssert(Money::fromDecimalString('10.00')->mulDiv(1, 3)->toDecimalString() === '3.33', 'Money mulDiv rounds down below half');
engineAssert(Money::fromDecimalString('10.00')->mulDiv(2, 3)->toDecimalString() === '6.67', 'Money mulDiv half-up');
engineAssert(Money::fromDecimalString('1000.00')->applyRate(Rate::fromDecimalString('0.15'))->toDecimalString() === '150.00', 'Money applyRate');
foreach ([
    __DIR__ . '/../../api/src/Services/Money/Money.php',
    __DIR__ . '/../../api/src/Services/Money/Rate.php',
    __DIR__ . '/../../api/src/Services/Money/RoundingPolicy.php',
    __DIR__ . '/../../api/src/Services/Payroll/MaasHesaplamaEngine.php',
] as $sourceFile) {
    $source = file_get_contents($sourceFile);
    engineAssert(is_string($source) && preg_match('/\(\s*float\s*\)|floatval\s*\(/i', $source) !== 1, basename($sourceFile) . ' float cast yok');
}

// Rate
engineAssert(Rate::fromDecimalString('0.15')->ppm() === 150000, 'Rate fromDecimalString');
engineAssert(Rate::fromPercentString('15')->toDecimalString() === '0.150000', 'Rate fromPercentString whole');
engineAssert(Rate::fromPercentString('15.5')->toDecimalString() === '0.155000', 'Rate fromPercentString decimal');

// Finance catalog
$prim = FinanceKalemCatalog::classify('PRIM');
engineAssert($prim !== null && $prim['yon'] === 'ARTI' && $prim['sgk'] === true, 'FinanceKalemCatalog PRIM ARTI+sgk');
$ceza = FinanceKalemCatalog::classify('CEZA');
engineAssert($ceza !== null && $ceza['yon'] === 'EKSI' && $ceza['net_odeme'] === true, 'FinanceKalemCatalog CEZA EKSI+net_odeme');
engineAssert(FinanceKalemCatalog::isDuplicateSalary('MAAS'), 'FinanceKalemCatalog MAAS duplicate');

// Legal catalog
engineAssert(count(MaasHesaplamaLegalParameterCatalog::requiredCodes()) === 23, 'LegalParameterCatalog requiredCodes 23');

// Engine BRUT path
$brut = MaasHesaplamaEngine::calculate(engineInput('BRUT', '50000.00', [
    'puantajlar' => [[
        'muhur_satir_id' => 1,
        'tarih' => '2026-03-10',
        'gun_tipi' => 'Normal_Is_Gunu',
        'net_calisma_suresi_dakika' => 540,
    ]],
    'finanslar' => [[
        'kayit_id' => 10,
        'kalem_turu' => 'PRIM',
        'tutar' => '1000.00',
        'state' => 'AKTIF',
    ]],
]));
assertKalemIntegrity($brut, 'BRUT happy path');
engineAssert((string) $brut['ozet']['ucret_turu'] === 'BRUT', 'BRUT path ucret_turu');
engineAssert(decimalKurus((string) $brut['ozet']['net_odenecek']) > 0, 'BRUT path net positive');

// Engine NET path, no extras
$targetNet = '30000.00';
$net = MaasHesaplamaEngine::calculate(engineInput('NET', $targetNet));
assertKalemIntegrity($net, 'NET happy path');
engineAssert(is_array($net['solver']), 'NET path solver kullanildi');
engineAssert((int) $net['solver']['iterations'] <= MaasHesaplamaEngine::SOLVER_MAX_ITERATIONS, 'NET solver iterations <= 64');
engineAssert(abs(decimalKurus((string) $net['ozet']['net_odenecek']) - decimalKurus($targetNet)) <= 1, 'NET net within 1 kurus');

// Missing legal param
$missingInput = engineInput('BRUT', '50000.00');
unset($missingInput['mevzuat']['SGK_ISCI_PRIM_ORANI']);
$missingThrown = false;
try {
    MaasHesaplamaEngine::calculate($missingInput);
} catch (InvalidArgumentException $e) {
    $missingThrown = strpos($e->getMessage(), 'Missing parameter SGK_ISCI_PRIM_ORANI') !== false;
}
engineAssert($missingThrown, 'Missing legal param error');

// MAAS finance duplicate
$maas = MaasHesaplamaEngine::calculate(engineInput('BRUT', '50000.00', [
    'finanslar' => [[
        'kayit_id' => 11,
        'kalem_turu' => 'MAAS',
        'tutar' => '1000.00',
        'state' => 'AKTIF',
    ]],
]));
engineAssert(empty($maas['ok']) && $maas['error_code'] === 'DUPLICATE_FINANCE_EFFECT', 'MAAS finance DUPLICATE error');

// Determinism
$input = engineInput('BRUT', '50000.00');
$first = MaasHesaplamaEngine::calculate($input);
$second = MaasHesaplamaEngine::calculate($input);
engineAssert(!empty($first['ok']) && $first['result_hash'] === $second['result_hash'], 'same input same result_hash');

echo 'verify-maas-hesaplama-engine: OK' . PHP_EOL;
