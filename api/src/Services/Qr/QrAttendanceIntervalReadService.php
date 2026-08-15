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
    public const MANAGER_MAX_RANGE_DAYS = 31;

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
     * Manager operational read model. It derives intervals from the same raw
     * events as self-service and never writes events, intervals, or puantaj.
     *
     * @param array<int,int> $allowedSubeIds
     * @return array<string,mixed>
     */
    public static function listForManager(PDO $pdo, $scopeSubeId, array $allowedSubeIds, $personelId, $from, $to, $limit, $offset)
    {
        QrAttendanceEventService::assertSchemaReady($pdo);
        $range = self::resolveManagerRange($from, $to);
        $limit = max(1, min(100, (int) $limit));
        $offset = max(0, (int) $offset);
        $rangeFrom = (new \DateTimeImmutable($range['from']))->modify('-1 day')->format('Y-m-d');
        $rangeTo = (new \DateTimeImmutable($range['to']))->modify('+1 day')->format('Y-m-d');
        $utc = QrAttendanceEventService::businessDateRangeToUtc($rangeFrom, $rangeTo);

        $where = [
            'p.aktif_durum = \'AKTIF\'',
            'e.occurred_at_utc >= :from_utc',
            'e.occurred_at_utc < :to_utc',
        ];
        $params = [
            'from_utc' => $utc['from_utc'],
            'to_utc' => $utc['to_exclusive_utc'],
        ];
        if ((int) $personelId > 0) {
            $where[] = 'p.id = :personel_id';
            $params['personel_id'] = (int) $personelId;
        }
        if ($scopeSubeId !== null) {
            $where[] = 'p.sube_id = :scope_sube_id';
            $params['scope_sube_id'] = (int) $scopeSubeId;
        } elseif ($allowedSubeIds) {
            $keys = [];
            foreach (array_values($allowedSubeIds) as $index => $subeId) {
                $key = 'allowed_sube_' . $index;
                $keys[] = ':' . $key;
                $params[$key] = (int) $subeId;
            }
            $where[] = 'p.sube_id IN (' . implode(', ', $keys) . ')';
        }

        $baseSql = ' FROM qr_attendance_events e
            INNER JOIN personeller p ON p.id = e.personel_id
            LEFT JOIN subeler event_s ON event_s.id = e.sube_id
            LEFT JOIN subeler personel_s ON personel_s.id = p.sube_id
            WHERE ' . implode(' AND ', $where);
        $personStmt = $pdo->prepare(
            'SELECT DISTINCT p.id ' . $baseSql . ' ORDER BY p.id ASC'
        );
        foreach ($params as $key => $value) {
            $personStmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $personStmt->execute();
        $personIds = array_map('intval', $personStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$personIds) {
            return [
                'from' => $range['from'], 'to' => $range['to'], 'items' => [], 'total' => 0,
                'limit' => $limit, 'offset' => $offset, 'has_next' => false,
                'algorithm_version' => QrAttendanceIntervalDerivationService::ALGORITHM_VERSION,
            ];
        }
        $personKeys = [];
        $eventParams = $params;
        foreach ($personIds as $index => $id) {
            $key = 'page_personel_' . $index;
            $personKeys[] = ':' . $key;
            $eventParams[$key] = $id;
        }
        $stmt = $pdo->prepare(
            'SELECT e.id, e.personel_id, e.event_type, e.occurred_at_utc, e.sube_id,
                    e.user_id, event_s.ad AS event_sube_ad,
                    p.ad, p.soyad, p.sicil_no, p.sube_id AS personel_sube_id,
                    personel_s.ad AS personel_sube_ad
             ' . $baseSql . ' AND p.id IN (' . implode(', ', $personKeys) . ')
             ORDER BY e.personel_id ASC, e.occurred_at_utc ASC, e.id ASC'
        );
        foreach ($eventParams as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();

        $eventsByPersonel = [];
        $people = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) $row['personel_id'];
            $people[$id] = [
                'personel_id' => $id,
                'ad_soyad' => trim((string) $row['ad'] . ' ' . (string) $row['soyad']),
                'sicil_no' => $row['sicil_no'] ?? null,
                'sube_id' => (int) $row['personel_sube_id'],
                'sube' => (string) ($row['personel_sube_ad'] ?? ''),
            ];
            $eventsByPersonel[$id][] = [
                'id' => (int) $row['id'],
                'event_type' => (string) $row['event_type'],
                'occurred_at_utc' => (string) $row['occurred_at_utc'],
                'sube_id' => (int) $row['sube_id'],
                'sube_ad' => (string) ($row['event_sube_ad'] ?? ''),
                'user_id' => (int) $row['user_id'],
            ];
        }

        $items = [];
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        $businessDates = self::managerBusinessDates($range['from'], $range['to']);
        foreach ($people as $id => $person) {
            foreach ($businessDates as $businessDate) {
                $items[] = self::mapManagerBusinessDateRow(
                    $person,
                    $eventsByPersonel[$id] ?? [],
                    $businessDate,
                    $today
                );
            }
        }

        $items = array_values(array_filter($items));
        usort($items, static function (array $a, array $b): int {
            $dateCompare = strcmp((string) $b['date_from'], (string) $a['date_from']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return (int) $a['personel_id'] <=> (int) $b['personel_id'];
        });
        $total = count($items);
        $items = array_slice($items, $offset, $limit);

        return [
            'from' => $range['from'],
            'to' => $range['to'],
            'items' => $items,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_next' => $offset + count($items) < $total,
            'algorithm_version' => QrAttendanceIntervalDerivationService::ALGORITHM_VERSION,
        ];
    }

    /** @return list<string> */
    private static function managerBusinessDates(string $from, string $to): array
    {
        $dates = [];
        $cursor = new \DateTimeImmutable($from, new \DateTimeZone('Europe/Istanbul'));
        $end = new \DateTimeImmutable($to, new \DateTimeZone('Europe/Istanbul'));
        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /** @param list<array<string,mixed>> $events @return array<string,mixed>|null */
    private static function mapManagerBusinessDateRow(array $person, array $events, string $businessDate, string $today): ?array
    {
        $windowStart = (new \DateTimeImmutable($businessDate, new \DateTimeZone('Europe/Istanbul')))
            ->modify('-1 day')
            ->format('Y-m-d');
        $windowEnd = (new \DateTimeImmutable($businessDate, new \DateTimeZone('Europe/Istanbul')))
            ->modify('+1 day')
            ->format('Y-m-d');
        $dayEvents = array_values(array_filter($events, static function (array $event) use ($windowStart, $windowEnd): bool {
            $localDate = self::eventLocalDate($event['occurred_at_utc'] ?? '');

            return $localDate >= $windowStart && $localDate <= $windowEnd;
        }));
        $localEvents = array_values(array_filter($events, static function (array $event) use ($businessDate): bool {
            return self::eventLocalDate($event['occurred_at_utc'] ?? '') === $businessDate;
        }));

        $derived = QrAttendanceIntervalDerivationService::filterToBusinessRange(
            QrAttendanceIntervalDerivationService::derive($dayEvents),
            $businessDate,
            $businessDate
        );
        if ($localEvents === [] && $derived['intervals'] === [] && $derived['anomalies'] === []) {
            return null;
        }

        $anomalyTypes = array_values(array_unique(array_map(
            static fn (array $anomaly): string => (string) ($anomaly['type'] ?? ''),
            $derived['anomalies']
        )));
        usort($localEvents, static fn (array $a, array $b): int =>
            strcmp((string) ($a['occurred_at_utc'] ?? ''), (string) ($b['occurred_at_utc'] ?? ''))
        );
        $last = $localEvents ? $localEvents[count($localEvents) - 1] : null;
        $stateEvents = array_values(array_filter($dayEvents, static function (array $event) use ($businessDate): bool {
            return self::eventLocalDate($event['occurred_at_utc'] ?? '') <= $businessDate;
        }));
        usort($stateEvents, static fn (array $a, array $b): int =>
            strcmp((string) ($a['occurred_at_utc'] ?? ''), (string) ($b['occurred_at_utc'] ?? ''))
        );
        $stateLast = $stateEvents ? $stateEvents[count($stateEvents) - 1] : null;

        return $person + [
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'first_entry' => self::firstIntervalTime($derived['intervals']),
            'last_exit' => self::lastIntervalTime($derived['intervals']),
            'last_movement' => $last ? self::eventLocalIso($last['occurred_at_utc']) : null,
            'last_movement_type' => $last['event_type'] ?? null,
            'inside' => $businessDate === $today && $stateLast !== null && $stateLast['event_type'] === 'GIRIS',
            'interval_count' => count($derived['intervals']),
            'missing_entry' => in_array('MISSING_GIRIS', $anomalyTypes, true),
            'missing_exit' => in_array('MISSING_CIKIS', $anomalyTypes, true),
            'branch_mismatch' => in_array('BRANCH_MISMATCH', $anomalyTypes, true),
            'anomalies' => $anomalyTypes,
            'matched_seconds' => (int) ($derived['summary']['complete_duration_seconds'] ?? 0),
            'source_event_count' => count($localEvents),
        ];
    }

    private static function resolveManagerRange($from, $to)
    {
        $tz = new \DateTimeZone('Europe/Istanbul');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $from = trim((string) $from) !== '' ? trim((string) $from) : $today;
        $to = trim((string) $to) !== '' ? trim((string) $to) : $from;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
            || !checkdate((int) substr($from, 5, 2), (int) substr($from, 8, 2), (int) substr($from, 0, 4))
            || !checkdate((int) substr($to, 5, 2), (int) substr($to, 8, 2), (int) substr($to, 0, 4))
            || $from > $to
        ) {
            throw new \InvalidArgumentException('QR tarih araligi gecersiz.');
        }
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        if ($days > self::MANAGER_MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException('QR tarih araligi en fazla 31 gun olabilir.');
        }
        return ['from' => $from, 'to' => $to];
    }

    private static function firstIntervalTime(array $intervals)
    {
        return $intervals ? (string) $intervals[0]['entry_at'] : null;
    }

    private static function lastIntervalTime(array $intervals)
    {
        return $intervals ? (string) $intervals[count($intervals) - 1]['exit_at'] : null;
    }

    private static function eventLocalIso($utc)
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('c');
    }

    private static function eventLocalDate($utc): string
    {
        return (new \DateTimeImmutable((string) $utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Istanbul'))
            ->format('Y-m-d');
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
