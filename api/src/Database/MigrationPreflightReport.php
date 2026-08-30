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

    /**
     * The migration file this gate was written against. The version number alone
     * is not enough: slot 079 previously held a monthly-closing migration that
     * was withdrawn as business-model wrong, and it must never reach production
     * through this gate.
     */
    public const EXPECTED_PENDING_NAME = '079_sirket_sube_hiyerarsisi.sql';

    /** Withdrawn 079. Must not appear anywhere in the canonical source. */
    public const WITHDRAWN_MIGRATION_NAME = '079_aylik_kapanis_sube_scope_and_actor.sql';

    private const TARGET_TABLES = ['users', 'subeler', 'sgk_isverenler', 'calisma_lokasyonlari'];

    /** table => column added by the new 079, all nullable INT UNSIGNED. */
    private const RELATION_COLUMNS = [
        'subeler' => 'sirket_id',
        'sgk_isverenler' => 'sirket_id',
        'calisma_lokasyonlari' => 'sube_id',
    ];

    private const NEW_TABLES = ['sirketler', 'user_sirketler', 'user_sgk_isverenler'];

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
                // Published so the apply gate can prove the withdrawn 079 is gone
                // rather than inferring it from the absence of a blocker.
                'withdrawn_present' => $bundle['withdrawn_present'],
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

            return [
                'migrations' => [],
                'code_tip' => 'NONE',
                'expected_pending_checksum' => 'NONE',
                'withdrawn_present' => false,
            ];
        }

        $expectedChecksum = 'NONE';
        $withdrawnPresent = false;
        foreach ($migrations as $migration) {
            if ((string) $migration['name'] === self::WITHDRAWN_MIGRATION_NAME) {
                $withdrawnPresent = true;
            }
            if ($migration['version'] === self::EXPECTED_PENDING_VERSION
                && (string) $migration['name'] === self::EXPECTED_PENDING_NAME
            ) {
                $expectedChecksum = $migration['checksum'];
            }
        }

        return [
            'migrations' => $migrations,
            'code_tip' => (string) $migrations[count($migrations) - 1]['version'],
            'expected_pending_checksum' => $expectedChecksum,
            'withdrawn_present' => $withdrawnPresent,
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

        // Observed, not required: the new tables this migration creates and the
        // monthly-closing table the withdrawn 079 would have altered. Their
        // absence is the expected preimage, so it must not raise a blocker here.
        foreach (array_merge(self::NEW_TABLES, ['aylik_kapanis_state']) as $table) {
            $exists = self::tableExists($pdo, $table);
            $schema[$table] = [
                'exists' => $exists,
                'columns' => $exists ? self::columnFacts($pdo, $table) : [],
                'indexes' => $exists ? self::indexFacts($pdo, $table) : [],
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
            'branch_table_resolved' => ($schema['subeler']['exists'] ?? false) === true,
            'sube_rows' => 0,
            'sgk_isveren_rows' => 0,
            'calisma_lokasyonu_rows' => 0,
            'personel_rows' => 0,
            'user_sube_assignment_rows' => 0,
            'sirketler_table_present' => self::tableExists($pdo, 'sirketler'),
            'user_sirketler_table_present' => self::tableExists($pdo, 'user_sirketler'),
            'user_sgk_isverenler_table_present' => self::tableExists($pdo, 'user_sgk_isverenler'),
            'relation_columns_present' => 0,
            'sirket_rows' => 0,
            'sube_rows_expected_after_079' => 0,
            'user_sube_assignment_rows_expected_after_079' => 0,
        ];

        if (!$guards['branch_table_resolved']) {
            // No silent pass: an unresolvable branch owner means every guard
            // below was never actually evaluated.
            $blockers[] = 'BRANCH_TABLE_UNRESOLVED';

            return $guards;
        }

        $guards['sube_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM subeler');
        $guards['sgk_isveren_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM sgk_isverenler');
        $guards['calisma_lokasyonu_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM calisma_lokasyonlari');
        $guards['personel_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM personeller');
        $guards['user_sube_assignment_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM user_subeler');

        $present = 0;
        foreach (self::RELATION_COLUMNS as $table => $column) {
            if (isset($schema[$table]['columns'][$column])) {
                $present++;
            }
        }
        $guards['relation_columns_present'] = $present;

        if ($guards['sirketler_table_present']) {
            $guards['sirket_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM sirketler');
            if ($guards['sirket_rows'] > 0) {
                // The migration seeds nothing, so rows here mean the mapping
                // operation already started outside this gate.
                $warnings[] = 'SIRKET_ROWS_ALREADY_PRESENT';
            }
        }

        // 079 is additive and writes no data: every branch and every existing
        // user branch assignment must survive the apply untouched.
        $guards['sube_rows_expected_after_079'] = $guards['sube_rows'];
        $guards['user_sube_assignment_rows_expected_after_079'] = $guards['user_sube_assignment_rows'];

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
        if ($ledger['pending_names'] !== [] && $ledger['pending_names'] !== [self::EXPECTED_PENDING_NAME]) {
            $blockers[] = 'PENDING_NAME_UNEXPECTED';
        }
        if ($bundle['expected_pending_checksum'] === 'NONE') {
            $blockers[] = 'PENDING_CHECKSUM_UNRESOLVED';
        }
        if ($bundle['withdrawn_present']) {
            $blockers[] = 'WITHDRAWN_079_PRESENT_IN_SOURCE';
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $blockers
     * @param list<string> $warnings
     */
    private static function assertPreimage(array $schema, array &$blockers, array &$warnings): void
    {
        // The legacy owners this migration extends must be in their expected
        // preimage shape. Every one of them is a pre-079 table, so their absence
        // means the gate is pointed at the wrong database.
        foreach (self::RELATION_COLUMNS as $table => $column) {
            if (($schema[$table]['exists'] ?? false) !== true) {
                $blockers[] = 'PREIMAGE_OWNER_TABLE_MISSING';
                continue;
            }

            if (!isset($schema[$table]['columns'][$column])) {
                continue;
            }

            // Present is acceptable only as a resumable partial run: the column
            // must already be exactly what 079 would have created. A NOT NULL or
            // non-integer column is conflicting drift, not a partial state.
            $facts = $schema[$table]['columns'][$column];
            if ($facts['nullable'] !== true || $facts['type'] !== 'int') {
                $blockers[] = 'PREIMAGE_RELATION_COLUMN_INCOMPATIBLE';
                continue;
            }

            $warnings[] = 'PREIMAGE_PARTIAL_HIERARCHY_PRESENT';
        }

        // Withdrawn 079 touched the monthly-closing tables. Its structures must
        // not be present, because that would mean the withdrawn migration ran.
        $stateColumns = $schema['aylik_kapanis_state']['columns'] ?? [];
        if (isset($stateColumns['sube_id'])) {
            $blockers[] = 'WITHDRAWN_079_STRUCTURE_PRESENT';
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
