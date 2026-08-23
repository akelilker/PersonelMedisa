<?php

declare(strict_types=1);

namespace Medisa\Api\Support;

/**
 * UTF-8 string operations that remain available when the optional mbstring
 * extension is absent in a cPanel or local PHP runtime.
 */
final class Utf8
{
    public static function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($value, 'UTF-8');
        }

        return count(self::characters($value));
    }

    public static function substring(string $value, int $offset, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, $offset, $length, 'UTF-8');
        }

        return implode('', array_slice(self::characters($value), $offset, $length));
    }

    public static function lower(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return (string) mb_strtolower($value, 'UTF-8');
        }

        return strtolower(strtr($value, [
            'Ç' => 'ç',
            'Ğ' => 'ğ',
            'İ' => 'i',
            'Ö' => 'ö',
            'Ş' => 'ş',
            'Ü' => 'ü',
        ]));
    }

    /** @return list<string> */
    private static function characters(string $value): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($characters) ? $characters : str_split($value);
    }
}
