<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use PDO;

/**
 * Single owner for "is the sirket -> sgk/sube -> lokasyon hierarchy usable?".
 *
 * Schema presence and data mapping are deliberately two different questions:
 * migration 079 is additive and maps nothing, so a database can be fully
 * migrated (schema_ready) while every branch still has a NULL sirket_id
 * (data_ready = false). Callers that conflate the two either crash on a legacy
 * database or invent company cards that do not exist in production.
 *
 * Controllers must not grow their own SHOW TABLES probes; ask this class.
 * Results are cached for the request because a readiness report is consulted by
 * several endpoints per page load and the schema cannot change mid-request.
 */
final class OrganizasyonSchema
{
    /** @var array<string, mixed>|null */
    private static $report = null;

    /** @var bool|null */
    private static $sgkRelationReady = null;

    public static function isSchemaReady(PDO $pdo): bool
    {
        $report = self::report($pdo);

        return (bool) $report['schema_ready'];
    }

    public static function isDataReady(PDO $pdo): bool
    {
        $report = self::report($pdo);

        return (bool) $report['data_ready'];
    }

    /**
     * Read-only readiness report. Contains structure flags, aggregate counts and
     * blocker codes only — never a personnel row, a name or a secret.
     *
     * @return array<string, mixed>
     */
    public static function report(PDO $pdo): array
    {
        if (self::$report !== null) {
            return self::$report;
        }

        $structures = [
            'sirketler' => self::tableExists($pdo, 'sirketler'),
            'user_sirketler' => self::tableExists($pdo, 'user_sirketler'),
            'user_sgk_isverenler' => self::tableExists($pdo, 'user_sgk_isverenler'),
            'subeler_sirket_id' => self::columnExists($pdo, 'subeler', 'sirket_id'),
            'sgk_isverenler_sirket_id' => self::columnExists($pdo, 'sgk_isverenler', 'sirket_id'),
            'calisma_lokasyonlari_sube_id' => self::columnExists($pdo, 'calisma_lokasyonlari', 'sube_id'),
            'hierarchy_foreign_keys' => self::foreignKeysPresent($pdo),
        ];

        $blockers = [];
        foreach ([
            'sirketler' => 'SIRKETLER_TABLE_MISSING',
            'user_sirketler' => 'USER_SIRKETLER_TABLE_MISSING',
            'user_sgk_isverenler' => 'USER_SGK_ISVERENLER_TABLE_MISSING',
            'subeler_sirket_id' => 'SUBELER_SIRKET_ID_MISSING',
            'sgk_isverenler_sirket_id' => 'SGK_ISVERENLER_SIRKET_ID_MISSING',
            'calisma_lokasyonlari_sube_id' => 'CALISMA_LOKASYONLARI_SUBE_ID_MISSING',
        ] as $key => $code) {
            if (!$structures[$key]) {
                $blockers[] = $code;
            }
        }

        $schemaReady = count($blockers) === 0;
        if ($schemaReady && $structures['hierarchy_foreign_keys'] < self::EXPECTED_FOREIGN_KEYS) {
            $blockers[] = 'HIERARCHY_FOREIGN_KEY_MISSING';
            $schemaReady = false;
        }

        $counts = [
            'sirket_count' => 0,
            'sube_count' => self::count($pdo, 'SELECT COUNT(*) FROM subeler'),
            'unmapped_sube_count' => 0,
            'unmapped_sgk_isveren_count' => 0,
            'orphan_sube_sirket_count' => 0,
            'orphan_lokasyon_sube_count' => 0,
            'sube_sgk_sirket_mismatch_count' => 0,
        ];

        if ($schemaReady) {
            $counts['sirket_count'] = self::count($pdo, 'SELECT COUNT(*) FROM sirketler');
            $counts['unmapped_sube_count'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM subeler WHERE sirket_id IS NULL'
            );
            $counts['unmapped_sgk_isveren_count'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM sgk_isverenler WHERE sirket_id IS NULL'
            );
            $counts['orphan_sube_sirket_count'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM subeler s
                 WHERE s.sirket_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM sirketler c WHERE c.id = s.sirket_id)'
            );
            $counts['orphan_lokasyon_sube_count'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM calisma_lokasyonlari l
                 WHERE l.sube_id IS NOT NULL
                   AND NOT EXISTS (SELECT 1 FROM subeler s WHERE s.id = l.sube_id)'
            );
            // A branch and its payroll employer must belong to the same company.
            $counts['sube_sgk_sirket_mismatch_count'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM subeler s
                 INNER JOIN sgk_isverenler e ON e.id = s.sgk_isveren_id
                 WHERE s.sirket_id IS NOT NULL
                   AND e.sirket_id IS NOT NULL
                   AND e.sirket_id <> s.sirket_id'
            );

            if ($counts['sirket_count'] === 0) {
                $blockers[] = 'SIRKET_KAYDI_YOK';
            }
            if ($counts['unmapped_sube_count'] > 0) {
                $blockers[] = 'SUBE_SIRKET_UNMAPPED';
            }
            if ($counts['unmapped_sgk_isveren_count'] > 0) {
                $blockers[] = 'SGK_ISVEREN_SIRKET_UNMAPPED';
            }
            if ($counts['orphan_sube_sirket_count'] > 0) {
                $blockers[] = 'SUBE_SIRKET_ORPHAN';
            }
            if ($counts['orphan_lokasyon_sube_count'] > 0) {
                $blockers[] = 'LOKASYON_SUBE_ORPHAN';
            }
            if ($counts['sube_sgk_sirket_mismatch_count'] > 0) {
                $blockers[] = 'SUBE_SGK_SIRKET_MISMATCH';
            }
        }

        // A work location without a branch is an expected steady state, not a
        // blocker: it must never stop company/branch management.
        self::$report = [
            'schema_ready' => $schemaReady,
            'data_ready' => $schemaReady && count($blockers) === 0,
            'structures' => $structures,
            'counts' => $counts,
            'blockers' => array_values(array_unique($blockers)),
        ];

        return self::$report;
    }

    /**
     * Whether the payroll-employer relation on subeler is usable.
     *
     * This predates migration 079 and is tracked separately: a database old
     * enough to lack it (or a focused test schema) must still be able to read
     * branches instead of failing on an unknown table.
     */
    public static function isSgkRelationReady(PDO $pdo): bool
    {
        if (self::$sgkRelationReady === null) {
            self::$sgkRelationReady = self::tableExists($pdo, 'sgk_isverenler')
                && self::columnExists($pdo, 'subeler', 'sgk_isveren_id');
        }

        return self::$sgkRelationReady;
    }

    /**
     * Relation table probe for callers that must decide between "this relation
     * exists and may hold dependents" and "this database predates it".
     *
     * Controllers and services must not grow their own information_schema probes;
     * ask this class.
     */
    public static function hasTable(PDO $pdo, string $table): bool
    {
        return self::tableExists($pdo, $table);
    }

    /** Column probe with the same contract as hasTable(). */
    public static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        return self::columnExists($pdo, $table, $column);
    }

    /** Test helper — clear the request-scope cache. */
    public static function resetCache(): void
    {
        self::$report = null;
        self::$sgkRelationReady = null;
    }

    private const EXPECTED_FOREIGN_KEYS = 7;

    private const FOREIGN_KEY_NAMES = [
        'fk_subeler_sirket',
        'fk_sgk_isverenler_sirket',
        'fk_calisma_lokasyonlari_sube',
        'fk_user_sirketler_user',
        'fk_user_sirketler_sirket',
        'fk_user_sgk_isverenler_user',
        'fk_user_sgk_isverenler_sgk',
    ];

    private static function foreignKeysPresent(PDO $pdo): int
    {
        $placeholders = implode(', ', array_fill(0, count(self::FOREIGN_KEY_NAMES), '?'));

        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND CONSTRAINT_TYPE = 'FOREIGN KEY'
                   AND CONSTRAINT_NAME IN ($placeholders)"
            );
            $stmt->execute(self::FOREIGN_KEY_NAMES);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
            );
            $stmt->execute(['table' => $table]);
            $found = $stmt->fetchColumn();
            $stmt->closeCursor();

            return $found !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            // information_schema rather than SHOW COLUMNS: the latter cannot take a
            // server-side bound parameter, which production runs with.
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column
                 LIMIT 1'
            );
            $stmt->execute(['table' => $table, 'column' => $column]);
            $found = $stmt->fetchColumn();
            $stmt->closeCursor();

            return $found !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function count(PDO $pdo, string $sql): int
    {
        try {
            $stmt = $pdo->query($sql);

            return $stmt === false ? 0 : (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
