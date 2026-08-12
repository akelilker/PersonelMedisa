<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Qr\QrAttendanceException;
use Medisa\Api\Services\Qr\QrTokenService;
use PDO;

/**
 * Authenticated kiosk QR display token (S3C).
 * Permission: yonetim-paneli.manage (GENEL_YONETICI / SISTEM_YONETICISI).
 */
class QrKioskController
{
    public static function token(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'yonetim-paneli.manage');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $scope = SubeScope::resolveScope($user, $request);
        if ($scope === null || (int) $scope <= 0) {
            JsonResponse::badRequest('Aktif sube secilmelidir.', 'VALIDATION_ERROR', 'sube_id');
        }
        $subeId = (int) $scope;

        $stmt = $pdo->prepare('SELECT id, ad FROM subeler WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $subeId]);
        $sube = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($sube)) {
            JsonResponse::notFound('Sube bulunamadi.');
        }

        try {
            $minted = QrTokenService::mint($subeId);
        } catch (QrAttendanceException $e) {
            JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), $e->getField());
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
        }

        JsonResponse::success([
            'token' => $minted['token'],
            'issued_at' => $minted['issued_at'],
            'expires_at' => $minted['expires_at'],
            'ttl_seconds' => $minted['ttl_seconds'],
            'sube' => [
                'id' => (int) $sube['id'],
                'ad' => (string) ($sube['ad'] ?? ''),
            ],
        ]);
    }
}
