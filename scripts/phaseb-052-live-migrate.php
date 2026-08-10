<?php
/**
 * ONE-SHOT Phase B live migrate for 052_puantaj_tolerans_ve_disiplin.sql only.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * Additive schema only. No personel/puantaj/discipline business writes. UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_PHASEB052_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_PHASEB052_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_PHASEB052_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'identity';
$expected052 = '8310e0150aac845cbeb8bba3f60cff2ae0aea9f1a7073fec8d21540141e5e68a';
$migrationFile = '052_puantaj_tolerans_ve_disiplin.sql';
$expectedTables = [
    'puantaj_olay_kararlari',
    'puantaj_olay_karar_auditleri',
    'disiplin_vakalar',
    'disiplin_vaka_auditleri',
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
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB_CONNECT_FAILED', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

function pb052_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
        'charset' => (string) $pdo->query('SELECT @@character_set_database')->fetchColumn(),
        'collation' => (string) $pdo->query('SELECT @@collation_database')->fetchColumn(),
    ];
}

function pb052_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function pb052_count(PDO $pdo, string $table): int
{
    if (!pb052_table_exists($pdo, $table)) {
        return -1;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
}

function pb052_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function pb052_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

/** @return array<string, int> */
function pb052_business_counts(PDO $pdo): array
{
    return [
        'personeller' => pb052_count($pdo, 'personeller'),
        'gunluk_puantaj' => pb052_count($pdo, 'gunluk_puantaj'),
        'users' => pb052_count($pdo, 'users'),
        'surecler' => pb052_count($pdo, 'surecler'),
    ];
}

/** @return array<string, int> */
function pb052_new_table_counts(PDO $pdo, array $tables): array
{
    $out = [];
    foreach ($tables as $t) {
        $out[$t] = pb052_count($pdo, $t);
    }

    return $out;
}

/** @return array<string, mixed> */
function pb052_schema_probe(PDO $pdo, array $expectedTables): array
{
    $present = [];
    foreach ($expectedTables as $t) {
        $present[$t] = pb052_table_exists($pdo, $t);
    }
    $allPresent = !in_array(false, $present, true);
    $anyPresent = in_array(true, $present, true);
    $partial = $anyPresent && !$allPresent;

    $rowCounts = pb052_new_table_counts($pdo, $expectedTables);
    $emptyOk = true;
    if ($allPresent) {
        foreach ($rowCounts as $c) {
            if ((int) $c !== 0) {
                $emptyOk = false;
                break;
            }
        }
    }

    return [
        'expected_tables' => $expectedTables,
        'table_presence' => $present,
        'already_applied' => $allPresent,
        'partial_052_trace' => $partial,
        'fresh_apply_ready' => !$anyPresent,
        'new_table_counts' => $rowCounts,
        'new_tables_empty' => $allPresent ? $emptyOk : null,
        'base_personeller_exists' => pb052_table_exists($pdo, 'personeller'),
        'base_gunluk_puantaj_exists' => pb052_table_exists($pdo, 'gunluk_puantaj'),
        'base_surecler_exists' => pb052_table_exists($pdo, 'surecler'),
        'base_users_exists' => pb052_table_exists($pdo, 'users'),
    ];
}

/** @return array<string, mixed> */
function pb052_preflight(PDO $pdo, array $expectedTables): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    $probe = pb052_schema_probe($pdo, $expectedTables);

    return array_merge([
        'table_count' => count($tables),
        'business_counts' => pb052_business_counts($pdo),
        'has_051_users_varsayilan_col' => pb052_column_exists($pdo, 'users', 'varsayilan_sube_id'),
    ], $probe);
}

function pb052_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function pb052_php_sql_dump(PDO $pdo, string $dbName): string
{
    $out = [];
    $out[] = '-- PhaseB-052 PHP SQL dump (shared-host fallback; restoreable)';
    $out[] = '-- Database: ' . $dbName;
    $out[] = '-- Generated_at_utc: ' . gmdate('c');
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = 'SET time_zone = \'+00:00\';';
    $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $out[] = 'SET UNIQUE_CHECKS=0;';
    $out[] = 'START TRANSACTION;';
    $out[] = '';

    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    sort($tables);

    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE ' . pb052_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . pb052_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . pb052_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            continue;
        }
        $cols = array_map('pb052_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = pb052_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . pb052_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $out[] = 'COMMIT;';
    $out[] = 'SET UNIQUE_CHECKS=1;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '';

    return implode("\n", $out);
}

function pb052_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd_His');
    $path = __DIR__ . '/karmotor_medisa_pre_052_' . $stamp . '.sql';

    return $path;
}

/** @return array<int, string> */
function pb052_split_sql(string $sql): array
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
            $chunk = trim($buffer);
            $buffer = '';
            if ($chunk === '') {
                continue;
            }
            if (preg_match('/^\s*PREPARE\b/i', $chunk) && substr_count($chunk, ';') > 1) {
                foreach (explode(';', $chunk) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $statements[] = $part . ';';
                    }
                }
            } else {
                $statements[] = $chunk;
            }
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function pb052_apply_052(PDO $pdo, string $file, string $expectedSha): array
{
    if (!is_file($file)) {
        throw new RuntimeException('MIGRATION_FILE_MISSING:' . basename($file));
    }
    $sha = hash_file('sha256', $file);
    if (!hash_equals(strtolower($expectedSha), strtolower((string) $sha))) {
        throw new RuntimeException('MIGRATION_SHA_MISMATCH:' . basename($file) . ':' . $sha);
    }
    $sql = (string) file_get_contents($file);
    $count = 0;
    foreach (pb052_split_sql($sql) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }

    return ['file' => basename($file), 'sha256' => $sha, 'statements' => $count];
}

/** @return array<string, mixed> */
function pb052_postcheck(PDO $pdo, array $expectedTables, array $beforeBusiness): array
{
    $probe = pb052_schema_probe($pdo, $expectedTables);
    $afterBusiness = pb052_business_counts($pdo);
    $countsUnchanged =
        ($beforeBusiness['personeller'] ?? null) === $afterBusiness['personeller']
        && ($beforeBusiness['gunluk_puantaj'] ?? null) === $afterBusiness['gunluk_puantaj']
        && ($beforeBusiness['users'] ?? null) === $afterBusiness['users']
        && ($beforeBusiness['surecler'] ?? null) === $afterBusiness['surecler'];

    $emptyOk = !empty($probe['new_tables_empty']);
    $ok = !empty($probe['already_applied']) && $countsUnchanged && $emptyOk;

    return [
        'ok' => $ok,
        'schema' => $probe,
        'business_counts_after' => $afterBusiness,
        'business_counts_before' => $beforeBusiness,
        'business_counts_unchanged' => $countsUnchanged,
        'new_tables_empty_ok' => $emptyOk,
    ];
}

if ($action === 'identity') {
    $identity = pb052_identity($pdo);
    $ok = $identity['aktif_veritabani'] === 'karmotor_medisa';
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
        'authorized_head' => '0c4a6aa563bb2ceca9cd34c35db59ddb25622481',
        'authorized_migration' => $migrationFile,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'preflight') {
    $identity = pb052_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = pb052_preflight($pdo, $expectedTables);
    $code = 'PHASEB052_PREFLIGHT_OK';
    if (
        empty($pre['base_personeller_exists'])
        || empty($pre['base_gunluk_puantaj_exists'])
        || empty($pre['base_surecler_exists'])
        || empty($pre['base_users_exists'])
    ) {
        $code = 'PHASEB052_PREFLIGHT_BASE_TABLES_MISSING';
    } elseif (empty($pre['has_051_users_varsayilan_col'])) {
        $code = 'PHASEB052_PREFLIGHT_051_MISSING';
    } elseif ($pre['partial_052_trace']) {
        $code = 'PHASEB052_PREFLIGHT_PARTIAL_052_TRACE';
    } elseif ($pre['already_applied']) {
        $code = 'PHASEB052_ALREADY_APPLIED';
    }

    $ok = in_array($code, ['PHASEB052_PREFLIGHT_OK', 'PHASEB052_ALREADY_APPLIED'], true);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'identity' => $identity,
        'preflight' => $pre,
        'already_applied' => $pre['already_applied'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $identity = pb052_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $backupPath = pb052_backup_path();
    $meta = [
        'method' => null,
        'file' => basename($backupPath),
        'bytes' => 0,
        'sha256' => null,
        'table_count' => 0,
        'insert_block_count' => 0,
        'contains_create_table' => false,
        'contains_insert' => false,
        'contains_users_create' => false,
        'contains_personeller_create' => false,
        'contains_commit' => false,
        'created_at_utc' => gmdate('c'),
        'db_server_version' => $identity['db_version'],
    ];

    $mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    if ($mysqldump !== '') {
        $cmd = escapeshellarg($mysqldump)
            . ' --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4'
            . ' -h ' . escapeshellarg($host)
            . ' -u ' . escapeshellarg($user)
            . ' -p' . escapeshellarg($pass)
            . ' ' . escapeshellarg($name)
            . ' > ' . escapeshellarg($backupPath)
            . ' 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code === 0 && is_file($backupPath) && filesize($backupPath) > 0) {
            $meta['method'] = 'mysqldump';
        }
    }

    if ($meta['method'] === null) {
        $sql = pb052_php_sql_dump($pdo, $name);
        if ($sql === '' || strlen($sql) < 200) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'BACKUP_EMPTY'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        file_put_contents($backupPath, $sql);
        $meta['method'] = 'php_sql_dump';
    }

    if (!is_file($backupPath) || filesize($backupPath) <= 0) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'BACKUP_FILE_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $contents = (string) file_get_contents($backupPath);
    $meta['bytes'] = filesize($backupPath);
    $meta['sha256'] = hash_file('sha256', $backupPath);
    $meta['contains_create_table'] = stripos($contents, 'CREATE TABLE') !== false;
    $meta['contains_insert'] = stripos($contents, 'INSERT INTO') !== false;
    $meta['contains_users_create'] = (bool) preg_match('/CREATE TABLE [`"]?users[`"]?/i', $contents);
    $meta['contains_personeller_create'] = (bool) preg_match('/CREATE TABLE [`"]?personeller[`"]?/i', $contents);
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;
    $meta['insert_block_count'] = preg_match_all('/INSERT INTO/i', $contents);
    $meta['table_count'] = preg_match_all('/CREATE TABLE/i', $contents);

    file_put_contents(__DIR__ . '/phaseb052_latest_backup_path.txt', basename($backupPath));

    $ok =
        $meta['bytes'] > 0
        && $meta['contains_create_table']
        && $meta['contains_users_create']
        && $meta['contains_personeller_create']
        && $meta['contains_commit'];

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PHASEB052_BACKUP_OK' : 'PHASEB052_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'identity' => $identity,
        'preflight' => pb052_preflight($pdo, $expectedTables),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/phaseb052_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '') {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_052_*.sql') ?: [];
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
    $identity = pb052_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = pb052_preflight($pdo, $expectedTables);
    if (
        empty($pre['base_personeller_exists'])
        || empty($pre['base_gunluk_puantaj_exists'])
        || empty($pre['base_surecler_exists'])
        || empty($pre['base_users_exists'])
    ) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PHASEB052_PREFLIGHT_BASE_TABLES_MISSING', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($pre['has_051_users_varsayilan_col'])) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PHASEB052_PREFLIGHT_051_MISSING', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['partial_052_trace']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PHASEB052_PREFLIGHT_PARTIAL_052_TRACE', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['already_applied']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PHASEB052_ALREADY_APPLIED', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $before = $pre['business_counts'];
    $path = __DIR__ . '/' . $migrationFile;
    $startedAt = gmdate('c');
    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        $applied = pb052_apply_052($pdo, $path, $expected052);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'PHASEB052_MIGRATE_FAILED',
            'error' => $e->getMessage(),
            'started_at_utc' => $startedAt,
            'finished_at_utc' => gmdate('c'),
            'preflight' => $pre,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $finishedAt = gmdate('c');
    $post = pb052_postcheck($pdo, $expectedTables, $before);
    echo json_encode([
        'ok' => $post['ok'],
        'code' => $post['ok'] ? 'PHASEB052_MIGRATE_OK' : 'PHASEB052_MIGRATE_POSTCHECK_FAILED',
        'applied' => $applied,
        'started_at_utc' => $startedAt,
        'finished_at_utc' => $finishedAt,
        'before' => $before,
        'after' => $post['business_counts_after'],
        'postcheck' => $post,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'postcheck') {
    $before = [
        'personeller' => isset($_GET['before_personeller']) ? (int) $_GET['before_personeller'] : null,
        'gunluk_puantaj' => isset($_GET['before_gunluk_puantaj']) ? (int) $_GET['before_gunluk_puantaj'] : null,
        'users' => isset($_GET['before_users']) ? (int) $_GET['before_users'] : null,
        'surecler' => isset($_GET['before_surecler']) ? (int) $_GET['before_surecler'] : null,
    ];
    $check = pb052_postcheck($pdo, $expectedTables, $before);
    echo json_encode([
        'ok' => $check['ok'],
        'code' => $check['ok'] ? 'PHASEB052_POSTCHECK_OK' : 'PHASEB052_POSTCHECK_FAILED',
        'identity' => pb052_identity($pdo),
        'postcheck' => $check,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
