<?php
/**
 * ONE-SHOT S78-C3-R3 live migrate for 025_departmanlar_ad_unique.sql.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S78C3_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($tokenExpected === 'REPLACE_S78C3_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'preflight';

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

function s78_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_host' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s78_index_rows(PDO $pdo): array
{
    $stmt = $pdo->query("SHOW INDEX FROM departmanlar WHERE Key_name = 'uq_departmanlar_ad'");
    return $stmt ? $stmt->fetchAll() : [];
}

function s78_schema(PDO $pdo): array
{
    $create = $pdo->query('SHOW CREATE TABLE departmanlar')->fetch();
    $columns = $pdo->query('SHOW FULL COLUMNS FROM departmanlar')->fetchAll();
    $indexes = $pdo->query('SHOW INDEX FROM departmanlar')->fetchAll();
    $adCol = null;
    foreach ($columns as $col) {
        if (($col['Field'] ?? '') === 'ad') {
            $adCol = $col;
            break;
        }
    }
    $hasDurum = false;
    foreach ($columns as $col) {
        if (($col['Field'] ?? '') === 'durum') {
            $hasDurum = true;
            break;
        }
    }

    return [
        'create_table' => $create['Create Table'] ?? null,
        'ad_column' => $adCol,
        'has_durum' => $hasDurum,
        'uq_departmanlar_ad' => s78_index_rows($pdo),
        'all_indexes' => $indexes,
    ];
}

function s78_preflight(PDO $pdo): array
{
    $dup = $pdo->query(
        'SELECT ad, COUNT(*) AS adet, GROUP_CONCAT(id ORDER BY id) AS ids
         FROM departmanlar
         GROUP BY ad
         HAVING COUNT(*) > 1'
    )->fetchAll();

    $trimDup = $pdo->query(
        "SELECT TRIM(ad) AS normalize_ad, COUNT(*) AS adet,
                GROUP_CONCAT(CONCAT(id, ':', QUOTE(ad)) ORDER BY id SEPARATOR ' | ') AS kayitlar
         FROM departmanlar
         GROUP BY TRIM(ad)
         HAVING COUNT(*) > 1"
    )->fetchAll();

    $invalid = $pdo->query(
        'SELECT id, ad, durum, CHAR_LENGTH(ad) AS karakter
         FROM departmanlar
         WHERE ad IS NULL OR TRIM(ad) = \'\' OR CHAR_LENGTH(ad) > 120'
    )->fetchAll();

    $count = (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn();
    $rows = $pdo->query('SELECT id, ad, durum FROM departmanlar ORDER BY id')->fetchAll();

    return [
        'schema' => s78_schema($pdo),
        'duplicates' => $dup,
        'trim_duplicates' => $trimDup,
        'invalid' => $invalid,
        'toplam_departman' => $count,
        'rows' => $rows,
    ];
}

function s78_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s78_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function s78_php_sql_dump(PDO $pdo, string $dbName): string
{
    $out = [];
    $out[] = '-- S78-C3-R3 PHP SQL dump (shared-host fallback)';
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
        $create = $pdo->query('SHOW CREATE TABLE ' . s78_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . s78_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . s78_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            continue;
        }
        $cols = array_map('s78_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = s78_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . s78_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $out[] = 'COMMIT;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '';

    return implode("\n", $out);
}

function s78_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd-His');
    // Persist under api/public so download_backup works across requests on shared host.
    $path = __DIR__ . '/karmotor_medisa_pre_025_' . $stamp . '.sql';
    return $path;
}

if ($action === 'identity') {
    $identity = s78_identity($pdo);
    $ok = $identity['aktif_veritabani'] === 'karmotor_medisa';
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
        'config_db_name' => $name,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'preflight') {
    $identity = s78_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH',
            'identity' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $pre = s78_preflight($pdo);
    $uq = $pre['schema']['uq_departmanlar_ad'];
    $already = count($uq) > 0;
    $ad = $pre['schema']['ad_column'];
    $typeOk = is_array($ad) && stripos((string) ($ad['Type'] ?? ''), 'varchar(120)') !== false;
    $nullOk = is_array($ad) && strtoupper((string) ($ad['Null'] ?? '')) === 'NO';
    $dupOk = count($pre['duplicates']) === 0 && count($pre['trim_duplicates']) === 0;
    $invalidOk = count($pre['invalid']) === 0;
    $schemaOk = $typeOk && $nullOk && ($pre['schema']['has_durum'] === true);

    $code = 'S78_C3_PREFLIGHT_OK';
    if (!$schemaOk) {
        $code = 'S78_C3_PREFLIGHT_SCHEMA_BLOCKED';
    } elseif (!$dupOk || !$invalidOk) {
        $code = 'S78_C3_R3_BLOCKED_DUPLICATE_DATA';
    } elseif ($already) {
        $uqCol = (string) ($uq[0]['Column_name'] ?? '');
        $uqNonUnique = (int) ($uq[0]['Non_unique'] ?? 1);
        if ($uqCol !== 'ad' || $uqNonUnique !== 0) {
            $code = 'S78_C3_PREFLIGHT_INDEX_MISMATCH';
        } else {
            $code = 'S78_C3_INDEX_ALREADY_PRESENT';
        }
    }

    $ok = in_array($code, ['S78_C3_PREFLIGHT_OK', 'S78_C3_INDEX_ALREADY_PRESENT'], true);
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'identity' => $identity,
        'preflight' => $pre,
        'already_applied' => $already,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $identity = s78_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $backupPath = s78_backup_path();
    $meta = [
        'method' => null,
        'file' => basename($backupPath),
        'bytes' => 0,
        'sha256' => null,
        'path' => $backupPath,
        'contains_create_departmanlar' => false,
        'contains_insert_departmanlar' => false,
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
        $sql = s78_php_sql_dump($pdo, $name);
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
    $meta['contains_create_departmanlar'] = stripos($contents, 'CREATE TABLE') !== false && stripos($contents, 'departmanlar') !== false;
    $meta['contains_insert_departmanlar'] = stripos($contents, 'INSERT INTO') !== false && stripos($contents, 'departmanlar') !== false;
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;

    $ok = $meta['bytes'] > 0 && $meta['contains_create_departmanlar'] && $meta['contains_insert_departmanlar'];
    file_put_contents(__DIR__ . '/s78c3_latest_backup_path.txt', basename($backupPath));

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S78_C3_BACKUP_OK' : 'S78_C3_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'identity' => $identity,
        'toplam_departman' => (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s78c3_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_025_*.sql') ?: [];
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
    $identity = s78_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = s78_preflight($pdo);
    if (count($pre['duplicates']) > 0 || count($pre['trim_duplicates']) > 0 || count($pre['invalid']) > 0) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S78_C3_R3_BLOCKED_DUPLICATE_DATA',
            'preflight' => $pre,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $beforeCount = $pre['toplam_departman'];
    $uq = $pre['schema']['uq_departmanlar_ad'];
    if (count($uq) > 0) {
        $uqCol = (string) ($uq[0]['Column_name'] ?? '');
        $uqNonUnique = (int) ($uq[0]['Non_unique'] ?? 1);
        $ok = $uqCol === 'ad' && $uqNonUnique === 0;
        echo json_encode([
            'ok' => $ok,
            'code' => $ok ? 'S78_C3_INDEX_ALREADY_PRESENT' : 'S78_C3_PREFLIGHT_INDEX_MISMATCH',
            'skipped_apply' => true,
            'before_count' => $beforeCount,
            'after_count' => $beforeCount,
            'index' => $uq,
            'identity' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $file = '025_departmanlar_ad_unique.sql';
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

    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        // Single additive statement from 025.
        $pdo->exec("ALTER TABLE departmanlar ADD UNIQUE KEY uq_departmanlar_ad (ad)");
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S78_C3_MIGRATE_FAILED',
            'error' => $e->getMessage(),
            'sqlstate' => ($e instanceof PDOException && isset($e->errorInfo[0])) ? $e->errorInfo[0] : null,
            'driver_code' => ($e instanceof PDOException && isset($e->errorInfo[1])) ? $e->errorInfo[1] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $index = s78_index_rows($pdo);
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn();
    $ok =
        count($index) > 0
        && (string) ($index[0]['Column_name'] ?? '') === 'ad'
        && (int) ($index[0]['Non_unique'] ?? 1) === 0
        && $afterCount === $beforeCount;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S78_C3_MIGRATE_OK' : 'S78_C3_MIGRATE_POSTCHECK_FAILED',
        'skipped_apply' => false,
        'applied' => [
            'file' => $file,
            'sha256' => hash('sha256', $sql),
            'bytes' => strlen($sql),
            'statement' => 'ALTER TABLE departmanlar ADD UNIQUE KEY uq_departmanlar_ad (ad)',
        ],
        'before_count' => $beforeCount,
        'after_count' => $afterCount,
        'index' => $index,
        'create_table' => ($pdo->query('SHOW CREATE TABLE departmanlar')->fetch()['Create Table'] ?? null),
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'postcheck') {
    $identity = s78_identity($pdo);
    $index = s78_index_rows($pdo);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn();
    $dups = $pdo->query(
        'SELECT ad, COUNT(*) AS adet FROM departmanlar GROUP BY ad HAVING COUNT(*) > 1'
    )->fetchAll();
    $ok =
        $identity['aktif_veritabani'] === 'karmotor_medisa'
        && count($index) > 0
        && (string) ($index[0]['Column_name'] ?? '') === 'ad'
        && (int) ($index[0]['Non_unique'] ?? 1) === 0
        && (int) ($index[0]['Seq_in_index'] ?? 0) === 1
        && count($dups) === 0;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S78_C3_POSTCHECK_OK' : 'S78_C3_POSTCHECK_FAILED',
        'identity' => $identity,
        'index' => $index,
        'toplam_departman' => $count,
        'duplicates' => $dups,
        'create_table' => ($pdo->query('SHOW CREATE TABLE departmanlar')->fetch()['Create Table'] ?? null),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'duplicate_proof') {
    // Option A: attempt duplicate insert inside transaction and roll back.
    $identity = s78_identity($pdo);
    $before = (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn();
    $sample = $pdo->query('SELECT ad FROM departmanlar ORDER BY id LIMIT 1')->fetchColumn();
    if ($sample === false || $sample === null || $sample === '') {
        echo json_encode(['ok' => false, 'code' => 'NO_SAMPLE_ROW', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $duplicateError = null;
    $sqlstate = null;
    $driverCode = null;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO departmanlar (ad, durum) VALUES (:ad, 'AKTIF')");
        $stmt->execute(['ad' => (string) $sample]);
        // If unique missing, insert would succeed — roll back anyway.
        $pdo->rollBack();
        $duplicateError = 'INSERT_SUCCEEDED_UNEXPECTEDLY';
    } catch (Throwable $e) {
        $duplicateError = $e->getMessage();
        if ($e instanceof PDOException) {
            $sqlstate = $e->errorInfo[0] ?? null;
            $driverCode = $e->errorInfo[1] ?? null;
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    $after = (int) $pdo->query('SELECT COUNT(*) FROM departmanlar')->fetchColumn();
    $ok = ((string) $sqlstate === '23000' || (int) $driverCode === 1062) && $after === $before;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S78_C3_DUPLICATE_PROOF_OK' : 'S78_C3_DUPLICATE_PROOF_FAILED',
        'sample_ad' => (string) $sample,
        'error' => $duplicateError,
        'sqlstate' => $sqlstate,
        'driver_code' => $driverCode,
        'before_count' => $before,
        'after_count' => $after,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
