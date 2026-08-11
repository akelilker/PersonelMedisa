<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use PDO;
use RuntimeException;

/**
 * Destruction request / GM approve / evaluate execution.
 * No automatic delete. REQUESTED only when pre-approval passes.
 * Physical execute always returns EXECUTION_HANDLER_NOT_IMPLEMENTED.
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

        // Server-side fingerprint for personel targets.
        if (($entityType === 'personel' || $entityType === 'personeller') && $recordId > 0) {
            $fp = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, $recordId);
            if ($fp !== null) {
                $context['current_sha256'] = $fp;
            }
        }

        $eligibility = RetentionPolicyService::evaluatePreApprovalEligibility(
            $pdo,
            $category,
            $context,
            null
        );

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
            if (($context['entity_type'] === 'personel' || $context['entity_type'] === 'personeller')
                && (int) $context['record_id'] > 0
            ) {
                $fp = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, (int) $context['record_id']);
                if ($fp !== null) {
                    $context['current_sha256'] = $fp;
                }
            }

            // Re-resolve trigger / maturity / hold / integrity with server clock.
            $eligibility = RetentionPolicyService::evaluatePreApprovalEligibility(
                $pdo,
                (string) $talep['category'],
                $context,
                RetentionClock::now()
            );
            if (($eligibility['code'] ?? '') !== RetentionPolicyService::CODE_ELIGIBLE_FOR_DESTRUCTION_REQUEST) {
                throw new RuntimeException((string) $eligibility['code']);
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
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function evaluateExecution(PDO $pdo, array $user, $talepId)
    {
        RolePermissions::assertAny($user, [
            'retention.destruction.approve',
            'retention.destruction.view',
        ]);

        $talep = self::getById($pdo, (int) $talepId);
        if (!$talep) {
            throw new RuntimeException('DESTRUCTION_REQUEST_NOT_FOUND');
        }

        $context = self::contextFromTalep($talep);
        if (($context['entity_type'] === 'personel' || $context['entity_type'] === 'personeller')
            && (int) $context['record_id'] > 0
        ) {
            $fp = ArchiveManifestService::computePersonelOzlukFingerprint($pdo, (int) $context['record_id']);
            if ($fp !== null) {
                $context['current_sha256'] = $fp;
            }
        }

        $result = RetentionPolicyService::executeDestruction(
            $pdo,
            (string) $talep['category'],
            $context,
            $talep
        );

        $actorId = (int) ($user['id'] ?? 0);
        if ($actorId > 0) {
            self::appendAudit(
                $pdo,
                (int) $talep['id'],
                (string) $talep['category'],
                (string) $talep['entity_type'],
                (int) $talep['record_id'],
                $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
                'EVALUATE_EXECUTION',
                $actorId,
                null,
                $result['code'] ?? null
            );
        }

        return [
            'item' => $talep,
            'execution' => $result,
        ];
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

        if (is_array($allowedSubeIds) && count($allowedSubeIds) > 0) {
            $sql .= ' LEFT JOIN personeller p ON p.id = t.personel_id';
            $placeholders = [];
            foreach (array_values($allowedSubeIds) as $i => $sid) {
                $key = 'sube_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $sid;
            }
            $where[] = '(t.personel_id IS NULL OR p.sube_id IN (' . implode(',', $placeholders) . '))';
        }

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

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int>|null $allowedSubeIds
     * @return array<int, array<string, mixed>>
     */
    public static function listAudits(PDO $pdo, $limit = 200, $allowedSubeIds = null)
    {
        $limit = max(1, min(500, (int) $limit));
        $sql = 'SELECT a.* FROM retention_imha_auditleri a';
        $params = [];

        if (is_array($allowedSubeIds) && count($allowedSubeIds) > 0) {
            $sql .= ' LEFT JOIN personeller p ON p.id = a.personel_id';
            $placeholders = [];
            foreach (array_values($allowedSubeIds) as $i => $sid) {
                $key = 'sube_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $sid;
            }
            $sql .= ' WHERE (a.personel_id IS NULL OR p.sube_id IN (' . implode(',', $placeholders) . '))';
        }

        $sql .= ' ORDER BY a.id DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
            $manifest = ArchiveManifestService::find(
                $pdo,
                $entityType === 'personeller' ? 'personel' : $entityType,
                $recordId,
                $category
            );
        }

        $sourceIdentity = null;
        if ($manifest) {
            $sourceIdentity = (string) ($manifest['source_version_identity'] ?? '');
        } elseif (isset($context['sube_id'], $context['yil'], $context['ay'])) {
            $sourceIdentity = sprintf(
                'sube:%d:%d:%d',
                (int) $context['sube_id'],
                (int) $context['yil'],
                (int) $context['ay']
            );
        }

        return [
            'retention_until_snapshot' => $eligibility['retention_until'] ?? null,
            'source_identity_snapshot' => $sourceIdentity,
            'trigger_type_snapshot' => $eligibility['trigger_type'] ?? null,
            'trigger_date_snapshot' => $eligibility['trigger_date'] ?? null,
            'source_version_identity_snapshot' => $manifest
                ? (string) ($manifest['source_version_identity'] ?? '')
                : $sourceIdentity,
            'source_sha256_snapshot' => $manifest && !empty($manifest['source_sha256'])
                ? (string) $manifest['source_sha256']
                : (isset($context['current_sha256']) ? (string) $context['current_sha256'] : null),
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
        if (empty($context['sube_id'])
            && !empty($talep['source_identity_snapshot'])
            && preg_match('/^sube:(\d+):(\d{4}):(\d{1,2})$/', (string) $talep['source_identity_snapshot'], $m)
        ) {
            $context['sube_id'] = (int) $m[1];
            $context['yil'] = (int) $m[2];
            $context['ay'] = (int) $m[3];
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $eligibility
     * @param array<string, mixed> $context
     */
    private static function snapshotsMismatch(array $talep, array $eligibility, array $context)
    {
        if (!empty($talep['trigger_type_snapshot'])
            && (string) $talep['trigger_type_snapshot'] !== (string) ($eligibility['trigger_type'] ?? '')
        ) {
            return true;
        }
        if (!empty($talep['trigger_date_snapshot'])
            && (string) $talep['trigger_date_snapshot'] !== (string) ($eligibility['trigger_date'] ?? '')
        ) {
            return true;
        }
        if (!empty($talep['retention_until_snapshot'])
            && (string) $talep['retention_until_snapshot'] !== (string) ($eligibility['retention_until'] ?? '')
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
