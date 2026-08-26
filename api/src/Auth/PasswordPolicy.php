<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Http\JsonResponse;

/**
 * Canonical password validation for change-password and personnel activation.
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    /**
     * @return string|null error message, or null if valid
     */
    public static function validateNewPassword($plain)
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return 'Yeni sifre zorunludur.';
        }
        if (strlen($plain) < self::MIN_LENGTH) {
            return 'Yeni sifre en az 8 karakter olmalidir.';
        }

        return null;
    }

    /**
     * Fail closed via JsonResponse when invalid.
     */
    public static function assertValidNewPassword($plain, $confirmation = null)
    {
        $error = self::validateNewPassword($plain);
        if ($error !== null) {
            JsonResponse::badRequest($error, 'VALIDATION_ERROR', 'new_password');
        }
        if ($confirmation !== null && (string) $confirmation !== (string) $plain) {
            JsonResponse::badRequest(
                'Sifre tekrari eslesmiyor.',
                'VALIDATION_ERROR',
                'new_password_confirmation'
            );
        }
    }
}
