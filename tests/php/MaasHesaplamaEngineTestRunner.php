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
engineAssert(MaasHesaplamaEngine::ENGINE_VERSION === 'S85B_PAYROLL_ENGINE_V2', 'Engine version S85-B V2');
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
engineAssert((string) $brut['engine_version'] === 'S85B_PAYROLL_ENGINE_V2', 'BRUT result engine_version S85-B V2');

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

// HT fiili çalışma 45 saat havuzundadır; tatil ek ödeme tabanı FSC kaleminde ikinci kez ödenmez.
$htOverlap = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'puantajlar' => [
        ['muhur_satir_id' => 920, 'tarih' => '2026-03-02', 'gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 2400],
        ['muhur_satir_id' => 921, 'tarih' => '2026-03-08', 'gun_tipi' => 'Hafta_Tatili_Pazar', 'net_calisma_suresi_dakika' => 120],
    ],
]));
$htOverlapHoliday = findKalemler($htOverlap, 'HAFTA_TATILI_ODEMESI');
$htOverlapFs = findKalemler($htOverlap, 'FAZLA_SURELERLE_CALISMA_ODEMESI');
$htOverlapCredits = findKalemler($htOverlap, 'TATIL_TABAN_UCRET_MAHSUBU');
engineAssert(count($htOverlapHoliday) === 1 && (string) $htOverlapHoliday[0]['tutar'] === '1500.00', 'HT ek odeme ayri havuz 1500');
engineAssert(count($htOverlapFs) === 1 && (int) $htOverlapFs[0]['payload_json']['haftalik_toplam_dk'] === 2520, 'HT fiili dakika haftalik havuzda');
engineAssert((int) $htOverlapFs[0]['payload_json']['haftalik_tatil_calisma_dk'] === 120, 'HT tatil havuzu audit 120 dk');
engineAssert((int) $htOverlapFs[0]['payload_json']['tatil_taban_mahsup_dk'] === 120, 'HT FSC temel ucret mahsup 120 dk');
engineAssert((string) $htOverlapFs[0]['tutar'] === '100.00', 'HT FSC yalniz 0.25 ilave fark odemesi');
engineAssert(count($htOverlapCredits) === 1 && (string) $htOverlapCredits[0]['tutar'] === '400.00', 'HT temel ucret mahsup bilgi kalemi');

// UBGT de fiili havuza dahildir; 45 saat ustu cakismada yalniz 0.50 ilave fark kalir.
$ubgtOverlap = MaasHesaplamaEngine::calculate(engineInput('BRUT', '45000.00', [
    'puantajlar' => [
        ['muhur_satir_id' => 930, 'tarih' => '2026-03-02', 'gun_tipi' => 'Normal_Is_Gunu', 'net_calisma_suresi_dakika' => 2700],
        ['muhur_satir_id' => 931, 'tarih' => '2026-03-03', 'gun_tipi' => 'UBGT_Resmi_Tatil', 'net_calisma_suresi_dakika' => 60],
    ],
]));
$ubgtOverlapHoliday = findKalemler($ubgtOverlap, 'UBGT_ODEMESI');
$ubgtOverlapFm = findKalemler($ubgtOverlap, 'FAZLA_MESAI_ODEMESI');
$ubgtOverlapCredits = findKalemler($ubgtOverlap, 'TATIL_TABAN_UCRET_MAHSUBU');
engineAssert(count($ubgtOverlapHoliday) === 1 && (string) $ubgtOverlapHoliday[0]['tutar'] === '1500.00', 'UBGT ek odeme ayri havuz 1500');
engineAssert(count($ubgtOverlapFm) === 1 && (int) $ubgtOverlapFm[0]['payload_json']['haftalik_toplam_dk'] === 2760, 'UBGT fiili dakika haftalik havuzda');
engineAssert((int) $ubgtOverlapFm[0]['payload_json']['tatil_taban_mahsup_dk'] === 60, 'UBGT FM temel ucret mahsup 60 dk');
engineAssert((string) $ubgtOverlapFm[0]['tutar'] === '100.00', 'UBGT FM yalniz 0.50 ilave fark odemesi');
engineAssert(count($ubgtOverlapCredits) === 1 && (string) $ubgtOverlapCredits[0]['tutar'] === '200.00', 'UBGT temel ucret mahsup bilgi kalemi');

// NET tatil günlük tabanı da solver sonrası baz brütten gelir.
$netHoliday = MaasHesaplamaEngine::calculate(engineInput('NET', '30000.00', [
    'puantajlar' => [[
        'muhur_satir_id' => 940,
        'tarih' => '2026-03-02',
        'gun_tipi' => 'UBGT_Resmi_Tatil',
        'net_calisma_suresi_dakika' => 60,
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

echo 'verify-maas-hesaplama-engine: OK' . PHP_EOL;
