<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Services\Payroll\SgkKararPaketiAuthz;
use PDO;
use PDOException;
use RuntimeException;

final class ActorIdentityException extends RuntimeException
{
    /** @var int */
    public $httpStatus;
    /** @var string */
    public $errorCode;
    /** @var string|null */
    public $field;

    public function __construct($httpStatus, $errorCode, $message, $field = null)
    {
        parent::__construct($message);
        $this->httpStatus = (int) $httpStatus;
        $this->errorCode = (string) $errorCode;
        $this->field = $field !== null ? (string) $field : null;
    }
}

/**
 * Canonical owner for formal SGK actor identity create/verify/bind/read.
 *
 * Identity state is owned by actor_identities. User branch scope remains owned
 * by user_subeler and the authenticated SGK authz consumer remains the final
 * fail-closed readiness gate.
 */
final class ActorIdentityService
{
    public const MANAGEMENT_PERMISSION = 'yonetim-paneli.manage';

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, array $admin, $userId)
    {
        self::assertManagementPermission($admin);
        self::assertLifecycleSchema($pdo);

        $userId = self::positiveId($userId, 'user_id');
        $pdo->beginTransaction();
        try {
            $user = self::loadUserForUpdate($pdo, $userId);
            self::assertActiveUser($user);
            self::assertFormalUsername($user);

            $personelId = (int) ($user['personel_id'] ?? 0);
            if ($personelId <= 0) {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_PERSONEL_LINK_REQUIRED',
                    'Formal actor identity icin aktif personel baglantisi zorunludur.',
                    'user_id'
                );
            }

            $personel = self::loadActivePersonel($pdo, $personelId);
            self::assertUserBranchScope($pdo, $userId);

            $linkedIdentityId = self::nullableId($user['actor_identity_id'] ?? null);
            if ($linkedIdentityId !== null) {
                $existing = self::loadIdentity($pdo, $linkedIdentityId);
                self::assertIdentityPersonel($existing, $personelId);
                $pdo->commit();

                return self::readForUser($pdo, $userId);
            }

            $existingStmt = $pdo->prepare(
                'SELECT id FROM actor_identities WHERE personel_id = :personel_id LIMIT 1 FOR UPDATE'
            );
            $existingStmt->execute(['personel_id' => $personelId]);
            $existingId = $existingStmt->fetchColumn();
            if ($existingId !== false && $existingId !== null) {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_IDENTITY_ALREADY_EXISTS',
                    'Bu personel icin actor identity zaten mevcut.',
                    'personel_id'
                );
            }

            $identityCode = self::generatedIdentityCode($personelId);
            $displayName = trim((string) $personel['ad'] . ' ' . (string) $personel['soyad']);
            $normalizedName = self::normalizeName($displayName);
            $insert = $pdo->prepare(
                'INSERT INTO actor_identities
                    (identity_code, display_name, normalized_name, status, verification_source, personel_id)
                 VALUES
                    (:identity_code, :display_name, :normalized_name, :status, :verification_source, :personel_id)'
            );
            $insert->execute([
                'identity_code' => $identityCode,
                'display_name' => $displayName,
                'normalized_name' => $normalizedName,
                'status' => 'PENDING',
                'verification_source' => 'PERSONEL_LINKED',
                'personel_id' => $personelId,
            ]);

            $identityId = (int) $pdo->lastInsertId();
            self::writeAudit(
                $pdo,
                $identityId,
                $userId,
                'CREATE',
                (int) ($admin['id'] ?? 0),
                ['personel_id' => $personelId, 'status' => 'PENDING']
            );
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof ActorIdentityException) {
                throw $e;
            }
            if ($e instanceof PDOException && self::isDuplicate($e)) {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_IDENTITY_ALREADY_EXISTS',
                    'Actor identity zaten mevcut.'
                );
            }
            throw $e;
        }

        return self::readIdentity($pdo, $identityId);
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public static function verify(PDO $pdo, array $admin, $identityId)
    {
        self::assertManagementPermission($admin);
        self::assertLifecycleSchema($pdo);
        $identityId = self::positiveId($identityId, 'id');

        $pdo->beginTransaction();
        try {
            $identity = self::loadIdentityForUpdate($pdo, $identityId);
            $boundUserId = self::boundUserId($pdo, $identityId);
            if ($boundUserId !== null && $boundUserId === (int) ($admin['id'] ?? 0)) {
                throw new ActorIdentityException(
                    403,
                    'ACTOR_IDENTITY_SELF_VERIFY_FORBIDDEN',
                    'Aktor identity sahibi kendisini dogrulayamaz.'
                );
            }

            $status = strtoupper((string) $identity['status']);
            if ($status === 'REVOKED') {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_IDENTITY_REVOKED',
                    'Iptal edilmis actor identity dogrulanamaz.'
                );
            }
            if ($status === 'PENDING') {
                $update = $pdo->prepare(
                    'UPDATE actor_identities
                     SET status = :status, verification_source = :verification_source
                     WHERE id = :id AND status = :expected_status'
                );
                $update->execute([
                    'status' => 'VERIFIED',
                    'verification_source' => 'HUMAN_CONFIRMED',
                    'id' => $identityId,
                    'expected_status' => 'PENDING',
                ]);
                self::writeAudit(
                    $pdo,
                    $identityId,
                    $boundUserId,
                    'VERIFY',
                    (int) ($admin['id'] ?? 0),
                    ['from_status' => 'PENDING', 'to_status' => 'VERIFIED']
                );
            } elseif ($status !== 'VERIFIED') {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_IDENTITY_INVALID_STATE',
                    'Actor identity gecersiz durumdadir.'
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return self::readIdentity($pdo, $identityId);
    }

    /**
     * @param array<string, mixed> $admin
     * @return array<string, mixed>
     */
    public static function bind(PDO $pdo, array $admin, $userId, $identityId)
    {
        self::assertManagementPermission($admin);
        self::assertLifecycleSchema($pdo);
        $userId = self::positiveId($userId, 'user_id');
        $identityId = self::positiveId($identityId, 'actor_identity_id');

        $pdo->beginTransaction();
        try {
            $user = self::loadUserForUpdate($pdo, $userId);
            self::assertActiveUser($user);
            self::assertFormalUsername($user);
            $personelId = (int) ($user['personel_id'] ?? 0);
            if ($personelId <= 0) {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_PERSONEL_LINK_REQUIRED',
                    'Kullanici aktif personel kaydina bagli degil.',
                    'user_id'
                );
            }

            $identity = self::loadIdentityForUpdate($pdo, $identityId);
            self::assertIdentityPersonel($identity, $personelId);
            self::loadActivePersonel($pdo, $personelId);
            self::assertUserBranchScope($pdo, $userId);

            $boundUserId = self::boundUserId($pdo, $identityId);
            $currentIdentityId = self::nullableId($user['actor_identity_id'] ?? null);
            if ($boundUserId !== null && $boundUserId !== $userId) {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_IDENTITY_ALREADY_BOUND',
                    'Actor identity baska bir kullaniciya bagli.'
                );
            }
            if ($currentIdentityId !== null && $currentIdentityId !== $identityId) {
                throw new ActorIdentityException(
                    409,
                    'USER_ACTOR_IDENTITY_CONFLICT',
                    'Kullanici baska bir actor identityye bagli.'
                );
            }

            if ($currentIdentityId === null) {
                $update = $pdo->prepare(
                    'UPDATE users SET actor_identity_id = :actor_identity_id WHERE id = :id'
                );
                $update->execute([
                    'actor_identity_id' => $identityId,
                    'id' => $userId,
                ]);
                self::writeAudit(
                    $pdo,
                    $identityId,
                    $userId,
                    'BIND',
                    (int) ($admin['id'] ?? 0),
                    ['user_id' => $userId]
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && self::isDuplicate($e)) {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_IDENTITY_BIND_CONFLICT',
                    'Actor identity baglama cakismasi olustu.'
                );
            }
            throw $e;
        }

        return self::readForUser($pdo, $userId);
    }

    /** @return array<string, mixed> */
    public static function readForUser(PDO $pdo, $userId)
    {
        $userId = self::positiveId($userId, 'user_id');
        $stmt = $pdo->prepare(
            'SELECT u.id AS user_id, u.username, u.durum, u.personel_id,
                    u.actor_identity_id, ai.status, ai.personel_id AS identity_personel_id
             FROM users u
             LEFT JOIN actor_identities ai ON ai.id = u.actor_identity_id
             WHERE u.id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ActorIdentityException(404, 'USER_NOT_FOUND', 'Kullanici bulunamadi.', 'user_id');
        }

        return self::mapReadRow($pdo, $row);
    }

    /** @return array<string, mixed> */
    public static function readIdentity(PDO $pdo, $identityId)
    {
        $identityId = self::positiveId($identityId, 'id');
        $stmt = $pdo->prepare(
            'SELECT ai.id AS actor_identity_id, ai.status, ai.personel_id,
                    u.id AS user_id, u.username, u.durum
             FROM actor_identities ai
             LEFT JOIN users u ON u.actor_identity_id = ai.id
             WHERE ai.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $identityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ActorIdentityException(404, 'ACTOR_IDENTITY_NOT_FOUND', 'Actor identity bulunamadi.', 'id');
        }

        return self::mapReadRow($pdo, $row);
    }

    /** @param array<string, mixed> $admin */
    private static function assertManagementPermission(array $admin): void
    {
        if (!RolePermissions::has($admin, self::MANAGEMENT_PERMISSION)) {
            throw new ActorIdentityException(403, 'ACTOR_IDENTITY_MANAGEMENT_FORBIDDEN', 'Actor identity yonetimi yetkisi yok.');
        }
    }

    private static function assertLifecycleSchema(PDO $pdo): void
    {
        if (!SgkKararPaketiAuthz::actorIdentitySchemaSupported($pdo)) {
            throw new ActorIdentityException(409, 'ACTOR_IDENTITY_SCHEMA_REQUIRED', 'Actor identity semasi hazir degil.');
        }
        $table = $pdo->query("SHOW TABLES LIKE 'actor_identity_audits'");
        if ($table === false || $table->fetch(PDO::FETCH_NUM) === false) {
            throw new ActorIdentityException(409, 'ACTOR_IDENTITY_AUDIT_SCHEMA_REQUIRED', 'Actor identity audit semasi hazir degil.');
        }
    }

    /** @return array<string, mixed> */
    private static function loadUserForUpdate(PDO $pdo, $userId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, username, ad_soyad, rol, durum, personel_id, actor_identity_id
             FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => (int) $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ActorIdentityException(404, 'USER_NOT_FOUND', 'Kullanici bulunamadi.', 'user_id');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function loadIdentity(PDO $pdo, $identityId)
    {
        $stmt = $pdo->prepare('SELECT * FROM actor_identities WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $identityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ActorIdentityException(404, 'ACTOR_IDENTITY_NOT_FOUND', 'Actor identity bulunamadi.', 'id');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function loadIdentityForUpdate(PDO $pdo, $identityId)
    {
        $stmt = $pdo->prepare('SELECT * FROM actor_identities WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => (int) $identityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ActorIdentityException(404, 'ACTOR_IDENTITY_NOT_FOUND', 'Actor identity bulunamadi.', 'id');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private static function loadActivePersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, ad, soyad, aktif_durum FROM personeller WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ActorIdentityException(404, 'PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 'personel_id');
        }
        if (strtoupper(trim((string) $row['aktif_durum'])) !== 'AKTIF') {
            throw new ActorIdentityException(409, 'PERSONEL_INACTIVE', 'Pasif personel formal actor olamaz.', 'personel_id');
        }

        return $row;
    }

    private static function assertActiveUser(array $user): void
    {
        if (strtoupper(trim((string) ($user['durum'] ?? ''))) !== 'AKTIF') {
            throw new ActorIdentityException(409, 'USER_INACTIVE', 'Pasif kullanici formal actor olamaz.', 'user_id');
        }
    }

    private static function assertFormalUsername(array $user): void
    {
        if (!SgkKararPaketiAuthz::isFormalUsername((string) ($user['username'] ?? ''))) {
            throw new ActorIdentityException(409, 'ACTOR_GENERIC_USER_FORBIDDEN', 'Generic veya teknik kullanici formal actor olamaz.', 'user_id');
        }
    }

    private static function assertIdentityPersonel(array $identity, $personelId): void
    {
        if ((int) ($identity['personel_id'] ?? 0) !== (int) $personelId) {
            throw new ActorIdentityException(409, 'ACTOR_PERSONEL_MISMATCH', 'Actor identity personel ile eslesmiyor.');
        }
    }

    private static function assertUserBranchScope(PDO $pdo, $userId): void
    {
        $stmt = $pdo->prepare(
            'SELECT us.sube_id, s.durum
             FROM user_subeler us
             LEFT JOIN subeler s ON s.id = us.sube_id
             WHERE us.user_id = :user_id
             ORDER BY us.sube_id ASC'
        );
        $stmt->execute(['user_id' => (int) $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $scope = [];
        foreach ($rows as $row) {
            if (strtoupper(trim((string) ($row['durum'] ?? ''))) !== 'AKTIF') {
                throw new ActorIdentityException(
                    409,
                    'ACTOR_SCOPE_INVALID',
                    'Actor scope gecersiz veya pasif sube iceriyor.',
                    'user_id'
                );
            }
            $scope[] = (int) $row['sube_id'];
        }
        if (count($scope) === 0) {
            throw new ActorIdentityException(409, 'ACTOR_SCOPE_REQUIRED', 'Formal actor icin aktif sube kapsami zorunludur.', 'user_id');
        }
    }

    private static function boundUserId(PDO $pdo, $identityId)
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM users WHERE actor_identity_id = :actor_identity_id LIMIT 1'
        );
        $stmt->execute(['actor_identity_id' => (int) $identityId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function mapReadRow(PDO $pdo, array $row)
    {
        $userId = self::nullableId($row['user_id'] ?? null);
        $identityId = self::nullableId($row['actor_identity_id'] ?? null);
        $scope = $userId !== null ? self::loadUserScope($pdo, $userId) : [];
        $ready = false;
        $readinessCode = null;
        if ($userId !== null) {
            $actor = [
                'id' => $userId,
                'username' => (string) ($row['username'] ?? ''),
                'durum' => (string) ($row['durum'] ?? ''),
                'sube_ids' => $scope,
                'actor_identity_id' => $identityId,
                'actor_identity_status' => $row['status'] ?? null,
            ];
            $readiness = SgkKararPaketiAuthz::formalActorReadiness($pdo, $actor);
            $ready = (bool) ($readiness['ready'] ?? false);
            $readinessCode = $readiness['code'] ?? null;
        }

        return [
            'user_id' => $userId,
            'actor_identity_id' => $identityId,
            'actor_status' => $row['status'] !== null ? (string) $row['status'] : null,
            'personel_id' => self::nullableId($row['personel_id'] ?? ($row['identity_personel_id'] ?? null)),
            'branch_scope' => $scope,
            'ready' => $ready,
            'readiness_code' => $readinessCode,
        ];
    }

    /** @return list<int> */
    private static function loadUserScope(PDO $pdo, $userId)
    {
        $stmt = $pdo->prepare('SELECT sube_id FROM user_subeler WHERE user_id = :user_id ORDER BY sube_id ASC');
        $stmt->execute(['user_id' => (int) $userId]);
        $scope = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $subeId) {
            $scope[] = (int) $subeId;
        }

        return $scope;
    }

    private static function writeAudit(PDO $pdo, $identityId, $targetUserId, $action, $changedBy, array $details): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO actor_identity_audits
                (actor_identity_id, target_user_id, action, changed_by_user_id, details_json)
             VALUES
                (:actor_identity_id, :target_user_id, :action, :changed_by_user_id, :details_json)'
        );
        $stmt->execute([
            'actor_identity_id' => (int) $identityId,
            'target_user_id' => $targetUserId !== null ? (int) $targetUserId : null,
            'action' => (string) $action,
            'changed_by_user_id' => (int) $changedBy,
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function generatedIdentityCode($personelId): string
    {
        return 'PERSONEL-' . (int) $personelId;
    }

    private static function normalizeName($value): string
    {
        $value = trim((string) $value);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private static function positiveId($value, $field): int
    {
        $id = (int) $value;
        if ($id <= 0) {
            throw new ActorIdentityException(400, 'VALIDATION_ERROR', 'Gecersiz kimlik.', $field);
        }

        return $id;
    }

    private static function nullableId($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private static function isDuplicate(PDOException $e): bool
    {
        $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        return $driverCode === 1062 || stripos($e->getMessage(), 'duplicate') !== false;
    }
}
