<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Http\JsonResponse;
use PDO;
use PDOException;

/**
 * users.personel_id binding write + append-only audit (S3B).
 * Binding is identity, not authorization; UNIQUE(personel_id) enforces one active bind.
 */
class UserPersonelBindingService
{
    /**
     * @param mixed $oldId
     * @param mixed $newId
     * @return string|null SET|CLEAR|REPLACE|null (null = no-op)
     */
    public static function resolveAction($oldId, $newId)
    {
        $old = self::normalizePersonelId($oldId);
        $new = self::normalizePersonelId($newId);
        if ($old === $new) {
            return null;
        }
        if ($old === null && $new !== null) {
            return 'SET';
        }
        if ($old !== null && $new === null) {
            return 'CLEAR';
        }

        return 'REPLACE';
    }

    /**
     * Validate personel exists and is AKTIF for NEW binds (SET/REPLACE target).
     *
     * @return array<string, mixed>
     */
    public static function assertBindablePersonel(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            JsonResponse::error(404, 'PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 'personel_id');
        }

        $stmt = $pdo->prepare(
            'SELECT id, aktif_durum FROM personeller WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::error(404, 'PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 'personel_id');
        }

        $aktif = strtoupper(trim((string) ($row['aktif_durum'] ?? '')));
        if ($aktif !== 'AKTIF') {
            JsonResponse::badRequest(
                'Pasif personel kaydina baglama yapilamaz.',
                'PERSONEL_INACTIVE',
                'personel_id'
            );
        }

        return $row;
    }

    /**
     * Fail if another user already holds this personel_id. Do not leak which user.
     *
     * @param int|null $excludeUserId
     */
    public static function assertNotAlreadyBound(PDO $pdo, $personelId, $excludeUserId = null)
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            return;
        }

        $sql = 'SELECT id FROM users WHERE personel_id = :personel_id';
        $params = ['personel_id' => $personelId];
        if ($excludeUserId !== null && (int) $excludeUserId > 0) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = (int) $excludeUserId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            JsonResponse::error(
                409,
                'PERSONEL_ALREADY_BOUND',
                'Bu personel kaydi baska bir kullaniciya bagli.',
                'personel_id'
            );
        }
    }

    /**
     * @param int|null $oldPersonelId
     * @param int|null $newPersonelId
     * @param string $action SET|CLEAR|REPLACE
     */
    public static function writeAudit(PDO $pdo, $userId, $oldPersonelId, $newPersonelId, $action, $changedBy)
    {
        $stmt = $pdo->prepare(
            'INSERT INTO user_personel_binding_audit
                (user_id, old_personel_id, new_personel_id, action, changed_by)
             VALUES
                (:user_id, :old_personel_id, :new_personel_id, :action, :changed_by)'
        );
        $stmt->execute([
            'user_id' => (int) $userId,
            'old_personel_id' => $oldPersonelId,
            'new_personel_id' => $newPersonelId,
            'action' => (string) $action,
            'changed_by' => (int) $changedBy,
        ]);
    }

    /**
     * Apply binding change for a user. No-op when old === new.
     *
     * @param int|null $newPersonelId
     * @return string|null action applied, or null if no-op
     */
    public static function applyBinding(PDO $pdo, $userId, $newPersonelId, $changedByUserId)
    {
        $userId = (int) $userId;
        $changedByUserId = (int) $changedByUserId;
        $newId = self::normalizePersonelId($newPersonelId);

        $stmt = $pdo->prepare('SELECT personel_id FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::error(404, 'USER_NOT_FOUND', 'Kullanici bulunamadi.', 'id');
        }

        $oldId = self::normalizePersonelId($row['personel_id'] ?? null);
        $action = self::resolveAction($oldId, $newId);
        if ($action === null) {
            return null;
        }

        if ($newId !== null) {
            self::assertBindablePersonel($pdo, $newId);
            self::assertNotAlreadyBound($pdo, $newId, $userId);
        }

        try {
            $upd = $pdo->prepare(
                'UPDATE users SET personel_id = :personel_id WHERE id = :id'
            );
            $upd->execute([
                'personel_id' => $newId,
                'id' => $userId,
            ]);
            self::writeAudit($pdo, $userId, $oldId, $newId, $action, $changedByUserId);
        } catch (PDOException $e) {
            if (self::isUniqueViolation($e)) {
                JsonResponse::error(
                    409,
                    'PERSONEL_ALREADY_BOUND',
                    'Bu personel kaydi baska bir kullaniciya bagli.',
                    'personel_id'
                );
            }
            throw $e;
        }

        return $action;
    }

    /** @param mixed $value @return int|null */
    private static function normalizePersonelId($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private static function isUniqueViolation(PDOException $e)
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($driverCode === 1062) {
            return true;
        }
        $message = strtolower($e->getMessage());

        return strpos($message, '1062') !== false
            || strpos($message, 'uq_users_personel_id') !== false
            || strpos($message, 'duplicate') !== false;
    }
}
