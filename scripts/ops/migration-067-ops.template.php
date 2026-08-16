<?php
/**
 * Temporary, authenticated Migration 067 production operations endpoint.
 *
 * This endpoint is uploaded only for one workflow run and must be removed
 * before the workflow completes. It never applies a migration.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

const MIGRATION_067_EXPECTED_DATABASE = 'karmotor_medisa';
const MIGRATION_067_SOURCE_SHA256 = 'afa8e99867b9c670af9f8ab84a814d72231f602fa2cd01e3f8d73c06cdb8c5b9';
const MIGRATION_067_RUNTIME_TOKEN_B64 = 'REPLACE_MIGRATION_067_RUNTIME_TOKEN_B64';
const MIGRATION_067_SOURCE_FILE_B64 = 'REPLACE_MIGRATION_067_SOURCE_FILE_B64';
const MIGRATION_067_BACKUP_ROOT_B64 = 'REPLACE_MIGRATION_067_BACKUP_ROOT_B64';
const MIGRATION_067_TEMPLATE_PLACEHOLDER = 'REPLACE_MIGRATION_067_';

function migration_067_rendered_value(string $encoded): string
{
    if (str_starts_with($encoded, MIGRATION_067_TEMPLATE_PLACEHOLDER)) {
        return '';
    }
    $decoded = base64_decode($encoded, true);

    return $decoded === false ? '' : $decoded;
}

function migration_067_fail(string $error, int $status = 500): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function migration_067_token_is_valid(string $expected, string $provided): bool
{
    return $expected !== ''
        && $provided !== ''
        && hash_equals($expected, $provided);
}

function migration_067_request_token(): string
{
    $header = isset($_SERVER['HTTP_AUTHORIZATION']) ? trim((string) $_SERVER['HTTP_AUTHORIZATION']) : '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
        return trim($matches[1]);
    }

    return isset($_SERVER['HTTP_X_MIGRATION_067_TOKEN'])
        ? trim((string) $_SERVER['HTTP_X_MIGRATION_067_TOKEN'])
        : '';
}

function migration_067_config(): array
{
    $configPath = dirname(__DIR__) . '/src/Config/config.php';
    if (!is_file($configPath)) {
        migration_067_fail('CONFIG_MISSING');
    }
    require_once $configPath;
    $config = medisa_config();

    if (!is_array($config) || ($config['app_env'] ?? '') !== 'production') {
        migration_067_fail('PRODUCTION_GUARD_FAILED', 403);
    }

    return $config;
}

function migration_067_database(): PDO
{
    $config = migration_067_config();
    $host = (string) ($config['db_host'] ?? '');
    $name = (string) ($config['db_name'] ?? '');
    $user = (string) ($config['db_user'] ?? '');
    $password = (string) ($config['db_password'] ?? '');
    if ($host === '' || $name === '' || $user === '' || $password === '') {
        migration_067_fail('DB_CONFIG_INCOMPLETE');
    }

    try {
        $pdo = new PDO(
            'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($dbName !== MIGRATION_067_EXPECTED_DATABASE) {
            migration_067_fail('DATABASE_TARGET_GUARD_FAILED', 403);
        }
        return $pdo;
    } catch (Throwable $exception) {
        migration_067_fail('DB_CONNECT_FAILED');
    }
}

function migration_067_backup_root(): string
{
    $configured = getenv('MIGRATION_067_BACKUP_ROOT');
    $root = migration_067_rendered_value(MIGRATION_067_BACKUP_ROOT_B64);
    if ($root === '' && is_string($configured) && trim($configured) !== '') {
        $root = trim($configured);
    }
    if ($root === '') {
        migration_067_fail('BACKUP_ROOT_REQUIRED', 403);
    }
    if ($root === '' || !str_starts_with($root, DIRECTORY_SEPARATOR)) {
        migration_067_fail('BACKUP_PATH_NOT_ABSOLUTE');
    }

    $publicRoot = realpath(__DIR__) ?: __DIR__;
    $applicationRoot = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    $temporaryRoot = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
    $candidate = rtrim($root, DIRECTORY_SEPARATOR);
    $candidateParent = realpath(dirname($candidate)) ?: dirname($candidate);
    $candidateReal = realpath($candidate) ?: $candidateParent . DIRECTORY_SEPARATOR . basename($candidate);
    $comparison = strtolower(str_replace('\\', '/', $candidateReal));
    $publicComparison = strtolower(str_replace('\\', '/', $publicRoot));
    $parentComparison = strtolower(str_replace('\\', '/', $candidateParent));
    $applicationComparison = strtolower(str_replace('\\', '/', $applicationRoot));
    $temporaryComparison = strtolower(str_replace('\\', '/', $temporaryRoot));
    if (
        $comparison === $publicComparison
        || str_starts_with($comparison, $publicComparison . '/')
        || $parentComparison === $publicComparison
        || str_starts_with($parentComparison, $publicComparison . '/')
        || $comparison === $applicationComparison
        || str_starts_with($comparison, $applicationComparison . '/')
        || $parentComparison === $applicationComparison
        || str_starts_with($parentComparison, $applicationComparison . '/')
        || $comparison === $temporaryComparison
        || str_starts_with($comparison, $temporaryComparison . '/')
        || $parentComparison === $temporaryComparison
        || str_starts_with($parentComparison, $temporaryComparison . '/')
        || ($documentRoot !== false
            && (
                $comparison === strtolower(str_replace('\\', '/', $documentRoot))
                || str_starts_with($comparison, strtolower(str_replace('\\', '/', $documentRoot)) . '/')
                || $parentComparison === strtolower(str_replace('\\', '/', $documentRoot))
                || str_starts_with($parentComparison, strtolower(str_replace('\\', '/', $documentRoot)) . '/')
            ))
    ) {
        migration_067_fail('BACKUP_PATH_NOT_PERSISTENT_PRIVATE');
    }
    if (is_link($candidate)) {
        migration_067_fail('BACKUP_PATH_SYMLINK');
    }
    if (!is_dir($candidate) && !mkdir($candidate, 0700, true) && !is_dir($candidate)) {
        migration_067_fail('BACKUP_PATH_UNAVAILABLE');
    }
    if (!@chmod($candidate, 0700) || ((fileperms($candidate) & 0777) & 0077) !== 0 || !is_writable($candidate)) {
        migration_067_fail('BACKUP_PATH_NOT_WRITABLE');
    }

    return $candidate;
}

function migration_067_sql_value($value, string $columnType): string
{
    if ($value === null) {
        return 'NULL';
    }
    $numericTypes = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'];
    $binaryTypes = ['bit', 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];
    if (in_array(strtolower($columnType), $numericTypes, true) || is_int($value) || is_float($value)) {
        return (string) $value;
    }
    $hex = strtoupper(bin2hex((string) $value));
    if (in_array(strtolower($columnType), $binaryTypes, true)) {
        return '0x' . $hex;
    }

    return 'CONVERT(0x' . $hex . ' USING utf8mb4)';
}

function migration_067_strip_definer(string $statement): string
{
    $clean = preg_replace(
        '/\sDEFINER\s*=\s*(?:`[^`]*`@`[^`]*`|\'[^\']*\'@\'[^\']*\'|[^\s]+)\s*/i',
        ' ',
        $statement
    );

    return is_string($clean) ? $clean : $statement;
}

function migration_067_write($handle, string $content): void
{
    $length = strlen($content);
    if ($length > 0 && fwrite($handle, $content) !== $length) {
        throw new RuntimeException('BACKUP_WRITE_FAILED');
    }
}

function migration_067_php_dump(PDO $pdo, string $path): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        migration_067_fail('BACKUP_OPEN_FAILED');
    }
    if (!@chmod($path, 0600)) {
        fclose($handle);
        throw new RuntimeException('BACKUP_FILE_NOT_PRIVATE');
    }
    try {
        migration_067_write($handle, "-- Migration 067 full SQL backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $objects = $pdo->query(
            "SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        )->fetchAll();
        foreach ($objects as $object) {
            $table = (string) $object['TABLE_NAME'];
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            try {
                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $exception) {
                throw new RuntimeException('TABLE_DEFINITION_UNREADABLE', 0, $exception);
            }
            $createSql = (string) ($create['Create Table'] ?? '');
            if ($createSql === '') {
                throw new RuntimeException('CREATE statement missing');
            }
            $createSql = migration_067_strip_definer($createSql);
            migration_067_write($handle, $createSql . ";\n\n");
            $columns = $pdo->prepare(
                "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
                 ORDER BY ORDINAL_POSITION"
            );
            $columns->execute(['table' => $table]);
            $columnTypes = [];
            foreach ($columns->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columnTypes[(string) $column['COLUMN_NAME']] = (string) $column['DATA_TYPE'];
            }
            try {
                $rows = $pdo->query('SELECT * FROM ' . $quoted);
            } catch (Throwable $exception) {
                throw new RuntimeException('TABLE_DATA_UNREADABLE', 0, $exception);
            }
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($row as $column => $value) {
                    $values[] = migration_067_sql_value($value, $columnTypes[$column] ?? '');
                }
                migration_067_write($handle, 'INSERT INTO ' . $quoted . ' VALUES (' . implode(', ', $values) . ");\n");
            }
            migration_067_write($handle, "\n");
        }

        $views = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($views as $view) {
            $quoted = '`' . str_replace('`', '``', (string) $view) . '`';
            try {
                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $exception) {
                throw new RuntimeException('VIEW_DEFINITION_UNREADABLE', 0, $exception);
            }
            $createSql = (string) ($create['Create View'] ?? '');
            if ($createSql === '') {
                throw new RuntimeException('VIEW_DEFINITION_UNREADABLE');
            }
            $createSql = migration_067_strip_definer($createSql);
            migration_067_write($handle, $createSql . ";\n\n");
        }

        $triggers = $pdo->query(
            "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($triggers as $trigger) {
            try {
                $row = $pdo->query(
                    'SHOW CREATE TRIGGER `' . str_replace('`', '``', (string) $trigger) . '`'
                )->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $exception) {
                throw new RuntimeException('TRIGGER_DEFINITION_UNREADABLE', 0, $exception);
            }
            $statement = (string) ($row['SQL Original Statement'] ?? '');
            if ($statement === '') {
                throw new RuntimeException('TRIGGER_DEFINITION_UNREADABLE');
            }
            $statement = migration_067_strip_definer($statement);
            migration_067_write($handle, "DELIMITER $$\n" . $statement . " $$\nDELIMITER ;\n\n");
        }

        foreach (['PROCEDURE', 'FUNCTION'] as $routineType) {
            $routines = $pdo->prepare(
                'SELECT ROUTINE_NAME FROM information_schema.ROUTINES
                 WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_TYPE = :type ORDER BY ROUTINE_NAME'
            );
            $routines->execute(['type' => $routineType]);
            foreach ($routines->fetchAll(PDO::FETCH_COLUMN) as $routine) {
                try {
                    $row = $pdo->query(
                        'SHOW CREATE ' . $routineType . ' `' . str_replace('`', '``', (string) $routine) . '`'
                    )->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $exception) {
                    throw new RuntimeException('ROUTINE_DEFINITION_UNREADABLE', 0, $exception);
                }
                $key = 'Create ' . ucfirst(strtolower($routineType));
                $statement = (string) ($row[$key] ?? '');
                if ($statement === '') {
                    throw new RuntimeException('ROUTINE_DEFINITION_UNREADABLE');
                }
                $statement = migration_067_strip_definer($statement);
                migration_067_write($handle, "DELIMITER $$\n" . $statement . " $$\nDELIMITER ;\n\n");
            }
        }

        $events = $pdo->query(
            "SELECT EVENT_NAME FROM information_schema.EVENTS
             WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($events as $event) {
            try {
                $row = $pdo->query(
                    'SHOW CREATE EVENT `' . str_replace('`', '``', (string) $event) . '`'
                )->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $exception) {
                throw new RuntimeException('EVENT_DEFINITION_UNREADABLE', 0, $exception);
            }
            $statement = (string) ($row['Create Event'] ?? '');
            if ($statement === '') {
                throw new RuntimeException('EVENT_DEFINITION_UNREADABLE');
            }
            $statement = migration_067_strip_definer($statement);
            migration_067_write($handle, "DELIMITER $$\n" . $statement . " $$\nDELIMITER ;\n\n");
        }
        migration_067_write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($handle);
    }
}

function migration_067_backup_inventory(PDO $pdo): array
{
    $baseTables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $views = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $routines = $pdo->query(
        "SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES
         WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME"
    )->fetchAll(PDO::FETCH_ASSOC);
    $events = $pdo->query(
        "SELECT EVENT_NAME FROM information_schema.EVENTS
         WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $rowCounts = [];
    foreach ($baseTables as $table) {
        $quoted = '`' . str_replace('`', '``', (string) $table) . '`';
        $rowCounts[(string) $table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn();
    }

    return [
        'base_tables' => array_map('strval', $baseTables),
        'views' => array_map('strval', $views),
        'triggers' => array_map('strval', $triggers),
        'routines' => array_map(
            static fn(array $routine): string => (string) $routine['ROUTINE_TYPE'] . ':' . (string) $routine['ROUTINE_NAME'],
            $routines
        ),
        'events' => array_map('strval', $events),
        'row_counts' => $rowCounts,
    ];
}

function migration_067_backup_contains(string $path, string $needle): bool
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $carry = '';
    try {
        while (!feof($handle)) {
            $chunk = $carry . (string) fread($handle, 262144);
            if (stripos($chunk, $needle) !== false) {
                return true;
            }
            $carry = substr($chunk, -strlen($needle));
        }
    } finally {
        fclose($handle);
    }
    return false;
}

function migration_067_validate_backup(string $path, array $inventory): array
{
    if (!is_file($path) || filesize($path) <= 0) {
        throw new RuntimeException('BACKUP_EMPTY');
    }
    $sample = file_get_contents($path, false, null, 0, 262144);
    if ($sample === false || stripos($sample, '<html') !== false || stripos($sample, 'login') !== false) {
        throw new RuntimeException('BACKUP_NOT_SQL');
    }
    foreach (['CREATE TABLE', 'departmanlar', 'bolumler', 'birimler', 'personeller'] as $required) {
        if (!migration_067_backup_contains($path, $required)) {
            throw new RuntimeException('BACKUP_STRUCTURE_INVALID');
        }
    }
    foreach ($inventory['base_tables'] as $table) {
        if (!migration_067_backup_contains($path, (string) $table)) {
            throw new RuntimeException('BACKUP_TABLE_INVENTORY_MISMATCH');
        }
    }
    foreach ($inventory['views'] as $view) {
        if (!migration_067_backup_contains($path, 'CREATE VIEW') || !migration_067_backup_contains($path, (string) $view)) {
            throw new RuntimeException('BACKUP_VIEW_INVENTORY_MISMATCH');
        }
    }
    foreach ($inventory['triggers'] as $trigger) {
        if (!migration_067_backup_contains($path, 'TRIGGER') || !migration_067_backup_contains($path, (string) $trigger)) {
            throw new RuntimeException('BACKUP_TRIGGER_INVENTORY_MISMATCH');
        }
    }
    foreach ($inventory['routines'] as $routine) {
        [$routineType, $routineName] = explode(':', (string) $routine, 2);
        if (!migration_067_backup_contains($path, 'CREATE ' . $routineType)
            || !migration_067_backup_contains($path, $routineName)) {
            throw new RuntimeException('BACKUP_ROUTINE_INVENTORY_MISMATCH');
        }
    }
    foreach ($inventory['events'] as $event) {
        if (!migration_067_backup_contains($path, 'CREATE EVENT')
            || !migration_067_backup_contains($path, (string) $event)) {
            throw new RuntimeException('BACKUP_EVENT_INVENTORY_MISMATCH');
        }
    }
    $publicRoot = realpath(__DIR__) ?: __DIR__;
    $backupReal = realpath($path) ?: $path;
    $backupParent = realpath(dirname($path)) ?: dirname($path);
    $configuredRoot = realpath(migration_067_backup_root());
    $publicComparison = strtolower(str_replace('\\', '/', $publicRoot));
    $backupComparison = strtolower(str_replace('\\', '/', $backupReal));
    $parentComparison = strtolower(str_replace('\\', '/', $backupParent));
    $rootComparison = strtolower(str_replace('\\', '/', $configuredRoot ?: ''));
    if (str_starts_with($backupComparison, $publicComparison . '/')) {
        throw new RuntimeException('BACKUP_PATH_INSIDE_WEBROOT');
    }
    if ($rootComparison === '' || $parentComparison !== $rootComparison) {
        throw new RuntimeException('BACKUP_PATH_NOT_PERSISTENT_PRIVATE');
    }

    return [
        'backup_created' => true,
        'backup_filename' => basename($path),
        'backup_size_bytes' => (int) filesize($path),
        'backup_sha256' => hash_file('sha256', $path),
        'backup_location_class' => 'OUTSIDE_WEBROOT_PERSISTENT',
        'backup_table_count' => count($inventory['base_tables']),
        'backup_view_count' => count($inventory['views']),
        'backup_trigger_count' => count($inventory['triggers']),
        'backup_routine_count' => count($inventory['routines']),
        'backup_event_count' => count($inventory['events']),
    ];
}

function migration_067_backup(PDO $pdo): array
{
    $root = migration_067_backup_root();
    $filename = 'karmotor_medisa_pre_067_' . gmdate('Ymd_His') . '.sql';
    $path = $root . DIRECTORY_SEPARATOR . $filename;
    $temporaryPath = $path . '.part';
    @unlink($temporaryPath);

    $dumpPath = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    $config = migration_067_config();
    try {
        $inventory = migration_067_backup_inventory($pdo);
        if ($dumpPath !== '') {
            $command = escapeshellarg($dumpPath)
                . ' --single-transaction --routines --events --triggers --hex-blob'
                . ' -h ' . escapeshellarg((string) $config['db_host'])
                . ' -u ' . escapeshellarg((string) $config['db_user'])
                . ' ' . escapeshellarg((string) $config['db_name'])
                . ' > ' . escapeshellarg($temporaryPath) . ' 2>/dev/null';
            $oldPassword = getenv('MYSQL_PWD');
            putenv('MYSQL_PWD=' . (string) $config['db_password']);
            exec($command, $unusedOutput, $exitCode);
            if ($oldPassword === false) {
                putenv('MYSQL_PWD');
            } else {
                putenv('MYSQL_PWD=' . $oldPassword);
            }
            if ($exitCode !== 0) {
                throw new RuntimeException('BACKUP_MYSQLDUMP_FAILED');
            }
        } else {
            migration_067_php_dump($pdo, $temporaryPath);
        }
        if (!is_file($temporaryPath)
            || !@chmod($temporaryPath, 0600)
            || ((fileperms($temporaryPath) & 0777) & 0077) !== 0) {
            throw new RuntimeException('BACKUP_FILE_NOT_PRIVATE');
        }
        if (!is_file($temporaryPath) || filesize($temporaryPath) <= 0 || !rename($temporaryPath, $path)) {
            throw new RuntimeException('BACKUP_FINALIZE_FAILED');
        }
        if (!@chmod($path, 0600) || ((fileperms($path) & 0777) & 0077) !== 0) {
            throw new RuntimeException('BACKUP_FILE_NOT_PRIVATE');
        }
        return migration_067_validate_backup($path, $inventory);
    } catch (Throwable $exception) {
        @unlink($temporaryPath);
        @unlink($path);
        $safeErrors = [
            'BACKUP_MYSQLDUMP_FAILED',
            'BACKUP_INVENTORY_UNREADABLE',
            'TABLE_DEFINITION_UNREADABLE',
            'TABLE_DATA_UNREADABLE',
            'VIEW_DEFINITION_UNREADABLE',
            'TRIGGER_DEFINITION_UNREADABLE',
            'ROUTINE_DEFINITION_UNREADABLE',
            'EVENT_DEFINITION_UNREADABLE',
            'BACKUP_WRITE_FAILED',
            'BACKUP_FILE_NOT_PRIVATE',
        ];
        $error = in_array($exception->getMessage(), $safeErrors, true)
            ? $exception->getMessage()
            : 'BACKUP_VALIDATION_FAILED';
        migration_067_fail($error);
    }
}

function migration_067_precheck(PDO $pdo): array
{
    $select = static function (PDO $pdo, string $sql): array {
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    };
    $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $requiredTables = $select(
        $pdo,
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('departmanlar', 'bolumler', 'birimler', 'personeller')
         ORDER BY TABLE_NAME"
    );
    if (count($requiredTables) !== 4) {
        return ['ok' => true, 'DB_NAME' => $dbName, 'classification' => 'BELOW_066_OR_DRIFT'];
    }
    $schemaRows = $select(
        $pdo,
        "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('departmanlar', 'bolumler', 'birimler', 'personeller')
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $requiredColumns = [
        'departmanlar' => ['id', 'ad', 'durum'],
        'bolumler' => ['id', 'departman_id', 'ad', 'durum'],
        'birimler' => ['id', 'ad', 'bolum_id', 'durum'],
        'personeller' => ['bolum_id', 'birim_id'],
    ];
    $availableColumns = [];
    foreach ($schemaRows as $schemaRow) {
        $availableColumns[(string) $schemaRow['TABLE_NAME']][] = (string) $schemaRow['COLUMN_NAME'];
    }
    foreach ($requiredColumns as $table => $columns) {
        if (array_diff($columns, $availableColumns[$table] ?? []) !== []) {
            return [
                'ok' => true,
                'DB_NAME' => $dbName,
                'SCHEMA_066_FINGERPRINT' => hash('sha256', json_encode($schemaRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'classification' => 'BELOW_066_OR_DRIFT',
            ];
        }
    }
    $fingerprint = hash('sha256', json_encode($schemaRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $departman = $select($pdo, "SELECT id, ad, durum FROM departmanlar WHERE id = 1");
    $bolum3 = $select($pdo, "SELECT id, departman_id, ad, durum FROM bolumler WHERE id = 3");
    $bolum5 = $select($pdo, "SELECT id, departman_id, ad, durum FROM bolumler WHERE id = 5");
    $birim10 = $select($pdo, "SELECT id, ad, bolum_id, durum FROM birimler WHERE id = 10");
    $counts = $select(
        $pdo,
        "SELECT
          (SELECT COUNT(*) FROM birimler WHERE ad = 'Güvenlik' AND durum = 'AKTIF' AND id <> 10) AS duplicate_active_guvenlik_count,
          (SELECT COUNT(*) FROM birimler WHERE bolum_id = 5 AND durum = 'AKTIF' AND id <> 10) AS legacy_active_child_count,
          (SELECT COUNT(*) FROM personeller WHERE bolum_id = 5) AS personel_bolum5_count,
          (SELECT COUNT(*) FROM personeller WHERE birim_id = 10) AS personel_birim10_count"
    )[0];
    $departmanExact = count($departman) === 1
        && $departman[0]['ad'] === 'Üretim'
        && $departman[0]['durum'] === 'AKTIF';
    $bolum3Exact = count($bolum3) === 1
        && (int) $bolum3[0]['departman_id'] === 1
        && $bolum3[0]['ad'] === 'Üretim'
        && $bolum3[0]['durum'] === 'AKTIF';
    $legacyBolum5Exact = count($bolum5) === 1
        && (int) $bolum5[0]['departman_id'] === 1
        && $bolum5[0]['ad'] === 'Üretim Genel'
        && $bolum5[0]['durum'] === 'AKTIF';
    $canonicalBolum5Exact = count($bolum5) === 1
        && (int) $bolum5[0]['departman_id'] === 1
        && $bolum5[0]['ad'] === 'Üretim Genel'
        && $bolum5[0]['durum'] === 'PASIF';
    $legacyBirim10Exact = count($birim10) === 1
        && $birim10[0]['ad'] === 'Güvenlik'
        && (int) $birim10[0]['bolum_id'] === 5
        && $birim10[0]['durum'] === 'AKTIF';
    $canonicalBirim10Exact = count($birim10) === 1
        && $birim10[0]['ad'] === 'Güvenlik'
        && (int) $birim10[0]['bolum_id'] === 3
        && $birim10[0]['durum'] === 'AKTIF';
    $safeCounts = (int) $counts['duplicate_active_guvenlik_count'] === 0
        && (int) $counts['legacy_active_child_count'] === 0;
    $legacyExact = $departmanExact && $bolum3Exact && $legacyBolum5Exact && $legacyBirim10Exact
        && $safeCounts
        && (int) $counts['personel_bolum5_count'] === 0
        && (int) $counts['personel_birim10_count'] === 0;
    $canonicalExact = $departmanExact && $bolum3Exact && $canonicalBolum5Exact
        && $canonicalBirim10Exact && $safeCounts;
    return [
        'ok' => true,
        'DB_NAME' => $dbName,
        'SCHEMA_066_FINGERPRINT' => $fingerprint,
        'DEPARTMAN_1' => $departman,
        'BOLUM_3' => $bolum3,
        'BOLUM_5' => $bolum5,
        'BIRIM_10' => $birim10,
        'DUPLICATE_ACTIVE_GUVENLIK_COUNT' => (int) $counts['duplicate_active_guvenlik_count'],
        'LEGACY_ACTIVE_CHILD_COUNT' => (int) $counts['legacy_active_child_count'],
        'PERSONEL_BOLUM5_COUNT' => (int) $counts['personel_bolum5_count'],
        'PERSONEL_BIRIM10_COUNT' => (int) $counts['personel_birim10_count'],
        'classification' => $legacyExact ? 'LEGACY_EXACT' : ($canonicalExact ? 'CANONICAL_EXACT' : 'DRIFT'),
    ];
}

if (!defined('MIGRATION_067_TEST_IMPORT')) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        migration_067_fail('METHOD_NOT_ALLOWED', 405);
    }
    $config = migration_067_config();
    $renderedToken = migration_067_rendered_value(MIGRATION_067_RUNTIME_TOKEN_B64);
    $expectedToken = $renderedToken !== ''
        ? $renderedToken
        : (string) ($config['migration_067_runtime_token'] ?? getenv('MIGRATION_067_RUNTIME_TOKEN') ?: '');
    if (!migration_067_token_is_valid($expectedToken, migration_067_request_token())) {
        migration_067_fail('FORBIDDEN', 403);
    }
    $action = trim((string) ($_POST['action'] ?? ''));
    if (!in_array($action, ['backup', 'precheck'], true)) {
        migration_067_fail('ACTION_NOT_SUPPORTED', 400);
    }
    $pdo = migration_067_database();
    $sourceFile = migration_067_rendered_value(MIGRATION_067_SOURCE_FILE_B64);
    if ($sourceFile === ''
        || basename($sourceFile) !== $sourceFile
        || preg_match('/^067_personel_canonical_reference_gate_[0-9]+_[0-9]+\.sql$/', $sourceFile) !== 1) {
        migration_067_fail('SOURCE_FILE_INVALID', 500);
    }
    $sourcePath = dirname(__DIR__) . '/migrations/' . $sourceFile;
    if (!is_file($sourcePath) || !hash_equals(MIGRATION_067_SOURCE_SHA256, hash_file('sha256', $sourcePath))) {
        migration_067_fail('MIGRATION_SOURCE_HASH_FAILED', 500);
    }
    $result = $action === 'backup' ? migration_067_backup($pdo) : migration_067_precheck($pdo);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
