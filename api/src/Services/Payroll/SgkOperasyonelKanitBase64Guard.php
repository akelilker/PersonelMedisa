<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1.1: Strict Base64 + decoded-size guard for operasyonel kanit validation-only input.
 * Canonical limit is shared with frontend via SGK_OPERASYONEL_KANIT_MAX_DECODED_BYTES.
 */
final class SgkOperasyonelKanitBase64Guard
{
    /** Decoded byte ceiling (10 MiB). Do not duplicate as a magic number in controllers. */
    public const MAX_DECODED_BYTES = 10 * 1024 * 1024;

    public const ERROR_BASE64_GECERSIZ = 'SGK_OPERASYONEL_KANIT_BASE64_GECERSIZ';
    public const ERROR_DOSYA_BOYUTU_ASILDI = 'SGK_OPERASYONEL_KANIT_DOSYA_BOYUTU_ASILDI';

    /**
     * Resolve optional Base64 payload before validator hash checks.
     * Missing / empty string → null bytes (no content). Whitespace is not stripped.
     *
     * @return array{
     *   ok: true,
     *   bytes: ?string
     * }|array{
     *   ok: false,
     *   http: int,
     *   code: string,
     *   message: string,
     *   field: string,
     *   meta: array<string,int>
     * }
     */
    public static function resolve(?string $encoded): array
    {
        if ($encoded === null || $encoded === '') {
            return ['ok' => true, 'bytes' => null];
        }

        $encodedLen = strlen($encoded);
        // Reject clearly oversized encoded payloads before decode (DoS). Exact MAX±1 still need
        // post-decode checks because Base64 padding makes several sizes share the same length.
        if ($encodedLen > self::maxEncodedLength()) {
            return self::sizeExceeded(self::estimateDecodedBytesUpperBound($encodedLen), true);
        }

        if (!self::isStrictAlphabet($encoded)) {
            return self::invalid('Operasyonel kanit Base64 icerigi gecersiz karakter veya bicim iceriyor.');
        }

        if (($encodedLen % 4) !== 0) {
            return self::invalid('Operasyonel kanit Base64 padding/uzunluk gecersiz.');
        }

        if (!self::isValidPadding($encoded)) {
            return self::invalid('Operasyonel kanit Base64 padding gecersiz.');
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            return self::invalid('Operasyonel kanit Base64 cozumlenemedi.');
        }

        if (base64_encode($decoded) !== $encoded) {
            return self::invalid('Operasyonel kanit Base64 canonical bicimde degil.');
        }

        $actual = strlen($decoded);
        if ($actual > self::MAX_DECODED_BYTES) {
            return self::sizeExceeded($actual, false);
        }

        return ['ok' => true, 'bytes' => $decoded];
    }

    /**
     * Upper-bound decoded size from encoded length alone (no allocation of decoded buffer).
     * floor(len * 3 / 4) never underestimates a well-formed Base64 payload.
     */
    public static function estimateDecodedBytesUpperBound(int $encodedLength): int
    {
        if ($encodedLength <= 0) {
            return 0;
        }

        return intdiv($encodedLength * 3, 4);
    }

    /** Maximum encoded character length that can still yield ≤ MAX_DECODED_BYTES. */
    public static function maxEncodedLength(): int
    {
        return 4 * (int) ceil(self::MAX_DECODED_BYTES / 3);
    }

    private static function isStrictAlphabet(string $encoded): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9+/]*={0,2}$#', $encoded);
    }

    private static function isValidPadding(string $encoded): bool
    {
        $len = strlen($encoded);
        if ($len === 0) {
            return true;
        }

        $eq = 0;
        for ($i = $len - 1; $i >= 0 && $encoded[$i] === '='; $i--) {
            $eq++;
        }
        if ($eq > 2) {
            return false;
        }

        $body = $eq > 0 ? substr($encoded, 0, $len - $eq) : $encoded;
        if ($body === '' && $eq > 0) {
            return false;
        }
        if (strpos($body, '=') !== false) {
            return false;
        }

        return true;
    }

    /** @return array{ok:false,http:int,code:string,message:string,field:string,meta:array<string,int>} */
    private static function invalid(string $message): array
    {
        return [
            'ok' => false,
            'http' => 422,
            'code' => self::ERROR_BASE64_GECERSIZ,
            'message' => $message,
            'field' => 'dosya_icerik_base64',
            'meta' => [
                'limit_byte' => self::MAX_DECODED_BYTES,
            ],
        ];
    }

    /** @return array{ok:false,http:int,code:string,message:string,field:string,meta:array<string,int>} */
    private static function sizeExceeded(int $byteCount, bool $estimated): array
    {
        $meta = [
            'limit_byte' => self::MAX_DECODED_BYTES,
        ];
        if ($estimated) {
            $meta['tahmini_byte'] = $byteCount;
        } else {
            $meta['byte_sayisi'] = $byteCount;
        }

        return [
            'ok' => false,
            'http' => 413,
            'code' => self::ERROR_DOSYA_BOYUTU_ASILDI,
            'message' => 'Operasyonel kanit dosya boyutu limiti asildi.',
            'field' => 'dosya_icerik_base64',
            'meta' => $meta,
        ];
    }
}
