<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\MevzuatParametreException;
use Medisa\Api\Services\MevzuatParametreService;

class MevzuatParametreController
{
    public static function list(Request $request)
    {
        [$pdo, $user] = self::context($request, 'mevzuat_parametreleri.view');
        try {
            $items = MevzuatParametreService::listParameters($pdo, $request->getQuery('parametre_kodu'));
            JsonResponse::success(['items' => $items]);
        } catch (MevzuatParametreException $e) {
            self::error($e);
        }
    }

    public static function create(Request $request)
    {
        [$pdo, $user] = self::context($request, 'mevzuat_parametreleri.manage');
        try {
            $row = MevzuatParametreService::createParameter(
                $pdo,
                $request->getJsonBody(),
                $user,
                self::requestHash($request, $user)
            );
            JsonResponse::success($row, [], 201);
        } catch (MevzuatParametreException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Mevzuat parametresi olusturulamadi.');
        }
    }

    public static function update(Request $request, $id)
    {
        [$pdo, $user] = self::context($request, 'mevzuat_parametreleri.manage');
        try {
            $row = MevzuatParametreService::updateFutureParameter(
                $pdo,
                (int) $id,
                $request->getJsonBody(),
                $user,
                self::requestHash($request, $user)
            );
            JsonResponse::success($row);
        } catch (MevzuatParametreException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Mevzuat parametresi guncellenemedi.');
        }
    }

    public static function iptal(Request $request, $id)
    {
        [$pdo, $user] = self::context($request, 'mevzuat_parametreleri.manage');
        try {
            $row = MevzuatParametreService::cancelParameter(
                $pdo,
                (int) $id,
                $user,
                self::requestHash($request, $user)
            );
            JsonResponse::success($row);
        } catch (MevzuatParametreException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Mevzuat parametresi iptal edilemedi.');
        }
    }

    /** @return array{0: \PDO, 1: array<string, mixed>} */
    private static function context(Request $request, $permission)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, $permission);
        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        return [$pdo, $user];
    }

    private static function error(MevzuatParametreException $e)
    {
        JsonResponse::error($e->getHttpStatus(), $e->getCodeString(), $e->getMessage());
    }

    /** @param array<string, mixed> $user */
    private static function requestHash(Request $request, array $user)
    {
        return hash('sha256', json_encode([
            'actor_id' => (int) ($user['id'] ?? 0),
            'method' => $request->getMethod(),
            'path' => $request->getPath(),
            'request_id' => (string) $request->getHeader('x-request-id', ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
