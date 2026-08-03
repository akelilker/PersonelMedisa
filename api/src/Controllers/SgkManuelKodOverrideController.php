<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Payroll\SgkManuelKodOverrideService;

final class SgkManuelKodOverrideController
{
    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'sgk.manuel_kod_override');

        $payload = $request->getJsonBody();
        if (!is_array($payload)) {
            JsonResponse::badRequest('Gecersiz istek govdesi.');
        }

        $personelId = (int) ($payload['personel_id'] ?? 0);
        if ($personelId < 1) {
            JsonResponse::badRequest('personel_id zorunludur.', 'VALIDATION_ERROR', 'personel_id');
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $stmt = $pdo->prepare('SELECT id, sube_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $personel = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($personel)) {
            JsonResponse::notFound('Personel bulunamadi.');
        }
        SubeScope::assertPersonelAccess($user, $request, (int) $personel['sube_id']);

        $result = SgkManuelKodOverrideService::createOverride($pdo, $user, $payload);
        $status = (int) ($result['http_status'] ?? 500);
        unset($result['http_status']);

        if ($status >= 400) {
            JsonResponse::error(
                $status,
                (string) ($result['error_code'] ?? 'SGK_MANUEL_OVERRIDE_HATA'),
                (string) ($result['message'] ?? 'Manuel override kaydedilemedi.')
            );
        }

        JsonResponse::success($result, [], $status);
    }
}
