<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use DateTime;
use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Canonical owner for Medisa saklama politikası retention evaluation.
 * Fail-closed. Never auto-deletes. Physical mutation is owned by PhysicalDestructionService
 * (feature-flagged; default OFF).
 *
 * Pre-approval eligibility ≠ final execution eligibility.
 * Client as_of / gm_approved are NEVER trusted for business decisions.
 */
class RetentionPolicyService
{
    public const CODE_UNKNOWN_CATEGORY = 'UNKNOWN_CATEGORY';
    public const CODE_TRIGGER_NOT_RESOLVED = 'TRIGGER_NOT_RESOLVED';
    public const CODE_PERIOD_NOT_CLOSED = 'PERIOD_NOT_CLOSED';
    public const CODE_TERMINATION_DATE_MISSING = 'TERMINATION_DATE_MISSING';
    public const CODE_RETENTION_NOT_MATURE = 'RETENTION_NOT_MATURE';
    public const CODE_LEGAL_HOLD_ACTIVE = 'LEGAL_HOLD_ACTIVE';
    /** @deprecated Removed from eligibility path — kept for message map compat */
    public const CODE_NO_GM_APPROVAL = 'NO_GM_APPROVAL';
    public const CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED = 'ARCHIVE_SOURCE_INTEGRITY_CHANGED';
    public const CODE_ARCHIVE_MANIFEST_MISSING = 'ARCHIVE_MANIFEST_MISSING';
    public const CODE_ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE = 'ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE';
    public const CODE_INTEGRITY_UNKNOWN = 'INTEGRITY_UNKNOWN';
    public const CODE_ARCHIVE_AUDIT_UNAVAILABLE = 'ARCHIVE_AUDIT_UNAVAILABLE';
    public const CODE_SCHEMA_NOT_READY = 'SCHEMA_NOT_READY';
    public const CODE_SOURCE_CONTEXT_CHANGED = 'SOURCE_CONTEXT_CHANGED';
    public const CODE_SNAPSHOT_INCOMPLETE = 'SNAPSHOT_INCOMPLETE';
    public const CODE_ARCHIVED_PERSONEL_READ_ONLY = 'ARCHIVED_PERSONEL_READ_ONLY';
    public const CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST = 'ELIGIBLE_FOR_DESTRUCTION_REQUEST';
    public const CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED = 'EXECUTION_HANDLER_NOT_IMPLEMENTED';
    public const CODE_APPROVED_FOR_DESTRUCTION = 'APPROVED_FOR_DESTRUCTION';
    public const CODE_DESTRUCTION_REQUEST_NOT_APPROVED = 'DESTRUCTION_REQUEST_NOT_APPROVED';
    public const CODE_RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED = 'RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED';
    public const CODE_DESTRUCTION_EXECUTION_DISABLED = 'DESTRUCTION_EXECUTION_DISABLED';
    public const CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED = 'DESTRUCTION_HANDLER_POLICY_UNRESOLVED';
    public const CODE_DESTRUCTION_PLAN_CHANGED = 'DESTRUCTION_PLAN_CHANGED';
    public const CODE_ALREADY_EXECUTED = 'ALREADY_EXECUTED';
    public const CODE_TARGET_ALREADY_MISSING = 'TARGET_ALREADY_MISSING';
    public const CODE_DEPENDENT_RETENTION_RECORDS_REMAIN = 'DEPENDENT_RETENTION_RECORDS_REMAIN';
    public const CODE_DESTRUCTION_EXECUTED = 'DESTRUCTION_EXECUTED';

    /**
     * @param array<string, mixed> $context
     * @return array{trigger_type: string, trigger_date: string}
     */
    public static function resolveTrigger(PDO $pdo, $category, array $context)
    {
        $category = (string) $category;
        $triggerType = RetentionCategories::triggerTypeForCategory($category);
        if ($triggerType === null) {
            throw new RuntimeException(self::CODE_UNKNOWN_CATEGORY);
        }

        if ($triggerType === RetentionCategories::TRIGGER_PERIOD_CLOSURE) {
            return RetentionPeriodTriggerResolver::resolve($pdo, $category, $context);
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId <= 0) {
            throw new RuntimeException(self::CODE_TRIGGER_NOT_RESOLVED);
        }

        $termination = self::resolveTerminationDate($pdo, $personelId);
        if ($termination === null) {
            throw new RuntimeException(self::CODE_TERMINATION_DATE_MISSING);
        }

        return [
            'trigger_type' => RetentionCategories::TRIGGER_TERMINATION_DATE,
            'trigger_date' => $termination,
        ];
    }

    /**
     * Calendar +10 years from trigger date (not 3650 days).
     *
     * @return string Y-m-d
     */
    public static function calculateRetentionUntil(DateTime $triggerDate)
    {
        $until = clone $triggerDate;
        $until->modify('+' . RetentionCategories::POLICY_RETENTION_YEARS . ' years');

        return $until->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $context
     * @return bool
     */
    public static function hasActiveLegalHold(PDO $pdo, $category, array $context)
    {
        RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::legalHoldTables());

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        $recordId = isset($context['record_id']) ? (int) $context['record_id'] : 0;
        $entityType = isset($context['entity_type']) ? (string) $context['entity_type'] : '';
        $category = (string) $category;

        $sql = "SELECT id FROM legal_holdlar
            WHERE hold_state = 'ACTIVE'
              AND (
                    (personel_id IS NOT NULL AND :personel_id_check > 0 AND personel_id = :personel_id_match)
                 OR (target_category IS NOT NULL AND target_category = :category
                     AND (target_record_id IS NULL OR target_record_id = :record_id_cat))
                 OR (target_domain = :entity_type AND :entity_type_check <> ''
                     AND (target_record_id IS NULL OR target_record_id = :record_id_dom))
              )
            LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'personel_id_check' => $personelId,
            'personel_id_match' => $personelId,
            'category' => $category,
            'record_id_cat' => $recordId,
            'entity_type' => $entityType,
            'entity_type_check' => $entityType,
            'record_id_dom' => $recordId,
        ]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Public API eligibility = pre-approval (requestable), NOT final APPROVED_FOR_DESTRUCTION.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function evaluateDestructionEligibility(PDO $pdo, $category, array $context)
    {
        return self::evaluatePreApprovalEligibility($pdo, $category, $context, null);
    }

    /**
     * Pre-approval: known category, trigger, mature (server clock), no hold, mandatory integrity.
     * NO GM approval required. Returns ELIGIBLE_FOR_DESTRUCTION_REQUEST when pass.
     *
     * @param array<string, mixed> $context
     * @param DateTimeImmutable|null $asOf Explicit PHP arg only — never from HTTP context.
     * @return array<string, mixed>
     */
    public static function evaluatePreApprovalEligibility(
        PDO $pdo,
        $category,
        array $context,
        DateTimeImmutable $asOf = null
    ) {
        $category = (string) $category;
        $result = self::emptyResult($category);

        if (!RetentionCategories::isKnown($category)) {
            $result['code'] = self::CODE_UNKNOWN_CATEGORY;
            $result['message'] = self::codeMessage(self::CODE_UNKNOWN_CATEGORY);

            return $result;
        }

        try {
            RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::destructionTables());
        } catch (RuntimeException $e) {
            $result['code'] = self::CODE_SCHEMA_NOT_READY;
            $result['message'] = self::codeMessage(self::CODE_SCHEMA_NOT_READY);

            return $result;
        }

        try {
            $trigger = self::resolveTrigger($pdo, $category, $context);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if (!in_array($code, [
                self::CODE_PERIOD_NOT_CLOSED,
                self::CODE_TERMINATION_DATE_MISSING,
                self::CODE_TRIGGER_NOT_RESOLVED,
                self::CODE_UNKNOWN_CATEGORY,
                self::CODE_SCHEMA_NOT_READY,
            ], true)) {
                $code = self::CODE_TRIGGER_NOT_RESOLVED;
            }
            $result['code'] = $code;
            $result['message'] = self::codeMessage($code);

            return $result;
        }

        $result['trigger_type'] = $trigger['trigger_type'];
        $result['trigger_date'] = $trigger['trigger_date'];

        $triggerDt = DateTime::createFromFormat('Y-m-d', $trigger['trigger_date']);
        if (!$triggerDt) {
            $result['code'] = self::CODE_TRIGGER_NOT_RESOLVED;
            $result['message'] = self::codeMessage(self::CODE_TRIGGER_NOT_RESOLVED);

            return $result;
        }

        $retentionUntil = self::calculateRetentionUntil($triggerDt);
        $result['retention_until'] = $retentionUntil;

        $clock = $asOf instanceof DateTimeImmutable ? $asOf : RetentionClock::now();
        $asOfYmd = $clock->format('Y-m-d');
        if ($asOfYmd < $retentionUntil) {
            $result['code'] = self::CODE_RETENTION_NOT_MATURE;
            $result['message'] = self::codeMessage(self::CODE_RETENTION_NOT_MATURE);

            return $result;
        }

        try {
            if (self::hasActiveLegalHold($pdo, $category, $context)) {
                $result['code'] = self::CODE_LEGAL_HOLD_ACTIVE;
                $result['message'] = self::codeMessage(self::CODE_LEGAL_HOLD_ACTIVE);

                return $result;
            }
        } catch (RuntimeException $e) {
            $code = $e->getMessage() === RetentionSchemaGate::CODE_SCHEMA_NOT_READY
                ? self::CODE_SCHEMA_NOT_READY
                : $e->getMessage();
            $result['code'] = $code;
            $result['message'] = self::codeMessage($code);

            return $result;
        }

        $integrityCode = self::checkMandatoryIntegrity($pdo, $category, $context);
        if ($integrityCode !== null) {
            $result['code'] = $integrityCode;
            $result['message'] = self::codeMessage($integrityCode);

            return $result;
        }

        // Expose server-derived source identity for snapshot/compare callers.
        if (isset($context['source_version_identity'])) {
            $result['source_version_identity'] = $context['source_version_identity'];
        }
        if (isset($context['current_sha256'])) {
            $result['source_sha256'] = $context['current_sha256'];
        }

        $result['eligible'] = true;
        $result['code'] = self::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST;
        $result['message'] = self::codeMessage(self::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST);

        return $result;
    }

    /**
     * Final execution eligibility: pre-approval PLUS persisted APPROVED request + snapshot match.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $approvedRequest retention_imha_talepleri row
     * @param DateTimeImmutable|null $asOf
     * @return array<string, mixed>
     */
    public static function evaluateFinalExecutionEligibility(
        PDO $pdo,
        $category,
        array $context,
        $approvedRequest = null,
        DateTimeImmutable $asOf = null
    ) {
        $pre = self::evaluatePreApprovalEligibility($pdo, $category, $context, $asOf);
        if (($pre['code'] ?? '') !== self::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST) {
            return $pre;
        }

        if (!empty($pre['source_version_identity'])) {
            $context['source_version_identity'] = $pre['source_version_identity'];
        }
        if (!empty($pre['source_sha256'])) {
            $context['current_sha256'] = $pre['source_sha256'];
        }

        if (!is_array($approvedRequest)
            || (string) ($approvedRequest['status'] ?? '') !== DestructionWorkflowService::STATUS_APPROVED
            || empty($approvedRequest['approved_by'])
            || empty($approvedRequest['approved_at'])
        ) {
            $pre['eligible'] = false;
            $pre['code'] = self::CODE_DESTRUCTION_REQUEST_NOT_APPROVED;
            $pre['message'] = self::codeMessage(self::CODE_DESTRUCTION_REQUEST_NOT_APPROVED);

            return $pre;
        }

        // GM-owned approval provenance: APPROVE audit must exist for this request.
        if (!self::hasGmApprovalAudit($pdo, $approvedRequest)) {
            $pre['eligible'] = false;
            $pre['code'] = self::CODE_DESTRUCTION_REQUEST_NOT_APPROVED;
            $pre['message'] = self::codeMessage(self::CODE_DESTRUCTION_REQUEST_NOT_APPROVED);

            return $pre;
        }

        // Required snapshots missing → fail-closed (never skip comparison).
        $snapCode = self::requiredSnapshotsMissingCode($approvedRequest, $context);
        if ($snapCode !== null) {
            $pre['eligible'] = false;
            $pre['code'] = $snapCode;
            $pre['message'] = self::codeMessage($snapCode);

            return $pre;
        }

        // Source context snapshots must match current canonical resolution.
        $mismatch = self::compareRequestSnapshots($approvedRequest, $pre, $context);
        if ($mismatch) {
            $pre['eligible'] = false;
            $pre['code'] = self::CODE_SOURCE_CONTEXT_CHANGED;
            $pre['message'] = self::codeMessage(self::CODE_SOURCE_CONTEXT_CHANGED);

            return $pre;
        }

        $pre['eligible'] = true;
        $pre['code'] = self::CODE_APPROVED_FOR_DESTRUCTION;
        $pre['message'] = self::codeMessage(self::CODE_APPROVED_FOR_DESTRUCTION);

        return $pre;
    }

    /**
     * Legacy service entry: final eligibility only (no physical mutation).
     * Physical execute must go through PhysicalDestructionService::execute / HTTP execute.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed>|null $approvedRequest
     * @return array<string, mixed>
     */
    public static function executeDestruction(PDO $pdo, $category, array $context, $approvedRequest = null)
    {
        $eligibility = self::evaluateFinalExecutionEligibility(
            $pdo,
            $category,
            $context,
            $approvedRequest,
            null
        );
        if (($eligibility['code'] ?? '') !== self::CODE_APPROVED_FOR_DESTRUCTION) {
            return $eligibility;
        }

        if (!\Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService::isEnabled()) {
            $eligibility['eligible'] = false;
            $eligibility['code'] = self::CODE_DESTRUCTION_EXECUTION_DISABLED;
            $eligibility['message'] = self::codeMessage(self::CODE_DESTRUCTION_EXECUTION_DISABLED);

            return $eligibility;
        }

        // Eligible for physical path — caller must use plan/execute with plan_hash.
        $eligibility['handler_version'] = \Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes::HANDLER_VERSION;
        $eligibility['message'] = 'Onayli imha; fiziksel execute plan_hash ile ayridir.';

        return $eligibility;
    }

    /**
     * Rehire-safe termination date: PASIF + latest non-IPTAL ISTEN_AYRILMA (DESC), not MIN.
     *
     * @return string|null Y-m-d
     */
    public static function resolveTerminationDate(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT aktif_durum FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$personel) {
            return null;
        }

        $aktifDurum = strtoupper(trim((string) ($personel['aktif_durum'] ?? '')));
        if ($aktifDurum === 'AKTIF') {
            return null;
        }

        if ($aktifDurum === 'PASIF' && self::tableExists($pdo, 'surecler')) {
            $stmt = $pdo->prepare(
                "SELECT baslangic_tarihi
                 FROM surecler
                 WHERE personel_id = :personel_id
                   AND surec_turu = 'ISTEN_AYRILMA'
                   AND state <> 'IPTAL'
                 ORDER BY baslangic_tarihi DESC, id DESC
                 LIMIT 1"
            );
            $stmt->execute(['personel_id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['baslangic_tarihi'])) {
                return substr((string) $row['baslangic_tarihi'], 0, 10);
            }
        }

        if (self::columnExists($pdo, 'personeller', 'cikis_tarihi')) {
            $stmt = $pdo->prepare('SELECT cikis_tarihi FROM personeller WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $personelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['cikis_tarihi'])) {
                return substr((string) $row['cikis_tarihi'], 0, 10);
            }
        }

        return null;
    }

    public static function codeMessage($code)
    {
        $map = [
            self::CODE_UNKNOWN_CATEGORY => 'Bilinmeyen saklama kategorisi.',
            self::CODE_TRIGGER_NOT_RESOLVED => 'Saklama tetik tarihi cozumlenemedi.',
            self::CODE_PERIOD_NOT_CLOSED => 'Donem kapanisi eksik; ilgili owner kaydi gerekli.',
            self::CODE_TERMINATION_DATE_MISSING => 'Isten ayrilma / cikis tarihi eksik.',
            self::CODE_RETENTION_NOT_MATURE => 'Medisa saklama politikasi suresi dolmadi.',
            self::CODE_LEGAL_HOLD_ACTIVE => 'Aktif legal hold var; imha engellendi.',
            self::CODE_NO_GM_APPROVAL => 'Genel yonetici imha onayi yok.',
            self::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED => 'Arsiv kaynak butunlugu degismis.',
            self::CODE_ARCHIVE_MANIFEST_MISSING => 'Arsiv manifesti zorunlu; bulunamadi.',
            self::CODE_ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE => 'Guncel istihdam donemi arsiv manifesti yok.',
            self::CODE_INTEGRITY_UNKNOWN => 'Arsiv butunlugu dogrulanamadi.',
            self::CODE_ARCHIVE_AUDIT_UNAVAILABLE => 'Arsiv erisim audit tablosu hazir degil.',
            self::CODE_SCHEMA_NOT_READY => 'Saklama semasi hazir degil.',
            self::CODE_SOURCE_CONTEXT_CHANGED => 'Kaynak baglam snapshot ile uyusmuyor.',
            self::CODE_SNAPSHOT_INCOMPLETE => 'Imha talebi snapshot alanlari eksik.',
            self::CODE_ARCHIVED_PERSONEL_READ_ONLY => 'Pasif (arsiv) personel yalnizca okunabilir.',
            self::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST => 'Imha talebi icin uygun (onay bekler).',
            self::CODE_DESTRUCTION_REQUEST_NOT_APPROVED => 'Onaylanmis imha talebi yok.',
            self::CODE_EXECUTION_HANDLER_NOT_IMPLEMENTED => 'Fiziksel imha handler uygulanmadi (guvenli).',
            self::CODE_APPROVED_FOR_DESTRUCTION => 'Imha icin uygun (fiziksel execute ayri).',
            self::CODE_RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED => 'Saklama kaynak adapteri uygulanmadi.',
            self::CODE_DESTRUCTION_EXECUTION_DISABLED => 'Fiziksel imha feature flag kapali.',
            self::CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED => 'Kategori imha politikasi cozumlenmedi.',
            self::CODE_DESTRUCTION_PLAN_CHANGED => 'Imha plani degisti; execute reddedildi.',
            self::CODE_ALREADY_EXECUTED => 'Imha talebi daha once yurutuldu.',
            self::CODE_TARGET_ALREADY_MISSING => 'Hedef kaynak ilk execute oncesi yok; fail-closed.',
            self::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN => 'Bagimli saklama kayitlari hala mevcut.',
            self::CODE_DESTRUCTION_EXECUTED => 'Fiziksel imha tamamlandi.',
        ];

        return isset($map[$code]) ? $map[$code] : 'Saklama degerlendirmesi basarisiz.';
    }

    /**
     * @param array<string, mixed> $context
     * @return string|null blocker code
     */
    /**
     * @param array<string, mixed> $context
     * @return string|null blocker code
     */
    private static function checkMandatoryIntegrity(PDO $pdo, $category, array &$context)
    {
        $entityType = isset($context['entity_type']) ? (string) $context['entity_type'] : '';
        $recordId = isset($context['record_id']) ? (int) $context['record_id'] : 0;
        if ($entityType === '' || $recordId <= 0) {
            return self::CODE_ARCHIVE_MANIFEST_MISSING;
        }

        try {
            RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::manifestTables());
        } catch (RuntimeException $e) {
            return self::CODE_SCHEMA_NOT_READY;
        }

        $currentSha = null;
        $normalizedEntity = $entityType === 'personeller' ? 'personel' : $entityType;
        try {
            $source = RetentionSourceAdapterService::resolve($pdo, $category, $context);
            $currentSha = $source['source_sha256'];
            $context['source_version_identity'] = $source['source_version_identity'];
            if ($currentSha !== null) {
                $context['current_sha256'] = $currentSha;
            }
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, RetentionSourceAdapterService::CODE_NOT_IMPLEMENTED) === 0
                || $msg === self::CODE_RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED
            ) {
                return self::CODE_RETENTION_SOURCE_HANDLER_NOT_IMPLEMENTED;
            }
            if (isset($context['current_sha256']) && is_string($context['current_sha256'])) {
                $currentSha = (string) $context['current_sha256'];
            } elseif ($normalizedEntity === 'personel') {
                $currentSha = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, $recordId);
                if ($currentSha !== null) {
                    $context['current_sha256'] = $currentSha;
                }
            } else {
                return self::CODE_INTEGRITY_UNKNOWN;
            }
        }

        $integrity = ArchiveManifestService::verifySourceIntegrity(
            $pdo,
            $normalizedEntity,
            $recordId,
            $category,
            $currentSha,
            $context
        );

        if ($integrity === ArchiveManifestService::INTEGRITY_OK) {
            return null;
        }
        if ($integrity === RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED) {
            return self::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED;
        }
        if ($integrity === ArchiveManifestService::CODE_ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE
            || $integrity === self::CODE_ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE
        ) {
            return self::CODE_ARCHIVE_MANIFEST_MISSING_CURRENT_LIFECYCLE;
        }
        if ($integrity === self::CODE_ARCHIVE_MANIFEST_MISSING
            || $integrity === ArchiveManifestService::CODE_ARCHIVE_MANIFEST_MISSING
        ) {
            return self::CODE_ARCHIVE_MANIFEST_MISSING;
        }
        if ($integrity === self::CODE_INTEGRITY_UNKNOWN
            || $integrity === ArchiveManifestService::INTEGRITY_UNKNOWN
        ) {
            return self::CODE_INTEGRITY_UNKNOWN;
        }

        return self::CODE_INTEGRITY_UNKNOWN;
    }

    /**
     * @param array<string, mixed> $request
     */
    private static function hasGmApprovalAudit(PDO $pdo, array $request)
    {
        $talepId = isset($request['id']) ? (int) $request['id'] : 0;
        if ($talepId <= 0 || !self::tableExists($pdo, 'retention_imha_auditleri')) {
            return false;
        }
        $stmt = $pdo->prepare(
            "SELECT id FROM retention_imha_auditleri
             WHERE imha_talep_id = :id AND action = 'APPROVE'
             LIMIT 1"
        );
        $stmt->execute(['id' => $talepId]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $context
     * @return string|null
     */
    private static function requiredSnapshotsMissingCode(array $request, array $context)
    {
        $required = [
            'trigger_type_snapshot',
            'trigger_date_snapshot',
            'retention_until_snapshot',
            'source_version_identity_snapshot',
        ];
        foreach ($required as $col) {
            if (!array_key_exists($col, $request) || $request[$col] === null || $request[$col] === '') {
                return self::CODE_SNAPSHOT_INCOMPLETE;
            }
        }

        $entityType = isset($context['entity_type']) ? strtolower((string) $context['entity_type']) : '';
        if (in_array($entityType, ['personel', 'personeller'], true)) {
            if (empty($request['source_sha256_snapshot'])) {
                return self::CODE_SNAPSHOT_INCOMPLETE;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     * @param array<string, mixed> $eligibility
     * @param array<string, mixed> $context
     */
    private static function compareRequestSnapshots(array $request, array $eligibility, array $context)
    {
        $fields = [
            'trigger_type_snapshot' => $eligibility['trigger_type'] ?? null,
            'trigger_date_snapshot' => $eligibility['trigger_date'] ?? null,
            'retention_until_snapshot' => $eligibility['retention_until'] ?? null,
        ];
        foreach ($fields as $col => $expected) {
            if ((string) $request[$col] !== (string) $expected) {
                return true;
            }
        }

        // Old lifecycle snapshot cannot approve new lifecycle.
        if (!empty($request['source_version_identity_snapshot'])) {
            $currentIdentity = isset($context['source_version_identity'])
                ? (string) $context['source_version_identity']
                : '';
            if ($currentIdentity !== ''
                && (string) $request['source_version_identity_snapshot'] !== $currentIdentity
            ) {
                return true;
            }
        }

        if (!empty($request['source_sha256_snapshot']) && !empty($context['current_sha256'])) {
            if (!hash_equals(
                strtolower((string) $request['source_sha256_snapshot']),
                strtolower((string) $context['current_sha256'])
            )) {
                return true;
            }
        }

        if (!empty($request['canonical_sube_id'])
            && isset($context['sube_id'])
            && (int) $request['canonical_sube_id'] !== (int) $context['sube_id']
        ) {
            return true;
        }
        if (!empty($request['period_yil'])
            && isset($context['yil'])
            && (int) $request['period_yil'] !== (int) $context['yil']
        ) {
            return true;
        }
        if (!empty($request['period_ay'])
            && isset($context['ay'])
            && (int) $request['period_ay'] !== (int) $context['ay']
        ) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyResult($category)
    {
        return [
            'eligible' => false,
            'code' => self::CODE_UNKNOWN_CATEGORY,
            'category' => (string) $category,
            'trigger_type' => null,
            'trigger_date' => null,
            'retention_until' => null,
            'policy_note' => RetentionCategories::POLICY_NOTE,
            'message' => '',
        ];
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }

    private static function columnExists(PDO $pdo, $table, $column)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table, 'c' => (string) $column]);

        return (bool) $stmt->fetchColumn();
    }
}
