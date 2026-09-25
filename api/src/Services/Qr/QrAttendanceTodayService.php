<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityService;
use Medisa\Api\Services\SelfService\SelfPersonelContext;
use PDO;

/**
 * Mobile home attendance box state for today (Istanbul business date).
 * Presentation (effective time / status / correction_allowed) owned by
 * QrAttendancePresentationService — shared with History.
 */
class QrAttendanceTodayService
{
    /**
     * @param array<string, mixed> $authUser
     * @return array<string, mixed>
     */
    public static function today(PDO $pdo, array $authUser)
    {
        $ctx = SelfPersonelContext::resolveForSelfService($authUser, $pdo, true);
        $caps = PersonelMobileCapabilityService::resolve($pdo, (int) $ctx['personel_id'], $ctx);
        if (!RolePermissions::has($authUser, 'self_service.qr.scan')) {
            $caps['qr_scan'] = false;
        }
        $today = self::istanbulToday();
        $personelId = (int) $ctx['personel_id'];

        $giris = null;
        $cikis = null;
        $pendingGirisCorrection = null;
        $pendingCikisCorrection = null;

        if (!empty($caps['qr_scan'])) {
            try {
                QrAttendanceEventService::assertSchemaReady($pdo);
                $range = QrAttendanceEventService::businessDateRangeToUtc($today, $today);
                $stmt = $pdo->prepare(
                    'SELECT id, event_type, occurred_at_utc
                     FROM qr_attendance_events
                     WHERE personel_id = :pid
                       AND occurred_at_utc >= :from_utc
                       AND occurred_at_utc < :to_utc
                     ORDER BY occurred_at_utc ASC, id ASC'
                );
                $stmt->execute([
                    'pid' => $personelId,
                    'from_utc' => $range['from_utc'],
                    'to_utc' => $range['to_exclusive_utc'],
                ]);
                $rawGiris = null;
                $rawCikis = null;
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if ((string) $row['event_type'] === 'GIRIS') {
                        $rawGiris = $row;
                    } elseif ((string) $row['event_type'] === 'CIKIS') {
                        $rawCikis = $row;
                    }
                }

                if ($rawGiris !== null) {
                    $giris = QrAttendancePresentationService::presentTodayBoxEvent(
                        $pdo,
                        $personelId,
                        (int) $rawGiris['id'],
                        'GIRIS',
                        (string) $rawGiris['occurred_at_utc'],
                        $today
                    );
                    $pendingGirisCorrection = QrAttendancePresentationService::pendingForEvent(
                        $pdo,
                        (int) $rawGiris['id']
                    );
                }
                if ($rawCikis !== null) {
                    $cikis = QrAttendancePresentationService::presentTodayBoxEvent(
                        $pdo,
                        $personelId,
                        (int) $rawCikis['id'],
                        'CIKIS',
                        (string) $rawCikis['occurred_at_utc'],
                        $today
                    );
                    $pendingCikisCorrection = QrAttendancePresentationService::pendingForEvent(
                        $pdo,
                        (int) $rawCikis['id']
                    );
                }
            } catch (\Throwable $e) {
                // Schema not ready — empty boxes.
            }
        }

        return [
            'business_date' => $today,
            'capabilities' => $caps,
            'personel' => [
                'id' => $personelId,
                'ad_soyad' => (string) $ctx['ad_soyad'],
                'sube_ad' => (string) ($ctx['sube_ad'] ?? ''),
                'bolum_ad' => $ctx['bolum_ad'] ?? null,
                'birim_ad' => $ctx['birim_ad'] ?? null,
                'gorev_ad' => $ctx['gorev_ad'] ?? null,
            ],
            'giris' => $giris,
            'cikis' => $cikis,
            'can_scan_giris' => !empty($caps['qr_scan']) && $giris === null,
            'can_scan_cikis' => !empty($caps['qr_scan']) && $giris !== null && $cikis === null,
            'pending_giris_correction' => $pendingGirisCorrection,
            'pending_cikis_correction' => $pendingCikisCorrection,
        ];
    }

    private static function istanbulToday()
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
    }
}
