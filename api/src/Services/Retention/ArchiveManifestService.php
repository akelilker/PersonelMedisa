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
     * Pre-S3C ISE_GIRIS_CIKIS identity (shared ozluk-style termination key).
     */
    public static function legacyIseGirisCikisIdentity($personelId, $terminationYmd)
    {
        return 'personel:' . (int) $personelId . ':termination:' . (string) $terminationYmd;
    }

    /**
     * S3C QR-aware ISE_GIRIS_CIKIS identity.
     */
    public static function qrAwareIseGirisCikisIdentity($personelId, $terminationYmd)
    {
        return 'personel:' . (int) $personelId . ':ise_giris_cikis:termination:' . (string) $terminationYmd;
    }

    public static function isLegacyIseGirisCikisIdentity($identity)
    {
        $identity = (string) $identity;

        return (bool) preg_match('/^personel:\d+:termination:\d{4}-\d{2}-\d{2}$/', $identity);
    }

    /**
     * Current effective lifecycle for the target.
     * PERSONEL_OZLUK: personel:{id}:termination:{effective_date}.
     * ISE_GIRIS_CIKIS: prefer QR-aware identity; else same-termination legacy identity
     * (no silent latest-row fallback across lifecycles).
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

        if ($category === RetentionCategories::ISE_GIRIS_CIKIS && $entityType === 'personel') {
            $personelId = isset($context['personel_id']) && (int) $context['personel_id'] > 0
                ? (int) $context['personel_id']
                : $recordId;
            $termination = RetentionPolicyService::resolveTerminationDate($pdo, $personelId);
            if ($termination === null) {
                return null;
            }
            $qrAware = self::findBySourceIdentity(
                $pdo,
                $entityType,
                $recordId,
                $category,
                self::qrAwareIseGirisCikisIdentity($personelId, $termination)
            );
            if ($qrAware) {
                return $qrAware;
            }

            return self::findBySourceIdentity(
                $pdo,
                $entityType,
                $recordId,
                $category,
                self::legacyIseGirisCikisIdentity($personelId, $termination)
            );
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
     * Empty table and missing table use distinct stable hashes (documented).
     * created_at uses UNIX_TIMESTAMP (session-timezone independent).
     * Includes qr_issued_at_utc / qr_expires_at_utc as immutable evidence.
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
            // Distinct from empty-table hash: pre-057 rolling compatibility fail-safe.
            return hash('sha256', 'ise_giris_cikis:empty:personel:' . $personelId . ':no_table');
        }

        $stmt = $pdo->prepare(
            'SELECT id, personel_id, user_id, sube_id, event_type, occurred_at_utc,
                    qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc,
                    UNIX_TIMESTAMP(created_at) AS created_at_unix, request_nonce
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
                self::normalizeUtcEvidence((string) ($row['occurred_at_utc'] ?? '')),
                (string) ($row['qr_version'] ?? ''),
                strtolower((string) ($row['qr_jti'] ?? '')),
                self::normalizeUtcEvidence((string) ($row['qr_issued_at_utc'] ?? '')),
                self::normalizeUtcEvidence((string) ($row['qr_expires_at_utc'] ?? '')),
                (string) ($row['created_at_unix'] ?? ''),
                strtolower((string) ($row['request_nonce'] ?? '')),
            ]);
        }

        if (count($parts) === 0) {
            return hash('sha256', 'ise_giris_cikis:empty:personel:' . $personelId);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * Stable UTC datetime string for fingerprint (trim fractional noise consistently).
     */
    private static function normalizeUtcEvidence($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        } catch (\Throwable $e) {
            return $raw;
        }
    }

    /**
     * Create PERSONEL_OZLUK (+ ISE_GIRIS_CIKIS) and remaining TERMINATION_DATE category
     * manifests at PASIF / ISTEN_AYRILMA transition.
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
        // New PASIF transitions always mint QR-aware ISE identity (no legacy backfill).
        $girisCikisIdentity = self::qrAwareIseGirisCikisIdentity($personelId, $termination);
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

        foreach (self::createTerminationScopedManifests($pdo, $personelId, $actorId) as $row) {
            $created[] = $row;
        }

        return $created;
    }

    /**
     * Mint TERMINATION_DATE category manifests for employment-file entities at PASIF.
     * PERSONEL_BELGE / surec family / disiplin OLAY+SAVUNMA — only when source resolves.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function createTerminationScopedManifests(PDO $pdo, $personelId, $actorId)
    {
        $personelId = (int) $personelId;
        $actorId = (int) $actorId;
        $created = [];

        // PERSONEL_BELGE + IZIN/RAPOR/IS_KAZASI/DISIPLIN via surecler
        if (self::tableExistsLocal($pdo, 'surecler')) {
            $surecTurMap = [
                'BELGE' => RetentionCategories::PERSONEL_BELGE,
                'IZIN' => RetentionCategories::IZIN,
                'RAPOR' => RetentionCategories::RAPOR,
                'IS_KAZASI' => RetentionCategories::IS_KAZASI,
                'DISIPLIN' => RetentionCategories::DISIPLIN,
            ];
            $stmt = $pdo->prepare(
                "SELECT id, surec_turu, state
                 FROM surecler
                 WHERE personel_id = :pid AND state <> 'IPTAL'
                 ORDER BY id ASC"
            );
            $stmt->execute(['pid' => $personelId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $tur = strtoupper((string) ($row['surec_turu'] ?? ''));
                if (!isset($surecTurMap[$tur])) {
                    continue;
                }
                $category = $surecTurMap[$tur];
                $surecId = (int) $row['id'];
                try {
                    $ctx = [
                        'entity_type' => 'surec',
                        'record_id' => $surecId,
                        'personel_id' => $personelId,
                    ];
                    $created[] = self::createResolvedManifest(
                        $pdo,
                        $category,
                        $ctx,
                        $actorId > 0 ? $actorId : null
                    );
                } catch (RuntimeException $e) {
                    // Skip sources that are not yet retention-trigger-resolved (e.g. belge without active file).
                    if ($e->getMessage() === RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED
                        || strpos($e->getMessage(), RetentionSourceAdapterService::CODE_NOT_IMPLEMENTED) === 0
                    ) {
                        continue;
                    }
                    throw $e;
                }
            }
        }

        // OLAY + SAVUNMA via disiplin_vakalar (canonical attendance discipline owner)
        if (self::tableExistsLocal($pdo, 'disiplin_vakalar')) {
            $stmt = $pdo->prepare(
                'SELECT id FROM disiplin_vakalar WHERE personel_id = :pid ORDER BY id ASC'
            );
            $stmt->execute(['pid' => $personelId]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $vakaId = (int) $row['id'];
                $ctx = [
                    'entity_type' => 'disiplin_vaka',
                    'record_id' => $vakaId,
                    'disiplin_vaka_id' => $vakaId,
                    'personel_id' => $personelId,
                ];
                foreach ([RetentionCategories::OLAY, RetentionCategories::SAVUNMA] as $category) {
                    $created[] = self::createResolvedManifest(
                        $pdo,
                        $category,
                        $ctx,
                        $actorId > 0 ? $actorId : null
                    );
                }
            }
        }

        return $created;
    }

    /**
     * Server-owned create: resolve identity/fingerprint + trigger, then INSERT-only mint.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function createResolvedManifest(PDO $pdo, $category, array $context, $createdBy = null)
    {
        $category = (string) $category;
        if (!RetentionCategories::isKnown($category)) {
            throw new RuntimeException(RetentionPolicyService::CODE_UNKNOWN_CATEGORY);
        }

        $source = RetentionSourceAdapterService::resolve($pdo, $category, $context);
        $trigger = RetentionPolicyService::resolveTrigger($pdo, $category, $context);

        $entityType = isset($context['entity_type']) ? strtolower(trim((string) $context['entity_type'])) : '';
        $recordId = isset($context['record_id']) ? (int) $context['record_id'] : 0;
        if ($entityType === 'personeller') {
            $entityType = 'personel';
        }
        if ($entityType === 'surecler') {
            $entityType = 'surec';
        }
        if ($entityType === 'disiplin_vakalar') {
            $entityType = 'disiplin_vaka';
        }
        if ($entityType === 'haftalik_kapanislar') {
            $entityType = 'haftalik_kapanis';
        }
        if ($entityType === '' || $recordId <= 0) {
            throw new RuntimeException('ARCHIVE_MANIFEST_INVALID');
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : null;
        if ($personelId !== null && $personelId <= 0) {
            $personelId = null;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $trigger['trigger_date']);
        if (!$dt) {
            throw new RuntimeException(RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED);
        }

        return self::createManifest($pdo, [
            'entity_type' => $entityType,
            'record_id' => $recordId,
            'personel_id' => $personelId,
            'record_category' => $category,
            'source_version_identity' => $source['source_version_identity'],
            'trigger_type' => $trigger['trigger_type'],
            'trigger_date' => $trigger['trigger_date'],
            'retention_until' => RetentionPolicyService::calculateRetentionUntil($dt),
            'source_sha256' => $source['source_sha256'],
        ], $createdBy);
    }

    /**
     * PUANTAJ (+ generic parent ONAY_AUDIT) at monthly seal / reseal.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function createPuantajPeriodManifests(PDO $pdo, $subeId, $yil, $ay, $sealId, $actorId)
    {
        $subeId = (int) $subeId;
        $yil = (int) $yil;
        $ay = (int) $ay;
        $sealId = (int) $sealId;
        $actorId = (int) $actorId;
        $ctx = [
            'entity_type' => 'puantaj',
            'record_id' => $sealId,
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
            'parent_category' => RetentionCategories::PUANTAJ,
        ];
        $created = [];
        $created[] = self::createResolvedManifest(
            $pdo,
            RetentionCategories::PUANTAJ,
            $ctx,
            $actorId > 0 ? $actorId : null
        );
        $created[] = self::createResolvedManifest(
            $pdo,
            RetentionCategories::ONAY_AUDIT,
            $ctx,
            $actorId > 0 ? $actorId : null
        );

        return $created;
    }

    /**
     * BORDRO (+ parent ONAY_AUDIT) at kesinleştirme.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function createBordroPeriodManifests(PDO $pdo, $subeId, $yil, $ay, $runId, $actorId)
    {
        $ctx = [
            'entity_type' => 'bordro',
            'record_id' => (int) $runId,
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
            'parent_category' => RetentionCategories::BORDRO,
        ];
        $actorId = (int) $actorId;
        $created = [];
        $created[] = self::createResolvedManifest(
            $pdo,
            RetentionCategories::BORDRO,
            $ctx,
            $actorId > 0 ? $actorId : null
        );
        $created[] = self::createResolvedManifest(
            $pdo,
            RetentionCategories::ONAY_AUDIT,
            $ctx,
            $actorId > 0 ? $actorId : null
        );

        return $created;
    }

    /**
     * SGK_EKSIK_GUN at payroll period snapshot create (non-idempotent path).
     *
     * @return array<string, mixed>
     */
    public static function createSgkPeriodManifest(PDO $pdo, $subeId, $yil, $ay, $snapshotId, $actorId)
    {
        $actorId = (int) $actorId;

        return self::createResolvedManifest($pdo, RetentionCategories::SGK_EKSIK_GUN, [
            'entity_type' => 'sgk',
            'record_id' => (int) $snapshotId,
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
        ], $actorId > 0 ? $actorId : null);
    }

    /**
     * FAZLA_CALISMA + SERBEST_ZAMAN at haftalık kapanış KAPANDI.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function createHaftalikPeriodManifests(PDO $pdo, $kapanisId, $subeId, $haftaBaslangic, $actorId)
    {
        $kapanisId = (int) $kapanisId;
        $subeId = (int) $subeId;
        $actorId = (int) $actorId;
        $ctx = [
            'entity_type' => 'haftalik_kapanis',
            'record_id' => $kapanisId,
            'haftalik_kapanis_id' => $kapanisId,
            'sube_id' => $subeId,
            'hafta_baslangic' => (string) $haftaBaslangic,
        ];
        $created = [];
        foreach ([RetentionCategories::FAZLA_CALISMA, RetentionCategories::SERBEST_ZAMAN] as $category) {
            $created[] = self::createResolvedManifest(
                $pdo,
                $category,
                $ctx,
                $actorId > 0 ? $actorId : null
            );
        }

        return $created;
    }

    /**
     * S3F decision ledger → ONAY_AUDIT manifest (same transaction as ledger append).
     *
     * @param array<string, mixed> $ledgerRow
     * @return array<string, mixed>
     */
    public static function createQrPuantajDecisionOnayAuditManifest(PDO $pdo, array $ledgerRow, $actorId)
    {
        $ledgerId = (int) ($ledgerRow['id'] ?? 0);
        if ($ledgerId <= 0) {
            throw new RuntimeException('ARCHIVE_MANIFEST_INVALID');
        }
        $actorId = (int) $actorId;
        $ctx = [
            'entity_type' => 'qr_pc_decision',
            'record_id' => $ledgerId,
            'ledger_id' => $ledgerId,
            'personel_id' => (int) ($ledgerRow['personel_id'] ?? 0),
            'sube_id' => (int) ($ledgerRow['sube_id'] ?? 0),
            'candidate_date' => substr((string) ($ledgerRow['candidate_date'] ?? ''), 0, 10),
            'parent_category' => RetentionCategories::PUANTAJ,
            'audit_source_type' => RetentionSourceAdapterService::AUDIT_SOURCE_QR_PUANTAJ_CANDIDATE_DECISION,
        ];

        return self::createResolvedManifest(
            $pdo,
            RetentionCategories::ONAY_AUDIT,
            $ctx,
            $actorId > 0 ? $actorId : null
        );
    }

    /**
     * Pack-1 lifecycle side-effect host check.
     * Isolated SQLite unit runners (e.g. MaasHesaplamaSnapshotTestRunner) intentionally
     * omit 053 retention DDL — they are not production hosts. MariaDB/MySQL always require
     * retention schema (fail-closed). Never uses silent SCHEMA_NOT_READY swallow.
     */
    public static function isLifecycleRetentionHost(PDO $pdo)
    {
        try {
            $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        } catch (\Throwable $e) {
            return true;
        }

        return $driver !== 'sqlite';
    }

    /**
     * Required Pack1 mint — asserts 053 arsiv_manifestleri then runs $fn.
     * SCHEMA_NOT_READY and all other errors propagate (fail-closed).
     *
     * @param callable $fn
     * @return mixed
     */
    public static function requireManifestSideEffect(PDO $pdo, $fn)
    {
        RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::manifestTables());

        return $fn();
    }

    /**
     * @deprecated Pack1 lifecycle owners must use requireManifestSideEffect (fail-closed).
     * Kept only for non-lifecycle optional tooling — do not wire into create/close paths.
     *
     * @param callable $fn
     * @return mixed|null
     */
    public static function runIfSchemaReady(PDO $pdo, $fn)
    {
        try {
            return self::requireManifestSideEffect($pdo, $fn);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === RetentionSchemaGate::CODE_SCHEMA_NOT_READY
                || $e->getMessage() === RetentionPolicyService::CODE_SCHEMA_NOT_READY
            ) {
                return null;
            }
            throw $e;
        }
    }

    private static function tableExistsLocal(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }

    private static function sameSha($a, $b)
    {
        $a = $a !== null && $a !== '' ? strtolower((string) $a) : null;
        $b = $b !== null && $b !== '' ? strtolower((string) $b) : null;

        return $a === $b;
    }
}
