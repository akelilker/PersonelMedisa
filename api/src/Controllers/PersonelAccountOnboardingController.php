<?php

declare(strict_types=1);

namespace Medisa\Api\Controllers;

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Auth\PersonelAccountOnboardingService;

/**
 * Thin HTTP owner for personnel secure onboarding (management-authenticated).
 */
class PersonelAccountOnboardingController
{
    public static function onboard(Request $request, $personelId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'yonetim-paneli.manage');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        try {
            $body = $request->getJsonBody();
            $override = is_array($body) && array_key_exists('username', $body)
                ? $body['username']
                : null;
            $result = PersonelAccountOnboardingService::onboardAndIssue(
                $pdo,
                (int) $personelId,
                $user,
                $override
            );
        } catch (\Throwable $e) {
            JsonResponse::serverError('Personel hesabi olusturulamadi.');
        }

        PersonelAccountOnboardingService::sendNoStoreHeaders();
        JsonResponse::success($result);
    }

}
