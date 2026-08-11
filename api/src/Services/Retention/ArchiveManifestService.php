<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use DateTime;
use PDO;
use RuntimeException;

/**
 * Immutable-baseline archive manifests + source integrity verification.
 * createManifest is INSERT-only (idempotent same identity; never UPDATE baseline).
 */
class ArchiveManifestService
{
    public const INTEGRITY_OK = 'OK';
    public const INTEGRITY_CHANGED = 'CHANGED';
    public const INTEGRITY_UNKNOWN = 'UNKNOWN';
    public const CODE_ARCHIVE_MANIFEST_MISSING = 'ARCHIVE_MANIFEST_MISSING';
    public const CODE_ARCHIVE_MANIFEST_SOURCE_CHANGED = 'ARCHIVE_MANIFEST_SOURCE_CHANGED';

    /**
     * INSERT-only. Same unique key + identical baseline → return existing.
     * Different baseline on conflict → ARCHIVE_MANIFEST_SOURCE_CHANGED (no UPDATE).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function createManifest(PDO $pdo, array $payload, $createdBy = null)
    {
        RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::manifestTables());

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
            throw new RuntimeException('ARCHIVE_MANIFEST_INVALID');
        }
        if (!in_array($triggerType, [
            RetentionCategories::TRIGGER_PERIOD_CLOSURE,
            RetentionCategories::TRIGGER_TERMINATION_DATE,
        ], true)) {
            throw new RuntimeException('ARCHIVE_MANIFEST_TRIGGER_INVALID');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $triggerDate)) {
            throw new RuntimeException('ARCHIVE_MANIFEST_TRIGGER_DATE_INVALID');
        }
        if ($retentionUntil === '') {
            $dt = DateTime::createFromFormat('Y-m-d', $triggerDate);
            if (!$dt) {
                throw new RuntimeException('ARCHIVE_MANIFEST_TRIGGER_DATE_INVALID');
            }
            $retentionUntil = RetentionPolicyService::calculateRetentionUntil($dt);
        }

        $existing = self::find($pdo, $entityType, $recordId, $category);
        if ($existing) {
            $sameIdentity = (string) ($existing['source_version_identity'] ?? '') === $sourceIdentity
                && (string) ($existing['trigger_type'] ?? '') === $triggerType
                && (string) ($existing['trigger_date'] ?? '') === $triggerDate
                && (string) ($existing['retention_until'] ?? '') === $retentionUntil
                && self::sameSha($existing['source_sha256'] ?? null, $sha);
            if ($sameIdentity) {
                return $existing;
            }
            throw new RuntimeException(self::CODE_ARCHIVE_MANIFEST_SOURCE_CHANGED);
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
                 :trigger_type, :trigger_date, :retention_until, :sha, :integrity, :created_by)'
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
     * @deprecated Use createManifest — kept as alias for callers during transition.
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function upsertManifest(PDO $pdo, array $payload, $createdBy = null)
    {
        return self::createManifest($pdo, $payload, $createdBy);
    }

    /**
     * Sticky CHANGED only — never reset to OK via replay.
     */
    public static function markIntegrityChanged(PDO $pdo, $id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return;
        }
        $upd = $pdo->prepare(
            "UPDATE arsiv_manifestleri
             SET integrity_status = 'CHANGED'
             WHERE id = :id AND integrity_status <> 'CHANGED'"
        );
        $upd->execute(['id' => $id]);
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
     * Compare stored sha256 with current. Missing manifest → ARCHIVE_MANIFEST_MISSING.
     * Empty stored sha → INTEGRITY_UNKNOWN (fail-closed for destruction).
     *
     * @return string OK|UNKNOWN|ARCHIVE_MANIFEST_MISSING|ARCHIVE_SOURCE_INTEGRITY_CHANGED
     */
    public static function verifySourceIntegrity(PDO $pdo, $entityType, $recordId, $category, $currentSha256 = null)
    {
        $manifest = self::find($pdo, $entityType, $recordId, $category);
        if (!$manifest) {
            return self::CODE_ARCHIVE_MANIFEST_MISSING;
        }

        $stored = isset($manifest['source_sha256']) ? (string) $manifest['source_sha256'] : '';
        if ($stored === '') {
            return RetentionPolicyService::CODE_INTEGRITY_UNKNOWN;
        }
        if ($currentSha256 === null || $currentSha256 === '') {
            return RetentionPolicyService::CODE_INTEGRITY_UNKNOWN;
        }

        if (!hash_equals(strtolower($stored), strtolower((string) $currentSha256))) {
            self::markIntegrityChanged($pdo, (int) $manifest['id']);

            return RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED;
        }

        return self::INTEGRITY_OK;
    }

    /**
     * Deterministic fingerprint of stable personel fields (no volatile timestamps).
     *
     * @return string|null hex sha256
     */
    public static function computePersonelOzlukFingerprint(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT id, tc_kimlik_no, ad, soyad, ise_giris_tarihi, sube_id, aktif_durum, sicil_no
             FROM personeller WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $payload = implode('|', [
            (string) ($row['id'] ?? ''),
            (string) ($row['tc_kimlik_no'] ?? ''),
            (string) ($row['ad'] ?? ''),
            (string) ($row['soyad'] ?? ''),
            (string) ($row['ise_giris_tarihi'] ?? ''),
            (string) ($row['sube_id'] ?? ''),
            (string) ($row['aktif_durum'] ?? ''),
            (string) ($row['sicil_no'] ?? ''),
        ]);

        return hash('sha256', $payload);
    }

    /**
     * Create PERSONEL_OZLUK (+ ISE_GIRIS_CIKIS) manifests at PASIF transition.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function createPersonelLifecycleManifests(PDO $pdo, $personelId, $actorId)
    {
        $personelId = (int) $personelId;
        $actorId = (int) $actorId;
        if ($personelId <= 0) {
            throw new RuntimeException('ARCHIVE_MANIFEST_INVALID');
        }

        $termination = RetentionPolicyService::resolveTerminationDate($pdo, $personelId);
        if ($termination === null) {
            throw new RuntimeException(RetentionPolicyService::CODE_TERMINATION_DATE_MISSING);
        }

        $fingerprint = self::computePersonelOzlukFingerprint($pdo, $personelId);
        $identity = 'personel:' . $personelId . ':termination:' . $termination;
        $dt = DateTime::createFromFormat('Y-m-d', $termination);
        if (!$dt) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        $until = RetentionPolicyService::calculateRetentionUntil($dt);

        $created = [];
        foreach ([RetentionCategories::PERSONEL_OZLUK, RetentionCategories::ISE_GIRIS_CIKIS] as $category) {
            $created[] = self::createManifest($pdo, [
                'entity_type' => 'personel',
                'record_id' => $personelId,
                'personel_id' => $personelId,
                'record_category' => $category,
                'source_version_identity' => $identity,
                'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
                'trigger_date' => $termination,
                'retention_until' => $until,
                'source_sha256' => $fingerprint,
            ], $actorId > 0 ? $actorId : null);
        }

        return $created;
    }

    private static function sameSha($a, $b)
    {
        $a = $a !== null && $a !== '' ? strtolower((string) $a) : null;
        $b = $b !== null && $b !== '' ? strtolower((string) $b) : null;

        return $a === $b;
    }
}
