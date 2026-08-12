<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use PDO;

/**
 * Self-scoped QR interval read model (S3D).
 * Request-time derivation only — no interval table writes.
 */
class QrAttendanceIntervalReadService
{
    /**
     * @return array<string,mixed>
     */
    public static function listForSelf(PDO $pdo, $personelId, $from, $to)
    {
        QrAttendanceEventService::assertSchemaReady($pdo);
        $personelId = (int) $personelId;
        $range = QrAttendanceEventService::businessDateRangeToUtc($from, $to);

        $events = self::loadEventsWithBoundaryContext(
            $pdo,
            $personelId,
            $range['from_utc'],
            $range['to_exclusive_utc']
        ); // also used by S3E candidate read

        $derived = QrAttendanceIntervalDerivationService::derive($events);
        $filtered = QrAttendanceIntervalDerivationService::filterToBusinessRange(
            $derived,
            $range['from'],
            $range['to']
        );

        return self::publicResponse($filtered, $range['from'], $range['to']);
    }

    /**
     * Indexed raw load with previous/next boundary context (S3D + S3E reuse).
     *
     * @return list<array<string,mixed>>
     */
    public static function loadEventsWithBoundaryContext(PDO $pdo, $personelId, $fromUtc, $toExclusiveUtc)
    {
        $personelId = (int) $personelId;
        $rows = [];

        // Previous = last event strictly before from_utc
        $prevStmt = $pdo->prepare(
            'SELECT e.id, e.event_type, e.occurred_at_utc, e.sube_id, e.user_id, s.ad AS sube_ad
             FROM qr_attendance_events e
             LEFT JOIN subeler s ON s.id = e.sube_id
             WHERE e.personel_id = :personel_id
               AND e.occurred_at_utc < :from_utc
             ORDER BY e.occurred_at_utc DESC, e.id DESC
             LIMIT 1'
        );
        $prevStmt->execute([
            'personel_id' => $personelId,
            'from_utc' => $fromUtc,
        ]);
        $prev = $prevStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($prev)) {
            $rows[] = $prev;
        }

        $inStmt = $pdo->prepare(
            'SELECT e.id, e.event_type, e.occurred_at_utc, e.sube_id, e.user_id, s.ad AS sube_ad
             FROM qr_attendance_events e
             LEFT JOIN subeler s ON s.id = e.sube_id
             WHERE e.personel_id = :personel_id
               AND e.occurred_at_utc >= :from_utc
               AND e.occurred_at_utc < :to_utc
             ORDER BY e.occurred_at_utc ASC, e.id ASC'
        );
        $inStmt->execute([
            'personel_id' => $personelId,
            'from_utc' => $fromUtc,
            'to_utc' => $toExclusiveUtc,
        ]);
        while ($row = $inStmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        $nextStmt = $pdo->prepare(
            'SELECT e.id, e.event_type, e.occurred_at_utc, e.sube_id, e.user_id, s.ad AS sube_ad
             FROM qr_attendance_events e
             LEFT JOIN subeler s ON s.id = e.sube_id
             WHERE e.personel_id = :personel_id
               AND e.occurred_at_utc >= :to_utc
             ORDER BY e.occurred_at_utc ASC, e.id ASC
             LIMIT 1'
        );
        $nextStmt->execute([
            'personel_id' => $personelId,
            'to_utc' => $toExclusiveUtc,
        ]);
        $next = $nextStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($next)) {
            $rows[] = $next;
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $filtered
     * @return array<string,mixed>
     */
    private static function publicResponse(array $filtered, $from, $to)
    {
        $intervals = [];
        foreach ($filtered['intervals'] as $interval) {
            $intervals[] = [
                'entry_event_id' => (int) $interval['entry_event_id'],
                'exit_event_id' => (int) $interval['exit_event_id'],
                'entry_at' => (string) $interval['entry_at'],
                'exit_at' => (string) $interval['exit_at'],
                'entry_local_date' => (string) $interval['entry_local_date'],
                'exit_local_date' => (string) $interval['exit_local_date'],
                'spans_local_midnight' => (bool) $interval['spans_local_midnight'],
                'duration_seconds' => (int) $interval['duration_seconds'],
                'sube' => [
                    'id' => (int) ($interval['sube']['id'] ?? 0),
                    'ad' => (string) ($interval['sube']['ad'] ?? ''),
                ],
            ];
        }

        $anomalies = [];
        foreach ($filtered['anomalies'] as $anomaly) {
            $type = (string) ($anomaly['type'] ?? '');
            if ($type === 'BRANCH_MISMATCH') {
                $anomalies[] = [
                    'type' => 'BRANCH_MISMATCH',
                    'reason' => 'BRANCH_MISMATCH',
                    'entry_event_id' => (int) ($anomaly['entry_event_id'] ?? 0),
                    'exit_event_id' => (int) ($anomaly['exit_event_id'] ?? 0),
                    'occurred_at' => (string) ($anomaly['occurred_at'] ?? ''),
                    'local_date' => (string) ($anomaly['local_date'] ?? ''),
                    'entry_sube' => [
                        'id' => (int) ($anomaly['entry_sube']['id'] ?? 0),
                        'ad' => (string) ($anomaly['entry_sube']['ad'] ?? ''),
                    ],
                    'exit_sube' => [
                        'id' => (int) ($anomaly['exit_sube']['id'] ?? 0),
                        'ad' => (string) ($anomaly['exit_sube']['ad'] ?? ''),
                    ],
                    'correction_hint' => (string) ($anomaly['correction_hint'] ?? QrAttendanceIntervalDerivationService::CORRECTION_HINT),
                ];
                continue;
            }

            $anomalies[] = [
                'type' => $type,
                'reason' => (string) ($anomaly['reason'] ?? $type),
                'event_id' => (int) ($anomaly['event_id'] ?? 0),
                'event_type' => (string) ($anomaly['event_type'] ?? ''),
                'occurred_at' => (string) ($anomaly['occurred_at'] ?? ''),
                'local_date' => (string) ($anomaly['local_date'] ?? ''),
                'sube' => [
                    'id' => (int) ($anomaly['sube']['id'] ?? 0),
                    'ad' => (string) ($anomaly['sube']['ad'] ?? ''),
                ],
                'correction_hint' => (string) ($anomaly['correction_hint'] ?? QrAttendanceIntervalDerivationService::CORRECTION_HINT),
            ];
        }

        return [
            'from' => (string) $from,
            'to' => (string) $to,
            'algorithm_version' => (string) ($filtered['algorithm_version'] ?? QrAttendanceIntervalDerivationService::ALGORITHM_VERSION),
            'intervals' => $intervals,
            'anomalies' => $anomalies,
            'summary' => [
                'complete_interval_count' => (int) ($filtered['summary']['complete_interval_count'] ?? 0),
                'anomaly_count' => (int) ($filtered['summary']['anomaly_count'] ?? 0),
                'complete_duration_seconds' => (int) ($filtered['summary']['complete_duration_seconds'] ?? 0),
            ],
            'source_event_count' => (int) ($filtered['source_event_count'] ?? 0),
            'source_max_event_id' => $filtered['source_max_event_id'] === null
                ? null
                : (int) $filtered['source_max_event_id'],
        ];
    }
}
