<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Organizasyon\SubeReadModel;
use Medisa\Api\Services\Qr\QrAttendanceException;
use Medisa\Api\Services\Qr\QrTokenService;
use PDO;

/**
 * Authenticated kiosk QR display token.
 * Permission: qr.kiosk.display (GENEL_YONETICI / SISTEM_YONETICISI / SUBE_YONETICISI).
 * Branch scope remains SubeScope / user_subeler — no cross-branch mint.
 */
class QrKioskController
{
    public static function token(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'qr.kiosk.display');
        OrgScope::assertRequiredAssignment($user);

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

        $allowed = SubeScope::allowedSubeIds($user);
        if (!OrgScope::isUnrestricted($user) && !in_array($subeId, $allowed, true)) {
            JsonResponse::forbidden('Secili sube icin yetkiniz yok.');
        }

        $sube = SubeReadModel::findById($pdo, $subeId);
        if ($sube === null) {
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
                // Global kiosk label — company-qualified when mapped.
                'ad' => (string) $sube['tam_ad'],
            ],
        ]);
    }
}
