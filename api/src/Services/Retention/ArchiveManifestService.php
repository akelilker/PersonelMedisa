<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use DateTime;
use PDO;
use RuntimeException;

/**
 * Immutable-baseline archive manifests + source integrity verification.
 * createManifest is INSERT-only (idempotent same identity; never UPDATE baseline).
 * Multi-lifecycle: unique on (entity_type, record_id, record_category, source_version_identity).
 */
class ArchiveManifestService
{
    public const INTEGRITY_OK = 'OK';
    public const INTEGRITY_CHANGED = 'CHANGED';
    public const INTEGRITY_UNKNOWN = 'UNKNOWN';
    public const CODE_ARCHIVE_MANIFEST_MISSING = 'ARCHIVE_MANIFEST_MISSING';
    public const CODE_ARCHIVE_MANIFEST_SOURCE_CHANGED = 'ARCHIVE_MANIFEST_SOURCE_CHANGED';
    public const CODE_ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE = 'ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE';

    /**
     * INSERT-only. Same unique key + identical baseline → return existing.
     * Same unique key + different baseline → ARCHIVE_MANIFEST_SOURCE_CHANGED (no UPDATE).
     * Different source_version_identity → new immutable lifecycle row.
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

        $existing = self::findBySourceIdentity($pdo, $entityType, $recordId, $category, $sourceIdentity);
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

        return self::findBySourceIdentity($pdo, $entityType, $recordId, $category, $sourceIdentity);
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
     * Ambiguous across lifecycles — prefer findBySourceIdentity / findCurrentLifecycleManifest.
     *
     * @return array<string, mixed>|null
     */
    public static function find(PDO $pdo, $entityType, $recordId, $category)
    {
        return self::findCurrentLifecycleManifest($pdo, $entityType, $recordId, $category, []);
    }

    /**
     * Exact lifecycle lookup by source_version_identity.
     *
     * @return array<string, mixed>|null
     */
    public static function findBySourceIdentity(PDO $pdo, $entityType, $recordId, $category, $sourceVersionIdentity)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM arsiv_manifestleri
             WHERE entity_type = :entity_type
               AND record_id = :record_id
               AND record_category = :category
               AND source_version_identity = :source_identity
             LIMIT 1'
        );
        $stmt->execute([
            'entity_type' => (string) $entityType,
            'record_id' => (int) $recordId,
            'category' => (string) $category,
            'source_identity' => (string) $sourceVersionIdentity,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Current effective lifecycle for the target.
     * For PERSONEL_OZLUK / ISE_GIRIS_CIKIS: derives personel:{id}:termination:{effective_date}.
     * Older lifecycle-only → null (caller maps to ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    public static function findCurrentLifecycleManifest(PDO $pdo, $entityType, $recordId, $category, array $context = [])
    {
        $entityType = (string) $entityType;
        $recordId = (int) $recordId;
        $category = (string) $category;
        if ($entityType === 'personeller') {
            $entityType = 'personel';
        }

        $identity = null;
        try {
            $ctx = $context;
            $ctx['entity_type'] = $entityType;
            $ctx['record_id'] = $recordId;
            if (!isset($ctx['personel_id']) && $entityType === 'personel') {
                $ctx['personel_id'] = $recordId;
            }
            $source = RetentionSourceAdapterService::resolve($pdo, $category, $ctx);
            $identity = $source['source_version_identity'];
        } catch (RuntimeException $e) {
            // Fall through — try latest row only for non-lifecycle ambiguity diagnostics.
            $identity = null;
        }

        if ($identity !== null && $identity !== '') {
            return self::findBySourceIdentity($pdo, $entityType, $recordId, $category, $identity);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listForRecord(PDO $pdo, $entityType, $recordId, $category = null)
    {
        $sql = 'SELECT * FROM arsiv_manifestleri
                WHERE entity_type = :entity_type AND record_id = :record_id';
        $params = [
            'entity_type' => (string) $entityType,
            'record_id' => (int) $recordId,
        ];
        if ($category !== null && $category !== '') {
            $sql .= ' AND record_category = :category';
            $params['category'] = (string) $category;
        }
        $sql .= ' ORDER BY trigger_date ASC, id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Compare stored sha256 with current for the CURRENT lifecycle manifest.
     * Missing current lifecycle (older only) → ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE.
     *
     * @param array<string, mixed> $context
     * @return string OK|UNKNOWN|ARCHIVE_MANIFEST_MISSING|ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE|ARCHIVE_SOURCE_INTEGRITY_CHANGED
     */
    public static function verifySourceIntegrity(
        PDO $pdo,
        $entityType,
        $recordId,
        $category,
        $currentSha256 = null,
        array $context = []
    ) {
        $entityType = $entityType === 'personeller' ? 'personel' : (string) $entityType;
        $recordId = (int) $recordId;
        $category = (string) $category;

        $ctx = $context;
        $ctx['entity_type'] = $entityType;
        $ctx['record_id'] = $recordId;

        $manifest = self::findCurrentLifecycleManifest($pdo, $entityType, $recordId, $category, $ctx);
        if (!$manifest) {
            $any = self::listForRecord($pdo, $entityType, $recordId, $category);
            if (count($any) > 0) {
                return self::CODE_ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE;
            }

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
     * Deterministic fingerprint of QR raw attendance evidence for ISE_GIRIS_CIKIS.
     * Empty / missing table → stable empty-state hash (does not throw).
     *
     * @return string|null
     */
    public static function computeIseGirisCikisFingerprint(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            return null;
        }

        $hasTable = false;
        try {
            $check = $pdo->query("SHOW TABLES LIKE 'qr_attendance_events'");
            $hasTable = $check && (bool) $check->fetch(PDO::FETCH_NUM);
        } catch (\Throwable $e) {
            $hasTable = false;
        }

        if (!$hasTable) {
            return hash('sha256', 'ise_giris_cikis:empty:personel:' . $personelId . ':no_table');
        }

        $stmt = $pdo->prepare(
            'SELECT id, personel_id, user_id, sube_id, event_type, occurred_at_utc,
                    qr_version, qr_jti, created_at, request_nonce
             FROM qr_attendance_events
             WHERE personel_id = :personel_id
             ORDER BY occurred_at_utc ASC, id ASC'
        );
        $stmt->execute(['personel_id' => $personelId]);
        $parts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $parts[] = implode('|', [
                (string) ($row['id'] ?? ''),
                (string) ($row['personel_id'] ?? ''),
                (string) ($row['user_id'] ?? ''),
                (string) ($row['sube_id'] ?? ''),
                (string) ($row['event_type'] ?? ''),
                (string) ($row['occurred_at_utc'] ?? ''),
                (string) ($row['qr_version'] ?? ''),
                (string) ($row['qr_jti'] ?? ''),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['request_nonce'] ?? ''),
            ]);
        }

        if (count($parts) === 0) {
            return hash('sha256', 'ise_giris_cikis:empty:personel:' . $personelId);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * Create PERSONEL_OZLUK (+ ISE_GIRIS_CIKIS) manifests at PASIF transition.
     * New employment lifecycle → new row; prior lifecycle rows remain immutable.
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

        $ozlukFp = self::computePersonelOzlukFingerprint($pdo, $personelId);
        $girisCikisFp = self::computeIseGirisCikisFingerprint($pdo, $personelId);
        $ozlukIdentity = 'personel:' . $personelId . ':termination:' . $termination;
        $girisCikisIdentity = 'personel:' . $personelId . ':ise_giris_cikis:termination:' . $termination;
        $dt = DateTime::createFromFormat('Y-m-d', $termination);
        if (!$dt) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }
        $until = RetentionPolicyService::calculateRetentionUntil($dt);

        $created = [];
        foreach (
            [
                [
                    'category' => RetentionCategories::PERSONEL_OZLUK,
                    'identity' => $ozlukIdentity,
                    'fp' => $ozlukFp,
                ],
                [
                    'category' => RetentionCategories::ISE_GIRIS_CIKIS,
                    'identity' => $girisCikisIdentity,
                    'fp' => $girisCikisFp,
                ],
            ] as $spec
        ) {
            $created[] = self::createManifest($pdo, [
                'entity_type' => 'personel',
                'record_id' => $personelId,
                'personel_id' => $personelId,
                'record_category' => $spec['category'],
                'source_version_identity' => $spec['identity'],
                'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
                'trigger_date' => $termination,
                'retention_until' => $until,
                'source_sha256' => $spec['fp'],
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
