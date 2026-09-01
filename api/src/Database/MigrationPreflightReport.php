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

    /** Expected production ledger tip before this round's first migration. */
    public const EXPECTED_APPLIED_TIP = '081';

    /**
     * The migrations this round authorizes, in apply order. Each one is a
     * separate canonical request with its own backup, so the gate must accept the
     * chain both before the round starts and between two applies. The version
     * number alone is never enough: slot 079 previously held a monthly-closing
     * migration that was withdrawn as business-model wrong, so names are pinned
     * next to versions here.
     *
     * This round is the single access-change audit owner. The completed 080/081
     * round is not re-declared here: its migrations stay applied, their ledger
     * rows and checksums are untouched, and this gate only reasons about what
     * comes after them.
     *
     * @var array<string, string>
     */
    public const ROUND_MIGRATIONS = [
        '082' => '082_user_erisim_degisiklik_auditleri.sql',
    ];

    /** Withdrawn 079. Must not appear anywhere in the canonical source. */
    public const WITHDRAWN_MIGRATION_NAME = '079_aylik_kapanis_sube_scope_and_actor.sql';

    private const TARGET_TABLES = ['users', 'subeler', 'sgk_isverenler', 'calisma_lokasyonlari'];

    /** Owners 079 added. This round extends them and must find them applied. */
    private const RELATION_COLUMNS = [
        'subeler' => 'sirket_id',
        'sgk_isverenler' => 'sirket_id',
        'calisma_lokasyonlari' => 'sube_id',
    ];

    private const HIERARCHY_TABLES = ['sirketler', 'user_sirketler', 'user_sgk_isverenler'];

    /** Append-only audit tables this round creates. Absent is the preimage. */
    private const ROUND_AUDIT_TABLES = [
        'user_erisim_degisiklik_auditleri',
    ];

    /**
     * Audit owners the completed 080/081 round already delivered. This round
     * extends that family, so finding them present is how the gate proves it is
     * pointed at a database that actually received the previous round rather
     * than at one that merely reports tip 081.
     */
    private const PREDECESSOR_AUDIT_TABLES = [
        'personel_sube_degisiklik_auditleri',
        'sube_olusturma_auditleri',
        'user_org_scope_auditleri',
        'user_erisim_kaldirma_auditleri',
    ];

    /** Role 081 added to the users.rol enum. Present is the expected preimage. */
    private const PREDECESSOR_ROLE = 'IK_PERSONELI';

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
        // The checksum the gate authorizes is the checksum of the next migration
        // in the round, which is only knowable once the ledger has been read.
        $bundle['expected_pending_checksum'] = self::nextPendingChecksum($bundle, $ledger);
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
                'round_versions' => array_keys(self::ROUND_MIGRATIONS),
                'next_pending_version' => $ledger['pending_versions'][0] ?? 'NONE',
                'next_pending_name' => $ledger['pending_names'][0] ?? 'NONE',
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

        $withdrawnPresent = false;
        foreach ($migrations as $migration) {
            if ((string) $migration['name'] === self::WITHDRAWN_MIGRATION_NAME) {
                $withdrawnPresent = true;
            }
        }

        return [
            'migrations' => $migrations,
            'code_tip' => (string) $migrations[count($migrations) - 1]['version'],
            'expected_pending_checksum' => 'NONE',
            'withdrawn_present' => $withdrawnPresent,
        ];
    }

    /**
     * Checksum of the next migration the round would apply, resolved only when
     * that migration is the round member its version pins it to.
     *
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $ledger
     */
    private static function nextPendingChecksum(array $bundle, array $ledger): string
    {
        $version = $ledger['pending_versions'][0] ?? null;
        $name = $ledger['pending_names'][0] ?? null;
        if ($version === null || (self::ROUND_MIGRATIONS[$version] ?? null) !== $name) {
            return 'NONE';
        }

        foreach ($bundle['migrations'] as $migration) {
            if ((string) $migration['version'] === $version
                && (string) $migration['name'] === $name
            ) {
                return (string) $migration['checksum'];
            }
        }

        return 'NONE';
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

        // Observed, not required: the audit tables this round creates and the
        // monthly-closing table the withdrawn 079 would have altered. Their
        // absence is the expected preimage, so it must not raise a blocker here.
        foreach (array_merge(
            self::ROUND_AUDIT_TABLES,
            self::PREDECESSOR_AUDIT_TABLES,
            self::HIERARCHY_TABLES,
            ['aylik_kapanis_state']
        ) as $table) {
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
            'user_sirket_assignment_rows' => 0,
            'user_rows' => 0,
            'sirketler_table_present' => self::tableExists($pdo, 'sirketler'),
            'user_sirketler_table_present' => self::tableExists($pdo, 'user_sirketler'),
            'user_sgk_isverenler_table_present' => self::tableExists($pdo, 'user_sgk_isverenler'),
            'relation_columns_present' => 0,
            'sirket_rows' => 0,
            'round_audit_tables_present' => 0,
            'predecessor_audit_tables_present' => 0,
            'predecessor_role_present' => false,
            'sube_rows_expected_after_round' => 0,
            'user_sube_assignment_rows_expected_after_round' => 0,
            'user_rows_expected_after_round' => 0,
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
        $guards['user_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM users');
        if ($guards['user_sirketler_table_present']) {
            $guards['user_sirket_assignment_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM user_sirketler');
        }

        $auditPresent = 0;
        foreach (self::ROUND_AUDIT_TABLES as $table) {
            if (($schema[$table]['exists'] ?? false) === true) {
                $auditPresent++;
            }
        }
        $guards['round_audit_tables_present'] = $auditPresent;

        $predecessorPresent = 0;
        foreach (self::PREDECESSOR_AUDIT_TABLES as $table) {
            if (($schema[$table]['exists'] ?? false) === true) {
                $predecessorPresent++;
            }
        }
        $guards['predecessor_audit_tables_present'] = $predecessorPresent;

        // 081 widened the users.rol enum. This round does not touch roles, so the
        // role it added must already be there: its absence means the ledger tip
        // and the actual schema disagree about whether 081 ran.
        $roleType = self::columnType($pdo, 'users', 'rol');
        $guards['predecessor_role_present'] = $roleType !== null
            && str_contains($roleType, "'" . self::PREDECESSOR_ROLE . "'");
        if (!$guards['predecessor_role_present']) {
            $blockers[] = 'PREIMAGE_PREDECESSOR_ROLE_MISSING';
        }

        $present = 0;
        foreach (self::RELATION_COLUMNS as $table => $column) {
            if (isset($schema[$table]['columns'][$column])) {
                $present++;
            }
        }
        $guards['relation_columns_present'] = $present;

        if ($guards['sirketler_table_present']) {
            $guards['sirket_rows'] = self::count($pdo, 'SELECT COUNT(*) FROM sirketler');
        }

        // This round is additive and writes no data: every branch, user and user
        // assignment must survive the apply untouched.
        $guards['sube_rows_expected_after_round'] = $guards['sube_rows'];
        $guards['user_sube_assignment_rows_expected_after_round'] = $guards['user_sube_assignment_rows'];
        $guards['user_rows_expected_after_round'] = $guards['user_rows'];

        return $guards;
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $ledger
     * @param list<string> $blockers
     */
    private static function assertChainShape(array $bundle, array $ledger, array &$blockers): void
    {
        $roundVersions = array_keys(self::ROUND_MIGRATIONS);
        $roundTip = (string) $roundVersions[count($roundVersions) - 1];

        if ($bundle['code_tip'] !== $roundTip) {
            $blockers[] = 'CODE_TIP_UNEXPECTED';
        }

        // The pending set must be exactly what is left of the round: the whole
        // round before it starts, or its tail between two applies. Anything else
        // is either an unknown migration or a chain this gate cannot reason about.
        $pendingVersions = $ledger['pending_versions'];
        $expectedSuffixes = [];
        for ($index = 0; $index < count($roundVersions); $index++) {
            $expectedSuffixes[] = array_values(array_slice($roundVersions, $index));
        }
        if ($pendingVersions === []) {
            // Nothing left to authorize; a further request would re-apply. The
            // healthy end state is tip 082 exactly; an empty pending set on any
            // other tip means the chain is not the one this gate authorizes.
            $blockers[] = $ledger['ready'] && $ledger['applied_tip'] === $roundTip
                ? 'ROUND_ALREADY_COMPLETE'
                : 'APPLIED_TIP_UNEXPECTED';
        } elseif (!in_array($pendingVersions, $expectedSuffixes, true)) {
            $blockers[] = 'PENDING_NOT_ROUND_SUFFIX';
        } else {
            $expectedNames = [];
            foreach ($pendingVersions as $version) {
                $expectedNames[] = self::ROUND_MIGRATIONS[$version];
            }
            if ($ledger['pending_names'] !== $expectedNames) {
                $blockers[] = 'PENDING_NAME_UNEXPECTED';
            }

            // The applied tip must be exactly the version before the next pending
            // one, so a partially applied round is provable rather than assumed.
            $firstPending = (string) $pendingVersions[0];
            $expectedTip = $firstPending === (string) $roundVersions[0]
                ? self::EXPECTED_APPLIED_TIP
                : (string) $roundVersions[array_search($firstPending, $roundVersions, true) - 1];
            if ($ledger['ready'] && $ledger['applied_tip'] !== $expectedTip) {
                $blockers[] = 'APPLIED_TIP_UNEXPECTED';
            }
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
        // This round builds on the hierarchy 079 already delivered, so those owners
        // must be present and in their applied shape. Their absence means the gate
        // is pointed at a database that never received 079.
        foreach (self::RELATION_COLUMNS as $table => $column) {
            if (($schema[$table]['exists'] ?? false) !== true) {
                $blockers[] = 'PREIMAGE_OWNER_TABLE_MISSING';
                continue;
            }
            if (!isset($schema[$table]['columns'][$column])) {
                $blockers[] = 'PREIMAGE_HIERARCHY_COLUMN_MISSING';
                continue;
            }

            $facts = $schema[$table]['columns'][$column];
            if ($facts['nullable'] !== true || $facts['type'] !== 'int') {
                $blockers[] = 'PREIMAGE_RELATION_COLUMN_INCOMPATIBLE';
            }
        }

        foreach (self::HIERARCHY_TABLES as $table) {
            if (($schema[$table]['exists'] ?? false) !== true) {
                $blockers[] = 'PREIMAGE_HIERARCHY_TABLE_MISSING';
            }
        }

        // The 080/081 audit owners must already be here. This round is their
        // sibling, so their absence means the database never received the round
        // the ledger claims is applied.
        foreach (self::PREDECESSOR_AUDIT_TABLES as $table) {
            if (($schema[$table]['exists'] ?? false) !== true) {
                $blockers[] = 'PREIMAGE_PREDECESSOR_AUDIT_TABLE_MISSING';
            }
        }

        // Audit tables are what this round creates: absent is the clean preimage,
        // present is a resumable partial round, never a silent pass.
        foreach (self::ROUND_AUDIT_TABLES as $table) {
            if (($schema[$table]['exists'] ?? false) === true) {
                $warnings[] = 'PREIMAGE_PARTIAL_ROUND_AUDIT_TABLE_PRESENT';
            }
        }

        // Withdrawn 079 touched the monthly-closing tables. Its structures must
        // not be present, because that would mean the withdrawn migration ran.
        $stateColumns = $schema['aylik_kapanis_state']['columns'] ?? [];
        if (isset($stateColumns['sube_id'])) {
            $blockers[] = 'WITHDRAWN_079_STRUCTURE_PRESENT';
        }
    }

    /**
     * COLUMN_TYPE for one column — the enum definition, never a stored value.
     */
    private static function columnType(PDO $pdo, string $table, string $column): ?string
    {
        $statement = $pdo->prepare(
            'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute([':table' => $table, ':column' => $column]);
        $type = $statement->fetchColumn();

        return is_string($type) ? $type : null;
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
