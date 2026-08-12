<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

/**
 * Stateless short-lived HMAC workplace QR tokens (S3C).
 * Token is NOT a user JWT and never embeds personel/user identity.
 */
class QrTokenService
{
    /** Max future skew for iat (seconds). */
    private const IAT_FUTURE_SKEW_SECONDS = 30;

    /**
     * Mint a display token for an authenticated kiosk branch.
     *
     * @return array{token:string,issued_at:int,expires_at:int,ttl_seconds:int,sube_id:int,jti:string,version:int}
     */
    public static function mint($subeId)
    {
        QrConfig::assertReady();
        $subeId = (int) $subeId;
        if ($subeId <= 0) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'Gecersiz sube.', 400, 'sube_id');
        }

        $ttl = QrConfig::ttlSeconds();
        $now = time();
        $jti = bin2hex(random_bytes(16)); // 128-bit
        $payload = [
            'v' => QrConfig::TOKEN_VERSION,
            'sube_id' => $subeId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => $jti,
        ];

        return [
            'token' => self::encode($payload),
            'issued_at' => $now,
            'expires_at' => $now + $ttl,
            'ttl_seconds' => $ttl,
            'sube_id' => $subeId,
            'jti' => $jti,
            'version' => QrConfig::TOKEN_VERSION,
        ];
    }

    /**
     * Strict verify. Returns normalized claims on success.
     *
     * @return array{version:int,sube_id:int,iat:int,exp:int,jti:string}
     */
    public static function verify($token)
    {
        QrConfig::assertReady();
        if (!is_string($token) || trim($token) === '') {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }

        $parts = explode('.', trim($token));
        if (count($parts) !== 3 || $parts[0] !== 'mqr1') {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }

        $signingInput = $parts[0] . '.' . $parts[1];
        $expected = self::base64UrlEncode(
            hash_hmac('sha256', $signingInput, (string) QrConfig::signingSecret(), true)
        );
        if (!hash_equals($expected, $parts[2])) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }

        $json = self::base64UrlDecode($parts[1]);
        if ($json === false) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }
        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }

        if (!isset($payload['v'])) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }
        $version = (int) $payload['v'];
        if ($version !== QrConfig::TOKEN_VERSION) {
            throw new QrAttendanceException(
                'QR_TOKEN_VERSION_UNSUPPORTED',
                'QR token surumu desteklenmiyor.',
                400,
                'token'
            );
        }

        foreach (['sube_id', 'iat', 'exp', 'jti'] as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
            }
        }

        $subeId = (int) $payload['sube_id'];
        $iat = (int) $payload['iat'];
        $exp = (int) $payload['exp'];
        $jti = strtolower(trim((string) $payload['jti']));

        if ($subeId <= 0 || $iat <= 0 || $exp <= 0 || !preg_match('/^[a-f0-9]{32}$/', $jti)) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }

        $ttl = $exp - $iat;
        if ($ttl < QrConfig::TTL_MIN || $ttl > QrConfig::TTL_MAX) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }

        $now = time();
        if ($iat > $now + self::IAT_FUTURE_SKEW_SECONDS) {
            throw new QrAttendanceException('QR_TOKEN_INVALID', 'QR token gecersiz.', 400, 'token');
        }
        if ($exp < $now) {
            throw new QrAttendanceException(
                'QR_TOKEN_EXPIRED',
                'QR suresi doldu, tekrar okutun.',
                400,
                'token'
            );
        }

        return [
            'version' => $version,
            'sube_id' => $subeId,
            'iat' => $iat,
            'exp' => $exp,
            'jti' => $jti,
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function encode(array $payload)
    {
        $body = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signingInput = 'mqr1.' . $body;
        $sig = self::base64UrlEncode(
            hash_hmac('sha256', $signingInput, (string) QrConfig::signingSecret(), true)
        );

        return $signingInput . '.' . $sig;
    }

    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @return string|false */
    private static function base64UrlDecode($data)
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
