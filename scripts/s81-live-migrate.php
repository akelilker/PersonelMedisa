<?php
/**
 * ONE-SHOT S81 live migrate for 032_gunluk_bildirim_tamamlama_ve_duplicate.sql
 * + controlled write smoke. Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S81_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_S81_MIGRATE_TOKEN" after token injection.
// Reject if assignment still starts with REPLACE_ (placeholder not injected) or equals UNSET.
if (
    strpos($tokenExpected, 'REPLACE_') === 0
    || $tokenExpected === 'UNSET_S81_MIGRATE_TOKEN'
    || $tokenProvided === ''
    || !hash_equals($tokenExpected, $tokenProvided)
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'preflight';

const S81_MIGRATION_FILE = '032_gunluk_bildirim_tamamlama_ve_duplicate.sql';
const S81_EXPECTED_SHA256 = '20bdc304a089ea5c9a0c61e61a0345625835198a1ea356a59bcf39cb6fef61fb';
const S81_SMOKE_MARKER = 'S81 Production Smoke';
const S81_SMOKE_MARKER_KEY = 'S81_SMOKE_MARKER_081';
const S81_SMOKE_TC = '90081000081';
const S81_SMOKE_SICIL = 'S81-SMOKE-081';
const S81_SMOKE_USERNAME = 's81_smoke_amir';
const S81_SMOKE_SUBE_KOD = 'S81SMOKE';

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

function s81_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_host' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s81_host_hint_ok(array $identity, string $configHost): bool
{
    return stripos($identity['db_host'], 'zelda.veridyen.com') !== false
        || stripos($identity['db_host'], 'zelda') !== false
        || stripos($configHost, 'zelda.veridyen.com') !== false
        || stripos($configHost, 'zelda') !== false;
}

function s81_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s81_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function s81_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $stmt->execute(['t' => $table, 'i' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

function s81_schema_state(PDO $pdo): array
{
    $hasTamamlama = s81_table_exists($pdo, 'gunluk_bildirim_tamamlamalari');
    $hasOpenKey = s81_column_exists($pdo, 'gunluk_bildirimler', 'open_duplicate_key');
    $hasOpenIdx = s81_index_exists($pdo, 'gunluk_bildirimler', 'uniq_gb_open_duplicate');

    return [
        'gunluk_bildirimler_exists' => s81_table_exists($pdo, 'gunluk_bildirimler'),
        'gunluk_bildirim_tamamlamalari_exists' => $hasTamamlama,
        'open_duplicate_key_exists' => $hasOpenKey,
        'uniq_gb_open_duplicate_exists' => $hasOpenIdx,
        'fully_applied' => $hasTamamlama && $hasOpenKey && $hasOpenIdx,
    ];
}

function s81_relevant_counts(PDO $pdo): array
{
    $out = [
        'gunluk_bildirimler' => -1,
        'gunluk_bildirim_tamamlamalari' => -1,
        'personeller' => -1,
        'users' => -1,
        'subeler' => -1,
    ];
    foreach (array_keys($out) as $table) {
        if (!s81_table_exists($pdo, $table)) {
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

function s81_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s81_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function s81_php_sql_dump_tables(PDO $pdo, string $dbName, array $tables): string
{
    $out = [];
    $out[] = '-- S81 PHP SQL dump (relevant tables)';
    $out[] = '-- Database: ' . $dbName;
    $out[] = '-- Generated_at_utc: ' . gmdate('c');
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = 'SET time_zone = \'+00:00\';';
    $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $out[] = 'START TRANSACTION;';
    $out[] = '';

    foreach ($tables as $table) {
        if (!s81_table_exists($pdo, $table)) {
            $out[] = '-- SKIP missing table: ' . $table;
            $out[] = '';
            continue;
        }
        $create = $pdo->query('SHOW CREATE TABLE ' . s81_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . s81_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . s81_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        $out[] = '-- row_count ' . $table . '=' . count($rows);
        if ($rows === []) {
            $out[] = '';
            continue;
        }
        $cols = array_map('s81_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = s81_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . s81_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $out[] = 'COMMIT;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '';

    return implode("\n", $out);
}

function s81_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd-His');
    $path = __DIR__ . '/karmotor_medisa_pre_032_' . $stamp . '.sql';

    return $path;
}

function s81_split_sql(string $sql): array
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

function s81_assert_production_db(PDO $pdo): ?array
{
    $identity = s81_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        return $identity;
    }

    return null;
}

function s81_smoke_leftovers(PDO $pdo): array
{
    $counts = [
        'personeller_tc_or_sicil' => 0,
        'users_username' => 0,
        'user_subeler' => 0,
        'gunluk_bildirimler_marker' => 0,
        'gunluk_bildirim_tamamlamalari_marker' => 0,
        'subeler_smoke_kod' => 0,
    ];

    $p = $pdo->prepare('SELECT COUNT(*) FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil');
    $p->execute(['tc' => S81_SMOKE_TC, 'sicil' => S81_SMOKE_SICIL]);
    $counts['personeller_tc_or_sicil'] = (int) $p->fetchColumn();

    $u = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :u');
    $u->execute(['u' => S81_SMOKE_USERNAME]);
    $counts['users_username'] = (int) $u->fetchColumn();

    $us = $pdo->prepare(
        'SELECT COUNT(*) FROM user_subeler us
         INNER JOIN users u ON u.id = us.user_id
         WHERE u.username = :u'
    );
    $us->execute(['u' => S81_SMOKE_USERNAME]);
    $counts['user_subeler'] = (int) $us->fetchColumn();

    if (s81_table_exists($pdo, 'gunluk_bildirimler')) {
        $b = $pdo->prepare(
            'SELECT COUNT(*) FROM gunluk_bildirimler
             WHERE aciklama LIKE :m1 OR aciklama LIKE :m2'
        );
        $b->execute([
            'm1' => '%' . S81_SMOKE_MARKER . '%',
            'm2' => '%' . S81_SMOKE_MARKER_KEY . '%',
        ]);
        $counts['gunluk_bildirimler_marker'] = (int) $b->fetchColumn();
    }

    if (s81_table_exists($pdo, 'gunluk_bildirim_tamamlamalari')) {
        $t = $pdo->prepare(
            'SELECT COUNT(*) FROM gunluk_bildirim_tamamlamalari
             WHERE not_metni LIKE :m1 OR not_metni LIKE :m2'
        );
        $t->execute([
            'm1' => '%' . S81_SMOKE_MARKER . '%',
            'm2' => '%' . S81_SMOKE_MARKER_KEY . '%',
        ]);
        $counts['gunluk_bildirim_tamamlamalari_marker'] = (int) $t->fetchColumn();
    }

    $s = $pdo->prepare('SELECT COUNT(*) FROM subeler WHERE kod = :k');
    $s->execute(['k' => S81_SMOKE_SUBE_KOD]);
    $counts['subeler_smoke_kod'] = (int) $s->fetchColumn();

    $total = 0;
    foreach ($counts as $n) {
        $total += $n;
    }

    return ['counts' => $counts, 'total' => $total];
}

if ($action === 'identity') {
    $identity = s81_identity($pdo);
    $dbOk = $identity['aktif_veritabani'] === 'karmotor_medisa';
    $hostOk = s81_host_hint_ok($identity, $host);
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
    $bad = s81_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH',
            'identity' => $bad,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $identity = s81_identity($pdo);
    $schema = s81_schema_state($pdo);
    $counts = s81_relevant_counts($pdo);

    if (!$schema['gunluk_bildirimler_exists']) {
        echo json_encode([
            'ok' => false,
            'code' => 'S81_PREFLIGHT_PARENT_MISSING',
            'identity' => $identity,
            'schema' => $schema,
            'counts' => $counts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($schema['fully_applied']) {
        echo json_encode([
            'ok' => true,
            'code' => 'S81_SCHEMA_ALREADY_APPLIED',
            'identity' => $identity,
            'schema' => $schema,
            'counts' => $counts,
            'already_applied' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'code' => 'S81_PREFLIGHT_OK',
        'identity' => $identity,
        'schema' => $schema,
        'counts' => $counts,
        'already_applied' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $bad = s81_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = s81_identity($pdo);
    $backupPath = s81_backup_path();
    $meta = [
        'method' => null,
        'file' => basename($backupPath),
        'bytes' => 0,
        'sha256' => null,
        'path' => $backupPath,
        'tables' => [
            'gunluk_bildirimler',
            'gunluk_bildirim_tamamlamalari',
            'personeller',
            'users',
            'user_subeler',
            'subeler',
        ],
        'contains_create' => false,
        'contains_commit' => false,
    ];

    $mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    if ($mysqldump !== '') {
        $tableArgs = '';
        foreach ($meta['tables'] as $t) {
            $tableArgs .= ' ' . escapeshellarg($t);
        }
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

    if ($meta['method'] === null) {
        $sql = s81_php_sql_dump_tables($pdo, $name, $meta['tables']);
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
    $meta['counts'] = s81_relevant_counts($pdo);

    $ok = $meta['bytes'] > 0 && $meta['contains_create'];
    file_put_contents(__DIR__ . '/s81_latest_backup_path.txt', basename($backupPath));

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S81_BACKUP_OK' : 'S81_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s81_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_032_*.sql') ?: [];
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
    $bad = s81_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = s81_identity($pdo);
    $before = s81_schema_state($pdo);
    $beforeCounts = s81_relevant_counts($pdo);

    if ($before['fully_applied']) {
        echo json_encode([
            'ok' => true,
            'code' => 'S81_MIGRATE_OK',
            'skipped_apply' => true,
            'already_applied' => true,
            'schema_before' => $before,
            'schema_after' => $before,
            'before_counts' => $beforeCounts,
            'after_counts' => $beforeCounts,
            'identity' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $file = S81_MIGRATION_FILE;
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'MIGRATION_FILE_MISSING', 'file' => $file], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'MIGRATION_READ_FAILED'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sha = hash('sha256', $sql);
    if (!hash_equals(S81_EXPECTED_SHA256, $sha)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'MIGRATION_SHA256_MISMATCH',
            'expected' => S81_EXPECTED_SHA256,
            'actual' => $sha,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        foreach (s81_split_sql($sql) as $statement) {
            $pdo->exec($statement);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S81_MIGRATE_FAILED',
            'error' => $e->getMessage(),
            'sqlstate' => ($e instanceof PDOException && isset($e->errorInfo[0])) ? $e->errorInfo[0] : null,
            'driver_code' => ($e instanceof PDOException && isset($e->errorInfo[1])) ? $e->errorInfo[1] : null,
            'schema_before' => $before,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $after = s81_schema_state($pdo);
    $afterCounts = s81_relevant_counts($pdo);
    $ok = $after['fully_applied'] === true;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S81_MIGRATE_OK' : 'S81_MIGRATE_POSTCHECK_FAILED',
        'skipped_apply' => false,
        'already_applied' => false,
        'applied' => [
            'file' => $file,
            'sha256' => $sha,
            'bytes' => strlen($sql),
        ],
        'schema_before' => $before,
        'schema_after' => $after,
        'before_counts' => $beforeCounts,
        'after_counts' => $afterCounts,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_run') {
    $bad = s81_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = s81_identity($pdo);
    $schema = s81_schema_state($pdo);
    if (!$schema['fully_applied']) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S81_SMOKE_SCHEMA_NOT_READY',
            'schema' => $schema,
            'identity' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $leftover = s81_smoke_leftovers($pdo);
    if ($leftover['total'] > 0) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S81_SMOKE_LEFTOVER_EXISTS',
            'leftover' => $leftover,
            'hint' => 'Run smoke_cleanup first',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $createdSube = false;
    $subeId = 0;
    $userId = 0;
    $personelId = 0;
    $bildirimId = 0;
    $tamamlamaId = 0;
    $duplicateOk = false;
    $duplicateError = null;
    $duplicateSqlstate = null;
    $duplicateDriver = null;
    $tarih = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
    $aciklama = S81_SMOKE_MARKER . ' ' . S81_SMOKE_MARKER_KEY;
    $notMetni = S81_SMOKE_MARKER . ' ' . S81_SMOKE_MARKER_KEY;

    try {
        $pdo->beginTransaction();

        $subeRow = $pdo->query("SELECT id FROM subeler WHERE durum = 'AKTIF' ORDER BY id ASC LIMIT 1")->fetch();
        if ($subeRow) {
            $subeId = (int) $subeRow['id'];
        } else {
            $insSube = $pdo->prepare(
                "INSERT INTO subeler (kod, ad, durum) VALUES (:kod, :ad, 'AKTIF')"
            );
            $insSube->execute([
                'kod' => S81_SMOKE_SUBE_KOD,
                'ad' => S81_SMOKE_MARKER . ' Sube',
            ]);
            $subeId = (int) $pdo->lastInsertId();
            $createdSube = true;
        }

        $pw = password_hash('s81-smoke-not-for-login', PASSWORD_DEFAULT);
        $insUser = $pdo->prepare(
            "INSERT INTO users (username, password_hash, ad_soyad, rol, durum)
             VALUES (:u, :p, :ad, 'BIRIM_AMIRI', 'AKTIF')"
        );
        $insUser->execute([
            'u' => S81_SMOKE_USERNAME,
            'p' => $pw,
            'ad' => S81_SMOKE_MARKER . ' Amir',
        ]);
        $userId = (int) $pdo->lastInsertId();

        $insUs = $pdo->prepare('INSERT INTO user_subeler (user_id, sube_id) VALUES (:u, :s)');
        $insUs->execute(['u' => $userId, 's' => $subeId]);

        $insPersonel = $pdo->prepare(
            "INSERT INTO personeller (
                tc_kimlik_no, ad, soyad, dogum_tarihi, telefon,
                acil_durum_kisi, acil_durum_telefon, sicil_no, ise_giris_tarihi,
                sube_id, bagli_amir_id, aktif_durum
             ) VALUES (
                :tc, 'S81', 'Smoke', '1990-01-01', '05000000081',
                'S81 Contact', '05000000082', :sicil, CURDATE(),
                :sube, :amir, 'AKTIF'
             )"
        );
        $insPersonel->execute([
            'tc' => S81_SMOKE_TC,
            'sicil' => S81_SMOKE_SICIL,
            'sube' => $subeId,
            'amir' => $userId,
        ]);
        $personelId = (int) $pdo->lastInsertId();

        $insBildirim = $pdo->prepare(
            "INSERT INTO gunluk_bildirimler (
                personel_id, tarih, sube_id, bildirim_turu, aciklama, state,
                created_by, updated_by
             ) VALUES (
                :pid, :tarih, :sube, 'GELMEDI', :aciklama, 'TASLAK',
                :uid, :uid
             )"
        );
        $insBildirim->execute([
            'pid' => $personelId,
            'tarih' => $tarih,
            'sube' => $subeId,
            'aciklama' => $aciklama,
            'uid' => $userId,
        ]);
        $bildirimId = (int) $pdo->lastInsertId();

        $submit = $pdo->prepare(
            "UPDATE gunluk_bildirimler
             SET state = 'GONDERILDI', submitted_at = NOW(), updated_by = :uid
             WHERE id = :id"
        );
        $submit->execute(['uid' => $userId, 'id' => $bildirimId]);

        try {
            $dup = $pdo->prepare(
                "INSERT INTO gunluk_bildirimler (
                    personel_id, tarih, sube_id, bildirim_turu, aciklama, state,
                    created_by, updated_by
                 ) VALUES (
                    :pid, :tarih, :sube, 'GELMEDI', :aciklama, 'TASLAK',
                    :uid, :uid
                 )"
            );
            $dup->execute([
                'pid' => $personelId,
                'tarih' => $tarih,
                'sube' => $subeId,
                'aciklama' => $aciklama . ' DUP',
                'uid' => $userId,
            ]);
            $duplicateError = 'INSERT_SUCCEEDED_UNEXPECTEDLY';
        } catch (Throwable $e) {
            $duplicateError = $e->getMessage();
            if ($e instanceof PDOException) {
                $duplicateSqlstate = $e->errorInfo[0] ?? null;
                $duplicateDriver = $e->errorInfo[1] ?? null;
            }
            $duplicateOk = ((string) $duplicateSqlstate === '23000' || (int) $duplicateDriver === 1062);
        }

        if (!$duplicateOk) {
            throw new RuntimeException('DUPLICATE_PROOF_FAILED:' . (string) $duplicateError);
        }

        $insTamam = $pdo->prepare(
            "INSERT INTO gunluk_bildirim_tamamlamalari (
                sube_id, birim_amiri_user_id, tarih, state,
                tamamlayan_user_id, tamamlandi_at, not_metni
             ) VALUES (
                :sube, :amir, :tarih, 'TAMAMLANDI',
                :amir, NOW(), :not_metni
             )"
        );
        $insTamam->execute([
            'sube' => $subeId,
            'amir' => $userId,
            'tarih' => $tarih,
            'not_metni' => $notMetni,
        ]);
        $tamamlamaId = (int) $pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S81_SMOKE_FAILED',
            'error' => $e->getMessage(),
            'partial_ids' => [
                'personel_id' => $personelId,
                'user_id' => $userId,
                'bildirim_id' => $bildirimId,
                'tamamlama_id' => $tamamlamaId,
                'sube_id' => $subeId,
                'created_sube' => $createdSube,
            ],
            'duplicate' => [
                'ok' => $duplicateOk,
                'error' => $duplicateError,
                'sqlstate' => $duplicateSqlstate,
                'driver_code' => $duplicateDriver,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'code' => 'S81_SMOKE_OK',
        'marker' => S81_SMOKE_MARKER,
        'marker_key' => S81_SMOKE_MARKER_KEY,
        'tarih' => $tarih,
        'ids' => [
            'personel_id' => $personelId,
            'user_id' => $userId,
            'bildirim_id' => $bildirimId,
            'tamamlama_id' => $tamamlamaId,
            'sube_id' => $subeId,
            'created_sube' => $createdSube,
        ],
        'duplicate' => [
            'ok' => $duplicateOk,
            'error' => $duplicateError,
            'sqlstate' => $duplicateSqlstate,
            'driver_code' => $duplicateDriver,
        ],
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_cleanup') {
    $bad = s81_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = s81_identity($pdo);
    $deleted = [
        'gunluk_bildirim_tamamlamalari' => 0,
        'gunluk_bildirimler' => 0,
        'personeller' => 0,
        'user_subeler' => 0,
        'users' => 0,
        'subeler' => 0,
    ];

    try {
        $pdo->beginTransaction();

        $userIds = [];
        $uStmt = $pdo->prepare('SELECT id FROM users WHERE username = :u');
        $uStmt->execute(['u' => S81_SMOKE_USERNAME]);
        foreach ($uStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $userIds[] = (int) $id;
        }

        $personelIds = [];
        $pStmt = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil');
        $pStmt->execute(['tc' => S81_SMOKE_TC, 'sicil' => S81_SMOKE_SICIL]);
        foreach ($pStmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $personelIds[] = (int) $id;
        }

        if (s81_table_exists($pdo, 'gunluk_bildirim_tamamlamalari')) {
            $dt = $pdo->prepare(
                'DELETE FROM gunluk_bildirim_tamamlamalari
                 WHERE not_metni LIKE :m1 OR not_metni LIKE :m2'
            );
            $dt->execute([
                'm1' => '%' . S81_SMOKE_MARKER . '%',
                'm2' => '%' . S81_SMOKE_MARKER_KEY . '%',
            ]);
            $deleted['gunluk_bildirim_tamamlamalari'] += $dt->rowCount();

            if ($userIds !== []) {
                $in = implode(',', array_map('intval', $userIds));
                $dt2 = $pdo->exec(
                    "DELETE FROM gunluk_bildirim_tamamlamalari
                     WHERE birim_amiri_user_id IN ($in) OR tamamlayan_user_id IN ($in)"
                );
                $deleted['gunluk_bildirim_tamamlamalari'] += (int) $dt2;
            }
        }

        if (s81_table_exists($pdo, 'gunluk_bildirimler')) {
            $db = $pdo->prepare(
                'DELETE FROM gunluk_bildirimler
                 WHERE aciklama LIKE :m1 OR aciklama LIKE :m2'
            );
            $db->execute([
                'm1' => '%' . S81_SMOKE_MARKER . '%',
                'm2' => '%' . S81_SMOKE_MARKER_KEY . '%',
            ]);
            $deleted['gunluk_bildirimler'] += $db->rowCount();

            if ($personelIds !== []) {
                $in = implode(',', array_map('intval', $personelIds));
                $db2 = $pdo->exec("DELETE FROM gunluk_bildirimler WHERE personel_id IN ($in)");
                $deleted['gunluk_bildirimler'] += (int) $db2;
            }
        }

        if ($personelIds !== []) {
            $in = implode(',', array_map('intval', $personelIds));
            $deleted['personeller'] += (int) $pdo->exec("DELETE FROM personeller WHERE id IN ($in)");
        }
        $dp = $pdo->prepare('DELETE FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil');
        $dp->execute(['tc' => S81_SMOKE_TC, 'sicil' => S81_SMOKE_SICIL]);
        $deleted['personeller'] += $dp->rowCount();

        if ($userIds !== []) {
            $in = implode(',', array_map('intval', $userIds));
            $deleted['user_subeler'] += (int) $pdo->exec("DELETE FROM user_subeler WHERE user_id IN ($in)");
            $deleted['users'] += (int) $pdo->exec("DELETE FROM users WHERE id IN ($in)");
        }
        $du = $pdo->prepare('DELETE FROM users WHERE username = :u');
        $du->execute(['u' => S81_SMOKE_USERNAME]);
        $deleted['users'] += $du->rowCount();

        $ds = $pdo->prepare('DELETE FROM subeler WHERE kod = :k');
        $ds->execute(['k' => S81_SMOKE_SUBE_KOD]);
        $deleted['subeler'] += $ds->rowCount();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S81_CLEANUP_FAILED',
            'error' => $e->getMessage(),
            'deleted' => $deleted,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $leftover = s81_smoke_leftovers($pdo);
    $ok = $leftover['total'] === 0;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S81_CLEANUP_OK' : 'S81_CLEANUP_INCOMPLETE',
        'deleted' => $deleted,
        'leftover' => $leftover,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'integrity') {
    $bad = s81_assert_production_db($pdo);
    if ($bad !== null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $bad], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $identity = s81_identity($pdo);
    $schema = s81_schema_state($pdo);
    $leftover = s81_smoke_leftovers($pdo);
    $ok = $leftover['total'] === 0;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S81_INTEGRITY_OK' : 'S81_INTEGRITY_SMOKE_LEFTOVER',
        'schema' => $schema,
        'leftover' => $leftover,
        'counts' => s81_relevant_counts($pdo),
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
