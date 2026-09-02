<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use PDO;

/**
 * The one and only owner of the displayed branch name.
 *
 * `subeler.ad` is the SHORT branch name ("Ankara"). The shared display name
 * ("Medisa Ankara") is derived here and nowhere else — it is deliberately not a
 * column, because a stored tam_ad drifts the moment a company or branch is
 * renamed, and it is deliberately not built in the frontend, because then every
 * screen invents its own spelling.
 *
 * Derivation rule (general, never keyed on an id or a company name):
 *   1. no company relation -> tam_ad = the raw legacy branch name.
 *   2. company present     -> compare the normalized company name with the
 *      normalized short branch name; equal means the branch IS the company
 *      ("Karyapı" / "Karyapı"), so the company name alone is shown instead of
 *      the "Karyapı Karyapı" stutter. Otherwise "<company> <short branch>".
 */
final class SubeReadModel
{
    /**
     * Case/whitespace/diacritic-stable key used for comparison and for
     * in-company duplicate detection. Turkish dotted/dotless I is folded
     * explicitly because mb_strtolower maps 'I' to 'i', not 'ı'.
     */
    public static function normalizeName(?string $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = strtr($text, ['I' => 'ı', 'İ' => 'i']);

        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }

    /** Shared display name for a branch. Read-only, always derived. */
    public static function tamAd(?string $sirketAd, ?string $subeAd): string
    {
        $sube = trim((string) $subeAd);
        $sirket = trim((string) $sirketAd);

        if ($sirket === '') {
            return $sube;
        }
        if ($sube === '') {
            return $sirket;
        }
        if (self::normalizeName($sirket) === self::normalizeName($sube)) {
            return $sirket;
        }

        return $sirket . ' ' . $sube;
    }

    /**
     * SELECT list producing every column mapRow() consumes. Whatever a given
     * schema is missing is projected as a literal NULL, so the same projection,
     * the same mapping and the same legacy fallback serve every state — from a
     * pre-SGK schema through a fully mapped hierarchy.
     */
    public static function selectColumns(PDO $pdo, string $alias = 's'): string
    {
        $columns = $alias . '.id, ' . $alias . '.kod, ' . $alias . '.ad, ' . $alias . '.durum';

        $columns .= OrganizasyonSchema::isSgkRelationReady($pdo)
            ? ', ' . $alias . '.sgk_isveren_id, e.kod AS sgk_isveren_kod, e.ad AS sgk_isveren_ad'
            : ', NULL AS sgk_isveren_id, NULL AS sgk_isveren_kod, NULL AS sgk_isveren_ad';

        return $columns . (OrganizasyonSchema::isSchemaReady($pdo)
            ? ', ' . $alias . '.sirket_id, c.kod AS sirket_kod, c.ad AS sirket_ad'
            : ', NULL AS sirket_id, NULL AS sirket_kod, NULL AS sirket_ad');
    }

    /** JOINs matching selectColumns(). */
    public static function joinSql(PDO $pdo, string $alias = 's'): string
    {
        $joins = '';
        if (OrganizasyonSchema::isSgkRelationReady($pdo)) {
            $joins .= ' LEFT JOIN sgk_isverenler e ON e.id = ' . $alias . '.sgk_isveren_id';
        }
        if (OrganizasyonSchema::isSchemaReady($pdo)) {
            $joins .= ' LEFT JOIN sirketler c ON c.id = ' . $alias . '.sirket_id';
        }

        return $joins;
    }

    /** GROUP BY list matching selectColumns(). */
    public static function groupBySql(PDO $pdo, string $alias = 's'): string
    {
        $group = $alias . '.id, ' . $alias . '.kod, ' . $alias . '.ad, ' . $alias . '.durum';

        if (OrganizasyonSchema::isSgkRelationReady($pdo)) {
            $group .= ', ' . $alias . '.sgk_isveren_id, e.kod, e.ad';
        }
        if (OrganizasyonSchema::isSchemaReady($pdo)) {
            $group .= ', ' . $alias . '.sirket_id, c.kod, c.ad';
        }

        return $group;
    }

    /**
     * Load one branch through the shared projection/mapping path.
     * Callers that need a display label must use `tam_ad`; `ad` stays the short name.
     *
     * @return array<string, mixed>|null
     */
    public static function findById(PDO $pdo, $subeId): ?array
    {
        $id = (int) $subeId;
        if ($id <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT ' . self::selectColumns($pdo)
            . ' FROM subeler s' . self::joinSql($pdo)
            . ' WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        return self::mapRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function mapRow(array $row): array
    {
        $ad = (string) ($row['ad'] ?? '');
        $sirketAd = self::nullableString($row['sirket_ad'] ?? null);

        $item = [
            'id' => (int) ($row['id'] ?? 0),
            'kod' => (string) ($row['kod'] ?? ''),
            'ad' => $ad,
            'tam_ad' => self::tamAd($sirketAd, $ad),
            'durum' => (string) ($row['durum'] ?? ''),
            'sirket' => self::relation(
                $row['sirket_id'] ?? null,
                $row['sirket_kod'] ?? null,
                $row['sirket_ad'] ?? null
            ),
            'sgk_isveren' => self::relation(
                $row['sgk_isveren_id'] ?? null,
                $row['sgk_isveren_kod'] ?? null,
                $row['sgk_isveren_ad'] ?? null
            ),
            'departman_ids' => self::splitIds($row['departman_ids'] ?? null),
            'departman_adlari' => self::splitNames($row['departman_adlari'] ?? null),
        ];

        return $item;
    }

    /**
     * @param mixed $id
     * @param mixed $kod
     * @param mixed $ad
     * @return array<string, mixed>|null
     */
    private static function relation($id, $kod, $ad): ?array
    {
        $parsed = (int) $id;
        if ($parsed <= 0) {
            return null;
        }

        return [
            'id' => $parsed,
            'kod' => self::nullableString($kod),
            'ad' => (string) $ad,
        ];
    }

    /** @param mixed $value */
    private static function nullableString($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private static function splitIds($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $ids = [];
        foreach (explode(',', (string) $value) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private static function splitNames($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $names = [];
        foreach (explode(',', (string) $value) as $name) {
            $names[] = (string) $name;
        }

        return $names;
    }
}
