<?php

declare(strict_types=1);

/**
 * S2B: YillikIzinHakEdisService FE parity (no MariaDB).
 * php tests/php/YillikIzinHakEdisPureTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Izin\YillikIzinBakiyeService;
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

// --- yillik gun + 5y boundary quirk ---
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

// --- hak edis integrate ---
$h = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2023-01-01',
    'dogum_tarihi' => '1996-04-13',
    'referans_tarih' => '2026-04-13',
]);
yihAssert($h['kidem_yil'] === 3 && $h['yas'] === 30 && $h['yillik_izin_gun'] === 14, 'hak edis 14');

$h7 = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2019-01-01',
    'referans_tarih' => '2026-04-13',
]);
yihAssert($h7['yillik_izin_gun'] === 20, 'hak edis 20');

$h20 = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2006-01-01',
    'referans_tarih' => '2026-04-13',
]);
yihAssert($h20['yillik_izin_gun'] === 26, 'hak edis 26');

// --- kullanim classify (pure) ---
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

// --- bakiye clamp D3 ---
$bal = YillikIzinBakiyeService::assembleFromParts(
    ['kidem_yil' => 3, 'yas' => 30, 'yillik_izin_gun' => 14, 'yas_istisna_uygulandi' => false],
    8,
    ['kullanilan_gun' => 5]
);
yihAssert($bal['raw_remaining'] === 17 && $bal['remaining'] === 17, 'bakiye legal+manual-used');
yihAssert($bal['legal_entitlement_semantic'] === 'CURRENT_SERVICE_YEAR_BAND', 'semantic band');

$neg = YillikIzinBakiyeService::assembleFromParts(
    ['kidem_yil' => 3, 'yas' => 30, 'yillik_izin_gun' => 14, 'yas_istisna_uygulandi' => false],
    0,
    ['kullanilan_gun' => 20]
);
yihAssert($neg['raw_remaining'] === -6 && $neg['remaining'] === 0, 'remaining clamp max(raw,0)');

$unres = YillikIzinBakiyeService::assembleFromParts(
    ['kidem_yil' => 3, 'yas' => 30, 'yillik_izin_gun' => 14, 'yas_istisna_uygulandi' => false],
    5,
    ['kullanilan_gun' => null]
);
yihAssert($unres['remaining'] === null && $unres['raw_remaining'] === null, 'unresolved used → null remaining');

echo "YillikIzinHakEdisPureTestRunner OK\n";
