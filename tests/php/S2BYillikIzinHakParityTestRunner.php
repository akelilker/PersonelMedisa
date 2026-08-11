<?php

declare(strict_types=1);

/**
 * S2B: FE-aligned legal entitlement parity (pure PHP, no DB).
 * php tests/php/S2BYillikIzinHakParityTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Izin\YillikIzinHakEdisService;

function s2bParityAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$name}\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] {$name}\n");
}

$ref = '2026-04-13';

// less than 1 year → 0
$lt1 = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2026-01-01',
    'referans_tarih' => $ref,
]);
s2bParityAssert($lt1['kidem_yil'] === 0 && $lt1['yillik_izin_gun'] === 0, 'less than 1 year → 0');

// exact 1 year → 14 (1–5 band)
$exact1 = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2025-04-13',
    'referans_tarih' => $ref,
]);
s2bParityAssert($exact1['kidem_yil'] === 1 && $exact1['yillik_izin_gun'] === 14, 'exact 1 year → 14');

// 5y boundary quirk: anniversary → 14; +1 day → 20
$g5 = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2021-04-13',
    'referans_tarih' => $ref,
]);
$g5p = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2021-04-13',
    'referans_tarih' => '2026-04-14',
]);
s2bParityAssert($g5['gun'] === 14 && $g5p['gun'] === 20, '5y boundary 14 vs 20');

// over 5 (<15) → 20
$over5 = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2019-01-01',
    'referans_tarih' => $ref,
]);
s2bParityAssert($over5['kidem_yil'] === 7 && $over5['yillik_izin_gun'] === 20, 'over 5 years → 20');

// 15y boundary: day before 20, anniversary 26
$g14 = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2011-04-13',
    'referans_tarih' => '2026-04-12',
]);
$g15 = YillikIzinHakEdisService::hesaplaYillikIzinGun([
    'ise_giris_tarihi' => '2011-04-13',
    'referans_tarih' => $ref,
]);
s2bParityAssert($g14['gun'] === 20 && $g15['gun'] === 26, '15y boundary 20 vs 26');

// age >= 50 → min 20
$age50 = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2024-01-01',
    'dogum_tarihi' => '1976-01-01',
    'referans_tarih' => $ref,
]);
s2bParityAssert(
    $age50['yas'] === 50 && $age50['yillik_izin_gun'] === 20 && $age50['yas_istisna_uygulandi'] === true,
    'age >= 50 → min 20'
);

// age <= 18 → min 20
$age18 = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2024-01-01',
    'dogum_tarihi' => '2008-04-13',
    'referans_tarih' => $ref,
]);
s2bParityAssert(
    $age18['yas'] === 18 && $age18['yillik_izin_gun'] === 20 && $age18['yas_istisna_uygulandi'] === true,
    'age <= 18 → min 20'
);

// missing DOB → band-only (no age exception)
$noDob = YillikIzinHakEdisService::hesaplaIzinHakEdis([
    'ise_giris_tarihi' => '2023-01-01',
    'referans_tarih' => $ref,
]);
s2bParityAssert(
    $noDob['yas'] === null && $noDob['yillik_izin_gun'] === 14 && $noDob['yas_istisna_uygulandi'] === false,
    'missing DOB → band-only 14'
);

// S2C cumulative accrual parity anchors
$cum5 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2021-04-13',
    'referans_tarih' => $ref,
]);
s2bParityAssert(
    $cum5['birikmis_yasal_hak_gun'] === 70 && $cum5['mevcut_yillik_hak_gun'] === 14,
    'cumulative exact 5y = 70 / band 14'
);

$cum15 = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
    'ise_giris_tarihi' => '2011-04-13',
    'referans_tarih' => $ref,
]);
s2bParityAssert(
    $cum15['birikmis_yasal_hak_gun'] === 276 && $cum15['mevcut_yillik_hak_gun'] === 26,
    'cumulative exact 15y = 276 / band 26'
);

fwrite(STDOUT, "S2B/S2C legal parity OK\n");
