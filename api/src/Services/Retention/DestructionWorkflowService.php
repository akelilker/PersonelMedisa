<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use PDO;
use RuntimeException;

/**
 * Destruction request / GM approve / evaluate orchestration.
 * Physical mutation owned by PhysicalDestructionService (feature-flagged, plan-hash gated).
 */
class DestructionWorkflowService
{
    public const STATUS_REQUESTED = 'REQUESTED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';
    public const STATUS_BLOCKED = 'BLOCKED';

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function requestDestruction(PDO $pdo, array $user, array $payload)
    {
        RolePermissions::assert($user, 'retention.destruction.request');
        RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::destructionTables());

        $category = trim((string) ($payload['category'] ?? ''));
        $entityType = trim((string) ($payload['entity_type'] ?? ''));
        $recordId = (int) ($payload['record_id'] ?? 0);
        $personelId = isset($payload['personel_id']) ? (int) $payload['personel_id'] : null;
        if ($personelId !== null && $personelId <= 0) {
            $personelId = null;
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        $actorId = (int) ($user['id'] ?? 0);

        if ($category === '' || $entityType === '' || $recordId <= 0 || $reason === '' || $actorId <= 0) {
            throw new RuntimeException('DESTRUCTION_REQUEST_INVALID');
        }

        $periodHints = [];
        foreach (['sube_id', 'yil', 'ay', 'haftalik_kapanis_id', 'hafta_baslangic', 'parent_category'] as $key) {
            if (isset($payload[$key])) {
                $periodHints[$key] = $payload[$key];
            }
        }

        $context = RetentionTargetResolver::validateAndResolve(
            $pdo,
            $category,
            $entityType,
            $recordId,
            $personelId,
            $periodHints
        );
        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : null;
        if ($personelId !== null && $personelId <= 0) {
            $personelId = null;
        }
        $entityType = (string) $context['entity_type'];
        $recordId = (int) $context['record_id'];

        // Server-side fingerprint / source identity via canonical adapter.
        try {
            $source = RetentionSourceAdapterService::resolve($pdo, $category, $context);
            $context['source_version_identity'] = $source['source_version_identity'];
            if ($source['source_sha256'] !== null) {
                $context['current_sha256'] = $source['source_sha256'];
            }
        } catch (RuntimeException $e) {
            if (strpos($e->getMessage(), RetentionSourceAdapterService::CODE_NOT_IMPLEMENTED) === 0) {
                throw $e;
            }
            if (($entityType === 'personel' || $entityType === 'personeller') && $recordId > 0) {
                $fp = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, $recordId);
                if ($fp !== null) {
                    $context['current_sha256'] = $fp;
                }
            }
        }

        $eligibility = RetentionPolicyService::evaluatePreApprovalEligibility(
            $pdo,
            $category,
            $context,
            null
        );
        if (!empty($eligibility['source_version_identity'])) {
            $context['source_version_identity'] = $eligibility['source_version_identity'];
        }
        if (!empty($eligibility['source_sha256'])) {
            $context['current_sha256'] = $eligibility['source_sha256'];
        }

        $snapshots = self::buildSnapshots($pdo, $category, $context, $eligibility);
        $status = (($eligibility['code'] ?? '') === RetentionPolicyService::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST)
            ? self::STATUS_REQUESTED
            : self::STATUS_BLOCKED;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO retention_imha_talepleri
                    (category, entity_type, record_id, personel_id, reason, status,
                     requested_by, retention_until_snapshot, source_identity_snapshot,
                     trigger_type_snapshot, trigger_date_snapshot,
                     source_version_identity_snapshot, source_sha256_snapshot,
                     canonical_sube_id, period_yil, period_ay)
                 VALUES
                    (:category, :entity_type, :record_id, :personel_id, :reason, :status,
                     :requested_by, :retention_until, :source_identity,
                     :trigger_type, :trigger_date,
                     :source_version_identity, :source_sha256,
                     :canonical_sube_id, :period_yil, :period_ay)'
            );
            $stmt->execute([
                'category' => $category,
                'entity_type' => $entityType,
                'record_id' => $recordId,
                'personel_id' => $personelId,
                'reason' => $reason,
                'status' => $status,
                'requested_by' => $actorId,
                'retention_until' => $snapshots['retention_until_snapshot'],
                'source_identity' => $snapshots['source_identity_snapshot'],
                'trigger_type' => $snapshots['trigger_type_snapshot'],
                'trigger_date' => $snapshots['trigger_date_snapshot'],
                'source_version_identity' => $snapshots['source_version_identity_snapshot'],
                'source_sha256' => $snapshots['source_sha256_snapshot'],
                'canonical_sube_id' => $snapshots['canonical_sube_id'],
                'period_yil' => $snapshots['period_yil'],
                'period_ay' => $snapshots['period_ay'],
            ]);
            $id = (int) $pdo->lastInsertId();
            self::appendAudit(
                $pdo,
                $id,
                $category,
                $entityType,
                $recordId,
                $personelId,
                $status === self::STATUS_REQUESTED ? 'REQUEST' : 'BLOCKED',
                $actorId,
                $reason,
                $eligibility['code']
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'item' => self::getById($pdo, $id),
            'eligibility' => $eligibility,
        ];
    }

    /**
     * GM only — approve=true re-checks maturity/hold/integrity/snapshots in one transaction.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function approveDestruction(PDO $pdo, array $user, $talepId, $approvalReason, $approve = true)
    {
        RolePermissions::assert($user, 'retention.destruction.approve');
        $role = strtoupper(trim((string) ($user['rol'] ?? '')));
        if ($role !== 'GENEL_YONETICI') {
            JsonResponse::forbidden('Imha onayi yalnizca genel yonetici tarafindan verilir.');
        }

        $talepId = (int) $talepId;
        $approvalReason = trim((string) $approvalReason);
        $actorId = (int) ($user['id'] ?? 0);
        if ($talepId <= 0 || $approvalReason === '' || $actorId <= 0) {
            throw new RuntimeException('DESTRUCTION_APPROVAL_INVALID');
        }

        $pdo->beginTransaction();
        try {
            $talep = self::getById($pdo, $talepId, true);
            if (!$talep) {
                throw new RuntimeException('DESTRUCTION_REQUEST_NOT_FOUND');
            }
            if ((string) $talep['status'] !== self::STATUS_REQUESTED) {
                throw new RuntimeException('DESTRUCTION_REQUEST_NOT_OPEN');
            }

            if (!$approve) {
                $stmt = $pdo->prepare(
                    'UPDATE retention_imha_talepleri
                     SET status = :status,
                         approved_by = :approved_by,
                         approved_at = CURRENT_TIMESTAMP,
                         approval_reason = :reason
                     WHERE id = :id'
                );
                $stmt->execute([
                    'status' => self::STATUS_REJECTED,
                    'approved_by' => $actorId,
                    'reason' => $approvalReason,
                    'id' => $talepId,
                ]);
                self::appendAudit(
                    $pdo,
                    $talepId,
                    (string) $talep['category'],
                    (string) $talep['entity_type'],
                    (int) $talep['record_id'],
                    $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
                    'REJECT',
                    $actorId,
                    $approvalReason,
                    self::STATUS_REJECTED
                );
                $pdo->commit();

                return self::getById($pdo, $talepId);
            }

            $context = self::contextFromTalep($talep);
            try {
                $source = RetentionSourceAdapterService::resolve($pdo, (string) $talep['category'], $context);
                $context['source_version_identity'] = $source['source_version_identity'];
                if ($source['source_sha256'] !== null) {
                    $context['current_sha256'] = $source['source_sha256'];
                }
            } catch (RuntimeException $e) {
                if (($context['entity_type'] === 'personel' || $context['entity_type'] === 'personeller')
                    && (int) $context['record_id'] > 0
                ) {
                    $fp = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, (int) $context['record_id']);
                    if ($fp !== null) {
                        $context['current_sha256'] = $fp;
                    }
                }
            }

            // Re-resolve trigger / maturity / hold / integrity with server clock.
            $eligibility = RetentionPolicyService::evaluatePreApprovalEligibility(
                $pdo,
                (string) $talep['category'],
                $context,
                RetentionClock::now()
            );
            if (!empty($eligibility['source_version_identity'])) {
                $context['source_version_identity'] = $eligibility['source_version_identity'];
            }
            if (!empty($eligibility['source_sha256'])) {
                $context['current_sha256'] = $eligibility['source_sha256'];
            }
            if (($eligibility['code'] ?? '') !== RetentionPolicyService::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST) {
                throw new RuntimeException((string) $eligibility['code']);
            }

            // Required snapshots must be present (fail-closed).
            if (self::requiredSnapshotsIncomplete($talep, $context)) {
                throw new RuntimeException(RetentionPolicyService::CODE_SNAPSHOT_INCOMPLETE);
            }

            // Snapshot vs current canonical context.
            if (self::snapshotsMismatch($talep, $eligibility, $context)) {
                throw new RuntimeException(RetentionPolicyService::CODE_SOURCE_CONTEXT_CHANGED);
            }

            $stmt = $pdo->prepare(
                'UPDATE retention_imha_talepleri
                 SET status = :status,
                     approved_by = :approved_by,
                     approved_at = CURRENT_TIMESTAMP,
                     approval_reason = :reason
                 WHERE id = :id'
            );
            $stmt->execute([
                'status' => self::STATUS_APPROVED,
                'approved_by' => $actorId,
                'reason' => $approvalReason,
                'id' => $talepId,
            ]);
            self::appendAudit(
                $pdo,
                $talepId,
                (string) $talep['category'],
                (string) $talep['entity_type'],
                (int) $talep['record_id'],
                $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
                'APPROVE',
                $actorId,
                $approvalReason,
                self::STATUS_APPROVED
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::getById($pdo, $talepId);
    }

    /**
     * Non-destructive evaluate: eligibility + plan (no physical mutation).
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function evaluateExecution(PDO $pdo, array $user, $talepId)
    {
        return \Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService::evaluate(
            $pdo,
            $user,
            $talepId
        );
    }

    /**
     * Physical execute — delegates to PhysicalDestructionService.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function executePhysicalDestruction(PDO $pdo, array $user, $talepId, array $payload)
    {
        return \Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService::execute(
            $pdo,
            $user,
            $talepId,
            $payload
        );
    }

    /**
     * Public audit append for PhysicalDestructionService (same table owner).
     */
    public static function appendAuditPublic(
        PDO $pdo,
        $talepId,
        $category,
        $entityType,
        $recordId,
        $personelId,
        $action,
        $actorUserId,
        $reason,
        $resultCode
    ) {
        self::appendAudit(
            $pdo,
            $talepId,
            $category,
            $entityType,
            $recordId,
            $personelId,
            $action,
            $actorUserId,
            $reason,
            $resultCode
        );
    }

    /**
     * @param array<int>|null $allowedSubeIds
     * @return array<int, array<string, mixed>>
     */
    public static function listRequests(PDO $pdo, $status = null, $allowedSubeIds = null)
    {
        $sql = 'SELECT t.* FROM retention_imha_talepleri t';
        $params = [];
        $where = [];

        if ($status !== null && $status !== '') {
            $where[] = 't.status = :status';
            $params['status'] = (string) $status;
        }

        if (count($where) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return RetentionScopeResolver::filterRowsBySubeScope($pdo, $rows, $allowedSubeIds);
    }

    /**
     * @param array<int>|null $allowedSubeIds
     * @return array<int, array<string, mixed>>
     */
    public static function listAudits(PDO $pdo, $limit = 200, $allowedSubeIds = null)
    {
        $limit = max(1, min(500, (int) $limit));
        $sql = 'SELECT a.* FROM retention_imha_auditleri a ORDER BY a.id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute([]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return RetentionScopeResolver::filterRowsBySubeScope($pdo, $rows, $allowedSubeIds);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getById(PDO $pdo, $id, $forUpdate = false)
    {
        $sql = 'SELECT * FROM retention_imha_talepleri WHERE id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $eligibility
     * @return array<string, mixed>
     */
    private static function buildSnapshots(PDO $pdo, $category, array $context, array $eligibility)
    {
        $entityType = (string) ($context['entity_type'] ?? '');
        $recordId = (int) ($context['record_id'] ?? 0);
        $manifest = null;
        if ($entityType !== '' && $recordId > 0) {
            $manifest = ArchiveManifestService::findCurrentLifecycleManifest(
                $pdo,
                $entityType === 'personeller' ? 'personel' : $entityType,
                $recordId,
                $category,
                $context
            );
        }

        $sourceIdentity = null;
        if (!empty($context['source_version_identity'])) {
            $sourceIdentity = (string) $context['source_version_identity'];
        } elseif ($manifest) {
            $sourceIdentity = (string) ($manifest['source_version_identity'] ?? '');
        } elseif (!empty($eligibility['source_version_identity'])) {
            $sourceIdentity = (string) $eligibility['source_version_identity'];
        }

        $sha = null;
        if ($manifest && !empty($manifest['source_sha256'])) {
            $sha = (string) $manifest['source_sha256'];
        } elseif (isset($context['current_sha256'])) {
            $sha = (string) $context['current_sha256'];
        } elseif (!empty($eligibility['source_sha256'])) {
            $sha = (string) $eligibility['source_sha256'];
        }

        return [
            'retention_until_snapshot' => $eligibility['retention_until'] ?? null,
            'source_identity_snapshot' => $sourceIdentity,
            'trigger_type_snapshot' => $eligibility['trigger_type'] ?? null,
            'trigger_date_snapshot' => $eligibility['trigger_date'] ?? null,
            'source_version_identity_snapshot' => $sourceIdentity,
            'source_sha256_snapshot' => $sha,
            'canonical_sube_id' => isset($context['sube_id']) ? (int) $context['sube_id'] : null,
            'period_yil' => isset($context['yil']) ? (int) $context['yil'] : null,
            'period_ay' => isset($context['ay']) ? (int) $context['ay'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $talep
     * @return array<string, mixed>
     */
    private static function contextFromTalep(array $talep)
    {
        $context = [
            'personel_id' => $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
            'entity_type' => (string) $talep['entity_type'],
            'record_id' => (int) $talep['record_id'],
            'category' => (string) ($talep['category'] ?? ''),
        ];
        if (!empty($talep['canonical_sube_id'])) {
            $context['sube_id'] = (int) $talep['canonical_sube_id'];
        }
        if (!empty($talep['period_yil'])) {
            $context['yil'] = (int) $talep['period_yil'];
        }
        if (!empty($talep['period_ay'])) {
            $context['ay'] = (int) $talep['period_ay'];
        }
        if (!empty($talep['source_version_identity_snapshot'])) {
            $context['source_version_identity'] = (string) $talep['source_version_identity_snapshot'];
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     */
    private static function requiredSnapshotsIncomplete(array $talep, array $context)
    {
        foreach ([
            'trigger_type_snapshot',
            'trigger_date_snapshot',
            'retention_until_snapshot',
            'source_version_identity_snapshot',
        ] as $col) {
            if (!array_key_exists($col, $talep) || $talep[$col] === null || $talep[$col] === '') {
                return true;
            }
        }
        $entityType = isset($context['entity_type']) ? strtolower((string) $context['entity_type']) : '';
        if (in_array($entityType, ['personel', 'personeller'], true)
            && empty($talep['source_sha256_snapshot'])
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $eligibility
     * @param array<string, mixed> $context
     */
    private static function snapshotsMismatch(array $talep, array $eligibility, array $context)
    {
        if ((string) $talep['trigger_type_snapshot'] !== (string) ($eligibility['trigger_type'] ?? '')) {
            return true;
        }
        if ((string) $talep['trigger_date_snapshot'] !== (string) ($eligibility['trigger_date'] ?? '')) {
            return true;
        }
        if ((string) $talep['retention_until_snapshot'] !== (string) ($eligibility['retention_until'] ?? '')) {
            return true;
        }
        $currentIdentity = isset($context['source_version_identity'])
            ? (string) $context['source_version_identity']
            : (string) ($eligibility['source_version_identity'] ?? '');
        if ($currentIdentity !== ''
            && (string) $talep['source_version_identity_snapshot'] !== $currentIdentity
        ) {
            return true;
        }
        if (!empty($talep['canonical_sube_id'])
            && isset($context['sube_id'])
            && (int) $talep['canonical_sube_id'] !== (int) $context['sube_id']
        ) {
            return true;
        }
        if (!empty($talep['source_sha256_snapshot']) && !empty($context['current_sha256'])) {
            if (!hash_equals(
                strtolower((string) $talep['source_sha256_snapshot']),
                strtolower((string) $context['current_sha256'])
            )) {
                return true;
            }
        }

        return false;
    }

    private static function appendAudit(
        PDO $pdo,
        $talepId,
        $category,
        $entityType,
        $recordId,
        $personelId,
        $action,
        $actorUserId,
        $reason,
        $resultCode
    ) {
        $stmt = $pdo->prepare(
            'INSERT INTO retention_imha_auditleri
                (imha_talep_id, category, entity_type, record_id, personel_id,
                 action, actor_user_id, reason, result_code)
             VALUES
                (:talep_id, :category, :entity_type, :record_id, :personel_id,
                 :action, :actor, :reason, :result_code)'
        );
        $stmt->execute([
            'talep_id' => $talepId !== null ? (int) $talepId : null,
            'category' => (string) $category,
            'entity_type' => (string) $entityType,
            'record_id' => (int) $recordId,
            'personel_id' => $personelId,
            'action' => (string) $action,
            'actor' => (int) $actorUserId,
            'reason' => $reason !== null && $reason !== '' ? (string) $reason : null,
            'result_code' => $resultCode !== null ? (string) $resultCode : null,
        ]);
    }
}
