<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Http\JsonResponse;

/**
 * Canonical owner of the single standard initial password shared by all new accounts.
 *
 * Only the bcrypt hash is stored, in the private production config
 * (`standard_initial_password_hash`). The plaintext is never present in the repo,
 * in a response, in a log or in any config example.
 */
class StandardInitialPassword
{
    public const CONFIG_KEY = 'standard_initial_password_hash';
    public const ERROR_CODE = 'STANDARD_INITIAL_PASSWORD_NOT_CONFIGURED';

    /**
     * @return string|null the configured bcrypt hash, or null when unusable
     */
    public static function configuredHash()
    {
        $hash = (string) medisa_config(self::CONFIG_KEY, '');
        if ($hash === '' || strpos($hash, 'CHANGE_ME') === 0) {
            return null;
        }
        if (preg_match('/^\$2[aby]\$\d{2}\$.{53}$/', $hash) !== 1) {
            return null;
        }

        return $hash;
    }

    public static function isConfigured()
    {
        return self::configuredHash() !== null;
    }

    /**
     * Fail closed via JsonResponse when the production secret is absent or malformed.
     *
     * @return string
     */
    public static function requireHash()
    {
        $hash = self::configuredHash();
        if ($hash === null) {
            JsonResponse::error(
                409,
                self::ERROR_CODE,
                'Standart baslangic sifresi yapilandirilmamis.'
            );
        }

        return $hash;
    }
}
