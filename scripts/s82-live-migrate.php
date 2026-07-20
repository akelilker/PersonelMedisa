<?php
/**
 * ONE-SHOT S82 live migrate for 033/034 + read-only production acceptance.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S82_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (
    strpos($tokenExpected, 'REPLACE_') === 0
    || $tokenExpected === 'UNSET_S82_MIGRATE_TOKEN'
    || $tokenProvided === ''
    || !hash_equals($tokenExpected, $tokenProvided)
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'preflight';

/** @var array<string, string> */
const S82_MIGRATION_ALLOWLIST = [
    '033_sirket_calisma_politikalari.sql' => '4d68ce636b8f91e990f4d0588566dd270009d12a98a4e76c5aa0dd258dc03016',
    '034_bordro_onay_ve_projection.sql' => 'abb4f0bbd827e5c15771d8672a50ce8e913dc2a44c31f0e8610fd4cf7d9ebf46',
];

$configCandidates = [
    dirname(__DIR__) . '/config.local.php',
    dirname(__DIR__) . '/src/Config/config.local.php',
];
$config = null;
foreach ($configCandidates as $path) {
    if (is_file($path)) {
        $config = require $path;
        break;
    }
}
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'CONFIG_MISSING'], JSON_UNESCAPED_UNICODE);
    exit;
}

$host = (string) ($config['db_host'] ?? 'localhost');
$name = (string) ($config['db_name'] ?? '');
$user = (string) ($config['db_user'] ?? '');
$pass = (string) ($config['db_password'] ?? '');
if ($name === '' || $user === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_CONFIG_INCOMPLETE'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_CONNECT_FAILED', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

function s82_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_host' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s82_host_hint_ok(array $identity, string $configHost): bool
{
    return stripos($identity['db_host'], 'zelda.veridyen.com') !== false
        || stripos($identity['db_host'], 'zelda') !== false
        || stripos($configHost, 'zelda.veridyen.com') !== false
        || stripos($configHost, 'zelda') !== false;
}

function s82_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s82_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function s82_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $stmt->execute(['t' => $table, 'i' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

function s82_schema_state(PDO $pdo): array
{
    $m033 = s82_table_exists($pdo, 'sirket_calisma_politikalari')
        && s82_table_exists($pdo, 'sirket_calisma_politika_degerleri')
        && s82_table_exists($pdo, 'sirket_calisma_politika_auditleri');
    $m034 = s82_column_exists($pdo, 'maas_hesaplama_calistirmalari', 'bordro_onay_durumu')
        && s82_column_exists($pdo, 'maas_hesaplama_calistirmalari', 'correction_projection_hash')
        && s82_column_exists($pdo, 'maas_hesaplama_calistirmalari', 'policy_version_hash')
        && s82_column_exists($pdo, 'maas_hesaplama_adaylari', 'correction_projection_json')
        && s82_column_exists($pdo, 'maas_hesaplama_adaylari', 'bordro_onay_durumu')
        && s82_table_exists($pdo, 'personel_bordro_devir_importlari');

    return [
        'migration_033_applied' => $m033,
        'migration_034_applied' => $m034,
        'fully_applied' => $m033 && $m034,
        'indexes' => [
            'uq_scp_aktif_onayli' => s82_index_exists($pdo, 'sirket_calisma_politikalari', 'uq_scp_aktif_onayli'),
            'idx_mhc_bordro_onay' => s82_index_exists($pdo, 'maas_hesaplama_calistirmalari', 'idx_mhc_bordro_onay'),
            'idx_pbdi_sube_donem' => s82_index_exists($pdo, 'personel_bordro_devir_importlari', 'idx_pbdi_sube_donem'),
        ],
    ];
}

function s82_relevant_counts(PDO $pdo): array
{
    $tables = [
        'sirket_calisma_politikalari',
        'sirket_calisma_politika_degerleri',
        'personel_bordro_devirleri',
        'maas_hesaplama_calistirmalari',
        'maas_hesaplama_adaylari',
        'personel_bordro_devir_importlari',
        'personeller',
        'users',
    ];
    $out = [];
    foreach ($tables as $table) {
        if (!s82_table_exists($pdo, $table)) {
            $out[$table] = -1;
            continue;
        }
        try {
            $out[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
        } catch (Throwable $e) {
            $out[$table] = -1;
        }
    }

    return $out;
}

function s82_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s82_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function s82_php_sql_dump_tables(PDO $pdo, string $dbName, array $tables): string
{
    $out = [];
    $out[] = '-- S82 PHP SQL dump (relevant tables)';
    $out[] = '-- Database: ' . $dbName;
    $out[] = '-- Generated_at_utc: ' . gmdate('c');
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = 'SET time_zone = \'+00:00\';';
    $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $out[] = 'START TRANSACTION;';
    $out[] = '';

    foreach ($tables as $table) {
        if (!s82_table_exists($pdo, $table)) {
            $out[] = '-- SKIP missing table: ' . $table;
            $out[] = '';
            continue;
        }
        $create = $pdo->query('SHOW CREATE TABLE ' . s82_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . s82_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . s82_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        $out[] = '-- row_count ' . $table . '=' . count($rows);
        if ($rows === []) {
            $out[] = '';
            continue;
        }
        $cols = array_map('s82_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = s82_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . s82_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $out[] = 'COMMIT;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '';

    return implode("\n", $out);
}

function s82_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd-His');
    $path = __DIR__ . '/karmotor_medisa_pre_s82_' . $stamp . '.sql';

    return $path;
}

function s82_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function s82_assert_production_db(PDO $pdo): ?array
{
    $identity = s82_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        return $identity;
    }

    return null;
}

function s82_apply_migration_file(PDO $pdo, string $file, string $expectedSha): array
{
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException('MIGRATION_FILE_MISSING:' . $file);
    }
    if (!isset(S82_MIGRATION_ALLOWLIST[$file])) {
        throw new RuntimeException('MIGRATION_NOT_ALLOWLISTED:' . $file);
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('MIGRATION_READ_FAILED:' . $file);
    }
    $sha = hash('sha256', $sql);
    if (!hash_equals($expectedSha, $sha)) {
        throw new RuntimeException('MIGRATION_SHA256_MISMATCH:' . $file);
    }
    foreach (s82_split_sql($sql) as $statement) {
        $pdo->exec($statement);
    }

    return [
        'file' => $file,
        'sha256' => $sha,
        'bytes' => strlen($sql),
    ];
}

function s82_latest_period(PDO $pdo, int $subeId): ?array
{
    if (!s82_table_exists($pdo, 'puantaj_aylik_muhurleri')) {
        return null;
    }
    $stmt = $pdo->prepare(
        "SELECT yil, ay, donem FROM puantaj_aylik_muhurleri
         WHERE sube_id = :sube AND durum = 'MUHURLENDI'
         ORDER BY yil DESC, ay DESC LIMIT 1"
    );
    $stmt->execute(['sube' => $subeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

if ($action === 'identity') {
    $identity = s82_identity($pdo);
    $dbOk = $identity['aktif_veritabani'] === 'karmotor_medisa';
    $hostOk = s82_host_hint_ok($identity, $host);
    $ok = $dbOk && $hostOk;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
        'expected_host_hint' => 'zelda.veridyen.com',
        'config_db_name' => $name,
        'config_db_host' => $host,
        'host_hint_ok' => $hostOk,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'preflight') {
    $bad = s82_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $identity = s82_identity($pdo);
    $schema = s82_schema_state($pdo);
    $counts = s82_relevant_counts($pdo);
    $code = $schema['fully_applied'] ? 'S82_SCHEMA_ALREADY_APPLIED' : 'S82_PREFLIGHT_OK';

    echo json_encode([
        'ok' => true,
        'code' => $code,
        'identity' => $identity,
        'schema' => $schema,
        'counts' => $counts,
        'already_applied' => $schema['fully_applied'],
        'allowlist' => array_keys(S82_MIGRATION_ALLOWLIST),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $bad = s82_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = s82_identity($pdo);
    $backupPath = s82_backup_path();
    $meta = [
        'method' => null,
        'file' => basename($backupPath),
        'bytes' => 0,
        'sha256' => null,
        'path' => $backupPath,
        'tables' => [
            'sirket_calisma_politikalari',
            'sirket_calisma_politika_degerleri',
            'sirket_calisma_politika_auditleri',
            'maas_hesaplama_calistirmalari',
            'maas_hesaplama_adaylari',
            'personel_bordro_devirleri',
            'personel_bordro_devir_importlari',
            'personeller',
            'users',
            'subeler',
        ],
        'contains_create' => false,
        'contains_commit' => false,
    ];

    $mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    if ($mysqldump !== '') {
        $tableArgs = '';
        foreach ($meta['tables'] as $t) {
            if (s82_table_exists($pdo, $t)) {
                $tableArgs .= ' ' . escapeshellarg($t);
            }
        }
        if ($tableArgs !== '') {
            $cmd = escapeshellarg($mysqldump)
                . ' --single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4'
                . ' -h ' . escapeshellarg($host)
                . ' -u ' . escapeshellarg($user)
                . ' -p' . escapeshellarg($pass)
                . ' ' . escapeshellarg($name)
                . $tableArgs
                . ' > ' . escapeshellarg($backupPath)
                . ' 2>/dev/null';
            exec($cmd, $out, $code);
            if ($code === 0 && is_file($backupPath) && filesize($backupPath) > 0) {
                $meta['method'] = 'mysqldump_relevant';
            }
        }
    }

    if ($meta['method'] === null) {
        $sql = s82_php_sql_dump_tables($pdo, $name, $meta['tables']);
        if ($sql === '' || strlen($sql) < 50) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'BACKUP_EMPTY'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        file_put_contents($backupPath, $sql);
        $meta['method'] = 'php_sql_dump_relevant';
    }

    if (!is_file($backupPath) || filesize($backupPath) <= 0) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'BACKUP_FILE_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $contents = (string) file_get_contents($backupPath);
    $meta['bytes'] = filesize($backupPath);
    $meta['sha256'] = hash_file('sha256', $backupPath);
    $meta['contains_create'] = stripos($contents, 'CREATE TABLE') !== false;
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;
    $meta['counts'] = s82_relevant_counts($pdo);

    $ok = $meta['bytes'] > 0 && $meta['contains_create'];
    file_put_contents(__DIR__ . '/s82_latest_backup_path.txt', basename($backupPath));

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S82_BACKUP_OK' : 'S82_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s82_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_s82_*.sql') ?: [];
        rsort($matches);
        $backupPath = $matches[0] ?? '';
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'BACKUP_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($backupPath) . '"');
    header('Content-Length: ' . (string) filesize($backupPath));
    readfile($backupPath);
    exit;
}

if ($action === 'migrate') {
    $bad = s82_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = s82_identity($pdo);
    $before = s82_schema_state($pdo);
    $beforeCounts = s82_relevant_counts($pdo);
    $applied = [];
    $skipped = [];

    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        if (!$before['migration_033_applied']) {
            $applied[] = s82_apply_migration_file(
                $pdo,
                '033_sirket_calisma_politikalari.sql',
                S82_MIGRATION_ALLOWLIST['033_sirket_calisma_politikalari.sql']
            );
        } else {
            $skipped[] = '033_sirket_calisma_politikalari.sql';
        }

        $mid = s82_schema_state($pdo);
        if (!$mid['migration_034_applied']) {
            $applied[] = s82_apply_migration_file(
                $pdo,
                '034_bordro_onay_ve_projection.sql',
                S82_MIGRATION_ALLOWLIST['034_bordro_onay_ve_projection.sql']
            );
        } else {
            $skipped[] = '034_bordro_onay_ve_projection.sql';
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S82_MIGRATE_FAILED',
            'error' => $e->getMessage(),
            'schema_before' => $before,
            'applied' => $applied,
            'skipped' => $skipped,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $after = s82_schema_state($pdo);
    $afterCounts = s82_relevant_counts($pdo);
    $ok = $after['fully_applied'] === true;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S82_MIGRATE_OK' : 'S82_MIGRATE_POSTCHECK_FAILED',
        'skipped_apply' => count($applied) === 0,
        'already_applied' => count($applied) === 0 && $before['fully_applied'],
        'applied' => $applied,
        'skipped' => $skipped,
        'schema_before' => $before,
        'schema_after' => $after,
        'before_counts' => $beforeCounts,
        'after_counts' => $afterCounts,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'postcheck') {
    $bad = s82_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = s82_identity($pdo);
    $schema = s82_schema_state($pdo);
    $counts = s82_relevant_counts($pdo);
    $ok = $schema['fully_applied'] === true
        && $schema['indexes']['uq_scp_aktif_onayli'] === true
        && $schema['indexes']['idx_mhc_bordro_onay'] === true
        && $schema['indexes']['idx_pbdi_sube_donem'] === true;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S82_POSTCHECK_OK' : 'S82_POSTCHECK_FAILED',
        'identity' => $identity,
        'schema' => $schema,
        'counts' => $counts,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'business_data_audit') {
    $bad = s82_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $approvedPolicy = 0;
    $policyValues = 0;
    if (s82_table_exists($pdo, 'sirket_calisma_politikalari')) {
        $approvedPolicy = (int) $pdo->query("SELECT COUNT(*) FROM sirket_calisma_politikalari WHERE state = 'ONAYLANDI'")->fetchColumn();
        if (s82_table_exists($pdo, 'sirket_calisma_politika_degerleri')) {
            $policyValues = (int) $pdo->query('SELECT COUNT(*) FROM sirket_calisma_politika_degerleri')->fetchColumn();
        }
    }
    $carryover = s82_table_exists($pdo, 'personel_bordro_devirleri')
        ? (int) $pdo->query('SELECT COUNT(*) FROM personel_bordro_devirleri')->fetchColumn()
        : -1;
    $importAudit = s82_table_exists($pdo, 'personel_bordro_devir_importlari')
        ? (int) $pdo->query('SELECT COUNT(*) FROM personel_bordro_devir_importlari')->fetchColumn()
        : -1;

    $candidateWriteBlocked = $approvedPolicy === 0;
    $code = $candidateWriteBlocked
        ? 'BUSINESS_POLICY_OR_CARRYOVER_DATA_REQUIRED'
        : 'S82_BUSINESS_DATA_PRESENT';

    echo json_encode([
        'ok' => true,
        'code' => $code,
        'approved_policy_count' => $approvedPolicy,
        'policy_value_count' => $policyValues,
        'carryover_count' => $carryover,
        'devir_import_audit_count' => $importAudit,
        'candidate_write_allowed' => !$candidateWriteBlocked,
        'note' => 'Read-only audit; no sample business data written by S82 ops tooling.',
        'identity' => s82_identity($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'bordro_preflight') {
    $bad = s82_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $subeId = isset($_GET['sube_id']) ? (int) $_GET['sube_id'] : 1;
    $yil = isset($_GET['yil']) ? (int) $_GET['yil'] : 0;
    $ay = isset($_GET['ay']) ? (int) $_GET['ay'] : 0;
    if ($yil <= 0 || $ay <= 0) {
        $period = s82_latest_period($pdo, $subeId);
        if ($period === null) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'code' => 'S82_PREFLIGHT_PERIOD_NOT_FOUND'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $yil = (int) $period['yil'];
        $ay = (int) $period['ay'];
    }

    require_once dirname(__DIR__) . '/src/bootstrap.php';
    $result = \Medisa\Api\Services\BordroHazirlikPreflightService::build($pdo, $subeId, $yil, $ay);
    $blockers = array_values(array_filter($result['items'] ?? [], static function (array $item) {
        return ($item['severity'] ?? '') === 'BLOCKER';
    }));
    $codes = array_map(static function (array $item) {
        return (string) ($item['code'] ?? '');
    }, $blockers);

    echo json_encode([
        'ok' => true,
        'code' => 'S82_BORDRO_PREFLIGHT_READONLY_OK',
        'sube_id' => $subeId,
        'yil' => $yil,
        'ay' => $ay,
        'hesaplanabilir_mi' => (bool) ($result['hesaplanabilir_mi'] ?? false),
        'blocker_count' => (int) ($result['blocker_count'] ?? count($blockers)),
        'warning_count' => (int) ($result['warning_count'] ?? 0),
        'blocker_codes' => $codes,
        'items' => $result['items'] ?? [],
        'identity' => s82_identity($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'role_matrix') {
    require_once dirname(__DIR__) . '/src/bootstrap.php';
    $roles = ['GENEL_YONETICI', 'MUHASEBE', 'BOLUM_YONETICISI', 'BIRIM_AMIRI', 'PATRON'];
    $matrix = [];
    foreach ($roles as $rol) {
        $user = ['rol' => $rol];
        $matrix[$rol] = [
            'bordro_on_izleme.view' => \Medisa\Api\Auth\RolePermissions::has($user, 'bordro_on_izleme.view'),
            'sirket_parametreleri.manage' => \Medisa\Api\Auth\RolePermissions::has($user, 'sirket_parametreleri.manage'),
            'bordro_kesinlestirme.approve' => \Medisa\Api\Auth\RolePermissions::has($user, 'bordro_kesinlestirme.approve'),
            'maas_hesaplama_adaylari.manage' => \Medisa\Api\Auth\RolePermissions::has($user, 'maas_hesaplama_adaylari.manage'),
        ];
    }
    $expected = [
        'GENEL_YONETICI' => ['bordro_on_izleme.view' => true],
        'MUHASEBE' => ['bordro_on_izleme.view' => true],
        'BOLUM_YONETICISI' => ['bordro_on_izleme.view' => false],
        'BIRIM_AMIRI' => ['bordro_on_izleme.view' => false],
        'PATRON' => ['bordro_on_izleme.view' => false],
    ];
    $ok = true;
    foreach ($expected as $rol => $perms) {
        foreach ($perms as $perm => $want) {
            if (($matrix[$rol][$perm] ?? null) !== $want) {
                $ok = false;
            }
        }
    }

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S82_ROLE_MATRIX_OK' : 'S82_ROLE_MATRIX_MISMATCH',
        'matrix' => $matrix,
        'expected' => $expected,
        'note' => 'Production PHP permission contract; HTTP auth matrix verified separately.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'integrity') {
    $bad = s82_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $schema = s82_schema_state($pdo);
    $ok = $schema['fully_applied'] === true;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S82_INTEGRITY_OK' : 'S82_INTEGRITY_SCHEMA_INCOMPLETE',
        'schema' => $schema,
        'counts' => s82_relevant_counts($pdo),
        'identity' => s82_identity($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
