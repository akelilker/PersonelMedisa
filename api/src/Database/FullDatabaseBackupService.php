<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Full-database backup owner for the migration worker.
 *
 * The five-table pre-migration dumps stay on MigrationBackupService. This owner
 * adds the durable snapshot used before a migration: every table, view, trigger,
 * routine and event in the current database, written outside the webroot.
 *
 * The dump is not a download. Plain FTP is the only deploy transport and must
 * never carry this file. Restore proof is an on-server sibling database, dropped
 * before the method returns. A failed restore is not a successful backup.
 *
 * Published evidence is counts, checksums and reason codes. Row text stays in
 * the dump, which is mode 0600 and is never copied into the control-plane JSON.
 */
final class FullDatabaseBackupService
{
    public const SCHEMA_VERSION = '1';

    /** @var list<string> */
    private const YETKI_TABLES = [
        'user_yetki_istisnalari',
        'user_yetki_auditleri',
        'yetki_politikasi',
    ];

    /** @var list<string> */
    private const YETKI_TRIGGERS = [
        'trg_uyi_no_delete',
        'trg_uyi_only_revoke',
        'trg_uya_no_update',
        'trg_uya_no_delete',
    ];

    /** @var array<string, list<string>> */
    private const YETKI_INDEXES = [
        'user_yetki_istisnalari' => [
            'PRIMARY',
            'idx_uyi_user_aktif',
            'idx_uyi_permission',
            'idx_uyi_veren',
            'fk_uyi_sube',
            'fk_uyi_veren_kimlik',
        ],
        'user_yetki_auditleri' => [
            'PRIMARY',
            'idx_uya_hedef',
            'idx_uya_aktor',
            'fk_uya_istisna',
            'fk_uya_aktor_kimlik',
        ],
        'yetki_politikasi' => ['PRIMARY'],
    ];

    /**
     * @return array<string, mixed> publishable evidence; no row text and no absolute path
     */
    public static function capture(
        PDO $live,
        string $apiDirectory,
        string $requestId,
        string $deployedSha,
        string $serverDsn,
        string $username,
        string $password
    ): array {
        $fileName = 'NONE';
        $path = '';
        $snapshot = false;
        $tempName = null;
        $server = null;
        $liveName = '';
        $failed = null;
        $cleanupFailed = false;

        try {
            $directory = MigrationBackupService::operationsDirectory($apiDirectory);
            $liveName = self::currentDatabase($live);
            $tip = self::ledgerTip($live);
            $fileName = sprintf(
                'medisa-full-%s-%s-%s.sql',
                self::safe($tip),
                self::safe($requestId),
                gmdate('Ymd-His')
            );
            $path = $directory . DIRECTORY_SEPARATOR . $fileName;
            $live->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $live->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $snapshot = true;

            $inventory = self::inventory($live);
            $yetki = self::yetkiSchema($live);
            self::writeDump($live, $path, $requestId, $tip, $inventory);
            $live->commit();
            $snapshot = false;

            $readback = self::verifyFile($path);
            $tempName = $liveName . '_r' . bin2hex(random_bytes(4));
            self::assertRestoreName($tempName, $liveName);

            $server = self::serverPdo($serverDsn, $username, $password);
            self::createRestoreDatabase($server, $tempName);
            $target = self::serverPdo($serverDsn . ';dbname=' . $tempName, $username, $password);
            self::restoreInto($target, $path, $liveName);
            $mismatches = self::compare($target, $inventory);
            $restored = self::inventory($target);
        } catch (Throwable $exception) {
            $reason = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'FULL_DATABASE_BACKUP_FAILED';
            $tip = isset($tip) && is_string($tip) ? $tip : '000';
            $failed = self::failure($deployedSha, $tip, $reason, $fileName, $path);
        } finally {
            if ($snapshot && $live->inTransaction()) {
                $live->rollBack();
            }
            if ($server instanceof PDO && is_string($tempName) && !self::dropRestoreDatabase($server, $tempName, $liveName)) {
                $cleanupFailed = true;
            }
        }

        if ($failed !== null) {
            return $failed;
        }
        if ($cleanupFailed) {
            return self::failure($deployedSha, $tip, 'RESTORE_DATABASE_CLEANUP_FAILED', $fileName, $path);
        }

        if ($mismatches !== []) {
            return self::failure($deployedSha, $tip, 'FULL_BACKUP_RESTORE_MISMATCH', $fileName, $path, [
                'schema_mismatch_count' => count($mismatches),
            ]);
        }

        $evidence = self::evidence(
            $deployedSha,
            $tip,
            'PASS',
            null,
            $fileName,
            $readback,
            $yetki,
            $inventory,
            $restored
        );
        self::writeMeta($path, $evidence);

        return $evidence;
    }

    /**
     * Replay a length-prefixed dump into an already-selected sibling database.
     * Refuses the live database name.
     */
    public static function restoreInto(PDO $target, string $path, string $forbiddenDatabase): void
    {
        $current = self::currentDatabase($target);
        self::assertRestoreName($current, $forbiddenDatabase);

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^S(\d+)\r?\n$/', $line) !== 1) {
                    continue;
                }
                $length = (int) substr(trim($line), 1);
                $sql = '';
                while (strlen($sql) < $length) {
                    $chunk = fread($handle, $length - strlen($sql));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
                    }
                    $sql .= $chunk;
                }
                $statement = rtrim($sql, "\r\n");
                if ($statement !== '') {
                    $target->exec($statement);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{
     *   tables: list<string>,
     *   views: list<string>,
     *   triggers: list<string>,
     *   routines: list<string>,
     *   events: list<string>,
     *   row_counts: array<string, int>,
     *   index_counts: array<string, int>,
     *   foreign_key_counts: array<string, int>
     * } $inventory
     */
    private static function writeDump(
        PDO $live,
        string $path,
        string $requestId,
        string $tip,
        array $inventory
    ): void {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('BACKUP_WRITE_FAILED');
        }
        $header = implode("\n", [
            '-- medisa-full-backup',
            '-- schema_version: ' . self::SCHEMA_VERSION,
            '-- request_id: ' . self::safe($requestId),
            '-- migration_tip: ' . self::safe($tip),
            '-- created_at: ' . gmdate('Y-m-d\TH:i:s\Z'),
            '-- framing: length-prefixed statements; not for the mysql client',
            '',
        ]) . "\n";
        if (fwrite($handle, $header) === false) {
            fclose($handle);
            throw new RuntimeException('BACKUP_WRITE_FAILED');
        }

        $write = static function (string $sql) use ($handle): void {
            $payload = rtrim($sql, "\r\n") . "\n";
            $record = 'S' . strlen($payload) . "\n" . $payload;
            if (fwrite($handle, $record) === false) {
                throw new RuntimeException('BACKUP_WRITE_FAILED');
            }
        };

        try {
            $write('SET NAMES utf8mb4');
            $write('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($inventory['views'] as $view) {
                $write('DROP VIEW IF EXISTS ' . self::ident($view));
            }
            foreach ($inventory['tables'] as $table) {
                $write('DROP TABLE IF EXISTS ' . self::ident($table));
            }
            foreach ($inventory['tables'] as $table) {
                $write(self::showCreate($live, 'TABLE', $table));
                self::writeRows($live, $handle, $table);
            }
            foreach ($inventory['views'] as $view) {
                $write(self::showCreate($live, 'VIEW', $view));
            }
            foreach ($inventory['triggers'] as $trigger) {
                $write(self::showCreate($live, 'TRIGGER', $trigger));
            }
            foreach ($inventory['routines'] as $routine) {
                $write(self::showCreateRoutine($live, $routine));
            }
            foreach ($inventory['events'] as $event) {
                $write(self::showCreate($live, 'EVENT', $event));
            }
            $write('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable $exception) {
            fclose($handle);
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
        }
        fclose($handle);
        @chmod($path, 0600);
    }

    /**
     * @param resource $handle
     */
    private static function writeRows(PDO $live, $handle, string $table): void
    {
        $previous = $live->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $live->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        try {
            $statement = $live->query('SELECT * FROM ' . self::ident($table));
            if ($statement === false) {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
            }
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $payload = rtrim(self::renderInsert($live, $table, $row), "\r\n") . "\n";
                $record = 'S' . strlen($payload) . "\n" . $payload;
                if (fwrite($handle, $record) === false) {
                    throw new RuntimeException('BACKUP_WRITE_FAILED');
                }
            }
            $statement->closeCursor();
        } finally {
            $live->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previous);
        }
    }

    /**
     * @return array{bytes: int, sha256: string}
     */
    private static function verifyFile(string $path): array
    {
        clearstatcache(true, $path);
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 32) {
            throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
        }
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
        }
        $head = (string) file_get_contents($path, false, null, 0, 64);
        if (strpos($head, '-- medisa-full-backup') !== 0) {
            throw new RuntimeException('BACKUP_READBACK_INCOMPLETE');
        }

        return ['bytes' => $bytes, 'sha256' => $hash];
    }

    /**
     * @return array{
     *   tables: list<string>,
     *   views: list<string>,
     *   triggers: list<string>,
     *   routines: list<string>,
     *   events: list<string>,
     *   row_counts: array<string, int>,
     *   index_counts: array<string, int>,
     *   foreign_key_counts: array<string, int>
     * }
     */
    private static function inventory(PDO $pdo): array
    {
        $schema = self::currentDatabase($pdo);
        $tables = self::names($pdo, "SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME", $schema);
        $views = self::names($pdo, "SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME", $schema);
        $triggers = self::names($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = :schema ORDER BY TRIGGER_NAME', $schema);
        $routines = self::names($pdo, "SELECT CONCAT(ROUTINE_TYPE, ':', ROUTINE_NAME) FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = :schema ORDER BY ROUTINE_TYPE, ROUTINE_NAME", $schema);
        $events = self::names($pdo, 'SELECT EVENT_NAME FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = :schema ORDER BY EVENT_NAME', $schema);

        $rowCounts = [];
        $indexCounts = [];
        $foreignKeys = [];
        foreach ($tables as $table) {
            $rowCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::ident($table))->fetchColumn();
            $indexCounts[$table] = self::countFor($pdo, 'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name', $schema, $table);
            $foreignKeys[$table] = self::countFor($pdo, "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name AND CONSTRAINT_TYPE = 'FOREIGN KEY'", $schema, $table);
        }

        return [
            'tables' => $tables,
            'views' => $views,
            'triggers' => $triggers,
            'routines' => $routines,
            'events' => $events,
            'row_counts' => $rowCounts,
            'index_counts' => $indexCounts,
            'foreign_key_counts' => $foreignKeys,
        ];
    }

    /**
     * @param array<string, mixed> $inventory
     * @return list<string>
     */
    private static function compare(PDO $restored, array $inventory): array
    {
        $actual = self::inventory($restored);
        $mismatches = [];
        foreach (['tables', 'views', 'triggers', 'routines', 'events'] as $key) {
            if ($actual[$key] !== $inventory[$key]) {
                $mismatches[] = strtoupper($key) . '_SET';
            }
        }
        if ($actual['row_counts'] !== $inventory['row_counts']) {
            $mismatches[] = 'ROW_COUNT';
        }
        if ($actual['index_counts'] !== $inventory['index_counts']) {
            $mismatches[] = 'INDEX_COUNT';
        }
        if ($actual['foreign_key_counts'] !== $inventory['foreign_key_counts']) {
            $mismatches[] = 'FOREIGN_KEY_COUNT';
        }

        return $mismatches;
    }

    /**
     * @return array{state: string, tables_present: int, triggers_present: int, indexes_present: int}
     */
    private static function yetkiSchema(PDO $pdo): array
    {
        $schema = self::currentDatabase($pdo);
        $tables = 0;
        foreach (self::YETKI_TABLES as $table) {
            if (self::countFor($pdo, 'SELECT COUNT(*) FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name', $schema, $table) === 1) {
                $tables++;
            }
        }
        $triggers = 0;
        foreach (self::YETKI_TRIGGERS as $trigger) {
            if (self::countFor($pdo, 'SELECT COUNT(*) FROM information_schema.TRIGGERS
                WHERE TRIGGER_SCHEMA = :schema AND TRIGGER_NAME = :name', $schema, $trigger) === 1) {
                $triggers++;
            }
        }
        $indexes = 0;
        $expectedIndexes = 0;
        foreach (self::YETKI_INDEXES as $table => $names) {
            foreach ($names as $name) {
                $expectedIndexes++;
                if (self::countFor($pdo, 'SELECT COUNT(*) FROM information_schema.STATISTICS
                    WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name AND INDEX_NAME = :index_name', $schema, $table, $name) > 0) {
                    $indexes++;
                }
            }
        }

        if ($tables === 0 && $triggers === 0 && $indexes === 0) {
            $state = 'ABSENT';
        } elseif ($tables === count(self::YETKI_TABLES)
            && $triggers === count(self::YETKI_TRIGGERS)
            && $indexes === $expectedIndexes
        ) {
            $state = 'PRESENT';
        } else {
            $state = 'PARTIAL';
        }

        return [
            'state' => $state,
            'tables_present' => $tables,
            'triggers_present' => $triggers,
            'indexes_present' => $indexes,
        ];
    }

    private static function showCreate(PDO $pdo, string $kind, string $name): string
    {
        $sql = 'SHOW CREATE ' . $kind . ' ' . self::ident($name);
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
        }
        $statement = null;
        foreach ($row as $value) {
            if (!is_string($value) || strncasecmp(ltrim($value), 'CREATE', 6) !== 0) {
                continue;
            }
            if ($statement === null || strlen($value) > strlen($statement)) {
                $statement = $value;
            }
        }
        if ($statement === null || $statement === '') {
            throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
        }

        return self::withoutDefiner($statement);
    }

    /**
     * SHOW CREATE keeps the original account. An empty or foreign DEFINER
     * cannot be replayed by the database user, so the object is recreated
     * as the account that restores the dump.
     */
    private static function withoutDefiner(string $statement): string
    {
        $stripped = preg_replace(
            '/\s*DEFINER\s*=\s*`[^`]*`(?:\s*@\s*`[^`]*`)?/i',
            '',
            $statement
        );
        if (!is_string($stripped) || trim($stripped) === '' || strncasecmp(ltrim($stripped), 'CREATE', 6) !== 0) {
            throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
        }

        return $stripped;
    }

    private static function showCreateRoutine(PDO $pdo, string $typedName): string
    {
        $parts = explode(':', $typedName, 2);
        if (count($parts) !== 2 || ($parts[0] !== 'PROCEDURE' && $parts[0] !== 'FUNCTION')) {
            throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
        }

        return self::showCreate($pdo, $parts[0], $parts[1]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function renderInsert(PDO $pdo, string $table, array $row): string
    {
        $columns = [];
        $values = [];
        foreach ($row as $column => $value) {
            $columns[] = self::ident((string) $column);
            if ($value === null) {
                $values[] = 'NULL';
                continue;
            }
            $quoted = $pdo->quote((string) $value);
            if ($quoted === false) {
                throw new RuntimeException('BACKUP_VALUE_UNQUOTABLE');
            }
            $values[] = $quoted;
        }

        return 'INSERT INTO ' . self::ident($table) . ' (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $values) . ')';
    }

    /**
     * @param array<string, int|string> $extra
     * @return array<string, mixed>
     */
    private static function failure(
        string $deployedSha,
        string $tip,
        string $reason,
        string $fileName,
        string $path,
        array $extra = []
    ): array {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $reason) !== 1) {
            $reason = 'FULL_DATABASE_BACKUP_FAILED';
        }
        $readback = ['bytes' => 0, 'sha256' => str_repeat('0', 64)];
        if (is_file($path)) {
            $hash = hash_file('sha256', $path);
            $bytes = filesize($path);
            if ($hash !== false && $bytes !== false) {
                $readback = ['bytes' => $bytes, 'sha256' => $hash];
            }
        }
        $empty = [
            'tables' => [],
            'views' => [],
            'triggers' => [],
            'routines' => [],
            'events' => [],
            'row_counts' => [],
            'index_counts' => [],
            'foreign_key_counts' => [],
        ];

        return self::evidence(
            $deployedSha,
            $tip,
            'FAIL',
            $reason,
            is_file($path) ? $fileName : 'NONE',
            $readback,
            ['state' => 'UNKNOWN', 'tables_present' => -1, 'triggers_present' => -1, 'indexes_present' => -1],
            $empty,
            $empty,
            $extra
        );
    }

    /**
     * @param array{bytes: int, sha256: string} $readback
     * @param array{state: string, tables_present: int, triggers_present: int, indexes_present: int} $yetki
     * @param array<string, mixed> $source
     * @param array<string, mixed> $restored
     * @param array<string, int|string> $extra
     * @return array<string, mixed>
     */
    private static function evidence(
        string $deployedSha,
        string $tip,
        string $result,
        ?string $reason,
        string $fileName,
        array $readback,
        array $yetki,
        array $source,
        array $restored,
        array $extra = []
    ): array {
        $sourceRows = array_sum($source['row_counts'] ?? []);
        $restoredRows = array_sum($restored['row_counts'] ?? []);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => 'FULL_DATABASE_BACKUP',
            'deployed_sha' => strtolower($deployedSha),
            'ledger_tip' => $tip,
            'result' => $result,
            'failure_reason' => $reason,
            'dump_transferred' => false,
            'dump_channel' => 'NONE',
            'outside_webroot' => true,
            'readback' => $result === 'PASS' ? 'VERIFIED' : 'FAILED',
            'restore_verified' => $result === 'PASS',
            'backup_file' => $fileName,
            'backup_sha256' => $readback['sha256'],
            'backup_bytes' => $readback['bytes'],
            'table_count' => count($source['tables'] ?? []),
            'view_count' => count($source['views'] ?? []),
            'trigger_count' => count($source['triggers'] ?? []),
            'routine_count' => count($source['routines'] ?? []),
            'event_count' => count($source['events'] ?? []),
            'total_rows' => $sourceRows,
            'restore_table_count' => count($restored['tables'] ?? []),
            'restore_total_rows' => $restoredRows,
            'schema_mismatch_count' => (int) ($extra['schema_mismatch_count'] ?? 0),
            'yetki_schema' => $yetki['state'],
            'yetki_tables_present' => $yetki['tables_present'],
            'yetki_triggers_present' => $yetki['triggers_present'],
            'yetki_indexes_present' => $yetki['indexes_present'],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private static function writeMeta(string $path, array $evidence): void
    {
        @file_put_contents(
            $path . '.meta.json',
            json_encode($evidence + ['absolute_path' => $path], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL,
            LOCK_EX
        );
        @chmod($path . '.meta.json', 0600);
    }

    private static function createRestoreDatabase(PDO $server, string $name): void
    {
        try {
            $server->exec(
                'CREATE DATABASE ' . self::ident($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('RESTORE_DATABASE_UNAVAILABLE');
        }
    }

    private static function dropRestoreDatabase(PDO $server, string $name, string $liveName): bool
    {
        try {
            self::assertRestoreName($name, $liveName);
            $server->exec('DROP DATABASE IF EXISTS ' . self::ident($name));
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }

    private static function assertRestoreName(string $name, string $liveName): void
    {
        $prefix = $liveName . '_r';
        if ($name === $liveName || strncmp($name, $prefix, strlen($prefix)) !== 0) {
            throw new RuntimeException('RESTORE_TARGET_REFUSED');
        }
        self::ident($name);
        self::ident($liveName);
    }

    private static function serverPdo(string $dsn, string $username, string $password): PDO
    {
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function currentDatabase(PDO $pdo): string
    {
        $name = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($name) || $name === '') {
            throw new RuntimeException('BACKUP_DATABASE_UNKNOWN');
        }

        return self::bareIdent($name);
    }

    private static function ledgerTip(PDO $pdo): string
    {
        try {
            $tip = $pdo->query(
                'SELECT version FROM medisa_schema_migrations ORDER BY version DESC LIMIT 1'
            )->fetchColumn();
        } catch (Throwable $exception) {
            throw new RuntimeException('BACKUP_LEDGER_UNREADABLE');
        }
        if (!is_string($tip) || preg_match('/^\d{3}$/', $tip) !== 1) {
            throw new RuntimeException('BACKUP_LEDGER_UNREADABLE');
        }

        return $tip;
    }

    /**
     * @return list<string>
     */
    private static function names(PDO $pdo, string $sql, string $schema): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(['schema' => $schema]);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (!is_string($name) || $name === '') {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
            }
            if (strpos($name, ':') !== false) {
                $names[] = $name;
                continue;
            }
            $names[] = self::bareIdent($name);
        }

        return $names;
    }

    private static function countFor(PDO $pdo, string $sql, string $schema, string $name, ?string $index = null): int
    {
        $statement = $pdo->prepare($sql);
        $params = ['schema' => $schema, 'name' => $name];
        if ($index !== null) {
            $params['index_name'] = $index;
        }
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    private static function ident(string $name): string
    {
        return '`' . self::bareIdent($name) . '`';
    }

    private static function bareIdent(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException('BACKUP_IDENTIFIER_REJECTED');
        }

        return $name;
    }

    private static function safe(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);

        return is_string($safe) && $safe !== '' ? $safe : 'unknown';
    }
}
