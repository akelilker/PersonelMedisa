<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Http\JsonResponse;
use PDO;
use RuntimeException;

/**
 * Legal hold create/release — GENEL_YONETICI only (legal_hold.manage).
 * Release does not delete anything. Schema gate before create.
 */
class LegalHoldService
{
    public const STATE_ACTIVE = 'ACTIVE';
    public const STATE_RELEASED = 'RELEASED';
    public const CODE_TARGET_UNSUPPORTED = 'LEGAL_HOLD_TARGET_UNSUPPORTED';

    /** @var array<int, string> */
    private static $knownDomains = [
        'personel',
        'personeller',
        'surec',
        'surecler',
        'belge',
        'belge_kaydi',
        'retention',
        'category',
        'puantaj',
        'bordro',
    ];

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, array $user, array $payload)
    {
        self::assertManage($user);
        RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::legalHoldTables());

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
        if (!in_array(strtolower($domain), self::$knownDomains, true)
            && !RetentionCategories::isKnown($domain)
        ) {
            throw new RuntimeException('LEGAL_HOLD_DOMAIN_INVALID');
        }
        if ($category !== null && !RetentionCategories::isKnown($category)) {
            throw new RuntimeException('LEGAL_HOLD_CATEGORY_INVALID');
        }
        if ($personelId === null && $targetRecordId === null && $category === null) {
            throw new RuntimeException('LEGAL_HOLD_TARGET_REQUIRED');
        }

        if ($personelId !== null) {
            $stmt = $pdo->prepare('SELECT id FROM personeller WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $personelId]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('LEGAL_HOLD_PERSONEL_NOT_FOUND');
            }
        }

        if ($targetRecordId !== null) {
            self::assertTargetRecordExistsAndMatches($pdo, $domain, $category, $targetRecordId, $personelId);
        } elseif ($category !== null && $personelId === null) {
            // Category-wide company hold without personel/record — allowed for GM only (already asserted).
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
        RetentionSchemaGate::assertReady($pdo, RetentionSchemaGate::legalHoldTables());

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
     * @param array<int>|null $allowedSubeIds
     * @return array<int, array<string, mixed>>
     */
    public static function list(PDO $pdo, $activeOnly = true, $allowedSubeIds = null)
    {
        $sql = 'SELECT h.* FROM legal_holdlar h';
        $params = [];
        $where = [];

        if ($activeOnly) {
            $where[] = "h.hold_state = 'ACTIVE'";
        }

        if (count($where) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY h.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return RetentionScopeResolver::filterRowsBySubeScope($pdo, $rows, $allowedSubeIds);
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

    private static function assertTargetRecordExistsAndMatches(
        PDO $pdo,
        $domain,
        $category,
        $recordId,
        $personelId
    ) {
        $domain = strtolower(trim((string) $domain));
        $recordId = (int) $recordId;

        if (in_array($domain, ['personel', 'personeller'], true)) {
            $stmt = $pdo->prepare('SELECT id FROM personeller WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $recordId]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('LEGAL_HOLD_TARGET_NOT_FOUND');
            }
            if ($personelId !== null && $personelId !== $recordId) {
                throw new RuntimeException('LEGAL_HOLD_PERSONEL_MISMATCH');
            }

            return;
        }

        if (in_array($domain, ['surec', 'surecler'], true)) {
            if (!self::tableExists($pdo, 'surecler')) {
                throw new RuntimeException(self::CODE_TARGET_UNSUPPORTED);
            }
            $stmt = $pdo->prepare('SELECT id, personel_id FROM surecler WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('LEGAL_HOLD_TARGET_NOT_FOUND');
            }
            if ($personelId !== null && (int) $row['personel_id'] !== $personelId) {
                throw new RuntimeException('LEGAL_HOLD_PERSONEL_MISMATCH');
            }

            return;
        }

        if (in_array($domain, ['belge', 'belge_kaydi'], true)) {
            // Canonical belge kaydı = surecler BELGE + dosya sürümü.
            if (!self::tableExists($pdo, 'surecler')) {
                throw new RuntimeException(self::CODE_TARGET_UNSUPPORTED);
            }
            $stmt = $pdo->prepare(
                "SELECT id, personel_id, surec_turu FROM surecler WHERE id = :id LIMIT 1"
            );
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('LEGAL_HOLD_TARGET_NOT_FOUND');
            }
            if ($personelId !== null && (int) $row['personel_id'] !== $personelId) {
                throw new RuntimeException('LEGAL_HOLD_PERSONEL_MISMATCH');
            }

            return;
        }

        if ($domain === 'retention') {
            if (!self::tableExists($pdo, 'retention_imha_talepleri')) {
                throw new RuntimeException(self::CODE_TARGET_UNSUPPORTED);
            }
            $stmt = $pdo->prepare('SELECT id, personel_id FROM retention_imha_talepleri WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $recordId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('LEGAL_HOLD_TARGET_NOT_FOUND');
            }
            if ($personelId !== null
                && $row['personel_id'] !== null
                && (int) $row['personel_id'] !== $personelId
            ) {
                throw new RuntimeException('LEGAL_HOLD_PERSONEL_MISMATCH');
            }

            return;
        }

        if (in_array($domain, ['puantaj', 'bordro', 'category'], true)) {
            // Domain known but no record-level resolver for arbitrary IDs.
            throw new RuntimeException(self::CODE_TARGET_UNSUPPORTED);
        }

        throw new RuntimeException(self::CODE_TARGET_UNSUPPORTED);
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
