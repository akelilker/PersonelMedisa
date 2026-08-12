<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Medisa\Api\Services\PuantajDonemPeriodService;
use PDO;

/**
 * Operational read model: QR intervals → daily puantaj evidence candidates (S3E).
 * Request-time only — no candidate table / no canonical write.
 */
class QrPuantajCandidateReadService
{
    public const MAX_RANGE_DAYS_INCLUSIVE = 62;

    /**
     * @return array<string,mixed>
     */
    public static function listForPersonel(PDO $pdo, $personelId, $subeId, $from, $to)
    {
        QrAttendanceEventService::assertSchemaReady($pdo);
        $personelId = (int) $personelId;
        $subeId = (int) $subeId;
        $range = self::resolveOperationalRange($from, $to);

        $utcRange = QrAttendanceEventService::businessDateRangeToUtc(
            $range['from'],
            $range['to']
        );

        $events = QrAttendanceIntervalReadService::loadEventsWithBoundaryContext(
            $pdo,
            $personelId,
            $utcRange['from_utc'],
            $utcRange['to_exclusive_utc']
        );

        $derived = QrAttendanceIntervalDerivationService::derive($events);
        $canonicalByDate = self::loadCanonicalByDate($pdo, $personelId, $range['from'], $range['to']);
        $correctionPresentByDate = self::loadGirisCikisCorrectionFlags(
            $pdo,
            $personelId,
            $subeId,
            $range['from'],
            $range['to']
        );
        $periodContextByDate = self::buildPeriodContextByDate(
            $pdo,
            $subeId,
            $range['from'],
            $range['to']
        );

        $items = QrPuantajCandidateProjectionService::buildDailyItems(
            $derived,
            $range['from'],
            $range['to'],
            $canonicalByDate,
            $periodContextByDate,
            $correctionPresentByDate
        );

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'algorithm_version' => QrPuantajCandidateProjectionService::ALGORITHM_VERSION,
            'interval_algorithm_version' => QrPuantajCandidateProjectionService::INTERVAL_ALGORITHM_VERSION,
            'personel_id' => $personelId,
            'items' => $items,
            'summary' => QrPuantajCandidateProjectionService::buildSummary($items),
        ];
    }

    /**
     * @return array{from:string,to:string}
     */
    public static function resolveOperationalRange($from, $to)
    {
        $tz = new \DateTimeZone('Europe/Istanbul');
        $now = new \DateTimeImmutable('now', $tz);
        $defaultFrom = $now->modify('first day of this month')->format('Y-m-d');
        $defaultTo = $now->modify('last day of this month')->format('Y-m-d');

        $fromYmd = self::normalizeYmd($from, $defaultFrom);
        $toYmd = self::normalizeYmd($to, $defaultTo);
        if ($fromYmd > $toYmd) {
            throw new \InvalidArgumentException('from must be <= to');
        }

        $days = self::inclusiveDayCount($fromYmd, $toYmd);
        if ($days > self::MAX_RANGE_DAYS_INCLUSIVE) {
            throw new \InvalidArgumentException(
                'Range exceeds maximum of ' . self::MAX_RANGE_DAYS_INCLUSIVE . ' days.'
            );
        }

        return ['from' => $fromYmd, 'to' => $toYmd];
    }

    private static function normalizeYmd($value, $fallback)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return $fallback;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new \InvalidArgumentException('Invalid date format.');
        }
        $parts = explode('-', $raw);
        if (!checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw new \InvalidArgumentException('Invalid calendar date.');
        }

        return $raw;
    }

    private static function inclusiveDayCount($fromYmd, $toYmd)
    {
        $from = new \DateTimeImmutable((string) $fromYmd);
        $to = new \DateTimeImmutable((string) $toYmd);

        return (int) $from->diff($to)->days + 1;
    }

    /**
     * @return array<string, array<string,mixed>|null>
     */
    private static function loadCanonicalByDate(PDO $pdo, $personelId, $from, $to)
    {
        $stmt = $pdo->prepare(
            'SELECT id, tarih, giris_saati, cikis_saati, state, kontrol_durumu
             FROM gunluk_puantaj
             WHERE personel_id = :personel_id
               AND tarih >= :from_date
               AND tarih <= :to_date'
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'from_date' => (string) $from,
            'to_date' => (string) $to,
        ]);

        $byDate = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $byDate[(string) $row['tarih']] = $row;
            }
        }

        return $byDate;
    }

    /**
     * Active approved GIRIS_CIKIS corrections — no reusable entry/exit overlay on server.
     *
     * @return array<string, bool>
     */
    private static function loadGirisCikisCorrectionFlags(PDO $pdo, $personelId, $subeId, $from, $to)
    {
        if (!self::tableExists($pdo, 'haftalik_kapanis_revizyon_corrections')) {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.etkilenen_tarih
             FROM haftalik_kapanis_revizyon_corrections c
             INNER JOIN haftalik_kapanis_revizyon_talepleri t ON t.id = c.revizyon_talebi_id
             WHERE c.personel_id = :personel_id
               AND c.sube_id = :sube_id
               AND c.iptal_edildi_mi = 0
               AND c.correction_tipi = 'GIRIS_CIKIS_DUZELTME'
               AND t.durum = 'ONAYLANDI'
               AND c.etkilenen_tarih BETWEEN :from_date AND :to_date"
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'sube_id' => (int) $subeId,
            'from_date' => (string) $from,
            'to_date' => (string) $to,
        ]);

        $flags = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $flags[(string) $row['etkilenen_tarih']] = true;
            }
        }

        return $flags;
    }

    /**
     * @return array<string, array<string,mixed>>
     */
    public static function buildPeriodContextByDate(PDO $pdo, $subeId, $from, $to)
    {
        $cache = [];
        $out = [];
        $cursor = new \DateTimeImmutable((string) $from);
        $end = new \DateTimeImmutable((string) $to);

        while ($cursor <= $end) {
            $ymd = $cursor->format('Y-m-d');
            $yil = (int) $cursor->format('Y');
            $ay = (int) $cursor->format('n');
            $monthKey = $yil . '-' . $ay;
            if (!isset($cache[$monthKey])) {
                $cache[$monthKey] = PuantajDonemPeriodService::resolveCanonicalWriteContext(
                    $pdo,
                    $subeId,
                    $yil,
                    $ay
                );
            }
            $out[$ymd] = $cache[$monthKey];
            $cursor = $cursor->modify('+1 day');
        }

        return $out;
    }

    private static function tableExists(PDO $pdo, $table)
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . str_replace("'", '', (string) $table) . "'");

            return (bool) $stmt->fetch(PDO::FETCH_NUM);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
