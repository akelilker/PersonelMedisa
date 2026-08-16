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
const MIGRATION_067_SOURCE_FILE = 'REPLACE_MIGRATION_067_SOURCE_FILE';
const MIGRATION_067_TOKEN_PLACEHOLDER = 'REPLACE_MIGRATION_067_OPS_TOKEN';

function migration_067_fail(string $error, int $status = 500): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

function migration_067_token_is_valid(string $expected, string $provided): bool
{
    return $expected !== ''
        && $expected !== MIGRATION_067_TOKEN_PLACEHOLDER
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
    $root = is_string($configured) && trim($configured) !== ''
        ? trim($configured)
        : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'personelmedisa-migration-067';
    if ($root === '' || !str_starts_with($root, DIRECTORY_SEPARATOR)) {
        migration_067_fail('BACKUP_PATH_NOT_ABSOLUTE');
    }

    $publicRoot = realpath(__DIR__) ?: __DIR__;
    $candidate = rtrim($root, DIRECTORY_SEPARATOR);
    $candidateParent = realpath(dirname($candidate)) ?: dirname($candidate);
    $candidateReal = realpath($candidate) ?: $candidateParent . DIRECTORY_SEPARATOR . basename($candidate);
    $comparison = strtolower(str_replace('\\', '/', $candidateReal));
    $publicComparison = strtolower(str_replace('\\', '/', $publicRoot));
    $parentComparison = strtolower(str_replace('\\', '/', $candidateParent));
    if (
        $comparison === $publicComparison
        || str_starts_with($comparison, $publicComparison . '/')
        || $parentComparison === $publicComparison
        || str_starts_with($parentComparison, $publicComparison . '/')
    ) {
        migration_067_fail('BACKUP_PATH_INSIDE_WEBROOT');
    }
    if (!is_dir($candidate) && !mkdir($candidate, 0700, true) && !is_dir($candidate)) {
        migration_067_fail('BACKUP_PATH_UNAVAILABLE');
    }

    return $candidate;
}

function migration_067_sql_value($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $value) . "'";
}

function migration_067_php_dump(PDO $pdo, string $path): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        migration_067_fail('BACKUP_OPEN_FAILED');
    }
    try {
        fwrite($handle, "-- Migration 067 full SQL backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $objects = $pdo->query(
            "SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME"
        )->fetchAll();
        foreach ($objects as $object) {
            $table = (string) $object['TABLE_NAME'];
            $quoted = '`' . str_replace('`', '``', $table) . '`';
            $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_ASSOC);
            $createSql = (string) ($create['Create Table'] ?? $create['Create View'] ?? '');
            if ($createSql === '') {
                throw new RuntimeException('CREATE statement missing');
            }
            fwrite($handle, $createSql . ";\n\n");
            if ($object['TABLE_TYPE'] !== 'VIEW') {
                $rows = $pdo->query('SELECT * FROM ' . $quoted);
                while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                    $values = implode(', ', array_map('migration_067_sql_value', $row));
                    fwrite($handle, 'INSERT INTO ' . $quoted . ' VALUES (' . $values . ");\n");
                }
                fwrite($handle, "\n");
            }
        }
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($handle);
    }
}

function migration_067_assert_php_fallback_supported(PDO $pdo): void
{
    $unsupported = (int) $pdo->query(
        "SELECT
          (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW')
          + (SELECT COUNT(*) FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = DATABASE())
          + (SELECT COUNT(*) FROM information_schema.EVENTS
             WHERE EVENT_SCHEMA = DATABASE())"
    )->fetchColumn();
    if ($unsupported > 0) {
        throw new RuntimeException('BACKUP_FALLBACK_UNSUPPORTED_OBJECTS');
    }
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

function migration_067_validate_backup(string $path): array
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
    $publicRoot = realpath(__DIR__) ?: __DIR__;
    $backupReal = realpath($path) ?: $path;
    $publicComparison = strtolower(str_replace('\\', '/', $publicRoot));
    $backupComparison = strtolower(str_replace('\\', '/', $backupReal));
    if (str_starts_with($backupComparison, $publicComparison . '/')) {
        throw new RuntimeException('BACKUP_PATH_INSIDE_WEBROOT');
    }

    return [
        'backup_created' => true,
        'backup_filename' => basename($path),
        'backup_size_bytes' => (int) filesize($path),
        'backup_sha256' => hash_file('sha256', $path),
        'backup_location_class' => 'OUTSIDE_WEBROOT',
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
            migration_067_assert_php_fallback_supported($pdo);
            migration_067_php_dump($pdo, $temporaryPath);
        }
        if (!is_file($temporaryPath) || filesize($temporaryPath) <= 0 || !rename($temporaryPath, $path)) {
            throw new RuntimeException('BACKUP_FINALIZE_FAILED');
        }
        return migration_067_validate_backup($path);
    } catch (Throwable $exception) {
        @unlink($temporaryPath);
        @unlink($path);
        $safeErrors = [
            'BACKUP_FALLBACK_UNSUPPORTED_OBJECTS',
            'BACKUP_MYSQLDUMP_FAILED',
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    migration_067_fail('METHOD_NOT_ALLOWED', 405);
}
$config = migration_067_config();
$expectedToken = (string) ($config['migration_067_runtime_token'] ?? getenv('MIGRATION_067_RUNTIME_TOKEN') ?: MIGRATION_067_TOKEN_PLACEHOLDER);
if (!migration_067_token_is_valid($expectedToken, migration_067_request_token())) {
    migration_067_fail('FORBIDDEN', 403);
}
$action = trim((string) ($_POST['action'] ?? ''));
if (!in_array($action, ['backup', 'precheck'], true)) {
    migration_067_fail('ACTION_NOT_SUPPORTED', 400);
}
$pdo = migration_067_database();
$sourcePath = dirname(__DIR__) . '/migrations/' . MIGRATION_067_SOURCE_FILE;
if (!is_file($sourcePath) || !hash_equals(MIGRATION_067_SOURCE_SHA256, hash_file('sha256', $sourcePath))) {
    migration_067_fail('MIGRATION_SOURCE_HASH_FAILED', 500);
}
$result = $action === 'backup' ? migration_067_backup($pdo) : migration_067_precheck($pdo);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
