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

        $stmt = $pdo->prepare(
            'SELECT id, username, ad_soyad, rol, durum FROM users WHERE id = :id LIMIT 1'
        );
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
            'sube_ids' => $subeIds,
        ];

        return self::$user;
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
