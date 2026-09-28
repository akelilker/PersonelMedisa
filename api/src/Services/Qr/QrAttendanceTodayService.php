<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Services\Attendance\LateEarlyInfoService;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityService;
use Medisa\Api\Services\SelfService\SelfPersonelContext;
use PDO;

/**
 * Mobile home attendance box state for today (Istanbul business date).
 * Presentation (effective time / status / correction_allowed) owned by
 * QrAttendancePresentationService — shared with History.
 *
 * Multi-cycle same-day: boxes show latest GIRIS / CIKIS clocks; next_action /
 * can_scan_* follow the open-shift sequence (not "one pair per day").
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
        $nextAction = null;
        $canScanGiris = false;
        $canScanCikis = false;

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
                $dayEvents = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row)) {
                        $dayEvents[] = $row;
                    }
                }

                $rawGiris = null;
                $rawCikis = null;
                $firstGirisId = null;
                foreach ($dayEvents as $row) {
                    $type = (string) $row['event_type'];
                    if ($type === 'GIRIS') {
                        if ($firstGirisId === null) {
                            $firstGirisId = (int) $row['id'];
                        }
                        $rawGiris = $row;
                    } elseif ($type === 'CIKIS') {
                        $rawCikis = $row;
                    }
                }

                $lastOfDay = count($dayEvents) > 0 ? $dayEvents[count($dayEvents) - 1] : null;
                $lastOfDayIsFinalCikis = is_array($lastOfDay)
                    && (string) $lastOfDay['event_type'] === 'CIKIS';

                if ($rawGiris !== null) {
                    $giris = QrAttendancePresentationService::presentTodayBoxEvent(
                        $pdo,
                        $personelId,
                        (int) $rawGiris['id'],
                        'GIRIS',
                        (string) $rawGiris['occurred_at_utc'],
                        $today,
                        [
                            'is_first_giris' => $firstGirisId !== null && (int) $rawGiris['id'] === $firstGirisId,
                            'is_final_cikis' => false,
                            'suppress_early_until_planned_end_passed' => false,
                        ]
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
                        $today,
                        [
                            'is_first_giris' => false,
                            'is_final_cikis' => $lastOfDayIsFinalCikis
                                && (int) $rawCikis['id'] === (int) $lastOfDay['id'],
                            'suppress_early_until_planned_end_passed' => true,
                        ]
                    );
                    $pendingCikisCorrection = QrAttendancePresentationService::pendingForEvent(
                        $pdo,
                        (int) $rawCikis['id']
                    );
                }

                $openShift = QrAttendanceEventService::resolveOpenShiftState($pdo, $personelId);
                $nextAction = $openShift['next_action'];
                $canScanGiris = $nextAction === 'GIRIS';
                $canScanCikis = $nextAction === 'CIKIS';

                // Final early-exit PERSONEL notifications are materialised idempotently
                // in the canonical Today lifecycle — no scheduler, no duplicate. A small
                // bounded lookback also recovers a final early exit that settled on a
                // previous day the personel did not re-open the app (e.g. 25 Sep exit,
                // first open on 26 Sep).
                QrAttendanceEventService::ensureRecentFinalEarlyExitNotifications(
                    $pdo,
                    $personelId,
                    (int) $authUser['id'],
                    $today
                );
            } catch (\Throwable $e) {
                // Schema not ready — empty boxes.
            }
        }

        $plannedShift = self::plannedShiftPayload($pdo, $personelId, $today);

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
            'next_action' => $nextAction,
            'can_scan_giris' => !empty($caps['qr_scan']) && $canScanGiris,
            'can_scan_cikis' => !empty($caps['qr_scan']) && $canScanCikis,
            'pending_giris_correction' => $pendingGirisCorrection,
            'pending_cikis_correction' => $pendingCikisCorrection,
            'planned_shift' => $plannedShift,
        ];
    }

    /**
     * Canonical planned shift window for client countdown (no server-side stale labels).
     *
     * @return array<string, string|null>|null
     */
    private static function plannedShiftPayload(PDO $pdo, $personelId, $businessDateYmd)
    {
        try {
            $planned = LateEarlyInfoService::loadPlannedDay($pdo, (int) $personelId, (string) $businessDateYmd);
            if (!is_array($planned)) {
                return null;
            }
            $giris = isset($planned['beklenen_giris_saati']) ? trim((string) $planned['beklenen_giris_saati']) : '';
            $cikis = isset($planned['beklenen_cikis_saati']) ? trim((string) $planned['beklenen_cikis_saati']) : '';
            if ($giris === '' && $cikis === '') {
                return null;
            }

            return [
                'beklenen_giris_saati' => $giris !== '' ? $giris : null,
                'beklenen_cikis_saati' => $cikis !== '' ? $cikis : null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function istanbulToday()
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
    }
}
