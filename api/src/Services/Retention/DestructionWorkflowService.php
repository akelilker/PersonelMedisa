<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use PDO;
use RuntimeException;

/**
 * Destruction request / GM approve / evaluate execution.
 * No automatic delete. No export-before-delete gate.
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

        $context = self::buildContext($payload, $personelId, $entityType, $recordId);
        $eligibility = RetentionPolicyService::evaluateDestructionEligibility($pdo, $category, $context);

        $status = self::STATUS_REQUESTED;
        if (in_array($eligibility['code'], [
            RetentionPolicyService::CODE_UNKNOWN_CATEGORY,
            RetentionPolicyService::CODE_PERIOD_NOT_CLOSED,
            RetentionPolicyService::CODE_TERMINATION_DATE_MISSING,
            RetentionPolicyService::CODE_TRIGGER_NOT_RESOLVED,
            RetentionPolicyService::CODE_LEGAL_HOLD_ACTIVE,
            RetentionPolicyService::CODE_ARCHIVE_SOURCE_INTEGRITY_CHANGED,
        ], true)) {
            $status = self::STATUS_BLOCKED;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO retention_imha_talepleri
                    (category, entity_type, record_id, personel_id, reason, status,
                     requested_by, retention_until_snapshot, source_identity_snapshot)
                 VALUES
                    (:category, :entity_type, :record_id, :personel_id, :reason, :status,
                     :requested_by, :retention_until, :source_identity)'
            );
            $stmt->execute([
                'category' => $category,
                'entity_type' => $entityType,
                'record_id' => $recordId,
                'personel_id' => $personelId,
                'reason' => $reason,
                'status' => $status,
                'requested_by' => $actorId,
                'retention_until' => $eligibility['retention_until'] ?? null,
                'source_identity' => isset($payload['source_identity'])
                    ? (string) $payload['source_identity']
                    : null,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::appendAudit(
                $pdo,
                $id,
                $category,
                $entityType,
                $recordId,
                $personelId,
                'REQUEST',
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

        $item = self::getById($pdo, $id);

        return [
            'item' => $item,
            'eligibility' => $eligibility,
        ];
    }

    /**
     * GM only — legal_hold.manage sibling: retention.destruction.approve.
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

            $newStatus = $approve ? self::STATUS_APPROVED : self::STATUS_REJECTED;
            $stmt = $pdo->prepare(
                'UPDATE retention_imha_talepleri
                 SET status = :status,
                     approved_by = :approved_by,
                     approved_at = CURRENT_TIMESTAMP,
                     approval_reason = :reason
                 WHERE id = :id'
            );
            $stmt->execute([
                'status' => $newStatus,
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
                $approve ? 'APPROVE' : 'REJECT',
                $actorId,
                $approvalReason,
                $newStatus
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
     * Re-check eligibility then always return EXECUTION_HANDLER_NOT_IMPLEMENTED.
     *
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

        $context = [
            'personel_id' => $talep['personel_id'] !== null ? (int) $talep['personel_id'] : null,
            'entity_type' => (string) $talep['entity_type'],
            'record_id' => (int) $talep['record_id'],
            'gm_approved' => (string) $talep['status'] === self::STATUS_APPROVED,
            'has_gm_approval' => (string) $talep['status'] === self::STATUS_APPROVED,
        ];
        // Period context may be stored in source_identity_snapshot as "sube:yil:ay" optionally.
        if (!empty($talep['source_identity_snapshot'])
            && preg_match('/^sube:(\d+):(\d{4}):(\d{1,2})$/', (string) $talep['source_identity_snapshot'], $m)
        ) {
            $context['sube_id'] = (int) $m[1];
            $context['yil'] = (int) $m[2];
            $context['ay'] = (int) $m[3];
        }

        $result = RetentionPolicyService::executeDestruction($pdo, (string) $talep['category'], $context);

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
     * @return array<int, array<string, mixed>>
     */
    public static function listRequests(PDO $pdo, $status = null)
    {
        $sql = 'SELECT * FROM retention_imha_talepleri';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = :status';
            $params['status'] = (string) $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listAudits(PDO $pdo, $limit = 200)
    {
        $limit = max(1, min(500, (int) $limit));
        $stmt = $pdo->prepare(
            'SELECT * FROM retention_imha_auditleri ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute();

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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function buildContext(array $payload, $personelId, $entityType, $recordId)
    {
        $context = [
            'personel_id' => $personelId,
            'entity_type' => $entityType,
            'record_id' => $recordId,
            'gm_approved' => false,
            'has_gm_approval' => false,
        ];
        if (isset($payload['sube_id'])) {
            $context['sube_id'] = (int) $payload['sube_id'];
        }
        if (isset($payload['yil'])) {
            $context['yil'] = (int) $payload['yil'];
        }
        if (isset($payload['ay'])) {
            $context['ay'] = (int) $payload['ay'];
        }
        if (isset($payload['as_of'])) {
            $context['as_of'] = (string) $payload['as_of'];
        }
        if (!empty($payload['check_integrity'])) {
            $context['check_integrity'] = true;
            if (isset($payload['current_sha256'])) {
                $context['current_sha256'] = (string) $payload['current_sha256'];
            }
        }

        return $context;
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
