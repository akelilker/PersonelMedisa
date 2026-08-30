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
 * Scope is deliberately narrow — aylik_kapanis_state, aylik_ozet_satirlari and
 * medisa_schema_migrations. A whole-database dump is not attempted: it would not
 * finish inside a cron tick and it would copy far more personal data than the
 * rollback of one migration needs.
 *
 * The dump contains real closing rows, so it may only ever be written outside the
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
        'aylik_kapanis_state',
        'aylik_ozet_satirlari',
        'medisa_schema_migrations',
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
        $directory = self::resolveDirectory($apiDirectory);
        $fileName = sprintf(
            'medisa-pre-%s-%s-%s.sql',
            self::safe($migrationTip),
            self::safe($requestId),
            gmdate('Ymd-His')
        );
        $path = $directory . DIRECTORY_SEPARATOR . $fileName;

        $dump = self::renderDump($pdo, $requestId, $migrationTip);
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
            'readback' => 'VERIFIED',
        ];

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
     * @return array{sql: string, tables: list<string>, row_counts: array<string, int>}
     */
    private static function renderDump(PDO $pdo, string $requestId, string $migrationTip): array
    {
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

        foreach (self::BACKED_UP_TABLES as $table) {
            $createStatement = self::showCreate($pdo, $table);
            if ($createStatement === null) {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
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
            'tables' => self::BACKED_UP_TABLES,
            'row_counts' => $rowCounts,
        ];
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
     * digest and must still contain every required CREATE TABLE block.
     *
     * @param array{sql: string, tables: list<string>, row_counts: array<string, int>} $dump
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
