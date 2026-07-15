<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiDecisionPolicy.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiProjectionService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiPuantajMapper.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiManualApplyService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiApplyService.php';

use Medisa\Api\Services\BildirimPuantajEtkiApplyService;
use Medisa\Api\Services\BildirimPuantajEtkiDecisionPolicy;
use Medisa\Api\Services\BildirimPuantajEtkiManualApplyService;
use Medisa\Api\Services\BildirimPuantajEtkiProjectionService;
use Medisa\Api\Services\BildirimPuantajEtkiPuantajMapper;

function manualBaseAday(array $overrides = []): array
{
    $snapshot = [
        'gunluk_bildirim_id' => 3,
        'bildirim_turu' => 'DIGER',
        'personel_id' => 1,
        'tarih' => '2026-07-15',
    ];

    return array_merge([
        'id' => 1,
        'personel_id' => 1,
        'sube_id' => 1,
        'tarih' => '2026-07-15',
        'state' => 'INCELEME_GEREKLI',
        'etki_turu' => 'MANUEL_INCELEME',
        'conflict_code' => 'DIGER_MANUEL_INCELEME',
        'bildirim_aciklama' => 'Operasyon notu',
        'source_snapshot' => $snapshot,
        'source_hash' => BildirimPuantajEtkiProjectionService::computeSourceHash($snapshot),
        'uygulama_modu' => 'OTOMATIK',
        'manuel_karar_turu' => null,
        'manuel_karar_miktari' => null,
    ], $overrides);
}

$scenarios = [
    ['name' => 'INCELEME manual apply allowed', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isManualApplyAllowed('INCELEME_GEREKLI') === true;
    }],
    ['name' => 'HAZIR manual apply blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isManualApplyAllowed('HAZIR') === false;
    }],
    ['name' => 'GOREVDE manual mapping', 'fn' => function () {
        $result = BildirimPuantajEtkiPuantajMapper::mapManualKararToFields('GOREVDE_CALISILMIS_GUN', null);
        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        $fields = $result['fields'];

        return $fields['hareket_durumu'] === 'Geldi'
            && $fields['dayanak'] === 'Gorevde_Calisma'
            && $fields['hesap_etkisi'] === 'Tam_Yevmiye_Ver';
    }],
    ['name' => 'DEVAMSIZLIK manual mapping', 'fn' => function () {
        $result = BildirimPuantajEtkiPuantajMapper::mapManualKararToFields('DEVAMSIZLIK_GUN', null);

        return ($result['ok'] ?? false) === true
            && $result['fields']['hareket_durumu'] === 'Gelmedi'
            && $result['fields']['hesap_etkisi'] === 'Yevmiye_Kes';
    }],
    ['name' => 'GEC manual mapping with dakika', 'fn' => function () {
        $result = BildirimPuantajEtkiPuantajMapper::mapManualKararToFields('GEC_KALMA_DAKIKA', 12);

        return ($result['ok'] ?? false) === true && $result['fields']['gec_kalma_dakika'] === 12;
    }],
    ['name' => 'ERKEN manual mapping with dakika', 'fn' => function () {
        $result = BildirimPuantajEtkiPuantajMapper::mapManualKararToFields('ERKEN_CIKIS_DAKIKA', 8);

        return ($result['ok'] ?? false) === true && $result['fields']['erken_cikis_dakika'] === 8;
    }],
    ['name' => 'GEC without dakika rejected', 'fn' => function () {
        $result = BildirimPuantajEtkiPuantajMapper::mapManualKararToFields('GEC_KALMA_DAKIKA', null);

        return ($result['ok'] ?? false) === false;
    }],
    ['name' => 'GOREVDE with miktar rejected', 'fn' => function () {
        $result = BildirimPuantajEtkiPuantajMapper::mapManualKararToFields('GOREVDE_CALISILMIS_GUN', 1);

        return ($result['ok'] ?? false) === false;
    }],
    ['name' => 'unsupported manual karar rejected', 'fn' => function () {
        $result = BildirimPuantajEtkiPuantajMapper::mapManualKararToFields('IZIN_GUNU', null);

        return ($result['ok'] ?? false) === false;
    }],
    ['name' => 'source integrity ok', 'fn' => function () {
        $aday = manualBaseAday();

        return BildirimPuantajEtkiManualApplyService::verifySourceIntegrity($aday)['ok'] === true;
    }],
    ['name' => 'source integrity failed', 'fn' => function () {
        $aday = manualBaseAday(['source_hash' => str_repeat('a', 64)]);

        return BildirimPuantajEtkiManualApplyService::verifySourceIntegrity($aday)['ok'] === false;
    }],
    ['name' => 'manual hash deterministic', 'fn' => function () {
        $aday = manualBaseAday();
        $puantaj = [
            'id' => 3,
            'personel_id' => 1,
            'tarih' => '2026-07-15',
            'state' => 'ACIK',
            'gun_tipi' => null,
            'hareket_durumu' => 'Geldi',
            'dayanak' => 'Gorevde_Calisma',
            'durumu_bildirdi_mi' => 1,
            'durum_bildirim_aciklamasi' => 'test',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'beklenen_giris_saati' => null,
            'beklenen_cikis_saati' => null,
            'giris_saati' => null,
            'cikis_saati' => null,
            'gec_kalma_dakika' => null,
            'erken_cikis_dakika' => null,
            'gercek_mola_dakika' => null,
            'hesaplanan_mola_dakika' => null,
            'net_calisma_suresi_dakika' => null,
            'gunluk_brut_sure_dakika' => null,
            'hafta_tatili_hak_kazandi_mi' => null,
            'kontrol_durumu' => 'BEKLIYOR',
            'kaynak' => 'BILDIRIM_ETKI_ADAYI',
            'aciklama' => null,
            'muhur_id' => null,
        ];
        $snapshot = BildirimPuantajEtkiManualApplyService::buildManualSnapshot(
            $aday,
            $puantaj,
            'GOREVDE_CALISILMIS_GUN',
            null,
            'Operasyon teyidi yapildi.'
        );
        $hash1 = BildirimPuantajEtkiManualApplyService::computeManualHash(
            $aday,
            $snapshot,
            'GOREVDE_CALISILMIS_GUN',
            null,
            'Operasyon teyidi yapildi.'
        );
        $hash2 = BildirimPuantajEtkiManualApplyService::computeManualHash(
            $aday,
            $snapshot,
            'GOREVDE_CALISILMIS_GUN',
            null,
            'Operasyon teyidi yapildi.'
        );

        return $hash1 === $hash2 && strlen($hash1) === 64;
    }],
    ['name' => 'manual snapshot schema version', 'fn' => function () {
        $aday = manualBaseAday();
        $snapshot = BildirimPuantajEtkiManualApplyService::buildManualSnapshot(
            $aday,
            ['id' => 1, 'personel_id' => 1, 'tarih' => '2026-07-15', 'state' => 'ACIK'],
            'GOREVDE_CALISILMIS_GUN',
            null,
            'test gerekce'
        );

        return ($snapshot['schema_version'] ?? '') === 'S74_MANUAL_APPLY_V1';
    }],
    ['name' => 'automatic hash schema unchanged', 'fn' => function () {
        $aday = [
            'id' => 11,
            'personel_id' => 7,
            'tarih' => '2026-06-04',
            'etki_turu' => 'GOREVDE_CALISILMIS_GUN',
            'etki_miktari' => 1,
            'etki_birimi' => 'GUN',
        ];
        $snapshot = BildirimPuantajEtkiApplyService::buildApplySnapshot(11, [
            'id' => 99,
            'personel_id' => 7,
            'tarih' => '2026-06-04',
            'state' => 'ACIK',
            'gun_tipi' => null,
            'hareket_durumu' => 'Geldi',
            'dayanak' => 'Gorevde_Calisma',
            'durumu_bildirdi_mi' => 1,
            'durum_bildirim_aciklamasi' => null,
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'beklenen_giris_saati' => null,
            'beklenen_cikis_saati' => null,
            'giris_saati' => null,
            'cikis_saati' => null,
            'gec_kalma_dakika' => null,
            'erken_cikis_dakika' => null,
            'gercek_mola_dakika' => null,
            'hesaplanan_mola_dakika' => null,
            'net_calisma_suresi_dakika' => null,
            'gunluk_brut_sure_dakika' => null,
            'hafta_tatili_hak_kazandi_mi' => null,
            'kontrol_durumu' => 'BEKLIYOR',
            'kaynak' => 'BILDIRIM_ETKI_ADAYI',
            'aciklama' => null,
            'muhur_id' => null,
        ]);

        return ($snapshot['schema_version'] ?? '') === 'S74_APPLY_V1';
    }],
    ['name' => 'Pazar gun tipi', 'fn' => function () {
        return BildirimPuantajEtkiPuantajMapper::resolveGunTipi('2026-07-12') === 'Hafta_Tatili_Pazar';
    }],
    ['name' => 'weekday gun tipi null', 'fn' => function () {
        return BildirimPuantajEtkiPuantajMapper::resolveGunTipi('2026-07-15') === null;
    }],
];

$failed = 0;
foreach ($scenarios as $scenario) {
    $ok = (bool) $scenario['fn']();
    if ($ok) {
        echo '[PASS] ' . $scenario['name'] . PHP_EOL;
        continue;
    }
    echo '[FAIL] ' . $scenario['name'] . PHP_EOL;
    $failed++;
}

if ($failed > 0) {
    echo 'verify-bildirim-puantaj-etki-manual-apply: FAILED (' . $failed . ')' . PHP_EOL;
    exit(1);
}

echo 'verify-bildirim-puantaj-etki-manual-apply: OK' . PHP_EOL;
