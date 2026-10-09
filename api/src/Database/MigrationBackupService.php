<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Mandatory, verifiable pre-migration backup owner.
 *
 * cPanel gives the worker no shell and no mysqldump, so the applicable safe owner
 * is a PDO dump written by the worker itself: SHOW CREATE TABLE plus fully quoted
 * INSERT statements for exactly the tables a migration can damage, plus the
 * migration ledger preimage needed to put the ledger back.
 *
 * Scope is deliberately narrow — the owners migration 079 touches (subeler,
 * sgk_isverenler, calisma_lokasyonlari and the branch assignment table) plus the
 * migration ledger preimage. A whole-database dump is not attempted: it would not
 * finish inside a cron tick and it would copy far more personal data than the
 * rollback of one migration needs.
 *
 * The tables 079 creates (sirketler, user_sirketler, user_sgk_isverenler) are not
 * backed up: they do not exist in the preimage, so the rollback for them is a
 * DROP, not a restore.
 *
 * The dump contains real organisation rows, so it may only ever be written outside the
 * webroot. When no such location can be resolved the backup fails closed and the
 * migration does not start; it never falls back to a web-reachable directory.
 *
 * Backups are never deleted by this owner. Retention until post-apply verification
 * completes is the whole point of taking them.
 */
final class MigrationBackupService
{
    public const SCHEMA_VERSION = '1';

    /** @var list<string> */
    private const BACKED_UP_TABLES = [
        'subeler',
        'sgk_isverenler',
        'calisma_lokasyonlari',
        'user_subeler',
        'medisa_schema_migrations',
    ];

    /**
     * Rollback scope of the initial organisation mapping operation.
     *
     * Wider than the migration scope on purpose: the mapping writes company rows
     * and the relation columns on three owners, so restoring it needs the company
     * table and both scope tables in the preimage too — unlike migration 079,
     * which created them and whose rollback for them is a DROP.
     *
     * @var list<string>
     */
    private const MAPPING_BACKED_UP_TABLES = [
        'sirketler',
        'subeler',
        'sgk_isverenler',
        'calisma_lokasyonlari',
        'user_subeler',
        'user_sirketler',
        'user_sgk_isverenler',
        'medisa_schema_migrations',
    ];

    /**
     * Migration 099 rollback scope. The migration mutates `users` (adds the
     * `silinmesi_korunur` column and flags the two protected accounts) and the
     * migration ledger (adds the 099 row), and it adds FK constraints to the 10
     * historically FK-less tables below. A 099 rollback restores exactly these
     * owners; the two tables 099 creates are dropped, not restored.
     *
     * @var list<string>
     */
    private const KALICI_SIL_BACKED_UP_TABLES = [
        'users',
        'ek_odeme_kesinti',
        'gunluk_bildirimler',
        'legal_holdlar',
        'legal_hold_auditleri',
        'offline_mutation_idempotency',
        'personel_gecici_gorevlendirmeler',
        'personel_import_runs',
        'personel_test_fixture_archive_kayitlari',
        'personel_test_fixture_siniflandirmalari',
        'retention_imha_auditleri',
        'medisa_schema_migrations',
    ];

    /**
     * Migration 099 only adds FK constraints (metadata) to these 10 tables; it
     * never rewrites their rows. Dumping their full row sets would copy far more
     * personal data than a rollback needs and could exceed a cron tick's memory,
     * so they are captured schema-only (SHOW CREATE TABLE). A rollback drops the
     * `fk_p099_*` constraints they gained; `users` and the ledger, whose rows 099
     * mutates, are dumped with their rows.
     *
     * @var list<string>
     */
    private const KALICI_SIL_SCHEMA_ONLY_TABLES = [
        'ek_odeme_kesinti',
        'gunluk_bildirimler',
        'legal_holdlar',
        'legal_hold_auditleri',
        'offline_mutation_idempotency',
        'personel_gecici_gorevlendirmeler',
        'personel_import_runs',
        'personel_test_fixture_archive_kayitlari',
        'personel_test_fixture_siniflandirmalari',
        'retention_imha_auditleri',
    ];

    /**
     * The exact FK constraint names migration 099 adds, keyed by table. A 099
     * rollback drops only these (metadata), so the schema-only tables keep their
     * rows. Restoring them via DROP/CREATE would destroy those rows, which is
     * why this owner never emits a table drop for them.
     *
     * @var array<string, list<string>>
     */
    private const KALICI_SIL_FK_CONSTRAINTS = [
        'ek_odeme_kesinti' => ['fk_p099_eok_created_by', 'fk_p099_eok_updated_by'],
        'gunluk_bildirimler' => ['fk_p099_gb_created_by', 'fk_p099_gb_updated_by', 'fk_p099_gb_correction_requested_by'],
        'legal_holdlar' => ['fk_p099_lh_released_by'],
        'legal_hold_auditleri' => ['fk_p099_lha_actor_user'],
        'offline_mutation_idempotency' => ['fk_p099_omi_actor_user'],
        'personel_gecici_gorevlendirmeler' => ['fk_p099_pgg_olusturan_user', 'fk_p099_pgg_sonlandiran_user'],
        'personel_import_runs' => ['fk_p099_pir_actor'],
        'personel_test_fixture_archive_kayitlari' => ['fk_p099_ptfak_archived'],
        'personel_test_fixture_siniflandirmalari' => ['fk_p099_ptfs_classified', 'fk_p099_ptfs_iptal'],
        'retention_imha_auditleri' => ['fk_p099_ria_actor'],
    ];

    private const DIRECTORY_NAME = 'medisa-migration-backups';

    /**
     * @return array<string, mixed> publishable metadata: no absolute path, no row content
     */
    public static function create(
        PDO $pdo,
        string $apiDirectory,
        string $requestId,
        string $migrationTip
    ): array {
        return self::write($pdo, $apiDirectory, 'medisa-pre', $requestId, $migrationTip, self::BACKED_UP_TABLES, []);
    }

    /**
     * Pre-mapping backup for the initial organisation mapping operation.
     *
     * Same owner, same webroot-outside rule, same readback — a parallel dump
     * system would be a second thing to audit and a second thing to get wrong.
     * The published metadata additionally pins what the dump was taken for, so a
     * restore candidate can be matched to the exact operation that needed it.
     *
     * @return array<string, mixed>
     */
    public static function createForOrganizationMapping(
        PDO $pdo,
        string $apiDirectory,
        string $operationId,
        string $migrationTip,
        string $authorizedSha,
        string $inventoryChecksum,
        string $specChecksum
    ): array {
        return self::write(
            $pdo,
            $apiDirectory,
            'medisa-pre-orgmapping',
            $operationId,
            $migrationTip,
            self::MAPPING_BACKED_UP_TABLES,
            [
                'operation' => 'ORGANIZATION_INITIAL_MAPPING',
                'operation_id' => $operationId,
                'authorized_deploy_sha' => strtolower($authorizedSha),
                'inventory_checksum' => $inventoryChecksum,
                'spec_checksum' => $specChecksum,
                'schema_objects' => self::schemaObjects($pdo, self::MAPPING_BACKED_UP_TABLES),
            ]
        );
    }

    /**
     * Pre-099 backup for the protected-account migration.
     *
     * Same owner, same webroot-outside rule, same readback. The scope is the
     * exact 099 rollback preimage: `users` and the ledger with their rows, plus
     * the 10 FK tables schema-only (their rows are untouched by 099 and too
     * large to copy inside a cron tick).
     *
     * @return array<string, mixed>
     */
    public static function createForKaliciSil(
        PDO $pdo,
        string $apiDirectory,
        string $requestId,
        string $migrationTip
    ): array {
        return self::write(
            $pdo,
            $apiDirectory,
            'medisa-pre-kalicisil',
            $requestId,
            $migrationTip,
            self::KALICI_SIL_BACKED_UP_TABLES,
            [
                'operation' => 'KALICI_SIL_MIGRATION',
                'schema_objects' => self::schemaObjects($pdo, self::KALICI_SIL_BACKED_UP_TABLES),
            ],
            self::KALICI_SIL_SCHEMA_ONLY_TABLES
        );
    }

    /**
     * @param list<string> $tables
     * @param array<string, mixed> $extraMetadata
     * @param list<string> $schemaOnlyTables
     * @return array<string, mixed>
     */
    private static function write(
        PDO $pdo,
        string $apiDirectory,
        string $namePrefix,
        string $requestId,
        string $migrationTip,
        array $tables,
        array $extraMetadata,
        array $schemaOnlyTables = []
    ): array {
        $directory = self::resolveDirectory($apiDirectory);
        $fileName = sprintf(
            '%s-%s-%s-%s.sql',
            $namePrefix,
            self::safe($migrationTip),
            self::safe($requestId),
            gmdate('Ymd-His')
        );
        $path = $directory . DIRECTORY_SEPARATOR . $fileName;

        $dump = self::renderDump($pdo, $requestId, $migrationTip, $tables, $schemaOnlyTables);
        if (@file_put_contents($path, $dump['sql'], LOCK_EX) === false) {
            throw new RuntimeException('BACKUP_WRITE_FAILED');
        }
        @chmod($path, 0600);

        $readback = self::verify($path, $dump);

        $metadata = [
            'schema_version' => self::SCHEMA_VERSION,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'request_id' => $requestId,
            'migration_tip' => $migrationTip,
            'file' => $fileName,
            'outside_webroot' => true,
            'bytes' => $readback['bytes'],
            'sha256' => $readback['sha256'],
            'tables' => $dump['tables'],
            'row_counts' => $dump['row_counts'],
            'schema_only_tables' => $dump['schema_only_tables'],
            'readback' => 'VERIFIED',
        ] + $extraMetadata;

        // The absolute location stays next to the dump instead of in the published
        // status, so CI logs never have to carry the server directory layout.
        @file_put_contents(
            $path . '.meta.json',
            json_encode(
                $metadata + ['absolute_path' => $path],
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ) . PHP_EOL,
            LOCK_EX
        );
        @chmod($path . '.meta.json', 0600);

        return $metadata;
    }

    /**
     * Webroot-outside resolution, fail-closed.
     *
     * On cPanel the deploy root lives under `public_html`, so the canonical safe
     * location is a sibling of `public_html` in the account home. When neither the
     * explicit override nor that layout resolves, there is no safe place to write
     * a dump containing closing rows and the caller must abort.
     */
    /** Private operations evidence shares the existing verified backup location. */
    public static function operationsDirectory(string $apiDirectory): string
    {
        if (self::detectWebroot($apiDirectory) === null) {
            throw new RuntimeException('BACKUP_LOCATION_UNRESOLVED');
        }
        return self::resolveDirectory($apiDirectory);
    }

    private static function resolveDirectory(string $apiDirectory): string
    {
        $webroot = self::detectWebroot($apiDirectory);

        $configured = getenv('MEDISA_MIGRATION_BACKUP_DIR');
        if (is_string($configured) && $configured !== '') {
            return self::prepareDirectory($configured, $webroot);
        }

        if ($webroot === null) {
            throw new RuntimeException('BACKUP_LOCATION_UNRESOLVED');
        }

        return self::prepareDirectory(
            dirname($webroot) . DIRECTORY_SEPARATOR . self::DIRECTORY_NAME,
            $webroot
        );
    }

    private static function prepareDirectory(string $candidate, ?string $webroot): string
    {
        if (!is_dir($candidate) && !@mkdir($candidate, 0700, true) && !is_dir($candidate)) {
            throw new RuntimeException('BACKUP_LOCATION_UNRESOLVED');
        }
        $resolved = realpath($candidate);
        if ($resolved === false) {
            throw new RuntimeException('BACKUP_LOCATION_UNRESOLVED');
        }
        if ($webroot !== null && self::isInside($resolved, $webroot)) {
            throw new RuntimeException('BACKUP_LOCATION_INSIDE_WEBROOT');
        }
        if (!is_writable($resolved)) {
            throw new RuntimeException('BACKUP_LOCATION_NOT_WRITABLE');
        }
        @chmod($resolved, 0700);

        return $resolved;
    }

    private static function detectWebroot(string $apiDirectory): ?string
    {
        $current = realpath($apiDirectory);
        if ($current === false) {
            return null;
        }
        while (true) {
            if (basename($current) === 'public_html') {
                return $current;
            }
            $parent = dirname($current);
            if ($parent === $current) {
                return null;
            }
            $current = $parent;
        }
    }

    private static function isInside(string $path, string $ancestor): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $ancestor = rtrim($ancestor, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return strncmp($path, $ancestor, strlen($ancestor)) === 0;
    }

    /**
     * @param list<string> $tables
     * @param list<string> $schemaOnlyTables
     * @return array{sql: string, tables: list<string>, row_counts: array<string, int|null>, schema_only_tables: list<string>}
     */
    private static function renderDump(
        PDO $pdo,
        string $requestId,
        string $migrationTip,
        array $tables,
        array $schemaOnlyTables = []
    ): array {
        $lines = [
            '-- Medisa pre-migration backup',
            '-- schema_version: ' . self::SCHEMA_VERSION,
            '-- request_id: ' . self::safe($requestId),
            '-- migration_tip: ' . self::safe($migrationTip),
            '-- created_at: ' . gmdate('Y-m-d\TH:i:s\Z'),
            '-- Restore drops and recreates only the tables listed below.',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS = 0;',
            '',
        ];
        $rowCounts = [];
        $schemaOnly = [];

        // Row-safe 099 FK rollback runs before any table is dropped: it removes
        // only the fk_p099_* constraints, never a table, so the schema-only
        // tables keep their rows.
        if (array_intersect($tables, $schemaOnlyTables) !== []) {
            $lines[] = '-- 099 FK rollback (metadata only: drops fk_p099_* constraints, keeps rows).';
            foreach (self::KALICI_SIL_FK_CONSTRAINTS as $constraintTable => $constraints) {
                foreach ($constraints as $constraint) {
                    $lines[] = 'ALTER TABLE `' . $constraintTable . '` DROP FOREIGN KEY `' . $constraint . '`;';
                }
            }
            $lines[] = '';
        }

        foreach ($tables as $table) {
            $createStatement = self::showCreate($pdo, $table);
            if ($createStatement === null) {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
            }
            $isSchemaOnly = in_array($table, $schemaOnlyTables, true);
            if ($isSchemaOnly) {
                $schemaOnly[] = $table;
                $rowCounts[$table] = null;
                $lines[] = '-- table: ' . $table . ' (schema-only: rows preserved on restore)';
                foreach (preg_split('/\R/', $createStatement) ?: [] as $schemaLine) {
                    if ($schemaLine !== '') {
                        $lines[] = '-- ' . $schemaLine;
                    }
                }
                $lines[] = '-- schema-only(' . $table . '): 1';
                $lines[] = '';
                continue;
            }

            $lines[] = '-- table: ' . $table;
            $lines[] = 'DROP TABLE IF EXISTS `' . $table . '`;';
            $lines[] = $createStatement . ';';

            $rows = $pdo->query('SELECT * FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $lines[] = self::renderInsert($pdo, $table, $row);
            }
            $rowCounts[$table] = count($rows);
            $lines[] = '-- rows(' . $table . '): ' . count($rows);
            $lines[] = '';
        }

        $lines[] = 'SET FOREIGN_KEY_CHECKS = 1;';
        $lines[] = '';

        return [
            'sql' => implode("\n", $lines),
            'tables' => array_values($tables),
            'row_counts' => $rowCounts,
            'schema_only_tables' => $schemaOnly,
        ];
    }

    /**
     * Index and foreign-key inventory of the backed-up tables. The dump already
     * carries the definitions inside SHOW CREATE TABLE; this is the manifest-side
     * summary a restore readback can compare against without parsing SQL.
     *
     * @param list<string> $tables
     * @return array<string, array{indexes: int, foreign_keys: int}>
     */
    private static function schemaObjects(PDO $pdo, array $tables): array
    {
        $objects = [];
        foreach ($tables as $table) {
            $objects[$table] = [
                'indexes' => self::metadataCount(
                    $pdo,
                    'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                    $table
                ),
                'foreign_keys' => self::metadataCount(
                    $pdo,
                    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
                       AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                    $table
                ),
            ];
        }

        return $objects;
    }

    private static function metadataCount(PDO $pdo, string $sql, string $table): int
    {
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute(['table' => $table]);

            return (int) $statement->fetchColumn();
        } catch (Throwable $exception) {
            return -1;
        }
    }

    private static function showCreate(PDO $pdo, string $table): ?string
    {
        try {
            $row = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            return null;
        }
        if (!is_array($row)) {
            return null;
        }
        foreach ($row as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'Create Table') === 0) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderInsert(PDO $pdo, string $table, array $row): string
    {
        $columns = [];
        $values = [];
        foreach ($row as $column => $value) {
            $columns[] = '`' . (string) $column . '`';
            if ($value === null) {
                $values[] = 'NULL';
                continue;
            }
            $values[] = $pdo->quote((string) $value);
        }

        return 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $values) . ');';
    }

    /**
     * Readback: the file on disk, not the buffer in memory, must hash to the same
     * digest and must still contain every required CREATE TABLE block plus either
     * the row-count marker or the schema-only marker for each table.
     *
     * @param array{sql: string, tables: list<string>, row_counts: array<string, int|null>, schema_only_tables: list<string>} $dump
     * @return array{bytes: int, sha256: string}
     */
    private static function verify(string $path, array $dump): array
    {
        clearstatcache(true, $path);
        $written = @file_get_contents($path);
        if ($written === false) {
            throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
        }
        if (!hash_equals(hash('sha256', $dump['sql']), hash('sha256', $written))) {
            throw new RuntimeException('BACKUP_CHECKSUM_MISMATCH');
        }
        foreach ($dump['tables'] as $table) {
            if (in_array($table, $dump['schema_only_tables'], true)) {
                if (strpos($written, '-- schema-only(' . $table . '): 1') === false) {
                    throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
                }
                // A schema-only table must never be dropped or recreated: doing so
                // would destroy its rows. This is the data-loss guard.
                if (strpos($written, 'DROP TABLE IF EXISTS `' . $table . '`') !== false) {
                    throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
                }
                continue;
            }
            if (strpos($written, 'CREATE TABLE `' . $table . '`') === false) {
                throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
            }
            if (strpos($written, '-- rows(' . $table . '): ') === false) {
                throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
            }
        }

        return [
            'bytes' => strlen($written),
            'sha256' => hash('sha256', $written),
        ];
    }

    private static function safe(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);

        return is_string($safe) && $safe !== '' ? $safe : 'unknown';
    }
}
