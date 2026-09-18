<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityService;
use Medisa\Api\Services\SelfService\SelfPersonelContext;
use PDO;

/**
 * Mobile home attendance box state for today (Istanbul business date).
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
        // PERSONEL self-service QR capability is collar-gated (fail-closed).
        // Management roles keep the personnel-linked behaviour of this phase.
        if (!RolePermissions::has($authUser, 'self_service.qr.scan')) {
            $caps['qr_scan'] = false;
        }
        $today = self::istanbulToday();

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
                    'pid' => (int) $ctx['personel_id'],
                    'from_utc' => $range['from_utc'],
                    'to_utc' => $range['to_exclusive_utc'],
                ]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $item = [
                        'id' => (int) $row['id'],
                        'event_type' => (string) $row['event_type'],
                        'occurred_at' => self::formatClient((string) $row['occurred_at_utc']),
                        'local_time' => self::hhmm((string) $row['occurred_at_utc']),
                    ];
                    if ($item['event_type'] === 'GIRIS') {
                        $giris = $item;
                    } elseif ($item['event_type'] === 'CIKIS') {
                        $cikis = $item;
                    }
                }

                $corrTable = $pdo->query("SHOW TABLES LIKE 'qr_attendance_correction_requests'");
                $hasCorr = $corrTable !== false && $corrTable->fetch(PDO::FETCH_NUM) !== false;
                if ($corrTable !== false) {
                    $corrTable->closeCursor();
                }
                if ($hasCorr) {
                    $pendingGirisCorrection = self::pendingForEvent($pdo, $giris ? (int) $giris['id'] : 0);
                    $pendingCikisCorrection = self::pendingForEvent($pdo, $cikis ? (int) $cikis['id'] : 0);
                    // Effective approved overlay for display
                    if ($giris) {
                        $giris['display_local_time'] = self::effectiveDisplayTime($pdo, (int) $giris['id'], $giris['local_time']);
                    }
                    if ($cikis) {
                        $cikis['display_local_time'] = self::effectiveDisplayTime($pdo, (int) $cikis['id'], $cikis['local_time']);
                    }
                } else {
                    if ($giris) {
                        $giris['display_local_time'] = $giris['local_time'];
                    }
                    if ($cikis) {
                        $cikis['display_local_time'] = $cikis['local_time'];
                    }
                }
            } catch (\Throwable $e) {
                // Schema not ready — empty boxes.
            }
        }

        return [
            'business_date' => $today,
            'capabilities' => $caps,
            'personel' => [
                'id' => (int) $ctx['personel_id'],
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

    /** @return array<string, mixed>|null */
    private static function pendingForEvent(PDO $pdo, $eventId)
    {
        if ($eventId <= 0) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT id, status, requested_local_time, original_occurred_at_utc
             FROM qr_attendance_correction_requests
             WHERE source_event_id = :eid AND status = 'BEKLIYOR'
             LIMIT 1"
        );
        $stmt->execute(['eid' => (int) $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'status' => 'BEKLIYOR',
            'status_label' => 'Bekliyor',
            'requested_local_time' => (string) $row['requested_local_time'],
        ];
    }

    private static function effectiveDisplayTime(PDO $pdo, $eventId, $fallback)
    {
        $stmt = $pdo->prepare(
            "SELECT effective_local_time
             FROM qr_attendance_correction_requests
             WHERE source_event_id = :eid AND status = 'ONAYLANDI'
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute(['eid' => (int) $eventId]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && trim((string) $val) !== '') {
            return (string) $val;
        }

        return (string) $fallback;
    }

    private static function istanbulToday()
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
    }

    private static function hhmm($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('H:i');
    }

    private static function formatClient($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('c');
    }
}
