<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use PDO;

class AuthMiddleware
{
    /** @var array<string, mixed>|null */
    private static $user = null;

    /** @return array<string, mixed>|null */
    public static function authenticate(Request $request, $required = true)
    {
        if (self::$user !== null) {
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

        $subeIds = self::loadUserSubeIds($pdo, $userId);
        self::$user = [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'ad_soyad' => (string) $row['ad_soyad'],
            'rol' => (string) $row['rol'],
            'durum' => (string) ($row['durum'] ?? ''),
            'sube_ids' => $subeIds,
        ];
        if (array_key_exists('actor_identity_id', $row) && $row['actor_identity_id'] !== null && $row['actor_identity_id'] !== '') {
            $aid = (int) $row['actor_identity_id'];
            self::$user['actor_identity_id'] = $aid > 0 ? $aid : null;
        } else {
            self::$user['actor_identity_id'] = null;
        }

        if (!empty(self::$user['actor_identity_id'])) {
            self::$user['actor_identity_status'] = self::loadActorIdentityStatus($pdo, (int) self::$user['actor_identity_id']);
        } else {
            self::$user['actor_identity_status'] = null;
        }

        return self::$user;
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

        return $hasActorIdentity
            ? 'SELECT id, username, ad_soyad, rol, durum, actor_identity_id FROM users WHERE id = :id LIMIT 1'
            : 'SELECT id, username, ad_soyad, rol, durum FROM users WHERE id = :id LIMIT 1';
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
