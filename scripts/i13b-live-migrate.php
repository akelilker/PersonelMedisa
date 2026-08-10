<?php
/**
 * ONE-SHOT I13-B live migrate for 051_users_varsayilan_sube_id.sql only.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * Additive schema only. No user/personel writes. No backfill. UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_I13B_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_I13B_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_I13B_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'identity';
$expected051 = '920cc2cbb3e153413b930e8a8ebbb66cea0ffb507eb8da5f6d93506590192911';
$migrationFile = '051_users_varsayilan_sube_id.sql';

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

function i13b_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
        'charset' => (string) $pdo->query('SELECT @@character_set_database')->fetchColumn(),
        'collation' => (string) $pdo->query('SELECT @@collation_database')->fetchColumn(),
    ];
}

function i13b_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function i13b_count(PDO $pdo, string $table): int
{
    if (!i13b_table_exists($pdo, $table)) {
        return -1;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
}

function i13b_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function i13b_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function i13b_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function i13b_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"
    );
    $stmt->execute(['t' => $table, 'i' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

/** @return array<string, mixed>|null */
function i13b_column_meta(PDO $pdo, string $table, string $column): ?array
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

/** @return array<int, array<string, mixed>> */
function i13b_fk_rules(PDO $pdo, string $table, string $constraintName): array
{
    $stmt = $pdo->prepare(
        "SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE, rc.UPDATE_RULE, rc.REFERENCED_TABLE_NAME,
                kcu.COLUMN_NAME, kcu.REFERENCED_COLUMN_NAME
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         INNER JOIN information_schema.KEY_COLUMN_USAGE kcu
           ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
          AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
          AND kcu.TABLE_NAME = rc.TABLE_NAME
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
           AND rc.TABLE_NAME = :t
           AND rc.CONSTRAINT_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $constraintName]);

    return $stmt->fetchAll();
}

/** @return array<string, mixed> */
function i13b_counts(PDO $pdo): array
{
    return [
        'users' => i13b_count($pdo, 'users'),
        'subeler' => i13b_count($pdo, 'subeler'),
        'personeller' => i13b_count($pdo, 'personeller'),
        'user_subeler' => i13b_count($pdo, 'user_subeler'),
    ];
}

/** @return array<string, mixed> */
function i13b_no_backfill(PDO $pdo): array
{
    if (!i13b_column_exists($pdo, 'users', 'varsayilan_sube_id')) {
        return [
            'column_present' => false,
            'total_users' => i13b_count($pdo, 'users'),
            'users_with_default' => null,
        ];
    }
    $row = $pdo->query(
        'SELECT COUNT(*) AS total_users,
                SUM(varsayilan_sube_id IS NOT NULL) AS users_with_default
         FROM users'
    )->fetch();

    return [
        'column_present' => true,
        'total_users' => (int) ($row['total_users'] ?? 0),
        'users_with_default' => (int) ($row['users_with_default'] ?? 0),
    ];
}

/** @return array<string, mixed> */
function i13b_schema_probe(PDO $pdo): array
{
    $usersExists = i13b_table_exists($pdo, 'users');
    $subelerExists = i13b_table_exists($pdo, 'subeler');
    $col = $usersExists ? i13b_column_meta($pdo, 'users', 'varsayilan_sube_id') : null;
    $colPresent = is_array($col);
    $colTypeOk = $colPresent
        && strtolower((string) ($col['DATA_TYPE'] ?? '')) === 'int'
        && stripos((string) ($col['COLUMN_TYPE'] ?? ''), 'unsigned') !== false;
    $colNullableOk = $colPresent && strtoupper((string) ($col['IS_NULLABLE'] ?? '')) === 'YES';
    $indexOk = $usersExists && i13b_index_exists($pdo, 'users', 'idx_users_varsayilan_sube_id');
    $fkRows = $usersExists ? i13b_fk_rules($pdo, 'users', 'fk_users_varsayilan_sube') : [];
    $fkOk = count($fkRows) === 1
        && strtoupper((string) ($fkRows[0]['DELETE_RULE'] ?? '')) === 'SET NULL'
        && strtolower((string) ($fkRows[0]['REFERENCED_TABLE_NAME'] ?? '')) === 'subeler'
        && strtolower((string) ($fkRows[0]['COLUMN_NAME'] ?? '')) === 'varsayilan_sube_id'
        && strtolower((string) ($fkRows[0]['REFERENCED_COLUMN_NAME'] ?? '')) === 'id';
    $deleteRule = $fkRows[0]['DELETE_RULE'] ?? null;

    $already = $colPresent && $colTypeOk && $colNullableOk && $indexOk && $fkOk;
    $partial = (!$already) && ($colPresent || $indexOk || count($fkRows) > 0);
    $fresh = !$colPresent && !$indexOk && count($fkRows) === 0 && $usersExists && $subelerExists;

    return [
        'users_exists' => $usersExists,
        'subeler_exists' => $subelerExists,
        'column' => $col,
        'column_present' => $colPresent,
        'column_type_ok' => $colTypeOk,
        'column_nullable_ok' => $colNullableOk,
        'index_present' => $indexOk,
        'fk_rows' => $fkRows,
        'fk_ok' => $fkOk,
        'fk_delete_rule' => $deleteRule,
        'already_applied' => $already,
        'partial_051_trace' => $partial,
        'fresh_apply_ready' => $fresh,
        'no_backfill' => i13b_no_backfill($pdo),
    ];
}

/** @return array<string, mixed> */
function i13b_preflight(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    $probe = i13b_schema_probe($pdo);

    return array_merge([
        'table_count' => count($tables),
        'counts' => i13b_counts($pdo),
    ], $probe);
}

function i13b_php_sql_dump(PDO $pdo, string $dbName): string
{
    $out = [];
    $out[] = '-- I13B PHP SQL dump (shared-host fallback; restoreable)';
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
        $create = $pdo->query('SHOW CREATE TABLE ' . i13b_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . i13b_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . i13b_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            continue;
        }
        $cols = array_map('i13b_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = i13b_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . i13b_quote_ident($table) . ' ' . $colList . ' VALUES';
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

function i13b_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd_His');
    $path = __DIR__ . '/karmotor_medisa_pre_051_' . $stamp . '.sql';

    return $path;
}

/** @return array<int, string> */
function i13b_split_sql(string $sql): array
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
            // Expand single-line PREPARE; EXECUTE; DEALLOCATE for PDO single-statement exec.
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

function i13b_apply_051(PDO $pdo, string $file, string $expectedSha): array
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
    foreach (i13b_split_sql($sql) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }

    return ['file' => basename($file), 'sha256' => $sha, 'statements' => $count];
}

/** @return array<string, mixed> */
function i13b_postcheck(PDO $pdo, array $beforeCounts): array
{
    $probe = i13b_schema_probe($pdo);
    $after = i13b_counts($pdo);
    $noBackfill = $probe['no_backfill'];

    $countsUnchanged =
        ($beforeCounts['users'] ?? null) === $after['users']
        && ($beforeCounts['subeler'] ?? null) === $after['subeler']
        && ($beforeCounts['personeller'] ?? null) === $after['personeller']
        && ($beforeCounts['user_subeler'] ?? null) === $after['user_subeler'];

    $backfillOk = is_array($noBackfill)
        && !empty($noBackfill['column_present'])
        && (int) ($noBackfill['users_with_default'] ?? -1) === 0;

    $ok = !empty($probe['already_applied']) && $countsUnchanged && $backfillOk;

    return [
        'ok' => $ok,
        'schema' => $probe,
        'row_counts' => $after,
        'before_counts' => $beforeCounts,
        'counts_unchanged' => $countsUnchanged,
        'no_backfill_ok' => $backfillOk,
        'no_backfill' => $noBackfill,
    ];
}

if ($action === 'identity') {
    $identity = i13b_identity($pdo);
    $ok = $identity['aktif_veritabani'] === 'karmotor_medisa';
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'preflight') {
    $identity = i13b_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = i13b_preflight($pdo);
    $code = 'I13B_PREFLIGHT_OK';
    if (empty($pre['users_exists']) || empty($pre['subeler_exists'])) {
        $code = 'I13B_PREFLIGHT_BASE_TABLES_MISSING';
    } elseif ($pre['partial_051_trace']) {
        $code = 'I13B_PREFLIGHT_PARTIAL_051_TRACE';
    } elseif ($pre['already_applied']) {
        $code = 'I13B_ALREADY_APPLIED';
    }

    $ok = in_array($code, ['I13B_PREFLIGHT_OK', 'I13B_ALREADY_APPLIED'], true);
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
    $identity = i13b_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $backupPath = i13b_backup_path();
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
        'contains_subeler_create' => false,
        'contains_commit' => false,
        'created_at_utc' => gmdate('c'),
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
        $sql = i13b_php_sql_dump($pdo, $name);
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
    $meta['contains_subeler_create'] = (bool) preg_match('/CREATE TABLE [`"]?subeler[`"]?/i', $contents);
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;
    $meta['insert_block_count'] = preg_match_all('/INSERT INTO/i', $contents);
    $meta['table_count'] = preg_match_all('/CREATE TABLE/i', $contents);

    file_put_contents(__DIR__ . '/i13b_latest_backup_path.txt', basename($backupPath));

    $ok =
        $meta['bytes'] > 0
        && $meta['contains_create_table']
        && $meta['contains_users_create']
        && $meta['contains_subeler_create']
        && $meta['contains_commit'];

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'I13B_BACKUP_OK' : 'I13B_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'identity' => $identity,
        'preflight' => i13b_preflight($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/i13b_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '') {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_051_*.sql') ?: [];
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
    $identity = i13b_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = i13b_preflight($pdo);
    if (empty($pre['users_exists']) || empty($pre['subeler_exists'])) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'I13B_PREFLIGHT_BASE_TABLES_MISSING', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['partial_051_trace']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'I13B_PREFLIGHT_PARTIAL_051_TRACE', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['already_applied']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'I13B_ALREADY_APPLIED', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $before = $pre['counts'];
    $path = __DIR__ . '/' . $migrationFile;
    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        $applied = i13b_apply_051($pdo, $path, $expected051);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'I13B_MIGRATE_FAILED',
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $post = i13b_postcheck($pdo, $before);
    echo json_encode([
        'ok' => $post['ok'],
        'code' => $post['ok'] ? 'I13B_MIGRATE_OK' : 'I13B_MIGRATE_POSTCHECK_FAILED',
        'applied' => $applied,
        'before' => $before,
        'after' => $post['row_counts'],
        'postcheck' => $post,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'postcheck') {
    $before = [
        'users' => isset($_GET['before_users']) ? (int) $_GET['before_users'] : null,
        'subeler' => isset($_GET['before_subeler']) ? (int) $_GET['before_subeler'] : null,
        'personeller' => isset($_GET['before_personeller']) ? (int) $_GET['before_personeller'] : null,
        'user_subeler' => isset($_GET['before_user_subeler']) ? (int) $_GET['before_user_subeler'] : null,
    ];
    $check = i13b_postcheck($pdo, $before);
    echo json_encode([
        'ok' => $check['ok'],
        'code' => $check['ok'] ? 'I13B_POSTCHECK_OK' : 'I13B_POSTCHECK_FAILED',
        'identity' => i13b_identity($pdo),
        'postcheck' => $check,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
