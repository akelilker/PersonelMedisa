<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;
use Throwable;

/**
 * Read-only production preflight for the canonical migration control plane.
 *
 * The migration worker is the only owner that holds production database
 * credentials, so it is also the only place a production ledger/schema/data
 * preflight can be produced. This report is what the FTP-only diagnostics
 * workflow reads back afterwards.
 *
 * Contract: every statement here is a SELECT. No INSERT, UPDATE, DELETE, DDL,
 * migration apply or ledger write happens on this path.
 *
 * Output contract: aggregate numbers, column/index names, migration versions and
 * checksums only. No personel, bordro or closing row content, no names, no
 * TCKN, no wages, no tokens and no credentials ever enter the report, because it
 * is published into the control directory and echoed into CI logs.
 */
final class MigrationPreflightReport
{
    public const SCHEMA_VERSION = '1';

    /** Expected production ledger tip before migration 079 is applied. */
    public const EXPECTED_APPLIED_TIP = '078';

    /** The single migration this preflight authorizes. */
    public const EXPECTED_PENDING_VERSION = '079';

    private const TARGET_TABLES = ['aylik_kapanis_state', 'aylik_ozet_satirlari'];

    private const ACTOR_COLUMNS = [
        'bolum_onay_actor_user_id',
        'bolum_onay_actor_identity_id',
        'bolum_onay_at',
        'kapanis_actor_user_id',
        'kapanis_actor_identity_id',
        'kapanis_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function collect(
        PDO $pdo,
        MigrationSourceProvider $source,
        string $deployedSha
    ): array {
        $blockers = [];
        $warnings = [];

        $bundle = self::bundleFacts($source, $blockers);
        $ledger = self::ledgerFacts($pdo, $bundle['migrations'], $blockers);
        $schema = self::schemaFacts($pdo, $blockers);
        $guards = self::guardFacts($pdo, $schema, $blockers, $warnings);

        self::assertChainShape($bundle, $ledger, $blockers);
        self::assertPreimage($schema, $blockers, $warnings);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => 'READ_ONLY_MIGRATION_PREFLIGHT',
            'deployed_sha' => strtolower($deployedSha),
            'bundle' => [
                'migration_count' => count($bundle['migrations']),
                'code_tip' => $bundle['code_tip'],
                'expected_pending_checksum' => $bundle['expected_pending_checksum'],
            ],
            'ledger' => [
                'ready' => $ledger['ready'],
                'applied_count' => $ledger['applied_count'],
                'applied_tip' => $ledger['applied_tip'],
                'checksum_mismatch_versions' => $ledger['checksum_mismatch_versions'],
                'gap_versions' => $ledger['gap_versions'],
                'pending_versions' => $ledger['pending_versions'],
                'pending_names' => $ledger['pending_names'],
            ],
            'schema' => $schema,
            'guards' => $guards,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => array_values(array_unique($warnings)),
            'result' => $blockers === [] ? 'PASS' : 'BLOCKED',
        ];
    }

    /**
     * @param list<string> $blockers
     * @return array{migrations: list<array<string, mixed>>, code_tip: string, expected_pending_checksum: string}
     */
    private static function bundleFacts(MigrationSourceProvider $source, array &$blockers): array
    {
        $migrations = $source->all();
        if ($migrations === []) {
            $blockers[] = 'MIGRATION_SOURCE_MISSING';

            return ['migrations' => [], 'code_tip' => 'NONE', 'expected_pending_checksum' => 'NONE'];
        }

        $expectedChecksum = 'NONE';
        foreach ($migrations as $migration) {
            if ($migration['version'] === self::EXPECTED_PENDING_VERSION) {
                $expectedChecksum = $migration['checksum'];
            }
        }

        return [
            'migrations' => $migrations,
            'code_tip' => (string) $migrations[count($migrations) - 1]['version'],
            'expected_pending_checksum' => $expectedChecksum,
        ];
    }

    /**
     * @param list<array<string, mixed>> $migrations
     * @param list<string> $blockers
     * @return array<string, mixed>
     */
    private static function ledgerFacts(PDO $pdo, array $migrations, array &$blockers): array
    {
        $facts = [
            'ready' => false,
            'applied_count' => 0,
            'applied_tip' => 'NONE',
            'checksum_mismatch_versions' => [],
            'gap_versions' => [],
            'pending_versions' => [],
            'pending_names' => [],
        ];

        if (!self::tableExists($pdo, 'medisa_schema_migrations')) {
            $blockers[] = 'MIGRATION_LEDGER_MISSING';

            return $facts;
        }
        $facts['ready'] = true;

        $ledger = [];
        $rows = $pdo->query(
            'SELECT version, checksum FROM medisa_schema_migrations ORDER BY version'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $ledger[(string) $row['version']] = (string) $row['checksum'];
        }
        $facts['applied_count'] = count($ledger);

        $appliedTip = 'NONE';
        $pendingSeen = false;
        foreach ($migrations as $migration) {
            $version = (string) $migration['version'];
            if (!isset($ledger[$version])) {
                $pendingSeen = true;
                $facts['pending_versions'][] = $version;
                $facts['pending_names'][] = (string) $migration['name'];
                continue;
            }
            if ($pendingSeen) {
                // An applied version behind a pending one means the chain was not
                // applied in order; the runner refuses this and so does the gate.
                $facts['gap_versions'][] = $version;
            }
            if (!hash_equals($ledger[$version], (string) $migration['checksum'])) {
                $facts['checksum_mismatch_versions'][] = $version;
            }
            $appliedTip = $version;
        }
        $facts['applied_tip'] = $appliedTip;

        if ($facts['checksum_mismatch_versions'] !== []) {
            // Fail-closed lock on "a migration already applied to production was
            // edited afterwards" — the one thing rewriting 079 must never become.
            $blockers[] = 'MIGRATION_CHECKSUM_MISMATCH';
        }
        if ($facts['gap_versions'] !== []) {
            $blockers[] = 'MIGRATION_LEDGER_GAP';
        }

        return $facts;
    }

    /**
     * @param list<string> $blockers
     * @return array<string, mixed>
     */
    private static function schemaFacts(PDO $pdo, array &$blockers): array
    {
        $schema = [];
        foreach (self::TARGET_TABLES as $table) {
            if (!self::tableExists($pdo, $table)) {
                $blockers[] = 'TARGET_TABLE_MISSING';
                $schema[$table] = ['exists' => false, 'columns' => [], 'indexes' => []];
                continue;
            }
            $schema[$table] = [
                'exists' => true,
                'columns' => self::columnFacts($pdo, $table),
                'indexes' => self::indexFacts($pdo, $table),
            ];
        }

        return $schema;
    }

    /**
     * Column names, nullability and key flag only — never a stored value.
     *
     * @return array<string, array{nullable: bool, type: string}>
     */
    private static function columnFacts(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([':table' => $table]);

        $columns = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[(string) $row['COLUMN_NAME']] = [
                'nullable' => (string) $row['IS_NULLABLE'] === 'YES',
                'type' => (string) $row['DATA_TYPE'],
            ];
        }

        return $columns;
    }

    /**
     * @return array<string, array{unique: bool, columns: list<string>}>
     */
    private static function indexFacts(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
             ORDER BY INDEX_NAME, SEQ_IN_INDEX'
        );
        $statement->execute([':table' => $table]);

        $indexes = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['INDEX_NAME'];
            if (!isset($indexes[$name])) {
                $indexes[$name] = ['unique' => (int) $row['NON_UNIQUE'] === 0, 'columns' => []];
            }
            $indexes[$name]['columns'][] = (string) $row['COLUMN_NAME'];
        }

        return $indexes;
    }

    /**
     * Aggregate-only data guards. Every value is a count, so no row can be
     * reconstructed from the report.
     *
     * @param array<string, mixed> $schema
     * @param list<string> $blockers
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private static function guardFacts(
        PDO $pdo,
        array $schema,
        array &$blockers,
        array &$warnings
    ): array {
        $guards = [
            'branch_table_resolved' => false,
            'ozet_rows' => 0,
            'ozet_sube_null_rows' => 0,
            'ozet_sube_orphan_rows' => 0,
            'ozet_distinct_ay' => 0,
            'ozet_distinct_ay_sube_pairs' => 0,
            'ozet_duplicate_ay_sube_personel' => 0,
            'ozet_actor_columns_present' => 0,
            'state_rows' => 0,
            'state_distinct_ay' => 0,
            'state_duplicate_ay' => 0,
            'state_sube_column_present' => false,
            'state_legacy_unique_present' => false,
            'state_composite_unique_present' => false,
            'state_rows_expected_after_079' => 0,
        ];

        $ozetPresent = ($schema['aylik_ozet_satirlari']['exists'] ?? false) === true;
        $statePresent = ($schema['aylik_kapanis_state']['exists'] ?? false) === true;

        if ($ozetPresent) {
            $guards['ozet_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM aylik_ozet_satirlari');
            $guards['ozet_sube_null_rows'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM aylik_ozet_satirlari WHERE sube_id IS NULL'
            );
            $guards['ozet_distinct_ay'] = self::count(
                $pdo,
                'SELECT COUNT(DISTINCT ay) FROM aylik_ozet_satirlari'
            );
            $guards['ozet_distinct_ay_sube_pairs'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM (SELECT ay, sube_id FROM aylik_ozet_satirlari
                 GROUP BY ay, sube_id) AS scoped'
            );
            $guards['ozet_duplicate_ay_sube_personel'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM (SELECT ay, sube_id, personel_id
                 FROM aylik_ozet_satirlari GROUP BY ay, sube_id, personel_id
                 HAVING COUNT(*) > 1) AS duplicates'
            );

            $columns = $schema['aylik_ozet_satirlari']['columns'];
            $present = 0;
            foreach (self::ACTOR_COLUMNS as $column) {
                if (isset($columns[$column])) {
                    $present++;
                }
            }
            $guards['ozet_actor_columns_present'] = $present;

            if (self::tableExists($pdo, 'subeler')) {
                $guards['branch_table_resolved'] = true;
                $guards['ozet_sube_orphan_rows'] = self::count(
                    $pdo,
                    'SELECT COUNT(*) FROM aylik_ozet_satirlari o
                     WHERE o.sube_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM subeler s WHERE s.id = o.sube_id)'
                );
            } else {
                // No silent pass: an unresolvable branch owner means the orphan
                // guard was never actually evaluated.
                $blockers[] = 'BRANCH_TABLE_UNRESOLVED';
            }

            if ($guards['ozet_sube_null_rows'] > 0) {
                $warnings[] = 'OZET_SUBE_NULL_ROWS_PRESENT';
            }
            if ($guards['ozet_sube_orphan_rows'] > 0) {
                $warnings[] = 'OZET_SUBE_ORPHAN_ROWS_PRESENT';
            }
        }

        if ($statePresent) {
            $stateColumns = $schema['aylik_kapanis_state']['columns'];
            $stateIndexes = $schema['aylik_kapanis_state']['indexes'];
            $guards['state_sube_column_present'] = isset($stateColumns['sube_id']);
            $guards['state_legacy_unique_present'] = isset($stateIndexes['uq_aylik_kapanis_state_ay']);
            $guards['state_composite_unique_present']
                = isset($stateIndexes['uq_aylik_kapanis_state_ay_sube']);

            $guards['state_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM aylik_kapanis_state');
            $guards['state_distinct_ay'] = self::count(
                $pdo,
                'SELECT COUNT(DISTINCT ay) FROM aylik_kapanis_state'
            );
            $guards['state_duplicate_ay'] = self::count(
                $pdo,
                'SELECT COUNT(*) FROM (SELECT ay FROM aylik_kapanis_state
                 GROUP BY ay HAVING COUNT(*) > 1) AS duplicates'
            );

            // 079 is schema-only: it adds no state row and removes none, so the
            // legacy rows survive verbatim under the sentinel sube_id = 0.
            $guards['state_rows_expected_after_079'] = $guards['state_rows'];

            if ($guards['state_duplicate_ay'] > 0 && !$guards['state_sube_column_present']) {
                // Duplicate months would collide on (ay, 0) when the composite
                // UNIQUE key is created.
                $blockers[] = 'STATE_DUPLICATE_AY_BLOCKS_COMPOSITE_KEY';
            }
        }

        return $guards;
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $ledger
     * @param list<string> $blockers
     */
    private static function assertChainShape(array $bundle, array $ledger, array &$blockers): void
    {
        if ($bundle['code_tip'] !== self::EXPECTED_PENDING_VERSION) {
            $blockers[] = 'CODE_TIP_UNEXPECTED';
        }
        if ($ledger['ready'] && $ledger['applied_tip'] !== self::EXPECTED_APPLIED_TIP) {
            $blockers[] = 'APPLIED_TIP_NOT_078';
        }
        if ($ledger['pending_versions'] !== [self::EXPECTED_PENDING_VERSION]) {
            $blockers[] = 'PENDING_NOT_ONLY_079';
        }
        if ($bundle['expected_pending_checksum'] === 'NONE') {
            $blockers[] = 'PENDING_CHECKSUM_UNRESOLVED';
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    private static function assertPreimage(array $schema, array &$blockers, array &$warnings): void
    {
        $stateIndexes = $schema['aylik_kapanis_state']['indexes'] ?? [];
        $ozetColumns = $schema['aylik_ozet_satirlari']['columns'] ?? [];

        $actorPresent = 0;
        foreach (self::ACTOR_COLUMNS as $column) {
            if (isset($ozetColumns[$column])) {
                $actorPresent++;
            }
        }
        if ($actorPresent !== 0) {
            // Either 079 already ran or someone changed the schema by hand; both
            // invalidate the preimage this gate was written against.
            $blockers[] = 'PREIMAGE_ACTOR_COLUMNS_ALREADY_PRESENT';
        }
        if (!isset($stateIndexes['uq_aylik_kapanis_state_ay'])) {
            $blockers[] = 'PREIMAGE_LEGACY_UNIQUE_MISSING';
        }
        if (isset($stateIndexes['uq_aylik_kapanis_state_ay_sube'])) {
            $warnings[] = 'PREIMAGE_COMPOSITE_UNIQUE_ALREADY_PRESENT';
        }
    }

    private static function count(PDO $pdo, string $sql): int
    {
        try {
            return (int) $pdo->query($sql)->fetchColumn();
        } catch (Throwable $exception) {
            // A guard that cannot be evaluated must not read as zero.
            return -1;
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute([':table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }
}
