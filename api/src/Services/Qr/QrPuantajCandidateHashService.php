<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

/**
 * Server-owned candidate_hash for QR_PUANTAJ_DECISION_V1 stale protection.
 * Client MUST NOT compute this.
 */
class QrPuantajCandidateHashService
{
    public const HASH_SCHEMA_VERSION = 'QR_CANDIDATE_HASH_V1';

    /**
     * @param array<string,mixed> $item Projection item (cosmetic UI labels ignored)
     * @return string 64-char lowercase hex SHA-256
     */
    public static function compute($personelId, array $item)
    {
        $payload = self::materialPayload((int) $personelId, $item);

        return hash('sha256', self::canonicalJson($payload));
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public static function materialPayload($personelId, array $item)
    {
        $proposed = is_array($item['proposed'] ?? null) ? $item['proposed'] : [];
        $canonical = is_array($item['canonical'] ?? null) ? $item['canonical'] : null;
        $period = is_array($item['period'] ?? null) ? $item['period'] : [];
        $provenance = is_array($item['provenance'] ?? null) ? $item['provenance'] : [];

        $canonicalMaterial = null;
        if (is_array($canonical) && !empty($canonical['exists'])) {
            $canonicalMaterial = [
                'id' => self::nullableInt($canonical['puantaj_id'] ?? ($canonical['id'] ?? null)),
                'giris_saati' => self::nullableString($canonical['giris_saati'] ?? null),
                'cikis_saati' => self::nullableString($canonical['cikis_saati'] ?? null),
                'state' => self::nullableString($canonical['state'] ?? null),
                'kontrol_durumu' => self::nullableString($canonical['kontrol_durumu'] ?? null),
                'updated_at' => self::nullableString($canonical['updated_at'] ?? null),
            ];
        }

        $sourceEventIds = [];
        if (isset($provenance['source_event_ids']) && is_array($provenance['source_event_ids'])) {
            foreach ($provenance['source_event_ids'] as $id) {
                $sourceEventIds[] = (int) $id;
            }
            sort($sourceEventIds);
        }

        return [
            'hash_schema_version' => self::HASH_SCHEMA_VERSION,
            'personel_id' => (int) $personelId,
            'candidate_date' => (string) ($item['candidate_date'] ?? ''),
            'algorithm_version' => (string) ($provenance['algorithm_version']
                ?? QrPuantajCandidateProjectionService::ALGORITHM_VERSION),
            'interval_algorithm_version' => (string) ($provenance['interval_algorithm_version']
                ?? QrPuantajCandidateProjectionService::INTERVAL_ALGORITHM_VERSION),
            'classification' => (string) ($item['classification'] ?? ''),
            'comparison_status' => (string) ($item['comparison_status'] ?? ''),
            'proposed' => [
                'giris_saati' => self::nullableString($proposed['giris_saati'] ?? null),
                'cikis_saati' => self::nullableString($proposed['cikis_saati'] ?? null),
            ],
            'canonical' => $canonicalMaterial,
            'period' => [
                'state' => (string) ($period['state'] ?? ''),
                'canonical_write_open' => !empty($period['canonical_write_open']),
                'canonical_write_block_code' => self::nullableString($period['canonical_write_block_code'] ?? null),
            ],
            'correction_ambiguity' => (string) ($item['comparison_status'] ?? '')
                === QrPuantajCandidateProjectionService::COMPARE_APPROVED_CORRECTION_PRESENT,
            'source_event_ids' => $sourceEventIds,
            'source_max_event_id' => self::nullableInt($provenance['source_max_event_id'] ?? null),
            'source_interval_count' => (int) ($provenance['source_interval_count'] ?? 0),
            'source_anomaly_count' => (int) ($provenance['source_anomaly_count'] ?? 0),
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
