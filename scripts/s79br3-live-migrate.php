<?php
/**
 * ONE-SHOT S79-B-R3 live migrate for 027_haftalik_kapanis.sql.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S79BR3_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_S79BR3_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_S79BR3_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'preflight';

const S79_EXPECTED_MIGRATION_SHA256 = '008dd3a74f65a9af99818703bd3f44664b60a9c5a3dc96701f8592e90df37bf5';
const S79_MIGRATION_FILE = '027_haftalik_kapanis.sql';
const S79_SMOKE_MARKER = 'S79-B-R3 Production Smoke';
const S79_SMOKE_WEEK_START = '2035-06-04'; // Monday
const S79_SMOKE_WEEK_END = '2035-06-10';

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

function s79_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_host' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s79_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s79_count(PDO $pdo, string $table): int
{
    if (!s79_table_exists($pdo, $table)) {
        return -1;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
}

function s79_counts(PDO $pdo): array
{
    return [
        'sube_count' => s79_count($pdo, 'subeler'),
        'departman_count' => s79_count($pdo, 'departmanlar'),
        'personel_count' => s79_count($pdo, 'personeller'),
        'mutabakat_count' => s79_count($pdo, 'haftalik_bildirim_mutabakatlari'),
        'gunluk_puantaj_count' => s79_count($pdo, 'gunluk_puantaj'),
        'users_count' => s79_count($pdo, 'users'),
        'kapanis_count' => s79_count($pdo, 'haftalik_kapanislar'),
        'kapanis_satir_count' => s79_count($pdo, 'haftalik_kapanis_satirlari'),
    ];
}

function s79_show_create(PDO $pdo, string $table): ?string
{
    if (!s79_table_exists($pdo, $table)) {
        return null;
    }
    $row = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`')->fetch();

    return is_array($row) ? (string) ($row['Create Table'] ?? null) : null;
}

function s79_parent_preflight(PDO $pdo): array
{
    $parents = ['subeler', 'departmanlar', 'users', 'personeller', 'haftalik_bildirim_mutabakatlari', 'gunluk_puantaj'];
    $out = [];
    foreach ($parents as $table) {
        $exists = s79_table_exists($pdo, $table);
        $create = $exists ? s79_show_create($pdo, $table) : null;
        $engineOk = is_string($create) && stripos($create, 'ENGINE=InnoDB') !== false;
        $out[$table] = [
            'exists' => $exists,
            'innodb' => $engineOk,
            'create_excerpt' => is_string($create) ? substr($create, 0, 400) : null,
        ];
    }

    return $out;
}

function s79_fk_rows(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('haftalik_kapanislar', 'haftalik_kapanis_satirlari')
         ORDER BY TABLE_NAME, CONSTRAINT_NAME"
    );

    return $stmt ? $stmt->fetchAll() : [];
}

function s79_table_schema(PDO $pdo, string $table): ?array
{
    if (!s79_table_exists($pdo, $table)) {
        return null;
    }

    return [
        'create_table' => s79_show_create($pdo, $table),
        'columns' => $pdo->query('SHOW FULL COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(),
        'indexes' => $pdo->query('SHOW INDEX FROM `' . str_replace('`', '', $table) . '`')->fetchAll(),
    ];
}

function s79_existing_schema(PDO $pdo): array
{
    $header = s79_table_exists($pdo, 'haftalik_kapanislar');
    $detail = s79_table_exists($pdo, 'haftalik_kapanis_satirlari');

    return [
        'haftalik_kapanislar' => $header,
        'haftalik_kapanis_satirlari' => $detail,
        'both_absent' => !$header && !$detail,
        'both_present' => $header && $detail,
        'partial' => ($header xor $detail),
        'header' => $header ? s79_table_schema($pdo, 'haftalik_kapanislar') : null,
        'detail' => $detail ? s79_table_schema($pdo, 'haftalik_kapanis_satirlari') : null,
        'fks' => ($header || $detail) ? s79_fk_rows($pdo) : [],
    ];
}

function s79_schema_matches_contract(PDO $pdo): array
{
    $header = s79_table_schema($pdo, 'haftalik_kapanislar');
    $detail = s79_table_schema($pdo, 'haftalik_kapanis_satirlari');
    $fks = s79_fk_rows($pdo);
    $issues = [];

    if ($header === null || $detail === null) {
        $issues[] = 'tables_missing';
        return ['ok' => false, 'issues' => $issues, 'header' => $header, 'detail' => $detail, 'fks' => $fks];
    }

    $hCreate = (string) ($header['create_table'] ?? '');
    $dCreate = (string) ($detail['create_table'] ?? '');

    if (stripos($hCreate, 'uq_haftalik_kapanis_scope') === false) {
        $issues[] = 'missing_uq_haftalik_kapanis_scope';
    }
    if (stripos($hCreate, 'departman_scope_key') === false || stripos($hCreate, 'STORED') === false) {
        $issues[] = 'missing_generated_departman_scope_key';
    }
    if (stripos($hCreate, 'IFNULL(departman_id,0)') === false && stripos($hCreate, 'ifnull(`departman_id`,0)') === false
        && stripos($hCreate, 'ifnull(departman_id,0)') === false) {
        // MariaDB may quote identifiers differently
        if (!preg_match('/IFNULL\s*\(\s*`?departman_id`?\s*,\s*0\s*\)/i', $hCreate)) {
            $issues[] = 'departman_scope_key_expression_mismatch';
        }
    }
    if (stripos($hCreate, 'utf8mb4') === false) {
        $issues[] = 'header_charset_mismatch';
    }
    if (stripos($dCreate, 'utf8mb4') === false) {
        $issues[] = 'detail_charset_mismatch';
    }
    if (stripos($dCreate, 'uq_hks_kapanis_personel') === false) {
        $issues[] = 'missing_uq_hks_kapanis_personel';
    }

    $cascade = false;
    foreach ($fks as $fk) {
        $del = strtoupper((string) ($fk['DELETE_RULE'] ?? ''));
        if ($del === 'CASCADE') {
            $cascade = true;
            $issues[] = 'fk_cascade:' . ($fk['CONSTRAINT_NAME'] ?? '');
        } elseif (!in_array($del, ['RESTRICT', 'NO ACTION'], true)) {
            $issues[] = 'fk_delete_rule:' . ($fk['CONSTRAINT_NAME'] ?? '') . '=' . $del;
        }
    }
    if (count($fks) < 6) {
        $issues[] = 'fk_count_low:' . count($fks);
    }

    return [
        'ok' => $issues === [] && !$cascade,
        'issues' => $issues,
        'header' => $header,
        'detail' => $detail,
        'fks' => $fks,
    ];
}

function s79_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s79_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function s79_php_sql_dump(PDO $pdo, string $dbName): string
{
    $out = [];
    $out[] = '-- S79-B-R3 PHP SQL dump (shared-host fallback)';
    $out[] = '-- Database: ' . $dbName;
    $out[] = '-- Generated_at_utc: ' . gmdate('c');
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = 'SET time_zone = \'+00:00\';';
    $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $out[] = 'START TRANSACTION;';
    $out[] = '';

    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    sort($tables);

    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE ' . s79_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . s79_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . s79_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            continue;
        }
        $cols = array_map('s79_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = s79_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . s79_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $out[] = 'COMMIT;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '';

    return implode("\n", $out);
}

function s79_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd-His');
    $path = __DIR__ . '/karmotor_medisa_pre_027_' . $stamp . '.sql';

    return $path;
}

function s79_split_sql(string $sql): array
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

function s79_assert_db(PDO $pdo): ?array
{
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        return $identity;
    }
    $hostOk = stripos($identity['db_host'], 'zelda') !== false
        || stripos($identity['db_host'], 'veridyen') !== false
        || $identity['db_host'] !== '';
    // Shared hosting often reports short hostname; require MariaDB 10.6.x and correct DB name.
    if (!$hostOk) {
        return $identity;
    }

    return null;
}

if ($action === 'identity') {
    $identity = s79_identity($pdo);
    $dbOk = $identity['aktif_veritabani'] === 'karmotor_medisa';
    $verOk = (bool) preg_match('/^10\.6\./', $identity['db_version']);
    $hostHint = stripos($identity['db_host'], 'zelda') !== false || stripos($identity['db_host'], 'veridyen') !== false;
    $ok = $dbOk && $verOk;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
        'expected_host_hint' => 'zelda.veridyen.com',
        'host_matches_hint' => $hostHint,
        'mariadb_10_6' => $verOk,
        'config_db_name' => $name,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'preflight') {
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_B_R3_BLOCKED_DB_IDENTITY',
            'identity' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $parents = s79_parent_preflight($pdo);
    $parentOk = true;
    foreach ($parents as $row) {
        if (!$row['exists'] || !$row['innodb']) {
            $parentOk = false;
            break;
        }
    }

    $existing = s79_existing_schema($pdo);
    $counts = s79_counts($pdo);

    if (!$parentOk) {
        $code = 'S79_B_R3_BLOCKED_PARENT_SCHEMA';
        $ok = false;
    } elseif ($existing['partial']) {
        $code = 'S79_B_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
        $ok = false;
    } elseif ($existing['both_present']) {
        $match = s79_schema_matches_contract($pdo);
        if ($match['ok']) {
            $code = 'S79_B_SCHEMA_ALREADY_APPLIED';
            $ok = true;
        } else {
            $code = 'S79_B_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
            $ok = false;
        }
    } else {
        $code = 'S79_B_PREFLIGHT_OK';
        $ok = true;
    }

    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'already_applied' => $existing['both_present'] && $ok && $code === 'S79_B_SCHEMA_ALREADY_APPLIED',
        'identity' => $identity,
        'parents' => $parents,
        'existing' => $existing,
        'counts' => $counts,
        'schema_match' => $existing['both_present'] ? s79_schema_matches_contract($pdo) : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S79_B_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $backupPath = s79_backup_path();
    $meta = [
        'method' => null,
        'file' => basename($backupPath),
        'bytes' => 0,
        'sha256' => null,
        'path' => $backupPath,
        'contains_create_personeller' => false,
        'contains_create_mutabakat' => false,
        'contains_create_gunluk_puantaj' => false,
        'contains_insert' => false,
        'contains_commit' => false,
    ];

    $mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    if ($mysqldump !== '') {
        $cmd = escapeshellarg($mysqldump)
            . ' --single-transaction --routines --triggers --hex-blob --default-character-set=utf8mb4'
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
        $sql = s79_php_sql_dump($pdo, $name);
        if ($sql === '' || strlen($sql) < 100) {
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

    $contents = file_get_contents($backupPath);
    $meta['bytes'] = filesize($backupPath);
    $meta['sha256'] = hash_file('sha256', $backupPath);
    $meta['contains_create_personeller'] = stripos($contents, 'CREATE TABLE') !== false && stripos($contents, 'personeller') !== false;
    $meta['contains_create_mutabakat'] = stripos($contents, 'haftalik_bildirim_mutabakatlari') !== false;
    $meta['contains_create_gunluk_puantaj'] = stripos($contents, 'gunluk_puantaj') !== false;
    $meta['contains_insert'] = stripos($contents, 'INSERT INTO') !== false || stripos($contents, 'INSERT ') !== false;
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;

    $ok = $meta['bytes'] > 0
        && $meta['contains_create_personeller']
        && $meta['contains_create_mutabakat']
        && $meta['contains_create_gunluk_puantaj']
        && $meta['contains_insert'];
    file_put_contents(__DIR__ . '/s79br3_latest_backup_path.txt', basename($backupPath));

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_B_BACKUP_OK' : 'S79_B_R3_BLOCKED_BACKUP',
        'backup' => $meta,
        'identity' => $identity,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s79br3_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_027_*.sql') ?: [];
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
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S79_B_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existing = s79_existing_schema($pdo);
    $beforeCounts = s79_counts($pdo);

    if ($existing['partial']) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_B_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
            'existing' => $existing,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($existing['both_present']) {
        $match = s79_schema_matches_contract($pdo);
        echo json_encode([
            'ok' => $match['ok'],
            'code' => $match['ok'] ? 'S79_B_MIGRATE_OK' : 'S79_B_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
            'skipped_apply' => true,
            'already_applied' => true,
            'before_counts' => $beforeCounts,
            'after_counts' => $beforeCounts,
            'schema_match' => $match,
            'identity' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $path = __DIR__ . '/' . S79_MIGRATION_FILE;
    if (!is_file($path)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'MIGRATION_FILE_MISSING', 'file' => S79_MIGRATION_FILE], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'MIGRATION_READ_FAILED'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sha = hash('sha256', $sql);
    if (!hash_equals(S79_EXPECTED_MIGRATION_SHA256, strtolower($sha))) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_B_R3_BLOCKED_MIGRATION_DRIFT',
            'expected_sha256' => S79_EXPECTED_MIGRATION_SHA256,
            'actual_sha256' => $sha,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Guardrails: refuse destructive DML/DDL outside ON DELETE FK clauses.
    $withoutComments = preg_replace('/--.*$/m', '', $sql) ?? $sql;
    $badHits = [];
    if (preg_match('/\bDROP\s+TABLE\b/i', $withoutComments)) {
        $badHits[] = 'DROP TABLE';
    }
    if (preg_match('/\bTRUNCATE\b/i', $withoutComments)) {
        $badHits[] = 'TRUNCATE';
    }
    if (preg_match('/\bDELETE\s+FROM\b/i', $withoutComments)) {
        $badHits[] = 'DELETE FROM';
    }
    if (preg_match('/\bUPDATE\b/i', $withoutComments)) {
        $badHits[] = 'UPDATE';
    }
    if (preg_match('/\bCREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $withoutComments)) {
        $badHits[] = 'CREATE TABLE IF NOT EXISTS';
    }
    if ($badHits !== []) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_B_R3_BLOCKED_MIGRATION_DRIFT',
            'bad_tokens' => $badHits,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        foreach (s79_split_sql($sql) as $statement) {
            $pdo->exec($statement);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_B_R3_BLOCKED_MIGRATION_APPLY',
            'error' => $e->getMessage(),
            'sqlstate' => ($e instanceof PDOException && isset($e->errorInfo[0])) ? $e->errorInfo[0] : null,
            'driver_code' => ($e instanceof PDOException && isset($e->errorInfo[1])) ? $e->errorInfo[1] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $match = s79_schema_matches_contract($pdo);
    $afterCounts = s79_counts($pdo);
    $parentStable =
        $afterCounts['sube_count'] === $beforeCounts['sube_count']
        && $afterCounts['departman_count'] === $beforeCounts['departman_count']
        && $afterCounts['personel_count'] === $beforeCounts['personel_count']
        && $afterCounts['mutabakat_count'] === $beforeCounts['mutabakat_count']
        && $afterCounts['gunluk_puantaj_count'] === $beforeCounts['gunluk_puantaj_count'];
    $emptyNew = $afterCounts['kapanis_count'] === 0 && $afterCounts['kapanis_satir_count'] === 0;
    $ok = $match['ok'] && $parentStable && $emptyNew;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_B_MIGRATE_OK' : 'S79_B_R3_BLOCKED_MIGRATION_APPLY',
        'skipped_apply' => false,
        'already_applied' => false,
        'applied' => [
            'file' => S79_MIGRATION_FILE,
            'sha256' => $sha,
            'bytes' => strlen($sql),
            'statements' => count(s79_split_sql($sql)),
        ],
        'before_counts' => $beforeCounts,
        'after_counts' => $afterCounts,
        'schema_match' => $match,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'postcheck') {
    $identity = s79_identity($pdo);
    $match = s79_schema_matches_contract($pdo);
    $counts = s79_counts($pdo);
    $ok = $identity['aktif_veritabani'] === 'karmotor_medisa' && $match['ok'];

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_B_POSTCHECK_OK' : 'S79_B_MIGRATION_APPLIED_SCHEMA_ACCEPTANCE_BLOCKED',
        'identity' => $identity,
        'counts' => $counts,
        'schema_match' => $match,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'counts') {
    echo json_encode([
        'ok' => true,
        'code' => 'S79_B_COUNTS_OK',
        'counts' => s79_counts($pdo),
        'identity' => s79_identity($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'integrity') {
    $counts = s79_counts($pdo);
    $orphan = 0;
    $byState = [];
    if (s79_table_exists($pdo, 'haftalik_kapanis_satirlari') && s79_table_exists($pdo, 'haftalik_kapanislar')) {
        $orphan = (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanis_satirlari s
             LEFT JOIN haftalik_kapanislar k ON k.id = s.kapanis_id
             WHERE k.id IS NULL'
        )->fetchColumn();
        $byState = $pdo->query('SELECT state, COUNT(*) AS adet FROM haftalik_kapanislar GROUP BY state')->fetchAll();
    }
    $ok = $orphan === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_B_FINAL_INTEGRITY_OK' : 'S79_B_INTEGRITY_FAILED',
        'counts' => $counts,
        'orphan_satir' => $orphan,
        'state_groups' => $byState,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_prepare') {
    // Controlled temporary mutabakat for far-future Monday week (empty snapshot create).
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S79_B_R3_BLOCKED_DB_IDENTITY'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!s79_table_exists($pdo, 'haftalik_kapanislar')) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SCHEMA_NOT_READY'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sube = $pdo->query('SELECT id FROM subeler ORDER BY id ASC LIMIT 1')->fetch();
    $userRow = $pdo->query("SELECT id FROM users WHERE rol = 'GENEL_YONETICI' OR rol = 'BIRIM_AMIRI' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$sube || !$userRow) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_SCOPE_UNAVAILABLE'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $subeId = (int) $sube['id'];
    $userId = (int) $userRow['id'];

    // Ensure week is Monday.
    $dow = (int) $pdo->query("SELECT DAYOFWEEK('" . S79_SMOKE_WEEK_START . "')")->fetchColumn(); // 2 = Monday in MySQL
    if ($dow !== 2) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_WEEK_NOT_MONDAY', 'dow' => $dow], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingKap = $pdo->prepare(
        'SELECT id FROM haftalik_kapanislar
         WHERE sube_id = :s AND hafta_baslangic = :h AND departman_scope_key = 0 LIMIT 1'
    );
    $existingKap->execute(['s' => $subeId, 'h' => S79_SMOKE_WEEK_START]);
    if ($existingKap->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_KAPANIS_ALREADY_EXISTS'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $chk = $pdo->prepare(
        'SELECT id FROM haftalik_bildirim_mutabakatlari
         WHERE sube_id = :s AND hafta_baslangic = :h LIMIT 1'
    );
    $chk->execute(['s' => $subeId, 'h' => S79_SMOKE_WEEK_START]);
    $mutId = $chk->fetchColumn();
    if ($mutId === false) {
        $ins = $pdo->prepare(
            'INSERT INTO haftalik_bildirim_mutabakatlari
              (sube_id, birim_amiri_user_id, hafta_baslangic, hafta_bitis, state, onaylayan_user_id, onaylandi_at)
             VALUES
              (:sube_id, :amir, :hb, :he, \'TAMAMLANDI\', :onay, NOW())'
        );
        $ins->execute([
            'sube_id' => $subeId,
            'amir' => $userId,
            'hb' => S79_SMOKE_WEEK_START,
            'he' => S79_SMOKE_WEEK_END,
            'onay' => $userId,
        ]);
        $mutId = (int) $pdo->lastInsertId();
    } else {
        $mutId = (int) $mutId;
    }

    // Marker file for cleanup coordination (no secrets).
    file_put_contents(__DIR__ . '/s79br3_smoke_marker.json', json_encode([
        'marker' => S79_SMOKE_MARKER,
        'sube_id' => $subeId,
        'mutabakat_id' => $mutId,
        'hafta_baslangic' => S79_SMOKE_WEEK_START,
        'hafta_bitis' => S79_SMOKE_WEEK_END,
        'user_id' => $userId,
        'created_at_utc' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'ok' => true,
        'code' => 'S79_B_SMOKE_PREPARE_OK',
        'marker' => S79_SMOKE_MARKER,
        'sube_id' => $subeId,
        'mutabakat_id' => $mutId,
        'hafta_baslangic' => S79_SMOKE_WEEK_START,
        'hafta_bitis' => S79_SMOKE_WEEK_END,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_snapshot') {
    $kapanisId = isset($_GET['kapanis_id']) ? (int) $_GET['kapanis_id'] : 0;
    if ($kapanisId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'kapanis_id required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $header = $pdo->prepare('SELECT * FROM haftalik_kapanislar WHERE id = :id');
    $header->execute(['id' => $kapanisId]);
    $h = $header->fetch();
    $satir = $pdo->prepare('SELECT * FROM haftalik_kapanis_satirlari WHERE kapanis_id = :id ORDER BY id');
    $satir->execute(['id' => $kapanisId]);
    $rows = $satir->fetchAll();
    echo json_encode([
        'ok' => is_array($h),
        'header' => $h ?: null,
        'satirlar' => $rows,
        'satir_count' => count($rows),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_cleanup') {
    $markerPath = __DIR__ . '/s79br3_smoke_marker.json';
    $kapanisId = isset($_GET['kapanis_id']) ? (int) $_GET['kapanis_id'] : 0;
    $meta = is_file($markerPath) ? json_decode((string) file_get_contents($markerPath), true) : null;
    $deleted = [
        'satir' => 0,
        'kapanis' => 0,
        'mutabakat' => 0,
    ];

    if ($kapanisId > 0) {
        $chk = $pdo->prepare('SELECT * FROM haftalik_kapanislar WHERE id = :id');
        $chk->execute(['id' => $kapanisId]);
        $row = $chk->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'code' => 'KAPANIS_NOT_FOUND', 'kapanis_id' => $kapanisId], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Safety: only delete smoke week scope.
        if ((string) ($row['hafta_baslangic'] ?? '') !== S79_SMOKE_WEEK_START) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'code' => 'REFUSING_NON_SMOKE_WEEK', 'row' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        $d1 = $pdo->prepare('DELETE FROM haftalik_kapanis_satirlari WHERE kapanis_id = :id');
        $d1->execute(['id' => $kapanisId]);
        $deleted['satir'] = $d1->rowCount();
        $d2 = $pdo->prepare('DELETE FROM haftalik_kapanislar WHERE id = :id');
        $d2->execute(['id' => $kapanisId]);
        $deleted['kapanis'] = $d2->rowCount();
    }

    $mutId = is_array($meta) ? (int) ($meta['mutabakat_id'] ?? 0) : 0;
    if ($mutId > 0) {
        $mchk = $pdo->prepare(
            'SELECT id, hafta_baslangic FROM haftalik_bildirim_mutabakatlari WHERE id = :id'
        );
        $mchk->execute(['id' => $mutId]);
        $mrow = $mchk->fetch();
        if ($mrow && (string) $mrow['hafta_baslangic'] === S79_SMOKE_WEEK_START) {
            $d3 = $pdo->prepare('DELETE FROM haftalik_bildirim_mutabakatlari WHERE id = :id');
            $d3->execute(['id' => $mutId]);
            $deleted['mutabakat'] = $d3->rowCount();
        }
    }

    if (is_file($markerPath)) {
        @unlink($markerPath);
    }

    $leftKap = 0;
    $leftSat = 0;
    if ($kapanisId > 0) {
        $leftKap = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanislar WHERE id = ' . (int) $kapanisId)->fetchColumn();
        $leftSat = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_satirlari WHERE kapanis_id = ' . (int) $kapanisId)->fetchColumn();
    }

    echo json_encode([
        'ok' => $leftKap === 0 && $leftSat === 0,
        'code' => ($leftKap === 0 && $leftSat === 0) ? 'S79_B_SMOKE_CLEANUP_OK' : 'S79_B_SMOKE_CLEANUP_INCOMPLETE',
        'deleted' => $deleted,
        'left_kapanis' => $leftKap,
        'left_satir' => $leftSat,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
