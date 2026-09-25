<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Services\Attendance\AttendanceBusinessDayService;
use Medisa\Api\Services\Attendance\LateEarlyInfoService;
use PDO;

/**
 * Canonical PERSONEL-facing attendance presentation for Today + History.
 *
 * Raw QR occurred_at remains immutable audit.
 * Approved correction (if any) becomes the effective display/evaluation clock.
 */
class QrAttendancePresentationService
{
    public const TZ = 'Europe/Istanbul';

    /**
     * @return array{
     *   id:int,
     *   time:string,
     *   occurred_at:string,
     *   status:array{kind:string,label:string,delta_dakika:int}|null,
     *   correction_allowed:bool,
     *   pending_correction:array<string,mixed>|null
     * }
     */
    public static function presentDayEvent(
        PDO $pdo,
        $personelId,
        $eventId,
        $eventType,
        $occurredAtUtc,
        $businessDateYmd
    ) {
        $eventId = (int) $eventId;
        $rawLocal = self::hhmmFromUtc((string) $occurredAtUtc);
        $effectiveLocal = self::approvedEffectiveLocalTime($pdo, $eventId);
        if ($effectiveLocal === null || $effectiveLocal === '') {
            $effectiveLocal = $rawLocal;
        }

        $pending = self::pendingForEvent($pdo, $eventId);
        $correctionAllowed = $pending === null
            && self::correctionAllowedForEvent($pdo, $personelId, (string) $occurredAtUtc);

        $status = self::statusForEffective(
            $pdo,
            $personelId,
            (string) $eventType,
            (string) $businessDateYmd,
            (string) $effectiveLocal,
            (string) $occurredAtUtc
        );

        return [
            'id' => $eventId,
            'time' => (string) $effectiveLocal,
            'occurred_at' => self::formatClient((string) $occurredAtUtc),
            'status' => $status,
            'correction_allowed' => $correctionAllowed,
            'pending_correction' => $pending,
        ];
    }

    /**
     * Today-box shape (adds local_time / display_local_time / event_type).
     *
     * @return array<string, mixed>
     */
    public static function presentTodayBoxEvent(
        PDO $pdo,
        $personelId,
        $eventId,
        $eventType,
        $occurredAtUtc,
        $businessDateYmd
    ) {
        $day = self::presentDayEvent(
            $pdo,
            $personelId,
            $eventId,
            $eventType,
            $occurredAtUtc,
            $businessDateYmd
        );
        $rawLocal = self::hhmmFromUtc((string) $occurredAtUtc);

        return [
            'id' => $day['id'],
            'event_type' => (string) $eventType,
            'occurred_at' => $day['occurred_at'],
            'local_time' => $rawLocal,
            'display_local_time' => $day['time'],
            'correction_allowed' => $day['correction_allowed'],
            'status' => $day['status'],
        ];
    }

    /**
     * @return array{kind:string,label:string,delta_dakika:int}|null
     */
    public static function statusForEffective(
        PDO $pdo,
        $personelId,
        $eventType,
        $businessDateYmd,
        $effectiveLocalHhmm,
        $fallbackOccurredAtUtc
    ) {
        try {
            $planned = LateEarlyInfoService::loadPlannedDay($pdo, $personelId, $businessDateYmd);
            $evalClock = self::effectiveClockIso($businessDateYmd, $effectiveLocalHhmm, $fallbackOccurredAtUtc);
            $info = LateEarlyInfoService::evaluateAfterScan($eventType, $evalClock, $planned);
            if (!is_array($info)) {
                return null;
            }

            return [
                'kind' => (string) $info['kind'],
                'label' => isset($info['card_label'])
                    ? (string) $info['card_label']
                    : (string) $info['message'],
                'delta_dakika' => (int) $info['delta_dakika'],
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return string|null HH:MM */
    public static function approvedEffectiveLocalTime(PDO $pdo, $eventId)
    {
        try {
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
                $mins = LateEarlyInfoService::hhmmToMinutes((string) $val);
                if ($mins === null) {
                    return null;
                }

                return sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function pendingForEvent(PDO $pdo, $eventId)
    {
        if ((int) $eventId <= 0) {
            return null;
        }
        try {
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
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function correctionAllowedForEvent(PDO $pdo, $personelId, $occurredAtUtc)
    {
        if ($occurredAtUtc === '') {
            return false;
        }
        try {
            return AttendanceBusinessDayService::isCorrectionAllowedNow(
                $pdo,
                $personelId,
                $occurredAtUtc
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Build an ISO/UTC clock for LateEarlyInfoService using effective HH:MM on the business day.
     */
    public static function effectiveClockIso($businessDateYmd, $effectiveLocalHhmm, $fallbackOccurredAtUtc)
    {
        $mins = LateEarlyInfoService::hhmmToMinutes($effectiveLocalHhmm);
        if ($mins === null) {
            return (string) $fallbackOccurredAtUtc;
        }
        $hhmm = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
        $local = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            (string) $businessDateYmd . ' ' . $hhmm . ':00',
            new \DateTimeZone(self::TZ)
        );
        if ($local === false) {
            return (string) $fallbackOccurredAtUtc;
        }

        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function hhmmFromUtc($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TZ))
            ->format('H:i');
    }

    public static function formatClient($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TZ))
            ->format('c');
    }

    public static function businessDateFromUtc($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TZ))
            ->format('Y-m-d');
    }
}
