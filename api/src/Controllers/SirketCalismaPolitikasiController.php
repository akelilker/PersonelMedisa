<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\SirketCalismaPolitikasiException;
use Medisa\Api\Services\SirketCalismaPolitikasiService;

class SirketCalismaPolitikasiController
{
    public static function katalog(Request $request)
    {
        $user = AuthMiddleware::authenticate($request);
        RolePermissions::assert($user, 'sirket_parametreleri.view');
        JsonResponse::success(SirketCalismaPolitikasiService::getKatalog());
    }

    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request);
        RolePermissions::assert($user, 'sirket_parametreleri.view');
        $pdo = Connection::get();
        JsonResponse::success([
            'items' => SirketCalismaPolitikasiService::listPolitikalar($pdo, $request->getQuery('state')),
        ]);
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        RolePermissions::assert($user, 'sirket_parametreleri.view');
        $pdo = Connection::get();
        $detail = SirketCalismaPolitikasiService::getPolitikaDetail($pdo, (int) $id);
        if (!$detail) {
            JsonResponse::error(404, 'POLICY_NOT_FOUND', 'Politika bulunamadi.');
        }
        JsonResponse::success($detail);
    }

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request);
        RolePermissions::assert($user, 'sirket_parametreleri.manage');
        $pdo = Connection::get();
        try {
            JsonResponse::success(
                SirketCalismaPolitikasiService::createDraft($pdo, $request->getJsonBody(), $user, self::requestHash($request, $user)),
                [],
                201
            );
        } catch (SirketCalismaPolitikasiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Politika taslagi olusturulamadi.');
        }
    }

    public static function update(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        RolePermissions::assert($user, 'sirket_parametreleri.manage');
        $pdo = Connection::get();
        try {
            JsonResponse::success(
                SirketCalismaPolitikasiService::updateDraft($pdo, (int) $id, $request->getJsonBody(), $user, self::requestHash($request, $user))
            );
        } catch (SirketCalismaPolitikasiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Politika guncellenemedi.');
        }
    }

    public static function submit(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        RolePermissions::assert($user, 'sirket_parametreleri.manage');
        $pdo = Connection::get();
        try {
            JsonResponse::success(
                SirketCalismaPolitikasiService::submitForApproval($pdo, (int) $id, $user, self::requestHash($request, $user))
            );
        } catch (SirketCalismaPolitikasiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Politika onaya gonderilemedi.');
        }
    }

    public static function approve(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        RolePermissions::assert($user, 'bordro_kesinlestirme.approve');
        $pdo = Connection::get();
        try {
            JsonResponse::success(
                SirketCalismaPolitikasiService::approve($pdo, (int) $id, $user, self::requestHash($request, $user))
            );
        } catch (SirketCalismaPolitikasiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Politika onaylanamadi.');
        }
    }

    public static function cancel(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        RolePermissions::assert($user, 'sirket_parametreleri.manage');
        $pdo = Connection::get();
        $body = $request->getJsonBody();
        $neden = trim((string) ($body['neden'] ?? ''));
        try {
            JsonResponse::success(
                SirketCalismaPolitikasiService::cancel($pdo, (int) $id, $neden, $user, self::requestHash($request, $user))
            );
        } catch (SirketCalismaPolitikasiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Politika iptal edilemedi.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function requestHash(Request $request, array $user)
    {
        return hash('sha256', $request->getMethod() . '|' . $request->getPath() . '|' . (string) ($user['id'] ?? 0) . '|' . (string) $request->getRawBody());
    }

    private static function error(SirketCalismaPolitikasiException $e)
    {
        JsonResponse::error($e->getCode() > 0 ? (int) $e->getCode() : 400, $e->getErrorCode(), $e->getMessage(), null, $e->getContext());
    }
}
