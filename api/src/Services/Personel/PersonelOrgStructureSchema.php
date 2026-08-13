<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Pack6 — Bölüm / Birim / Pozisyon + subeler.sgk_isveren_id schema readiness owner.
 * Unconditional JOIN/SELECT/INSERT against new tables is forbidden until ready.
 * Does not weaken Pack5 ORG_LOCATION_SCHEMA_NOT_READY.
 *
 * Ready means usable structure: required column shapes, parent/personnel FK
 * semantics (not merely names), and uniqueness contracts — not table-name
 * existence alone.
 */
final class PersonelOrgStructureSchema
{
    public const ERROR_CODE = 'ORG_STRUCTURE_SCHEMA_NOT_READY';

    /** @var array<string, bool> */
    private static $readyCache = [];

    public static function isReady(PDO $pdo): bool
    {
        $cacheKey = self::cacheKey($pdo);
        if ($cacheKey !== null && array_key_exists($cacheKey, self::$readyCache)) {
            return self::$readyCache[$cacheKey];
        }

        $ready = self::evaluateReady($pdo);
        // Cache only positive readiness so pre-repair FALSE does not stick after 065 converges
        // on the same PDO (tests / same-request migrate-then-check).
        if ($ready && $cacheKey !== null) {
            self::$readyCache[$cacheKey] = true;
        }

        return $ready;
    }

    /**
     * Test/support hook — clears request-local readiness cache.
     */
    public static function clearReadyCache(): void
    {
        self::$readyCache = [];
    }

    private static function evaluateReady(PDO $pdo): bool
    {
        if (!self::tableExists($pdo, 'bolumler')
            || !self::tableExists($pdo, 'birimler')
            || !self::tableExists($pdo, 'pozisyonlar')
        ) {
            return false;
        }

        // Required business / usability columns with compatible shapes.
        if (!self::intUnsignedColumn($pdo, 'bolumler', 'departman_id', false)
            || !self::varcharNotNullColumn($pdo, 'bolumler', 'ad')
            || !self::varcharNotNullColumn($pdo, 'bolumler', 'durum')
            || !self::intUnsignedColumn($pdo, 'birimler', 'bolum_id', false)
            || !self::varcharNotNullColumn($pdo, 'birimler', 'ad')
            || !self::varcharNotNullColumn($pdo, 'birimler', 'durum')
            || !self::varcharNotNullColumn($pdo, 'pozisyonlar', 'ad')
            || !self::varcharNotNullColumn($pdo, 'pozisyonlar', 'durum')
            || !self::intUnsignedColumn($pdo, 'personeller', 'bolum_id', true)
            || !self::intUnsignedColumn($pdo, 'personeller', 'birim_id', true)
            || !self::intUnsignedColumn($pdo, 'personeller', 'pozisyon_id', true)
            || !self::intUnsignedColumn($pdo, 'subeler', 'sgk_isveren_id', true)
        ) {
            return false;
        }

        // Correctness-critical FK semantics (KEY_COLUMN_USAGE), not name-only.
        if (!self::foreignKeyMatches($pdo, 'bolumler', 'fk_bolumler_departman', 'departman_id', 'departmanlar', 'id')
            || !self::foreignKeyMatches($pdo, 'birimler', 'fk_birimler_bolum', 'bolum_id', 'bolumler', 'id')
            || !self::foreignKeyMatches($pdo, 'personeller', 'fk_personeller_bolum', 'bolum_id', 'bolumler', 'id')
            || !self::foreignKeyMatches($pdo, 'personeller', 'fk_personeller_birim', 'birim_id', 'birimler', 'id')
            || !self::foreignKeyMatches($pdo, 'personeller', 'fk_personeller_pozisyon', 'pozisyon_id', 'pozisyonlar', 'id')
            || !self::foreignKeyMatches($pdo, 'subeler', 'fk_subeler_sgk_isveren', 'sgk_isveren_id', 'sgk_isverenler', 'id')
        ) {
            return false;
        }

        // Unique index semantics: UNIQUE + exact ordered columns.
        if (!self::uniqueIndexMatches($pdo, 'bolumler', 'uq_bolumler_departman_ad', ['departman_id', 'ad'])
            || !self::uniqueIndexMatches($pdo, 'birimler', 'uq_birimler_bolum_ad', ['bolum_id', 'ad'])
            || !self::uniqueIndexMatches($pdo, 'pozisyonlar', 'uq_pozisyonlar_ad', ['ad'])
        ) {
            return false;
        }

        return true;
    }

    /**
     * True when payload explicitly attempts to write Pack6 org-structure fields.
     * Explicit null/blank still counts as write intent when the key is present.
     *
     * @param array<string, mixed> $payload
     */
    public static function payloadRequestsOrgStructureFields(array $payload): bool
    {
        foreach (['bolum_id', 'birim_id', 'pozisyon_id', 'bolum', 'birim', 'pozisyon'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fail-closed gate for shared create/update owners.
     *
     * @param array<string, mixed> $payload
     * @throws PersonelValidationException
     */
    public static function assertReadyForOrgStructureWrite(PDO $pdo, array $payload): void
    {
        if (!self::payloadRequestsOrgStructureFields($payload)) {
            return;
        }
        if (self::isReady($pdo)) {
            return;
        }
        throw new PersonelValidationException(
            'bolum_id',
            'Org structure schema hazir degil; bolum_id / birim_id / pozisyon_id yazilamaz.',
            self::ERROR_CODE
        );
    }

    public static function existsActiveBolum(PDO $pdo, int $id): bool
    {
        if ($id < 1 || !self::isReady($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id FROM bolumler WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function existsActiveBirim(PDO $pdo, int $id): bool
    {
        if ($id < 1 || !self::isReady($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id FROM birimler WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function existsActivePozisyon(PDO $pdo, int $id): bool
    {
        if ($id < 1 || !self::isReady($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare("SELECT id FROM pozisyonlar WHERE id = :id AND durum = 'AKTIF' LIMIT 1");
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Fail-closed hierarchy invariants for effective (merged) org state.
     * Does not silently rewrite parents.
     *
     * @param array<string, mixed> $effective keys: departman_id, bolum_id, birim_id, pozisyon_id
     * @throws PersonelValidationException
     */
    public static function assertHierarchyConsistent(PDO $pdo, array $effective): void
    {
        if (!self::isReady($pdo)) {
            return;
        }

        $departmanId = self::nullablePositiveInt($effective, 'departman_id');
        $bolumId = self::nullablePositiveInt($effective, 'bolum_id');
        $birimId = self::nullablePositiveInt($effective, 'birim_id');
        $pozisyonId = self::nullablePositiveInt($effective, 'pozisyon_id');

        if ($bolumId !== null) {
            if ($departmanId === null) {
                throw new PersonelValidationException(
                    'bolum_id',
                    'Bolum secildiginde departman_id zorunludur.'
                );
            }
            $stmt = $pdo->prepare(
                "SELECT departman_id FROM bolumler WHERE id = :id AND durum = 'AKTIF' LIMIT 1"
            );
            $stmt->execute(['id' => $bolumId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new PersonelValidationException('bolum_id', 'Gecersiz bolum.');
            }
            if ((int) $row['departman_id'] !== $departmanId) {
                throw new PersonelValidationException(
                    'bolum_id',
                    'Secilen bolum, secilen departmana ait degil.'
                );
            }
        }

        if ($birimId !== null) {
            if ($bolumId === null) {
                throw new PersonelValidationException(
                    'birim_id',
                    'Birim secildiginde bolum_id zorunludur.'
                );
            }
            $stmt = $pdo->prepare(
                "SELECT bolum_id FROM birimler WHERE id = :id AND durum = 'AKTIF' LIMIT 1"
            );
            $stmt->execute(['id' => $birimId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new PersonelValidationException('birim_id', 'Gecersiz birim.');
            }
            if ((int) $row['bolum_id'] !== $bolumId) {
                throw new PersonelValidationException(
                    'birim_id',
                    'Secilen birim, secilen bolume ait degil.'
                );
            }
        }

        if ($pozisyonId !== null && !self::existsActivePozisyon($pdo, $pozisyonId)) {
            throw new PersonelValidationException('pozisyon_id', 'Gecersiz pozisyon.');
        }
    }

    /**
     * Merge current row org fields with update payload for hierarchy checks.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $payload
     * @return array{departman_id:?int,bolum_id:?int,birim_id:?int,pozisyon_id:?int}
     */
    public static function mergeEffectiveOrgState(array $current, array $payload): array
    {
        $pick = static function (array $payload, array $current, string $key) {
            if (array_key_exists($key, $payload)) {
                if ($payload[$key] === null || $payload[$key] === '') {
                    return null;
                }

                return (int) $payload[$key];
            }
            if (!array_key_exists($key, $current) || $current[$key] === null || $current[$key] === '') {
                return null;
            }

            return (int) $current[$key];
        };

        return [
            'departman_id' => $pick($payload, $current, 'departman_id'),
            'bolum_id' => $pick($payload, $current, 'bolum_id'),
            'birim_id' => $pick($payload, $current, 'birim_id'),
            'pozisyon_id' => $pick($payload, $current, 'pozisyon_id'),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function nullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
            return null;
        }
        $v = (int) $row[$key];

        return $v > 0 ? $v : null;
    }

    private static function cacheKey(PDO $pdo): ?string
    {
        try {
            $db = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

            // spl_object_id keeps distinct PDO instances from contaminating each other in tests.
            return spl_object_id($pdo) . '|' . $db;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :t"
                );
                $stmt->execute(['t' => $table]);

                return (int) $stmt->fetchColumn() === 1;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t'
            );
            $stmt->execute(['t' => $table]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }

                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function intUnsignedColumn(PDO $pdo, string $table, string $column, bool $nullable): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return self::columnExists($pdo, $table, $column);
            }
            $wantNull = $nullable ? 'YES' : 'NO';
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c
                   AND data_type = 'int'
                   AND column_type LIKE '%unsigned%'
                   AND is_nullable = :n"
            );
            $stmt->execute(['t' => $table, 'c' => $column, 'n' => $wantNull]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function varcharNotNullColumn(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return self::columnExists($pdo, $table, $column);
            }
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c
                   AND data_type = 'varchar'
                   AND is_nullable = 'NO'"
            );
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function foreignKeyMatches(
        PDO $pdo,
        string $table,
        string $constraintName,
        string $column,
        string $refTable,
        string $refColumn
    ): bool {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                // Fail-closed: sqlite fixtures do not model Pack6 FK contract.
                return false;
            }
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE table_schema = DATABASE() AND table_name = :t
                   AND constraint_name = :c AND constraint_type = 'FOREIGN KEY'"
            );
            $stmt->execute(['t' => $table, 'c' => $constraintName]);
            if ((int) $stmt->fetchColumn() !== 1) {
                return false;
            }

            $stmt = $pdo->prepare(
                'SELECT column_name, referenced_table_name, referenced_column_name, ordinal_position
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE table_schema = DATABASE() AND table_name = :t
                   AND constraint_name = :c
                   AND referenced_table_name IS NOT NULL
                 ORDER BY ordinal_position ASC'
            );
            $stmt->execute(['t' => $table, 'c' => $constraintName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) !== 1) {
                return false;
            }
            $row = $rows[0];

            return (string) ($row['column_name'] ?? '') === $column
                && (string) ($row['referenced_table_name'] ?? '') === $refTable
                && (string) ($row['referenced_column_name'] ?? '') === $refColumn
                && (int) ($row['ordinal_position'] ?? 0) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param list<string> $orderedColumns
     */
    private static function uniqueIndexMatches(
        PDO $pdo,
        string $table,
        string $indexName,
        array $orderedColumns
    ): bool {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT column_name, seq_in_index, non_unique
                 FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i
                 ORDER BY seq_in_index ASC'
            );
            $stmt->execute(['t' => $table, 'i' => $indexName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($rows) !== count($orderedColumns)) {
                return false;
            }
            foreach ($rows as $i => $row) {
                if ((int) ($row['non_unique'] ?? 1) !== 0) {
                    return false;
                }
                if ((int) ($row['seq_in_index'] ?? 0) !== ($i + 1)) {
                    return false;
                }
                if ((string) ($row['column_name'] ?? '') !== $orderedColumns[$i]) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
