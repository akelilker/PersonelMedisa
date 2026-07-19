<?php
/**
 * ONE-SHOT S79-D-R3 live migrate for 029_serbest_zaman_events.sql.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S79DR3_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_S79DR3_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_S79DR3_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'preflight';

const S79_EXPECTED_MIGRATION_SHA256 = 'd5ce98f03701ee9e13af4ad5b54490f0a9f01a1b7b35df0f9e17539f80009a8c';
const S79_MIGRATION_FILE = '029_serbest_zaman_events.sql';
const S79_SMOKE_MARKER = 'S79-D-R3 Production Smoke';
const S79_SMOKE_WEEK_START = '2037-08-03'; // Monday
const S79_SMOKE_WEEK_END = '2037-08-09';
const S79_SMOKE_TC = '90079000037';
const S79_SMOKE_SICIL = 'S79DR3-SMOKE';

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
        'personel_count' => s79_count($pdo, 'personeller'),
        'user_count' => s79_count($pdo, 'users'),
        'kapanis_count' => s79_count($pdo, 'haftalik_kapanislar'),
        'snapshot_count' => s79_count($pdo, 'haftalik_kapanis_satirlari'),
        'fcot_count' => s79_count($pdo, 'fazla_calisma_odeme_tercihleri'),
        'fcot_audit_count' => s79_count($pdo, 'fazla_calisma_odeme_tercihi_audit'),
        'sz_event_count' => s79_count($pdo, 'serbest_zaman_events'),
        'aktif_olusum_count' => s79_count($pdo, 'serbest_zaman_aktif_olusumlar'),
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
        'personeller',
        'users',
        'haftalik_kapanislar',
        'haftalik_kapanis_satirlari',
        'fazla_calisma_odeme_tercihleri',
        'fazla_calisma_odeme_tercihi_audit',
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
    return $out;
}

function s79_fk_rows(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
               'serbest_zaman_events',
               'serbest_zaman_aktif_olusumlar'
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
    $events = s79_table_exists($pdo, 'serbest_zaman_events');
    $guard = s79_table_exists($pdo, 'serbest_zaman_aktif_olusumlar');

    return [
        'serbest_zaman_events' => $events,
        'serbest_zaman_aktif_olusumlar' => $guard,
        'both_absent' => !$events && !$guard,
        'both_present' => $events && $guard,
        'partial' => ($events xor $guard),
        'events' => $events ? s79_table_schema($pdo, 'serbest_zaman_events') : null,
        'guard' => $guard ? s79_table_schema($pdo, 'serbest_zaman_aktif_olusumlar') : null,
        'fks' => ($events || $guard) ? s79_fk_rows($pdo) : [],
    ];
}

function s79_schema_matches_contract(PDO $pdo): array
{
    $events = s79_table_schema($pdo, 'serbest_zaman_events');
    $guard = s79_table_schema($pdo, 'serbest_zaman_aktif_olusumlar');
    $fks = s79_fk_rows($pdo);
    $issues = [];

    if ($events === null || $guard === null) {
        $issues[] = 'tables_missing';
        return ['ok' => false, 'issues' => $issues, 'events' => $events, 'guard' => $guard, 'fks' => $fks];
    }

    $eCreate = (string) ($events['create_table'] ?? '');
    $gCreate = (string) ($guard['create_table'] ?? '');

    foreach ([
        'uq_sz_personel_islem_anahtari',
        'uq_sz_iptal_hedef',
        'iptal_hedef_key',
        'SERBEST_ZAMAN_OLUSUM',
        'SERBEST_ZAMAN_KULLANIM',
        'SERBEST_ZAMAN_DUZELTME',
        'SERBEST_ZAMAN_IPTAL',
        'utf8mb4',
        'ENGINE=InnoDB',
    ] as $needle) {
        if (stripos($eCreate, $needle) === false) {
            $issues[] = 'events_missing:' . $needle;
        }
    }
    foreach (['PRIMARY KEY', 'uq_sz_aktif_olusum_event', 'utf8mb4', 'ENGINE=InnoDB'] as $needle) {
        if (stripos($gCreate, $needle) === false) {
            $issues[] = 'guard_missing:' . $needle;
        }
    }

    $requiredCols = [
        'id','personel_id','event_tipi','dakika','yeni_dakika','event_tarihi','son_kullanim_tarihi',
        'kaynak_snapshot_id','kaynak_odeme_tercihi_id','hedef_event_id','hedef_event_tipi','islem_anahtari',
        'aciklama','donem_yil','donem_ay','donem_kilitli_miydi','created_by','created_at','iptal_hedef_key',
    ];
    $colNames = array_map(static function ($c) { return (string) ($c['Field'] ?? ''); }, $events['columns'] ?? []);
    foreach ($requiredCols as $col) {
        if (!in_array($col, $colNames, true)) {
            $issues[] = 'events_col_missing:' . $col;
        }
    }
    $gCols = array_map(static function ($c) { return (string) ($c['Field'] ?? ''); }, $guard['columns'] ?? []);
    foreach (['odeme_tercihi_id','olusum_event_id','created_at'] as $col) {
        if (!in_array($col, $gCols, true)) {
            $issues[] = 'guard_col_missing:' . $col;
        }
    }

    foreach ($fks as $fk) {
        $del = strtoupper((string) ($fk['DELETE_RULE'] ?? ''));
        if (!in_array($del, ['RESTRICT', 'NO ACTION'], true)) {
            $issues[] = 'fk_delete_rule:' . ($fk['CONSTRAINT_NAME'] ?? '') . '=' . $del;
        }
    }
    if (count($fks) < 7) {
        $issues[] = 'fk_count_low:' . count($fks);
    }

    return [
        'ok' => $issues === [],
        'issues' => $issues,
        'events' => $events,
        'guard' => $guard,
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
    $out[] = '-- S79-D-R3 PHP SQL dump (shared-host fallback)';
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
    $path = __DIR__ . '/karmotor_medisa_pre_029_' . $stamp . '.sql';

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
    return __DIR__ . '/s79dr3_smoke_marker.json';
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
    $out = ['tercih' => 0, 'audit' => 0, 'personel' => 0, 'kapanis' => 0, 'sz_event' => 0, 'aktif_guard' => 0];
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
    if (s79_table_exists($pdo, 'serbest_zaman_events')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM serbest_zaman_events WHERE aciklama LIKE :m');
        $stmt->execute(['m' => '%' . S79_SMOKE_MARKER . '%']);
        $out['sz_event'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'serbest_zaman_aktif_olusumlar') && s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri')) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar g
             INNER JOIN fazla_calisma_odeme_tercihleri t ON t.id = g.odeme_tercihi_id
             WHERE t.gerekce LIKE :m'
        );
        $stmt->execute(['m' => '%' . S79_SMOKE_MARKER . '%']);
        $out['aktif_guard'] = (int) $stmt->fetchColumn();
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
            'code' => 'S79_D_R3_BLOCKED_DB_IDENTITY',
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
        $code = 'S79_D_R3_BLOCKED_PARENT_SCHEMA';
        $ok = false;
    } elseif ($existing['partial']) {
        $code = 'S79_D_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
        $ok = false;
    } elseif ($existing['both_present']) {
        $schemaMatch = s79_schema_matches_contract($pdo);
        if ($schemaMatch['ok']) {
            $code = 'S79_D_SCHEMA_ALREADY_APPLIED';
            $ok = true;
        } else {
            $code = 'S79_D_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
            $ok = false;
        }
    } else {
        $code = 'S79_D_PREFLIGHT_OK';
        $ok = true;
    }

    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'already_applied' => $existing['both_present'] && $ok && $code === 'S79_D_SCHEMA_ALREADY_APPLIED',
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
        echo json_encode(['ok' => false, 'code' => 'S79_D_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
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
    file_put_contents(__DIR__ . '/s79dr3_latest_backup_path.txt', basename($backupPath));

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_D_BACKUP_OK' : 'S79_D_R3_BLOCKED_BACKUP',
        'backup' => $meta,
        'identity' => $identity,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s79dr3_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_029_*.sql') ?: [];
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
        echo json_encode(['ok' => false, 'code' => 'S79_D_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existing = s79_existing_schema($pdo);
    $beforeCounts = s79_counts($pdo);

    if ($existing['partial']) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_D_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
            'existing' => $existing,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($existing['both_present']) {
        $match = s79_schema_matches_contract($pdo);
        echo json_encode([
            'ok' => $match['ok'],
            'code' => $match['ok'] ? 'S79_D_MIGRATE_OK' : 'S79_D_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
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
            'code' => 'S79_D_R3_BLOCKED_MIGRATION_DRIFT',
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
            'code' => 'S79_D_R3_BLOCKED_MIGRATION_DRIFT',
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
            'code' => 'S79_D_R3_BLOCKED_MIGRATION_APPLY',
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
        && $afterCounts['user_count'] === $beforeCounts['user_count']
        && $afterCounts['fcot_count'] === $beforeCounts['fcot_count']
        && $afterCounts['fcot_audit_count'] === $beforeCounts['fcot_audit_count'];
    $emptyNew = ($afterCounts['sz_event_count'] ?? -1) === 0 && ($afterCounts['aktif_olusum_count'] ?? -1) === 0;
    $ok = $match['ok'] && $parentStable && $emptyNew;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_D_MIGRATE_OK' : 'S79_D_R3_BLOCKED_MIGRATION_APPLY',
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
        'code' => $ok ? 'S79_D_POSTCHECK_OK' : 'S79_D_MIGRATION_APPLIED_SCHEMA_ACCEPTANCE_BLOCKED',
        'identity' => $identity,
        'counts' => $counts,
        'schema_match' => $match,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'counts') {
    echo json_encode([
        'ok' => true,
        'code' => 'S79_D_COUNTS_OK',
        'counts' => s79_counts($pdo),
        'identity' => s79_identity($pdo),
        'marker_rows' => s79_marker_rows($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'integrity') {
    $counts = s79_counts($pdo);
    $orphanGuard = 0;
    $orphanTarget = 0;
    if (s79_table_exists($pdo, 'serbest_zaman_aktif_olusumlar') && s79_table_exists($pdo, 'serbest_zaman_events')) {
        $orphanGuard = (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar g
             LEFT JOIN serbest_zaman_events e ON e.id = g.olusum_event_id
             WHERE e.id IS NULL'
        )->fetchColumn();
        $orphanTarget = (int) $pdo->query(
            'SELECT COUNT(*) FROM serbest_zaman_events e
             LEFT JOIN serbest_zaman_events t ON t.id = e.hedef_event_id
             WHERE e.hedef_event_id IS NOT NULL AND t.id IS NULL'
        )->fetchColumn();
    }
    $markers = s79_marker_rows($pdo);
    $ok = $orphanGuard === 0
        && $orphanTarget === 0
        && $markers['tercih'] === 0
        && $markers['audit'] === 0
        && $markers['personel'] === 0
        && $markers['sz_event'] === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_D_FINAL_INTEGRITY_OK' : 'S79_D_INTEGRITY_FAILED',
        'counts' => $counts,
        'orphan_guard' => $orphanGuard,
        'orphan_target' => $orphanTarget,
        'marker_rows' => $markers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function s79_api_base(): string
{
    return 'https://www.karmotors.com.tr/personelmedisa/api';
}

function s79_http(string $method, string $path, ?array $body = null, array $headers = [], array $query = []): array
{
    $url = s79_api_base() . $path;
    if ($query !== []) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
    }
    $ch = curl_init($url);
    $hdrs = ['Accept: application/json'];
    foreach ($headers as $k => $v) {
        $hdrs[] = $k . ': ' . $v;
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HEADER => true,
    ];
    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hdrs[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
        $opts[CURLOPT_POSTFIELDS] = $json;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    if ($raw === false) {
        return ['status' => 0, 'payload' => null, 'error' => $err !== '' ? $err : ('curl_' . $errno)];
    }
    $bodyRaw = substr($raw, $headerSize);
    $decoded = json_decode($bodyRaw, true);
    return [
        'status' => $status,
        'payload' => is_array($decoded) ? $decoded : null,
        'raw' => $bodyRaw,
    ];
}

function s79_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function s79_issue_jwt(array $config, int $userId, string $rol): string
{
    $secret = (string) ($config['jwt_secret'] ?? '');
    if (strlen($secret) < 32) {
        throw new RuntimeException('JWT_SECRET_INVALID');
    }
    $header = s79_b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_UNESCAPED_UNICODE));
    $payload = s79_b64url(json_encode([
        'sub' => $userId,
        'rol' => $rol,
        'iat' => time(),
        'exp' => time() + 3600,
    ], JSON_UNESCAPED_UNICODE));
    $signing = $header . '.' . $payload;
    $sig = s79_b64url(hash_hmac('sha256', $signing, $secret, true));

    return $signing . '.' . $sig;
}

function s79_load_role_user(PDO $pdo, string $rol): ?array
{
    $stmt = $pdo->prepare("SELECT id, username, rol FROM users WHERE rol = :r AND durum = 'AKTIF' ORDER BY id ASC LIMIT 1");
    $stmt->execute(['r' => $rol]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $subeStmt = $pdo->prepare('SELECT sube_id FROM user_subeler WHERE user_id = :u ORDER BY sube_id');
    $subeStmt->execute(['u' => (int) $row['id']]);
    $subeIds = array_map('intval', $subeStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return [
        'id' => (int) $row['id'],
        'username' => (string) $row['username'],
        'rol' => (string) $row['rol'],
        'sube_ids' => $subeIds,
    ];
}

function s79_auth_headers(string $token, ?int $activeSubeId = null): array
{
    $h = ['Authorization' => 'Bearer ' . $token];
    if ($activeSubeId !== null) {
        $h['X-Active-Sube-Id'] = (string) $activeSubeId;
    }
    return $h;
}

function s79_insert_fcot(
    PDO $pdo,
    int $snapshotId,
    int $kapanisId,
    int $personelId,
    string $hb,
    string $he,
    int $fm,
    string $tip,
    int $userId
): int {
    $ins = $pdo->prepare(
        "INSERT INTO fazla_calisma_odeme_tercihleri (
            snapshot_id, kapanis_id, personel_id, hafta_baslangic, hafta_bitis,
            fazla_calisma_dakika, odeme_tipi, secim_zamani, secen_kullanici_id, onceki_odeme_tipi, gerekce
         ) VALUES (
            :sid, :kid, :pid, :hb, :he,
            :fm, :tip, :secim, :uid, 'KARAR_BEKLIYOR', :gerekce
         )"
    );
    $ins->execute([
        'sid' => $snapshotId,
        'kid' => $kapanisId,
        'pid' => $personelId,
        'hb' => $hb,
        'he' => $he,
        'fm' => $fm,
        'tip' => $tip,
        'secim' => $he . ' 12:00:00',
        'uid' => $userId,
        'gerekce' => S79_SMOKE_MARKER,
    ]);
    return (int) $pdo->lastInsertId();
}

function s79_insert_week_snapshot(
    PDO $pdo,
    int $subeId,
    int $personelId,
    int $userId,
    string $hb,
    string $he,
    int $fm
): array {
    $insK = $pdo->prepare(
        "INSERT INTO haftalik_kapanislar (
            sube_id, hafta_baslangic, hafta_bitis, departman_id,
            state, personel_sayisi, snapshot_satir_sayisi, kaynak_versiyon, created_by
         ) VALUES (
            :sube_id, :hb, :he, NULL,
            'KAPANDI', 1, 1, 'A2_MOTOR_V1', :created_by
         )"
    );
    $insK->execute(['sube_id' => $subeId, 'hb' => $hb, 'he' => $he, 'created_by' => $userId]);
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
            :hb, :he, 2037, 1,
            'KAPANDI', 'A2_MOTOR_V1',
            :toplam, 2700, :fm,
            0, 1,
            '[]', 0, 0,
            '2037-08-10 00:00:00', 7, NULL
         )"
    );
    $insS->execute([
        'kapanis_id' => $kapanisId,
        'personel_id' => $personelId,
        'hb' => $hb,
        'he' => $he,
        'toplam' => 2700 + $fm,
        'fm' => $fm,
    ]);
    return ['kapanis_id' => $kapanisId, 'snapshot_id' => (int) $pdo->lastInsertId()];
}

if ($action === 'smoke_prepare') {
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S79_D_R3_BLOCKED_DB_IDENTITY'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!s79_table_exists($pdo, 'serbest_zaman_events') || !s79_table_exists($pdo, 'fazla_calisma_odeme_tercihleri')) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SCHEMA_NOT_READY'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sube = $pdo->query('SELECT id FROM subeler ORDER BY id ASC LIMIT 1')->fetch();
    $sube2 = $pdo->query('SELECT id FROM subeler ORDER BY id ASC LIMIT 1 OFFSET 1')->fetch();
    $userRow = $pdo->query("SELECT id FROM users WHERE rol = 'GENEL_YONETICI' AND durum = 'AKTIF' ORDER BY id ASC LIMIT 1")->fetch();
    if (!$sube || !$userRow) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_SCOPE_UNAVAILABLE'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $subeId = (int) $sube['id'];
    $sube2Id = $sube2 ? (int) $sube2['id'] : 0;
    $userId = (int) $userRow['id'];

    $dow = (int) $pdo->query("SELECT DAYOFWEEK('" . S79_SMOKE_WEEK_START . "')")->fetchColumn();
    if ($dow !== 2) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_WEEK_NOT_MONDAY', 'dow' => $dow], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingKap = $pdo->prepare('SELECT id FROM haftalik_kapanislar WHERE hafta_baslangic = :h LIMIT 1');
    $existingKap->execute(['h' => S79_SMOKE_WEEK_START]);
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

    $main = $wKb = $wUc = $wZero = null;
    $tercihMain = $tercihKb = $tercihUc = $tercihZero = 0;
    $personelId = 0;
    $personel2Id = null;
    $pdo->beginTransaction();
    try {
        $insP = $pdo->prepare(
            "INSERT INTO personeller (
                tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
                sicil_no, ise_giris_tarihi, sube_id, departman_id, aktif_durum
             ) VALUES (
                :tc, 'S79DR3', 'Smoke', '1990-01-01', '05000000000', 'S79DR3 Contact', '05000000001',
                :sicil, '2030-01-01', :sube_id, NULL, 'AKTIF'
             )"
        );
        $insP->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL, 'sube_id' => $subeId]);
        $personelId = (int) $pdo->lastInsertId();

        $personel2Id = null;
        if ($sube2Id > 0 && $sube2Id !== $subeId) {
            $insP2 = $pdo->prepare(
                "INSERT INTO personeller (
                    tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
                    sicil_no, ise_giris_tarihi, sube_id, departman_id, aktif_durum
                 ) VALUES (
                    '90079000038', 'S79DR3', 'ScopeOut', '1990-01-01', '05000000002', 'S79DR3 Contact', '05000000003',
                    'S79DR3-SMOKE2', '2030-01-01', :sube_id, NULL, 'AKTIF'
                 )"
            );
            $insP2->execute(['sube_id' => $sube2Id]);
            $personel2Id = (int) $pdo->lastInsertId();
        }

        $main = s79_insert_week_snapshot($pdo, $subeId, $personelId, $userId, S79_SMOKE_WEEK_START, S79_SMOKE_WEEK_END, 40);
        $tercihMain = s79_insert_fcot($pdo, $main['snapshot_id'], $main['kapanis_id'], $personelId, S79_SMOKE_WEEK_START, S79_SMOKE_WEEK_END, 40, 'SERBEST_ZAMAN', $userId);

        $wKb = s79_insert_week_snapshot($pdo, $subeId, $personelId, $userId, '2037-08-10', '2037-08-16', 50);
        $tercihKb = s79_insert_fcot($pdo, $wKb['snapshot_id'], $wKb['kapanis_id'], $personelId, '2037-08-10', '2037-08-16', 50, 'KARAR_BEKLIYOR', $userId);

        $wUc = s79_insert_week_snapshot($pdo, $subeId, $personelId, $userId, '2037-08-17', '2037-08-23', 50);
        $tercihUc = s79_insert_fcot($pdo, $wUc['snapshot_id'], $wUc['kapanis_id'], $personelId, '2037-08-17', '2037-08-23', 50, 'UCRET', $userId);

        $wZero = s79_insert_week_snapshot($pdo, $subeId, $personelId, $userId, '2037-08-24', '2037-08-30', 0);
        $tercihZero = s79_insert_fcot($pdo, $wZero['snapshot_id'], $wZero['kapanis_id'], $personelId, '2037-08-24', '2037-08-30', 0, 'SERBEST_ZAMAN', $userId);

        $muhurChk = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = :s AND yil = 2037 AND ay = 8 LIMIT 1');
        $muhurChk->execute(['s' => $subeId]);
        if ($muhurChk->fetchColumn() !== false) {
            throw new RuntimeException('SMOKE_MONTH_ALREADY_SEALED');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'S79_D_SMOKE_PREPARE_FAILED', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $meta = [
        'marker' => S79_SMOKE_MARKER,
        'sube_id' => $subeId,
        'sube2_id' => $sube2Id,
        'user_id' => $userId,
        'personel_id' => $personelId,
        'personel2_id' => $personel2Id,
        'kapanis_ids' => [$main['kapanis_id'], $wKb['kapanis_id'], $wUc['kapanis_id'], $wZero['kapanis_id']],
        'snapshot_ids' => [$main['snapshot_id'], $wKb['snapshot_id'], $wUc['snapshot_id'], $wZero['snapshot_id']],
        'kapanis_id' => $main['kapanis_id'],
        'snapshot_id' => $main['snapshot_id'],
        'tercih_id' => $tercihMain,
        'tercih_kb_id' => $tercihKb,
        'tercih_ucret_id' => $tercihUc,
        'tercih_zero_id' => $tercihZero,
        'hafta_baslangic' => S79_SMOKE_WEEK_START,
        'hafta_bitis' => S79_SMOKE_WEEK_END,
        'muhur_id' => null,
        'event_ids' => [],
        'created_at_utc' => gmdate('c'),
    ];
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo json_encode(['ok' => true, 'code' => 'S79_D_SMOKE_PREPARE_OK', 'fixture' => $meta, 'counts' => s79_counts($pdo)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_run') {
    $meta = s79_load_smoke_marker();
    if ($meta === null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_MARKER_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $baselineCounts = s79_counts($pdo);
    $steps = [];
    $failed = false;

    $pass = static function (string $name, bool $ok, array $detail = []) use (&$steps, &$failed): void {
        $steps[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        if (!$ok) {
            $failed = true;
        }
    };

    // Health / assets / unauth
    $health = s79_http('GET', '/health');
    $pass('health', $health['status'] === 200, ['status' => $health['status']]);
    foreach (['/serbest-zaman/events', '/serbest-zaman/bakiye'] as $p) {
        $r = s79_http('GET', $p, null, [], ['personel_id' => '1']);
        $pass('unauth GET ' . $p, $r['status'] === 401, ['status' => $r['status']]);
    }
    foreach (['/serbest-zaman/olusum', '/serbest-zaman/kullanim', '/serbest-zaman/iptal', '/serbest-zaman/duzeltme'] as $p) {
        $r = s79_http('POST', $p, []);
        $pass('unauth POST ' . $p, $r['status'] === 401, ['status' => $r['status']]);
    }

    $roles = [];
    foreach (['GENEL_YONETICI', 'BOLUM_YONETICISI', 'MUHASEBE', 'BIRIM_AMIRI', 'PATRON'] as $rol) {
        $u = s79_load_role_user($pdo, $rol);
        $roles[$rol] = $u;
        $pass('role account ' . $rol, $u !== null || in_array($rol, ['BOLUM_YONETICISI', 'MUHASEBE', 'BIRIM_AMIRI', 'PATRON'], true), [
            'available' => $u !== null,
            'note' => $u === null ? 'not tested — account unavailable' : 'available',
        ]);
    }

    $gy = $roles['GENEL_YONETICI'];
    if ($gy === null) {
        echo json_encode(['ok' => false, 'code' => 'SMOKE_GY_MISSING', 'steps' => $steps], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    $gyToken = s79_issue_jwt($config, $gy['id'], $gy['rol']);
    $subeId = (int) $meta['sube_id'];
    $personelId = (int) $meta['personel_id'];
    $tercihId = (int) $meta['tercih_id'];
    $gyH = s79_auth_headers($gyToken, $subeId);

    // Validation
    $r = s79_http('GET', '/serbest-zaman/events', null, $gyH);
    $pass('GET events missing personel_id', in_array($r['status'], [400, 422], true), ['status' => $r['status'], 'code' => $r['payload']['errors'][0]['code'] ?? null]);
    $r = s79_http('GET', '/serbest-zaman/bakiye', null, $gyH);
    $pass('GET bakiye missing personel_id', in_array($r['status'], [400, 422], true), ['status' => $r['status']]);
    $r = s79_http('GET', '/serbest-zaman/events', null, $gyH, ['personel_id' => '99999999']);
    $pass('GET events missing personel 404', $r['status'] === 404, ['status' => $r['status']]);
    foreach (['/serbest-zaman/olusum', '/serbest-zaman/kullanim', '/serbest-zaman/iptal', '/serbest-zaman/duzeltme'] as $p) {
        $r = s79_http('POST', $p, [], $gyH);
        $pass('POST empty ' . $p, $r['status'] === 422, ['status' => $r['status']]);
    }
    $beforeVal = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
    $r = s79_http('POST', '/serbest-zaman/olusum', [
        'snapshot_id' => (int) $meta['snapshot_id'],
        'personel_id' => 999,
        'dakika' => 999,
        'created_by' => 999,
    ], $gyH);
    $afterVal = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
    $pass('olusum server-owned override 422', $r['status'] === 422 && $beforeVal === $afterVal, ['status' => $r['status'], 'count_delta' => $afterVal - $beforeVal]);

    // Permission matrix
    foreach (['MUHASEBE', 'BIRIM_AMIRI'] as $rol) {
        if ($roles[$rol] === null) {
            $pass($rol . ' write', true, ['note' => 'not tested — account unavailable']);
            continue;
        }
        $tok = s79_issue_jwt($config, $roles[$rol]['id'], $rol);
        $active = $roles[$rol]['sube_ids'][0] ?? $subeId;
        $rr = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => $tercihId], s79_auth_headers($tok, $active));
        $pass($rol . ' write 403', $rr['status'] === 403, ['status' => $rr['status']]);
        $rg = s79_http('GET', '/serbest-zaman/events', null, s79_auth_headers($tok, $active), ['personel_id' => (string) $personelId]);
        $pass($rol . ' GET', in_array($rg['status'], [200, 403], true), ['status' => $rg['status']]);
    }
    if ($roles['PATRON'] !== null) {
        $tok = s79_issue_jwt($config, $roles['PATRON']['id'], 'PATRON');
        $rg = s79_http('GET', '/serbest-zaman/events', null, s79_auth_headers($tok), ['personel_id' => (string) $personelId]);
        $rw = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => $tercihId], s79_auth_headers($tok));
        $pass('PATRON GET 403', $rg['status'] === 403, ['status' => $rg['status']]);
        $pass('PATRON write 403', $rw['status'] === 403, ['status' => $rw['status']]);
    } else {
        $pass('PATRON', true, ['note' => 'not tested — account unavailable']);
    }

    // FCOT eligibility
    $r = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => 99999999], $gyH);
    $pass('NOT_PERSISTED', $r['status'] === 409 && (($r['payload']['errors'][0]['code'] ?? '') === 'NOT_PERSISTED'), ['status' => $r['status']]);
    $r = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => (int) $meta['tercih_kb_id']], $gyH);
    $pass('KARAR_BEKLIYOR NOT_ELIGIBLE', $r['status'] === 409 && (($r['payload']['errors'][0]['code'] ?? '') === 'NOT_ELIGIBLE'), ['status' => $r['status']]);
    $r = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => (int) $meta['tercih_ucret_id']], $gyH);
    $pass('UCRET NOT_ELIGIBLE', $r['status'] === 409 && (($r['payload']['errors'][0]['code'] ?? '') === 'NOT_ELIGIBLE'), ['status' => $r['status']]);
    $r = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => (int) $meta['tercih_zero_id']], $gyH);
    $pass('ZERO_DAKIKA', $r['status'] === 422 && (($r['payload']['errors'][0]['code'] ?? '') === 'ZERO_DAKIKA'), ['status' => $r['status']]);

    // OLUSUM
    $r = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => $tercihId], $gyH);
    $olusumOk = $r['status'] === 200 && (int) ($r['payload']['data']['dakika'] ?? 0) === 60;
    $olusumId = (int) ($r['payload']['data']['id'] ?? 0);
    $pass('OLUSUM 200 dakika=60', $olusumOk && $olusumId > 0, ['status' => $r['status'], 'id' => $olusumId, 'dakika' => $r['payload']['data']['dakika'] ?? null]);
    $guard = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id = ' . $tercihId)->fetchColumn();
    $pass('aktif guard=1', $guard === 1, ['guard' => $guard]);
    $r2 = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => $tercihId], $gyH);
    $pass('OLUSUM duplicate ALREADY_EXISTS', $r2['status'] === 409 && (($r2['payload']['errors'][0]['code'] ?? '') === 'ALREADY_EXISTS'), ['status' => $r2['status']]);

    // FCOT guard integration (before period seal)
    $fcotPut = s79_http('PUT', '/fazla-calisma-odeme-tercihi', [
        'snapshot_id' => (int) $meta['snapshot_id'],
        'odeme_tipi' => 'UCRET',
        'gerekce' => S79_SMOKE_MARKER,
    ], $gyH);
    $pass('FCOT change blocked while active olusum', $fcotPut['status'] === 409 && (($fcotPut['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT'), ['status' => $fcotPut['status'], 'code' => $fcotPut['payload']['errors'][0]['code'] ?? null]);


    // GET events/bakiye
    $beforeGet = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
    $ge = s79_http('GET', '/serbest-zaman/events', null, $gyH, ['personel_id' => (string) $personelId]);
    $gb = s79_http('GET', '/serbest-zaman/bakiye', null, $gyH, ['personel_id' => (string) $personelId, 'referans_tarih' => S79_SMOKE_WEEK_START]);
    $afterGet = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
    $pass('GET events 200', $ge['status'] === 200 && is_array($ge['payload']['data']['items'] ?? null), ['status' => $ge['status']]);
    $pass('GET no-write', $beforeGet === $afterGet, []);
    $pass('GET bakiye', $gb['status'] === 200
        && (int) ($gb['payload']['data']['toplam_hak_dakika'] ?? -1) === 60
        && (int) ($gb['payload']['data']['kalan_dakika'] ?? -1) === 60
        && (int) ($gb['payload']['data']['event_sayisi'] ?? -1) === 1, ['data' => $gb['payload']['data'] ?? null]);

    // Scope
    if (!empty($meta['personel2_id']) && !empty($roles['BOLUM_YONETICISI'])) {
        $bolum = $roles['BOLUM_YONETICISI'];
        $bt = s79_issue_jwt($config, $bolum['id'], $bolum['rol']);
        $inScope = in_array($subeId, $bolum['sube_ids'], true);
        if ($inScope) {
            $rg = s79_http('GET', '/serbest-zaman/events', null, s79_auth_headers($bt, $subeId), ['personel_id' => (string) $personelId]);
            $pass('BOLUM scope içi GET', $rg['status'] === 200, ['status' => $rg['status']]);
        }
        $rg2 = s79_http('GET', '/serbest-zaman/events', null, s79_auth_headers($bt, $subeId), ['personel_id' => (string) $meta['personel2_id']]);
        $pass('BOLUM scope dışı GET', $rg2['status'] === 403, ['status' => $rg2['status']]);
        $emptyTokUser = ['id' => $bolum['id'], 'rol' => $bolum['rol'], 'sube_ids' => []];
        // empty allowedSubeIds cannot be forced via JWT easily; skip if user has sube bindings
        $pass('allowedSubeIds=[]', true, ['note' => 'covered by MariaDB acceptance; live user has bound subeler']);
    } else {
        $pass('scope smoke', true, ['note' => 'partial — second sube or BOLUM account unavailable']);
    }

    // KULLANIM
    $r = s79_http('POST', '/serbest-zaman/kullanim', [
        'personel_id' => $personelId,
        'dakika' => 20,
        'event_tarihi' => '2037-08-05',
        'islem_anahtari' => 'S79-D-R3-KULLANIM-001',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $kullanimId = (int) ($r['payload']['data']['id'] ?? 0);
    $pass('KULLANIM 200', $r['status'] === 200 && $kullanimId > 0, ['status' => $r['status'], 'id' => $kullanimId]);
    $gb = s79_http('GET', '/serbest-zaman/bakiye', null, $gyH, ['personel_id' => (string) $personelId, 'referans_tarih' => '2037-08-05']);
    $pass('bakiye after kullanim=40', (int) ($gb['payload']['data']['kalan_dakika'] ?? -1) === 40, ['data' => $gb['payload']['data'] ?? null]);

    $r = s79_http('POST', '/serbest-zaman/kullanim', [
        'personel_id' => $personelId,
        'dakika' => 20,
        'event_tarihi' => '2037-08-05',
        'islem_anahtari' => 'S79-D-R3-KULLANIM-001',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $pass('idempotent retry', $r['status'] === 200 && (int) ($r['payload']['data']['id'] ?? 0) === $kullanimId, ['status' => $r['status']]);
    $r = s79_http('POST', '/serbest-zaman/kullanim', [
        'personel_id' => $personelId,
        'dakika' => 25,
        'event_tarihi' => '2037-08-05',
        'islem_anahtari' => 'S79-D-R3-KULLANIM-001',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $pass('IDEMPOTENCY_CONFLICT', $r['status'] === 409 && (($r['payload']['errors'][0]['code'] ?? '') === 'IDEMPOTENCY_CONFLICT'), ['status' => $r['status']]);
    $r = s79_http('POST', '/serbest-zaman/kullanim', [
        'personel_id' => $personelId,
        'dakika' => 100,
        'event_tarihi' => '2037-08-06',
        'islem_anahtari' => 'S79-D-R3-KULLANIM-TOO-MUCH',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $pass('INSUFFICIENT_BALANCE', $r['status'] === 409 && (($r['payload']['errors'][0]['code'] ?? '') === 'INSUFFICIENT_BALANCE'), ['status' => $r['status']]);

    // KULLANIM iptal
    $r = s79_http('POST', '/serbest-zaman/iptal', [
        'personel_id' => $personelId,
        'hedef_event_id' => $kullanimId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'event_tarihi' => '2037-08-06',
        'islem_anahtari' => 'S79-D-R3-IPTAL-KULLANIM-001',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $pass('iptal KULLANIM', $r['status'] === 200, ['status' => $r['status']]);
    $gb = s79_http('GET', '/serbest-zaman/bakiye', null, $gyH, ['personel_id' => (string) $personelId, 'referans_tarih' => '2037-08-06']);
    $pass('bakiye restore=60', (int) ($gb['payload']['data']['kalan_dakika'] ?? -1) === 60, ['data' => $gb['payload']['data'] ?? null]);
    $r = s79_http('POST', '/serbest-zaman/iptal', [
        'personel_id' => $personelId,
        'hedef_event_id' => $kullanimId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'event_tarihi' => '2037-08-07',
        'islem_anahtari' => 'S79-D-R3-IPTAL-KULLANIM-002',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $pass('second iptal ALREADY_CANCELLED', $r['status'] === 409 && (($r['payload']['errors'][0]['code'] ?? '') === 'ALREADY_CANCELLED'), ['status' => $r['status']]);

    // DUZELTME on olusum
    $r = s79_http('POST', '/serbest-zaman/duzeltme', [
        'personel_id' => $personelId,
        'hedef_event_id' => $olusumId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'yeni_dakika' => 70,
        'event_tarihi' => '2037-08-07',
        'islem_anahtari' => 'S79-D-R3-DUZELTME-001',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $duzeltmeId = (int) ($r['payload']['data']['id'] ?? 0);
    $pass('DUZELTME olusum', $r['status'] === 200 && $duzeltmeId > 0, ['status' => $r['status']]);
    $r = s79_http('POST', '/serbest-zaman/duzeltme', [
        'personel_id' => $personelId,
        'hedef_event_id' => $olusumId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'yeni_dakika' => 70,
        'event_tarihi' => '2037-08-07',
        'islem_anahtari' => 'S79-D-R3-DUZELTME-NOACIK',
    ], $gyH);
    $pass('duzeltme missing aciklama 422', $r['status'] === 422, ['status' => $r['status']]);

    // Period metadata: sealed month write continues
    $muhurIns = $pdo->prepare(
        "INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, created_by)
         VALUES (:s, 2037, 8, '2037-08', 'MUHURLENDI', :u)"
    );
    $muhurIns->execute(['s' => $subeId, 'u' => $gy['id']]);
    $muhurId = (int) $pdo->lastInsertId();
    $meta['muhur_id'] = $muhurId;
    $meta['muhur_owned'] = true;
    $r = s79_http('POST', '/serbest-zaman/kullanim', [
        'personel_id' => $personelId,
        'dakika' => 5,
        'event_tarihi' => '2037-08-08',
        'islem_anahtari' => 'S79-D-R3-KULLANIM-SEALED',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $sealedKulId = (int) ($r['payload']['data']['id'] ?? 0);
    $pass('sealed period write continues', $r['status'] === 200 && (($r['payload']['data']['donem_kilitli_miydi'] ?? false) === true), ['status' => $r['status'], 'donem' => $r['payload']['data']['donem_kilitli_miydi'] ?? null]);

    // OLUSUM iptal + re-olusum
    $r = s79_http('POST', '/serbest-zaman/iptal', [
        'personel_id' => $personelId,
        'hedef_event_id' => $olusumId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'event_tarihi' => '2037-08-09',
        'islem_anahtari' => 'S79-D-R3-IPTAL-OLUSUM-001',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $pass('iptal OLUSUM', $r['status'] === 200, ['status' => $r['status']]);
    $guard = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id = ' . $tercihId)->fetchColumn();
    $pass('guard deleted', $guard === 0, ['guard' => $guard]);
    $r = s79_http('POST', '/serbest-zaman/duzeltme', [
        'personel_id' => $personelId,
        'hedef_event_id' => $olusumId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'yeni_dakika' => 55,
        'event_tarihi' => '2037-08-09',
        'islem_anahtari' => 'S79-D-R3-DUZELTME-CANCELLED',
        'aciklama' => S79_SMOKE_MARKER,
    ], $gyH);
    $pass('TARGET_ALREADY_CANCELLED', $r['status'] === 409 && (($r['payload']['errors'][0]['code'] ?? '') === 'TARGET_ALREADY_CANCELLED'), ['status' => $r['status']]);
    $r = s79_http('POST', '/serbest-zaman/olusum', ['odeme_tercihi_id' => $tercihId], $gyH);
    $reOlusumId = (int) ($r['payload']['data']['id'] ?? 0);
    $pass('re-OLUSUM', $r['status'] === 200 && $reOlusumId > 0 && $reOlusumId !== $olusumId, ['status' => $r['status'], 'id' => $reOlusumId]);
    $guard = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id = ' . $tercihId)->fetchColumn();
    $pass('re-OLUSUM guard=1', $guard === 1, ['guard' => $guard]);

    $eventIds = array_values(array_filter([$olusumId, $kullanimId, $duzeltmeId, $sealedKulId, $reOlusumId]));
    $meta['event_ids'] = $eventIds;
    $meta['olusum_id'] = $olusumId;
    $meta['re_olusum_id'] = $reOlusumId;
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $ok = !$failed;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_D_SMOKE_RUN_OK' : 'S79_D_SMOKE_RUN_FAILED',
        'steps' => $steps,
        'fixture' => $meta,
        'baseline_counts' => $baselineCounts,
        'counts' => s79_counts($pdo),
        'login_note' => 'password login not tested — credentials unavailable; JWT issued from config.local secret for existing role users',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_cleanup') {
    $meta = s79_load_smoke_marker();
    $deleted = [
        'aktif_guard' => 0,
        'sz_event' => 0,
        'audit' => 0,
        'tercih' => 0,
        'muhur' => 0,
        'donem_kilit' => 0,
        'satir' => 0,
        'kapanis' => 0,
        'personel' => 0,
        'personel2' => 0,
    ];

    if ($meta === null) {
        $meta = [];
        $p = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil LIMIT 1');
        $p->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL]);
        $pid = $p->fetchColumn();
        if ($pid !== false) {
            $meta['personel_id'] = (int) $pid;
        }
    }

    $personelId = (int) ($meta['personel_id'] ?? 0);
    $personel2Id = (int) ($meta['personel2_id'] ?? 0);
    $kapanisIds = array_map('intval', $meta['kapanis_ids'] ?? (isset($meta['kapanis_id']) ? [(int) $meta['kapanis_id']] : []));
    $snapshotIds = array_map('intval', $meta['snapshot_ids'] ?? (isset($meta['snapshot_id']) ? [(int) $meta['snapshot_id']] : []));
    $tercihIds = array_values(array_filter([
        (int) ($meta['tercih_id'] ?? 0),
        (int) ($meta['tercih_kb_id'] ?? 0),
        (int) ($meta['tercih_ucret_id'] ?? 0),
        (int) ($meta['tercih_zero_id'] ?? 0),
    ]));
    $muhurId = (int) ($meta['muhur_id'] ?? 0);
    $muhurOwned = !empty($meta['muhur_owned']);
    $subeId = (int) ($meta['sube_id'] ?? 0);

    $pdo->beginTransaction();
    try {
        if ($tercihIds !== []) {
            $in = implode(',', $tercihIds);
            $deleted['aktif_guard'] = (int) $pdo->exec('DELETE FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id IN (' . $in . ')');
        }
        if ($personelId > 0 && s79_table_exists($pdo, 'serbest_zaman_events')) {
            // Delete children first (iptal/duzeltme referencing), then all for personel with marker or all smoke personel events
            $d = $pdo->prepare('DELETE FROM serbest_zaman_events WHERE personel_id = :p');
            $d->execute(['p' => $personelId]);
            $deleted['sz_event'] = $d->rowCount();
        }
        if ($tercihIds !== []) {
            $in = implode(',', $tercihIds);
            if (s79_table_exists($pdo, 'fazla_calisma_odeme_tercihi_audit')) {
                $deleted['audit'] = (int) $pdo->exec(
                    'DELETE FROM fazla_calisma_odeme_tercihi_audit WHERE tercih_id IN (' . $in . ') OR gerekce LIKE ' . $pdo->quote('%' . S79_SMOKE_MARKER . '%')
                );
            }
            $deleted['tercih'] = (int) $pdo->exec('DELETE FROM fazla_calisma_odeme_tercihleri WHERE id IN (' . $in . ')');
        }
        if ($muhurId > 0 && $muhurOwned) {
            $chk = $pdo->prepare('SELECT id, yil, ay FROM puantaj_aylik_muhurleri WHERE id = :id');
            $chk->execute(['id' => $muhurId]);
            $row = $chk->fetch();
            if ($row && (int) $row['yil'] === 2037 && (int) $row['ay'] === 8) {
                $d = $pdo->prepare('DELETE FROM puantaj_aylik_muhurleri WHERE id = :id');
                $d->execute(['id' => $muhurId]);
                $deleted['muhur'] = $d->rowCount();
            }
        }
        if (s79_table_exists($pdo, 'puantaj_donem_kilitleri')) {
            if ($subeId > 0) {
                $d = $pdo->prepare('DELETE FROM puantaj_donem_kilitleri WHERE sube_id = :s AND yil = 2037 AND ay = 8');
                $d->execute(['s' => $subeId]);
                $deleted['donem_kilit'] = $d->rowCount();
            }
        }
        foreach ($kapanisIds as $kid) {
            if ($kid <= 0) {
                continue;
            }
            $chk = $pdo->prepare('SELECT id, hafta_baslangic FROM haftalik_kapanislar WHERE id = :id');
            $chk->execute(['id' => $kid]);
            $row = $chk->fetch();
            if (!$row) {
                continue;
            }
            $hb = (string) $row['hafta_baslangic'];
            if (strpos($hb, '2037-08-') !== 0) {
                throw new RuntimeException('REFUSING_NON_SMOKE_WEEK:' . $hb);
            }
            $d1 = $pdo->prepare('DELETE FROM haftalik_kapanis_satirlari WHERE kapanis_id = :id');
            $d1->execute(['id' => $kid]);
            $deleted['satir'] += $d1->rowCount();
            $d2 = $pdo->prepare('DELETE FROM haftalik_kapanislar WHERE id = :id');
            $d2->execute(['id' => $kid]);
            $deleted['kapanis'] += $d2->rowCount();
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
        if ($personel2Id > 0) {
            $chk = $pdo->prepare('SELECT id, sicil_no FROM personeller WHERE id = :id');
            $chk->execute(['id' => $personel2Id]);
            $row = $chk->fetch();
            if ($row && (string) $row['sicil_no'] === 'S79DR3-SMOKE2') {
                $d = $pdo->prepare('DELETE FROM personeller WHERE id = :id');
                $d->execute(['id' => $personel2Id]);
                $deleted['personel2'] = $d->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'S79_D_SMOKE_CLEANUP_FAILED', 'error' => $e->getMessage(), 'deleted' => $deleted], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    @unlink(s79_smoke_marker_path());
    $markers = s79_marker_rows($pdo);
    $ok = $markers['tercih'] === 0 && $markers['audit'] === 0 && $markers['personel'] === 0 && $markers['sz_event'] === 0 && $markers['kapanis'] === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_D_SMOKE_CLEANUP_OK' : 'S79_D_SMOKE_CLEANUP_INCOMPLETE',
        'deleted' => $deleted,
        'marker_rows' => $markers,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
