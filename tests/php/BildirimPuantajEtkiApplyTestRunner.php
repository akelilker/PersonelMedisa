<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiDecisionPolicy.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiProjectionService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiApplyService.php';

use Medisa\Api\Services\BildirimPuantajEtkiApplyService;
use Medisa\Api\Services\BildirimPuantajEtkiDecisionPolicy;

function applyBaseAday(array $overrides = []): array
{
    return array_merge([
        'id' => 11,
        'personel_id' => 7,
        'sube_id' => 1,
        'tarih' => '2026-06-04',
        'state' => 'HAZIR',
        'etki_turu' => 'GEC_KALMA_DAKIKA',
        'etki_miktari' => 15,
        'etki_birimi' => 'DAKIKA',
        'conflict_code' => null,
        'bildirim_aciklama' => 'Gec geldi',
        'ucretli_mi_snapshot' => null,
        'resmi_surec_turu' => null,
        'resmi_surec_alt_tur' => null,
    ], $overrides);
}

$scenarios = [
    ['name' => 'HAZIR apply allowed', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isApplyAllowed('HAZIR') === true;
    }],
    ['name' => 'INCELEME_GEREKLI apply blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isApplyAllowed('INCELEME_GEREKLI') === false;
    }],
    ['name' => 'UYGULANDI apply blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isApplyAllowed('UYGULANDI') === false;
    }],
    ['name' => 'YOK_SAYILDI apply blocked', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::isApplyAllowed('YOK_SAYILDI') === false;
    }],
    ['name' => 'target state UYGULANDI', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::targetStateForAction(
            BildirimPuantajEtkiDecisionPolicy::ACTION_APPLY
        ) === 'UYGULANDI';
    }],
    ['name' => 'apply permission constant', 'fn' => function () {
        return BildirimPuantajEtkiDecisionPolicy::permissionForAction(
            BildirimPuantajEtkiDecisionPolicy::ACTION_APPLY
        ) === 'puantaj.bildirim_etki.apply';
    }],
    ['name' => 'DEVAMSIZLIK mapping', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_turu' => 'DEVAMSIZLIK_GUN',
            'etki_miktari' => 1,
            'etki_birimi' => 'GUN',
        ]));
        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        $v = $result['values'];

        return $v['hareket_durumu'] === 'Gelmedi'
            && $v['dayanak'] === 'Yok_Izinsiz'
            && $v['hesap_etkisi'] === 'Yevmiye_Kes'
            && $v['gec_kalma_dakika'] === null
            && $v['erken_cikis_dakika'] === null;
    }],
    ['name' => 'GEC dakika mapping authoritative', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_turu' => 'GEC_KALMA_DAKIKA',
            'etki_miktari' => 17,
        ]));
        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        $v = $result['values'];

        return $v['hareket_durumu'] === 'Gec_Geldi'
            && $v['gec_kalma_dakika'] === 17
            && $v['erken_cikis_dakika'] === null
            && $v['giris_saati'] === null;
    }],
    ['name' => 'ERKEN dakika mapping authoritative', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_turu' => 'ERKEN_CIKIS_DAKIKA',
            'etki_miktari' => 22,
        ]));
        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        $v = $result['values'];

        return $v['hareket_durumu'] === 'Erken_Cikti'
            && $v['erken_cikis_dakika'] === 22
            && $v['gec_kalma_dakika'] === null;
    }],
    ['name' => 'UCRETLI IZIN mapping', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_turu' => 'IZIN_GUNU',
            'etki_miktari' => 1,
            'etki_birimi' => 'GUN',
            'ucretli_mi_snapshot' => 1,
            'resmi_surec_alt_tur' => 'MAZERET',
        ]));
        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        $v = $result['values'];

        return $v['hareket_durumu'] === 'Gelmedi'
            && $v['dayanak'] === 'Ucretli_Izinli'
            && $v['hesap_etkisi'] === 'Ucretli_Izin';
    }],
    ['name' => 'YILLIK IZIN mapping', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_turu' => 'IZIN_GUNU',
            'etki_miktari' => 1,
            'etki_birimi' => 'GUN',
            'ucretli_mi_snapshot' => 1,
            'resmi_surec_alt_tur' => 'YILLIK_IZIN',
        ]));
        if (($result['ok'] ?? false) !== true) {
            return false;
        }

        return $result['values']['dayanak'] === 'Yillik_Izin';
    }],
    ['name' => 'UCRETSIZ IZIN blocked', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_turu' => 'IZIN_GUNU',
            'ucretli_mi_snapshot' => 0,
            'conflict_code' => 'UCRETSIZ_IZIN_MANUEL_INCELEME',
        ]));

        return ($result['ok'] ?? true) === false
            && ($result['code'] ?? '') === 'APPLY_UNSUPPORTED';
    }],
    ['name' => 'RAPOR hastalik mapping', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_turu' => 'RAPOR_GUNU',
            'etki_miktari' => 1,
            'etki_birimi' => 'GUN',
            'resmi_surec_turu' => 'RAPOR',
            'resmi_surec_alt_tur' => 'Raporlu_Hastalik',
        ]));
        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        $v = $result['values'];

        return $v['dayanak'] === 'Raporlu_Hastalik' && $v['hesap_etkisi'] === 'Raporlu';
    }],
    ['name' => 'GOREVDE mapping', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_turu' => 'GOREVDE_CALISILMIS_GUN',
            'etki_miktari' => 1,
            'etki_birimi' => 'GUN',
        ]));
        if (($result['ok'] ?? false) !== true) {
            return false;
        }
        $v = $result['values'];

        return $v['hareket_durumu'] === 'Geldi'
            && $v['dayanak'] === 'Gorevde_Calisma'
            && $v['hesap_etkisi'] === 'Tam_Yevmiye_Ver';
    }],
    ['name' => 'Pazar gun tipi', 'fn' => function () {
        return BildirimPuantajEtkiApplyService::resolveGunTipi('2026-06-07') === 'Hafta_Tatili_Pazar';
    }],
    ['name' => 'Hafta ici gun tipi null', 'fn' => function () {
        return BildirimPuantajEtkiApplyService::resolveGunTipi('2026-06-04') === null;
    }],
    ['name' => 'hash deterministic', 'fn' => function () {
        $aday = applyBaseAday();
        $row = [
            'id' => 42,
            'personel_id' => 7,
            'tarih' => '2026-06-04',
            'state' => 'ACIK',
            'gun_tipi' => null,
            'hareket_durumu' => 'Gec_Geldi',
            'dayanak' => 'Yok_Izinsiz',
            'durumu_bildirdi_mi' => 1,
            'durum_bildirim_aciklamasi' => 'Gec geldi',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'beklenen_giris_saati' => null,
            'beklenen_cikis_saati' => null,
            'giris_saati' => null,
            'cikis_saati' => null,
            'gec_kalma_dakika' => 15,
            'erken_cikis_dakika' => null,
            'gercek_mola_dakika' => null,
            'hesaplanan_mola_dakika' => null,
            'net_calisma_suresi_dakika' => null,
            'gunluk_brut_sure_dakika' => null,
            'hafta_tatili_hak_kazandi_mi' => null,
            'kontrol_durumu' => 'BEKLIYOR',
            'kaynak' => BildirimPuantajEtkiApplyService::KAYNAK,
            'aciklama' => null,
            'muhur_id' => null,
        ];
        $snap = BildirimPuantajEtkiApplyService::buildApplySnapshot(11, $row);
        $h1 = BildirimPuantajEtkiApplyService::computeUygulamaHash($aday, $snap);
        $h2 = BildirimPuantajEtkiApplyService::computeUygulamaHash($aday, $snap);

        return $h1 === $h2 && strlen($h1) === 64;
    }],
    ['name' => 'snapshot schema version', 'fn' => function () {
        $snap = BildirimPuantajEtkiApplyService::buildApplySnapshot(5, null);

        return ($snap['schema_version'] ?? '') === BildirimPuantajEtkiApplyService::SNAPSHOT_SCHEMA_VERSION
            && ($snap['aday_id'] ?? null) === 5
            && array_key_exists('puantaj', $snap)
            && $snap['puantaj'] === null;
    }],
    ['name' => 'GEC dakika missing blocked', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday([
            'etki_miktari' => null,
        ]));

        return ($result['ok'] ?? true) === false;
    }],
    ['name' => 'kaynak BILDIRIM_ETKI_ADAYI', 'fn' => function () {
        $result = BildirimPuantajEtkiApplyService::buildPuantajValuesFromAday(applyBaseAday());

        return ($result['values']['kaynak'] ?? '') === 'BILDIRIM_ETKI_ADAYI';
    }],
];

$passed = 0;
$failed = 0;
$failures = [];

foreach ($scenarios as $scenario) {
    $ok = (bool) ($scenario['fn'])();
    if ($ok) {
        $passed++;
        echo '[PASS] ' . $scenario['name'] . PHP_EOL;
    } else {
        $failed++;
        $failures[] = $scenario['name'];
        echo '[FAIL] ' . $scenario['name'] . PHP_EOL;
    }
}

echo 'Summary: PASS=' . $passed . ' FAIL=' . $failed . PHP_EOL;
if ($failed > 0) {
    fwrite(STDERR, 'Failures: ' . implode(', ', $failures) . PHP_EOL);
    exit(1);
}

echo 'verify-bildirim-puantaj-etki-apply: OK' . PHP_EOL;
