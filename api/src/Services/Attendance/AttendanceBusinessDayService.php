<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;
use Medisa\Api\Services\ResmiTatilTakvimiService;
use Medisa\Api\Services\SirketCalismaPolitikasiService;
use PDO;

/**
 * Europe/Istanbul business-day navigation for attendance correction windows.
 * Fail-closed: unresolved tatil or çalışma politikası ⇒ not a usable work day.
 * No Mon–Fri hardcode. No 08:30/17:40 invent.
 */
class AttendanceBusinessDayService
{
    public const TZ = 'Europe/Istanbul';

    /**
     * First work day strictly after $fromYmd (exclusive).
     * Returns null when no work day can be resolved within lookahead
     * (including when calendar resolution is unresolved).
     *
     * @return string|null Y-m-d
     */
    public static function nextWorkDay(PDO $pdo, $fromYmd, $maxLookaheadDays = 21)
    {
        $fromYmd = (string) $fromYmd;
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromYmd, new \DateTimeZone(self::TZ));
        if ($dt === false) {
            return null;
        }
        $max = max(1, min(60, (int) $maxLookaheadDays));
        for ($i = 1; $i <= $max; $i++) {
            $candidate = $dt->modify('+' . $i . ' day')->format('Y-m-d');
            $flag = self::resolveWorkDay($pdo, $candidate);
            if ($flag === true) {
                return $candidate;
            }
            // false = non-work → keep looking
            // null = unresolved → fail closed (cannot navigate past unknown day safely)
            if ($flag === null) {
                return null;
            }
        }

        return null;
    }

    /**
     * Tri-state work-day resolution.
     *
     * @return bool|null true = work day, false = non-work, null = unresolved (fail-closed)
     */
    public static function resolveWorkDay(PDO $pdo, $ymd)
    {
        $ymd = (string) $ymd;
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone(self::TZ));
        if ($dt === false) {
            return null;
        }

        try {
            $ubgt = ResmiTatilTakvimiService::resolveActiveForDate($pdo, $ymd, 'UBGT');
        } catch (\Throwable $e) {
            return null;
        }
        if (is_array($ubgt)) {
            return false;
        }

        $haftaTatiliDays = self::resolveHaftaTatiliWeekdays($pdo, $ymd);
        if ($haftaTatiliDays === null) {
            return null;
        }

        $weekday = (int) $dt->format('w'); // 0=Sun … 6=Sat
        if (in_array($weekday, $haftaTatiliDays, true)) {
            return false;
        }

        return true;
    }

    /**
     * @deprecated Prefer resolveWorkDay() tri-state. true only when positively a work day.
     */
    public static function isWorkDay(PDO $pdo, $ymd)
    {
        return self::resolveWorkDay($pdo, $ymd) === true;
    }

    /**
     * Deadline Instant (UTC) = next work day + that day's canonical beklenen_cikis_saati.
     * Does NOT copy event-day or recent previous clocks onto the next work day.
     * Returns null when next work day or its planned exit cannot be resolved.
     *
     * @return \DateTimeImmutable|null UTC
     */
    public static function resolveCorrectionDeadlineUtc(PDO $pdo, $personelId, $eventOccurredAtUtc)
    {
        $eventDate = (new \DateTimeImmutable((string) $eventOccurredAtUtc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TZ))
            ->format('Y-m-d');
        $nextWork = self::nextWorkDay($pdo, $eventDate);
        if ($nextWork === null) {
            return null;
        }

        $nextPlanned = LateEarlyInfoService::loadPlannedDay($pdo, $personelId, $nextWork);
        if (!is_array($nextPlanned)) {
            return null;
        }
        $mins = LateEarlyInfoService::hhmmToMinutes($nextPlanned['beklenen_cikis_saati'] ?? null);
        if ($mins === null) {
            return null;
        }
        $plannedHhmm = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);

        $local = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $nextWork . ' ' . $plannedHhmm . ':00',
            new \DateTimeZone(self::TZ)
        );
        if ($local === false) {
            return null;
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @return bool true when request is still within window
     */
    public static function isCorrectionAllowedNow(PDO $pdo, $personelId, $eventOccurredAtUtc, $nowUtc = null)
    {
        $deadline = self::resolveCorrectionDeadlineUtc($pdo, $personelId, $eventOccurredAtUtc);
        if ($deadline === null) {
            return false;
        }
        if ($nowUtc === null) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        } else {
            $now = new \DateTimeImmutable((string) $nowUtc, new \DateTimeZone('UTC'));
        }

        return $now <= $deadline;
    }

    /**
     * @return array<int, int>|null null = politika unresolved
     */
    private static function resolveHaftaTatiliWeekdays(PDO $pdo, $ymd)
    {
        try {
            $resolved = SirketCalismaPolitikasiService::resolveApprovedForPeriod($pdo, $ymd, $ymd);
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($resolved['politika'] ?? null)) {
            return null;
        }
        $byCode = isset($resolved['degerler_by_code']) && is_array($resolved['degerler_by_code'])
            ? $resolved['degerler_by_code']
            : [];
        $raw = '';
        if (isset($byCode['HAFTA_TATILI_GUNLERI'])) {
            $row = $byCode['HAFTA_TATILI_GUNLERI'];
            $raw = (string) ($row['metin_deger'] ?? '');
            if ($raw === '' && isset($row['sayisal_deger'])) {
                $raw = (string) $row['sayisal_deger'];
            }
        }
        if ($raw === '') {
            $raw = SirketCalismaPolitikasiCatalog::LEGACY_HAFTA_TATILI_GUNLERI;
        }
        $parsed = SirketCalismaPolitikasiCatalog::parseHaftaTatiliGunleri($raw);
        if (!($parsed['ok'] ?? false) || !isset($parsed['days']) || !is_array($parsed['days'])) {
            return null;
        }

        return array_map('intval', $parsed['days']);
    }
}