<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

class PasswordHasher
{
    public static function hash($plain)
    {
        return password_hash((string) $plain, PASSWORD_BCRYPT);
    }

    public static function verify($plain, $hash)
    {
        if (!is_string($hash) || $hash === '') {
            return false;
        }

        return password_verify((string) $plain, $hash);
    }
}
