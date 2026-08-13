<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Pack6 — Bölüm / Birim / Pozisyon + subeler.sgk_isveren_id schema readiness owner.
 * Unconditional JOIN/SELECT/INSERT against new tables is forbidden until ready.
 * Does not weaken Pack5 ORG_LOCATION_SCHEMA_NOT_READY.
 *
 * Ready means usable structure: required columns, parent/personnel FKs, and
 * uniqueness contracts — not merely table-name existence.
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

        // Columns application SQL depends on (e.g. WHERE durum='AKTIF').
        foreach (['id', 'departman_id', 'ad', 'durum'] as $col) {
            if (!self::columnExists($pdo, 'bolumler', $col)) {
                return false;
            }
        }
        foreach (['id', 'bolum_id', 'ad', 'durum'] as $col) {
            if (!self::columnExists($pdo, 'birimler', $col)) {
                return false;
            }
        }
        foreach (['id', 'ad', 'durum'] as $col) {
            if (!self::columnExists($pdo, 'pozisyonlar', $col)) {
                return false;
            }
        }
        if (!self::columnExists($pdo, 'personeller', 'bolum_id')
            || !self::columnExists($pdo, 'personeller', 'birim_id')
            || !self::columnExists($pdo, 'personeller', 'pozisyon_id')
            || !self::columnExists($pdo, 'subeler', 'sgk_isveren_id')
        ) {
            return false;
        }

        // Correctness-critical relationships + uniqueness for safe writes/import.
        if (!self::foreignKeyExists($pdo, 'bolumler', 'fk_bolumler_departman')
            || !self::foreignKeyExists($pdo, 'birimler', 'fk_birimler_bolum')
            || !self::foreignKeyExists($pdo, 'personeller', 'fk_personeller_bolum')
            || !self::foreignKeyExists($pdo, 'personeller', 'fk_personeller_birim')
            || !self::foreignKeyExists($pdo, 'personeller', 'fk_personeller_pozisyon')
            || !self::foreignKeyExists($pdo, 'subeler', 'fk_subeler_sgk_isveren')
        ) {
            return false;
        }

        if (!self::indexExists($pdo, 'bolumler', 'uq_bolumler_departman_ad')
            || !self::indexExists($pdo, 'birimler', 'uq_birimler_bolum_ad')
            || !self::indexExists($pdo, 'pozisyonlar', 'uq_pozisyonlar_ad')
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

    private static function foreignKeyExists(PDO $pdo, string $table, string $constraintName): bool
    {
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

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function indexExists(PDO $pdo, string $table, string $indexName): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i'
            );
            $stmt->execute(['t' => $table, 'i' => $indexName]);

            return (int) $stmt->fetchColumn() >= 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
