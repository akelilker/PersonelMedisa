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
 * The dump is not a download and it is not an off-server disaster-recovery
 * copy: it never leaves the machine that holds the live database. Plain FTP is
 * the only deploy transport and must never carry this file. When the account
 * has global CREATE and DROP, restore proof is a temporary sibling database
 * that is dropped before the method returns. On shared hosting the proof is
 * the empty database named by server config db_restore_name. That database is
 * kept; only objects this run created are removed. The name is never taken
 * from a request. The live database is never the target. A database that
 * already holds data is left untouched and the run stops. Losing the server
 * disk loses this backup with it.
 *
 * Framing is length-prefixed records (`S` + byte length + newline + SQL +
 * newline), not a mysql-client script. Replay goes through restoreInto().
 * See docs/guncel/tam-veritabani-yedegi.md.
 *
 * Published evidence is counts, checksums and reason codes. Row text stays in
 * the dump, which is mode 0600 and is never copied into the control-plane JSON.
 */
final class FullDatabaseBackupService
{
    public const SCHEMA_VERSION = '2';

    public const MAX_AGE_SECONDS = 43200;

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
        $privilege = 'UNREADABLE';
        $restoreTarget = 'NONE';
        $tip = '000';
        $dedicated = null;
        $clearDedicated = false;

        try {
            $liveName = self::currentDatabase($live);
            $globalPrivilege = self::readSiblingPrivilege($live);
            $configured = self::configuredRestoreName();
            if ($globalPrivilege === 'PRESENT') {
                $privilege = 'PRESENT';
                $restoreTarget = 'TEMPORARY';
            } elseif ($configured !== '') {
                if ($configured === $liveName) {
                    throw new RuntimeException('RESTORE_TARGET_REFUSED');
                }
                self::assertDedicatedReady($live, $configured, $liveName);
                $privilege = 'DEDICATED';
                $restoreTarget = 'DEDICATED';
            } elseif ($globalPrivilege === 'ABSENT') {
                throw new RuntimeException('RESTORE_PRIVILEGE_ABSENT');
            } else {
                $privilege = 'UNREADABLE';
                $restoreTarget = 'TEMPORARY';
            }
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
            if ($restoreTarget === 'DEDICATED') {
                $dedicated = self::serverPdo($serverDsn . ';dbname=' . $configured, $username, $password);
                if (self::currentDatabase($dedicated) !== $configured || $configured === $liveName) {
                    $dedicated = null;
                    throw new RuntimeException('RESTORE_TARGET_REFUSED');
                }
                if (self::schemaObjectCount($dedicated, $configured) !== 0) {
                    $dedicated = null;
                    throw new RuntimeException('RESTORE_TARGET_OCCUPIED');
                }
                $clearDedicated = true;
                $target = $dedicated;
            } else {
                $tempName = $liveName . '_r' . bin2hex(random_bytes(4));
                self::assertRestoreName($tempName, $liveName);
                $server = self::serverPdo($serverDsn, $username, $password);
                self::createRestoreDatabase($server, $tempName);
                $target = self::serverPdo($serverDsn . ';dbname=' . $tempName, $username, $password);
            }
            self::restoreInto($target, $path, $liveName);
            $mismatches = self::compare($target, $inventory);
            $restored = self::inventory($target);
        } catch (Throwable $exception) {
            $reason = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'FULL_DATABASE_BACKUP_FAILED';
            $tip = isset($tip) && is_string($tip) ? $tip : '000';
            $failed = self::failure($deployedSha, $tip, $reason, $fileName, $path, [
                'restore_target' => $restoreTarget,
            ]);
        } finally {
            if ($snapshot && $live->inTransaction()) {
                $live->rollBack();
            }
            if ($clearDedicated && $dedicated instanceof PDO && !self::clearDedicatedDatabase($dedicated, $liveName)) {
                $cleanupFailed = true;
            }
            if ($server instanceof PDO && is_string($tempName) && !self::dropRestoreDatabase($server, $tempName, $liveName)) {
                $cleanupFailed = true;
            }
        }

        if ($cleanupFailed) {
            return self::failure($deployedSha, $tip, 'RESTORE_DATABASE_CLEANUP_FAILED', $fileName, $path, [
                'restore_target' => $restoreTarget,
            ]);
        }
        if ($failed !== null) {
            return $failed;
        }

        if ($mismatches !== []) {
            return self::failure($deployedSha, $tip, 'FULL_BACKUP_RESTORE_MISMATCH', $fileName, $path, [
                'schema_mismatch_count' => count($mismatches),
                'content_mismatch_count' => count(array_intersect($mismatches, ['CONTENT_HASH', 'VIEW_CONTENT'])),
                'definition_mismatch_count' => count(array_intersect($mismatches, ['DEFINITION', 'TRIGGER_BODY', 'ROUTINE_BODY'])),
                'restore_target' => $restoreTarget,
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
            $restored,
            ['restore_target' => $restoreTarget],
            $privilege
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
        self::assertReplayTarget($current, $forbiddenDatabase);

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
            '-- scope: same-server verification only; not an off-server disaster-recovery copy',
            '-- framing: length-prefixed statements; replay with FullDatabaseBackupService::restoreInto',
            '-- restore-target: a sibling named {live}_r* ; the live database name is refused',
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
            $generated = self::generatedColumns($live, $table);
            $statement = $live->query('SELECT * FROM ' . self::ident($table));
            if ($statement === false) {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
            }
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $payload = rtrim(self::renderInsert($live, $table, array_diff_key($row, $generated)), "\r\n") . "\n";
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
        $contentHashes = [];
        $definitionHashes = [];
        foreach ($tables as $table) {
            $rowCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . self::ident($table))->fetchColumn();
            $indexCounts[$table] = self::countFor($pdo, 'SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name', $schema, $table);
            $foreignKeys[$table] = self::countFor($pdo, "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name AND CONSTRAINT_TYPE = 'FOREIGN KEY'", $schema, $table);
            $contentHashes[$table] = self::contentHash($pdo, $schema, $table);
            $definitionHashes['TABLE:' . $table] = hash('sha256', self::showCreate($pdo, 'TABLE', $table));
        }
        $viewContentHashes = [];
        foreach ($views as $view) {
            $viewContentHashes[$view] = self::contentHash($pdo, $schema, $view);
            $definitionHashes['VIEW:' . $view] = hash('sha256', self::showCreate($pdo, 'VIEW', $view));
        }
        foreach ($triggers as $trigger) {
            $definitionHashes['TRIGGER:' . $trigger] = hash('sha256', self::showCreate($pdo, 'TRIGGER', $trigger));
        }
        foreach ($routines as $routine) {
            $parts = explode(':', $routine, 2);
            $definitionHashes['ROUTINE:' . $routine] = hash('sha256', self::showCreate($pdo, $parts[0], $parts[1]));
        }
        foreach ($events as $event) {
            $definitionHashes['EVENT:' . $event] = hash('sha256', self::showCreate($pdo, 'EVENT', $event));
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
            'content_hashes' => $contentHashes,
            'view_content_hashes' => $viewContentHashes,
            'definition_hashes' => $definitionHashes,
            'trigger_bodies' => self::triggerBodies($pdo, $schema),
            'routine_bodies' => self::routineBodies($pdo, $schema),
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
        if (($actual['content_hashes'] ?? null) !== ($inventory['content_hashes'] ?? null)) {
            $mismatches[] = 'CONTENT_HASH';
        }
        if (($actual['view_content_hashes'] ?? null) !== ($inventory['view_content_hashes'] ?? null)) {
            $mismatches[] = 'VIEW_CONTENT';
        }
        if (($actual['definition_hashes'] ?? null) !== ($inventory['definition_hashes'] ?? null)) {
            $mismatches[] = 'DEFINITION';
        }
        if (($actual['trigger_bodies'] ?? null) !== ($inventory['trigger_bodies'] ?? null)) {
            $mismatches[] = 'TRIGGER_BODY';
        }
        if (($actual['routine_bodies'] ?? null) !== ($inventory['routine_bodies'] ?? null)) {
            $mismatches[] = 'ROUTINE_BODY';
        }

        return $mismatches;
    }

    /**
     * Mismatch codes only. No row text and no object definitions.
     *
     * @return list<string>
     */
    public static function mismatchCodes(PDO $source, PDO $restored): array
    {
        return self::compare($restored, self::inventory($source));
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
    /**
     * VIRTUAL/STORED (generated) kolonlar INSERT'e yazılmaz; MariaDB değeri reddeder (1906)
     * ve geri yüklemede ifadeden yeniden hesaplanır.
     *
     * @return array<string, true>
     */
    private static function generatedColumns(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name
               AND (UPPER(EXTRA) LIKE '%GENERATED%' OR UPPER(EXTRA) LIKE '%VIRTUAL%'
                    OR UPPER(EXTRA) LIKE '%STORED%' OR UPPER(EXTRA) LIKE '%PERSISTENT%')"
        );
        $statement->execute(['name' => $table]);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name) && $name !== '') {
                $names[$name] = true;
            }
        }

        return $names;
    }

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
        array $extra = [],
        string $privilege = 'UNREADABLE'
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
            'content_mismatch_count' => (int) ($extra['content_mismatch_count'] ?? 0),
            'definition_mismatch_count' => (int) ($extra['definition_mismatch_count'] ?? 0),
            'restore_privilege' => self::publishedPrivilege($result, $reason, $privilege),
            'restore_target' => self::restoreTargetMode($extra),
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

    /**
     * PRESENT only when SHOW GRANTS lists global CREATE and DROP.
     * ABSENT is the cPanel-shaped account: rights on the live database, nothing global.
     * UNREADABLE means the grant tables could not be asked; that is not VERIFIED.
     */
    private static function readSiblingPrivilege(PDO $account): string
    {
        try {
            $statement = $account->query('SHOW GRANTS FOR CURRENT_USER()');
            if ($statement === false) {
                return 'UNREADABLE';
            }
            $lines = $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $exception) {
            return 'UNREADABLE';
        }

        return self::classifyGrantLines($lines);
    }

    /**
     * @param list<mixed> $lines
     */
    public static function classifyGrantLines(array $lines): string
    {
        return self::grantsAllowSiblingDatabase($lines) ? 'PRESENT' : 'ABSENT';
    }

    private static function publishedPrivilege(string $result, ?string $reason, string $privilege): string
    {
        if (
            $reason === 'RESTORE_PRIVILEGE_ABSENT'
            || $reason === 'RESTORE_TARGET_PRIVILEGE_ABSENT'
            || $privilege === 'ABSENT'
        ) {
            return 'ABSENT';
        }
        if ($result === 'PASS' && ($privilege === 'PRESENT' || $privilege === 'DEDICATED')) {
            return 'VERIFIED';
        }

        return 'UNPROVEN';
    }

    /**
     * @param list<mixed> $lines
     */
    private static function grantsAllowSiblingDatabase(array $lines): bool
    {
        foreach ($lines as $line) {
            if (!is_string($line)) {
                continue;
            }
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+`?\*`?\.`?\*`?\s+TO\s+/i', $line, $match) !== 1) {
                continue;
            }
            $tokens = self::privilegeTokens($match[1]);
            if (in_array('ALL PRIVILEGES', $tokens, true) || in_array('ALL', $tokens, true)) {
                return true;
            }
            if (in_array('CREATE', $tokens, true) && in_array('DROP', $tokens, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function privilegeTokens(string $clause): array
    {
        $clause = preg_replace('/\s+IDENTIFIED\s+BY\s+.*/i', '', $clause);
        $parts = preg_split('/\s*,\s*/', strtoupper(trim(is_string($clause) ? $clause : '')));
        if ($parts === false) {
            return [];
        }
        $tokens = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Server-side gate for migration 100. GitHub's check is not this check.
     * The dump must still be on disk, match its SHA-256, belong to this deploy
     * and ledger tip, be inside the validity window, and the three yetki tables
     * must still be absent at the moment APPLY would start.
     */
    public static function assertReadyForApply(
        PDO $live,
        string $apiDirectory,
        string $controlDirectory,
        string $deployedSha
    ): void {
        $evidencePath = $controlDirectory . DIRECTORY_SEPARATOR . 'full-backup-evidence.json';
        if (!is_file($evidencePath)) {
            throw new RuntimeException('FULL_BACKUP_EVIDENCE_MISSING');
        }
        $raw = file_get_contents($evidencePath);
        $evidence = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($evidence)) {
            throw new RuntimeException('FULL_BACKUP_EVIDENCE_UNREADABLE');
        }
        if (($evidence['schema_version'] ?? '') !== self::SCHEMA_VERSION) {
            throw new RuntimeException('FULL_BACKUP_EVIDENCE_UNREADABLE');
        }
        if (($evidence['result'] ?? '') !== 'PASS') {
            throw new RuntimeException('FULL_BACKUP_NOT_PASS');
        }
        if (($evidence['restore_verified'] ?? false) !== true || ($evidence['readback'] ?? '') !== 'VERIFIED') {
            throw new RuntimeException('FULL_BACKUP_RESTORE_UNVERIFIED');
        }
        if (($evidence['restore_privilege'] ?? '') !== 'VERIFIED') {
            throw new RuntimeException('RESTORE_PRIVILEGE_ABSENT');
        }
        if (($evidence['dump_transferred'] ?? true) !== false || ($evidence['dump_channel'] ?? '') !== 'NONE') {
            throw new RuntimeException('FULL_BACKUP_TRANSFER_FORBIDDEN');
        }
        if (($evidence['outside_webroot'] ?? false) !== true) {
            throw new RuntimeException('FULL_BACKUP_LOCATION_UNSAFE');
        }
        if (strtolower((string) ($evidence['deployed_sha'] ?? '')) !== strtolower($deployedSha)) {
            throw new RuntimeException('FULL_BACKUP_DEPLOY_SHA_MISMATCH');
        }
        if ((string) ($evidence['ledger_tip'] ?? '') !== self::ledgerTip($live)) {
            throw new RuntimeException('FULL_BACKUP_LEDGER_MISMATCH');
        }
        $generated = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            (string) ($evidence['generated_at'] ?? ''),
            new \DateTimeZone('UTC')
        );
        if (!$generated instanceof \DateTimeImmutable) {
            throw new RuntimeException('FULL_BACKUP_STALE');
        }
        $age = time() - $generated->getTimestamp();
        if ($age < 0 || $age > self::MAX_AGE_SECONDS) {
            throw new RuntimeException('FULL_BACKUP_STALE');
        }
        if ((int) ($evidence['schema_mismatch_count'] ?? 1) !== 0
            || (int) ($evidence['content_mismatch_count'] ?? 1) !== 0
            || (int) ($evidence['definition_mismatch_count'] ?? 1) !== 0
        ) {
            throw new RuntimeException('FULL_BACKUP_RESTORE_MISMATCH');
        }
        if (($evidence['yetki_schema'] ?? '') !== 'ABSENT' || self::yetkiSchema($live)['state'] !== 'ABSENT') {
            throw new RuntimeException('YETKI_SCHEMA_NOT_ABSENT');
        }

        $file = (string) ($evidence['backup_file'] ?? '');
        if (preg_match('/^medisa-full-[A-Za-z0-9._-]+\.sql$/', $file) !== 1) {
            throw new RuntimeException('FULL_BACKUP_FILE_MISSING');
        }
        $directory = MigrationBackupService::operationsDirectory($apiDirectory);
        $dump = $directory . DIRECTORY_SEPARATOR . $file;
        $realDirectory = realpath($directory);
        $realDump = realpath($dump);
        if ($realDirectory === false || $realDump === false || !self::pathInside($realDump, $realDirectory) || !is_file($realDump)) {
            throw new RuntimeException('FULL_BACKUP_FILE_MISSING');
        }
        $hash = hash_file('sha256', $realDump);
        $bytes = filesize($realDump);
        $expectedHash = (string) ($evidence['backup_sha256'] ?? '');
        if (!is_string($hash) || strlen($expectedHash) !== 64 || !hash_equals($expectedHash, $hash)) {
            throw new RuntimeException('FULL_BACKUP_SHA_MISMATCH');
        }
        if ($bytes === false || $bytes !== (int) ($evidence['backup_bytes'] ?? -1)) {
            throw new RuntimeException('FULL_BACKUP_SHA_MISMATCH');
        }
    }

    private static function pathInside(string $file, string $directory): bool
    {
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $file = str_replace('\\', '/', $file);

        return $file === $directory || strpos($file, $directory . '/') === 0;
    }

    private static function contentHash(PDO $pdo, string $schema, string $table): string
    {
        $columns = self::columnNames($pdo, $schema, $table);
        if ($columns === []) {
            throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
        }
        $order = self::orderColumns($pdo, $schema, $table, $columns);
        $sql = 'SELECT ' . implode(', ', array_map([self::class, 'ident'], $columns))
            . ' FROM ' . self::ident($table)
            . ' ORDER BY ' . implode(', ', array_map([self::class, 'ident'], $order));
        $previous = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $context = hash_init('sha256');
        hash_update($context, implode("\x1f", $columns) . "\n");
        try {
            $statement = $pdo->query($sql);
            if ($statement === false) {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
            }
            while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $parts = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;
                    if ($value === null) {
                        $parts[] = "\\N";
                        continue;
                    }
                    $quoted = $pdo->quote((string) $value);
                    if ($quoted === false) {
                        throw new RuntimeException('BACKUP_VALUE_UNQUOTABLE');
                    }
                    $parts[] = $quoted;
                }
                hash_update($context, implode("\x1e", $parts) . "\n");
            }
            $statement->closeCursor();
        } finally {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $previous);
        }

        return hash_final($context);
    }

    /**
     * @return list<string>
     */
    private static function columnNames(PDO $pdo, string $schema, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name
             ORDER BY ORDINAL_POSITION'
        );
        $statement->execute(['schema' => $schema, 'name' => $table]);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (!is_string($name) || $name === '') {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
            }
            $names[] = self::bareIdent($name);
        }

        return $names;
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private static function orderColumns(PDO $pdo, string $schema, string $table, array $columns): array
    {
        $statement = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :name AND INDEX_NAME = 'PRIMARY'
             ORDER BY SEQ_IN_INDEX"
        );
        $statement->execute(['schema' => $schema, 'name' => $table]);
        $primary = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name) && $name !== '') {
                $primary[] = self::bareIdent($name);
            }
        }

        return $primary !== [] ? $primary : $columns;
    }

    /**
     * @return array<string, string>
     */
    private static function triggerBodies(PDO $pdo, string $schema): array
    {
        $statement = $pdo->prepare(
            'SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_ORIENTATION, ACTION_STATEMENT
             FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :schema ORDER BY TRIGGER_NAME'
        );
        $statement->execute(['schema' => $schema]);
        $bodies = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $statementText = (string) ($row['ACTION_STATEMENT'] ?? '');
            if ($statementText === '') {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
            }
            $name = self::bareIdent((string) $row['TRIGGER_NAME']);
            $bodies[$name] = hash('sha256', implode('|', [
                (string) $row['EVENT_MANIPULATION'],
                (string) $row['ACTION_TIMING'],
                (string) $row['ACTION_ORIENTATION'],
                $statementText,
            ]));
        }

        return $bodies;
    }

    /**
     * @return array<string, string>
     */
    private static function routineBodies(PDO $pdo, string $schema): array
    {
        $statement = $pdo->prepare(
            'SELECT ROUTINE_TYPE, ROUTINE_NAME, ROUTINE_DEFINITION, DTD_IDENTIFIER, IS_DETERMINISTIC, SQL_DATA_ACCESS
             FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :schema
             ORDER BY ROUTINE_TYPE, ROUTINE_NAME'
        );
        $statement->execute(['schema' => $schema]);
        $bodies = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $definition = $row['ROUTINE_DEFINITION'] ?? null;
            if (!is_string($definition) || $definition === '') {
                throw new RuntimeException('BACKUP_SOURCE_INCOMPLETE');
            }
            $key = self::bareIdent((string) $row['ROUTINE_TYPE']) . ':' . self::bareIdent((string) $row['ROUTINE_NAME']);
            $bodies[$key] = hash('sha256', implode('|', [
                $definition,
                (string) ($row['DTD_IDENTIFIER'] ?? ''),
                (string) ($row['IS_DETERMINISTIC'] ?? ''),
                (string) ($row['SQL_DATA_ACCESS'] ?? ''),
            ]));
        }

        return $bodies;
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

    private static function assertReplayTarget(string $current, string $liveName): void
    {
        if ($current === $liveName) {
            throw new RuntimeException('RESTORE_TARGET_REFUSED');
        }
        $configured = self::configuredRestoreName();
        if ($configured !== '' && $current === $configured) {
            return;
        }
        self::assertRestoreName($current, $liveName);
    }

    private static function configuredRestoreName(): string
    {
        if (!function_exists('medisa_config')) {
            return '';
        }
        $value = medisa_config('db_restore_name', '');
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return self::bareIdent($value);
    }

    /**
     * @param array<string, int|string> $extra
     */
    private static function restoreTargetMode(array $extra): string
    {
        $mode = (string) ($extra['restore_target'] ?? 'NONE');

        return in_array($mode, ['TEMPORARY', 'DEDICATED', 'NONE'], true) ? $mode : 'NONE';
    }

    /** @var list<string> */
    private const DEDICATED_PRIVILEGES = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'DROP',
        'INDEX',
        'ALTER',
        'REFERENCES',
        'CREATE VIEW',
        'SHOW VIEW',
        'TRIGGER',
        'CREATE ROUTINE',
        'ALTER ROUTINE',
        'EVENT',
    ];

    private static function assertDedicatedReady(PDO $account, string $name, string $liveName): void
    {
        if ($name === $liveName) {
            throw new RuntimeException('RESTORE_TARGET_REFUSED');
        }
        $statement = $account->prepare(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :name'
        );
        $statement->execute(['name' => $name]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('RESTORE_DATABASE_UNAVAILABLE');
        }
        $lines = self::grantLines($account);
        if ($lines === [] || !self::grantsAllowDedicatedDatabase($lines, $name)) {
            throw new RuntimeException('RESTORE_TARGET_PRIVILEGE_ABSENT');
        }
        if (self::schemaObjectCount($account, $name) !== 0) {
            throw new RuntimeException('RESTORE_TARGET_OCCUPIED');
        }
    }

    /**
     * @return list<string>
     */
    private static function grantLines(PDO $account): array
    {
        try {
            $statement = $account->query('SHOW GRANTS FOR CURRENT_USER()');
            if ($statement === false) {
                return [];
            }
            $lines = $statement->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $exception) {
            return [];
        }
        $grants = [];
        foreach ($lines as $line) {
            if (is_string($line) && $line !== '') {
                $grants[] = $line;
            }
        }

        return $grants;
    }

    /**
     * @param list<string> $lines
     */
    private static function grantsAllowDedicatedDatabase(array $lines, string $database): bool
    {
        foreach ($lines as $line) {
            $tokens = self::privilegeTokensOnDatabase($line, $database);
            if ($tokens === null) {
                continue;
            }
            if (in_array('ALL PRIVILEGES', $tokens, true) || in_array('ALL', $tokens, true)) {
                return true;
            }
            if (array_diff(self::DEDICATED_PRIVILEGES, $tokens) === []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null
     */
    private static function privilegeTokensOnDatabase(string $line, string $database): ?array
    {
        $patterns = [
            '/^GRANT\s+(.+?)\s+ON\s+`([^`]+)`\.\*\s+TO\s+/i',
            '/^GRANT\s+(.+?)\s+ON\s+([A-Za-z0-9_]+)\.\*\s+TO\s+/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line, $match) === 1 && $match[2] === $database) {
                return self::privilegeTokens($match[1]);
            }
        }

        return null;
    }

    private static function schemaObjectCount(PDO $pdo, string $schema): int
    {
        $queries = [
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema',
            'SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :schema',
            'SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :schema',
            'SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = :schema',
        ];
        $total = 0;
        foreach ($queries as $sql) {
            $statement = $pdo->prepare($sql);
            $statement->execute(['schema' => $schema]);
            $total += (int) $statement->fetchColumn();
        }

        return $total;
    }

    private static function clearDedicatedDatabase(PDO $target, string $liveName): bool
    {
        try {
            $current = self::currentDatabase($target);
            $configured = self::configuredRestoreName();
            if ($configured === '' || $current !== $configured || $current === $liveName) {
                return false;
            }
            $target->exec('SET FOREIGN_KEY_CHECKS=0');
            self::dropIdentified(
                $target,
                'DROP TRIGGER %s',
                self::schemaNames($target, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :schema', $current)
            );
            self::dropIdentified(
                $target,
                'DROP VIEW %s',
                self::schemaNames($target, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'VIEW'", $current)
            );
            self::dropIdentified(
                $target,
                'DROP TABLE %s',
                self::schemaNames($target, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_TYPE = 'BASE TABLE'", $current)
            );
            $routines = $target->prepare(
                'SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = :schema'
            );
            $routines->execute(['schema' => $current]);
            foreach ($routines->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $type = (string) ($row['ROUTINE_TYPE'] ?? '');
                if ($type !== 'PROCEDURE' && $type !== 'FUNCTION') {
                    return false;
                }
                $target->exec('DROP ' . $type . ' ' . self::ident((string) $row['ROUTINE_NAME']));
            }
            self::dropIdentified(
                $target,
                'DROP EVENT %s',
                self::schemaNames($target, 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = :schema', $current)
            );
            $target->exec('SET FOREIGN_KEY_CHECKS=1');

            return self::schemaObjectCount($target, $current) === 0;
        } catch (Throwable $exception) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private static function schemaNames(PDO $pdo, string $sql, string $schema): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(['schema' => $schema]);
        $names = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (!is_string($name) || $name === '') {
                throw new RuntimeException('BACKUP_IDENTIFIER_REJECTED');
            }
            $names[] = self::bareIdent($name);
        }

        return $names;
    }

    /**
     * @param list<string> $names
     */
    private static function dropIdentified(PDO $pdo, string $template, array $names): void
    {
        foreach ($names as $name) {
            $pdo->exec(sprintf($template, self::ident($name)));
        }
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

    /** Uygulama içi "Yedek Al" dosya öneki; migration kanıt dosyalarından (medisa-full-*) ayrıdır. */
    public const APP_BACKUP_PREFIX = 'medisa-yedek-';
    public const APP_BACKUP_KEEP = 7;

    /**
     * Uygulama içi tam mantıksal yedek (Ayarlar > Veri Yedekleme > Yedek Al).
     * Tutarlı anlık görüntü + web kökü dışında dosya + sha256 okuma doğrulaması.
     * Geri yükleme yapmaz, ikinci veritabanı veya CREATE/DROP DATABASE gerektirmez.
     * Migration 100 kapısının kanıt dosyasına (full-backup-evidence.json) dokunmaz.
     *
     * @return array{file: string, sha256: string, bytes: int, created_at: string, ledger_tip: string, table_count: int, total_rows: int}
     */
    public static function createAppBackup(PDO $live, string $apiDirectory, string $requestId): array
    {
        $directory = MigrationBackupService::operationsDirectory($apiDirectory);
        $tip = self::ledgerTip($live);
        $fileName = self::APP_BACKUP_PREFIX . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sql';
        $path = $directory . DIRECTORY_SEPARATOR . $fileName;
        $snapshot = false;
        try {
            $live->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $live->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $snapshot = true;
            $inventory = self::inventory($live);
            self::writeDump($live, $path, $requestId, $tip, $inventory);
            $live->commit();
            $snapshot = false;
            $readback = self::verifyFile($path);
        } catch (Throwable $exception) {
            if ($snapshot && $live->inTransaction()) {
                $live->rollBack();
            }
            if (is_file($path)) {
                @unlink($path);
            }
            throw $exception instanceof RuntimeException ? $exception : new RuntimeException('APP_BACKUP_FAILED');
        }

        $meta = [
            'file' => $fileName,
            'sha256' => $readback['sha256'],
            'bytes' => $readback['bytes'],
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'ledger_tip' => $tip,
            'table_count' => count($inventory['tables']),
            'total_rows' => array_sum($inventory['row_counts']),
        ];
        @file_put_contents($path . '.meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
        @chmod($path . '.meta.json', 0600);
        self::pruneAppBackups($directory, self::APP_BACKUP_KEEP);

        return $meta;
    }

    /** @return list<array<string, mixed>> en yeni önce */
    public static function listAppBackups(string $apiDirectory): array
    {
        $directory = MigrationBackupService::operationsDirectory($apiDirectory);
        $items = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . self::APP_BACKUP_PREFIX . '*.sql.meta.json') ?: [] as $metaPath) {
            $raw = @file_get_contents($metaPath);
            $meta = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($meta) || !self::isAppBackupName((string) ($meta['file'] ?? ''))) {
                continue;
            }
            if (!is_file($directory . DIRECTORY_SEPARATOR . $meta['file'])) {
                continue;
            }
            $items[] = $meta;
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['file'], (string) $a['file']));

        return $items;
    }

    public static function isAppBackupName(string $name): bool
    {
        return preg_match('/^medisa-yedek-\d{8}-\d{6}-[0-9a-f]{6}\.sql$/', $name) === 1;
    }

    /**
     * İndirilecek yedeğin mutlak yolu; ad kalıbı, dizin içi olma ve sha256 eşleşmesi zorunlu.
     */
    public static function resolveAppBackupForDownload(string $apiDirectory, string $name): string
    {
        if (!self::isAppBackupName($name)) {
            throw new RuntimeException('APP_BACKUP_NOT_FOUND');
        }
        $directory = MigrationBackupService::operationsDirectory($apiDirectory);
        $realDirectory = realpath($directory);
        $realFile = realpath($directory . DIRECTORY_SEPARATOR . $name);
        if ($realDirectory === false || $realFile === false || !self::pathInside($realFile, $realDirectory) || !is_file($realFile)) {
            throw new RuntimeException('APP_BACKUP_NOT_FOUND');
        }
        $raw = @file_get_contents($realFile . '.meta.json');
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        $hash = hash_file('sha256', $realFile);
        if (!is_array($meta) || !is_string($hash) || !hash_equals((string) ($meta['sha256'] ?? ''), $hash)) {
            throw new RuntimeException('APP_BACKUP_SHA_MISMATCH');
        }

        return $realFile;
    }

    private static function pruneAppBackups(string $directory, int $keep): void
    {
        $files = glob($directory . DIRECTORY_SEPARATOR . self::APP_BACKUP_PREFIX . '*.sql') ?: [];
        $files = array_values(array_filter($files, static fn (string $f): bool => self::isAppBackupName(basename($f))));
        rsort($files, SORT_STRING);
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            @unlink($old . '.meta.json');
        }
    }
}
