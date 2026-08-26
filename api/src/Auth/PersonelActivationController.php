<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Auth\PersonelAccountOnboardingService;

/**
 * Public personnel activation endpoints — authorized solely by one-time high-entropy token.
 */
class PersonelActivationController
{
    public static function status(Request $request)
    {
        PersonelAccountOnboardingService::sendNoStoreHeaders();

        if (!medisa_config_ready()) {
            JsonResponse::serverError('API yapilandirmasi tamamlanmamis.');
        }

        $body = $request->getJsonBody();
        $token = isset($body['token']) ? (string) $body['token'] : '';

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        $status = PersonelAccountOnboardingService::activationStatus($pdo, $token);
        // Never echo raw token.
        JsonResponse::success($status);
    }

    public static function complete(Request $request)
    {
        PersonelAccountOnboardingService::sendNoStoreHeaders();

        if (!medisa_config_ready()) {
            JsonResponse::serverError('API yapilandirmasi tamamlanmamis.');
        }

        $body = $request->getJsonBody();
        $token = isset($body['token']) ? (string) $body['token'] : '';
        $newPassword = isset($body['new_password']) ? (string) $body['new_password'] : '';
        $confirmation = array_key_exists('new_password_confirmation', $body)
            ? (string) $body['new_password_confirmation']
            : (array_key_exists('new_password_tekrar', $body) ? (string) $body['new_password_tekrar'] : null);

        // Reject trusted-identity selectors from client.
        if (array_key_exists('user_id', $body)
            || array_key_exists('personel_id', $body)
            || array_key_exists('username', $body)
            || array_key_exists('rol', $body)
        ) {
            JsonResponse::badRequest(
                'Aktivasyon kimligi yalnizca gecerli baglanti tokeni ile belirlenir.',
                'VALIDATION_ERROR'
            );
        }

        try {
            $pdo = Connection::get();
        } catch (\Throwable $e) {
            JsonResponse::serverError('Veritabani baglantisi kurulamadi.');
        }

        try {
            $result = PersonelAccountOnboardingService::completeActivation(
                $pdo,
                $token,
                $newPassword,
                $confirmation
            );
        } catch (\Throwable $e) {
            JsonResponse::serverError('Aktivasyon tamamlanamadi.');
        }

        JsonResponse::success($result);
    }
}
