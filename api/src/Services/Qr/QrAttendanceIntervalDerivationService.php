<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

/**
 * Pure deterministic QR raw-event → interval derivation (S3D / QR_INTERVAL_V1).
 *
 * No SQL / Auth / HTTP. No synthetic events. No max-duration cutoff.
 * No midnight split. No gunluk_puantaj / revision writes.
 */
class QrAttendanceIntervalDerivationService
{
    public const ALGORITHM_VERSION = 'QR_INTERVAL_V1';
    public const CORRECTION_HINT = 'GIRIS_CIKIS_DUZELTME';

    /**
     * @param list<array<string,mixed>> $orderedEvents ASC by occurred_at_utc, id
     * @return array{
     *   algorithm_version:string,
     *   intervals:list<array<string,mixed>>,
     *   anomalies:list<array<string,mixed>>,
     *   summary:array{complete_interval_count:int,anomaly_count:int,complete_duration_seconds:int},
     *   source_event_count:int,
     *   source_max_event_id:int|null
     * }
     */
    public static function derive(array $orderedEvents)
    {
        $events = array_values($orderedEvents);
        usort($events, static function (array $a, array $b) {
            $ta = (string) ($a['occurred_at_utc'] ?? '');
            $tb = (string) ($b['occurred_at_utc'] ?? '');
            if ($ta !== $tb) {
                return $ta < $tb ? -1 : 1;
            }
            $ida = (int) ($a['id'] ?? 0);
            $idb = (int) ($b['id'] ?? 0);
            if ($ida === $idb) {
                return 0;
            }

            return $ida < $idb ? -1 : 1;
        });

        $open = null;
        $intervals = [];
        $anomalies = [];
        $maxId = null;

        foreach ($events as $event) {
            $normalized = self::normalizeEvent($event);
            $id = (int) $normalized['id'];
            $maxId = $maxId === null ? $id : max($maxId, $id);
            $type = (string) $normalized['event_type'];

            if ($type === 'GIRIS') {
                if ($open !== null) {
                    $anomalies[] = self::missingCikisAnomaly($open);
                }
                $open = $normalized;
                continue;
            }

            if ($type === 'CIKIS') {
                if ($open === null) {
                    $anomalies[] = self::missingGirisAnomaly($normalized);
                    continue;
                }

                if ((int) $open['sube_id'] !== (int) $normalized['sube_id']) {
                    $anomalies[] = self::branchMismatchAnomaly($open, $normalized);
                    $open = null;
                    continue;
                }

                $intervals[] = self::completeInterval($open, $normalized);
                $open = null;
                continue;
            }

            // Explicit GIRIS/CIKIS only — ignore unknown types without mutation.
        }

        if ($open !== null) {
            $anomalies[] = self::missingCikisAnomaly($open);
        }

        $durationSum = 0;
        foreach ($intervals as $interval) {
            $durationSum += (int) $interval['duration_seconds'];
        }

        return [
            'algorithm_version' => self::ALGORITHM_VERSION,
            'intervals' => $intervals,
            'anomalies' => $anomalies,
            'summary' => [
                'complete_interval_count' => count($intervals),
                'anomaly_count' => count($anomalies),
                'complete_duration_seconds' => $durationSum,
            ],
            'source_event_count' => count($events),
            'source_max_event_id' => $maxId,
        ];
    }

    /**
     * Filter derived projection to requested inclusive business YMD window.
     *
     * @param array<string,mixed> $derived
     * @return array<string,mixed>
     */
    public static function filterToBusinessRange(array $derived, $fromYmd, $toYmd)
    {
        $fromYmd = (string) $fromYmd;
        $toYmd = (string) $toYmd;
        $intervals = [];
        foreach ($derived['intervals'] as $interval) {
            $entryDate = (string) ($interval['entry_local_date'] ?? '');
            if ($entryDate >= $fromYmd && $entryDate <= $toYmd) {
                $intervals[] = $interval;
            }
        }

        $anomalies = [];
        foreach ($derived['anomalies'] as $anomaly) {
            $type = (string) ($anomaly['type'] ?? '');
            if ($type === 'MISSING_CIKIS' || $type === 'BRANCH_MISMATCH') {
                $local = (string) ($anomaly['local_date'] ?? ($anomaly['entry_local_date'] ?? ''));
                if ($local >= $fromYmd && $local <= $toYmd) {
                    $anomalies[] = $anomaly;
                }
                continue;
            }
            if ($type === 'MISSING_GIRIS') {
                $local = (string) ($anomaly['local_date'] ?? '');
                if ($local >= $fromYmd && $local <= $toYmd) {
                    $anomalies[] = $anomaly;
                }
            }
        }

        $durationSum = 0;
        foreach ($intervals as $interval) {
            $durationSum += (int) $interval['duration_seconds'];
        }

        return [
            'algorithm_version' => (string) ($derived['algorithm_version'] ?? self::ALGORITHM_VERSION),
            'intervals' => $intervals,
            'anomalies' => $anomalies,
            'summary' => [
                'complete_interval_count' => count($intervals),
                'anomaly_count' => count($anomalies),
                'complete_duration_seconds' => $durationSum,
            ],
            'source_event_count' => (int) ($derived['source_event_count'] ?? 0),
            'source_max_event_id' => array_key_exists('source_max_event_id', $derived)
                ? $derived['source_max_event_id']
                : null,
        ];
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private static function normalizeEvent(array $event)
    {
        $utc = (string) ($event['occurred_at_utc'] ?? '');
        $local = self::utcToLocalParts($utc);

        return [
            'id' => (int) ($event['id'] ?? 0),
            'event_type' => strtoupper(trim((string) ($event['event_type'] ?? ''))),
            'occurred_at_utc' => $utc,
            'sube_id' => (int) ($event['sube_id'] ?? 0),
            'user_id' => (int) ($event['user_id'] ?? 0),
            'sube_ad' => (string) ($event['sube_ad'] ?? ''),
            'local_date' => $local['local_date'],
            'local_iso' => $local['local_iso'],
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $exit
     * @return array<string,mixed>
     */
    private static function completeInterval(array $entry, array $exit)
    {
        $duration = self::durationSeconds(
            (string) $entry['occurred_at_utc'],
            (string) $exit['occurred_at_utc']
        );
        $entryDate = (string) $entry['local_date'];
        $exitDate = (string) $exit['local_date'];

        return [
            'entry_event_id' => (int) $entry['id'],
            'exit_event_id' => (int) $exit['id'],
            'entry_at' => (string) $entry['local_iso'],
            'exit_at' => (string) $exit['local_iso'],
            'entry_occurred_at_utc' => (string) $entry['occurred_at_utc'],
            'exit_occurred_at_utc' => (string) $exit['occurred_at_utc'],
            'entry_local_date' => $entryDate,
            'exit_local_date' => $exitDate,
            'spans_local_midnight' => $entryDate !== $exitDate,
            'duration_seconds' => $duration,
            'sube' => [
                'id' => (int) $entry['sube_id'],
                'ad' => (string) ($entry['sube_ad'] !== '' ? $entry['sube_ad'] : ($exit['sube_ad'] ?? '')),
            ],
            // Internal provenance (not required in public self response).
            'entry_user_id' => (int) ($entry['user_id'] ?? 0),
            'exit_user_id' => (int) ($exit['user_id'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $giris
     * @return array<string,mixed>
     */
    private static function missingCikisAnomaly(array $giris)
    {
        return [
            'type' => 'MISSING_CIKIS',
            'reason' => 'MISSING_CIKIS',
            'event_id' => (int) $giris['id'],
            'event_type' => 'GIRIS',
            'occurred_at' => (string) $giris['local_iso'],
            'local_date' => (string) $giris['local_date'],
            'sube' => [
                'id' => (int) $giris['sube_id'],
                'ad' => (string) ($giris['sube_ad'] ?? ''),
            ],
            'correction_hint' => self::CORRECTION_HINT,
        ];
    }

    /**
     * @param array<string,mixed> $cikis
     * @return array<string,mixed>
     */
    private static function missingGirisAnomaly(array $cikis)
    {
        return [
            'type' => 'MISSING_GIRIS',
            'reason' => 'MISSING_GIRIS',
            'event_id' => (int) $cikis['id'],
            'event_type' => 'CIKIS',
            'occurred_at' => (string) $cikis['local_iso'],
            'local_date' => (string) $cikis['local_date'],
            'sube' => [
                'id' => (int) $cikis['sube_id'],
                'ad' => (string) ($cikis['sube_ad'] ?? ''),
            ],
            'correction_hint' => self::CORRECTION_HINT,
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $exit
     * @return array<string,mixed>
     */
    private static function branchMismatchAnomaly(array $entry, array $exit)
    {
        return [
            'type' => 'BRANCH_MISMATCH',
            'reason' => 'BRANCH_MISMATCH',
            'entry_event_id' => (int) $entry['id'],
            'exit_event_id' => (int) $exit['id'],
            'entry_local_date' => (string) $entry['local_date'],
            'exit_local_date' => (string) $exit['local_date'],
            'local_date' => (string) $entry['local_date'],
            'occurred_at' => (string) $entry['local_iso'],
            'entry_sube' => [
                'id' => (int) $entry['sube_id'],
                'ad' => (string) ($entry['sube_ad'] ?? ''),
            ],
            'exit_sube' => [
                'id' => (int) $exit['sube_id'],
                'ad' => (string) ($exit['sube_ad'] ?? ''),
            ],
            'sube' => [
                'id' => (int) $entry['sube_id'],
                'ad' => (string) ($entry['sube_ad'] ?? ''),
            ],
            'correction_hint' => self::CORRECTION_HINT,
        ];
    }

    private static function durationSeconds($fromUtc, $toUtc)
    {
        try {
            $from = new \DateTimeImmutable((string) $fromUtc, new \DateTimeZone('UTC'));
            $to = new \DateTimeImmutable((string) $toUtc, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return 0;
        }
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        if ($seconds < 0) {
            return 0;
        }

        return (int) $seconds;
    }

    /**
     * @return array{local_date:string,local_iso:string}
     */
    private static function utcToLocalParts($utc)
    {
        $raw = trim((string) $utc);
        if ($raw === '') {
            return ['local_date' => '', 'local_iso' => ''];
        }
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            $local = $dt->setTimezone(new \DateTimeZone('Europe/Istanbul'));

            return [
                'local_date' => $local->format('Y-m-d'),
                'local_iso' => $local->format('c'),
            ];
        } catch (\Throwable $e) {
            return ['local_date' => '', 'local_iso' => $raw];
        }
    }
}
