<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;
use Medisa\Api\Services\ResmiTatilTakvimiService;
use Medisa\Api\Services\SirketCalismaPolitikasiService;
use PDO;

/**
 * Europe/Istanbul business-day navigation for attendance correction windows.
 * Uses resmi tatil + şirket çalışma politikası hafta tatili — no hardcoded Sat/Sun/17:40.
 */
class AttendanceBusinessDayService
{
    public const TZ = 'Europe/Istanbul';

    /**
     * First work day strictly after $fromYmd (exclusive).
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
            if (self::isWorkDay($pdo, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public static function isWorkDay(PDO $pdo, $ymd)
    {
        $ymd = (string) $ymd;
        try {
            $ubgt = ResmiTatilTakvimiService::resolveActiveForDate($pdo, $ymd, 'UBGT');
            if (is_array($ubgt)) {
                return false;
            }
        } catch (\Throwable $e) {
            // If tatil service fails, continue with hafta tatili only.
        }

        $haftaTatiliDays = self::resolveHaftaTatiliWeekdays($pdo, $ymd);
        if ($haftaTatiliDays === null) {
            // Policy unresolved: treat Mon–Fri as work days (ISO), never invent mesai hours.
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone(self::TZ));
            if ($dt === false) {
                return false;
            }
            $w = (int) $dt->format('N'); // 1=Mon … 7=Sun

            return $w >= 1 && $w <= 5;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new \DateTimeZone(self::TZ));
        if ($dt === false) {
            return false;
        }
        $weekday = (int) $dt->format('w'); // 0=Sun … 6=Sat
        if (in_array($weekday, $haftaTatiliDays, true)) {
            return false;
        }

        return true;
    }

    /**
     * Deadline Instant (UTC DateTimeImmutable) = next work day + normal mesai bitiş.
     * Planned end source order:
     * 1) gunluk_puantaj.beklenen_cikis_saati on next work day
     * 2) gunluk_puantaj.beklenen_cikis_saati on event day
     * 3) most recent beklenen_cikis_saati for personel on/before event day
     *
     * Returns null when mesai bitiş cannot be resolved without inventing a clock time.
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

        $plannedHhmm = null;
        $nextPlanned = LateEarlyInfoService::loadPlannedDay($pdo, $personelId, $nextWork);
        if (is_array($nextPlanned)) {
            $mins = LateEarlyInfoService::hhmmToMinutes($nextPlanned['beklenen_cikis_saati'] ?? null);
            if ($mins !== null) {
                $plannedHhmm = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
            }
        }
        if ($plannedHhmm === null) {
            $eventPlanned = LateEarlyInfoService::loadPlannedDay($pdo, $personelId, $eventDate);
            if (is_array($eventPlanned)) {
                $mins = LateEarlyInfoService::hhmmToMinutes($eventPlanned['beklenen_cikis_saati'] ?? null);
                if ($mins !== null) {
                    $plannedHhmm = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
                }
            }
        }
        if ($plannedHhmm === null) {
            $plannedHhmm = LateEarlyInfoService::findRecentBeklenenCikis($pdo, $personelId, $eventDate);
        }
        if ($plannedHhmm === null) {
            return null;
        }

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
            // Cannot resolve mesai bitiş without inventing → fail closed (no pencil / API deny).
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
     * @return array<int, int>|null
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

        return is_array($parsed) ? $parsed : null;
    }
}
