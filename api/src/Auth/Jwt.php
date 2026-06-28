<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

class Jwt
{
    /** @param array<string, mixed> $payload */
    public static function encode(array $payload)
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $segments = [
            self::base64UrlEncode(json_encode($header)),
            self::base64UrlEncode(json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, (string) medisa_config('jwt_secret'), true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /** @return array<string, mixed>|null */
    public static function decode($token)
    {
        if (!is_string($token) || trim($token) === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $signingInput = $parts[0] . '.' . $parts[1];
        $expected = self::base64UrlEncode(
            hash_hmac('sha256', $signingInput, (string) medisa_config('jwt_secret'), true)
        );

        if (!hash_equals($expected, $parts[2])) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($parts[1]);
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
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
