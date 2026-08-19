<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Database\Connection;
use Medisa\Api\Database\UsersSchema;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use PDO;

/**
 * Authenticated password change for credential onboarding (MG-CRED-ONBOARD-001).
 * User must supply current password; clears must_change_password when schema present.
 */
class ChangePasswordController
{
    public static function change(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true, true);
        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        if ($userId <= 0) {
            JsonResponse::unauthorized();
        }

        $body = $request->getJsonBody();
        $current = isset($body['current_password']) ? (string) $body['current_password'] : '';
        $next = isset($body['new_password']) ? (string) $body['new_password'] : '';

        if ($current === '') {
            JsonResponse::badRequest('Mevcut sifre zorunludur.', 'VALIDATION_ERROR', 'current_password');
        }
        if ($next === '') {
            JsonResponse::badRequest('Yeni sifre zorunludur.', 'VALIDATION_ERROR', 'new_password');
        }
        if (strlen($next) < 8) {
            JsonResponse::badRequest('Yeni sifre en az 8 karakter olmalidir.', 'VALIDATION_ERROR', 'new_password');
        }
        if ($current === $next) {
            JsonResponse::badRequest('Yeni sifre mevcut sifreden farkli olmalidir.', 'VALIDATION_ERROR', 'new_password');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $hasMustChange = UsersSchema::hasMustChangePassword($pdo);
        $cols = ['password_hash'];
        if ($hasMustChange) {
            $cols[] = 'must_change_password';
        }
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            JsonResponse::unauthorized();
        }

        if (!PasswordHasher::verify($current, (string) ($row['password_hash'] ?? ''))) {
            JsonResponse::error(401, 'INVALID_CURRENT_PASSWORD', 'Mevcut sifre hatali.', 'current_password');
        }

        if ($hasMustChange) {
            $update = $pdo->prepare(
                'UPDATE users SET password_hash = :password_hash, must_change_password = 0 WHERE id = :id'
            );
        } else {
            $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        }
        $update->execute([
            'id' => $userId,
            'password_hash' => PasswordHasher::hash($next),
        ]);

        AuthMiddleware::markPasswordChanged();

        JsonResponse::success([
            'must_change_password' => false,
        ]);
    }
}
