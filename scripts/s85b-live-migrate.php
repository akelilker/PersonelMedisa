<?php
/**
 * ONE-SHOT S85-B live migrate for 036/037 SGK owner schema.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * No catalog/policy/personel seed. No candidate writes. UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S85B_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($tokenExpected === 'UNSET_S85B_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'identity';
$expected036 = '4eeeaf3897fd2bb01689d665ae0a1f7d52d04c8b191dde2ae7179b0ccd06be73';
$expected037 = '962d83eebdaad008ea8bcaf4e0caf4e750af408145e6ad351bfe94954c7b699d';

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

function s85_identity(PDO $pdo, string $configDb, string $configHost): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'config_db_name' => $configDb,
        'config_db_host' => $configHost,
        'db_hostname' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s85_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s85_count(PDO $pdo, string $table): int
{
    if (!s85_table_exists($pdo, $table)) {
        return -1;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
}

function s85_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s85_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

/** @return array<int, string> */
function s85_sgk_new_tables(): array
{
    return [
        'sgk_kaynak_manifestleri',
        'sgk_eksik_gun_katalog_surumleri',
        'sgk_eksik_gun_kodlari',
        'sgk_eksik_gun_kod_cakismalari',
        'sgk_surec_neden_eslemeleri',
        'sgk_sirket_politika_surumleri',
        'sgk_sirket_politika_degerleri',
        'sgk_eksik_gun_belgeleri',
        'sgk_belge_surec_baglantilari',
        'sgk_personel_sigortalilik_surumleri',
        'sgk_is_goremezlik_finans_kayitlari',
        'maas_hesaplama_sgk_snapshotlari',
        'sgk_hesap_auditleri',
    ];
}

/** @return array<string, mixed> */
function s85_inventory(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    sort($tables);
    $new = [];
    foreach (s85_sgk_new_tables() as $t) {
        $new[$t] = s85_table_exists($pdo, $t) ? s85_count($pdo, $t) : -1;
    }
    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
           AND TRIGGER_NAME IN ('trg_mhss_no_update','trg_mhss_no_delete','trg_sgk_ha_no_update','trg_sgk_ha_no_delete')
         ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    return [
        'db' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'table_count' => count($tables),
        'tables' => $tables,
        'core_counts' => [
            'personeller' => s85_count($pdo, 'personeller'),
            'surecler' => s85_count($pdo, 'surecler'),
            'puantaj_aylik_muhurleri' => s85_count($pdo, 'puantaj_aylik_muhurleri'),
            'puantaj_aylik_muhur_satirlari' => s85_count($pdo, 'puantaj_aylik_muhur_satirlari'),
            'maas_hesaplama_donem_snapshotlari' => s85_count($pdo, 'maas_hesaplama_donem_snapshotlari'),
            'maas_hesaplama_personel_snapshotlari' => s85_count($pdo, 'maas_hesaplama_personel_snapshotlari'),
            'maas_hesaplama_girdi_snapshotlari' => s85_count($pdo, 'maas_hesaplama_girdi_snapshotlari'),
            'maas_hesaplama_adaylari' => s85_count($pdo, 'maas_hesaplama_adaylari'),
            'personel_bordro_kapsamlari' => s85_count($pdo, 'personel_bordro_kapsamlari'),
        ],
        'sgk_counts' => $new,
        'sgk_immutable_triggers' => $triggers,
        'has_035_personel_bordro_kapsamlari' => s85_table_exists($pdo, 'personel_bordro_kapsamlari'),
    ];
}

/** @return array<string, mixed> */
function s85_preflight(PDO $pdo): array
{
    $inv = s85_inventory($pdo);
    $present = [];
    $missing = [];
    foreach (s85_sgk_new_tables() as $t) {
        if (s85_table_exists($pdo, $t)) {
            $present[] = $t;
        } else {
            $missing[] = $t;
        }
    }
    $partial = count($present) > 0 && count($missing) > 0;
    $already = count($present) === count(s85_sgk_new_tables());

    return [
        'inventory' => $inv,
        'sgk_tables_present' => $present,
        'sgk_tables_missing' => $missing,
        'partial_036_trace' => $partial,
        'already_fully_applied' => $already,
        'has_035' => $inv['has_035_personel_bordro_kapsamlari'],
    ];
}

function s85_php_sql_dump(PDO $pdo, string $dbName): string
{
    $out = [];
    $out[] = '-- S85-B PHP SQL dump (shared-host capable)';
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
        $create = $pdo->query('SHOW CREATE TABLE ' . s85_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . s85_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';
        $rows = $pdo->query('SELECT * FROM ' . s85_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            continue;
        }
        $cols = array_map('s85_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 40) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = s85_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . s85_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($triggers as $triggerName) {
        $row = $pdo->query('SHOW CREATE TRIGGER ' . s85_quote_ident((string) $triggerName))->fetch();
        $sql = (string) ($row['SQL Original Statement'] ?? $row['Create Trigger'] ?? '');
        if ($sql !== '') {
            $out[] = 'DROP TRIGGER IF EXISTS ' . s85_quote_ident((string) $triggerName) . ';';
            $out[] = $sql . ';';
            $out[] = '';
        }
    }

    $routines = $pdo->query(
        "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES
         WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME"
    )->fetchAll();
    foreach ($routines as $routine) {
        $rName = (string) $routine['ROUTINE_NAME'];
        $rType = strtoupper((string) $routine['ROUTINE_TYPE']);
        if ($rType === 'PROCEDURE') {
            $row = $pdo->query('SHOW CREATE PROCEDURE ' . s85_quote_ident($rName))->fetch();
            $sql = (string) ($row['Create Procedure'] ?? '');
            $out[] = 'DROP PROCEDURE IF EXISTS ' . s85_quote_ident($rName) . ';';
        } else {
            $row = $pdo->query('SHOW CREATE FUNCTION ' . s85_quote_ident($rName))->fetch();
            $sql = (string) ($row['Create Function'] ?? '');
            $out[] = 'DROP FUNCTION IF EXISTS ' . s85_quote_ident($rName) . ';';
        }
        if ($sql !== '') {
            $out[] = $sql . ';';
            $out[] = '';
        }
    }

    try {
        $events = $pdo->query(
            "SELECT EVENT_NAME FROM information_schema.EVENTS
             WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($events as $eventName) {
            $row = $pdo->query('SHOW CREATE EVENT ' . s85_quote_ident((string) $eventName))->fetch();
            $sql = (string) ($row['Create Event'] ?? '');
            if ($sql !== '') {
                $out[] = 'DROP EVENT IF EXISTS ' . s85_quote_ident((string) $eventName) . ';';
                $out[] = $sql . ';';
                $out[] = '';
            }
        }
    } catch (Throwable $e) {
        $out[] = '-- EVENTS skipped: ' . $e->getMessage();
    }

    $out[] = 'COMMIT;';
    $out[] = 'SET UNIQUE_CHECKS=1;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '-- Dump completed at ' . gmdate('c');
    $out[] = '';

    return implode("\n", $out);
}

function s85_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd-His');
    $path = __DIR__ . '/karmotor_medisa_pre_s85b_' . $stamp . '.sql';

    return $path;
}

/** @return array<int, string> */
function s85_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function s85_apply_file(PDO $pdo, string $file, string $expectedSha): array
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
    foreach (s85_split_sql($sql) as $statement) {
        $pdo->exec($statement);
        $count++;
    }

    return ['file' => basename($file), 'sha256' => $sha, 'statements' => $count];
}

if ($action === 'identity') {
    $identity = s85_identity($pdo, $name, $host);
    $ok = $identity['aktif_veritabani'] === 'karmotor_medisa' && $name === 'karmotor_medisa';
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'preflight') {
    $identity = s85_identity($pdo, $name, $host);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa' || $name !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pre = s85_preflight($pdo);
    $code = 'S85B_PREFLIGHT_OK';
    if (!$pre['has_035']) {
        $code = 'S85B_PREFLIGHT_035_MISSING';
    } elseif ($pre['partial_036_trace']) {
        $code = 'S85B_PREFLIGHT_PARTIAL_036_TRACE';
    } elseif ($pre['already_fully_applied']) {
        $code = 'S85B_ALREADY_APPLIED';
    }
    $ok = $code === 'S85B_PREFLIGHT_OK' || $code === 'S85B_ALREADY_APPLIED';
    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'identity' => $identity,
        'preflight' => $pre,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $identity = s85_identity($pdo, $name, $host);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $inventoryBefore = s85_inventory($pdo);
    $backupPath = s85_backup_path();
    $meta = [
        'method' => null,
        'file' => basename($backupPath),
        'bytes' => 0,
        'sha256' => null,
        'path' => $backupPath,
        'db' => 'karmotor_medisa',
        'table_count' => $inventoryBefore['table_count'],
        'contains_create_table' => false,
        'contains_insert' => false,
        'contains_trigger' => false,
        'contains_commit_or_completed' => false,
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
        $sql = s85_php_sql_dump($pdo, $name);
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
    $meta['contains_trigger'] = stripos($contents, 'TRIGGER') !== false;
    $meta['contains_commit_or_completed'] = stripos($contents, 'COMMIT') !== false || stripos($contents, 'Dump completed') !== false;
    file_put_contents(__DIR__ . '/s85b_latest_backup_path.txt', basename($backupPath));
    $ok = $meta['bytes'] > 0 && $meta['contains_create_table'] && $meta['contains_insert'] && $meta['contains_commit_or_completed'];
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S85B_BACKUP_OK' : 'S85B_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'inventory' => $inventoryBefore,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s85b_latest_backup_path.txt';
    if (!is_file($marker)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'BACKUP_MARKER_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $file = basename(trim((string) file_get_contents($marker)));
    $path = __DIR__ . '/' . $file;
    if (!is_file($path) || strpos($file, 'karmotor_medisa_pre_s85b_') !== 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'BACKUP_FILE_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . (string) filesize($path));
    readfile($path);
    exit;
}

if ($action === 'migrate') {
    $identity = s85_identity($pdo, $name, $host);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa' || $name !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $before = s85_preflight($pdo);
    if (!$before['has_035']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S85B_PREFLIGHT_035_MISSING', 'preflight' => $before], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($before['partial_036_trace']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S85B_PREFLIGHT_PARTIAL_036_TRACE', 'preflight' => $before], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $started = gmdate('c');
    $applied = [];
    try {
        $applied[] = s85_apply_file($pdo, __DIR__ . '/036_sgk_prim_gunu_owner.sql', $expected036);
        $applied[] = s85_apply_file($pdo, __DIR__ . '/037_sgk_resmi_kaynak_manifesti_v1.sql', $expected037);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'S85B_MIGRATE_FAILED', 'error' => $e->getMessage(), 'applied' => $applied], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $after = s85_inventory($pdo);
    $ok = s85_table_exists($pdo, 'sgk_kaynak_manifestleri')
        && s85_count($pdo, 'sgk_kaynak_manifestleri') === 8
        && s85_count($pdo, 'sgk_eksik_gun_kodlari') === 0
        && s85_count($pdo, 'sgk_surec_neden_eslemeleri') === 0
        && s85_count($pdo, 'sgk_sirket_politika_surumleri') === 0
        && count($after['sgk_immutable_triggers']) === 4;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S85B_MIGRATE_OK' : 'S85B_MIGRATE_POSTCHECK_FAILED',
        'started_at_utc' => $started,
        'finished_at_utc' => gmdate('c'),
        'applied' => $applied,
        'inventory_before_core' => $before['inventory']['core_counts'],
        'inventory_after' => $after,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'postcheck') {
    $inv = s85_inventory($pdo);
    $ok = $inv['db'] === 'karmotor_medisa'
        && s85_count($pdo, 'sgk_kaynak_manifestleri') === 8
        && s85_count($pdo, 'sgk_eksik_gun_kodlari') === 0
        && s85_count($pdo, 'sgk_eksik_gun_katalog_surumleri') === 0
        && s85_count($pdo, 'sgk_surec_neden_eslemeleri') === 0
        && s85_count($pdo, 'sgk_sirket_politika_surumleri') === 0
        && s85_count($pdo, 'maas_hesaplama_sgk_snapshotlari') === 0
        && s85_count($pdo, 'sgk_hesap_auditleri') === 0
        && count($inv['sgk_immutable_triggers']) === 4
        && $inv['has_035_personel_bordro_kapsamlari'];
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S85B_POSTCHECK_OK' : 'S85B_POSTCHECK_FAILED',
        'inventory' => $inv,
        'identity' => s85_identity($pdo, $name, $host),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
