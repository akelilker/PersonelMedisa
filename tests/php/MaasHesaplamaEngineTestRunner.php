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
function mevzuatFixture(array $overrides = []): array
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
        'AYLIK_NORMAL_CALISMA_SAATI' => '225',
        'HAFTALIK_IS_GUNU_SAYISI' => '5',
        'FAZLA_MESAI_CARPANI' => '1.5',
        'FAZLA_SURELERLE_CALISMA_CARPANI' => '1.25',
        'HAFTA_TATILI_CARPANI' => '1',
        'UBGT_CARPANI' => '1',
        'HAFTA_TATILI_HESAP_MODU' => 'GUNLUK_ILAVE',
        'UBGT_HESAP_MODU' => 'GUNLUK_ILAVE',
    ];
    foreach ($overrides as $code => $value) {
        $values[$code] = $value;
    }

    $fixture = [];
    foreach ($values as $code => $value) {
        $meta = MaasHesaplamaLegalParameterCatalog::meta($code);
        $isMetin = $meta && $meta['deger_tipi'] === 'METIN';
        $fixture[$code] = [
            'parametre_kodu' => $code,
            'sayisal_deger' => $isMetin ? null : $value,
            'metin_deger' => $isMetin ? $value : null,
            'deger_tipi' => $meta ? $meta['deger_tipi'] : 'SAYISAL',
            'birim' => $meta ? $meta['birim'] : null,
        ];
    }

    return $fixture;
}

/** @return array<int, array<string, mixed>> */
function findKalemler(array $result, string $kod): array
{
    return array_values(array_filter($result['kalemler'], static function (array $k) use ($kod) {
        return (string) $k['kalem_kodu'] === $kod;
    }));
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
engineAssert(count(MaasHesaplamaLegalParameterCatalog::requiredCodes()) === 27, 'LegalParameterCatalog requiredCodes 27');
engineAssert(MaasHesaplamaEngine::ENGINE_VERSION === 'S77D_PAYROLL_ENGINE_V2', 'Engine version V2');
engineAssert(MaasHesaplamaEngine::CONTRACT_VERSION === 'S77D_PAYROLL_CANDIDATE_V2', 'Contract version V2');

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
engineAssert((string) $brut['engine_version'] === 'S77D_PAYROLL_ENGINE_V2', 'BRUT result engine_version V2');

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
engineAssert(isset($first['input_hash']) && strlen((string) $first['input_hash']) === 64, 'input_hash SHA-256');

// V2: 7.5 saat truncate edilmez; AYLIK_NORMAL_CALISMA_SAATI saatlik divisor
$frac = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture([
        'GUNLUK_CALISMA_SAATI' => '7.5',
        'AYLIK_NORMAL_CALISMA_SAATI' => '225',
        'HAFTALIK_IS_GUNU_SAYISI' => '6',
    ]),
    'puantajlar' => [[
        'muhur_satir_id' => 2,
        'tarih' => '2026-03-02',
        'gun_tipi' => 'UBGT_Resmi_Tatil',
        'net_calisma_suresi_dakika' => 450,
    ]],
]));
assertKalemIntegrity($frac, 'fractional hours path');
$ubgt = findKalemler($frac, 'UBGT_ODEMESI');
engineAssert(count($ubgt) === 1, 'UBGT GUNLUK_ILAVE kalem');
// gunluk = 45000/30 = 1500; carpan=1 → 1500.00
engineAssert((string) $ubgt[0]['tutar'] === '1500.00', 'UBGT gunluk ilave tutar');
engineAssert((string) $ubgt[0]['birim'] === 'GUN', 'UBGT birim GUN');

// Haftalik FS + FM siniflandirmasi (sozlesme 5*8h=2400, yasal 2700)
$weekDays = [];
// 2026-03-02 Pazartesi ... 2026-03-06 Cuma: 5x540=2700 → FS=300, FM=0
for ($d = 2; $d <= 6; $d++) {
    $weekDays[] = [
        'muhur_satir_id' => 100 + $d,
        'tarih' => sprintf('2026-03-%02d', $d),
        'gun_tipi' => 'Normal_Is_Gunu',
        'net_calisma_suresi_dakika' => 540,
    ];
}
$weekFs = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture([
        'GUNLUK_CALISMA_SAATI' => '8',
        'HAFTALIK_IS_GUNU_SAYISI' => '5',
        'AYLIK_NORMAL_CALISMA_SAATI' => '225',
    ]),
    'puantajlar' => $weekDays,
]));
assertKalemIntegrity($weekFs, 'weekly FS path');
$fs = findKalemler($weekFs, 'FAZLA_SURELERLE_CALISMA_ODEMESI');
$fm = findKalemler($weekFs, 'FAZLA_MESAI_ODEMESI');
engineAssert(count($fs) === 1 && (int) $fs[0]['miktar'] === 300, 'FS 300 dk (2400..2700)');
engineAssert(count($fm) === 0, 'FM yok when total=2700');

// FM band: 5x600=3000 → FS=300, FM=300
$weekFmDays = [];
for ($d = 2; $d <= 6; $d++) {
    $weekFmDays[] = [
        'muhur_satir_id' => 200 + $d,
        'tarih' => sprintf('2026-03-%02d', $d),
        'gun_tipi' => 'Normal_Is_Gunu',
        'net_calisma_suresi_dakika' => 600,
    ];
}
$weekFm = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture([
        'GUNLUK_CALISMA_SAATI' => '8',
        'HAFTALIK_IS_GUNU_SAYISI' => '5',
        'AYLIK_NORMAL_CALISMA_SAATI' => '225',
    ]),
    'puantajlar' => $weekFmDays,
]));
assertKalemIntegrity($weekFm, 'weekly FM path');
$fs2 = findKalemler($weekFm, 'FAZLA_SURELERLE_CALISMA_ODEMESI');
$fm2 = findKalemler($weekFm, 'FAZLA_MESAI_ODEMESI');
engineAssert(count($fs2) === 1 && (int) $fs2[0]['miktar'] === 300, 'FS 300 dk above contract');
engineAssert(count($fm2) === 1 && (int) $fm2[0]['miktar'] === 300, 'FM 300 dk above 2700');

// Hafta tatili GUNLUK_ILAVE
$ht = MaasHesaplamaEngine::calculate(engineInput('BRUT', '30000.00', [
    'puantajlar' => [[
        'muhur_satir_id' => 3,
        'tarih' => '2026-03-08',
        'gun_tipi' => 'Hafta_Tatili_Pazar',
        'net_calisma_suresi_dakika' => 480,
    ]],
]));
assertKalemIntegrity($ht, 'HT path');
$htKalem = findKalemler($ht, 'HAFTA_TATILI_ODEMESI');
engineAssert(count($htKalem) === 1 && (string) $htKalem[0]['tutar'] === '1000.00', 'HT gunluk ilave = 30000/30');

// UBGT SAAT_CARPAN modu
$ubgtSaat = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture([
        'UBGT_HESAP_MODU' => 'SAAT_CARPAN',
        'UBGT_CARPANI' => '2',
        'AYLIK_NORMAL_CALISMA_SAATI' => '225',
    ]),
    'puantajlar' => [[
        'muhur_satir_id' => 4,
        'tarih' => '2026-03-02',
        'gun_tipi' => 'UBGT_Resmi_Tatil',
        'net_calisma_suresi_dakika' => 60,
    ]],
]));
assertKalemIntegrity($ubgtSaat, 'UBGT SAAT_CARPAN path');
$ubgt2 = findKalemler($ubgtSaat, 'UBGT_ODEMESI');
// hourly = 45000 * 60 / (225*60) = 45000/225 = 200; 1h * 2 = 400
engineAssert(count($ubgt2) === 1 && (string) $ubgt2[0]['tutar'] === '400.00', 'UBGT SAAT_CARPAN tutar');

// Tek gunluk 9h Normal_Is_Gunu artik gunluk FM uretmez
$noDailyOt = MaasHesaplamaEngine::calculate(engineInput('BRUT', '50000.00', [
    'puantajlar' => [[
        'muhur_satir_id' => 5,
        'tarih' => '2026-03-10',
        'gun_tipi' => 'Normal_Is_Gunu',
        'net_calisma_suresi_dakika' => 540,
    ]],
]));
engineAssert(count(findKalemler($noDailyOt, 'FAZLA_MESAI_ODEMESI')) === 0, 'V1 daily OT removed');
engineAssert(count(findKalemler($noDailyOt, 'FAZLA_SURELERLE_CALISMA_ODEMESI')) === 0, 'single day under weekly contract');

echo 'verify-maas-hesaplama-engine: OK' . PHP_EOL;
