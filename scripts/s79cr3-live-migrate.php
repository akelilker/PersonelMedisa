<?php
/**
 * ONE-SHOT S79-C-R3 live migrate for 028_fazla_calisma_odeme_tercihleri.sql.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S79CR3_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_S79CR3_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_S79CR3_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'preflight';

const S79_EXPECTED_MIGRATION_SHA256 = '83916f10d8fef3180a9896d0b99b553490463b39c3cd9e6482829c2fc47aa930';
const S79_MIGRATION_FILE = '028_fazla_calisma_odeme_tercihleri.sql';
const S79_SMOKE_MARKER = 'S79-C-R3 Production Smoke';
const S79_SMOKE_WEEK_START = '2036-07-07'; // Monday
const S79_SMOKE_WEEK_END = '2036-07-13';
const S79_SMOKE_TC = '90079000036';
const S79_SMOKE_SICIL = 'S79CR3-SMOKE';

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
        'kapanis_count' => s79_count($pdo, 'haftalik_kapanislar'),
        'snapshot_count' => s79_count($pdo, 'haftalik_kapanis_satirlari'),
        'personel_count' => s79_count($pdo, 'personeller'),
        'user_count' => s79_count($pdo, 'users'),
        'tercih_count' => s79_count($pdo, 'fazla_calisma_odeme_tercihleri'),
        'audit_count' => s79_count($pdo, 'fazla_calisma_odeme_tercihi_audit'),
        'sube_count' => s79_count($pdo, 'subeler'),
        'donem_kilit_count' => s79_count($pdo, 'puantaj_donem_kilitleri'),
        'aylik_muhur_count' => s79_count($pdo, 'puantaj_aylik_muhurleri'),
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
    $parents = [
        'haftalik_kapanislar',
        'haftalik_kapanis_satirlari',
        'personeller',
        'users',
        'puantaj_donem_kilitleri',
        'puantaj_aylik_muhurleri',
    ];
    $out = [];
    foreach ($parents as $table) {
        $exists = s79_table_exists($pdo, $table);
        $create = $exists ? s79_show_create($pdo, $table) : null;
        $engineOk = is_string($create) && stripos($create, 'ENGINE=InnoDB') !== false;
        $out[$table] = [
            'exists' => $exists,
            'innodb' => $engineOk,
            'create_excerpt' => is_string($create) ? substr($create, 0, 500) : null,
        ];
    }
    $szExists = s79_table_exists($pdo, 'serbest_zaman_events');
    $out['serbest_zaman_events'] = [
        'exists' => $szExists,
        'innodb' => $szExists ? (stripos((string) s79_show_create($pdo, 'serbest_zaman_events'), 'ENGINE=InnoDB') !== false) : false,
        'optional' => true,
        'create_excerpt' => $szExists ? substr((string) s79_show_create($pdo, 'serbest_zaman_events'), 0, 400) : null,
    ];

    return $out;
}

function s79_fk_rows(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
               'fazla_calisma_odeme_tercihleri',
               'fazla_calisma_odeme_tercihi_audit'
           )
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
    $main = s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri');
    $audit = s79_table_exists($pdo, 'fazla_calisma_odeme_tercihi_audit');

    return [
        'fazla_calisma_odeme_tercihleri' => $main,
        'fazla_calisma_odeme_tercihi_audit' => $audit,
        'both_absent' => !$main && !$audit,
        'both_present' => $main && $audit,
        'partial' => ($main xor $audit),
        'main' => $main ? s79_table_schema($pdo, 'fazla_calisma_odeme_tercihleri') : null,
        'audit' => $audit ? s79_table_schema($pdo, 'fazla_calisma_odeme_tercihi_audit') : null,
        'fks' => ($main || $audit) ? s79_fk_rows($pdo) : [],
    ];
}

function s79_schema_matches_contract(PDO $pdo): array
{
    $main = s79_table_schema($pdo, 'fazla_calisma_odeme_tercihleri');
    $audit = s79_table_schema($pdo, 'fazla_calisma_odeme_tercihi_audit');
    $fks = s79_fk_rows($pdo);
    $issues = [];

    if ($main === null || $audit === null) {
        $issues[] = 'tables_missing';
        return ['ok' => false, 'issues' => $issues, 'main' => $main, 'audit' => $audit, 'fks' => $fks];
    }

    $mCreate = (string) ($main['create_table'] ?? '');
    $aCreate = (string) ($audit['create_table'] ?? '');

    if (stripos($mCreate, 'uq_fcot_snapshot') === false) {
        $issues[] = 'missing_uq_fcot_snapshot';
    }
    if (stripos($mCreate, 'secen_kullanici_id') === false) {
        $issues[] = 'missing_secen_kullanici_id';
    }
    if (stripos($mCreate, 'secim_zamani') === false) {
        $issues[] = 'missing_secim_zamani';
    }
    if (stripos($mCreate, 'utf8mb4') === false) {
        $issues[] = 'main_charset_mismatch';
    }
    if (stripos($aCreate, 'utf8mb4') === false) {
        $issues[] = 'audit_charset_mismatch';
    }
    if (stripos($aCreate, 'secen_kullanici_id') === false || stripos($aCreate, 'secim_zamani') === false) {
        $issues[] = 'audit_canonical_columns_mismatch';
    }
    if (stripos($mCreate, 'ENGINE=InnoDB') === false || stripos($aCreate, 'ENGINE=InnoDB') === false) {
        $issues[] = 'engine_not_innodb';
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
        'main' => $main,
        'audit' => $audit,
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
    $out[] = '-- S79-C-R3 PHP SQL dump (shared-host fallback)';
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
    $path = __DIR__ . '/karmotor_medisa_pre_028_' . $stamp . '.sql';

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

function s79_smoke_marker_path(): string
{
    return __DIR__ . '/s79cr3_smoke_marker.json';
}

function s79_load_smoke_marker(): ?array
{
    $path = s79_smoke_marker_path();
    if (!is_file($path)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);

    return is_array($data) ? $data : null;
}

function s79_marker_rows(PDO $pdo): array
{
    $out = ['tercih' => 0, 'audit' => 0, 'personel' => 0, 'kapanis' => 0];
    if (s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri WHERE gerekce LIKE :m');
        $stmt->execute(['m' => '%' . S79_SMOKE_MARKER . '%']);
        $out['tercih'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'fazla_calisma_odeme_tercihi_audit')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit WHERE gerekce LIKE :m');
        $stmt->execute(['m' => '%' . S79_SMOKE_MARKER . '%']);
        $out['audit'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'personeller')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil');
        $stmt->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL]);
        $out['personel'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'haftalik_kapanislar')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM haftalik_kapanislar WHERE hafta_baslangic = :h');
        $stmt->execute(['h' => S79_SMOKE_WEEK_START]);
        $out['kapanis'] = (int) $stmt->fetchColumn();
    }

    return $out;
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
            'code' => 'S79_C_R3_BLOCKED_DB_IDENTITY',
            'identity' => $identity,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $parents = s79_parent_preflight($pdo);
    $parentOk = true;
    foreach ($parents as $key => $row) {
        if (!empty($row['optional'])) {
            continue;
        }
        if (!$row['exists'] || !$row['innodb']) {
            $parentOk = false;
            break;
        }
    }

    $existing = s79_existing_schema($pdo);
    $counts = s79_counts($pdo);
    $schemaMatch = null;

    if (!$parentOk) {
        $code = 'S79_C_R3_BLOCKED_PARENT_SCHEMA';
        $ok = false;
    } elseif ($existing['partial']) {
        $code = 'S79_C_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
        $ok = false;
    } elseif ($existing['both_present']) {
        $schemaMatch = s79_schema_matches_contract($pdo);
        if ($schemaMatch['ok']) {
            $code = 'S79_C_SCHEMA_ALREADY_APPLIED';
            $ok = true;
        } else {
            $code = 'S79_C_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
            $ok = false;
        }
    } else {
        $code = 'S79_C_PREFLIGHT_OK';
        $ok = true;
    }

    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'already_applied' => $existing['both_present'] && $ok && $code === 'S79_C_SCHEMA_ALREADY_APPLIED',
        'identity' => $identity,
        'parents' => $parents,
        'existing' => $existing,
        'counts' => $counts,
        'schema_match' => $schemaMatch,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S79_C_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
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
        'contains_create_haftalik_kapanislar' => false,
        'contains_create_haftalik_kapanis_satirlari' => false,
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
    $meta['contains_create_haftalik_kapanislar'] = stripos($contents, 'haftalik_kapanislar') !== false;
    $meta['contains_create_haftalik_kapanis_satirlari'] = stripos($contents, 'haftalik_kapanis_satirlari') !== false;
    $meta['contains_insert'] = stripos($contents, 'INSERT INTO') !== false || stripos($contents, 'INSERT ') !== false;
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;

    $ok = $meta['bytes'] > 0
        && $meta['contains_create_personeller']
        && $meta['contains_create_haftalik_kapanislar']
        && $meta['contains_create_haftalik_kapanis_satirlari']
        && $meta['contains_insert'];
    file_put_contents(__DIR__ . '/s79cr3_latest_backup_path.txt', basename($backupPath));

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_C_BACKUP_OK' : 'S79_C_R3_BLOCKED_BACKUP',
        'backup' => $meta,
        'identity' => $identity,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s79cr3_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_028_*.sql') ?: [];
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
        echo json_encode(['ok' => false, 'code' => 'S79_C_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existing = s79_existing_schema($pdo);
    $beforeCounts = s79_counts($pdo);

    if ($existing['partial']) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_C_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
            'existing' => $existing,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($existing['both_present']) {
        $match = s79_schema_matches_contract($pdo);
        echo json_encode([
            'ok' => $match['ok'],
            'code' => $match['ok'] ? 'S79_C_MIGRATE_OK' : 'S79_C_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
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
            'code' => 'S79_C_R3_BLOCKED_MIGRATION_DRIFT',
            'expected_sha256' => S79_EXPECTED_MIGRATION_SHA256,
            'actual_sha256' => $sha,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

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
    // Allow "ON UPDATE CURRENT_TIMESTAMP"; refuse standalone DML UPDATE.
    $noOnUpdate = preg_replace('/\bON\s+UPDATE\b/i', 'ON_UPDATE_OK', $withoutComments) ?? $withoutComments;
    if (preg_match('/\bUPDATE\b/i', $noOnUpdate)) {
        $badHits[] = 'UPDATE';
    }
    if (preg_match('/\bCREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\b/i', $withoutComments)) {
        $badHits[] = 'CREATE TABLE IF NOT EXISTS';
    }
    if ($badHits !== []) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_C_R3_BLOCKED_MIGRATION_DRIFT',
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
            'code' => 'S79_C_R3_BLOCKED_MIGRATION_APPLY',
            'error' => $e->getMessage(),
            'sqlstate' => ($e instanceof PDOException && isset($e->errorInfo[0])) ? $e->errorInfo[0] : null,
            'driver_code' => ($e instanceof PDOException && isset($e->errorInfo[1])) ? $e->errorInfo[1] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $match = s79_schema_matches_contract($pdo);
    $afterCounts = s79_counts($pdo);
    $parentStable =
        $afterCounts['kapanis_count'] === $beforeCounts['kapanis_count']
        && $afterCounts['snapshot_count'] === $beforeCounts['snapshot_count']
        && $afterCounts['personel_count'] === $beforeCounts['personel_count']
        && $afterCounts['user_count'] === $beforeCounts['user_count'];
    $emptyNew = $afterCounts['tercih_count'] === 0 && $afterCounts['audit_count'] === 0;
    $ok = $match['ok'] && $parentStable && $emptyNew;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_C_MIGRATE_OK' : 'S79_C_R3_BLOCKED_MIGRATION_APPLY',
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
        'code' => $ok ? 'S79_C_POSTCHECK_OK' : 'S79_C_MIGRATION_APPLIED_SCHEMA_ACCEPTANCE_BLOCKED',
        'identity' => $identity,
        'counts' => $counts,
        'schema_match' => $match,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'counts') {
    echo json_encode([
        'ok' => true,
        'code' => 'S79_C_COUNTS_OK',
        'counts' => s79_counts($pdo),
        'identity' => s79_identity($pdo),
        'marker_rows' => s79_marker_rows($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'integrity') {
    $counts = s79_counts($pdo);
    $orphan = 0;
    if (s79_table_exists($pdo, 'fazla_calisma_odeme_tercihi_audit') && s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri')) {
        $orphan = (int) $pdo->query(
            'SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit a
             LEFT JOIN fazla_calisma_odeme_tercihleri t ON t.id = a.tercih_id
             WHERE t.id IS NULL'
        )->fetchColumn();
    }
    $markers = s79_marker_rows($pdo);
    $ok = $orphan === 0
        && $markers['tercih'] === 0
        && $markers['audit'] === 0
        && $markers['personel'] === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_C_FINAL_INTEGRITY_OK' : 'S79_C_INTEGRITY_FAILED',
        'counts' => $counts,
        'orphan_audit' => $orphan,
        'marker_rows' => $markers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_prepare') {
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S79_C_R3_BLOCKED_DB_IDENTITY'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri') || !s79_table_exists($pdo, 'haftalik_kapanis_satirlari')) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SCHEMA_NOT_READY'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sube = $pdo->query('SELECT id FROM subeler ORDER BY id ASC LIMIT 1')->fetch();
    $userRow = $pdo->query("SELECT id FROM users WHERE rol = 'GENEL_YONETICI' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$sube || !$userRow) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_SCOPE_UNAVAILABLE'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $subeId = (int) $sube['id'];
    $userId = (int) $userRow['id'];

    $dow = (int) $pdo->query("SELECT DAYOFWEEK('" . S79_SMOKE_WEEK_START . "')")->fetchColumn();
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

    $existingPersonel = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil LIMIT 1');
    $existingPersonel->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL]);
    if ($existingPersonel->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_PERSONEL_ALREADY_EXISTS'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $insP = $pdo->prepare(
            "INSERT INTO personeller (
                tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
                sicil_no, ise_giris_tarihi, sube_id, departman_id, aktif_durum
             ) VALUES (
                :tc, 'S79CR3', 'Smoke', '1990-01-01', '05000000000', 'S79CR3 Contact', '05000000001',
                :sicil, '2030-01-01', :sube_id, NULL, 'AKTIF'
             )"
        );
        $insP->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL, 'sube_id' => $subeId]);
        $personelId = (int) $pdo->lastInsertId();

        $insK = $pdo->prepare(
            "INSERT INTO haftalik_kapanislar (
                sube_id, hafta_baslangic, hafta_bitis, departman_id,
                state, personel_sayisi, snapshot_satir_sayisi, kaynak_versiyon, created_by
             ) VALUES (
                :sube_id, :hb, :he, NULL,
                'KAPANDI', 1, 1, 'A2_MOTOR_V1', :created_by
             )"
        );
        $insK->execute([
            'sube_id' => $subeId,
            'hb' => S79_SMOKE_WEEK_START,
            'he' => S79_SMOKE_WEEK_END,
            'created_by' => $userId,
        ]);
        $kapanisId = (int) $pdo->lastInsertId();

        $insS = $pdo->prepare(
            "INSERT INTO haftalik_kapanis_satirlari (
                kapanis_id, personel_id, departman_id,
                hafta_baslangic, hafta_bitis, yil, hafta_no,
                state, kaynak_versiyon,
                toplam_net_dakika, normal_calisma_dakika, fazla_calisma_dakika,
                fazla_surelerle_calisma_dakika, tam_hafta_verisi,
                compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi,
                hesaplama_zamani, kaynak_gun_sayisi, notlar_json
             ) VALUES (
                :kapanis_id, :personel_id, NULL,
                :hb, :he, 2036, 28,
                'KAPANDI', 'A2_MOTOR_V1',
                2820, 2700, 120,
                0, 1,
                '[]', 0, 0,
                '2036-07-14 00:00:00', 7, NULL
             )"
        );
        $insS->execute([
            'kapanis_id' => $kapanisId,
            'personel_id' => $personelId,
            'hb' => S79_SMOKE_WEEK_START,
            'he' => S79_SMOKE_WEEK_END,
        ]);
        $snapshotId = (int) $pdo->lastInsertId();

        // Ensure smoke month is not sealed for this sube.
        $muhurChk = $pdo->prepare(
            'SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = :s AND yil = 2036 AND ay = 7 LIMIT 1'
        );
        $muhurChk->execute(['s' => $subeId]);
        $existingMuhurId = $muhurChk->fetchColumn();
        if ($existingMuhurId !== false) {
            throw new RuntimeException('SMOKE_MONTH_ALREADY_SEALED');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_C_SMOKE_PREPARE_FAILED',
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $meta = [
        'marker' => S79_SMOKE_MARKER,
        'sube_id' => $subeId,
        'user_id' => $userId,
        'personel_id' => $personelId,
        'kapanis_id' => $kapanisId,
        'snapshot_id' => $snapshotId,
        'hafta_baslangic' => S79_SMOKE_WEEK_START,
        'hafta_bitis' => S79_SMOKE_WEEK_END,
        'muhur_id' => null,
        'sz_event_id' => null,
        'created_at_utc' => gmdate('c'),
    ];
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'ok' => true,
        'code' => 'S79_C_SMOKE_PREPARE_OK',
        'fixture' => $meta,
        'counts' => s79_counts($pdo),
        'serbest_zaman_events_exists' => s79_table_exists($pdo, 'serbest_zaman_events'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_period_lock') {
    $meta = s79_load_smoke_marker();
    if ($meta === null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_MARKER_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $subeId = (int) ($meta['sube_id'] ?? 0);
    $userId = (int) ($meta['user_id'] ?? 0);
    if ($subeId < 1) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_SCOPE_INVALID'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $chk = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = :s AND yil = 2036 AND ay = 7 LIMIT 1');
    $chk->execute(['s' => $subeId]);
    $existing = $chk->fetchColumn();
    if ($existing !== false) {
        $meta['muhur_id'] = (int) $existing;
        $meta['muhur_owned'] = false;
        file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        echo json_encode(['ok' => true, 'code' => 'S79_C_SMOKE_PERIOD_LOCKED', 'muhur_id' => (int) $existing, 'owned' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ins = $pdo->prepare(
        "INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by)
         VALUES (:sube_id, 2036, 7, '2036-07', 'MUHURLENDI', 0, :created_by)"
    );
    $ins->execute(['sube_id' => $subeId, 'created_by' => $userId > 0 ? $userId : null]);
    $muhurId = (int) $pdo->lastInsertId();
    $meta['muhur_id'] = $muhurId;
    $meta['muhur_owned'] = true;
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'ok' => true,
        'code' => 'S79_C_SMOKE_PERIOD_LOCKED',
        'muhur_id' => $muhurId,
        'owned' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_period_unlock') {
    $meta = s79_load_smoke_marker();
    if ($meta === null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_MARKER_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $muhurId = (int) ($meta['muhur_id'] ?? 0);
    $owned = !empty($meta['muhur_owned']);
    $deleted = 0;
    if ($muhurId > 0 && $owned) {
        $chk = $pdo->prepare('SELECT id, sube_id, yil, ay FROM puantaj_aylik_muhurleri WHERE id = :id');
        $chk->execute(['id' => $muhurId]);
        $row = $chk->fetch();
        if ($row && (int) $row['yil'] === 2036 && (int) $row['ay'] === 7 && (int) $row['sube_id'] === (int) $meta['sube_id']) {
            $del = $pdo->prepare('DELETE FROM puantaj_aylik_muhurleri WHERE id = :id');
            $del->execute(['id' => $muhurId]);
            $deleted = $del->rowCount();
        }
    }
    $meta['muhur_id'] = null;
    $meta['muhur_owned'] = false;
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'ok' => true,
        'code' => 'S79_C_SMOKE_PERIOD_UNLOCKED',
        'deleted' => $deleted,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_sz_create') {
    $meta = s79_load_smoke_marker();
    if ($meta === null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_MARKER_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!s79_table_exists($pdo, 'serbest_zaman_events')) {
        echo json_encode([
            'ok' => true,
            'code' => 'S79_C_SMOKE_SZ_SKIPPED',
            'reason' => 'serbest_zaman_events table absent',
            'tested' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $tercihId = isset($_GET['tercih_id']) ? (int) $_GET['tercih_id'] : 0;
    $personelId = (int) ($meta['personel_id'] ?? 0);
    if ($tercihId < 1 || $personelId < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'tercih_id required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Discover columns dynamically for production schema variance.
    $cols = $pdo->query('SHOW COLUMNS FROM serbest_zaman_events')->fetchAll(PDO::FETCH_COLUMN);
    $colSet = array_flip(array_map('strval', $cols));
    if (!isset($colSet['event_tipi'])) {
        echo json_encode(['ok' => false, 'code' => 'S79_C_SMOKE_SZ_SCHEMA_UNSUPPORTED'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fields = ['event_tipi'];
    $values = [':event_tipi'];
    $params = ['event_tipi' => 'SERBEST_ZAMAN_OLUSUM'];
    if (isset($colSet['kaynak_odeme_tercihi_id'])) {
        $fields[] = 'kaynak_odeme_tercihi_id';
        $values[] = ':kaynak';
        $params['kaynak'] = $tercihId;
    }
    if (isset($colSet['personel_id'])) {
        $fields[] = 'personel_id';
        $values[] = ':personel_id';
        $params['personel_id'] = $personelId;
    }
    if (isset($colSet['dakika'])) {
        $fields[] = 'dakika';
        $values[] = ':dakika';
        $params['dakika'] = 120;
    }
    if (isset($colSet['event_tarihi'])) {
        $fields[] = 'event_tarihi';
        $values[] = ':event_tarihi';
        $params['event_tarihi'] = S79_SMOKE_WEEK_START;
    }

    $sql = 'INSERT INTO serbest_zaman_events (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
    $ins = $pdo->prepare($sql);
    $ins->execute($params);
    $szId = (int) $pdo->lastInsertId();
    $meta['sz_event_id'] = $szId;
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    echo json_encode([
        'ok' => true,
        'code' => 'S79_C_SMOKE_SZ_CREATED',
        'sz_event_id' => $szId,
        'tested' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_cleanup') {
    $meta = s79_load_smoke_marker();
    $deleted = [
        'sz_event' => 0,
        'audit' => 0,
        'tercih' => 0,
        'muhur' => 0,
        'donem_kilit' => 0,
        'satir' => 0,
        'kapanis' => 0,
        'personel' => 0,
    ];

    if ($meta === null) {
        // Fallback: clean by exact smoke identifiers only.
        $meta = [
            'snapshot_id' => 0,
            'kapanis_id' => 0,
            'personel_id' => 0,
            'muhur_id' => null,
            'sz_event_id' => null,
            'muhur_owned' => false,
            'sube_id' => 0,
        ];
        $p = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil LIMIT 1');
        $p->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL]);
        $pid = $p->fetchColumn();
        if ($pid !== false) {
            $meta['personel_id'] = (int) $pid;
            $k = $pdo->prepare(
                'SELECT k.id AS kapanis_id, s.id AS snapshot_id
                 FROM haftalik_kapanislar k
                 INNER JOIN haftalik_kapanis_satirlari s ON s.kapanis_id = k.id
                 WHERE k.hafta_baslangic = :h AND s.personel_id = :p
                 LIMIT 1'
            );
            $k->execute(['h' => S79_SMOKE_WEEK_START, 'p' => (int) $pid]);
            $row = $k->fetch();
            if ($row) {
                $meta['kapanis_id'] = (int) $row['kapanis_id'];
                $meta['snapshot_id'] = (int) $row['snapshot_id'];
            }
        }
    }

    $snapshotId = (int) ($meta['snapshot_id'] ?? 0);
    $kapanisId = (int) ($meta['kapanis_id'] ?? 0);
    $personelId = (int) ($meta['personel_id'] ?? 0);
    $szEventId = (int) ($meta['sz_event_id'] ?? 0);
    $muhurId = (int) ($meta['muhur_id'] ?? 0);
    $muhurOwned = !empty($meta['muhur_owned']);

    $pdo->beginTransaction();
    try {
        if ($szEventId > 0 && s79_table_exists($pdo, 'serbest_zaman_events')) {
            $d = $pdo->prepare('DELETE FROM serbest_zaman_events WHERE id = :id');
            $d->execute(['id' => $szEventId]);
            $deleted['sz_event'] = $d->rowCount();
        }

        if ($snapshotId > 0 && s79_table_exists($pdo, 'fazla_calisma_odeme_tercihi_audit')) {
            $d = $pdo->prepare('DELETE FROM fazla_calisma_odeme_tercihi_audit WHERE snapshot_id = :id');
            $d->execute(['id' => $snapshotId]);
            $deleted['audit'] = $d->rowCount();
        }
        if ($snapshotId > 0 && s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri')) {
            $d = $pdo->prepare('DELETE FROM fazla_calisma_odeme_tercihleri WHERE snapshot_id = :id');
            $d->execute(['id' => $snapshotId]);
            $deleted['tercih'] = $d->rowCount();
        }

        if ($muhurId > 0 && $muhurOwned) {
            $chk = $pdo->prepare('SELECT id, yil, ay FROM puantaj_aylik_muhurleri WHERE id = :id');
            $chk->execute(['id' => $muhurId]);
            $row = $chk->fetch();
            if ($row && (int) $row['yil'] === 2036 && (int) $row['ay'] === 7) {
                $d = $pdo->prepare('DELETE FROM puantaj_aylik_muhurleri WHERE id = :id');
                $d->execute(['id' => $muhurId]);
                $deleted['muhur'] = $d->rowCount();
            }
        }

        // Smoke week is far-future 2036-07; remove period-lock row created by PUT acquire.
        $subeId = (int) ($meta['sube_id'] ?? 0);
        if (s79_table_exists($pdo, 'puantaj_donem_kilitleri')) {
            if ($subeId > 0) {
                $d = $pdo->prepare(
                    'DELETE FROM puantaj_donem_kilitleri WHERE sube_id = :s AND yil = 2036 AND ay = 7'
                );
                $d->execute(['s' => $subeId]);
                $deleted['donem_kilit'] = $d->rowCount();
            } else {
                // Fallback: only far-future smoke month, never touch real periods.
                $deleted['donem_kilit'] = (int) $pdo->exec(
                    'DELETE FROM puantaj_donem_kilitleri WHERE yil = 2036 AND ay = 7'
                );
            }
        }

        if ($kapanisId > 0) {
            $chk = $pdo->prepare('SELECT id, hafta_baslangic FROM haftalik_kapanislar WHERE id = :id');
            $chk->execute(['id' => $kapanisId]);
            $row = $chk->fetch();
            if ($row && (string) $row['hafta_baslangic'] === S79_SMOKE_WEEK_START) {
                $d1 = $pdo->prepare('DELETE FROM haftalik_kapanis_satirlari WHERE kapanis_id = :id');
                $d1->execute(['id' => $kapanisId]);
                $deleted['satir'] = $d1->rowCount();
                $d2 = $pdo->prepare('DELETE FROM haftalik_kapanislar WHERE id = :id');
                $d2->execute(['id' => $kapanisId]);
                $deleted['kapanis'] = $d2->rowCount();
            }
        }

        if ($personelId > 0) {
            $chk = $pdo->prepare('SELECT id, tc_kimlik_no, sicil_no FROM personeller WHERE id = :id');
            $chk->execute(['id' => $personelId]);
            $row = $chk->fetch();
            if ($row && ((string) $row['tc_kimlik_no'] === S79_SMOKE_TC || (string) $row['sicil_no'] === S79_SMOKE_SICIL)) {
                $d = $pdo->prepare('DELETE FROM personeller WHERE id = :id');
                $d->execute(['id' => $personelId]);
                $deleted['personel'] = $d->rowCount();
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_C_SMOKE_CLEANUP_FAILED',
            'error' => $e->getMessage(),
            'deleted' => $deleted,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $markerPath = s79_smoke_marker_path();
    if (is_file($markerPath)) {
        @unlink($markerPath);
    }

    $markers = s79_marker_rows($pdo);
    $left = 0;
    if ($snapshotId > 0 && s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri')) {
        $left += (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri WHERE snapshot_id = ' . (int) $snapshotId)->fetchColumn();
        $left += (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit WHERE snapshot_id = ' . (int) $snapshotId)->fetchColumn();
    }
    if ($kapanisId > 0) {
        $left += (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanislar WHERE id = ' . (int) $kapanisId)->fetchColumn();
    }
    if ($personelId > 0) {
        $left += (int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE id = ' . (int) $personelId)->fetchColumn();
    }

    $ok = $left === 0 && $markers['tercih'] === 0 && $markers['audit'] === 0 && $markers['personel'] === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_C_SMOKE_CLEANUP_OK' : 'S79_C_SMOKE_CLEANUP_INCOMPLETE',
        'deleted' => $deleted,
        'left' => $left,
        'marker_rows' => $markers,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_snapshot') {
    $meta = s79_load_smoke_marker();
    $snapshotId = isset($_GET['snapshot_id']) ? (int) $_GET['snapshot_id'] : (int) ($meta['snapshot_id'] ?? 0);
    if ($snapshotId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'snapshot_id required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $satir = $pdo->prepare(
        'SELECT s.*, k.sube_id FROM haftalik_kapanis_satirlari s
         INNER JOIN haftalik_kapanislar k ON k.id = s.kapanis_id
         WHERE s.id = :id'
    );
    $satir->execute(['id' => $snapshotId]);
    $s = $satir->fetch();
    $tercih = null;
    $audits = [];
    if (s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri')) {
        $t = $pdo->prepare('SELECT * FROM fazla_calisma_odeme_tercihleri WHERE snapshot_id = :id');
        $t->execute(['id' => $snapshotId]);
        $tercih = $t->fetch() ?: null;
    }
    if (s79_table_exists($pdo, 'fazla_calisma_odeme_tercihi_audit')) {
        $a = $pdo->prepare('SELECT * FROM fazla_calisma_odeme_tercihi_audit WHERE snapshot_id = :id ORDER BY id');
        $a->execute(['id' => $snapshotId]);
        $audits = $a->fetchAll();
    }
    echo json_encode([
        'ok' => is_array($s),
        'snapshot' => $s ?: null,
        'tercih' => $tercih,
        'audits' => $audits,
        'marker' => $meta,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
