<?php
/**
 * ONE-SHOT S79-E-R3 live migrate for 030_haftalik_kapanis_revizyon_talepleri.sql.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S79ER3_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Workflow greps for UNSET_S79ER3_MIGRATE_TOKEN; live token must be openssl rand -hex 24.
if (
    !preg_match('/^[a-f0-9]{48}$/', $tokenExpected)
    || $tokenProvided === ''
    || !hash_equals($tokenExpected, $tokenProvided)
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'preflight';

const S79_EXPECTED_MIGRATION_SHA256 = '477e27b59e3cdfd8a3686a2dcd5a763f3db4d03d8fb388fc831bdc98a7a73e42';
const S79_MIGRATION_FILE = '030_haftalik_kapanis_revizyon_talepleri.sql';
const S79_SMOKE_MARKER = 'S79-E-R3 Production Smoke';
const S79_SMOKE_WEEK_START = '2038-03-07'; // Monday
const S79_SMOKE_WEEK_END = '2038-03-13';
const S79_SMOKE_OPEN_WEEK_START = '2038-03-14';
const S79_SMOKE_OPEN_WEEK_END = '2038-03-20';
const S79_SMOKE_TC = '90079000038';
const S79_SMOKE_SICIL = 'S79ER3-SMOKE';

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
        'subeler' => s79_count($pdo, 'subeler'),
        'personeller' => s79_count($pdo, 'personeller'),
        'users' => s79_count($pdo, 'users'),
        'haftalik_kapanislar' => s79_count($pdo, 'haftalik_kapanislar'),
        'haftalik_kapanis_satirlari' => s79_count($pdo, 'haftalik_kapanis_satirlari'),
        'surecler' => s79_count($pdo, 'surecler'),
        'serbest_zaman_events' => s79_count($pdo, 'serbest_zaman_events'),
        'haftalik_kapanis_revizyon_talepleri' => s79_count($pdo, 'haftalik_kapanis_revizyon_talepleri'),
        'haftalik_kapanis_revizyon_talebi_gecmisi' => s79_count($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi'),
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
        'subeler',
        'personeller',
        'users',
        'haftalik_kapanislar',
        'haftalik_kapanis_satirlari',
        'surecler',
        'serbest_zaman_events',
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
        "SELECT TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE, REFERENCED_TABLE_NAME
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME IN (
               'haftalik_kapanis_revizyon_talepleri',
               'haftalik_kapanis_revizyon_talebi_gecmisi'
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
    $talep = s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri');
    $gecmis = s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi');

    return [
        'haftalik_kapanis_revizyon_talepleri' => $talep,
        'haftalik_kapanis_revizyon_talebi_gecmisi' => $gecmis,
        'both_absent' => !$talep && !$gecmis,
        'both_present' => $talep && $gecmis,
        'partial' => ($talep xor $gecmis),
        'talep' => $talep ? s79_table_schema($pdo, 'haftalik_kapanis_revizyon_talepleri') : null,
        'gecmis' => $gecmis ? s79_table_schema($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi') : null,
        'fks' => ($talep || $gecmis) ? s79_fk_rows($pdo) : [],
    ];
}

function s79_correction_event_fk_count(PDO $pdo): int
{
    if (!s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        return 0;
    }
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'haftalik_kapanis_revizyon_talepleri'
           AND COLUMN_NAME = 'correction_event_id'
           AND REFERENCED_TABLE_NAME IS NOT NULL"
    );

    return (int) $stmt->fetchColumn();
}

function s79_schema_matches_contract(PDO $pdo): array
{
    $talep = s79_table_schema($pdo, 'haftalik_kapanis_revizyon_talepleri');
    $gecmis = s79_table_schema($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi');
    $fks = s79_fk_rows($pdo);
    $issues = [];

    if ($talep === null || $gecmis === null) {
        $issues[] = 'tables_missing';
        return ['ok' => false, 'issues' => $issues, 'talep' => $talep, 'gecmis' => $gecmis, 'fks' => $fks];
    }

    $tCreate = (string) ($talep['create_table'] ?? '');
    $gCreate = (string) ($gecmis['create_table'] ?? '');

    foreach ([
        'uq_hkrt_acik_kaynak',
        'acik_talep_slot',
        'GENERATED',
        'utf8mb4',
        'utf8mb4_unicode_ci',
        'ENGINE=InnoDB',
    ] as $needle) {
        if (stripos($tCreate, $needle) === false) {
            $issues[] = 'talep_missing:' . $needle;
        }
    }
    if (preg_match(
        "/acik_talep_slot[\\s\\S]*?case\\s+when\\s+`?durum`?\\s+in\\s*\\(\\s*'TASLAK'\\s*,\\s*'ONAY_BEKLIYOR'\\s*\\)\\s+then\\s+1\\s+else\\s+NULL\\s+end/i",
        $tCreate
    ) !== 1) {
        $issues[] = 'talep_acik_talep_slot_expression';
    }
    foreach (['utf8mb4', 'utf8mb4_unicode_ci', 'ENGINE=InnoDB'] as $needle) {
        if (stripos($gCreate, $needle) === false) {
            $issues[] = 'gecmis_missing:' . $needle;
        }
    }

    $talepCols = array_map(static function ($c) { return (string) ($c['Field'] ?? ''); }, $talep['columns'] ?? []);
    foreach ([
        'id', 'personel_id', 'sube_id', 'kapanis_id', 'snapshot_id', 'hafta_baslangic', 'hafta_bitis',
        'etkilenen_tarih', 'kaynak_tipi', 'kaynak_id', 'revizyon_tipi', 'onceki_deger', 'talep_edilen_deger',
        'gerekce', 'bordro_etki_var_mi', 'bordro_etki_notu', 'durum', 'talep_eden_kullanici_id', 'talep_eden_rol',
        'talep_zamani', 'karar_veren_kullanici_id', 'karar_zamani', 'karar_aciklamasi', 'correction_event_id',
        'acik_talep_slot', 'created_at', 'updated_at',
    ] as $col) {
        if (!in_array($col, $talepCols, true)) {
            $issues[] = 'talep_col_missing:' . $col;
        }
    }

    $gecmisCols = array_map(static function ($c) { return (string) ($c['Field'] ?? ''); }, $gecmis['columns'] ?? []);
    foreach ([
        'id', 'revizyon_talebi_id', 'onceki_durum', 'yeni_durum', 'aksiyon', 'aciklama',
        'islem_yapan_kullanici_id', 'islem_zamani',
    ] as $col) {
        if (!in_array($col, $gecmisCols, true)) {
            $issues[] = 'gecmis_col_missing:' . $col;
        }
    }

    $indexNames = array_values(array_unique(array_map(static function ($idx) {
        return (string) ($idx['Key_name'] ?? '');
    }, $talep['indexes'] ?? [])));
    foreach ([
        'PRIMARY', 'uq_hkrt_acik_kaynak', 'idx_hkrt_personel_talep', 'idx_hkrt_sube_hafta',
        'idx_hkrt_durum', 'idx_hkrt_kapanis', 'idx_hkrt_snapshot',
    ] as $idx) {
        if (!in_array($idx, $indexNames, true)) {
            $issues[] = 'talep_index_missing:' . $idx;
        }
    }

    foreach ($fks as $fk) {
        $del = strtoupper((string) ($fk['DELETE_RULE'] ?? ''));
        $upd = strtoupper((string) ($fk['UPDATE_RULE'] ?? ''));
        if (!in_array($del, ['RESTRICT', 'NO ACTION'], true)) {
            $issues[] = 'fk_delete_rule:' . ($fk['CONSTRAINT_NAME'] ?? '') . '=' . $del;
        }
        if (!in_array($upd, ['RESTRICT', 'NO ACTION'], true)) {
            $issues[] = 'fk_update_rule:' . ($fk['CONSTRAINT_NAME'] ?? '') . '=' . $upd;
        }
    }
    if (count($fks) < 8) {
        $issues[] = 'fk_count_low:' . count($fks);
    }
    if (s79_correction_event_fk_count($pdo) !== 0) {
        $issues[] = 'correction_event_id_has_fk';
    }

    return [
        'ok' => $issues === [],
        'issues' => $issues,
        'talep' => $talep,
        'gecmis' => $gecmis,
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
    $out[] = '-- S79-E-R3 PHP SQL dump (shared-host fallback)';
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
    $path = __DIR__ . '/karmotor_medisa_pre_030_' . $stamp . '.sql';

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
    return __DIR__ . '/s79er3_smoke_marker.json';
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
    $out = [
        'talep' => 0,
        'gecmis' => 0,
        'personel' => 0,
        'kapanis' => 0,
        'satir' => 0,
    ];
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri WHERE gerekce LIKE :m');
        $stmt->execute(['m' => '%' . S79_SMOKE_MARKER . '%']);
        $out['talep'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi')) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi g
             INNER JOIN haftalik_kapanis_revizyon_talepleri t ON t.id = g.revizyon_talebi_id
             WHERE t.gerekce LIKE :m OR g.aciklama LIKE :m'
        );
        $stmt->execute(['m' => '%' . S79_SMOKE_MARKER . '%']);
        $out['gecmis'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'personeller')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil');
        $stmt->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL]);
        $out['personel'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'haftalik_kapanislar')) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM haftalik_kapanislar
             WHERE hafta_baslangic IN (:h1, :h2) OR hafta_baslangic LIKE :prefix'
        );
        $stmt->execute([
            'h1' => S79_SMOKE_WEEK_START,
            'h2' => S79_SMOKE_OPEN_WEEK_START,
            'prefix' => '2038-03-%',
        ]);
        $out['kapanis'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'haftalik_kapanis_satirlari') && s79_table_exists($pdo, 'personeller')) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM haftalik_kapanis_satirlari s
             INNER JOIN personeller p ON p.id = s.personel_id
             WHERE p.tc_kimlik_no = :tc OR p.sicil_no = :sicil'
        );
        $stmt->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL]);
        $out['satir'] = (int) $stmt->fetchColumn();
    }

    return $out;
}

if ($action === 'identity') {
    $identity = s79_identity($pdo);
    $dbOk = $identity['aktif_veritabani'] === 'karmotor_medisa';
    $configHost = (string) ($config['db_host'] ?? '');
    $hostHint = stripos($identity['db_host'], 'zelda.veridyen.com') !== false
        || stripos($identity['db_host'], 'zelda') !== false
        || stripos($identity['db_host'], 'veridyen') !== false
        || stripos($configHost, 'zelda.veridyen.com') !== false
        || stripos($configHost, 'zelda') !== false
        || stripos($configHost, 'veridyen') !== false;
    $mariadb106 = (bool) preg_match('/^10\.6\./', $identity['db_version']);
    $ok = $dbOk && $hostHint;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
        'expected_host_hint' => 'zelda.veridyen.com',
        'host_matches_hint' => $hostHint,
        'mariadb_10_6' => $mariadb106,
        'config_db_name' => $name,
        'config_db_host' => $configHost,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'preflight') {
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_E_R3_BLOCKED_DB_IDENTITY',
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
        $code = 'S79_E_R3_BLOCKED_PARENT_SCHEMA';
        $ok = false;
    } elseif ($existing['partial']) {
        $code = 'S79_E_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
        $ok = false;
    } elseif ($existing['both_present']) {
        $schemaMatch = s79_schema_matches_contract($pdo);
        if ($schemaMatch['ok']) {
            $code = 'S79_E_SCHEMA_ALREADY_APPLIED';
            $ok = true;
        } else {
            $code = 'S79_E_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
            $ok = false;
        }
    } else {
        $code = 'S79_E_PREFLIGHT_OK';
        $ok = true;
    }

    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'already_applied' => $existing['both_present'] && $ok && $code === 'S79_E_SCHEMA_ALREADY_APPLIED',
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
        echo json_encode(['ok' => false, 'code' => 'S79_E_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
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
        'contains_create_serbest_zaman_events' => false,
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
    $meta['contains_create_serbest_zaman_events'] = stripos($contents, 'serbest_zaman_events') !== false;
    $meta['contains_insert'] = stripos($contents, 'INSERT INTO') !== false || stripos($contents, 'INSERT ') !== false;
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;

    $ok = $meta['bytes'] > 0
        && $meta['contains_create_personeller']
        && $meta['contains_create_haftalik_kapanislar']
        && $meta['contains_create_haftalik_kapanis_satirlari']
        && $meta['contains_create_serbest_zaman_events']
        && $meta['contains_insert']
        && $meta['contains_commit'];
    file_put_contents(__DIR__ . '/s79er3_latest_backup_path.txt', basename($backupPath));

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_E_BACKUP_OK' : 'S79_E_R3_BLOCKED_BACKUP',
        'backup' => $meta,
        'identity' => $identity,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s79er3_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_030_*.sql') ?: [];
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
        echo json_encode(['ok' => false, 'code' => 'S79_E_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existing = s79_existing_schema($pdo);
    $beforeCounts = s79_counts($pdo);

    if ($existing['partial']) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S79_E_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
            'existing' => $existing,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($existing['both_present']) {
        $match = s79_schema_matches_contract($pdo);
        echo json_encode([
            'ok' => $match['ok'],
            'code' => $match['ok'] ? 'S79_E_MIGRATE_OK' : 'S79_E_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
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
            'code' => 'S79_E_R3_BLOCKED_MIGRATION_DRIFT',
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
            'code' => 'S79_E_R3_BLOCKED_MIGRATION_DRIFT',
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
            'code' => 'S79_E_R3_BLOCKED_MIGRATION_APPLY',
            'error' => $e->getMessage(),
            'sqlstate' => ($e instanceof PDOException && isset($e->errorInfo[0])) ? $e->errorInfo[0] : null,
            'driver_code' => ($e instanceof PDOException && isset($e->errorInfo[1])) ? $e->errorInfo[1] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $match = s79_schema_matches_contract($pdo);
    $afterCounts = s79_counts($pdo);
    $parentStable =
        $afterCounts['haftalik_kapanislar'] === $beforeCounts['haftalik_kapanislar']
        && $afterCounts['haftalik_kapanis_satirlari'] === $beforeCounts['haftalik_kapanis_satirlari']
        && $afterCounts['personeller'] === $beforeCounts['personeller']
        && $afterCounts['users'] === $beforeCounts['users']
        && $afterCounts['subeler'] === $beforeCounts['subeler']
        && $afterCounts['surecler'] === $beforeCounts['surecler']
        && $afterCounts['serbest_zaman_events'] === $beforeCounts['serbest_zaman_events'];
    $emptyNew = ($afterCounts['haftalik_kapanis_revizyon_talepleri'] ?? -1) === 0
        && ($afterCounts['haftalik_kapanis_revizyon_talebi_gecmisi'] ?? -1) === 0;
    $ok = $match['ok'] && $parentStable && $emptyNew;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_E_MIGRATE_OK' : 'S79_E_R3_BLOCKED_MIGRATION_APPLY',
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
        'code' => $ok ? 'S79_E_POSTCHECK_OK' : 'S79_E_MIGRATION_APPLIED_SCHEMA_ACCEPTANCE_BLOCKED',
        'identity' => $identity,
        'counts' => $counts,
        'schema_match' => $match,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'counts') {
    echo json_encode([
        'ok' => true,
        'code' => 'S79_E_COUNTS_OK',
        'counts' => s79_counts($pdo),
        'identity' => s79_identity($pdo),
        'marker_rows' => s79_marker_rows($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'integrity') {
    $counts = s79_counts($pdo);
    $orphanGecmis = 0;
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi')
        && s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $orphanGecmis = (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi g
             LEFT JOIN haftalik_kapanis_revizyon_talepleri t ON t.id = g.revizyon_talebi_id
             WHERE t.id IS NULL'
        )->fetchColumn();
    }
    $correctionNonNull = 0;
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $correctionNonNull = (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri WHERE correction_event_id IS NOT NULL'
        )->fetchColumn();
    }
    $markers = s79_marker_rows($pdo);
    $ok = $orphanGecmis === 0
        && $correctionNonNull === 0
        && $markers['talep'] === 0
        && $markers['gecmis'] === 0
        && $markers['personel'] === 0
        && $markers['kapanis'] === 0
        && $markers['satir'] === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_E_FINAL_INTEGRITY_OK' : 'S79_E_INTEGRITY_FAILED',
        'counts' => $counts,
        'orphan_gecmis' => $orphanGecmis,
        'correction_event_id_non_null' => $correctionNonNull,
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

function s79_gecmis_count(PDO $pdo, int $talepId, ?string $aksiyon = null): int
{
    if (!s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi')) {
        return 0;
    }
    if ($aksiyon === null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi WHERE revizyon_talebi_id = :id');
        $stmt->execute(['id' => $talepId]);
        return (int) $stmt->fetchColumn();
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi
         WHERE revizyon_talebi_id = :id AND aksiyon = :a'
    );
    $stmt->execute(['id' => $talepId, 'a' => $aksiyon]);
    return (int) $stmt->fetchColumn();
}

function s79_rt_create_body(
    int $personelId,
    int $kaynakId,
    string $etkilenenTarih,
    string $haftaBaslangic,
    string $haftaBitis,
    string $gerekce = S79_SMOKE_MARKER
): array {
    return [
        'personel_id' => $personelId,
        'hafta_baslangic' => $haftaBaslangic,
        'hafta_bitis' => $haftaBitis,
        'etkilenen_tarih' => $etkilenenTarih,
        'kaynak_tipi' => 'HAFTALIK_KAPANIS_SATIR',
        'kaynak_id' => $kaynakId,
        'revizyon_tipi' => 'KAPANIS_HESAP_REVIZYONU',
        'gerekce' => $gerekce,
        'onceki_deger' => ['dakika' => 60],
        'talep_edilen_deger' => ['dakika' => 90],
    ];
}

function s79_insert_week_snapshot(
    PDO $pdo,
    int $subeId,
    int $personelId,
    int $userId,
    string $hb,
    string $he,
    int $fm = 0
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
    $yil = (int) substr($hb, 0, 4);
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
            :hb, :he, :yil, 1,
            'KAPANDI', 'A2_MOTOR_V1',
            :toplam, 2700, :fm,
            0, 1,
            '[]', 0, 0,
            :hesaplama, 7, NULL
         )"
    );
    $insS->execute([
        'kapanis_id' => $kapanisId,
        'personel_id' => $personelId,
        'hb' => $hb,
        'he' => $he,
        'yil' => $yil,
        'toplam' => 2700 + $fm,
        'fm' => $fm,
        'hesaplama' => $he . ' 12:00:00',
    ]);
    return ['kapanis_id' => $kapanisId, 'snapshot_id' => (int) $pdo->lastInsertId()];
}

function s79_insert_open_kapanis(PDO $pdo, int $subeId, int $userId, string $hb, string $he): int
{
    $insK = $pdo->prepare(
        "INSERT INTO haftalik_kapanislar (
            sube_id, hafta_baslangic, hafta_bitis, departman_id,
            state, personel_sayisi, snapshot_satir_sayisi, kaynak_versiyon, created_by
         ) VALUES (
            :sube_id, :hb, :he, NULL,
            'ACIK', 0, 0, 'A2_MOTOR_V1', :created_by
         )"
    );
    $insK->execute(['sube_id' => $subeId, 'hb' => $hb, 'he' => $he, 'created_by' => $userId]);
    return (int) $pdo->lastInsertId();
}

function s79_marker_token(array $meta, string $rol): ?string
{
    $tokens = $meta['tokens'] ?? null;
    if (!is_array($tokens)) {
        return null;
    }
    $token = $tokens[$rol] ?? null;
    return is_string($token) && $token !== '' ? $token : null;
}

function s79_err_code(?array $payload): ?string
{
    if (!is_array($payload)) {
        return null;
    }
    $errors = $payload['errors'] ?? null;
    if (!is_array($errors) || !isset($errors[0]) || !is_array($errors[0])) {
        return null;
    }
    $code = $errors[0]['code'] ?? null;
    return is_string($code) ? $code : null;
}

function s79_track_talep(array &$meta, int $talepId): void
{
    if ($talepId <= 0) {
        return;
    }
    $ids = array_map('intval', $meta['talep_ids'] ?? []);
    if (!in_array($talepId, $ids, true)) {
        $ids[] = $talepId;
    }
    $meta['talep_ids'] = $ids;
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

if ($action === 'smoke_prepare') {
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S79_E_R3_BLOCKED_DB_IDENTITY'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')
        || !s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi')) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SCHEMA_NOT_READY'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $roleNames = ['GENEL_YONETICI', 'BIRIM_AMIRI', 'BOLUM_YONETICISI', 'MUHASEBE', 'PATRON'];
    $roles = [];
    $tokens = [];
    $tokensIssued = [];
    foreach ($roleNames as $rol) {
        $u = s79_load_role_user($pdo, $rol);
        $roles[$rol] = $u;
        if ($u !== null) {
            try {
                $tokens[$rol] = s79_issue_jwt($config, $u['id'], $u['rol']);
                $tokensIssued[$rol] = true;
            } catch (Throwable $e) {
                $tokensIssued[$rol] = false;
            }
        } else {
            $tokensIssued[$rol] = false;
        }
    }
    if ($roles['GENEL_YONETICI'] === null || $roles['BIRIM_AMIRI'] === null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_CRITICAL_ROLES_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ba = $roles['BIRIM_AMIRI'];
    $gy = $roles['GENEL_YONETICI'];
    $subeId = 0;
    if (!empty($ba['sube_ids'])) {
        $subeId = (int) $ba['sube_ids'][0];
    } else {
        $subeRow = $pdo->query('SELECT id FROM subeler ORDER BY id ASC LIMIT 1')->fetch();
        if (!$subeRow) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'code' => 'SMOKE_SCOPE_UNAVAILABLE'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $subeId = (int) $subeRow['id'];
    }

    $departmanId = null;
    if (s79_table_exists($pdo, 'sube_departmanlar')) {
        $dStmt = $pdo->prepare('SELECT departman_id FROM sube_departmanlar WHERE sube_id = :s ORDER BY departman_id ASC LIMIT 1');
        $dStmt->execute(['s' => $subeId]);
        $dep = $dStmt->fetchColumn();
        if ($dep !== false) {
            $departmanId = (int) $dep;
        }
    }

    $dow = (int) $pdo->query("SELECT DAYOFWEEK('" . S79_SMOKE_WEEK_START . "')")->fetchColumn();
    if ($dow !== 2) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_WEEK_NOT_MONDAY', 'dow' => $dow], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingKap = $pdo->prepare('SELECT id FROM haftalik_kapanislar WHERE sube_id = :s AND hafta_baslangic = :h LIMIT 1');
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

    $userId = (int) $gy['id'];
    $etkilenenTarih = '2038-03-08';
    $openEtkilenen = '2038-03-15';
    $personelId = 0;
    $main = null;
    $openKapanisId = 0;

    $pdo->beginTransaction();
    try {
        $insP = $pdo->prepare(
            "INSERT INTO personeller (
                tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
                sicil_no, ise_giris_tarihi, sube_id, departman_id, aktif_durum
             ) VALUES (
                :tc, 'S79ER3', 'Smoke', '1990-01-01', '05000000000', 'S79ER3 Contact', '05000000001',
                :sicil, '2030-01-01', :sube_id, :departman_id, 'AKTIF'
             )"
        );
        $insP->execute([
            'tc' => S79_SMOKE_TC,
            'sicil' => S79_SMOKE_SICIL,
            'sube_id' => $subeId,
            'departman_id' => $departmanId,
        ]);
        $personelId = (int) $pdo->lastInsertId();

        $main = s79_insert_week_snapshot(
            $pdo,
            $subeId,
            $personelId,
            $userId,
            S79_SMOKE_WEEK_START,
            S79_SMOKE_WEEK_END,
            0
        );
        $openKapanisId = s79_insert_open_kapanis(
            $pdo,
            $subeId,
            $userId,
            S79_SMOKE_OPEN_WEEK_START,
            S79_SMOKE_OPEN_WEEK_END
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'S79_E_SMOKE_PREPARE_FAILED', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $meta = [
        'marker' => S79_SMOKE_MARKER,
        'sube_id' => $subeId,
        'personel_id' => $personelId,
        'kapanis_id' => (int) $main['kapanis_id'],
        'snapshot_id' => (int) $main['snapshot_id'],
        'open_kapanis_id' => $openKapanisId,
        'kapanis_ids' => [(int) $main['kapanis_id'], $openKapanisId],
        'hafta_baslangic' => S79_SMOKE_WEEK_START,
        'hafta_bitis' => S79_SMOKE_WEEK_END,
        'open_hafta_baslangic' => S79_SMOKE_OPEN_WEEK_START,
        'open_hafta_bitis' => S79_SMOKE_OPEN_WEEK_END,
        'etkilenen_tarih' => $etkilenenTarih,
        'open_etkilenen_tarih' => $openEtkilenen,
        'talep_ids' => [],
        'roles' => [],
        'tokens' => $tokens,
        'created_at_utc' => gmdate('c'),
    ];
    foreach ($roles as $rol => $u) {
        $meta['roles'][$rol] = $u === null ? null : [
            'id' => $u['id'],
            'username' => $u['username'],
            'rol' => $u['rol'],
            'sube_ids' => $u['sube_ids'],
        ];
    }
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $fixturePublic = $meta;
    unset($fixturePublic['tokens']);

    echo json_encode([
        'ok' => true,
        'code' => 'S79_E_SMOKE_PREPARE_OK',
        'fixture' => $fixturePublic,
        'tokens_issued' => $tokensIssued,
        'role_availability' => array_map(static function ($u) {
            return $u !== null;
        }, $roles),
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_run') {
    $meta = s79_load_smoke_marker();
    if ($meta === null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_MARKER_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $subeId = (int) ($meta['sube_id'] ?? 0);
    $personelId = (int) ($meta['personel_id'] ?? 0);
    $snapshotId = (int) ($meta['snapshot_id'] ?? 0);
    $hb = (string) ($meta['hafta_baslangic'] ?? S79_SMOKE_WEEK_START);
    $he = (string) ($meta['hafta_bitis'] ?? S79_SMOKE_WEEK_END);
    $etkilenen = (string) ($meta['etkilenen_tarih'] ?? '2038-03-08');
    $openHb = (string) ($meta['open_hafta_baslangic'] ?? S79_SMOKE_OPEN_WEEK_START);
    $openHe = (string) ($meta['open_hafta_bitis'] ?? S79_SMOKE_OPEN_WEEK_END);
    $openEtkilenen = (string) ($meta['open_etkilenen_tarih'] ?? '2038-03-15');

    $gyToken = s79_marker_token($meta, 'GENEL_YONETICI');
    $baToken = s79_marker_token($meta, 'BIRIM_AMIRI');
    if ($gyToken === null || $baToken === null) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_TOKENS_MISSING'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $gyH = s79_auth_headers($gyToken, $subeId);
    $baH = s79_auth_headers($baToken, $subeId);
    $bolumToken = s79_marker_token($meta, 'BOLUM_YONETICISI');
    $muhToken = s79_marker_token($meta, 'MUHASEBE');
    $patronToken = s79_marker_token($meta, 'PATRON');
    $bolumH = $bolumToken !== null ? s79_auth_headers($bolumToken, $subeId) : null;
    $muhH = $muhToken !== null ? s79_auth_headers($muhToken, $subeId) : null;
    $patronH = $patronToken !== null ? s79_auth_headers($patronToken, $subeId) : null;

    $steps = [];
    $failed = false;
    $criticalFailed = false;
    $pass = static function (string $name, bool $ok, array $detail = [], bool $critical = false) use (&$steps, &$failed, &$criticalFailed): void {
        $steps[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
        if (!$ok) {
            $failed = true;
            if ($critical) {
                $criticalFailed = true;
            }
        }
    };
    $skip = static function (string $name, string $note = 'not tested — account unavailable') use (&$steps): void {
        $steps[] = ['name' => $name, 'ok' => true, 'detail' => ['skipped' => true, 'note' => $note]];
    };

    $health = s79_http('GET', '/health');
    $pass('health', $health['status'] === 200, ['status' => $health['status']], true);

    $unauthList = s79_http('GET', '/haftalik-kapanis/revizyon-talepleri');
    $pass('unauth GET list', $unauthList['status'] === 401, ['status' => $unauthList['status']]);
    $unauthCreate = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId,
        $snapshotId,
        $etkilenen,
        $hb,
        $he
    ));
    $pass('unauth POST create', $unauthCreate['status'] === 401, ['status' => $unauthCreate['status']]);

    $unknownQuery = s79_http('GET', '/haftalik-kapanis/revizyon-talepleri', null, $gyH, ['foo' => '1']);
    $pass('unknown query GET list', $unknownQuery['status'] === 422, ['status' => $unknownQuery['status'], 'code' => s79_err_code($unknownQuery['payload'])]);

    $emptyCreate = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', [], $baH);
    $pass('empty body create', $emptyCreate['status'] === 422, ['status' => $emptyCreate['status']]);

    $serverOwned = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
        s79_rt_create_body($personelId, $snapshotId, $etkilenen, $hb, $he),
        ['durum' => 'ONAYLANDI']
    ), $baH);
    $pass('server-owned fields create', $serverOwned['status'] === 422, ['status' => $serverOwned['status']]);

    if ($patronH !== null) {
        $patronList = s79_http('GET', '/haftalik-kapanis/revizyon-talepleri', null, $patronH);
        $pass('PATRON list', $patronList['status'] === 403, ['status' => $patronList['status']]);
    } else {
        $skip('PATRON list');
    }

    $create1 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId,
        $snapshotId,
        $etkilenen,
        $hb,
        $he
    ), $baH);
    $talep1 = (int) ($create1['payload']['data']['id'] ?? 0);
    $pass(
        'create KAPANIS_HESAP_REVIZYONU',
        $create1['status'] === 201
            && ($create1['payload']['data']['durum'] ?? '') === 'TASLAK'
            && ($create1['payload']['data']['correction_event_id'] ?? null) === null
            && $talep1 > 0,
        ['status' => $create1['status'], 'id' => $talep1],
        true
    );
    if ($talep1 > 0) {
        s79_track_talep($meta, $talep1);
        $pass('audit OLUSTUR', s79_gecmis_count($pdo, $talep1, 'OLUSTUR') === 1, ['count' => s79_gecmis_count($pdo, $talep1, 'OLUSTUR')]);
    }

    $dupCreate = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId,
        $snapshotId,
        $etkilenen,
        $hb,
        $he
    ), $baH);
    $pass(
        'duplicate create',
        $dupCreate['status'] === 409 && s79_err_code($dupCreate['payload']) === 'ALREADY_EXISTS',
        ['status' => $dupCreate['status'], 'code' => s79_err_code($dupCreate['payload'])],
        true
    );

    $gonder1 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep1 . '/gonder', [], $baH);
    $pass(
        'BA owner gonder',
        $gonder1['status'] === 200 && ($gonder1['payload']['data']['durum'] ?? '') === 'ONAY_BEKLIYOR',
        ['status' => $gonder1['status'], 'code' => s79_err_code($gonder1['payload'])],
        true
    );
    if ($talep1 > 0) {
        $pass('audit GONDER', s79_gecmis_count($pdo, $talep1, 'GONDER') === 1, ['count' => s79_gecmis_count($pdo, $talep1, 'GONDER')]);
    }

    if ($bolumH !== null && (int) (($meta['roles']['BOLUM_YONETICISI']['id'] ?? 0)) !== (int) (($meta['roles']['BIRIM_AMIRI']['id'] ?? 0))) {
        $nonOwnerGonder = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep1 . '/gonder', [], $bolumH);
        $pass(
            'non-owner gonder',
            $nonOwnerGonder['status'] === 403 && s79_err_code($nonOwnerGonder['payload']) === 'REVISION_OWNER_DENIED',
            ['status' => $nonOwnerGonder['status'], 'code' => s79_err_code($nonOwnerGonder['payload'])]
        );
    } else {
        $skip('non-owner gonder');
    }

    $onay1 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep1 . '/onay', [], $gyH);
    $pass(
        'GY onay',
        $onay1['status'] === 200
            && ($onay1['payload']['data']['durum'] ?? '') === 'ONAYLANDI'
            && ($onay1['payload']['data']['correction_event_id'] ?? null) === null,
        ['status' => $onay1['status'], 'code' => s79_err_code($onay1['payload'])],
        true
    );
    if ($talep1 > 0) {
        $pass('audit ONAY', s79_gecmis_count($pdo, $talep1, 'ONAY') === 1, ['count' => s79_gecmis_count($pdo, $talep1, 'ONAY')]);
    }

    $onayRetry = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep1 . '/onay', [], $gyH);
    $pass(
        'onay retry',
        $onayRetry['status'] === 409,
        ['status' => $onayRetry['status'], 'code' => s79_err_code($onayRetry['payload'])],
        true
    );

    $create2 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId,
        $snapshotId,
        $etkilenen,
        $hb,
        $he
    ), $baH);
    $talep2 = (int) ($create2['payload']['data']['id'] ?? 0);
    $pass('create second after terminal', $create2['status'] === 201 && $talep2 > 0, ['status' => $create2['status'], 'id' => $talep2], true);
    if ($talep2 > 0) {
        s79_track_talep($meta, $talep2);
    }
    $gonder2 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep2 . '/gonder', [], $baH);
    $pass('second gonder', $gonder2['status'] === 200, ['status' => $gonder2['status']]);

    if ($muhH !== null) {
        $muhOnay = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep2 . '/onay', [], $muhH);
        $pass('MUHASEBE onay forbidden', $muhOnay['status'] === 403, ['status' => $muhOnay['status']]);
    } else {
        $skip('MUHASEBE onay forbidden');
    }
    if ($bolumH !== null) {
        $bolumOnay = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep2 . '/onay', [], $bolumH);
        $pass('BOLUM onay forbidden', $bolumOnay['status'] === 403, ['status' => $bolumOnay['status']]);
    } else {
        $skip('BOLUM onay forbidden');
    }

    $redMissing = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep2 . '/red', [], $gyH);
    $pass('red missing karar_notu', $redMissing['status'] === 422, ['status' => $redMissing['status']]);
    $redWs = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep2 . '/red', ['karar_notu' => '   '], $gyH);
    $pass('red whitespace karar_notu', $redWs['status'] === 422, ['status' => $redWs['status']]);
    $redOk = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep2 . '/red', ['karar_notu' => S79_SMOKE_MARKER . ' red'], $gyH);
    $pass(
        'red valid',
        $redOk['status'] === 200 && ($redOk['payload']['data']['durum'] ?? '') === 'REDDEDILDI',
        ['status' => $redOk['status'], 'code' => s79_err_code($redOk['payload'])],
        true
    );
    if ($talep2 > 0) {
        $pass('audit RED', s79_gecmis_count($pdo, $talep2, 'RED') === 1, ['count' => s79_gecmis_count($pdo, $talep2, 'RED')]);
    }

    $create3 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId,
        $snapshotId,
        $etkilenen,
        $hb,
        $he
    ), $baH);
    $talep3 = (int) ($create3['payload']['data']['id'] ?? 0);
    $pass('create third', $create3['status'] === 201 && $talep3 > 0, ['status' => $create3['status'], 'id' => $talep3], true);
    if ($talep3 > 0) {
        s79_track_talep($meta, $talep3);
    }
    $iptal3 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep3 . '/iptal', [], $baH);
    $pass(
        'iptal TASLAK',
        $iptal3['status'] === 200 && ($iptal3['payload']['data']['durum'] ?? '') === 'IPTAL',
        ['status' => $iptal3['status']],
        true
    );

    $create4 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId,
        $snapshotId,
        $etkilenen,
        $hb,
        $he
    ), $baH);
    $talep4 = (int) ($create4['payload']['data']['id'] ?? 0);
    $pass('create fourth', $create4['status'] === 201 && $talep4 > 0, ['status' => $create4['status'], 'id' => $talep4], true);
    if ($talep4 > 0) {
        s79_track_talep($meta, $talep4);
    }
    $gonder4 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep4 . '/gonder', [], $baH);
    $pass('fourth gonder', $gonder4['status'] === 200, ['status' => $gonder4['status']]);
    $iptal4 = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep4 . '/iptal', [], $baH);
    $pass(
        'iptal ONAY_BEKLIYOR',
        $iptal4['status'] === 200 && ($iptal4['payload']['data']['durum'] ?? '') === 'IPTAL',
        ['status' => $iptal4['status']],
        true
    );

    $terminalIptal = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talep1 . '/iptal', [], $baH);
    $pass(
        'terminal iptal attempt',
        $terminalIptal['status'] === 409,
        ['status' => $terminalIptal['status'], 'code' => s79_err_code($terminalIptal['payload'])],
        true
    );

    $listResp = s79_http('GET', '/haftalik-kapanis/revizyon-talepleri', null, $gyH);
    $listIds = array_map(static function ($item) {
        return (int) ($item['id'] ?? 0);
    }, $listResp['payload']['data']['items'] ?? []);
    $pass(
        'list contains fixture',
        $listResp['status'] === 200 && in_array($talep1, $listIds, true),
        ['status' => $listResp['status'], 'count' => count($listIds)]
    );
    $detailResp = s79_http('GET', '/haftalik-kapanis/revizyon-talepleri/' . $talep1, null, $gyH);
    $detailKeys = array_keys($detailResp['payload']['data'] ?? []);
    $pass(
        'detail without acik_talep_slot',
        $detailResp['status'] === 200 && !in_array('acik_talep_slot', $detailKeys, true),
        ['status' => $detailResp['status'], 'keys' => $detailKeys]
    );

    $periodNotClosed = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId,
        $snapshotId,
        $openEtkilenen,
        $openHb,
        $openHe
    ), $baH);
    $pass(
        'open week PERIOD_NOT_CLOSED',
        $periodNotClosed['status'] === 409 && s79_err_code($periodNotClosed['payload']) === 'PERIOD_NOT_CLOSED',
        ['status' => $periodNotClosed['status'], 'code' => s79_err_code($periodNotClosed['payload'])]
    );

    $targetNotFound = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
        s79_rt_create_body($personelId, $snapshotId, $etkilenen, $hb, $he),
        ['kaynak_id' => 99999999]
    ), $baH);
    $pass(
        'snapshot wrong id TARGET_NOT_FOUND',
        $targetNotFound['status'] === 404 && s79_err_code($targetNotFound['payload']) === 'TARGET_NOT_FOUND',
        ['status' => $targetNotFound['status'], 'code' => s79_err_code($targetNotFound['payload'])]
    );

    $beforeTalep = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri')->fetchColumn();
    $beforeGecmis = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi')->fetchColumn();
    $listNoWrite = s79_http('GET', '/haftalik-kapanis/revizyon-talepleri', null, $gyH);
    $afterTalep = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri')->fetchColumn();
    $afterGecmis = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi')->fetchColumn();
    $pass(
        'GET list no-write counts',
        $listNoWrite['status'] === 200
            && $beforeTalep === $afterTalep
            && $beforeGecmis === $afterGecmis,
        [
            'status' => $listNoWrite['status'],
            'before_talep' => $beforeTalep,
            'after_talep' => $afterTalep,
            'before_gecmis' => $beforeGecmis,
            'after_gecmis' => $afterGecmis,
        ]
    );

    $ok = !$criticalFailed;
    $fixturePublic = $meta;
    unset($fixturePublic['tokens']);
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_E_SMOKE_RUN_OK' : 'S79_E_SMOKE_RUN_FAILED',
        'steps' => $steps,
        'fixture' => $fixturePublic,
        'talep_ids' => array_values(array_map('intval', $meta['talep_ids'] ?? [])),
        'counts' => s79_counts($pdo),
        'login_note' => 'JWT issued in smoke_prepare from config.local secret; tokens stored only in marker file',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_cleanup') {
    $meta = s79_load_smoke_marker();
    $deleted = [
        'gecmis' => 0,
        'talep' => 0,
        'satir' => 0,
        'kapanis' => 0,
        'personel' => 0,
    ];

    $talepIds = array_values(array_unique(array_map('intval', $meta['talep_ids'] ?? [])));
    $personelId = (int) ($meta['personel_id'] ?? 0);
    $kapanisIds = array_values(array_unique(array_map('intval', $meta['kapanis_ids'] ?? [])));
    if ($kapanisIds === [] && isset($meta['kapanis_id'])) {
        $kapanisIds = [(int) $meta['kapanis_id']];
    }
    if ($personelId <= 0) {
        $p = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil LIMIT 1');
        $p->execute(['tc' => S79_SMOKE_TC, 'sicil' => S79_SMOKE_SICIL]);
        $pid = $p->fetchColumn();
        if ($pid !== false) {
            $personelId = (int) $pid;
        }
    }

    $pdo->beginTransaction();
    try {
        if ($talepIds !== [] && s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi')) {
            $in = implode(',', $talepIds);
            $deleted['gecmis'] = (int) $pdo->exec(
                'DELETE FROM haftalik_kapanis_revizyon_talebi_gecmisi WHERE revizyon_talebi_id IN (' . $in . ')'
            );
        }
        if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
            if ($talepIds !== []) {
                $in = implode(',', $talepIds);
                $deleted['talep'] = (int) $pdo->exec(
                    'DELETE FROM haftalik_kapanis_revizyon_talepleri WHERE id IN (' . $in . ')'
                );
            }
            $d = $pdo->prepare('DELETE FROM haftalik_kapanis_revizyon_talepleri WHERE gerekce LIKE :m');
            $d->execute(['m' => '%' . S79_SMOKE_MARKER . '%']);
            $deleted['talep'] += $d->rowCount();
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
            if (strpos($hb, '2038-03-') !== 0) {
                throw new RuntimeException('REFUSING_NON_SMOKE_WEEK:' . $hb);
            }
            if ($personelId > 0) {
                $d1 = $pdo->prepare('DELETE FROM haftalik_kapanis_satirlari WHERE kapanis_id = :id AND personel_id = :p');
                $d1->execute(['id' => $kid, 'p' => $personelId]);
                $deleted['satir'] += $d1->rowCount();
            }
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
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'S79_E_SMOKE_CLEANUP_FAILED', 'error' => $e->getMessage(), 'deleted' => $deleted], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    @unlink(s79_smoke_marker_path());
    $markers = s79_marker_rows($pdo);
    $ok = $markers['talep'] === 0
        && $markers['gecmis'] === 0
        && $markers['personel'] === 0
        && $markers['kapanis'] === 0
        && $markers['satir'] === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S79_E_SMOKE_CLEANUP_OK' : 'S79_E_SMOKE_CLEANUP_INCOMPLETE',
        'deleted' => $deleted,
        'marker_rows' => $markers,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
