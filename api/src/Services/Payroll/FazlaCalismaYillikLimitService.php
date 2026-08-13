<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

use PDO;

/**
 * Canonical yearly fazla-calisma (270h) compliance owner — Pack5.
 *
 * POLICY_CODE = ROLLING_12_MONTH_ACTUAL_DATE_V1
 * - Weekly FM amount comes from existing weekly motor (unchanged).
 * - This service only assigns actual-date provenance to that FM amount.
 * - Hard guard = rolling 12 months (16200 dk); ISO/calendar year is NOT the 270h owner.
 * - Legacy snapshots without distribution: conservative week-overlap inclusion (no under-count).
 * - Concurrency: personel-scoped lock (sentinel yil=0), not calendar/ISO year keys.
 */
final class FazlaCalismaYillikLimitService
{
    public const POLICY_CODE = 'ROLLING_12_MONTH_ACTUAL_DATE_V1';

    /** Sentinel in yillik_fazla_calisma_kilitleri.yil — personel rolling lock, not a calendar year. */
    public const PERSONEL_ROLLING_LOCK_YIL = 0;

    public const LIMIT_DAKIKA = 16200;
    public const YAKLASMA_ESIK_DAKIKA = 15600;
    public const HAFTALIK_NORMAL_ESIK_DAKIKA = 2700;

    /**
     * Deterministic actual-date allocation of an already-computed weekly FM total.
     * Does not invent a new FM amount — walks daily net chronologically and assigns
     * only the excess minutes after the weekly normal threshold.
     *
     * @param array<string, int|float|string|null> $dailyNetByDate map Y-m-d => net dakika
     * @return list<array{tarih:string,dakika:int}>
     */
    public static function allocateActualDateProvenance(
        array $dailyNetByDate,
        int $weeklyFazlaDakika,
        int $haftalikEsikDakika = self::HAFTALIK_NORMAL_ESIK_DAKIKA
    ): array {
        $weeklyFazlaDakika = max(0, $weeklyFazlaDakika);
        if ($weeklyFazlaDakika === 0) {
            return [];
        }

        $dates = array_keys($dailyNetByDate);
        sort($dates, SORT_STRING);

        $remainingNormal = max(0, $haftalikEsikDakika);
        $otRemaining = $weeklyFazlaDakika;
        $out = [];

        foreach ($dates as $tarih) {
            if ($otRemaining <= 0) {
                break;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $tarih)) {
                continue;
            }
            $dayNet = (int) $dailyNetByDate[$tarih];
            if ($dayNet < 0) {
                $dayNet = 0;
            }
            if ($dayNet === 0) {
                continue;
            }

            if ($remainingNormal > 0) {
                $takeNormal = min($dayNet, $remainingNormal);
                $remainingNormal -= $takeNormal;
                $dayNet -= $takeNormal;
            }

            if ($dayNet > 0 && $otRemaining > 0) {
                $alloc = min($dayNet, $otRemaining);
                $out[] = [
                    'tarih' => (string) $tarih,
                    'dakika' => $alloc,
                ];
                $otRemaining -= $alloc;
            }
        }

        return $out;
    }

    /**
     * @param list<array{tarih:string,dakika:int}> $distribution
     */
    public static function sumDistributionMinutes(array $distribution): int
    {
        $sum = 0;
        foreach ($distribution as $row) {
            $dk = (int) ($row['dakika'] ?? 0);
            if ($dk > 0) {
                $sum += $dk;
            }
        }

        return $sum;
    }

    /**
     * Inclusive rolling window ending on $windowEnd (Y-m-d):
     * [windowEnd - 1 year + 1 day, windowEnd].
     *
     * @return array{start:string,end:string}
     */
    public static function rollingWindowBounds(string $windowEnd): array
    {
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($windowEnd));
        if (!$end) {
            throw new \InvalidArgumentException('Invalid windowEnd date');
        }
        $start = $end->modify('-1 year')->modify('+1 day');

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    public static function provenanceSchemaReady(PDO $pdo): bool
    {
        return self::columnExists($pdo, 'haftalik_kapanis_satirlari', 'fazla_calisma_tarih_dagilimi_json')
            && self::columnExists($pdo, 'haftalik_kapanis_satirlari', 'fazla_calisma_tarih_dagilim_policy');
    }

    /**
     * Personel-scoped race-safe lock for rolling 270h (independent of calendar/ISO year).
     */
    public static function acquirePersonelRollingLock(PDO $pdo, int $personelId, ?int $actorId): void
    {
        PayrollComplianceGuard::acquireYillikLock(
            $pdo,
            $personelId,
            self::PERSONEL_ROLLING_LOCK_YIL,
            $actorId
        );
    }

    /**
     * Load closed weekly FM contributions inside a rolling window.
     *
     * Exact provenance (policy + JSON): only minutes whose tarih falls in window.
     * Legacy (missing distribution): if week range overlaps window, include full week FM
     * (conservative — must not under-count). Never invent fake daily distribution.
     *
     * @param list<string> $excludeHaftaBaslangic
     * @return array{
     *   kullanilan:int,
     *   contributions:list<array<string,mixed>>,
     *   window_start:string,
     *   window_end:string,
     *   policy:string
     * }
     */
    public static function loadRollingKapanmisFazlaCalisma(
        PDO $pdo,
        int $personelId,
        string $windowEnd,
        array $excludeHaftaBaslangic = []
    ): array {
        $bounds = self::rollingWindowBounds($windowEnd);
        $windowStart = $bounds['start'];
        $windowEndNorm = $bounds['end'];
        $exclude = [];
        foreach ($excludeHaftaBaslangic as $hb) {
            $hb = trim((string) $hb);
            if ($hb !== '') {
                $exclude[$hb] = true;
            }
        }

        if (!self::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            throw new \RuntimeException(
                PayrollComplianceGuard::BLOCKER_COMPLIANCE_SCHEMA_UNAVAILABLE . ':haftalik_kapanis_satirlari'
            );
        }

        $hasProvenance = self::provenanceSchemaReady($pdo);
        $selectExtra = $hasProvenance
            ? ', s.fazla_calisma_tarih_dagilimi_json, s.fazla_calisma_tarih_dagilim_policy'
            : ', NULL AS fazla_calisma_tarih_dagilimi_json, NULL AS fazla_calisma_tarih_dagilim_policy';

        // Pull weeks that could overlap the rolling window (week start up to window end,
        // week end on/after window start).
        $stmt = $pdo->prepare(
            "SELECT s.id, s.kapanis_id, s.personel_id, s.hafta_baslangic, s.hafta_bitis,
                    s.fazla_calisma_dakika, s.tam_hafta_verisi, s.state
                    {$selectExtra}
             FROM haftalik_kapanis_satirlari s
             WHERE s.personel_id = :pid
               AND s.state = 'KAPANDI'
               AND s.hafta_baslangic <= :window_end
               AND s.hafta_bitis >= :window_start
             ORDER BY s.hafta_baslangic ASC, s.kapanis_id DESC"
        );
        $stmt->execute([
            'pid' => $personelId,
            'window_end' => $windowEndNorm,
            'window_start' => $windowStart,
        ]);

        $byHafta = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!(int) ($row['tam_hafta_verisi'] ?? 1)) {
                continue;
            }
            $key = (string) $row['hafta_baslangic'];
            if (isset($exclude[$key])) {
                continue;
            }
            if (isset($byHafta[$key])) {
                continue; // highest kapanis_id first
            }
            $byHafta[$key] = $row;
        }

        $kullanilan = 0;
        $contributions = [];
        foreach ($byHafta as $row) {
            $weekFm = max(0, (int) ($row['fazla_calisma_dakika'] ?? 0));
            if ($weekFm < 1) {
                continue;
            }

            $counted = self::countMinutesForWindow(
                $row,
                $weekFm,
                $windowStart,
                $windowEndNorm,
                $hasProvenance
            );
            if ($counted < 1) {
                continue;
            }
            $kullanilan += $counted;
            $contributions[] = [
                'fazla_calisma_dakika' => $counted,
                'hafta_baslangic' => (string) $row['hafta_baslangic'],
                'hafta_bitis' => (string) $row['hafta_bitis'],
                'kapanis_id' => (int) $row['kapanis_id'],
                'accounting' => $counted === $weekFm && self::isLegacyMissingDistribution($row, $hasProvenance)
                    ? 'LEGACY_WEEK_OVERLAP'
                    : 'EXACT_DATE',
            ];
        }

        return [
            'kullanilan' => $kullanilan,
            'contributions' => $contributions,
            'window_start' => $windowStart,
            'window_end' => $windowEndNorm,
            'policy' => self::POLICY_CODE,
        ];
    }

    /**
     * Canonical hard-guard: pending OT minutes are evaluated chronologically by
     * actual date. Each pending date uses its own rolling 12-month window so an
     * early-week / early-month OT date cannot drop valid historical FM that a
     * later week_end / donem_bitis window would exclude.
     *
     * @param list<array{tarih:string,dakika:int}>|null $pendingDistribution
     * @param list<string> $excludeHaftaBaslangic
     * @return array{
     *   kullanilan:int,
     *   pending:int,
     *   projected:int,
     *   max_projected:int,
     *   asildi:bool,
     *   yaklasiyor:bool,
     *   window_start:string,
     *   window_end:string,
     *   violation_date:?string,
     *   violation_window_start:?string,
     *   violation_window_end:?string,
     *   policy:string
     * }
     */
    public static function evaluatePendingAgainstRolling(
        PDO $pdo,
        int $personelId,
        string $windowEnd,
        int $pendingDakika,
        ?array $pendingDistribution = null,
        array $excludeHaftaBaslangic = []
    ): array {
        $pendingDakika = max(0, $pendingDakika);
        $normalized = self::normalizePendingDistribution($pendingDistribution);
        $distTotal = self::sumDistributionMinutes($normalized);

        // Usable per-date provenance: sum matches weekly/period motor amount.
        // Empty / incomplete / mismatched dist must NEVER under-count — fall back
        // to single-lump evaluation on windowEnd with full pendingDakika.
        $usePerDate = $normalized !== [] && $distTotal === $pendingDakika && $pendingDakika > 0;

        if (!$usePerDate) {
            $loaded = self::loadRollingKapanmisFazlaCalisma(
                $pdo,
                $personelId,
                $windowEnd,
                $excludeHaftaBaslangic
            );
            $pending = $pendingDakika;
            if ($pendingDistribution !== null && $normalized !== []) {
                // Partial dist present: never reduce below motor amount.
                $pending = max($pendingDakika, $distTotal);
            }
            $projected = $loaded['kullanilan'] + $pending;
            $asildi = $projected > self::LIMIT_DAKIKA;

            return [
                'kullanilan' => $loaded['kullanilan'],
                'pending' => $pending,
                'projected' => $projected,
                'max_projected' => $projected,
                'asildi' => $asildi,
                'yaklasiyor' => $projected >= self::YAKLASMA_ESIK_DAKIKA,
                'window_start' => $loaded['window_start'],
                'window_end' => $loaded['window_end'],
                'violation_date' => $asildi ? $loaded['window_end'] : null,
                'violation_window_start' => $asildi ? $loaded['window_start'] : null,
                'violation_window_end' => $asildi ? $loaded['window_end'] : null,
                'policy' => self::POLICY_CODE,
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            return strcmp($a['tarih'], $b['tarih']);
        });

        $earlierPending = [];
        $maxProjected = 0;
        $lastKullanilan = 0;
        $lastBounds = self::rollingWindowBounds($windowEnd);
        $violationDate = null;
        $violationWindowStart = null;
        $violationWindowEnd = null;

        foreach ($normalized as $item) {
            $bounds = self::rollingWindowBounds($item['tarih']);
            $loaded = self::loadRollingKapanmisFazlaCalisma(
                $pdo,
                $personelId,
                $item['tarih'],
                $excludeHaftaBaslangic
            );
            $pendingInWindow = (int) $item['dakika'];
            foreach ($earlierPending as $prev) {
                if ($prev['tarih'] >= $bounds['start'] && $prev['tarih'] <= $bounds['end']) {
                    $pendingInWindow += (int) $prev['dakika'];
                }
            }
            $projected = $loaded['kullanilan'] + $pendingInWindow;
            $lastKullanilan = $loaded['kullanilan'];
            $lastBounds = $bounds;
            if ($projected > $maxProjected) {
                $maxProjected = $projected;
            }
            if ($projected > self::LIMIT_DAKIKA && $violationDate === null) {
                $violationDate = $item['tarih'];
                $violationWindowStart = $bounds['start'];
                $violationWindowEnd = $bounds['end'];
            }
            $earlierPending[] = $item;
        }

        $asildi = $violationDate !== null || $maxProjected > self::LIMIT_DAKIKA;

        return [
            'kullanilan' => $lastKullanilan,
            'pending' => $pendingDakika,
            'projected' => $maxProjected,
            'max_projected' => $maxProjected,
            'asildi' => $asildi,
            'yaklasiyor' => $maxProjected >= self::YAKLASMA_ESIK_DAKIKA,
            'window_start' => $violationWindowStart !== null ? $violationWindowStart : $lastBounds['start'],
            'window_end' => $violationWindowEnd !== null ? $violationWindowEnd : $lastBounds['end'],
            'violation_date' => $violationDate,
            'violation_window_start' => $violationWindowStart,
            'violation_window_end' => $violationWindowEnd,
            'policy' => self::POLICY_CODE,
        ];
    }

    /**
     * @param list<array{tarih:string,dakika:int}>|null $pendingDistribution
     * @return list<array{tarih:string,dakika:int}>
     */
    private static function normalizePendingDistribution(?array $pendingDistribution): array
    {
        if ($pendingDistribution === null) {
            return [];
        }
        $byDate = [];
        foreach ($pendingDistribution as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tarih = isset($row['tarih']) ? trim((string) $row['tarih']) : '';
            $dk = isset($row['dakika']) ? (int) $row['dakika'] : 0;
            if ($tarih === '' || $dk < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih)) {
                continue;
            }
            if (!isset($byDate[$tarih])) {
                $byDate[$tarih] = 0;
            }
            $byDate[$tarih] += $dk;
        }
        $out = [];
        foreach ($byDate as $tarih => $dk) {
            $out[] = ['tarih' => (string) $tarih, 'dakika' => (int) $dk];
        }

        return $out;
    }

    /**
     * Load exact-date distribution for one closed week when 063 provenance exists.
     * Legacy missing JSON → null (caller may fall back to lump pending).
     *
     * @return list<array{tarih:string,dakika:int}>|null
     */
    public static function loadWeekPendingDistribution(
        PDO $pdo,
        int $personelId,
        string $haftaBaslangic
    ): ?array {
        if (!self::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            return null;
        }
        $hasProvenance = self::provenanceSchemaReady($pdo);
        if (!$hasProvenance) {
            return null;
        }
        $stmt = $pdo->prepare(
            "SELECT fazla_calisma_dakika, fazla_calisma_tarih_dagilimi_json, fazla_calisma_tarih_dagilim_policy
             FROM haftalik_kapanis_satirlari
             WHERE personel_id = :pid
               AND hafta_baslangic = :hb
               AND state = 'KAPANDI'
               AND tam_hafta_verisi = 1
             ORDER BY kapanis_id DESC
             LIMIT 1"
        );
        $stmt->execute(['pid' => $personelId, 'hb' => $haftaBaslangic]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || self::isLegacyMissingDistribution($row, true)) {
            return null;
        }
        $dist = self::decodeDistributionJson(
            isset($row['fazla_calisma_tarih_dagilimi_json'])
                ? (string) $row['fazla_calisma_tarih_dagilimi_json']
                : null
        );

        return $dist;
    }

    /**
     * Build pending actual-date distribution for sealed OT inside a payroll period.
     * Exact provenance used when present; legacy weeks contribute full FM on hafta_baslangic
     * (conservative — never invent daily split, never under-count).
     *
     * @return list<array{tarih:string,dakika:int}>
     */
    public static function collectPendingDistributionForPeriod(
        PDO $pdo,
        int $personelId,
        string $donemBaslangic,
        string $donemBitis
    ): array {
        if (!self::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            return [];
        }
        $hasProvenance = self::provenanceSchemaReady($pdo);
        $selectExtra = $hasProvenance
            ? ', s.fazla_calisma_tarih_dagilimi_json, s.fazla_calisma_tarih_dagilim_policy'
            : ', NULL AS fazla_calisma_tarih_dagilimi_json, NULL AS fazla_calisma_tarih_dagilim_policy';
        $stmt = $pdo->prepare(
            "SELECT s.hafta_baslangic, s.hafta_bitis, s.fazla_calisma_dakika, s.kapanis_id
                    {$selectExtra}
             FROM haftalik_kapanis_satirlari s
             WHERE s.personel_id = :pid
               AND s.state = 'KAPANDI'
               AND s.tam_hafta_verisi = 1
               AND s.hafta_baslangic <= :donem_bit
               AND s.hafta_bitis >= :donem_bas
             ORDER BY s.hafta_baslangic ASC, s.kapanis_id DESC"
        );
        $stmt->execute([
            'pid' => $personelId,
            'donem_bas' => $donemBaslangic,
            'donem_bit' => $donemBitis,
        ]);
        $byHafta = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = (string) $row['hafta_baslangic'];
            if (isset($byHafta[$key])) {
                continue;
            }
            $byHafta[$key] = $row;
        }

        $byDate = [];
        foreach ($byHafta as $row) {
            $weekFm = max(0, (int) ($row['fazla_calisma_dakika'] ?? 0));
            if ($weekFm < 1) {
                continue;
            }
            if (!$hasProvenance || self::isLegacyMissingDistribution($row, $hasProvenance)) {
                $t = (string) $row['hafta_baslangic'];
                if (!isset($byDate[$t])) {
                    $byDate[$t] = 0;
                }
                $byDate[$t] += $weekFm;
                continue;
            }
            $dist = self::decodeDistributionJson(
                isset($row['fazla_calisma_tarih_dagilimi_json'])
                    ? (string) $row['fazla_calisma_tarih_dagilimi_json']
                    : null
            );
            if ($dist === null) {
                $t = (string) $row['hafta_baslangic'];
                if (!isset($byDate[$t])) {
                    $byDate[$t] = 0;
                }
                $byDate[$t] += $weekFm;
                continue;
            }
            foreach ($dist as $item) {
                $t = $item['tarih'];
                if ($t < $donemBaslangic || $t > $donemBitis) {
                    continue;
                }
                if (!isset($byDate[$t])) {
                    $byDate[$t] = 0;
                }
                $byDate[$t] += (int) $item['dakika'];
            }
        }

        $out = [];
        foreach ($byDate as $tarih => $dk) {
            if ($dk > 0) {
                $out[] = ['tarih' => (string) $tarih, 'dakika' => (int) $dk];
            }
        }

        return $out;
    }

    /**
     * Encode distribution for snapshot persist (null when empty / schema not ready caller).
     *
     * @param list<array{tarih:string,dakika:int}> $distribution
     */
    public static function encodeDistributionJson(array $distribution): ?string
    {
        if ($distribution === []) {
            return null;
        }
        $encoded = json_encode(array_values($distribution), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : null;
    }

    /**
     * @return list<array{tarih:string,dakika:int}>|null null = missing/unparseable (legacy)
     */
    public static function decodeDistributionJson(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tarih = isset($row['tarih']) ? trim((string) $row['tarih']) : '';
            $dk = isset($row['dakika']) ? (int) $row['dakika'] : 0;
            if ($tarih === '' || $dk < 1) {
                continue;
            }
            $out[] = ['tarih' => $tarih, 'dakika' => $dk];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function countMinutesForWindow(
        array $row,
        int $weekFm,
        string $windowStart,
        string $windowEnd,
        bool $hasProvenanceCols
    ): int {
        if (!self::isLegacyMissingDistribution($row, $hasProvenanceCols)) {
            $dist = self::decodeDistributionJson(
                isset($row['fazla_calisma_tarih_dagilimi_json'])
                    ? (string) $row['fazla_calisma_tarih_dagilimi_json']
                    : null
            );
            if ($dist === null) {
                // Policy claimed but unreadable → conservative full week if overlaps
                return self::weekOverlapsWindow(
                    (string) $row['hafta_baslangic'],
                    (string) $row['hafta_bitis'],
                    $windowStart,
                    $windowEnd
                ) ? $weekFm : 0;
            }
            $sum = 0;
            foreach ($dist as $item) {
                $t = $item['tarih'];
                if ($t >= $windowStart && $t <= $windowEnd) {
                    $sum += $item['dakika'];
                }
            }

            return $sum;
        }

        // Legacy: no invented daily split — full week FM if ranges overlap.
        return self::weekOverlapsWindow(
            (string) $row['hafta_baslangic'],
            (string) $row['hafta_bitis'],
            $windowStart,
            $windowEnd
        ) ? $weekFm : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function isLegacyMissingDistribution(array $row, bool $hasProvenanceCols): bool
    {
        if (!$hasProvenanceCols) {
            return true;
        }
        $policy = trim((string) ($row['fazla_calisma_tarih_dagilim_policy'] ?? ''));
        $json = $row['fazla_calisma_tarih_dagilimi_json'] ?? null;
        if ($policy === '' || $json === null || trim((string) $json) === '') {
            return true;
        }

        return false;
    }

    private static function weekOverlapsWindow(
        string $haftaBaslangic,
        string $haftaBitis,
        string $windowStart,
        string $windowEnd
    ): bool {
        return $haftaBaslangic <= $windowEnd && $haftaBitis >= $windowStart;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }

                return false;
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t"
                );
                $stmt->execute(['t' => $table]);

                return (int) $stmt->fetchColumn() === 1;
            }

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t'
            );
            $stmt->execute(['t' => $table]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
