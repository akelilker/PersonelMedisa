<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Money\Money;
use Medisa\Api\Services\Money\Rate;
use Medisa\Api\Services\Payroll\FinanceKalemCatalog;
use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use Medisa\Api\Services\Payroll\MaasHesaplamaLegalParameterCatalog;
use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;

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
        if ($meta === null) {
            $meta = SirketCalismaPolitikasiCatalog::meta($code);
        }
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
        'sgk_hesabi' => engineSgkFixture(30),
        'donem_baslangic' => '2026-03-01',
        'donem_bitis' => '2026-03-31',
    ], $overrides);
}

/** @return array<string, mixed> */
function engineSgkFixture(int $primDay): array
{
    return [
        'hesaplanan_prim_gunu' => $primDay,
        'eksik_gun_sayisi' => 30 - $primDay,
        'eksik_gun_kodu' => $primDay < 30 ? '01' : null,
        'eksik_gun_aciklamasi' => $primDay < 30 ? 'Istirahat' : null,
        'manuel_inceleme_gerekli_mi' => false,
        'blocker_kodlari' => [],
        'sgk_hesap_hash' => hash('sha256', 'sgk-' . $primDay),
        'katalog_surumu' => 'TEST_DOGRULANMIS_KATALOG_V1',
        'kaynak_manifest_hash' => str_repeat('b', 64),
        'gunluk_alt_sinir' => '866.86',
        'gunluk_ust_sinir' => '6501.45',
        'donem_alt_sinir' => bcmul('866.86', (string) $primDay, 2),
        'donem_ust_sinir' => bcmul('6501.45', (string) $primDay, 2),
    ];
}

/** @return array<string, mixed> */
function weeklyEngineResult(
    int $totalMinutes,
    string $ucretTuru = 'BRUT',
    string $tutar = '45000.00'
): array
{
    return MaasHesaplamaEngine::calculate(engineInput($ucretTuru, $tutar, [
        'mevzuat' => mevzuatFixture([
            'GUNLUK_CALISMA_SAATI' => '8',
            'HAFTALIK_IS_GUNU_SAYISI' => '5',
            'AYLIK_NORMAL_CALISMA_SAATI' => '225',
        ]),
        'puantajlar' => [[
            'muhur_satir_id' => 900,
            'tarih' => '2026-03-02',
            'gun_tipi' => 'Normal_Is_Gunu',
            'net_calisma_suresi_dakika' => $totalMinutes,
        ]],
    ]));
}

/** @return array<string, mixed> */
function holidayOverlapResult(
    string $ucretTuru,
    string $tutar,
    string $gunTipi,
    int $normalMinutes,
    int $holidayMinutes,
    array $mevzuatOverrides = [],
    array $extraPuantaj = []
): array {
    return MaasHesaplamaEngine::calculate(engineInput($ucretTuru, $tutar, [
        'mevzuat' => mevzuatFixture($mevzuatOverrides),
        'puantajlar' => array_merge([
            [
                'muhur_satir_id' => 920,
                'tarih' => '2026-03-02',
                'gun_tipi' => 'Normal_Is_Gunu',
                'net_calisma_suresi_dakika' => $normalMinutes,
            ],
            [
                'muhur_satir_id' => 921,
                'tarih' => $gunTipi === 'Hafta_Tatili_Pazar' ? '2026-03-08' : '2026-03-03',
                'gun_tipi' => $gunTipi,
                'net_calisma_suresi_dakika' => $holidayMinutes,
            ] + ($gunTipi === 'UBGT_Resmi_Tatil' ? ['ubgt_gun_kapsami' => 'TAM_GUN'] : []),
        ], $extraPuantaj),
    ]));
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
engineAssert(
    SirketCalismaPolitikasiCatalog::isKnown(MaasHesaplamaEngine::HOLIDAY_OVERTIME_POLICY_CODE),
    'holiday overtime mode generic company policy owner'
);
engineAssert(
    in_array(MaasHesaplamaEngine::HOLIDAY_OVERTIME_POLICY_CODE, SirketCalismaPolitikasiCatalog::requiredCodes(), true),
    'holiday overtime mode required and has no engine default'
);
engineAssert(MaasHesaplamaEngine::ENGINE_VERSION === 'S91C2_PAYROLL_ENGINE_V2', 'Engine version S91-C2 V2');
engineAssert(MaasHesaplamaEngine::CONTRACT_VERSION === 'S85B_PAYROLL_CANDIDATE_V1', 'Contract version S85-B');

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
engineAssert((string) $brut['engine_version'] === 'S91C2_PAYROLL_ENGINE_V2', 'BRUT result engine_version S91-C2 V2');

// Engine NET path, no extras
$targetNet = '30000.00';
$net = MaasHesaplamaEngine::calculate(engineInput('NET', $targetNet));
assertKalemIntegrity($net, 'NET happy path');
engineAssert(is_array($net['solver']), 'NET path solver kullanildi');
engineAssert((int) $net['solver']['iterations'] <= MaasHesaplamaEngine::SOLVER_MAX_ITERATIONS, 'NET solver iterations <= 64');
engineAssert(abs(decimalKurus((string) $net['ozet']['net_odenecek']) - decimalKurus($targetNet)) <= 1, 'NET net within 1 kurus');
engineAssert(
    decimalKurus((string) $net['ozet']['ucret_hesaplama_baz_brut_tutar']) > decimalKurus($targetNet),
    'NET saatlik/gunluk taban solver sonrasi baz brut'
);
engineAssert(
    (string) $net['ozet']['ucret_hesaplama_baz_brut_tutar'] === (string) $net['ozet']['sozlesme_brut_tutar'],
    'NET ucret hesaplama baz brutu ozetle ayni'
);
engineAssert(
    (string) $net['ozet']['saatlik_brut_ucret']
        === Money::fromDecimalString((string) $net['ozet']['sozlesme_brut_tutar'])->mulDiv(1, 225)->toDecimalString(),
    'NET saatlik taban solved brut / 225'
);
engineAssert(
    (string) $net['ozet']['gunluk_brut_ucret']
        === Money::fromDecimalString((string) $net['ozet']['sozlesme_brut_tutar'])->mulDiv(1, 30)->toDecimalString(),
    'NET gunluk taban solved brut / 30'
);

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
        'ubgt_gun_kapsami' => 'TAM_GUN',
    ]],
]));
assertKalemIntegrity($frac, 'fractional hours path');
$ubgt = findKalemler($frac, 'UBGT_ODEMESI');
engineAssert(count($ubgt) === 1, 'UBGT GUNLUK_ILAVE kalem');
// gunluk = 45000/30 = 1500; carpan=1 → 1500.00
engineAssert((string) $ubgt[0]['tutar'] === '1500.00', 'UBGT gunluk ilave tutar');
engineAssert((string) $ubgt[0]['birim'] === 'GUN', 'UBGT birim GUN');
engineAssert((string) ($ubgt[0]['payload_json']['ubgt_gun_kapsami'] ?? '') === 'TAM_GUN', 'UBGT TAM_GUN audit');

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

// Mevzuat yuvarlamasi yalniz FSC/FM bantlarinda: <30 => 30, =30 => 30, >30 => 60.
$overtimeBoundaries = [
    0 => 0,
    1 => 30,
    29 => 30,
    30 => 30,
    31 => 60,
    59 => 60,
    60 => 60,
    61 => 90,
];
foreach (['BRUT' => '45000.00', 'NET' => '30000.00'] as $contractType => $contractAmount) {
    foreach ($overtimeBoundaries as $rawMinutes => $roundedMinutes) {
        $fsResult = weeklyEngineResult(2400 + $rawMinutes, $contractType, $contractAmount);
        $fsBoundary = findKalemler($fsResult, 'FAZLA_SURELERLE_CALISMA_ODEMESI');
        engineAssert(
            $rawMinutes === 0
                ? count($fsBoundary) === 0
                : count($fsBoundary) === 1 && (int) $fsBoundary[0]['miktar'] === $roundedMinutes,
            'Engine V2 ' . $contractType . ' FSC rounding boundary ' . $rawMinutes . ' => ' . $roundedMinutes
        );
        if ($rawMinutes > 0) {
            engineAssert(
                (int) $fsBoundary[0]['payload_json']['ham_fazla_surelerle_calisma_dk'] === $rawMinutes,
                'Engine V2 ' . $contractType . ' FSC raw audit minute ' . $rawMinutes
            );
            engineAssert(
                (string) $fsBoundary[0]['payload_json']['ucret_hesaplama_baz_brut_tutar']
                    === (string) $fsResult['ozet']['sozlesme_brut_tutar'],
                'Engine V2 ' . $contractType . ' FSC solved brut base owner ' . $rawMinutes
            );
            $expectedFsAmount = Money::fromDecimalString((string) $fsResult['ozet']['saatlik_brut_ucret'])
                ->mulDiv($roundedMinutes, 60)
                ->applyRate(Rate::fromDecimalString('1.25'));
            engineAssert(
                (string) $fsBoundary[0]['tutar'] === $expectedFsAmount->toDecimalString(),
                'Engine V2 ' . $contractType . ' FSC payable amount matrix ' . $rawMinutes
            );
        }

        $fmResult = weeklyEngineResult(2700 + $rawMinutes, $contractType, $contractAmount);
        $fmBoundary = findKalemler($fmResult, 'FAZLA_MESAI_ODEMESI');
        engineAssert(
            $rawMinutes === 0
                ? count($fmBoundary) === 0
                : count($fmBoundary) === 1 && (int) $fmBoundary[0]['miktar'] === $roundedMinutes,
            'Engine V2 ' . $contractType . ' FM rounding boundary ' . $rawMinutes . ' => ' . $roundedMinutes
        );
        if ($rawMinutes > 0) {
            engineAssert(
                (int) $fmBoundary[0]['payload_json']['ham_fazla_calisma_dk'] === $rawMinutes,
                'Engine V2 ' . $contractType . ' FM raw audit minute ' . $rawMinutes
            );
            engineAssert(
                (string) $fmBoundary[0]['payload_json']['ucret_hesaplama_baz_brut_tutar']
                    === (string) $fmResult['ozet']['sozlesme_brut_tutar'],
                'Engine V2 ' . $contractType . ' FM solved brut base owner ' . $rawMinutes
            );
            $expectedFmAmount = Money::fromDecimalString((string) $fmResult['ozet']['saatlik_brut_ucret'])
                ->mulDiv($roundedMinutes, 60)
                ->applyRate(Rate::fromDecimalString('1.5'));
            engineAssert(
                (string) $fmBoundary[0]['tutar'] === $expectedFmAmount->toDecimalString(),
                'Engine V2 ' . $contractType . ' FM payable amount matrix ' . $rawMinutes
            );
        }
    }
}

// Parçalı satırlar tek ISO hafta/bant owner'inda toplanır; 31 dk satır bazında üç kez yuvarlanmaz.
$fragmented = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'puantajlar' => [
        ['muhur_satir_id' => 910, 'tarih' => '2026-03-02', 'gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 487],
        ['muhur_satir_id' => 911, 'tarih' => '2026-03-03', 'gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 486],
        ['muhur_satir_id' => 912, 'tarih' => '2026-03-04', 'gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 486],
        ['muhur_satir_id' => 913, 'tarih' => '2026-03-05', 'gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 486],
        ['muhur_satir_id' => 914, 'tarih' => '2026-03-06', 'gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 486],
    ],
]));
$fragmentedFs = findKalemler($fragmented, 'FAZLA_SURELERLE_CALISMA_ODEMESI');
engineAssert(count($fragmentedFs) === 1, 'fragmented FSC tek haftalik kalem');
engineAssert((int) $fragmentedFs[0]['payload_json']['ham_fazla_surelerle_calisma_dk'] === 31, 'fragmented FSC ham toplam 31 dk');
engineAssert((int) $fragmentedFs[0]['miktar'] === 60, 'fragmented FSC haftalik toplam bir kez 60 dk yuvarlanir');

// HT/UBGT ile FSC/FM ayni haftalik havuza girdiginde yetkili politika yoksa aday uretilmez.
foreach (['BRUT' => '45000.00', 'NET' => '30000.00'] as $contractType => $contractAmount) {
    foreach (['Hafta_Tatili_Pazar' => 'HT', 'UBGT_Resmi_Tatil' => 'UBGT'] as $holidayType => $holidayLabel) {
        foreach ([1, 29, 30, 31, 59, 60, 61] as $rawMinutes) {
            foreach ([2400 => 'FSC', 2700 => 'FM'] as $normalMinutes => $bandLabel) {
                $overlap = holidayOverlapResult(
                    $contractType,
                    $contractAmount,
                    $holidayType,
                    $normalMinutes,
                    $rawMinutes
                );
                engineAssert(
                    empty($overlap['ok'])
                        && (string) $overlap['error_code'] === MaasHesaplamaEngine::HOLIDAY_OVERTIME_ERROR_CODE
                        && (string) $overlap['error_message'] === MaasHesaplamaEngine::HOLIDAY_OVERTIME_ERROR_MESSAGE,
                    $contractType . ' ' . $holidayLabel . ' ' . $bandLabel . ' overlap ' . $rawMinutes . ' dk fail-closed'
                );
                engineAssert(
                    !isset($overlap['kalemler']),
                    $contractType . ' ' . $holidayLabel . ' ' . $bandLabel . ' overlap candidate yok ' . $rawMinutes . ' dk'
                );
            }
        }
    }
}

foreach ([449, 450, 451] as $holidayMinutes) {
    $candidateBoundary = holidayOverlapResult(
        'BRUT',
        '45000.00',
        'UBGT_Resmi_Tatil',
        2400,
        $holidayMinutes
    );
    engineAssert(
        empty($candidateBoundary['ok'])
            && (string) $candidateBoundary['error_code'] === MaasHesaplamaEngine::HOLIDAY_OVERTIME_ERROR_CODE,
        'missing mode UBGT candidate ' . $holidayMinutes . ' dk fail-closed'
    );
}

$yargitayMode = [MaasHesaplamaEngine::HOLIDAY_OVERTIME_POLICY_CODE => MaasHesaplamaEngine::HOLIDAY_OVERTIME_APPROVED_MODE];
foreach (['Hafta_Tatili_Pazar' => 'HT', 'UBGT_Resmi_Tatil' => 'UBGT'] as $holidayType => $holidayLabel) {
    foreach ([0, 1, 449, 450, 451, 600] as $holidayMinutes) {
        foreach ([2400 => 'FSC', 2700 => 'FM'] as $normalMinutes => $bandLabel) {
            $overlap = holidayOverlapResult(
                'BRUT',
                '45000.00',
                $holidayType,
                $normalMinutes,
                $holidayMinutes,
                $yargitayMode
            );
            $poolExcess = max(0, $holidayMinutes - MaasHesaplamaEngine::YARGITAY_HOLIDAY_SPLIT_MINUTES);
            $expectedTotal = $normalMinutes + $poolExcess;
            $rawFs = max(0, min($expectedTotal - 2400, 300));
            $rawFm = max(0, $expectedTotal - 2700);
            $expectedFs = $bandLabel === 'FSC' ? $rawFs : 0;
            $expectedFm = $bandLabel === 'FM' ? $rawFm : 0;
            engineAssert(!empty($overlap['ok']), $holidayLabel . ' YARGITAY ' . $bandLabel . ' ' . $holidayMinutes . ' dk hesaplanir');
            $fsLines = findKalemler($overlap, 'FAZLA_SURELERLE_CALISMA_ODEMESI');
            $fmLines = findKalemler($overlap, 'FAZLA_MESAI_ODEMESI');
            if ($bandLabel === 'FSC') {
                engineAssert(
                    $expectedFs < 1 ? count($fsLines) === 0 : count($fsLines) === 1 && (int) $fsLines[0]['payload_json']['ham_fazla_surelerle_calisma_dk'] === $expectedFs,
                    $holidayLabel . ' YARGITAY FSC boundary ' . $holidayMinutes . ' dk raw=' . $expectedFs
                );
            } else {
                engineAssert(
                    $expectedFm < 1 ? count($fmLines) === 0 : count($fmLines) === 1 && (int) $fmLines[0]['payload_json']['ham_fazla_calisma_dk'] === $expectedFm,
                    $holidayLabel . ' YARGITAY FM boundary ' . $holidayMinutes . ' dk raw=' . $expectedFm
                );
            }
        }
    }
}

$yargitayWeekly = holidayOverlapResult(
    'BRUT',
    '45000.00',
    'Hafta_Tatili_Pazar',
    2400,
    450,
    $yargitayMode
);
engineAssert(!empty($yargitayWeekly['ok']), 'YARGITAY Normal 2400 + HT 450 => FSC/FM 0');
engineAssert(count(findKalemler($yargitayWeekly, 'FAZLA_MESAI_ODEMESI')) === 0, 'YARGITAY HT 450 FM yok');
engineAssert(count(findKalemler($yargitayWeekly, 'FAZLA_SURELERLE_CALISMA_ODEMESI')) === 0, 'YARGITAY HT 450 FSC yok');
engineAssert(count(findKalemler($yargitayWeekly, 'HAFTA_TATILI_ODEMESI')) === 1, 'YARGITAY HT 450 premium odendi');

$yargitayFm150 = holidayOverlapResult(
    'BRUT',
    '45000.00',
    'Hafta_Tatili_Pazar',
    2700,
    600,
    $yargitayMode
);
$fm150 = findKalemler($yargitayFm150, 'FAZLA_MESAI_ODEMESI');
engineAssert(
    !empty($yargitayFm150['ok']) && count($fm150) === 1 && (int) $fm150[0]['payload_json']['ham_fazla_calisma_dk'] === 150,
    'YARGITAY Normal 2700 + HT 600 => FM 150'
);

$htUbgtSameDay = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture($yargitayMode),
    'puantajlar' => [
        [
            'muhur_satir_id' => 920,
            'tarih' => '2026-03-02',
            'gun_tipi' => 'Normal_Is_Gunu',
            'net_calisma_suresi_dakika' => 2700,
        ],
        [
            'muhur_satir_id' => 921,
            'tarih' => '2026-03-08',
            'gun_tipi' => 'Hafta_Tatili_Pazar',
            'net_calisma_suresi_dakika' => 600,
            'ht_ubgt_ayni_gun_mi' => true,
        ],
    ],
]));
$htUbgtLine = findKalemler($htUbgtSameDay, 'HAFTA_TATILI_ODEMESI');
engineAssert(
    !empty($htUbgtSameDay['ok'])
        && count($htUbgtLine) === 1
        && (string) ($htUbgtLine[0]['payload_json']['ht_ubgt_cakisma_hesap_modu'] ?? '') === 'HAFTA_TATILI_ESAS',
    'HT+UBGT ayni gun yalniz HT premium'
);
engineAssert(count(findKalemler($htUbgtSameDay, 'UBGT_ODEMESI')) === 0, 'HT+UBGT ayni gun UBGT premium yok');

$halfDayPolicy = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture($yargitayMode),
    'puantajlar' => [[
        'muhur_satir_id' => 950,
        'tarih' => '2026-03-03',
        'gun_tipi' => 'UBGT_Resmi_Tatil',
        'net_calisma_suresi_dakika' => 120,
        'ubgt_gun_kapsami' => 'YARIM_GUN',
        'yarim_gun_tatil_interval_dakika' => 240,
    ]],
]));
engineAssert(
    empty($halfDayPolicy['ok'])
        && (string) $halfDayPolicy['error_code'] === MaasHesaplamaEngine::HALF_DAY_UBGT_POLICY_ERROR_CODE
        && (string) $halfDayPolicy['error_message'] === MaasHesaplamaEngine::HALF_DAY_UBGT_POLICY_ERROR_MESSAGE,
    'yarim gun UBGT tum net sureler fail-closed'
);

foreach ([1, 225, 450, 600] as $halfNet) {
    $halfMatrix = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
        'mevzuat' => mevzuatFixture($yargitayMode),
        'puantajlar' => [[
            'muhur_satir_id' => 951,
            'tarih' => '2026-03-03',
            'gun_tipi' => 'UBGT_Resmi_Tatil',
            'net_calisma_suresi_dakika' => $halfNet,
            'ubgt_gun_kapsami' => 'YARIM_GUN',
            'yarim_gun_tatil_interval_dakika' => 240,
        ]],
    ]));
    engineAssert(
        empty($halfMatrix['ok'])
            && (string) $halfMatrix['error_code'] === MaasHesaplamaEngine::HALF_DAY_UBGT_POLICY_ERROR_CODE,
        'YARIM_GUN UBGT net ' . $halfNet . ' fail-closed'
    );
}

foreach ([null, '', ' ', 'TAM', 'FULL_DAY'] as $idx => $badScope) {
    $row = [
        'muhur_satir_id' => 960 + $idx,
        'tarih' => '2026-03-03',
        'gun_tipi' => 'UBGT_Resmi_Tatil',
        'net_calisma_suresi_dakika' => 1,
    ];
    if ($badScope !== null) {
        $row['ubgt_gun_kapsami'] = $badScope;
    }
    $unknownScope = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
        'mevzuat' => mevzuatFixture($yargitayMode),
        'puantajlar' => [$row],
    ]));
    engineAssert(
        empty($unknownScope['ok'])
            && (string) $unknownScope['error_code'] === MaasHesaplamaEngine::UBGT_DAY_SCOPE_ERROR_CODE
            && (string) $unknownScope['error_message'] === MaasHesaplamaEngine::UBGT_DAY_SCOPE_ERROR_MESSAGE,
        'UBGT bilinmeyen kapsam fail-closed case ' . var_export($badScope, true)
    );
}

engineAssert(
    MaasHesaplamaEngine::resolveUbgtGunKapsami(['ubgt_gun_kapsami' => 'tam_gun']) === 'TAM_GUN',
    'resolveUbgtGunKapsami trim+upper TAM_GUN'
);
engineAssert(
    MaasHesaplamaEngine::resolveUbgtGunKapsami(['tatil_gun_kapsami' => 'YARIM_GUN']) === 'YARIM_GUN',
    'resolveUbgtGunKapsami tatil_gun_kapsami'
);
engineAssert(
    MaasHesaplamaEngine::resolveUbgtGunKapsami(['tarih' => '2026-01-01', 'net_calisma_suresi_dakika' => 480]) === 'BILINMIYOR',
    'resolveUbgtGunKapsami tarih/net inference yok'
);

$htUbgtMissingScope = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture($yargitayMode),
    'puantajlar' => [[
        'muhur_satir_id' => 970,
        'tarih' => '2026-03-08',
        'gun_tipi' => 'Hafta_Tatili_Pazar',
        'net_calisma_suresi_dakika' => 480,
        'ht_ubgt_ayni_gun_mi' => true,
    ]],
]));
engineAssert(!empty($htUbgtMissingScope['ok']), 'HT+UBGT kapsam yok HT esas hesaplanir');
engineAssert(count(findKalemler($htUbgtMissingScope, 'HAFTA_TATILI_ODEMESI')) === 1, 'HT+UBGT tek HT odeme');
engineAssert(count(findKalemler($htUbgtMissingScope, 'UBGT_ODEMESI')) === 0, 'HT+UBGT UBGT odeme yok');

engineAssert(
    MaasHesaplamaEngine::buildFmDegerlendirmeHavuzuDk([
        ['gun_tipi' => 'UBGT_Resmi_Tatil', 'net_calisma_suresi_dakika' => 600],
    ]) === 0,
    'buildFmDegerlendirmeHavuzuDk UBGT kapsamsiz 0'
);
engineAssert(
    MaasHesaplamaEngine::buildFmDegerlendirmeHavuzuDk([
        ['gun_tipi' => 'UBGT_Resmi_Tatil', 'net_calisma_suresi_dakika' => 600, 'ubgt_gun_kapsami' => 'TAM_GUN'],
    ]) === 150,
    'buildFmDegerlendirmeHavuzuDk UBGT TAM_GUN 600 => 150'
);

engineAssert(
    MaasHesaplamaEngine::buildFmDegerlendirmeHavuzuDk([
        ['gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 2700],
        ['gun_tipi' => 'Hafta_Tatili_Pazar', 'net_calisma_suresi_dakika' => 450],
    ]) === 2700,
    'buildFmDegerlendirmeHavuzuDk Normal 2700 + HT 450'
);
engineAssert(
    MaasHesaplamaEngine::buildFmDegerlendirmeHavuzuDk([
        ['gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 2700],
        ['gun_tipi' => 'Hafta_Tatili_Pazar', 'net_calisma_suresi_dakika' => 600],
    ]) === 2850,
    'buildFmDegerlendirmeHavuzuDk Normal 2700 + HT 600'
);

foreach ([
    2399 => false,
    2400 => false,
    2401 => true,
    2699 => true,
    2700 => true,
    2701 => true,
] as $weeklyTotal => $mustBlock) {
    $weeklyBoundary = holidayOverlapResult(
        'BRUT',
        '45000.00',
        'Hafta_Tatili_Pazar',
        $weeklyTotal - 1,
        1
    );
    engineAssert(
        $mustBlock
            ? empty($weeklyBoundary['ok']) && (string) $weeklyBoundary['error_code'] === MaasHesaplamaEngine::HOLIDAY_OVERTIME_ERROR_CODE
            : !empty($weeklyBoundary['ok']),
        'holiday weekly boundary ' . $weeklyTotal . ' dk fail-closed matrix'
    );
}

$engineSource = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Payroll/MaasHesaplamaEngine.php');
engineAssert(strpos($engineSource, 'TATIL_TABAN_UCRET_MAHSUBU') === false, 'authoritative tatil taban mahsup kalemi kaldirildi');

// NET tatil günlük tabanı da solver sonrası baz brütten gelir.
$netHoliday = MaasHesaplamaEngine::calculate(engineInput('NET', '30000.00', [
    'puantajlar' => [[
        'muhur_satir_id' => 940,
        'tarih' => '2026-03-02',
        'gun_tipi' => 'UBGT_Resmi_Tatil',
        'net_calisma_suresi_dakika' => 60,
        'ubgt_gun_kapsami' => 'TAM_GUN',
    ]],
]));
$netHolidayLine = findKalemler($netHoliday, 'UBGT_ODEMESI');
engineAssert(
    count($netHolidayLine) === 1
        && (string) $netHolidayLine[0]['matrah'] === (string) $netHoliday['ozet']['gunluk_brut_ucret'],
    'NET tatil kalemi solver sonrasi gunluk brut taban'
);

// Gec/erken kesintisi ayri owner: ham dakika korunur; FM mevzuat yuvarlamasi uygulanmaz.
$lateEarly = MaasHesaplamaEngine::calculate(engineInput('BRUT', '30000.00', [
    'puantajlar' => [[
        'muhur_satir_id' => 901,
        'tarih' => '2026-03-02',
        'gun_tipi' => 'Normal_Is_Gunu',
        'net_calisma_suresi_dakika' => 0,
        'gec_kalma_dakika' => 1,
        'erken_cikis_dakika' => 31,
    ]],
]));
$lateLines = findKalemler($lateEarly, 'GEC_KALMA_KESINTISI');
$earlyLines = findKalemler($lateEarly, 'ERKEN_CIKIS_KESINTISI');
engineAssert(count($lateLines) === 1 && (int) $lateLines[0]['miktar'] === 1, 'Engine V2 gec kalma ham 1 dk');
engineAssert(count($earlyLines) === 1 && (int) $earlyLines[0]['miktar'] === 31, 'Engine V2 erken cikis ham 31 dk');
// Saatlik = 30000/225 = 133.333...; 1dk = 2.22, 31dk = 68.89
engineAssert((string) $lateLines[0]['tutar'] === '2.22', 'Engine V2 gec kalma tutar FE parity');
engineAssert((string) $earlyLines[0]['tutar'] === '68.89', 'Engine V2 erken cikis tutar FE parity');

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
        'ubgt_gun_kapsami' => 'TAM_GUN',
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

foreach (['BRUT', 'NET'] as $salaryType) {
    foreach ([1, 29, 30] as $primDay) {
        $bounded = MaasHesaplamaEngine::calculate(engineInput($salaryType, '50000.00', [
            'sgk_hesabi' => engineSgkFixture($primDay),
        ]));
        engineAssert(!empty($bounded['ok']), $salaryType . ' SGK ' . $primDay . ' gun hesaplanir');
        engineAssert((int) $bounded['ozet']['hesaplanan_prim_gunu'] === $primDay, $salaryType . ' SGK prim gunu parity');
        $matrahLines = findKalemler($bounded, 'SGK_MATRAH');
        engineAssert(count($matrahLines) === 1 && (int) $matrahLines[0]['miktar'] === $primDay, $salaryType . ' SGK matrah gunu');
        engineAssert(
            (string) $matrahLines[0]['payload_json']['taban'] === bcmul('866.86', (string) $primDay, 2),
            $salaryType . ' SGK PEK taban gun olcegi'
        );
    }
}

// ---------------------------------------------------------------------------
// S91-C2: HT/UBGT premium mahsup + sozlesme haftalik limit fail-closed
// ---------------------------------------------------------------------------
$yargitayMode = [MaasHesaplamaEngine::HOLIDAY_OVERTIME_POLICY_CODE => MaasHesaplamaEngine::HOLIDAY_OVERTIME_APPROVED_MODE];

foreach ([
    [449, 449, 0],
    [450, 450, 0],
    [451, 450, 1],
    [600, 450, 150],
] as $case) {
    [$raw, $premiumEsas, $asim] = $case;
    $split = MaasHesaplamaEngine::holidayPremiumSplitMinutes($raw, true);
    engineAssert(
        (int) $split['premium_esas_dakika'] === $premiumEsas
            && (int) $split['fsc_fm_havuz_asim_dakika'] === $asim
            && $split['mahsup_uygulandi_mi'] === true,
        'S91C2 split HT/UBGT ' . $raw . ' => premium ' . $premiumEsas . ' asim ' . $asim
    );
    foreach (['Hafta_Tatili_Pazar' => 'HAFTA_TATILI_ODEMESI', 'UBGT_Resmi_Tatil' => 'UBGT_ODEMESI'] as $gunTipi => $kalemKod) {
        $r = holidayOverlapResult('BRUT', '45000.00', $gunTipi, 0, $raw, $yargitayMode);
        engineAssert(!empty($r['ok']), 'S91C2 ' . $gunTipi . ' ' . $raw . ' hesaplanir');
        $lines = findKalemler($r, $kalemKod);
        engineAssert(count($lines) === 1, 'S91C2 ' . $gunTipi . ' ' . $raw . ' tek premium kalem');
        $p = $lines[0]['payload_json'];
        engineAssert((int) $p['net_dakika'] === $raw, 'S91C2 ' . $gunTipi . ' net_dakika ' . $raw);
        engineAssert((int) $p['premium_esas_dakika'] === $premiumEsas, 'S91C2 ' . $gunTipi . ' premium_esas ' . $premiumEsas);
        engineAssert((int) $p['fsc_fm_havuz_asim_dakika'] === $asim, 'S91C2 ' . $gunTipi . ' asim ' . $asim);
        engineAssert(!empty($p['mahsup_uygulandi_mi']), 'S91C2 ' . $gunTipi . ' mahsup true');
        engineAssert(
            (string) $p['tatil_fsc_fm_cakisma_hesap_modu'] === MaasHesaplamaEngine::HOLIDAY_OVERTIME_APPROVED_MODE,
            'S91C2 ' . $gunTipi . ' mode audit'
        );
    }
}

$sameDay = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture($yargitayMode),
    'puantajlar' => [[
        'muhur_satir_id' => 931,
        'tarih' => '2026-03-08',
        'gun_tipi' => 'Hafta_Tatili_Pazar',
        'net_calisma_suresi_dakika' => 600,
        'ht_ubgt_ayni_gun_mi' => true,
    ]],
]));
$sameDayHt = findKalemler($sameDay, 'HAFTA_TATILI_ODEMESI');
engineAssert(!empty($sameDay['ok']) && count($sameDayHt) === 1, 'S91C2 HT+UBGT ayni gun HT kalemi');
engineAssert(count(findKalemler($sameDay, 'UBGT_ODEMESI')) === 0, 'S91C2 HT+UBGT ayni gun UBGT yok');
engineAssert((int) $sameDayHt[0]['payload_json']['premium_esas_dakika'] === 450, 'S91C2 HT+UBGT premium 450');
engineAssert((int) $sameDayHt[0]['payload_json']['fsc_fm_havuz_asim_dakika'] === 150, 'S91C2 HT+UBGT tek asim 150');
engineAssert(
    MaasHesaplamaEngine::buildFmDegerlendirmeHavuzuDk([[
        'gun_tipi' => 'Hafta_Tatili_Pazar',
        'net_calisma_suresi_dakika' => 600,
        'ht_ubgt_ayni_gun_mi' => true,
    ]]) === 150,
    'S91C2 HT+UBGT havuz tek asim 150'
);

foreach (['GUNLUK_ILAVE', 'SAAT_CARPAN', 'GUNLUK_ILAVE_VE_SAAT_CARPAN'] as $mode) {
    foreach (['Hafta_Tatili_Pazar' => 'HAFTA_TATILI', 'UBGT_Resmi_Tatil' => 'UBGT'] as $gunTipi => $prefix) {
        $modeParams = $yargitayMode + [
            $prefix . '_HESAP_MODU' => $mode,
            $prefix . '_CARPANI' => '1',
            'AYLIK_NORMAL_CALISMA_SAATI' => '225',
        ];
        $cap = holidayOverlapResult('BRUT', '45000.00', $gunTipi, 0, 600, $modeParams);
        $uncapped = holidayOverlapResult('BRUT', '45000.00', $gunTipi, 0, 450, $modeParams);
        $kalemKod = $gunTipi === 'Hafta_Tatili_Pazar' ? 'HAFTA_TATILI_ODEMESI' : 'UBGT_ODEMESI';
        engineAssert(!empty($cap['ok']) && !empty($uncapped['ok']), 'S91C2 ' . $prefix . ' ' . $mode . ' hesap');
        $capLine = findKalemler($cap, $kalemKod)[0];
        $baseLine = findKalemler($uncapped, $kalemKod)[0];
        engineAssert(
            (string) $capLine['tutar'] === (string) $baseLine['tutar'],
            'S91C2 ' . $prefix . ' ' . $mode . ' 600dk premium = 450dk premium (150 asim yok)'
        );
        engineAssert((int) $capLine['payload_json']['premium_esas_dakika'] === 450, 'S91C2 ' . $prefix . ' ' . $mode . ' esas 450');
        engineAssert((int) $capLine['payload_json']['fsc_fm_havuz_asim_dakika'] === 150, 'S91C2 ' . $prefix . ' ' . $mode . ' asim 150');
    }
}

foreach ([
    [2300, 2400, 0, 0],
    [2500, 2400, 100, 0],
    [2700, 2400, 300, 0],
    [2800, 2400, 300, 100],
    [2800, 2700, 0, 100],
] as $band) {
    [$havuz, $sozlesme, $fs, $fm] = $band;
    $gunlukSaat = $sozlesme === 2700 ? '9' : '8';
    $haftaGun = $sozlesme === 2700 ? '5' : '5';
    if ($sozlesme === 2700) {
        $gunlukSaat = '9';
    }
    $poolRows = [];
    if ($havuz >= 1) {
        $poolRows[] = [
            'muhur_satir_id' => 940,
            'tarih' => '2026-03-02',
            'gun_tipi' => 'Normal_Is_Gunu',
            'net_calisma_suresi_dakika' => $havuz,
        ];
    }
    $bandResult = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
        'mevzuat' => mevzuatFixture($yargitayMode + [
            'GUNLUK_CALISMA_SAATI' => $gunlukSaat,
            'HAFTALIK_IS_GUNU_SAYISI' => $haftaGun,
        ]),
        'puantajlar' => $poolRows,
    ]));
    engineAssert(!empty($bandResult['ok']), 'S91C2 havuz ' . $havuz . ' sozlesme ' . $sozlesme . ' ok');
    $fsLines = findKalemler($bandResult, 'FAZLA_SURELERLE_CALISMA_ODEMESI');
    $fmLines = findKalemler($bandResult, 'FAZLA_MESAI_ODEMESI');
    engineAssert(
        $fs < 1 ? count($fsLines) === 0 : count($fsLines) === 1 && (int) $fsLines[0]['payload_json']['ham_fazla_surelerle_calisma_dk'] === $fs,
        'S91C2 FSC havuz=' . $havuz . ' => ' . $fs
    );
    engineAssert(
        $fm < 1 ? count($fmLines) === 0 : count($fmLines) === 1 && (int) $fmLines[0]['payload_json']['ham_fazla_calisma_dk'] === $fm,
        'S91C2 FM havuz=' . $havuz . ' => ' . $fm
    );
}

$dualHoliday = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture($yargitayMode),
    'puantajlar' => [
        [
            'muhur_satir_id' => 950,
            'tarih' => '2026-03-02',
            'gun_tipi' => 'Normal_Is_Gunu',
            'net_calisma_suresi_dakika' => 2400,
        ],
        [
            'muhur_satir_id' => 951,
            'tarih' => '2026-03-08',
            'gun_tipi' => 'Hafta_Tatili_Pazar',
            'net_calisma_suresi_dakika' => 600,
        ],
        [
            'muhur_satir_id' => 952,
            'tarih' => '2026-03-03',
            'gun_tipi' => 'UBGT_Resmi_Tatil',
            'net_calisma_suresi_dakika' => 600,
            'ubgt_gun_kapsami' => 'TAM_GUN',
        ],
    ],
]));
engineAssert(!empty($dualHoliday['ok']), 'S91C2 ayni hafta HT+UBGT ok');
$htDual = findKalemler($dualHoliday, 'HAFTA_TATILI_ODEMESI');
$ubgtDual = findKalemler($dualHoliday, 'UBGT_ODEMESI');
engineAssert(count($htDual) === 1 && (int) $htDual[0]['payload_json']['premium_esas_dakika'] === 450, 'S91C2 dual HT premium 450');
engineAssert(count($ubgtDual) === 1 && (int) $ubgtDual[0]['payload_json']['premium_esas_dakika'] === 450, 'S91C2 dual UBGT premium 450');
engineAssert((int) $htDual[0]['payload_json']['fsc_fm_havuz_asim_dakika'] === 150, 'S91C2 dual HT asim 150');
engineAssert((int) $ubgtDual[0]['payload_json']['fsc_fm_havuz_asim_dakika'] === 150, 'S91C2 dual UBGT asim 150');
// havuz = 2400 + 150 + 150 = 2700 => FSC 300, FM 0
$fsDual = findKalemler($dualHoliday, 'FAZLA_SURELERLE_CALISMA_ODEMESI');
engineAssert(
    count($fsDual) === 1 && (int) $fsDual[0]['payload_json']['ham_fazla_surelerle_calisma_dk'] === 300,
    'S91C2 dual hafta FSC 300'
);
engineAssert(count(findKalemler($dualHoliday, 'FAZLA_MESAI_ODEMESI')) === 0, 'S91C2 dual hafta FM yok');

$pass75x6 = MaasHesaplamaEngine::resolveContractWeeklyMinutes(
    MaasHesaplamaEngine::decimalHoursToMinutes('7.5'),
    6
);
engineAssert($pass75x6['ok'] && $pass75x6['sozlesme_haftalik_dk'] === 2700, 'S91C2 7.5x6=2700 PASS');
$pass9x5 = MaasHesaplamaEngine::resolveContractWeeklyMinutes(
    MaasHesaplamaEngine::decimalHoursToMinutes('9'),
    5
);
engineAssert($pass9x5['ok'] && $pass9x5['sozlesme_haftalik_dk'] === 2700, 'S91C2 9x5=2700 PASS');
$fail8x6 = MaasHesaplamaEngine::resolveContractWeeklyMinutes(
    MaasHesaplamaEngine::decimalHoursToMinutes('8'),
    6
);
engineAssert(
    !$fail8x6['ok']
        && $fail8x6['error']['code'] === MaasHesaplamaEngine::CONTRACT_WEEKLY_LIMIT_ERROR_CODE
        && $fail8x6['error']['blocker_code'] === MaasHesaplamaEngine::CONTRACT_WEEKLY_LIMIT_BLOCKER_CODE
        && $fail8x6['error']['reason'] === MaasHesaplamaEngine::CONTRACT_WEEKLY_LIMIT_REASON,
    'S91C2 8x6=2880 fail-closed'
);
$fail76x6 = MaasHesaplamaEngine::resolveContractWeeklyMinutes(
    MaasHesaplamaEngine::decimalHoursToMinutes('7.6'),
    6
);
engineAssert(
    !$fail76x6['ok'] && $fail76x6['sozlesme_haftalik_dk'] === 2736,
    'S91C2 7.6x6=2736 fail-closed'
);

$engineFail2880 = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'mevzuat' => mevzuatFixture($yargitayMode + [
        'GUNLUK_CALISMA_SAATI' => '8',
        'HAFTALIK_IS_GUNU_SAYISI' => '6',
    ]),
]));
engineAssert(
    empty($engineFail2880['ok'])
        && (string) $engineFail2880['error_code'] === MaasHesaplamaEngine::CONTRACT_WEEKLY_LIMIT_ERROR_CODE
        && (string) $engineFail2880['error_message'] === MaasHesaplamaEngine::CONTRACT_WEEKLY_LIMIT_ERROR_MESSAGE,
    'S91C2 engine 8x6 fail-closed exact code'
);

$conflictWithMode = MaasHesaplamaEngine::detectHolidayOvertimePolicyConflict(
    [],
    MaasHesaplamaEngine::decimalHoursToMinutes('8'),
    6,
    MaasHesaplamaEngine::HOLIDAY_OVERTIME_APPROVED_MODE
);
engineAssert(
    !empty($conflictWithMode['has_conflict'])
        && (string) $conflictWithMode['reason'] === MaasHesaplamaEngine::CONTRACT_WEEKLY_LIMIT_REASON
        && (string) $conflictWithMode['error_code'] === MaasHesaplamaEngine::CONTRACT_WEEKLY_LIMIT_ERROR_CODE,
    'S91C2 detectHoliday approved mode ile bile weekly guard'
);

$noMahsupWithoutMode = holidayOverlapResult('BRUT', '45000.00', 'Hafta_Tatili_Pazar', 0, 60, [
    'HAFTA_TATILI_HESAP_MODU' => 'SAAT_CARPAN',
    'HAFTA_TATILI_CARPANI' => '1',
]);
$noMahsupLine = findKalemler($noMahsupWithoutMode, 'HAFTA_TATILI_ODEMESI')[0];
engineAssert(
    empty($noMahsupLine['payload_json']['mahsup_uygulandi_mi'])
        && (int) $noMahsupLine['payload_json']['premium_esas_dakika'] === 60
        && (int) $noMahsupLine['payload_json']['net_dakika'] === 60,
    'S91C2 politika yokken mahsup uygulanmaz'
);

echo 'verify-maas-hesaplama-engine: OK' . PHP_EOL;
