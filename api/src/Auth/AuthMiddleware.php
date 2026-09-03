<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Database\Connection;
use Medisa\Api\Database\UserOrgAssignmentSchema;
use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;
use PDO;

class AuthMiddleware
{
    /** @var array<string, mixed>|null */
    private static $user = null;

    /** Clears request-scoped cached user (e.g. after password change). */
    public static function markPasswordChanged()
    {
        if (self::$user !== null) {
            self::$user['must_change_password'] = false;
        }
    }

    /** @return array<string, mixed>|null */
    public static function authenticate(Request $request, $required = true, $allowPasswordChangeRequired = false)
    {
        if (self::$user !== null) {
            self::enforceMustChangePasswordIfRequired($required, $allowPasswordChangeRequired);
            return self::$user;
        }

        $authHeader = $request->getHeader('authorization', '');
        if (!is_string($authHeader) || stripos($authHeader, 'Bearer ') !== 0) {
            if ($required) {
                JsonResponse::unauthorized();
            }
            return null;
        }

        $token = trim(substr($authHeader, 7));
        $payload = Jwt::decode($token);
        if ($payload === null || !isset($payload['sub'])) {
            if ($required) {
                JsonResponse::unauthorized('Gecersiz veya suresi dolmus oturum.');
            }
            return null;
        }

        $userId = (int) $payload['sub'];
        if ($userId <= 0) {
            if ($required) {
                JsonResponse::unauthorized();
            }
            return null;
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $selectSql = self::usersSelectSql($pdo);
        $stmt = $pdo->prepare($selectSql);
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || ($row['durum'] ?? '') !== 'AKTIF') {
            if ($required) {
                JsonResponse::unauthorized();
            }
            return null;
        }

        $explicitSubeIds = self::loadUserSubeIds($pdo, $userId);
        $bolumIds = UserOrgAssignmentSchema::loadUserBolumIds($pdo, $userId);
        $birimIds = UserOrgAssignmentSchema::loadUserBirimIds($pdo, $userId);
        $sirketIds = UserOrgAssignmentSchema::loadUserSirketIds($pdo, $userId);
        $sgkIsverenIds = UserOrgAssignmentSchema::loadUserSgkIsverenIds($pdo, $userId);
        $rolCanonical = RolePermissions::normalizeRole((string) $row['rol']);
        $rol = $rolCanonical !== '' ? $rolCanonical : (string) $row['rol'];
        // Organisation-wide İK read is role-derived. Company grants feed write_sube_ids
        // only (HrWriteScope); materialising them into sube_ids would poison legacy
        // SubeScope::appendSubeFilter callers into a false company-narrowed read.
        $visibilitySubeIds = OrgScope::isOrganizationGlobalRead(['rol' => $rol])
            ? []
            : self::resolveEffectiveSubeIds($pdo, $explicitSubeIds, $sirketIds);
        self::$user = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'ad_soyad' => (string) $row['ad_soyad'],
            'rol' => $rol,
            'durum' => (string) ($row['durum'] ?? ''),
            'sube_ids' => $visibilitySubeIds,
            'explicit_sube_ids' => $explicitSubeIds,
            // Write reach of a company-write-scoped role: strictly the branches of
            // the granted companies, never the explicit branch grants, and
            // recomputed per request so a newly created branch of a granted
            // company is writable without touching any grant row.
            'write_sube_ids' => UserOrgAssignmentSchema::resolveSubeIdsForSirketIds($pdo, $sirketIds),
            'sirket_ids' => $sirketIds,
            'sgk_isveren_ids' => $sgkIsverenIds,
            'bolum_ids' => $bolumIds,
            'birim_ids' => $birimIds,
        ];
        if (array_key_exists('actor_identity_id', $row) && $row['actor_identity_id'] !== null && $row['actor_identity_id'] !== '') {
            $aid = (int) $row['actor_identity_id'];
            self::$user['actor_identity_id'] = $aid > 0 ? $aid : null;
        } else {
            self::$user['actor_identity_id'] = null;
        }

        if (array_key_exists('personel_id', $row) && $row['personel_id'] !== null && $row['personel_id'] !== '') {
            $pid = (int) $row['personel_id'];
            self::$user['personel_id'] = $pid > 0 ? $pid : null;
        } else {
            self::$user['personel_id'] = null;
        }

        if (!empty(self::$user['actor_identity_id'])) {
            self::$user['actor_identity_status'] = self::loadActorIdentityStatus($pdo, (int) self::$user['actor_identity_id']);
        } else {
            self::$user['actor_identity_status'] = null;
        }

        if (array_key_exists('must_change_password', $row)) {
            self::$user['must_change_password'] = ((int) ($row['must_change_password'] ?? 0)) === 1;
        }

        self::enforceMustChangePasswordIfRequired($required, $allowPasswordChangeRequired);

        return self::$user;
    }

    private static function enforceMustChangePasswordIfRequired($required, $allowPasswordChangeRequired)
    {
        if (!$required || $allowPasswordChangeRequired || self::$user === null) {
            return;
        }
        if (!empty(self::$user['must_change_password'])) {
            JsonResponse::error(403, 'PASSWORD_CHANGE_REQUIRED', 'Sifre degistirme zorunludur.');
        }
    }

    private static function usersSelectSql(PDO $pdo)
    {
        // No process-level schema cache — same risk as SgkKararPaketiAuthz::actorIdentitySchemaSupported.
        $hasActorIdentity = false;
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'actor_identity_id'");
            $hasActorIdentity = $col !== false && $col->fetch(PDO::FETCH_ASSOC) !== false;
            if ($col !== false) {
                $col->closeCursor();
            }
        } catch (\Throwable $e) {
            $hasActorIdentity = false;
        }

        $hasPersonelId = UsersSchema::hasPersonelId($pdo);
        $hasMustChangePassword = UsersSchema::hasMustChangePassword($pdo);

        $cols = ['id', 'username', 'ad_soyad', 'rol', 'durum'];
        if ($hasActorIdentity) {
            $cols[] = 'actor_identity_id';
        }
        if ($hasPersonelId) {
            $cols[] = 'personel_id';
        }
        if ($hasMustChangePassword) {
            $cols[] = 'must_change_password';
        }

        return 'SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = :id LIMIT 1';
    }

    /** @return string|null */
    private static function loadActorIdentityStatus(PDO $pdo, $actorIdentityId)
    {
        $actorIdentityId = (int) $actorIdentityId;
        if ($actorIdentityId <= 0) {
            return null;
        }
        try {
            $table = $pdo->query("SHOW TABLES LIKE 'actor_identities'");
            if ($table === false || $table->fetch(PDO::FETCH_NUM) === false) {
                if ($table !== false) {
                    $table->closeCursor();
                }

                return null;
            }
            $table->closeCursor();
            $stmt = $pdo->prepare('SELECT status FROM actor_identities WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $actorIdentityId]);
            $status = $stmt->fetchColumn();
            if ($status === false || $status === null || $status === '') {
                return null;
            }

            return strtoupper(trim((string) $status));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Effective branch visibility = explicit user_subeler grants ∪ the branches
     * that currently belong to the granted companies.
     *
     * Recomputed per request rather than copied into user_subeler, so a branch
     * added to a company after the grant becomes visible immediately and no
     * assignment row ever has to be rewritten. The union is a widening only:
     * every explicit grant survives.
     *
     * @param array<int, int> $explicitSubeIds
     * @param array<int, int> $sirketIds
     * @return array<int, int>
     */
    private static function resolveEffectiveSubeIds(PDO $pdo, array $explicitSubeIds, array $sirketIds)
    {
        if (count($sirketIds) === 0) {
            return $explicitSubeIds;
        }

        $merged = array_merge($explicitSubeIds, UserOrgAssignmentSchema::resolveSubeIdsForSirketIds($pdo, $sirketIds));
        $unique = [];
        foreach ($merged as $id) {
            $value = (int) $id;
            if ($value > 0 && !in_array($value, $unique, true)) {
                $unique[] = $value;
            }
        }
        sort($unique);

        return $unique;
    }

    /** @return array<int, int> */
    private static function loadUserSubeIds(PDO $pdo, $userId)
    {
        $stmt = $pdo->prepare('SELECT sube_id FROM user_subeler WHERE user_id = :user_id ORDER BY sube_id ASC');
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['sube_id'];
        }

        return $ids;
    }
}
