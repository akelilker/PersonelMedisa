<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use DateTime;
use PDO;

/**
 * Helpers used by PersonellerController for archive gating + markers.
 */
class PersonelArchiveGate
{
    /**
     * Users without arsiv.view must never see PASIF via aktiflik=pasif|tum.
     *
     * @param array<string, mixed> $user
     * @return string Effective aktiflik filter: aktif|pasif|tum
     */
    public static function effectiveListAktiflik(array $user, $requestedAktiflik)
    {
        $requested = strtolower(trim((string) $requestedAktiflik));
        if (!in_array($requested, ['aktif', 'pasif', 'tum'], true)) {
            $requested = 'tum';
        }

        if (!ArchiveAccessService::canAccessArchive($user)) {
            return 'aktif';
        }

        return $requested;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $personelRow
     */
    public static function assertDetailAccess(array $user, array $personelRow)
    {
        $aktifDurum = strtoupper(trim((string) ($personelRow['aktif_durum'] ?? '')));
        if ($aktifDurum === 'PASIF') {
            ArchiveAccessService::assertPasifAccess($user);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $items
     */
    public static function maybeWriteListAudit(PDO $pdo, array $user, array $items, $routeSource)
    {
        if (!ArchiveAccessService::canAccessArchive($user)) {
            return;
        }

        $pasifIds = [];
        foreach ($items as $item) {
            if (strtoupper((string) ($item['aktif_durum'] ?? '')) === 'PASIF') {
                $pasifIds[] = (int) ($item['id'] ?? 0);
            }
        }
        $pasifIds = array_values(array_filter($pasifIds, static function ($id) {
            return $id > 0;
        }));
        if (count($pasifIds) === 0) {
            return;
        }

        ArchiveAccessService::writeAccessAudit(
            $pdo,
            $user,
            ArchiveAccessService::ACTION_LIST,
            'personeller',
            $pasifIds[0],
            null,
            $routeSource,
            ['pasif_ids' => $pasifIds, 'count' => count($pasifIds)]
        );
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $personelRow
     */
    public static function writeViewAuditIfPasif(PDO $pdo, array $user, array $personelRow, $routeSource)
    {
        if (strtoupper((string) ($personelRow['aktif_durum'] ?? '')) !== 'PASIF') {
            return;
        }
        ArchiveAccessService::writeAccessAudit(
            $pdo,
            $user,
            ArchiveAccessService::ACTION_VIEW,
            'personel',
            (int) $personelRow['id'],
            (int) $personelRow['id'],
            $routeSource,
            null
        );
    }

    /**
     * Cheap archive markers for detail map (no full retention resolve if expensive fails).
     *
     * @param array<string, mixed> $personelRow
     * @return array<string, mixed>
     */
    public static function buildArchiveMarkers(PDO $pdo, array $personelRow)
    {
        $aktifDurum = strtoupper((string) ($personelRow['aktif_durum'] ?? ''));
        $markers = [
            'arsiv_modu' => $aktifDurum === 'PASIF',
            'policy_note' => RetentionCategories::POLICY_NOTE,
            'retention_summary' => null,
            'legal_hold_active' => false,
        ];

        if ($aktifDurum !== 'PASIF') {
            return $markers;
        }

        $personelId = (int) ($personelRow['id'] ?? 0);
        $markers['legal_hold_active'] = RetentionPolicyService::hasActiveLegalHold(
            $pdo,
            RetentionCategories::PERSONEL_OZLUK,
            ['personel_id' => $personelId, 'entity_type' => 'personel', 'record_id' => $personelId]
        );

        try {
            $trigger = RetentionPolicyService::resolveTrigger(
                $pdo,
                RetentionCategories::PERSONEL_OZLUK,
                ['personel_id' => $personelId]
            );
            $dt = DateTime::createFromFormat('Y-m-d', $trigger['trigger_date']);
            if ($dt) {
                $until = RetentionPolicyService::calculateRetentionUntil($dt);
                $markers['retention_summary'] = [
                    'category' => RetentionCategories::PERSONEL_OZLUK,
                    'trigger_type' => $trigger['trigger_type'],
                    'trigger_date' => $trigger['trigger_date'],
                    'retention_until' => $until,
                    'earliest_destruction_review_date' => $until,
                    'policy_note' => RetentionCategories::POLICY_NOTE,
                ];
            }
        } catch (\Throwable $e) {
            $markers['retention_summary'] = [
                'category' => RetentionCategories::PERSONEL_OZLUK,
                'code' => $e->getMessage(),
                'policy_note' => RetentionCategories::POLICY_NOTE,
            ];
        }

        return $markers;
    }

    /**
     * Force AKTIF-only for personel-returning list queries without arsiv.view.
     *
     * @param array<string, mixed> $user
     */
    public static function forceAktifUnlessArchiveView(array $user, $requestedAktiflik)
    {
        return self::effectiveListAktiflik($user, $requestedAktiflik);
    }
}
