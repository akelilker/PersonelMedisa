<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use PDO;

/**
 * Manager inbox for self-service leave/advance requests (deep-link payload owner).
 */
class SelfRequestInboxNotifier
{
    /**
     * @param array<string, mixed> $ctx Self personel context (birim_id, ad_soyad, personel_id)
     */
    public static function notifyIzinRequest(PDO $pdo, array $ctx, $surecId, array $surecRow)
    {
        $approverId = self::resolveApproverUserId($pdo, $ctx);
        if ($approverId === null) {
            return;
        }
        $personelName = (string) ($ctx['ad_soyad'] ?? 'Personel');
        $bas = (string) ($surecRow['baslangic_tarihi'] ?? '');
        $bit = (string) ($surecRow['bitis_tarihi'] ?? $bas);
        $body = sprintf('%s, %s – %s arasi izin talebi gonderdi.', $personelName, $bas, $bit);
        if (getenv('MEDISA_TEST_SELF_NOTIFY_THROW') === '1') {
            throw new \RuntimeException('MEDISA_TEST_SELF_NOTIFY_THROW');
        }
        PersonelInboxNotificationService::createSelfServiceRequest(
            $pdo,
            $approverId,
            'SELF_IZIN_REQUEST',
            'Izin Talebi',
            $body,
            (int) $ctx['personel_id'],
            [
                'entity_type' => 'IZIN',
                'entity_id' => (int) $surecId,
                'surec_id' => (int) $surecId,
                'personel_id' => (int) $ctx['personel_id'],
            ],
            true
        );
    }

    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $avansRow
     */
    /**
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $raporRow
     */
    public static function notifyRaporRequest(PDO $pdo, array $ctx, array $raporRow)
    {
        $approverId = self::resolveApproverUserId($pdo, $ctx);
        if ($approverId === null) {
            return;
        }
        $personelName = (string) ($ctx['ad_soyad'] ?? 'Personel');
        $bas = (string) ($raporRow['baslangic_tarihi'] ?? '');
        $bit = (string) ($raporRow['bitis_tarihi'] ?? $bas);
        $body = sprintf('%s, %s – %s arasi saglik raporu bildirdi.', $personelName, $bas, $bit);
        if (getenv('MEDISA_TEST_SELF_NOTIFY_THROW') === '1') {
            throw new \RuntimeException('MEDISA_TEST_SELF_NOTIFY_THROW');
        }
        PersonelInboxNotificationService::createSelfServiceRequest(
            $pdo,
            $approverId,
            'SELF_RAPOR_REQUEST',
            'Saglik Raporu',
            $body,
            (int) $ctx['personel_id'],
            [
                'entity_type' => 'RAPOR',
                'entity_id' => (int) ($raporRow['id'] ?? 0),
                'surec_id' => (int) ($raporRow['id'] ?? 0),
                'personel_id' => (int) $ctx['personel_id'],
            ],
            true
        );
    }

    public static function notifyAvansRequest(PDO $pdo, array $ctx, array $avansRow)
    {
        $approverId = self::resolveApproverUserId($pdo, $ctx);
        if ($approverId === null) {
            return;
        }
        $personelName = (string) ($ctx['ad_soyad'] ?? 'Personel');
        $body = sprintf(
            '%s, %s tarihli %s TL avans talebi gonderdi.',
            $personelName,
            (string) ($avansRow['talep_tarihi'] ?? ''),
            (string) ($avansRow['tutar'] ?? '')
        );
        if (getenv('MEDISA_TEST_SELF_NOTIFY_THROW') === '1') {
            throw new \RuntimeException('MEDISA_TEST_SELF_NOTIFY_THROW');
        }
        PersonelInboxNotificationService::createSelfServiceRequest(
            $pdo,
            $approverId,
            'SELF_AVANS_REQUEST',
            'Avans Talebi',
            $body,
            (int) $ctx['personel_id'],
            [
                'entity_type' => 'AVANS',
                'entity_id' => (int) ($avansRow['id'] ?? 0),
                'personel_id' => (int) $ctx['personel_id'],
            ],
            true
        );
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private static function resolveApproverUserId(PDO $pdo, array $ctx)
    {
        $birimId = isset($ctx['birim_id']) ? (int) $ctx['birim_id'] : 0;
        if ($birimId <= 0) {
            return null;
        }

        return SelfPuantajReadService::resolveBirimAmiriUserIdForPersonelScope($pdo, $birimId);
    }
}
