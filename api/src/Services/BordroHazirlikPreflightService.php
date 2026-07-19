<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Services\Payroll\MaasHesaplamaLegalParameterCatalog;
use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;
use PDO;

/**
 * S82 actionable bordro hazirlik preflight owner'i.
 */
class BordroHazirlikPreflightService
{
    /** @return array<string, mixed> */
    public static function build(PDO $pdo, $subeId, $yil, $ay)
    {
        $snapshotPreflight = MaasHesaplamaSnapshotService::buildPreflight($pdo, (int) $subeId, (int) $yil, (int) $ay);
        $items = self::enrichItems($snapshotPreflight['items'] ?? []);
        $existingSnapshot = $snapshotPreflight['existing_snapshot'] ?? null;
        $calcPreflight = null;
        if ($existingSnapshot && isset($existingSnapshot['id'])) {
            $calcPreflight = MaasHesaplamaAdayService::buildCalculationPreflight($pdo, (int) $existingSnapshot['id']);
            $items = array_merge($items, self::enrichItems($calcPreflight['items'] ?? []));
        }

        $donemBaslangic = (string) $snapshotPreflight['donem_baslangic'];
        $donemBitis = (string) $snapshotPreflight['donem_bitis'];
        $policy = SirketCalismaPolitikasiService::resolveApprovedForPeriod($pdo, $donemBaslangic, $donemBitis);
        if ($policy['politika'] === null) {
            $items[] = self::actionableIssue(
                'BLOCKER',
                'BUSINESS_POLICY_REQUIRED',
                'Onaylı şirket çalışma politikası bulunamadı.',
                'sirket_politikasi',
                null,
                null,
                [],
                '/raporlar?panel=bordro-hazirlik&tab=politika'
            );
        } else {
            $missingPolicy = [];
            foreach (SirketCalismaPolitikasiCatalog::requiredCodes() as $code) {
                if (!isset($policy['degerler_by_code'][$code])) {
                    $missingPolicy[] = $code;
                }
            }
            if (count($missingPolicy) > 0) {
                $items[] = self::actionableIssue(
                    'BLOCKER',
                    'BUSINESS_POLICY_INCOMPLETE',
                    'Onaylı şirket politikasında eksik parametreler var.',
                    'sirket_politikasi',
                    (int) $policy['politika']['id'],
                    null,
                    ['eksik_kodlar' => $missingPolicy],
                    '/raporlar?panel=bordro-hazirlik&tab=politika'
                );
            } else {
                $items[] = self::actionableIssue(
                    'INFO',
                    'BUSINESS_POLICY_APPROVED',
                    'Onaylı şirket çalışma politikası mevcut.',
                    'sirket_politikasi',
                    (int) $policy['politika']['id'],
                    null,
                    ['policy_version_hash' => $policy['policy_version_hash']],
                    '/raporlar?panel=bordro-hazirlik&tab=politika'
                );
            }
        }

        $gyFinal = self::checkGenelYoneticiFinalApproval($pdo, (int) $subeId, (int) $yil, (int) $ay);
        if (!$gyFinal['tamam']) {
            $items[] = self::actionableIssue(
                'BLOCKER',
                'S81_GENEL_YONETICI_FINAL_ONAY_EKSIK',
                'Genel yönetici final onayı tamamlanmamış.',
                'onay_zinciri',
                null,
                null,
                $gyFinal,
                '/bildirimler'
            );
        } else {
            $items[] = self::actionableIssue(
                'INFO',
                'S81_GENEL_YONETICI_FINAL_ONAY_TAMAM',
                'Genel yönetici final onayı tamam.',
                'onay_zinciri',
                $gyFinal['genel_yonetici_bildirim_onayi_id'] ?? null,
                null,
                $gyFinal,
                '/bildirimler'
            );
        }

        $personelIds = array_map(static function (array $row) {
            return (int) $row['personel_id'];
        }, $snapshotPreflight['personel_summary'] ?? []);
        $projection = MaasHesaplamaCorrectionProjectionService::buildProjection(
            $pdo,
            (int) $subeId,
            $personelIds,
            $donemBaslangic,
            $donemBitis
        );
        foreach ($projection['blocker_items'] as $blocker) {
            $items[] = $blocker;
        }
        if ($projection['active_count'] > 0) {
            $items[] = self::actionableIssue(
                'INFO',
                'CORRECTION_PROJECTION_READY',
                'Aktif correction projection hazır.',
                'correction',
                null,
                null,
                ['adet' => $projection['active_count']],
                '/revizyon-merkezi'
            );
        }

        $blockerCount = self::countSeverity($items, 'BLOCKER');
        $warningCount = self::countSeverity($items, 'WARNING');
        $infoCount = self::countSeverity($items, 'INFO');

        return [
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'donem' => (string) $snapshotPreflight['donem'],
            'donem_baslangic' => $donemBaslangic,
            'donem_bitis' => $donemBitis,
            'hesaplanabilir_mi' => $blockerCount === 0,
            'blocker_count' => $blockerCount,
            'warning_count' => $warningCount,
            'info_count' => $infoCount,
            'items' => $items,
            'snapshot_preflight' => [
                'snapshot_olusturulabilir_mi' => (bool) ($snapshotPreflight['snapshot_olusturulabilir_mi'] ?? false),
                'existing_snapshot' => $existingSnapshot,
                'preflight_hash' => (string) ($snapshotPreflight['preflight_hash'] ?? ''),
            ],
            'calculation_preflight' => $calcPreflight ? MaasHesaplamaAdayService::publicPreflight($calcPreflight) : null,
            'policy_summary' => [
                'onayli_politika_id' => $policy['politika']['id'] ?? null,
                'policy_version_hash' => $policy['policy_version_hash'],
                'zorunlu_adet' => count(SirketCalismaPolitikasiCatalog::requiredCodes()),
            ],
            'correction_projection_hash' => $projection['projection_hash'],
            'mevzuat_kaynak' => 'mevzuat_parametreleri',
            'sirket_politikasi_kaynak' => 'sirket_calisma_politikalari',
            'contract_version' => 'S82_BORDRO_HAZIRLIK_PREFLIGHT_V1',
            'generated_at' => gmdate('c'),
        ];
    }

    /** @return array<string, mixed> */
    private static function checkGenelYoneticiFinalApproval(PDO $pdo, $subeId, $yil, $ay)
    {
        $donem = sprintf('%04d-%02d', (int) $yil, (int) $ay);
        try {
            $stmt = $pdo->prepare(
                "SELECT gy.id, gy.state, gy.onay_zamani
                 FROM genel_yonetici_bildirim_onaylari gy
                 WHERE gy.sube_id = :sube_id AND gy.ay = :ay AND gy.state = 'ONAYLANDI'
                 ORDER BY gy.onay_zamani DESC, gy.id DESC LIMIT 1"
            );
            $stmt->execute(['sube_id' => (int) $subeId, 'ay' => $donem]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return ['tamam' => false, 'neden' => 'ONAY_KAYDI_YOK'];
            }

            return [
                'tamam' => true,
                'genel_yonetici_bildirim_onayi_id' => (int) $row['id'],
                'onay_zamani' => (string) $row['onay_zamani'],
            ];
        } catch (\Throwable $e) {
            return ['tamam' => false, 'neden' => 'ONAY_TABLOSU_YOK'];
        }
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private static function enrichItems(array $items)
    {
        $out = [];
        foreach ($items as $item) {
            $code = (string) ($item['code'] ?? '');
            $item['action_link'] = self::actionLinkForCode($code, $item);
            $item['etkilenen_personel_sayisi'] = self::affectedPersonelCount($item);
            $item['etkilenen_kayit_sayisi'] = self::affectedRecordCount($item);
            $out[] = $item;
        }

        return $out;
    }

    private static function actionLinkForCode($code, array $item)
    {
        switch ($code) {
            case 'BUSINESS_POLICY_REQUIRED':
            case 'BUSINESS_POLICY_INCOMPLETE':
            case 'COMPANY_POLICY_MISSING':
                return '/raporlar?panel=bordro-hazirlik&tab=politika';
            case 'PERSONNEL_CARRYOVER_MISSING':
            case 'PERSONNEL_CARRYOVER_INVALID':
                return '/raporlar?panel=bordro-hazirlik&tab=devir';
            case 'SALARY_MISSING':
            case 'SALARY_SEGMENT_INVALID':
            case 'SALARY_TYPE_UNSUPPORTED':
            case 'LEGACY_SALARY_FALLBACK_USED':
                return '/personeller/' . (int) ($item['personel_id'] ?? 0) . '?tab=ucret';
            case 'LEGAL_PARAMETER_REQUIRED_MISSING':
            case 'LEGAL_PARAMETER_SET_EMPTY':
                return '/yonetim-paneli?tab=mevzuat';
            case 'PERIOD_NOT_SEALED':
            case 'PERIOD_SEAL_INVALID':
                return '/raporlar?panel=donem-kapanis';
            case 'CANDIDATE_PENDING':
            case 'ETKI_ADAY_PENDING':
                return '/raporlar?panel=etki-adayi';
            case 'S81_GENEL_YONETICI_FINAL_ONAY_EKSIK':
                return '/bildirimler';
            case 'CORRECTION_SOURCE_CONFLICT':
                return '/revizyon-merkezi';
            default:
                return '/raporlar?panel=bordro-hazirlik';
        }
    }

    private static function affectedPersonelCount(array $item)
    {
        if (isset($item['personel_id']) && $item['personel_id'] !== null) {
            return 1;
        }
        $meta = $item['metadata'] ?? [];

        return isset($meta['adet']) ? (int) $meta['adet'] : 0;
    }

    private static function affectedRecordCount(array $item)
    {
        return $item['record_id'] !== null ? 1 : 0;
    }

    /** @param array<string, mixed> $metadata */
    private static function actionableIssue(
        $severity,
        $code,
        $message,
        $recordType = null,
        $recordId = null,
        $personelId = null,
        array $metadata = [],
        $actionLink = null
    ) {
        return [
            'severity' => (string) $severity,
            'code' => (string) $code,
            'message' => (string) $message,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'personel_id' => $personelId,
            'personel_adi' => null,
            'metadata' => $metadata,
            'action_link' => $actionLink,
            'etkilenen_personel_sayisi' => $personelId !== null ? 1 : 0,
            'etkilenen_kayit_sayisi' => $recordId !== null ? 1 : 0,
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function countSeverity(array $items, $severity)
    {
        $n = 0;
        foreach ($items as $item) {
            if ((string) ($item['severity'] ?? '') === (string) $severity) {
                $n++;
            }
        }

        return $n;
    }
}
