<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;

/**
 * S103: PII-free authenticated smoke read surface.
 * Domain tablosu okumaz / yazmaz. Token/username/sube adi dondurmez.
 */
class AuthSmokeController
{
    public static function smokeRead(Request $request)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'ops.auth_smoke.read');

        $rol = strtoupper(trim((string) ($user['rol'] ?? '')));
        if ($rol !== 'AUTH_SMOKE_READONLY') {
            JsonResponse::error(403, 'FORBIDDEN', 'Bu endpoint yalniz AUTH_SMOKE_READONLY hesabi icindir.');
        }

        $subeIds = SubeScope::allowedSubeIds($user);
        if (count($subeIds) !== 1) {
            JsonResponse::error(
                403,
                'AUTH_SMOKE_SCOPE_INVALID',
                'AUTH_SMOKE_READONLY hesabi exact bir sube scope gerektirir.'
            );
        }

        JsonResponse::success([
            'authenticated' => true,
            'read_only' => true,
            'role' => 'AUTH_SMOKE_READONLY',
            'scope_type' => 'SINGLE_BRANCH',
            'scope_count' => 1,
        ]);
    }
}
