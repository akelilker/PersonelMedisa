<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use PDO;

class LoginController
{
    public static function login(Request $request)
    {
        if (!medisa_config_ready()) {
            JsonResponse::serverError('API yapilandirmasi tamamlanmamis.');
        }

        $body = $request->getJsonBody();
        $username = isset($body['username']) ? trim((string) $body['username']) : '';
        $password = isset($body['password']) ? (string) $body['password'] : '';

        if ($username === '' || $password === '') {
            JsonResponse::badRequest('Kullanici adi ve sifre zorunludur.', 'VALIDATION_ERROR', 'username');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash, ad_soyad, rol, durum FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || ($user['durum'] ?? '') !== 'AKTIF') {
            JsonResponse::error(401, 'INVALID_CREDENTIALS', 'Kullanici adi veya sifre hatali.');
        }

        if (!PasswordHasher::verify($password, (string) ($user['password_hash'] ?? ''))) {
            JsonResponse::error(401, 'INVALID_CREDENTIALS', 'Kullanici adi veya sifre hatali.');
        }

        $subeIds = self::loadUserSubeIds($pdo, (int) $user['id']);
        $subeList = self::loadSubeList($pdo, $subeIds);
        $rol = (string) $user['rol'];
        $activeSubeId = SubeScope::resolveInitialActiveSubeId($subeIds);

        $ttl = (int) medisa_config('jwt_ttl_seconds', 86400);
        $token = Jwt::encode([
            'sub' => (int) $user['id'],
            'rol' => $rol,
            'iat' => time(),
            'exp' => time() + $ttl,
        ]);

        JsonResponse::success([
            'token' => $token,
            'user' => [
                'id' => (int) $user['id'],
                'ad_soyad' => (string) $user['ad_soyad'],
                'rol' => $rol,
                'sube_ids' => $subeIds,
            ],
            'ui_profile' => $rol === 'BIRIM_AMIRI' ? 'birim_amiri' : 'yonetim',
            'sube_list' => $subeList,
            'active_sube_id' => $activeSubeId,
        ]);
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

    /** @param array<int, int> $subeIds @return array<int, array<string, mixed>> */
    private static function loadSubeList(PDO $pdo, array $subeIds)
    {
        if (count($subeIds) === 0) {
            $stmt = $pdo->query('SELECT id, ad FROM subeler WHERE durum = "AKTIF" ORDER BY id ASC');
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $placeholders = implode(',', array_fill(0, count($subeIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, ad FROM subeler WHERE id IN ($placeholders) AND durum = 'AKTIF' ORDER BY id ASC"
            );
            $stmt->execute($subeIds);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => (int) $row['id'],
                'ad' => (string) $row['ad'],
            ];
        }

        return $list;
    }
}
