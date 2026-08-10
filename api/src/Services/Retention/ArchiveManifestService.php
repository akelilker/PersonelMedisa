<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use DateTime;
use PDO;

/**
 * Idempotent archive manifest upsert + source integrity verification.
 * Does NOT copy full personel rows.
 */
class ArchiveManifestService
{
    public const INTEGRITY_OK = 'OK';
    public const INTEGRITY_CHANGED = 'CHANGED';
    public const INTEGRITY_UNKNOWN = 'UNKNOWN';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function upsertManifest(PDO $pdo, array $payload, $createdBy = null)
    {
        $entityType = trim((string) ($payload['entity_type'] ?? ''));
        $recordId = (int) ($payload['record_id'] ?? 0);
        $category = trim((string) ($payload['record_category'] ?? ''));
        $sourceIdentity = trim((string) ($payload['source_version_identity'] ?? ''));
        $triggerType = trim((string) ($payload['trigger_type'] ?? ''));
        $triggerDate = trim((string) ($payload['trigger_date'] ?? ''));
        $retentionUntil = trim((string) ($payload['retention_until'] ?? ''));
        $sha = isset($payload['source_sha256']) ? trim((string) $payload['source_sha256']) : null;
        if ($sha === '') {
            $sha = null;
        }
        $personelId = isset($payload['personel_id']) ? (int) $payload['personel_id'] : null;
        if ($personelId !== null && $personelId <= 0) {
            $personelId = null;
        }

        if ($entityType === '' || $recordId <= 0 || $category === '' || $sourceIdentity === '') {
            throw new \RuntimeException('ARCHIVE_MANIFEST_INVALID');
        }
        if (!in_array($triggerType, [
            RetentionCategories::TRIGGER_PERIOD_CLOSURE,
            RetentionCategories::TRIGGER_TERMINATION_DATE,
        ], true)) {
            throw new \RuntimeException('ARCHIVE_MANIFEST_TRIGGER_INVALID');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $triggerDate)) {
            throw new \RuntimeException('ARCHIVE_MANIFEST_TRIGGER_DATE_INVALID');
        }
        if ($retentionUntil === '') {
            $dt = DateTime::createFromFormat('Y-m-d', $triggerDate);
            if (!$dt) {
                throw new \RuntimeException('ARCHIVE_MANIFEST_TRIGGER_DATE_INVALID');
            }
            $retentionUntil = RetentionPolicyService::calculateRetentionUntil($dt);
        }

        $integrity = $sha !== null ? self::INTEGRITY_OK : self::INTEGRITY_UNKNOWN;
        $createdBy = $createdBy !== null ? (int) $createdBy : null;
        if ($createdBy !== null && $createdBy <= 0) {
            $createdBy = null;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO arsiv_manifestleri
                (entity_type, record_id, personel_id, record_category, source_version_identity,
                 trigger_type, trigger_date, retention_until, source_sha256, integrity_status, created_by)
             VALUES
                (:entity_type, :record_id, :personel_id, :record_category, :source_identity,
                 :trigger_type, :trigger_date, :retention_until, :sha, :integrity, :created_by)
             ON DUPLICATE KEY UPDATE
                personel_id = VALUES(personel_id),
                source_version_identity = VALUES(source_version_identity),
                trigger_type = VALUES(trigger_type),
                trigger_date = VALUES(trigger_date),
                retention_until = VALUES(retention_until),
                source_sha256 = COALESCE(VALUES(source_sha256), source_sha256),
                integrity_status = VALUES(integrity_status)'
        );
        $stmt->execute([
            'entity_type' => $entityType,
            'record_id' => $recordId,
            'personel_id' => $personelId,
            'record_category' => $category,
            'source_identity' => $sourceIdentity,
            'trigger_type' => $triggerType,
            'trigger_date' => $triggerDate,
            'retention_until' => $retentionUntil,
            'sha' => $sha,
            'integrity' => $integrity,
            'created_by' => $createdBy,
        ]);

        return self::find($pdo, $entityType, $recordId, $category);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(PDO $pdo, $entityType, $recordId, $category)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM arsiv_manifestleri
             WHERE entity_type = :entity_type AND record_id = :record_id AND record_category = :category
             LIMIT 1'
        );
        $stmt->execute([
            'entity_type' => (string) $entityType,
            'record_id' => (int) $recordId,
            'category' => (string) $category,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Compare stored sha256 with current. Mismatch → ARCHIVE_SOURCE_INTEGRITY_CHANGED.
     *
     * @return string OK|UNKNOWN|ARCHIVE_SOURCE_INTEGRITY_CHANGED
     */
    public static function verifySourceIntegrity(PDO $pdo, $entityType, $recordId, $category, $currentSha256 = null)
    {
        $manifest = self::find($pdo, $entityType, $recordId, $category);
        if (!$manifest) {
            return self::INTEGRITY_UNKNOWN;
        }

        $stored = isset($manifest['source_sha256']) ? (string) $manifest['source_sha256'] : '';
        if ($stored === '' || $currentSha256 === null || $currentSha256 === '') {
            return self::INTEGRITY_UNKNOWN;
        }

        if (!hash_equals(strtolower($stored), strtolower((string) $currentSha256))) {
            $upd = $pdo->prepare(
                "UPDATE arsiv_manifestleri SET integrity_status = 'CHANGED' WHERE id = :id"
            );
            $upd->execute(['id' => (int) $manifest['id']]);

            return RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED;
        }

        return self::INTEGRITY_OK;
    }
}
