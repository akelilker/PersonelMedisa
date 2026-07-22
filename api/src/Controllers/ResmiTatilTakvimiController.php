<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\ResmiTatilTakvimiException;
use Medisa\Api\Services\ResmiTatilTakvimiService;

class ResmiTatilTakvimiController
{
    /** @var string[] */
    private static $viewPermissions = [
        'mevzuat_parametreleri.view',
        'sirket_parametreleri.view',
        'resmi_tatil_takvimi.view',
    ];

    /** @var string[] */
    private static $managePermissions = [
        'sirket_parametreleri.manage',
        'resmi_tatil_takvimi.manage',
    ];

    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request);
        self::assertView($user);
        $pdo = Connection::get();
        JsonResponse::success([
            'items' => ResmiTatilTakvimiService::list(
                $pdo,
                $request->getQuery('durum'),
                $request->getQuery('tatil_turu'),
                $request->getQuery('tarih_bas'),
                $request->getQuery('tarih_bit')
            ),
        ]);
    }

    public static function detail(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        self::assertView($user);
        $pdo = Connection::get();
        $detail = ResmiTatilTakvimiService::get($pdo, (int) $id);
        if (!$detail) {
            JsonResponse::error(404, 'TATIL_TAKVIM_NOT_FOUND', 'Kayit bulunamadi.');
        }
        JsonResponse::success($detail);
    }

    public static function envanterOzet(Request $request)
    {
        $user = AuthMiddleware::authenticate($request);
        self::assertView($user);
        $yil = (int) $request->getQuery('yil', '0');
        $ay = (int) $request->getQuery('ay', '0');
        if ($yil < 2000 || $yil > 2100 || $ay < 1 || $ay > 12) {
            JsonResponse::badRequest('Gecersiz donem.', 'VALIDATION_ERROR', 'yil/ay');
        }
        $pdo = Connection::get();
        try {
            JsonResponse::success(ResmiTatilTakvimiService::envanterOzet($pdo, $yil, $ay));
        } catch (ResmiTatilTakvimiException $e) {
            self::error($e);
        }
    }

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request);
        self::assertManage($user);
        $pdo = Connection::get();
        try {
            JsonResponse::success(
                ResmiTatilTakvimiService::create($pdo, $request->getJsonBody(), $user, self::requestHash($request, $user)),
                [],
                201
            );
        } catch (ResmiTatilTakvimiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Resmi tatil kaydi olusturulamadi.');
        }
    }

    public static function update(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        self::assertManage($user);
        $pdo = Connection::get();
        try {
            JsonResponse::success(
                ResmiTatilTakvimiService::update(
                    $pdo,
                    (int) $id,
                    $request->getJsonBody(),
                    $user,
                    self::requestHash($request, $user)
                )
            );
        } catch (ResmiTatilTakvimiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Resmi tatil kaydi guncellenemedi.');
        }
    }

    public static function activate(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        self::assertManage($user);
        $pdo = Connection::get();
        try {
            JsonResponse::success(
                ResmiTatilTakvimiService::activate($pdo, (int) $id, $user, self::requestHash($request, $user))
            );
        } catch (ResmiTatilTakvimiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Resmi tatil kaydi aktiflestirilemedi.');
        }
    }

    public static function revise(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        self::assertManage($user);
        $pdo = Connection::get();
        try {
            JsonResponse::success(
                ResmiTatilTakvimiService::revise(
                    $pdo,
                    (int) $id,
                    $request->getJsonBody(),
                    $user,
                    self::requestHash($request, $user)
                )
            );
        } catch (ResmiTatilTakvimiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Resmi tatil kaydi revize edilemedi.');
        }
    }

    public static function cancel(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request);
        self::assertManage($user);
        $pdo = Connection::get();
        $body = $request->getJsonBody();
        $gerekce = trim((string) ($body['gerekce'] ?? $body['neden'] ?? ''));
        try {
            JsonResponse::success(
                ResmiTatilTakvimiService::cancel($pdo, (int) $id, $gerekce, $user, self::requestHash($request, $user))
            );
        } catch (ResmiTatilTakvimiException $e) {
            self::error($e);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Resmi tatil kaydi iptal edilemedi.');
        }
    }

    /** @param array<string, mixed> $user */
    private static function assertView(array $user)
    {
        RolePermissions::assertAny($user, self::$viewPermissions);
    }

    /** @param array<string, mixed> $user */
    private static function assertManage(array $user)
    {
        RolePermissions::assertAny($user, self::$managePermissions);
    }

    /** @param array<string, mixed> $user */
    private static function requestHash(Request $request, array $user)
    {
        return hash('sha256', $request->getMethod() . '|' . $request->getPath() . '|' . (string) ($user['id'] ?? 0) . '|' . (string) $request->getRawBody());
    }

    private static function error(ResmiTatilTakvimiException $e)
    {
        JsonResponse::error($e->getHttpStatus(), $e->getErrorCode(), $e->getMessage(), null, $e->getContext());
    }
}
