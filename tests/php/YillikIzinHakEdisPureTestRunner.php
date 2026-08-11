<?php

declare(strict_types=1);

/**
 * S2C: cumulative accrual + as-of balance integrity (pure PHP, no DB).
 * php tests/php/YillikIzinHakEdisPureTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Izin\YillikIzinBakiyeService;
use Medisa\Api\Services\Izin\YillikIzinHakDuzeltmeException;
use Medisa\Api\Services\Izin\YillikIzinHakEdisService;
use Medisa\Api\Services\Izin\YillikIzinKullanimService;

function yihAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

// --- kidem ---
yihAssert(YillikIzinHakEdisService::hesaplaKidemYil('2023-01-01', '2026-01-01') === 3, 'kidem 3y');
yihAssert(YillikIzinHakEdisService::hesaplaKidemYil('2023-01-02', '2026-01-01') === 2, 'kidem 2y incomplete');
yihAssert(YillikIzinHakEdisService::hesaplaKidemYil('2026-01-01', '2026-06-15') === 0, 'kidem <1');
yihAssert(YillikIzinHakEdisService::hesaplaKidemYil('2026-04-13', '2026-04-13') === 0, 'kidem same day');
yihAssert(YillikIzinHakEdisService::hesaplaKidemYil('2026-04-13', '2020-01-01') === 0, 'kidem ref before');
yihAssert(YillikIzinHakEdisService::hesaplaKidemYil('invalid', '2026-04-13') === 0, 'kidem invalid');
yihAssert(YillikIzinHakEdisService::hesaplaKidemYil('2011-04-13', '2026-04-13') === 15, 'kidem 15');
yihAssert(YillikIzinHakEdisService::hesaplaKidemYil('2021-04-13', '2026-04-13') === 5, 'kidem exact 5');

// --- yas ---
yihAssert(YillikIzinHakEdisService::hesaplaYas('1996-04-13', '2026-04-13') === 30, 'yas 30');
yihAssert(YillikIzinHakEdisService::hesaplaYas('1996-06-15', '2026-04-13') === 29, 'yas before birthday');
yihAssert(YillikIzinHakEdisService::hesaplaYas('1976-01-01', '2026-04-13') === 50, 'yas 50');
yihAssert(YillikIzinHakEdisService::hesaplaYas('invalid', '2026-04-13') === null, 'yas invalid');

// --- annual band owner (unchanged) ---
$g0 = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2026-01-01',
    'referans_tarih' => '2026-12-31',
]);
yihAssert($g0['gun'] === 0 && $g0['yas_istisna_uygulandi'] === false, 'gun <1 → 0');

$g5 = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2021-04-13',
    'referans_tarih' => '2026-04-13',
]);
yihAssert($g5['gun'] === 14, 'exact 5y anniversary → 14 (boundary quirk)');

$g5p = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2021-04-13',
    'referans_tarih' => '2026-04-14',
]);
yihAssert($g5p['gun'] === 20, '5y + 1 day → 20');

$g14 = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2011-04-13',
    'referans_tarih' => '2026-04-12',
]);
$g15 = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2011-04-13',
    'referans_tarih' => '2026-04-13',
]);
yihAssert($g14['gun'] === 20 && $g15['gun'] === 26, '15y boundary');

$g50 = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2024-04-13',
    'dogum_tarihi' => '1976-04-13',
    'referans_tarih' => '2026-04-13',
]);
yihAssert($g50['gun'] === 20 && $g50['yas_istisna_uygulandi'] === true, 'age 50 min 20');

// --- CUMULATIVE ACCRUAL MATRIX ---
$hireToday = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2026-08-11',
    'referans_tarih' => '2026-08-11',
]);
yihAssert($hireToday['birikmis_yasal_hak_gun'] === 0 && $hireToday['mevcut_yillik_hak_gun'] === 0, 'hire today cumulative=0');

$before1 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2025-08-12',
    'referans_tarih' => '2026-08-11',
]);
yihAssert($before1['birikmis_yasal_hak_gun'] === 0, 'before 1st anniversary cumulative=0');

$exact1 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2025-08-11',
    'referans_tarih' => '2026-08-11',
]);
yihAssert($exact1['birikmis_yasal_hak_gun'] === 14 && $exact1['mevcut_yillik_hak_gun'] === 14, 'exact 1st anniversary = 14');

$exact5 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2021-04-13',
    'referans_tarih' => '2026-04-13',
]);
// 5 × 14 = 70
yihAssert($exact5['birikmis_yasal_hak_gun'] === 70 && $exact5['mevcut_yillik_hak_gun'] === 14, 'exact 5th = 70 cumulative / band 14');

$exact6 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2020-04-13',
    'referans_tarih' => '2026-04-13',
]);
// 5×14 + 20 = 90
yihAssert($exact6['birikmis_yasal_hak_gun'] === 90 && $exact6['mevcut_yillik_hak_gun'] === 20, 'exact 6th = 90 cumulative / band 20');

$exact15 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2011-04-13',
    'referans_tarih' => '2026-04-13',
]);
// 5×14 + 9×20 + 26 = 70 + 180 + 26 = 276
yihAssert($exact15['birikmis_yasal_hak_gun'] === 276 && $exact15['mevcut_yillik_hak_gun'] === 26, 'exact 15th = 276 cumulative / band 26');

// age <=18 throughout early years → each accrual year uses min 20
$age18 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2024-01-01',
    'dogum_tarihi' => '2008-06-01', // age 16 at hire; age 17 at 1st anniv 2025-01-01
    'referans_tarih' => '2025-01-01',
]);
yihAssert($age18['birikmis_yasal_hak_gun'] === 20 && $age18['accrual_breakdown'][0]['yas_istisna_uygulandi'] === true, 'age<=18 first year accrues 20');

// crosses age 19: 1st anniversary still <=18 → 20; later years without exception → 14
$cross19 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2023-01-01',
    'dogum_tarihi' => '2006-06-01', // 1st anniv 2024-01-01 age 17; 2nd 2025-01-01 age 18; 3rd 2026-01-01 age 19
    'referans_tarih' => '2026-01-01',
]);
yihAssert(
    $cross19['birikmis_yasal_hak_gun'] === 54
    && $cross19['accrual_breakdown'][0]['gun'] === 20
    && $cross19['accrual_breakdown'][1]['gun'] === 20
    && $cross19['accrual_breakdown'][2]['gun'] === 14,
    'cross age 19: 20+20+14=54'
);

// crosses age 50 during employment
$cross50 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2020-01-01',
    'dogum_tarihi' => '1974-06-01', // age 50 on 2024-06-01; 4th anniv 2024-01-01 age 49 →14; 5th 2025-01-01 age 50 →20
    'referans_tarih' => '2025-01-01',
]);
yihAssert(
    $cross50['birikmis_yasal_hak_gun'] === 76
    && $cross50['accrual_breakdown'][3]['gun'] === 14
    && $cross50['accrual_breakdown'][4]['gun'] === 20
    && $cross50['accrual_breakdown'][4]['yas_istisna_uygulandi'] === true,
    'cross age 50: years 1-4 =14, year5=20 → 76'
);

$missingDob = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2024-01-01',
    'dogum_tarihi' => null,
    'referans_tarih' => '2026-01-01',
]);
yihAssert($missingDob['birikmis_yasal_hak_gun'] === 28 && $missingDob['yas'] === null, 'missing DOB: 14+14=28');

$futureHire = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2027-01-01',
    'referans_tarih' => '2026-08-11',
]);
yihAssert($futureHire['birikmis_yasal_hak_gun'] === 0, 'future hire cumulative=0');

// Feb 29 hire boundary
$leap = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2020-02-29',
    'referans_tarih' => '2021-03-01',
]);
yihAssert($leap['kidem_yil'] === 1 && $leap['birikmis_yasal_hak_gun'] === 14, 'Feb29 hire first anniversary accrues 14');

$leapBefore = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2020-02-29',
    'referans_tarih' => '2021-02-28',
]);
yihAssert($leapBefore['birikmis_yasal_hak_gun'] === 0, 'Feb29 hire before anniversary =0');

// --- PERIOD_MISMATCH_REGRESSION ---
// Multi-year employee: current band 14, historic used 30 across prior years.
// Old bug: max(14-30,0)=0. Correct: cumulative 42 - 30 = 12.
$periodLegal = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2023-01-01',
    'referans_tarih' => '2026-08-11',
]);
yihAssert($periodLegal['mevcut_yillik_hak_gun'] === 14 && $periodLegal['birikmis_yasal_hak_gun'] === 42, 'PERIOD_MISMATCH band=14 cumulative=42');
$periodBal = YillikIzinBakiyeService::assembleFromParts(
    $periodLegal,
    0,
    ['kullanilan_gun' => 30, 'takvim_dogrulandi_mi' => true],
    0,
    '2026-08-11'
);
yihAssert(
    $periodBal['raw_remaining'] === 12
    && $periodBal['remaining'] === 12
    && $periodBal['yasal_hak_gun'] === 42
    && $periodBal['birikmis_yasal_hak_gun'] === 42
    && $periodBal['mevcut_yillik_hak_gun'] === 14,
    'PERIOD_MISMATCH_REGRESSION remaining=12 not 0'
);
yihAssert(
    $periodBal['balance_legal_semantic'] === 'CUMULATIVE_STATUTORY_ACCRUAL_AS_OF_REFERENCE_DATE',
    'balance semantic cumulative'
);

// Old formula would have clamped to 0:
yihAssert(max(14 - 30, 0) === 0, 'old formula would wrongly return 0');

// --- USED LEAVE AS-OF ---
$surecLeave = [
    [
        'surec_turu' => 'IZIN',
        'alt_tur' => 'YILLIK_IZIN',
        'state' => 'ONAYLANDI',
        'baslangic_tarihi' => '2026-08-10',
        'bitis_tarihi' => '2026-08-20',
    ],
];
$takvimFull = [];
for ($d = 10; $d <= 20; $d++) {
    $key = sprintf('2026-08-%02d', $d);
    $takvimFull[$key] = 'Normal_Is_Gunu';
}
$mid = YillikIzinKullanimService::classifyFromSurecler($surecLeave, $takvimFull, '2026-08-15');
yihAssert($mid['kullanilan_gun'] === 6, 'used as-of mid-interval counts through 15th (6 days)');

$beforeStart = YillikIzinKullanimService::classifyFromSurecler($surecLeave, $takvimFull, '2026-08-09');
yihAssert($beforeStart['kullanilan_gun'] === 0, 'used as-of before start = 0');

$afterEnd = YillikIzinKullanimService::classifyFromSurecler($surecLeave, $takvimFull, '2026-08-21');
yihAssert($afterEnd['kullanilan_gun'] === 11, 'used as-of after end = full 11');

// Future days beyond as-of must not fail-closed when missing calendar
$partialTakvim = [
    '2026-08-10' => 'Normal_Is_Gunu',
    '2026-08-11' => 'Normal_Is_Gunu',
    '2026-08-12' => 'Normal_Is_Gunu',
    '2026-08-13' => 'Normal_Is_Gunu',
    '2026-08-14' => 'Normal_Is_Gunu',
    '2026-08-15' => 'Normal_Is_Gunu',
    // 16-20 intentionally missing — beyond as-of
];
$asOfOk = YillikIzinKullanimService::classifyFromSurecler($surecLeave, $partialTakvim, '2026-08-15');
yihAssert($asOfOk['kullanilan_gun'] === 6 && $asOfOk['takvim_dogrulandi_mi'] === true, 'future missing calendar ignored as-of');

// --- kullanim classify (pure, no as-of) ---
$ozet = YillikIzinKullanimService::classifyFromSurecler(
    [
        [
            'surec_turu' => 'IZIN',
            'alt_tur' => 'YILLIK_IZIN',
            'state' => 'ONAYLANDI',
            'baslangic_tarihi' => '2026-06-01',
            'bitis_tarihi' => '2026-06-03',
        ],
    ],
    [
        '2026-06-01' => 'Normal_Is_Gunu',
        '2026-06-02' => 'Hafta_Tatili_Pazar',
        '2026-06-03' => 'Normal_Is_Gunu',
    ]
);
yihAssert($ozet['kullanilan_gun'] === 2, 'used counts normal only');
yihAssert($ozet['haric_tutulan_hafta_tatili_gun'] === 1, 'HT excluded');

$fail = YillikIzinKullanimService::classifyFromSurecler(
    [
        [
            'surec_turu' => 'IZIN',
            'alt_tur' => 'YILLIK_IZIN',
            'state' => 'ONAYLANDI',
            'baslangic_tarihi' => '2026-06-01',
            'bitis_tarihi' => '2026-06-02',
        ],
    ],
    ['2026-06-01' => 'Normal_Is_Gunu']
);
yihAssert($fail['kullanilan_gun'] === null && $fail['takvim_dogrulandi_mi'] === false, 'fail-closed missing day');

// --- bakiye clamp D3 with cumulative ---
$bal = YillikIzinBakiyeService::assembleFromParts(
    [
        'kidem_yil' => 3,
        'yas' => 30,
        'mevcut_yillik_hak_gun' => 14,
        'birikmis_yasal_hak_gun' => 42,
        'yas_istisna_uygulandi' => false,
    ],
    8,
    ['kullanilan_gun' => 5]
);
yihAssert($bal['raw_remaining'] === 45 && $bal['remaining'] === 45, 'bakiye cumulative+manual-used');
yihAssert($bal['yasal_hak_gun'] === 42, 'yasal_hak_gun alias = cumulative');

$neg = YillikIzinBakiyeService::assembleFromParts(
    [
        'kidem_yil' => 3,
        'yas' => 30,
        'mevcut_yillik_hak_gun' => 14,
        'birikmis_yasal_hak_gun' => 42,
        'yas_istisna_uygulandi' => false,
    ],
    0,
    ['kullanilan_gun' => 50]
);
yihAssert($neg['raw_remaining'] === -8 && $neg['remaining'] === 0, 'remaining clamp max(raw,0)');

$unres = YillikIzinBakiyeService::assembleFromParts(
    [
        'kidem_yil' => 3,
        'yas' => 30,
        'mevcut_yillik_hak_gun' => 14,
        'birikmis_yasal_hak_gun' => 42,
        'yas_istisna_uygulandi' => false,
    ],
    5,
    ['kullanilan_gun' => null]
);
yihAssert($unres['remaining'] === null && $unres['raw_remaining'] === null, 'unresolved used → null remaining');

// --- INVALID REFERENCE DATE ---
try {
    YillikIzinBakiyeService::resolveReferansTarih('not-a-date');
    yihAssert(false, 'invalid referans should throw');
} catch (YillikIzinHakDuzeltmeException $e) {
    yihAssert($e->getErrorCode() === 'VALIDATION_ERROR' && $e->getHttpStatus() === 422, 'invalid referans → 422');
}
try {
    YillikIzinBakiyeService::resolveReferansTarih('2026-13-40');
    yihAssert(false, 'impossible date should throw');
} catch (YillikIzinHakDuzeltmeException $e) {
    yihAssert($e->getErrorCode() === 'VALIDATION_ERROR', 'impossible date rejected');
}
$todayResolved = YillikIzinBakiyeService::resolveReferansTarih(null);
yihAssert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $todayResolved) === 1, 'absent referans → today');
yihAssert(YillikIzinBakiyeService::resolveReferansTarih('2025-12-31') === '2025-12-31', 'explicit valid referans kept');

// --- HISTORICAL AS-OF integrated snapshot (pure parts) ---
// hire 2020-01-01; at T1=2023-12-31 kidem=3 cumulative=42; T2=2024-01-01 kidem=4 cum=56; T3=2025-01-01 kidem=5 cum=70
$t1Legal = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2020-01-01',
    'referans_tarih' => '2023-12-31',
]);
$t2Legal = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2020-01-01',
    'referans_tarih' => '2024-01-01',
]);
$t3Legal = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2020-01-01',
    'referans_tarih' => '2025-01-01',
]);
yihAssert($t1Legal['birikmis_yasal_hak_gun'] === 42, 'HISTORICAL T1 cumulative=42');
yihAssert($t2Legal['birikmis_yasal_hak_gun'] === 56, 'HISTORICAL T2 cumulative=56');
yihAssert($t3Legal['birikmis_yasal_hak_gun'] === 70, 'HISTORICAL T3 cumulative=70');

// Past manual +4 (2023-06-01), future manual +5 (2024-06-01)
// Past used 10, future used ignored at T1
$t1Bal = YillikIzinBakiyeService::assembleFromParts($t1Legal, 4, ['kullanilan_gun' => 10], 1, '2023-12-31');
$t2Bal = YillikIzinBakiyeService::assembleFromParts($t2Legal, 4, ['kullanilan_gun' => 10], 1, '2024-01-01');
$t3Bal = YillikIzinBakiyeService::assembleFromParts($t3Legal, 9, ['kullanilan_gun' => 15], 2, '2025-01-01');
yihAssert($t1Bal['remaining'] === 36, 'HISTORICAL_AS_OF T1 remaining=36');
yihAssert($t2Bal['remaining'] === 50, 'HISTORICAL_AS_OF T2 remaining=50');
yihAssert($t3Bal['remaining'] === 64, 'HISTORICAL_AS_OF T3 remaining=64');
yihAssert($t1Bal['remaining'] <= $t2Bal['remaining'] && $t2Bal['remaining'] <= $t3Bal['remaining'], 'HISTORICAL_AS_OF_REGRESSION monotonic remaining');

echo "YillikIzinHakEdisPureTestRunner OK\n";
