<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Canonical owner of the personel free-text search predicate.
 *
 * From the user's point of view `ad` + `soyad` are a single "Ad Soyad" field, so a
 * single search token is matched against the concatenated name instead of against
 * each column separately. The search text is split on Unicode whitespace and the
 * tokens are combined with AND while the searchable fields are combined with OR:
 * every token must match at least one field. That makes "Ilker Akel",
 * "Akel Ilker", "ilk ak" and "ILKER AKEL" all resolve to the same person.
 *
 * The predicate is appended to an existing `$where` list so callers can build the
 * org/scope predicate first and only ever combine it with AND. It never widens
 * authorization.
 */
final class PersonelSearchPredicate
{
    /** Longest accepted search text; anything beyond this is truncated. */
    public const MAX_LENGTH = 120;

    /** Upper bound on generated LIKE groups; extra tokens are dropped. */
    public const MAX_TOKENS = 6;

    /**
     * `ESCAPE '\\'` in SQL: user supplied `%`, `_` and `\` are matched literally.
     */
    private const LIKE_ESCAPE_SQL = " ESCAPE '\\\\'";

    /** @var array<string, string> Cached per connection-schema collation suffix. */
    private static array $collateSuffixCache = [];

    /**
     * Normalizes raw request input: collapses every Unicode whitespace run into a
     * single space, trims and clamps the length.
     */
    public static function normalize(mixed $raw): string
    {
        $value = (string) $raw;
        $collapsed = preg_replace('/\s+/u', ' ', $value);
        if (!is_string($collapsed)) {
            // Invalid UTF-8: fall back to the byte-oriented class.
            $collapsed = preg_replace('/\s+/', ' ', $value);
        }
        $value = trim(is_string($collapsed) ? $collapsed : $value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            $value = trim((string) mb_substr($value, 0, self::MAX_LENGTH, 'UTF-8'));
        } elseif (!function_exists('mb_strlen') && strlen($value) > self::MAX_LENGTH) {
            $value = trim(substr($value, 0, self::MAX_LENGTH));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function tokenize(mixed $raw): array
    {
        $normalized = self::normalize($raw);
        if ($normalized === '') {
            return [];
        }

        $tokens = [];
        foreach (explode(' ', $normalized) as $token) {
            if ($token === '') {
                continue;
            }
            $tokens[] = $token;
            if (count($tokens) === self::MAX_TOKENS) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * Appends the search predicate. No-op for empty input.
     *
     * @param list<string>         $where
     * @param array<string, mixed> $params
     */
    public static function append(
        array &$where,
        array &$params,
        mixed $raw,
        string $alias = 'p',
        string $paramPrefix = 'search',
        ?PDO $pdo = null
    ): void {
        $tokens = self::tokenize($raw);
        if ($tokens === []) {
            return;
        }

        $collate = self::collateSuffix($pdo);
        $fields = self::searchableFieldExpressions($alias, $collate);
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $paramPrefix);
        $prefix = is_string($prefix) && $prefix !== '' ? $prefix : 'search';

        foreach ($tokens as $tokenIndex => $token) {
            $pattern = '%' . self::escapeLike($token) . '%';
            $orGroup = [];
            foreach ($fields as $fieldIndex => $expression) {
                // A named placeholder is bound once per field: the connection runs
                // with emulated prepares disabled, which forbids re-use.
                $key = $prefix . '_t' . $tokenIndex . '_f' . $fieldIndex;
                $orGroup[] = $expression . ' LIKE :' . $key . self::LIKE_ESCAPE_SQL;
                $params[$key] = $pattern;
            }
            $where[] = '(' . implode(' OR ', $orGroup) . ')';
        }
    }

    /**
     * Fields a single token may match, in OR order.
     *
     * @return list<string>
     */
    private static function searchableFieldExpressions(string $alias, string $collate): array
    {
        $col = self::sanitizeAlias($alias) . '.';

        return [
            "CONCAT_WS(' ', {$col}ad, {$col}soyad)" . $collate,
            $col . 'sicil_no' . $collate,
            $col . 'tc_kimlik_no' . $collate,
            $col . 'telefon' . $collate,
        ];
    }

    private static function sanitizeAlias(string $alias): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $alias);

        return is_string($clean) && $clean !== '' ? $clean : 'p';
    }

    /**
     * LIKE wildcards typed by the user are literal text.
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Turkish case-insensitive matching is proven against the real database rather
     * than assumed: `*_general_ci` folds `I/i/İ/ı` and `Ş/Ğ/Ü/Ö/Ç` onto their
     * ASCII bases, while `*_turkish_ci` refuses `sule` -> `Şule`. Only the search
     * expression is collated; the schema is untouched.
     */
    private static function collateSuffix(?PDO $pdo): string
    {
        if (!$pdo instanceof PDO) {
            return '';
        }

        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
                return '';
            }
            $cacheKey = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (array_key_exists($cacheKey, self::$collateSuffixCache)) {
                return self::$collateSuffixCache[$cacheKey];
            }

            $charset = (string) $pdo->query(
                "SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personeller' AND COLUMN_NAME = 'ad'
                 LIMIT 1"
            )->fetchColumn();

            $suffix = '';
            if (preg_match('/^[a-z0-9]+$/', $charset) === 1) {
                $collation = $charset . '_general_ci';
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLLATIONS WHERE COLLATION_NAME = :c'
                );
                $stmt->execute(['c' => $collation]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $suffix = ' COLLATE ' . $collation;
                }
            }

            self::$collateSuffixCache[$cacheKey] = $suffix;

            return $suffix;
        } catch (\Throwable $e) {
            // Unknown schema metadata: the column's own (case-insensitive) collation
            // still applies, so search keeps working without an explicit COLLATE.
            return '';
        }
    }
}
