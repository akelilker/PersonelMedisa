<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

/**
 * QR signing secret + TTL contract (S3C).
 * Missing/invalid secret must fail closed for QR only — not crash the app.
 */
class QrConfig
{
    public const TTL_DEFAULT = 60;
    public const TTL_MIN = 30;
    public const TTL_MAX = 120;
    public const TOKEN_VERSION = 1;

    public static function isReady()
    {
        $secret = self::signingSecret();

        return $secret !== null;
    }

    /** @return string|null */
    public static function signingSecret()
    {
        $raw = medisa_config('qr_signing_secret', null);
        if (!is_string($raw)) {
            return null;
        }
        $secret = trim($raw);
        if ($secret === '' || strpos($secret, 'CHANGE_ME') === 0) {
            return null;
        }
        if (strlen($secret) < 32) {
            return null;
        }

        return $secret;
    }

    /**
     * Server-owned TTL seconds. Invalid config falls back to default 60.
     */
    public static function ttlSeconds()
    {
        $raw = medisa_config('qr_ttl_seconds', self::TTL_DEFAULT);
        if (is_string($raw) && is_numeric($raw)) {
            $raw = (int) $raw;
        }
        if (!is_int($raw) && !(is_float($raw) && (int) $raw == $raw)) {
            return self::TTL_DEFAULT;
        }
        $ttl = (int) $raw;
        if ($ttl < self::TTL_MIN || $ttl > self::TTL_MAX) {
            return self::TTL_DEFAULT;
        }

        return $ttl;
    }

    public static function assertReady()
    {
        if (!self::isReady()) {
            throw new QrAttendanceException(
                'QR_CONFIG_NOT_READY',
                'QR imza yapilandirmasi hazir degil.',
                503
            );
        }
    }
}
