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
            $result = PersonelAccountOnboardingService::onboardAndIssue($pdo, (int) $personelId, $user);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Personel hesabi olusturulamadi.');
        }

        PersonelAccountOnboardingService::sendNoStoreHeaders();
        JsonResponse::success($result);
    }

    public static function reissue(Request $request, $kullaniciId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'yonetim-paneli.manage');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        try {
            $result = PersonelAccountOnboardingService::reissueActivation($pdo, (int) $kullaniciId, $user);
        } catch (\Throwable $e) {
            JsonResponse::serverError('Aktivasyon baglantisi yenilenemedi.');
        }

        PersonelAccountOnboardingService::sendNoStoreHeaders();
        JsonResponse::success($result);
    }

    public static function pendingMeta(Request $request, $kullaniciId)
    {
        $user = AuthMiddleware::authenticate($request, true);
        RolePermissions::assert($user, 'yonetim-paneli.manage');

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $meta = PersonelAccountOnboardingService::getPendingInvitationMeta($pdo, (int) $kullaniciId);
        JsonResponse::success([
            'activation_invitation' => $meta,
        ]);
    }
}
