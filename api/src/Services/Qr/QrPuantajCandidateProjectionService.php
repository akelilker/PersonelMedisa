<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

/**
 * Pure daily QR puantaj evidence candidate projection (S3E / QR_PUANTAJ_CANDIDATE_V1).
 *
 * No SQL / Auth / HTTP. No canonical gunluk_puantaj write. No absence inference.
 */
class QrPuantajCandidateProjectionService
{
    public const ALGORITHM_VERSION = 'QR_PUANTAJ_CANDIDATE_V1';
    public const INTERVAL_ALGORITHM_VERSION = 'QR_INTERVAL_V1';
    public const REVISION_HINT = 'PUANTAJ_GIRIS_CIKIS_DUZELTME';
    public const CORRECTION_HINT = 'GIRIS_CIKIS_DUZELTME';

    public const CLASS_READY_SINGLE_INTERVAL = 'READY_SINGLE_INTERVAL';
    public const CLASS_REVIEW_MULTIPLE_INTERVALS = 'REVIEW_MULTIPLE_INTERVALS';
    public const CLASS_REVIEW_CROSS_MIDNIGHT = 'REVIEW_CROSS_MIDNIGHT';
    public const CLASS_REVIEW_ANOMALY = 'REVIEW_ANOMALY';
    public const CLASS_REVIEW_MULTIPLE_BRANCHES = 'REVIEW_MULTIPLE_BRANCHES';

    public const COMPARE_NO_CANONICAL_ROW = 'NO_CANONICAL_ROW';
    public const COMPARE_MATCHES_CANONICAL_TIME = 'MATCHES_CANONICAL_TIME';
    public const COMPARE_DIFFERS_CANONICAL_TIME = 'DIFFERS_CANONICAL_TIME';
    public const COMPARE_NO_SAFE_TIME_PROPOSAL = 'NO_SAFE_TIME_PROPOSAL';
    public const COMPARE_PERIOD_REQUIRES_REVISION = 'PERIOD_REQUIRES_REVISION';
    public const COMPARE_APPROVED_CORRECTION_PRESENT = 'APPROVED_CORRECTION_PRESENT';

    /**
     * Group derived intervals/anomalies by entry-anchor date and build candidate items.
     *
     * @param array<string,mixed> $derived QrAttendanceIntervalDerivationService output
     * @param array<string, array<string,mixed>|null> $canonicalByDate Y-m-d => row|null
     * @param array<string, array<string,mixed>> $periodContextByDate Y-m-d => period metadata
     * @param array<string, bool> $correctionPresentByDate
     * @return list<array<string,mixed>>
     */
    public static function buildDailyItems(
        array $derived,
        $fromYmd,
        $toYmd,
        array $canonicalByDate,
        array $periodContextByDate,
        array $correctionPresentByDate
    ) {
        $fromYmd = (string) $fromYmd;
        $toYmd = (string) $toYmd;
        $byDate = self::groupEvidenceByCandidateDate($derived, $fromYmd, $toYmd);
        $dates = array_keys($byDate);
        sort($dates);

        $items = [];
        foreach ($dates as $candidateDate) {
            $bucket = $byDate[$candidateDate];
            $items[] = self::buildItem(
                $candidateDate,
                $bucket['intervals'],
                $bucket['anomalies'],
                $canonicalByDate[$candidateDate] ?? null,
                $periodContextByDate[$candidateDate] ?? [],
                !empty($correctionPresentByDate[$candidateDate]),
                $derived
            );
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $derived
     * @return array<string, array{intervals:list<array<string,mixed>>,anomalies:list<array<string,mixed>>}>
     */
    private static function groupEvidenceByCandidateDate(array $derived, $fromYmd, $toYmd)
    {
        $byDate = [];

        foreach ($derived['intervals'] as $interval) {
            $anchor = (string) ($interval['entry_local_date'] ?? '');
            if ($anchor === '' || $anchor < $fromYmd || $anchor > $toYmd) {
                continue;
            }
            if (!isset($byDate[$anchor])) {
                $byDate[$anchor] = ['intervals' => [], 'anomalies' => []];
            }
            $byDate[$anchor]['intervals'][] = $interval;
        }

        foreach ($derived['anomalies'] as $anomaly) {
            $anchor = self::anomalyAnchorDate($anomaly);
            if ($anchor === '' || $anchor < $fromYmd || $anchor > $toYmd) {
                continue;
            }
            if (!isset($byDate[$anchor])) {
                $byDate[$anchor] = ['intervals' => [], 'anomalies' => []];
            }
            $byDate[$anchor]['anomalies'][] = $anomaly;
        }

        return $byDate;
    }

    /**
     * @param array<string,mixed> $anomaly
     */
    private static function anomalyAnchorDate(array $anomaly)
    {
        $type = (string) ($anomaly['type'] ?? '');
        if ($type === 'MISSING_CIKIS' || $type === 'BRANCH_MISMATCH') {
            return (string) ($anomaly['local_date'] ?? ($anomaly['entry_local_date'] ?? ''));
        }

        return (string) ($anomaly['local_date'] ?? '');
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @param list<array<string,mixed>> $anomalies
     * @param array<string,mixed>|null $canonical
     * @param array<string,mixed> $periodContext
     * @param array<string,mixed> $derived
     * @return array<string,mixed>
     */
    private static function buildItem(
        $candidateDate,
        array $intervals,
        array $anomalies,
        $canonical,
        array $periodContext,
        $correctionPresent,
        array $derived
    ) {
        $intervalCount = count($intervals);
        $anomalyCount = count($anomalies);
        $matchedSeconds = self::sumMatchedSeconds($intervals);
        $firstEntryAt = self::firstEntryAt($intervals, $anomalies);
        $lastExitAt = self::lastExitAt($intervals, $anomalies);
        $spansMidnight = self::anySpansMidnight($intervals);
        $branchSet = self::collectBranchSet($intervals, $anomalies);
        $classification = self::classify($intervals, $anomalies, $spansMidnight, $branchSet);
        $proposed = self::buildProposedTimes($classification, $intervals);
        $autoApplicable = self::isStructurallyAutoApplicable(
            $classification,
            $intervalCount,
            $anomalyCount,
            $spansMidnight,
            $branchSet,
            $proposed,
            $correctionPresent
        );
        $comparisonStatus = self::resolveComparisonStatus(
            $proposed,
            $canonical,
            $correctionPresent,
            $periodContext,
            $classification
        );
        if ($comparisonStatus === self::COMPARE_DIFFERS_CANONICAL_TIME
            || $comparisonStatus === self::COMPARE_PERIOD_REQUIRES_REVISION
            || $comparisonStatus === self::COMPARE_APPROVED_CORRECTION_PRESENT) {
            $autoApplicable = false;
        }

        $periodState = (string) ($periodContext['state'] ?? '');
        $periodWriteLocked = !empty($periodContext['period_write_locked']);
        $canonicalWriteOpen = !empty($periodContext['canonical_write_open']);
        $canonicalWriteBlockCode = array_key_exists('canonical_write_block_code', $periodContext)
            ? $periodContext['canonical_write_block_code']
            : null;
        if ($canonicalWriteBlockCode !== null) {
            $canonicalWriteBlockCode = (string) $canonicalWriteBlockCode;
        }
        $revisionRequired = $comparisonStatus === self::COMPARE_PERIOD_REQUIRES_REVISION;
        $hasSafeProposal = $classification === self::CLASS_READY_SINGLE_INTERVAL
            && $proposed['giris_saati'] !== null
            && $proposed['cikis_saati'] !== null;
        $futureAction = self::resolveFutureAction(
            $canonicalWriteOpen,
            $comparisonStatus,
            $correctionPresent,
            $hasSafeProposal
        );

        $sourceEventIds = self::collectSourceEventIds($intervals, $anomalies);
        $entryEventIds = self::collectEntryEventIds($intervals);
        $exitEventIds = self::collectExitEventIds($intervals);

        return [
            'candidate_date' => (string) $candidateDate,
            'classification' => $classification,
            'comparison_status' => $comparisonStatus,
            'auto_applicable' => $autoApplicable,
            'suggested_presence' => $classification === self::CLASS_READY_SINGLE_INTERVAL
                ? 'QR_PRESENT'
                : null,
            'qr' => [
                'interval_count' => $intervalCount,
                'anomaly_count' => $anomalyCount,
                'matched_seconds' => $matchedSeconds,
                'first_entry_at' => $firstEntryAt,
                'last_exit_at' => $lastExitAt,
                'spans_local_midnight' => $spansMidnight,
                'source_sube_ids' => array_values($branchSet['ids']),
                'source_sube_names' => array_values($branchSet['names']),
            ],
            'proposed' => $proposed,
            'canonical' => self::mapCanonical($canonical),
            'period' => [
                'state' => $periodState,
                'period_write_locked' => $periodWriteLocked,
                'canonical_write_open' => $canonicalWriteOpen,
                'canonical_write_block_code' => $canonicalWriteBlockCode,
                'revision_required' => $revisionRequired,
                'revision_hint' => $revisionRequired ? self::REVISION_HINT : null,
                'correction_hint' => $revisionRequired ? self::CORRECTION_HINT : null,
                'future_action' => $futureAction,
            ],
            'provenance' => [
                'algorithm_version' => self::ALGORITHM_VERSION,
                'interval_algorithm_version' => self::INTERVAL_ALGORITHM_VERSION,
                'candidate_date' => (string) $candidateDate,
                'source_interval_count' => $intervalCount,
                'source_anomaly_count' => $anomalyCount,
                'source_event_ids' => $sourceEventIds,
                'entry_event_ids' => $entryEventIds,
                'exit_event_ids' => $exitEventIds,
                'source_event_count' => count($sourceEventIds),
                'source_max_event_id' => self::maxEventId($sourceEventIds, $derived),
                'qr_matched_seconds' => $matchedSeconds,
                'first_entry_at' => $firstEntryAt,
                'last_exit_at' => $lastExitAt,
                'spans_local_midnight' => $spansMidnight,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @param list<array<string,mixed>> $anomalies
     * @param array{ids:list<int>,names:list<string>} $branchSet
     */
    private static function classify(
        array $intervals,
        array $anomalies,
        $spansMidnight,
        array $branchSet
    ) {
        if (count($anomalies) > 0) {
            return self::CLASS_REVIEW_ANOMALY;
        }
        if (count($branchSet['ids']) > 1) {
            return self::CLASS_REVIEW_MULTIPLE_BRANCHES;
        }
        if ($spansMidnight) {
            return self::CLASS_REVIEW_CROSS_MIDNIGHT;
        }
        if (count($intervals) > 1) {
            return self::CLASS_REVIEW_MULTIPLE_INTERVALS;
        }
        if (count($intervals) === 1) {
            return self::CLASS_READY_SINGLE_INTERVAL;
        }

        return self::CLASS_REVIEW_ANOMALY;
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @return array{giris_saati:?string,cikis_saati:?string}
     */
    private static function buildProposedTimes($classification, array $intervals)
    {
        if ($classification === self::CLASS_READY_SINGLE_INTERVAL && count($intervals) === 1) {
            $interval = $intervals[0];

            return [
                'giris_saati' => self::localIsoToHm((string) ($interval['entry_at'] ?? '')),
                'cikis_saati' => self::localIsoToHm((string) ($interval['exit_at'] ?? '')),
            ];
        }
        if ($classification === self::CLASS_REVIEW_CROSS_MIDNIGHT && count($intervals) === 1) {
            $interval = $intervals[0];

            return [
                'giris_saati' => self::localIsoToHm((string) ($interval['entry_at'] ?? '')),
                'cikis_saati' => self::localIsoToHm((string) ($interval['exit_at'] ?? '')),
            ];
        }

        return [
            'giris_saati' => null,
            'cikis_saati' => null,
        ];
    }

    /**
     * @param array{giris_saati:?string,cikis_saati:?string} $proposed
     * @param array<string,mixed>|null $canonical
     * @param array<string,mixed> $periodContext
     */
    private static function resolveComparisonStatus(
        array $proposed,
        $canonical,
        $correctionPresent,
        array $periodContext,
        $classification
    ) {
        if ($correctionPresent) {
            return self::COMPARE_APPROVED_CORRECTION_PRESENT;
        }

        $hasSafeProposal = $classification === self::CLASS_READY_SINGLE_INTERVAL
            && $proposed['giris_saati'] !== null
            && $proposed['cikis_saati'] !== null;

        if (!$hasSafeProposal) {
            return self::COMPARE_NO_SAFE_TIME_PROPOSAL;
        }

        if ($canonical === null) {
            if (!empty($periodContext['period_write_locked'])) {
                return self::COMPARE_PERIOD_REQUIRES_REVISION;
            }

            return self::COMPARE_NO_CANONICAL_ROW;
        }

        $canonicalGiris = self::normalizeCanonicalTime($canonical['giris_saati'] ?? null);
        $canonicalCikis = self::normalizeCanonicalTime($canonical['cikis_saati'] ?? null);

        if ($canonicalGiris === $proposed['giris_saati'] && $canonicalCikis === $proposed['cikis_saati']) {
            return self::COMPARE_MATCHES_CANONICAL_TIME;
        }

        if (!empty($periodContext['period_write_locked'])) {
            return self::COMPARE_PERIOD_REQUIRES_REVISION;
        }

        return self::COMPARE_DIFFERS_CANONICAL_TIME;
    }

    private static function resolveFutureAction(
        $canonicalWriteOpen,
        $comparisonStatus,
        $correctionPresent,
        $hasSafeProposal
    ) {
        if ($correctionPresent || !$canonicalWriteOpen || !$hasSafeProposal) {
            return null;
        }
        if ($comparisonStatus === self::COMPARE_MATCHES_CANONICAL_TIME
            || $comparisonStatus === self::COMPARE_PERIOD_REQUIRES_REVISION
            || $comparisonStatus === self::COMPARE_NO_SAFE_TIME_PROPOSAL
            || $comparisonStatus === self::COMPARE_APPROVED_CORRECTION_PRESENT) {
            return null;
        }
        if ($comparisonStatus === self::COMPARE_NO_CANONICAL_ROW
            || $comparisonStatus === self::COMPARE_DIFFERS_CANONICAL_TIME) {
            return 'DIRECT_PUANTAJ_REVIEW';
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @param list<array<string,mixed>> $anomalies
     * @return array{ids:list<int>,names:list<string>}
     */
    private static function collectBranchSet(array $intervals, array $anomalies)
    {
        $ids = [];
        $names = [];
        foreach ($intervals as $interval) {
            $id = (int) ($interval['sube']['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
                $name = trim((string) ($interval['sube']['ad'] ?? ''));
                if ($name !== '') {
                    $names[$id] = $name;
                }
            }
        }
        foreach ($anomalies as $anomaly) {
            $id = (int) ($anomaly['sube']['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
                $name = trim((string) ($anomaly['sube']['ad'] ?? ''));
                if ($name !== '') {
                    $names[$id] = $name;
                }
            }
        }

        ksort($ids);

        return [
            'ids' => array_values($ids),
            'names' => array_values($names),
        ];
    }

    /**
     * @param list<array<string,mixed>> $intervals
     */
    private static function sumMatchedSeconds(array $intervals)
    {
        $sum = 0;
        foreach ($intervals as $interval) {
            $sum += (int) ($interval['duration_seconds'] ?? 0);
        }

        return $sum;
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @param list<array<string,mixed>> $anomalies
     */
    private static function firstEntryAt(array $intervals, array $anomalies)
    {
        $candidates = [];
        foreach ($intervals as $interval) {
            $at = (string) ($interval['entry_at'] ?? '');
            if ($at !== '') {
                $candidates[] = $at;
            }
        }
        foreach ($anomalies as $anomaly) {
            if ((string) ($anomaly['type'] ?? '') === 'GIRIS' || (string) ($anomaly['event_type'] ?? '') === 'GIRIS') {
                $at = (string) ($anomaly['occurred_at'] ?? '');
                if ($at !== '') {
                    $candidates[] = $at;
                }
            }
            if ((string) ($anomaly['type'] ?? '') === 'MISSING_CIKIS') {
                $at = (string) ($anomaly['occurred_at'] ?? '');
                if ($at !== '') {
                    $candidates[] = $at;
                }
            }
        }
        if (count($candidates) === 0) {
            return null;
        }
        sort($candidates);

        return $candidates[0];
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @param list<array<string,mixed>> $anomalies
     */
    private static function lastExitAt(array $intervals, array $anomalies)
    {
        $candidates = [];
        foreach ($intervals as $interval) {
            $at = (string) ($interval['exit_at'] ?? '');
            if ($at !== '') {
                $candidates[] = $at;
            }
        }
        foreach ($anomalies as $anomaly) {
            if ((string) ($anomaly['type'] ?? '') === 'MISSING_GIRIS') {
                $at = (string) ($anomaly['occurred_at'] ?? '');
                if ($at !== '') {
                    $candidates[] = $at;
                }
            }
        }
        if (count($candidates) === 0) {
            return null;
        }
        sort($candidates);

        return $candidates[count($candidates) - 1];
    }

    /**
     * @param list<array<string,mixed>> $intervals
     */
    private static function anySpansMidnight(array $intervals)
    {
        foreach ($intervals as $interval) {
            if (!empty($interval['spans_local_midnight'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{giris_saati:?string,cikis_saati:?string} $proposed
     * @param array{ids:list<int>,names:list<string>} $branchSet
     */
    private static function isStructurallyAutoApplicable(
        $classification,
        $intervalCount,
        $anomalyCount,
        $spansMidnight,
        array $branchSet,
        array $proposed,
        $correctionPresent
    ) {
        if ($correctionPresent) {
            return false;
        }
        if ($classification !== self::CLASS_READY_SINGLE_INTERVAL) {
            return false;
        }
        if ($intervalCount !== 1 || $anomalyCount !== 0 || $spansMidnight) {
            return false;
        }
        if (count($branchSet['ids']) !== 1) {
            return false;
        }

        return $proposed['giris_saati'] !== null && $proposed['cikis_saati'] !== null;
    }

    /**
     * @param array<string,mixed>|null $canonical
     * @return array<string,mixed>
     */
    private static function mapCanonical($canonical)
    {
        if ($canonical === null) {
            return [
                'exists' => false,
                'puantaj_id' => null,
                'giris_saati' => null,
                'cikis_saati' => null,
                'state' => null,
                'kontrol_durumu' => null,
            ];
        }

        return [
            'exists' => true,
            'puantaj_id' => (int) ($canonical['id'] ?? 0),
            'giris_saati' => self::normalizeCanonicalTime($canonical['giris_saati'] ?? null),
            'cikis_saati' => self::normalizeCanonicalTime($canonical['cikis_saati'] ?? null),
            'state' => isset($canonical['state']) ? (string) $canonical['state'] : null,
            'kontrol_durumu' => isset($canonical['kontrol_durumu']) ? (string) $canonical['kontrol_durumu'] : null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @param list<array<string,mixed>> $anomalies
     * @return list<int>
     */
    private static function collectSourceEventIds(array $intervals, array $anomalies)
    {
        $ids = [];
        foreach ($intervals as $interval) {
            $ids[(int) $interval['entry_event_id']] = (int) $interval['entry_event_id'];
            $ids[(int) $interval['exit_event_id']] = (int) $interval['exit_event_id'];
        }
        foreach ($anomalies as $anomaly) {
            if (isset($anomaly['event_id'])) {
                $ids[(int) $anomaly['event_id']] = (int) $anomaly['event_id'];
            }
            if (isset($anomaly['entry_event_id'])) {
                $ids[(int) $anomaly['entry_event_id']] = (int) $anomaly['entry_event_id'];
            }
            if (isset($anomaly['exit_event_id'])) {
                $ids[(int) $anomaly['exit_event_id']] = (int) $anomaly['exit_event_id'];
            }
        }
        $out = array_values($ids);
        sort($out);

        return $out;
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @return list<int>
     */
    private static function collectEntryEventIds(array $intervals)
    {
        $ids = [];
        foreach ($intervals as $interval) {
            $ids[] = (int) $interval['entry_event_id'];
        }

        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $intervals
     * @return list<int>
     */
    private static function collectExitEventIds(array $intervals)
    {
        $ids = [];
        foreach ($intervals as $interval) {
            $ids[] = (int) $interval['exit_event_id'];
        }

        return $ids;
    }

    /**
     * @param list<int> $eventIds
     * @param array<string,mixed> $derived
     */
    private static function maxEventId(array $eventIds, array $derived)
    {
        $max = null;
        foreach ($eventIds as $id) {
            $max = $max === null ? $id : max($max, $id);
        }
        if ($max === null && array_key_exists('source_max_event_id', $derived)) {
            return $derived['source_max_event_id'];
        }

        return $max;
    }

    /**
     * @param mixed $value
     */
    public static function normalizeCanonicalTime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{2}):(\d{2})/', $raw, $m)) {
            return $m[1] . ':' . $m[2];
        }

        return $raw;
    }

    private static function localIsoToHm($localIso)
    {
        $raw = trim((string) $localIso);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/T(\d{2}):(\d{2})/', $raw, $m)) {
            return $m[1] . ':' . $m[2];
        }
        try {
            $dt = new \DateTimeImmutable($raw);

            return $dt->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public static function buildSummary(array $items)
    {
        $qrEvidenceDays = count($items);
        $readySingle = 0;
        $reviewRequired = 0;
        $matchesCanonical = 0;
        $differsCanonical = 0;
        $noCanonical = 0;

        foreach ($items as $item) {
            if (($item['classification'] ?? '') === self::CLASS_READY_SINGLE_INTERVAL) {
                ++$readySingle;
            } else {
                ++$reviewRequired;
            }
            switch ($item['comparison_status'] ?? '') {
                case self::COMPARE_MATCHES_CANONICAL_TIME:
                    ++$matchesCanonical;
                    break;
                case self::COMPARE_DIFFERS_CANONICAL_TIME:
                    ++$differsCanonical;
                    break;
                case self::COMPARE_NO_CANONICAL_ROW:
                    ++$noCanonical;
                    break;
            }
        }

        return [
            'qr_evidence_days' => $qrEvidenceDays,
            'ready_single_interval_days' => $readySingle,
            'review_required_days' => $reviewRequired,
            'matches_canonical_days' => $matchesCanonical,
            'differs_canonical_days' => $differsCanonical,
            'no_canonical_row_days' => $noCanonical,
        ];
    }
}
