<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use PDO;
use RuntimeException;

/**
 * Legal hold create/release — GENEL_YONETICI only (legal_hold.manage).
 * Release does not delete anything.
 */
class LegalHoldService
{
    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_RELEASED = 'RELEASED';

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, array $user, array $payload)
    {
        self::assertManage($user);

        $domain = trim((string) ($payload['target_domain'] ?? ''));
        $category = isset($payload['target_category']) ? trim((string) $payload['target_category']) : null;
        if ($category === '') {
            $category = null;
        }
        $targetRecordId = isset($payload['target_record_id']) ? (int) $payload['target_record_id'] : null;
        if ($targetRecordId !== null && $targetRecordId <= 0) {
            $targetRecordId = null;
        }
        $personelId = isset($payload['personel_id']) ? (int) $payload['personel_id'] : null;
        if ($personelId !== null && $personelId <= 0) {
            $personelId = null;
        }
        $reason = trim((string) ($payload['reason'] ?? ''));

        if ($domain === '' || $reason === '') {
            throw new RuntimeException('LEGAL_HOLD_INVALID_PAYLOAD');
        }
        if ($personelId === null && $targetRecordId === null && $category === null) {
            throw new RuntimeException('LEGAL_HOLD_TARGET_REQUIRED');
        }

        $actorId = (int) ($user['id'] ?? 0);
        if ($actorId <= 0) {
            throw new RuntimeException('LEGAL_HOLD_ACTOR_REQUIRED');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO legal_holdlar
                    (target_domain, target_category, target_record_id, personel_id, reason, hold_state, created_by)
                 VALUES
                    (:domain, :category, :record_id, :personel_id, :reason, :state, :created_by)'
            );
            $stmt->execute([
                'domain' => $domain,
                'category' => $category,
                'record_id' => $targetRecordId,
                'personel_id' => $personelId,
                'reason' => $reason,
                'state' => self::STATE_ACTIVE,
                'created_by' => $actorId,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::appendAudit($pdo, $id, 'CREATE', $actorId, $reason, [
                'target_domain' => $domain,
                'target_category' => $category,
                'target_record_id' => $targetRecordId,
                'personel_id' => $personelId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::getById($pdo, $id);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function release(PDO $pdo, array $user, $holdId, $releaseReason)
    {
        self::assertManage($user);

        $holdId = (int) $holdId;
        $releaseReason = trim((string) $releaseReason);
        if ($holdId <= 0 || $releaseReason === '') {
            throw new RuntimeException('LEGAL_HOLD_RELEASE_INVALID');
        }

        $actorId = (int) ($user['id'] ?? 0);
        if ($actorId <= 0) {
            throw new RuntimeException('LEGAL_HOLD_ACTOR_REQUIRED');
        }

        $pdo->beginTransaction();
        try {
            $hold = self::getById($pdo, $holdId, true);
            if (!$hold) {
                throw new RuntimeException('LEGAL_HOLD_NOT_FOUND');
            }
            if ((string) $hold['hold_state'] === self::STATE_RELEASED) {
                $pdo->commit();

                return $hold;
            }

            $stmt = $pdo->prepare(
                "UPDATE legal_holdlar
                 SET hold_state = :state,
                     released_by = :released_by,
                     released_at = CURRENT_TIMESTAMP,
                     release_reason = :reason
                 WHERE id = :id AND hold_state = 'ACTIVE'"
            );
            $stmt->execute([
                'state' => self::STATE_RELEASED,
                'released_by' => $actorId,
                'reason' => $releaseReason,
                'id' => $holdId,
            ]);
            self::appendAudit($pdo, $holdId, 'RELEASE', $actorId, $releaseReason, null);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::getById($pdo, $holdId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function list(PDO $pdo, $activeOnly = true)
    {
        $sql = 'SELECT * FROM legal_holdlar';
        if ($activeOnly) {
            $sql .= " WHERE hold_state = 'ACTIVE'";
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $pdo->query($sql);

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getById(PDO $pdo, $id, $forUpdate = false)
    {
        $sql = 'SELECT * FROM legal_holdlar WHERE id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function assertManage(array $user)
    {
        RolePermissions::assert($user, 'legal_hold.manage');
        $role = strtoupper(trim((string) ($user['rol'] ?? '')));
        if ($role !== 'GENEL_YONETICI') {
            JsonResponse::forbidden('Legal hold yalnizca genel yonetici tarafindan yonetilir.');
        }
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private static function appendAudit(PDO $pdo, $holdId, $action, $actorUserId, $reason, $metadata)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO legal_hold_auditleri
                (legal_hold_id, action, actor_user_id, reason, metadata_json)
             VALUES
                (:hold_id, :action, :actor, :reason, :metadata)'
        );
        $stmt->execute([
            'hold_id' => (int) $holdId,
            'action' => (string) $action,
            'actor' => (int) $actorUserId,
            'reason' => $reason !== null && $reason !== '' ? (string) $reason : null,
            'metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }
}
