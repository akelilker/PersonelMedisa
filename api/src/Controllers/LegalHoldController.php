<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Retention\LegalHoldService;
use RuntimeException;
use Throwable;

class LegalHoldController
{
    public static function list(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assertAny($user, ['legal_hold.manage', 'retention.view']);

        try {
            $pdo = Connection::get();
        } catch (Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $activeOnly = strtolower((string) $request->getQuery('active_only', '1')) !== '0';
        $allowed = SubeScope::allowedSubeIds($user);
        JsonResponse::success([
            'items' => LegalHoldService::list(
                $pdo,
                $activeOnly,
                count($allowed) > 0 ? $allowed : null
            ),
        ]);
    }

    public static function create(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $pdo = Connection::get();
            $item = LegalHoldService::create($pdo, $user, $body);
        } catch (RuntimeException $e) {
            JsonResponse::badRequest($e->getMessage(), $e->getMessage());
        } catch (Throwable $e) {
            JsonResponse::serverError('Legal hold olusturulamadi.');
        }

        JsonResponse::success(['item' => $item]);
    }

    public static function release(Request $request, $id)
    {
        $user = AuthMiddleware::authenticate($request, true);

        $body = $request->getJsonBody();
        if (!is_array($body)) {
            $body = [];
        }
        $reason = trim((string) ($body['release_reason'] ?? $body['reason'] ?? ''));

        try {
            $pdo = Connection::get();
            $item = LegalHoldService::release($pdo, $user, $id, $reason);
        } catch (RuntimeException $e) {
            $code = $e->getMessage();
            if ($code === 'LEGAL_HOLD_NOT_FOUND') {
                JsonResponse::notFound('Legal hold bulunamadi.');
            }
            JsonResponse::badRequest($code, $code);
        } catch (Throwable $e) {
            JsonResponse::serverError('Legal hold serbest birakilamadi.');
        }

        JsonResponse::success(['item' => $item]);
    }
}
