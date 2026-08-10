<?php

declare(strict_types=1);

/**
 * Phase A adjacent check: authoritative tatil_donemi_* is inside attendance source fingerprint.
 * php tests/php/TatilDonemiSourceHashTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\MaasHesaplamaSnapshotService;
use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;

function tatilHashAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function baseAttendanceRow(array $overrides = []): array
{
    return array_replace([
        'id' => 11,
        'muhur_id' => 1,
        'personel_id' => 7,
        'tarih' => '2026-03-03',
        'gun_tipi' => 'UBGT_Resmi_Tatil',
        'hareket_durumu' => 'Geldi',
        'dayanak' => null,
        'durumu_bildirdi_mi' => 1,
        'durum_bildirim_aciklamasi' => null,
        'hesap_etkisi' => 'Mesai_Yaz',
        'sgk_eksik_gun_neden_tipi' => null,
        'beklenen_giris_saati' => '08:00:00',
        'beklenen_cikis_saati' => '16:45:00',
        'giris_saati' => '13:00:00',
        'cikis_saati' => '15:00:00',
        'gec_kalma_dakika' => null,
        'erken_cikis_dakika' => null,
        'gercek_mola_dakika' => 0,
        'hesaplanan_mola_dakika' => 0,
        'net_calisma_suresi_dakika' => 120,
        'gunluk_brut_sure_dakika' => 120,
        'hafta_tatili_hak_kazandi_mi' => null,
        'kontrol_durumu' => 'BEKLIYOR',
        'kaynak' => 'MANUEL',
        'aciklama' => null,
        'tatil_takvim_id' => 5,
        'tatil_turu' => 'UBGT',
        'tatil_gun_kapsami' => 'YARIM_GUN',
        'tatil_interval_baslangic' => '13:00:00',
        'tatil_interval_bitis' => '16:45:00',
        'tatil_siniflandirma_durumu' => 'DOGRULANDI',
        'tatil_snapshot_hash' => str_repeat('b', 64),
        'tatil_kaynak_referansi' => 'RG-TEST',
        'tatil_donemi_brut_calisma_dakika' => 120,
        'tatil_donemi_ara_dinlenme_dakika' => 0,
        'tatil_donemi_net_calisma_dakika' => 120,
        'created_at' => '2026-03-03 10:00:00',
    ], $overrides);
}

$payloadA = MaasHesaplamaSnapshotService::attendancePayload(baseAttendanceRow([
    'tatil_donemi_net_calisma_dakika' => 0,
]));
$payloadB = MaasHesaplamaSnapshotService::attendancePayload(baseAttendanceRow([
    'tatil_donemi_net_calisma_dakika' => 120,
]));
$payloadC = MaasHesaplamaSnapshotService::attendancePayload(baseAttendanceRow([
    'tatil_donemi_brut_calisma_dakika' => 180,
    'tatil_donemi_ara_dinlenme_dakika' => 15,
    'tatil_donemi_net_calisma_dakika' => 165,
]));

tatilHashAssert(
    array_key_exists('tatil_donemi_brut_calisma_dakika', $payloadA)
        && array_key_exists('tatil_donemi_ara_dinlenme_dakika', $payloadA)
        && array_key_exists('tatil_donemi_net_calisma_dakika', $payloadA),
    'attendancePayload includes tatil_donemi_* fields'
);

$hashA = MaasHesaplamaEngine::hashCanonical([$payloadA]);
$hashB = MaasHesaplamaEngine::hashCanonical([$payloadB]);
$hashC = MaasHesaplamaEngine::hashCanonical([$payloadC]);

tatilHashAssert($hashA !== $hashB, 'net minute change alters puantaj source fingerprint');
tatilHashAssert($hashB !== $hashC, 'brut/mola/net change alters puantaj source fingerprint');

// Mutable calendar metadata change alone (snapshot hash) is separate from work minutes;
// work minutes remain in attendance canonical payload used by source_hash.
$metaOnly = MaasHesaplamaSnapshotService::attendancePayload(baseAttendanceRow([
    'tatil_snapshot_hash' => str_repeat('c', 64),
    'tatil_donemi_net_calisma_dakika' => 120,
]));
$hashMeta = MaasHesaplamaEngine::hashCanonical([$metaOnly]);
tatilHashAssert($hashMeta !== $hashB, 'calendar snapshot metadata also in attendance payload');

echo "ALL_TATIL_DONEMI_SOURCE_HASH_TESTS_PASSED\n";
