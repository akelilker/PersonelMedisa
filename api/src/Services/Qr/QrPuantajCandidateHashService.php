<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

/**
 * Server-owned candidate_hash for QR_PUANTAJ_DECISION_V1 stale protection.
 * Client MUST NOT compute this.
 *
 * HASH_SCHEMA_VERSION:
 * - QR_CANDIDATE_HASH_V1 was the pre-production S3F draft contract (never prod).
 * - QR_CANDIDATE_HASH_V2 is the locked S3F hardening material contract
 *   (sube_id, decision algorithm, muhur_id, dependent fields, period_write_locked,
 *   QR matched seconds / branch provenance).
 */
class QrPuantajCandidateHashService
{
    public const HASH_SCHEMA_VERSION = 'QR_CANDIDATE_HASH_V2';

    /**
     * @param array<string,mixed> $item Projection item (cosmetic UI labels ignored)
     * @return string 64-char lowercase hex SHA-256
     */
    public static function compute($personelId, $subeId, array $item)
    {
        $payload = self::materialPayload((int) $personelId, (int) $subeId, $item);

        return hash('sha256', self::canonicalJson($payload));
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function materialPayload($personelId, $subeId, array $item)
    {
        $proposed = is_array($item['proposed'] ?? null) ? $item['proposed'] : [];
        $canonical = is_array($item['canonical'] ?? null) ? $item['canonical'] : null;
        $period = is_array($item['period'] ?? null) ? $item['period'] : [];
        $provenance = is_array($item['provenance'] ?? null) ? $item['provenance'] : [];
        $qr = is_array($item['qr'] ?? null) ? $item['qr'] : [];

        $canonicalMaterial = null;
        if (is_array($canonical) && !empty($canonical['exists'])) {
            $canonicalMaterial = [
                'id' => self::nullableInt($canonical['puantaj_id'] ?? ($canonical['id'] ?? null)),
                'giris_saati' => self::nullableString($canonical['giris_saati'] ?? null),
                'cikis_saati' => self::nullableString($canonical['cikis_saati'] ?? null),
                'state' => self::nullableString($canonical['state'] ?? null),
                'kontrol_durumu' => self::nullableString($canonical['kontrol_durumu'] ?? null),
                'muhur_id' => self::nullableInt($canonical['muhur_id'] ?? null),
                'updated_at' => self::nullableString($canonical['updated_at'] ?? null),
            ];
            foreach (QrPuantajCandidateDecisionPolicy::$dependentGuardFields as $field) {
                $canonicalMaterial[$field] = self::nullableInt($canonical[$field] ?? null);
            }
        }

        $sourceEventIds = [];
        if (isset($provenance['source_event_ids']) && is_array($provenance['source_event_ids'])) {
            foreach ($provenance['source_event_ids'] as $id) {
                $sourceEventIds[] = (int) $id;
            }
            sort($sourceEventIds);
        }

        $sourceSubeIds = [];
        $rawSubeIds = $qr['source_sube_ids'] ?? ($provenance['source_sube_ids'] ?? []);
        if (is_array($rawSubeIds)) {
            foreach ($rawSubeIds as $id) {
                $sourceSubeIds[] = (int) $id;
            }
            sort($sourceSubeIds);
        }

        $matchedSeconds = self::nullableInt(
            $provenance['qr_matched_seconds'] ?? ($qr['matched_seconds'] ?? null)
        );
        $spansMidnight = array_key_exists('spans_local_midnight', $provenance)
            ? !empty($provenance['spans_local_midnight'])
            : !empty($qr['spans_local_midnight']);

        return [
            'hash_schema_version' => self::HASH_SCHEMA_VERSION,
            'personel_id' => (int) $personelId,
            'sube_id' => (int) $subeId,
            'candidate_date' => (string) ($item['candidate_date'] ?? ''),
            'algorithm_version' => (string) ($provenance['algorithm_version']
                ?? QrPuantajCandidateProjectionService::ALGORITHM_VERSION),
            'interval_algorithm_version' => (string) ($provenance['interval_algorithm_version']
                ?? QrPuantajCandidateProjectionService::INTERVAL_ALGORITHM_VERSION),
            'decision_algorithm_version' => QrPuantajCandidateDecisionPolicy::DECISION_ALGORITHM_VERSION,
            'classification' => (string) ($item['classification'] ?? ''),
            'comparison_status' => (string) ($item['comparison_status'] ?? ''),
            'proposed' => [
                'giris_saati' => self::nullableString($proposed['giris_saati'] ?? null),
                'cikis_saati' => self::nullableString($proposed['cikis_saati'] ?? null),
            ],
            'canonical' => $canonicalMaterial,
            'period' => [
                'state' => (string) ($period['state'] ?? ''),
                'period_write_locked' => !empty($period['period_write_locked']),
                'canonical_write_open' => !empty($period['canonical_write_open']),
                'canonical_write_block_code' => self::nullableString($period['canonical_write_block_code'] ?? null),
            ],
            'correction_ambiguity' => (string) ($item['comparison_status'] ?? '')
                === QrPuantajCandidateProjectionService::COMPARE_APPROVED_CORRECTION_PRESENT,
            'source_event_ids' => $sourceEventIds,
            'source_max_event_id' => self::nullableInt($provenance['source_max_event_id'] ?? null),
            'source_interval_count' => (int) ($provenance['source_interval_count'] ?? 0),
            'source_anomaly_count' => (int) ($provenance['source_anomaly_count'] ?? 0),
            'qr_matched_seconds' => $matchedSeconds,
            'spans_local_midnight' => $spansMidnight,
            'source_sube_ids' => $sourceSubeIds,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function canonicalJson(array $data)
    {
        return json_encode(self::sortKeysRecursive($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sortKeysRecursive($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::sortKeysRecursive($item);
            }

            return $out;
        }
        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::sortKeysRecursive($item);
        }

        return $out;
    }

    private static function nullableString($value)
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    private static function nullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
