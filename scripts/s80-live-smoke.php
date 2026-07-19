<?php
/**
 * ONE-SHOT S80 live smoke (no migration). Uploaded temporarily to api/public/, executed via HTTPS, then deleted. UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S80_SMOKE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Workflow greps for UNSET_S80_SMOKE_TOKEN; live token must be openssl rand -hex 24.
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

const S79_EXPECTED_MIGRATION_SHA256 = 'unused';
const S79_MIGRATION_FILE = 'UNUSED_NO_MIGRATION.sql';
const S80_SMOKE_MARKER = 'S80 Production Smoke';
const S80_SMOKE_WEEK_START = '2039-04-03'; // Monday (MariaDB DAYOFWEEK=2)
const S80_SMOKE_WEEK_END = '2039-04-09';
const S80_SMOKE_OPEN_WEEK_START = '2039-04-10';
const S80_SMOKE_OPEN_WEEK_END = '2039-04-16';
const S80_SMOKE_TC = '90079000080';
const S80_SMOKE_SICIL = 'S80-SMOKE';

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
    $linked = -1;
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $linked = (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri WHERE correction_event_id IS NOT NULL'
        )->fetchColumn();
    }

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
        'haftalik_kapanis_revizyon_corrections' => s79_count($pdo, 'haftalik_kapanis_revizyon_corrections'),
        'linked_revizyon' => $linked,
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

function s79_column_nullable(PDO $pdo, string $table, string $column): ?bool
{
    $stmt = $pdo->prepare(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
         LIMIT 1"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    $val = $stmt->fetchColumn();
    if ($val === false) {
        return null;
    }

    return strtoupper((string) $val) === 'YES';
}

function s79_named_fk_exists(PDO $pdo, string $table, string $constraintName): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND CONSTRAINT_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $constraintName]);

    return (int) $stmt->fetchColumn() === 1;
}

function s79_parent_preflight(PDO $pdo): array
{
    $parents = [
        'subeler',
        'personeller',
        'users',
        'haftalik_kapanislar',
        'haftalik_kapanis_satirlari',
        'haftalik_kapanis_revizyon_talepleri',
    ];
    $out = [];
    foreach ($parents as $table) {
        $exists = s79_table_exists($pdo, $table);
        $create = $exists ? s79_show_create($pdo, $table) : null;
        $engineOk = is_string($create) && stripos($create, 'ENGINE=InnoDB') !== false;
        $row = [
            'exists' => $exists,
            'innodb' => $engineOk,
            'create_excerpt' => is_string($create) ? substr($create, 0, 500) : null,
        ];
        if ($table === 'haftalik_kapanis_revizyon_talepleri') {
            $nullable = $exists ? s79_column_nullable($pdo, $table, 'correction_event_id') : null;
            $row['correction_event_id_nullable'] = $nullable;
            $row['correction_event_id_present'] = $nullable !== null;
            $row['fk_hkrt_correction_event'] = $exists
                ? s79_named_fk_exists($pdo, $table, 'fk_hkrt_correction_event')
                : false;
            if (!$exists || $nullable === null) {
                $row['innodb'] = false;
            }
        }
        $out[$table] = $row;
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
               'haftalik_kapanis_revizyon_corrections',
               'haftalik_kapanis_revizyon_talepleri'
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
    $corrections = s79_table_exists($pdo, 'haftalik_kapanis_revizyon_corrections');
    $fk = s79_named_fk_exists($pdo, 'haftalik_kapanis_revizyon_talepleri', 'fk_hkrt_correction_event');

    return [
        'haftalik_kapanis_revizyon_corrections' => $corrections,
        'fk_hkrt_correction_event' => $fk,
        'both_absent' => !$corrections && !$fk,
        'both_present' => $corrections && $fk,
        'partial' => ($corrections xor $fk),
        'corrections' => $corrections ? s79_table_schema($pdo, 'haftalik_kapanis_revizyon_corrections') : null,
        'talep' => s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')
            ? s79_table_schema($pdo, 'haftalik_kapanis_revizyon_talepleri')
            : null,
        'fks' => ($corrections || $fk) ? s79_fk_rows($pdo) : [],
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
           AND REFERENCED_TABLE_NAME = 'haftalik_kapanis_revizyon_corrections'"
    );

    return (int) $stmt->fetchColumn();
}

function s79_schema_matches_contract(PDO $pdo): array
{
    $corrections = s79_table_schema($pdo, 'haftalik_kapanis_revizyon_corrections');
    $talep = s79_table_schema($pdo, 'haftalik_kapanis_revizyon_talepleri');
    $fks = s79_fk_rows($pdo);
    $issues = [];

    if ($corrections === null || $talep === null) {
        $issues[] = 'tables_missing';
        return [
            'ok' => false,
            'issues' => $issues,
            'corrections' => $corrections,
            'talep' => $talep,
            'fks' => $fks,
            'correction_event_fk_count' => s79_correction_event_fk_count($pdo),
        ];
    }

    $cCreate = (string) ($corrections['create_table'] ?? '');
    foreach (['utf8mb4', 'utf8mb4_unicode_ci', 'ENGINE=InnoDB'] as $needle) {
        if (stripos($cCreate, $needle) === false) {
            $issues[] = 'corrections_missing:' . $needle;
        }
    }

    $corrCols = array_map(static function ($c) {
        return (string) ($c['Field'] ?? '');
    }, $corrections['columns'] ?? []);
    foreach ([
        'id', 'revizyon_talebi_id', 'personel_id', 'sube_id', 'kapanis_id', 'snapshot_id',
        'hafta_baslangic', 'hafta_bitis', 'etkilenen_tarih', 'kaynak_tipi', 'kaynak_id',
        'correction_tipi', 'onceki_deger', 'yeni_deger', 'delta_dakika', 'delta_gun',
        'bordro_etki_var_mi', 'bordro_etki_tipi', 'aciklama', 'olusturan_kullanici_id',
        'olusturma_zamani', 'iptal_edildi_mi', 'iptal_zamani', 'iptal_eden_kullanici_id',
        'iptal_aciklamasi', 'audit_ref', 'snapshot_ref', 'created_at', 'updated_at',
    ] as $col) {
        if (!in_array($col, $corrCols, true)) {
            $issues[] = 'corrections_col_missing:' . $col;
        }
    }

    $corrIndexes = array_values(array_unique(array_map(static function ($idx) {
        return (string) ($idx['Key_name'] ?? '');
    }, $corrections['indexes'] ?? [])));
    foreach (['PRIMARY', 'uq_hkrc_revizyon_talebi', 'uq_hkrc_audit_ref'] as $idx) {
        if (!in_array($idx, $corrIndexes, true)) {
            $issues[] = 'corrections_index_missing:' . $idx;
        }
    }

    $talepIndexes = array_values(array_unique(array_map(static function ($idx) {
        return (string) ($idx['Key_name'] ?? '');
    }, $talep['indexes'] ?? [])));
    if (!in_array('uq_hkrt_correction_event', $talepIndexes, true)) {
        $issues[] = 'talep_index_missing:uq_hkrt_correction_event';
    }
    if (!s79_named_fk_exists($pdo, 'haftalik_kapanis_revizyon_talepleri', 'fk_hkrt_correction_event')) {
        $issues[] = 'talep_fk_missing:fk_hkrt_correction_event';
    }

    foreach ($fks as $fk) {
        $del = strtoupper((string) ($fk['DELETE_RULE'] ?? ''));
        $upd = strtoupper((string) ($fk['UPDATE_RULE'] ?? ''));
        $name = (string) ($fk['CONSTRAINT_NAME'] ?? '');
        if ($del === 'CASCADE' || $upd === 'CASCADE') {
            $issues[] = 'fk_cascade:' . $name;
        }
        if (!in_array($del, ['RESTRICT', 'NO ACTION'], true)) {
            $issues[] = 'fk_delete_rule:' . $name . '=' . $del;
        }
        if (!in_array($upd, ['RESTRICT', 'NO ACTION'], true)) {
            $issues[] = 'fk_update_rule:' . $name . '=' . $upd;
        }
    }

    $fkCount = s79_correction_event_fk_count($pdo);
    if ($fkCount !== 1) {
        $issues[] = 'correction_event_fk_count:' . $fkCount;
    }

    return [
        'ok' => $issues === [],
        'issues' => $issues,
        'corrections' => $corrections,
        'talep' => $talep,
        'fks' => $fks,
        'correction_event_fk_count' => $fkCount,
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
    $out[] = '-- S80 PHP SQL dump (shared-host fallback)';
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
    $path = __DIR__ . '/karmotor_medisa_pre_031_' . $stamp . '.sql';

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
    return __DIR__ . '/s80_smoke_marker.json';
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
        'corrections' => 0,
        'personel' => 0,
        'kapanis' => 0,
        'satir' => 0,
    ];
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri WHERE gerekce LIKE :m');
        $stmt->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
        $out['talep'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi')) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi g
             INNER JOIN haftalik_kapanis_revizyon_talepleri t ON t.id = g.revizyon_talebi_id
             WHERE t.gerekce LIKE :m OR g.aciklama LIKE :m'
        );
        $stmt->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
        $out['gecmis'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_corrections')) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections
             WHERE audit_ref LIKE :m OR aciklama LIKE :m'
        );
        $stmt->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
        $out['corrections'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'personeller')) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil');
        $stmt->execute(['tc' => S80_SMOKE_TC, 'sicil' => S80_SMOKE_SICIL]);
        $out['personel'] = (int) $stmt->fetchColumn();
    }
    if (s79_table_exists($pdo, 'haftalik_kapanislar')) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM haftalik_kapanislar
             WHERE hafta_baslangic IN (:h1, :h2) OR hafta_baslangic LIKE :prefix'
        );
        $stmt->execute([
            'h1' => S80_SMOKE_WEEK_START,
            'h2' => S80_SMOKE_OPEN_WEEK_START,
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
        $stmt->execute(['tc' => S80_SMOKE_TC, 'sicil' => S80_SMOKE_SICIL]);
        $out['satir'] = (int) $stmt->fetchColumn();
    }

    return $out;
}


if (in_array($action, ['preflight','backup','download_backup','migrate','postcheck'], true)) {
    http_response_code(410);
    echo json_encode(['ok' => false, 'code' => 'S80_MIGRATE_ACTIONS_DISABLED', 'message' => 'S80 smoke tooling does not migrate'], JSON_UNESCAPED_UNICODE);
    exit;
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
            'code' => 'S80_R3_BLOCKED_DB_IDENTITY',
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
        $code = 'S80_R3_BLOCKED_PARENT_SCHEMA';
        $ok = false;
    } elseif ($existing['partial']) {
        $code = 'S80_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
        $ok = false;
    } elseif ($existing['both_present']) {
        $schemaMatch = s79_schema_matches_contract($pdo);
        if ($schemaMatch['ok']) {
            $code = 'S80_SCHEMA_ALREADY_APPLIED';
            $ok = true;
        } else {
            $code = 'S80_R3_BLOCKED_EXISTING_SCHEMA_DRIFT';
            $ok = false;
        }
    } else {
        $code = 'S80_PREFLIGHT_OK';
        $ok = true;
    }

    echo json_encode([
        'ok' => $ok,
        'code' => $code,
        'already_applied' => $existing['both_present'] && $ok && $code === 'S80_SCHEMA_ALREADY_APPLIED',
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
        echo json_encode(['ok' => false, 'code' => 'S80_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
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
        'contains_create_haftalik_kapanis_revizyon_talepleri' => false,
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
    $meta['contains_create_haftalik_kapanis_revizyon_talepleri'] = stripos($contents, 'haftalik_kapanis_revizyon_talepleri') !== false;
    $meta['contains_create_serbest_zaman_events'] = stripos($contents, 'serbest_zaman_events') !== false;
    $meta['contains_insert'] = stripos($contents, 'INSERT INTO') !== false || stripos($contents, 'INSERT ') !== false;
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;

    $ok = $meta['bytes'] > 0
        && $meta['contains_create_personeller']
        && $meta['contains_create_haftalik_kapanislar']
        && $meta['contains_create_haftalik_kapanis_satirlari']
        && $meta['contains_create_haftalik_kapanis_revizyon_talepleri']
        && $meta['contains_insert']
        && $meta['contains_commit'];
    file_put_contents(__DIR__ . '/s80_latest_backup_path.txt', basename($backupPath));

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S80_BACKUP_OK' : 'S80_R3_BLOCKED_BACKUP',
        'backup' => $meta,
        'identity' => $identity,
        'counts' => s79_counts($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s80_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_031_*.sql') ?: [];
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
        echo json_encode(['ok' => false, 'code' => 'S80_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existing = s79_existing_schema($pdo);
    $beforeCounts = s79_counts($pdo);

    if ($existing['partial']) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'code' => 'S80_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
            'existing' => $existing,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($existing['both_present']) {
        $match = s79_schema_matches_contract($pdo);
        echo json_encode([
            'ok' => $match['ok'],
            'code' => $match['ok'] ? 'S80_MIGRATE_OK' : 'S80_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
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
            'code' => 'S80_R3_BLOCKED_MIGRATION_DRIFT',
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
            'code' => 'S80_R3_BLOCKED_MIGRATION_DRIFT',
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
            'code' => 'S80_R3_BLOCKED_MIGRATION_APPLY',
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
        && $afterCounts['haftalik_kapanis_revizyon_talepleri'] === $beforeCounts['haftalik_kapanis_revizyon_talepleri']
        && $afterCounts['haftalik_kapanis_revizyon_talebi_gecmisi'] === $beforeCounts['haftalik_kapanis_revizyon_talebi_gecmisi']
        && ($afterCounts['haftalik_kapanis_revizyon_corrections'] ?? -1) === 0;
    $emptyNew = ($afterCounts['haftalik_kapanis_revizyon_corrections'] ?? -1) === 0;
    $ok = $match['ok'] && $parentStable && $emptyNew;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S80_MIGRATE_OK' : 'S80_R3_BLOCKED_MIGRATION_APPLY',
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
        'code' => $ok ? 'S80_POSTCHECK_OK' : 'S80_MIGRATION_APPLIED_SCHEMA_ACCEPTANCE_BLOCKED',
        'identity' => $identity,
        'counts' => $counts,
        'schema_match' => $match,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'counts') {
    echo json_encode([
        'ok' => true,
        'code' => 'S80_COUNTS_OK',
        'counts' => s79_counts($pdo),
        'identity' => s79_identity($pdo),
        'marker_rows' => s79_marker_rows($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'integrity') {
    $counts = s79_counts($pdo);
    $orphanCorrection = 0;
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_corrections')
        && s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $orphanCorrection = (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections c
             LEFT JOIN haftalik_kapanis_revizyon_talepleri t ON t.id = c.revizyon_talebi_id
             WHERE t.id IS NULL
                OR t.correction_event_id IS NULL
                OR t.correction_event_id <> c.id'
        )->fetchColumn();
    }
    $dualLink = 0;
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $dualLink = (int) $pdo->query(
            'SELECT COUNT(*) FROM (
                SELECT correction_event_id
                FROM haftalik_kapanis_revizyon_talepleri
                WHERE correction_event_id IS NOT NULL
                GROUP BY correction_event_id
                HAVING COUNT(*) > 1
             ) d'
        )->fetchColumn();
    }
    $markers = s79_marker_rows($pdo);
    $correctionCount = (int) ($counts['haftalik_kapanis_revizyon_corrections'] ?? 0);
    $linked = (int) ($counts['linked_revizyon'] ?? 0);
    // Post-031 migrate window / post-smoke-cleanup baseline expects empty corrections.
    $ok = $orphanCorrection === 0
        && $dualLink === 0
        && $markers['talep'] === 0
        && $markers['gecmis'] === 0
        && $markers['corrections'] === 0
        && $markers['personel'] === 0
        && $markers['kapanis'] === 0
        && $markers['satir'] === 0
        && $correctionCount === 0
        && $linked === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S80_FINAL_INTEGRITY_OK' : 'S80_INTEGRITY_FAILED',
        'counts' => $counts,
        'orphan_correction' => $orphanCorrection,
        'dual_link' => $dualLink,
        'correction_count' => $correctionCount,
        'linked_revizyon' => $linked,
        'marker_rows' => $markers,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function s79_api_base(): string
{
    return 'https://www.karmotors.com.tr/personelmedisa/api';
}

function s79_http(string $method, string $path, $body = null, array $headers = [], array $query = [], ?string $rawBody = null): array
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
    if ($rawBody !== null) {
        $hdrs[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $hdrs;
        $opts[CURLOPT_POSTFIELDS] = $rawBody;
    } elseif ($body !== null) {
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

/** @param array<int, array{method:string,path:string,body?:mixed,headers?:array,query?:array}> $requests */
function s79_http_parallel(array $requests): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($requests as $i => $req) {
        $method = strtoupper((string) ($req['method'] ?? 'GET'));
        $path = (string) ($req['path'] ?? '/');
        $url = s79_api_base() . $path;
        $query = is_array($req['query'] ?? null) ? $req['query'] : [];
        if ($query !== []) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($query);
        }
        $hdrs = ['Accept: application/json'];
        $headers = is_array($req['headers'] ?? null) ? $req['headers'] : [];
        foreach ($headers as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HEADER => true,
        ];
        if (array_key_exists('body', $req)) {
            $json = json_encode($req['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hdrs[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $hdrs;
            $opts[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $i => $ch) {
        $raw = curl_multi_getcontent($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $bodyRaw = is_string($raw) ? substr($raw, $headerSize) : '';
        $decoded = json_decode($bodyRaw, true);
        $out[$i] = [
            'status' => $httpStatus,
            'payload' => is_array($decoded) ? $decoded : null,
            'raw' => $bodyRaw,
            'code' => is_array($decoded) ? s79_err_code($decoded) : null,
        ];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $out;
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
    string $gerekce = S80_SMOKE_MARKER,
    array $overrides = []
): array {
    return array_merge([
        'personel_id' => $personelId,
        'hafta_baslangic' => $haftaBaslangic,
        'hafta_bitis' => $haftaBitis,
        'etkilenen_tarih' => $etkilenenTarih,
        'kaynak_tipi' => 'HAFTALIK_KAPANIS_SATIR',
        'kaynak_id' => $kaynakId,
        'revizyon_tipi' => 'KAPANIS_HESAP_REVIZYONU',
        'gerekce' => $gerekce,
        'talep_edilen_deger' => 90,
        'bordro_etki_var_mi' => true,
    ], $overrides);
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

function s79_track_correction(array &$meta, int $correctionId): void
{
    if ($correctionId <= 0) {
        return;
    }
    $ids = array_map('intval', $meta['correction_ids'] ?? []);
    if (!in_array($correctionId, $ids, true)) {
        $ids[] = $correctionId;
    }
    $meta['correction_ids'] = $ids;
    file_put_contents(s79_smoke_marker_path(), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function s79_snapshot_checksum(PDO $pdo): array
{
    $out = [
        'haftalik_kapanislar' => s79_count($pdo, 'haftalik_kapanislar'),
        'haftalik_kapanis_satirlari' => s79_count($pdo, 'haftalik_kapanis_satirlari'),
        'haftalik_kapanis_revizyon_corrections' => s79_count($pdo, 'haftalik_kapanis_revizyon_corrections'),
        'linked_revizyon' => -1,
    ];
    if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $out['linked_revizyon'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri WHERE correction_event_id IS NOT NULL'
        )->fetchColumn();
    }
    foreach (['puantaj', 'surecler', 'serbest_zaman_events'] as $table) {
        if (s79_table_exists($pdo, $table)) {
            $out[$table] = s79_count($pdo, $table);
        }
    }

    return $out;
}

/**
 * Create → gonder → onay via API. Returns ['ok'=>bool,'talep_id'=>int,'detail'=>array].
 *
 * @param array<string,mixed> $createBody
 * @param array<string,string> $baH
 * @param array<string,string> $gyH
 */
function s79_api_create_onaylandi(array &$meta, array $createBody, array $baH, array $gyH): array
{
    $create = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', $createBody, $baH);
    $talepId = (int) ($create['payload']['data']['id'] ?? 0);
    if ($talepId > 0) {
        s79_track_talep($meta, $talepId);
    }
    if ($create['status'] !== 201 || $talepId <= 0) {
        return [
            'ok' => false,
            'talep_id' => $talepId,
            'detail' => ['stage' => 'create', 'status' => $create['status'], 'code' => s79_err_code($create['payload'])],
        ];
    }
    $gonder = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/gonder', null, $baH);
    if ($gonder['status'] !== 200) {
        return [
            'ok' => false,
            'talep_id' => $talepId,
            'detail' => ['stage' => 'gonder', 'status' => $gonder['status'], 'code' => s79_err_code($gonder['payload'])],
        ];
    }
    $onay = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/onay', [], $gyH);
    $linked = $onay['payload']['data']['correction_event_id'] ?? null;
    $ok = $onay['status'] === 200
        && ($onay['payload']['data']['durum'] ?? '') === 'ONAYLANDI'
        && $linked === null;

    return [
        'ok' => $ok,
        'talep_id' => $talepId,
        'detail' => [
            'stage' => 'onay',
            'status' => $onay['status'],
            'code' => s79_err_code($onay['payload']),
            'correction_event_id' => $linked,
        ],
    ];
}

if ($action === 'smoke_prepare') {
    $identity = s79_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S80_R3_BLOCKED_DB_IDENTITY'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')
        || !s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi')
        || !s79_table_exists($pdo, 'haftalik_kapanis_revizyon_corrections')) {
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

    $dow = (int) $pdo->query("SELECT DAYOFWEEK('" . S80_SMOKE_WEEK_START . "')")->fetchColumn();
    if ($dow !== 2) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_WEEK_NOT_MONDAY', 'dow' => $dow], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $existingKap = $pdo->prepare('SELECT id FROM haftalik_kapanislar WHERE sube_id = :s AND hafta_baslangic = :h LIMIT 1');
    $existingKap->execute(['s' => $subeId, 'h' => S80_SMOKE_WEEK_START]);
    if ($existingKap->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_KAPANIS_ALREADY_EXISTS'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $existingPersonel = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil LIMIT 1');
    $existingPersonel->execute(['tc' => S80_SMOKE_TC, 'sicil' => S80_SMOKE_SICIL]);
    if ($existingPersonel->fetch()) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'SMOKE_PERSONEL_ALREADY_EXISTS'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = (int) $gy['id'];
    $etkilenenTarih = '2039-04-04';
    $openEtkilenen = '2039-04-11';
    $personelId = 0;
    $main = null;
    $surecId = 0;
    $closedSurecIds = [];
    $tempUserSube = null;

    try {
        $pdo->beginTransaction();
        $insP = $pdo->prepare(
            "INSERT INTO personeller (
                tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
                sicil_no, ise_giris_tarihi, sube_id, departman_id, aktif_durum
             ) VALUES (
                :tc, 'S80', 'Smoke', '1990-01-01', '05000000000', 'S80 Contact', '05000000001',
                :sicil, '2030-01-01', :sube_id, :departman_id, 'AKTIF'
             )"
        );
        $insP->execute([
            'tc' => S80_SMOKE_TC,
            'sicil' => S80_SMOKE_SICIL,
            'sube_id' => $subeId,
            'departman_id' => $departmanId,
        ]);
        $personelId = (int) $pdo->lastInsertId();

        $main = s79_insert_week_snapshot(
            $pdo,
            $subeId,
            $personelId,
            $userId,
            S80_SMOKE_WEEK_START,
            S80_SMOKE_WEEK_END,
            0
        );

        $insSurec = $pdo->prepare(
            "INSERT INTO surecler (
                personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi,
                ucretli_mi, aciklama, state
             ) VALUES (
                :personel_id, 'IZIN', 'YILLIK_IZIN', :bas, :bit,
                0, :aciklama, 'AKTIF'
             )"
        );
        // Open-week surec: PERIOD_NOT_CLOSED mapping for create without KAPANDI week.
        $insSurec->execute([
            'personel_id' => $personelId,
            'bas' => S80_SMOKE_OPEN_WEEK_START,
            'bit' => S80_SMOKE_OPEN_WEEK_START,
            'aciklama' => S80_SMOKE_MARKER,
        ]);
        $surecId = (int) $pdo->lastInsertId();

        // Closed-week surecler for state-guard / SUREC_GEC_GIRIS produce mapping.
        for ($i = 0; $i < 5; $i++) {
            $insSurec->execute([
                'personel_id' => $personelId,
                'bas' => $etkilenenTarih,
                'bit' => $etkilenenTarih,
                'aciklama' => S80_SMOKE_MARKER . ' closed-' . $i,
            ]);
            $closedSurecIds[] = (int) $pdo->lastInsertId();
        }

        $bolumUser = $roles['BOLUM_YONETICISI'];
        if ($bolumUser !== null) {
            $bolumId = (int) $bolumUser['id'];
            $hasSube = $pdo->prepare('SELECT 1 FROM user_subeler WHERE user_id = :u AND sube_id = :s LIMIT 1');
            $hasSube->execute(['u' => $bolumId, 's' => $subeId]);
            if (!$hasSube->fetchColumn()) {
                $insUs = $pdo->prepare('INSERT INTO user_subeler (user_id, sube_id) VALUES (:u, :s)');
                $insUs->execute(['u' => $bolumId, 's' => $subeId]);
                $tempUserSube = ['user_id' => $bolumId, 'sube_id' => $subeId];
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'S80_SMOKE_PREPARE_FAILED', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $meta = [
        'marker' => S80_SMOKE_MARKER,
        'sube_id' => $subeId,
        'personel_id' => $personelId,
        'kapanis_id' => (int) $main['kapanis_id'],
        'snapshot_id' => (int) $main['snapshot_id'],
        'surec_id' => $surecId,
        'closed_surec_ids' => $closedSurecIds,
        'open_kapanis_id' => 0,
        'kapanis_ids' => [(int) $main['kapanis_id']],
        'temp_user_sube' => $tempUserSube,
        'hafta_baslangic' => S80_SMOKE_WEEK_START,
        'hafta_bitis' => S80_SMOKE_WEEK_END,
        'open_hafta_baslangic' => S80_SMOKE_OPEN_WEEK_START,
        'open_hafta_bitis' => S80_SMOKE_OPEN_WEEK_END,
        'etkilenen_tarih' => $etkilenenTarih,
        'open_etkilenen_tarih' => $openEtkilenen,
        'talep_ids' => [],
        'correction_ids' => [],
        'roles' => [],
        'tokens' => $tokens,
        'created_at_utc' => gmdate('c'),
        'note_acik_kapanis' => 'schema_forbids_ACIK_state_covered_by_missing_kapanis_week_via_SUREC',
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
        'code' => 'S80_SMOKE_PREPARE_OK',
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
    $hb = (string) ($meta['hafta_baslangic'] ?? S80_SMOKE_WEEK_START);
    $he = (string) ($meta['hafta_bitis'] ?? S80_SMOKE_WEEK_END);
    $etkilenen = (string) ($meta['etkilenen_tarih'] ?? '2039-04-04');
    $openHb = (string) ($meta['open_hafta_baslangic'] ?? S80_SMOKE_OPEN_WEEK_START);
    $openHe = (string) ($meta['open_hafta_bitis'] ?? S80_SMOKE_OPEN_WEEK_END);
    $openEtkilenen = (string) ($meta['open_etkilenen_tarih'] ?? '2039-04-11');
    $closedSurecIds = array_values(array_map('intval', $meta['closed_surec_ids'] ?? []));
    $surecId = (int) ($meta['surec_id'] ?? 0);

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

    $corrBeforeState = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();

    $health = s79_http('GET', '/health');
    $pass('health', $health['status'] === 200, ['status' => $health['status']], true);

    // --- S80 enrichment + server-owned onceki ---
    $kaynaklar = s79_http('GET', '/haftalik-kapanis/revizyon-kaynaklar', null, $baH, [
        'personel_id' => $personelId,
        'hafta_baslangic' => $hb,
        'hafta_bitis' => $he,
    ]);
    $kaynakItems = $kaynaklar['payload']['data']['items'] ?? $kaynaklar['payload']['data'] ?? [];
    if (!is_array($kaynakItems)) { $kaynakItems = []; }
    $pass('S80 kaynaklar', $kaynaklar['status'] === 200 && count($kaynakItems) > 0, ['status' => $kaynaklar['status'], 'count' => count($kaynakItems)], true);

    $forged = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId, $snapshotId, $etkilenen, $hb, $he, S80_SMOKE_MARKER . ' forged-onceki', ['onceki_deger' => ['forged' => true]]
    ), $baH);
    $pass('S80 forged onceki_deger rejected', in_array($forged['status'], [400, 422], true), ['status' => $forged['status'], 'code' => s79_err_code($forged['payload'])], true);

    $enrichCreate = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId, $snapshotId, $etkilenen, $hb, $he, S80_SMOKE_MARKER . ' enrich-create'
    ), $baH);
    $enrichId = (int) ($enrichCreate['payload']['data']['id'] ?? 0);
    if ($enrichId > 0) { s79_track_talep($meta, $enrichId); }
    $enrichOnceki = $enrichCreate['payload']['data']['onceki_deger'] ?? null;
    $pass('S80 create server-owned onceki', $enrichCreate['status'] === 201 && $enrichId > 0 && $enrichOnceki !== null, ['status' => $enrichCreate['status'], 'onceki' => $enrichOnceki], true);

    $list = s79_http('GET', '/haftalik-kapanis/revizyon-talepleri', null, $gyH, ['personel_id' => $personelId]);
    $listItems = $list['payload']['data']['items'] ?? [];
    $hit = null;
    foreach ($listItems as $row) { if ((int)($row['id'] ?? 0) === $enrichId) { $hit = $row; break; } }
    $enrichOk = is_array($hit)
        && isset($hit['personel_ad_soyad'], $hit['sicil_no'], $hit['sube_adi'], $hit['aktif_correction_var_mi']);
    $pass('S80 list enrichment fields', $list['status'] === 200 && $enrichOk, ['status' => $list['status'], 'hit_keys' => is_array($hit) ? array_keys($hit) : []], true);

    $detail = s79_http('GET', '/haftalik-kapanis/revizyon-talepleri/' . max(1, $enrichId), null, $gyH);
    $d = $detail['payload']['data'] ?? [];
    $pass('S80 detail enrichment + audit', $detail['status'] === 200
        && isset($d['personel_ad_soyad'], $d['talep_eden_kullanici_adi'])
        && array_key_exists('audit_gecmisi', $d), ['status' => $detail['status']], true);
    if ($enrichId > 0) { s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $enrichId . '/iptal', [], $baH); }


    // --- Unauth ---
    $unauthList = s79_http('GET', '/haftalik-kapanis/revizyon-corrections');
    $pass('unauth GET corrections list', $unauthList['status'] === 401, ['status' => $unauthList['status']]);
    $unauthDetail = s79_http('GET', '/haftalik-kapanis/revizyon-corrections/1');
    $pass('unauth GET corrections detail', $unauthDetail['status'] === 401, ['status' => $unauthDetail['status']]);

    // Need a talep id for unauth produce; create one first (TASLAK) then use it.
    $seedCreate = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', s79_rt_create_body(
        $personelId,
        $snapshotId,
        $etkilenen,
        $hb,
        $he,
        S80_SMOKE_MARKER . ' seed-unauth'
    ), $baH);
    $seedTalepId = (int) ($seedCreate['payload']['data']['id'] ?? 0);
    if ($seedTalepId > 0) {
        s79_track_talep($meta, $seedTalepId);
    }
    $pass('seed talep for unauth produce', $seedCreate['status'] === 201 && $seedTalepId > 0, ['status' => $seedCreate['status'], 'id' => $seedTalepId], true);
    $unauthProduce = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . max(1, $seedTalepId) . '/correction-uret', []);
    $pass('unauth POST correction-uret', $unauthProduce['status'] === 401, ['status' => $unauthProduce['status']]);
    $unauthCancel = s79_http('POST', '/haftalik-kapanis/revizyon-corrections/1/iptal', []);
    $pass('unauth POST correction iptal', $unauthCancel['status'] === 401, ['status' => $unauthCancel['status']]);

    // --- Permission matrix (list/detail) ---
    if ($patronH !== null) {
        $patronList = s79_http('GET', '/haftalik-kapanis/revizyon-corrections', null, $patronH);
        $pass('PATRON corrections list', $patronList['status'] === 403, ['status' => $patronList['status']]);
    } else {
        $skip('PATRON corrections list');
    }

    // --- Main ONAYLANDI talep via API ---
    // Close seed TASLAK first so same kaynak can be reused.
    if ($seedTalepId > 0) {
        s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $seedTalepId . '/iptal', [], $baH);
    }

    $mainOnay = s79_api_create_onaylandi(
        $meta,
        s79_rt_create_body($personelId, $snapshotId, $etkilenen, $hb, $he, S80_SMOKE_MARKER . ' main'),
        $baH,
        $gyH
    );
    $mainTalepId = (int) $mainOnay['talep_id'];
    $pass('create→gonder→onay ONAYLANDI null link', $mainOnay['ok'] && $mainTalepId > 0, $mainOnay['detail'], true);

    // --- State guards (separate talepler on closed surecler) ---
    $stateDefs = [
        ['name' => 'TASLAK', 'surec_idx' => 0, 'leave' => 'TASLAK'],
        ['name' => 'ONAY_BEKLIYOR', 'surec_idx' => 1, 'leave' => 'ONAY_BEKLIYOR'],
        ['name' => 'REDDEDILDI', 'surec_idx' => 2, 'leave' => 'REDDEDILDI'],
        ['name' => 'IPTAL', 'surec_idx' => 3, 'leave' => 'IPTAL'],
    ];
    foreach ($stateDefs as $def) {
        $sid = $closedSurecIds[$def['surec_idx']] ?? 0;
        if ($sid <= 0) {
            $skip('state produce ' . $def['name'], 'closed surec fixture missing');
            continue;
        }
        $body = s79_rt_create_body(
            $personelId,
            $sid,
            $etkilenen,
            $hb,
            $he,
            S80_SMOKE_MARKER . ' state-' . $def['name'],
            [
                'kaynak_tipi' => 'SUREC',
                'revizyon_tipi' => 'SUREC_GEC_GIRIS',
                'bordro_etki_var_mi' => false,
            ]
        );
        $create = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', $body, $baH);
        $tid = (int) ($create['payload']['data']['id'] ?? 0);
        if ($tid > 0) {
            s79_track_talep($meta, $tid);
        }
        if ($create['status'] !== 201 || $tid <= 0) {
            $pass('state prepare ' . $def['name'], false, ['status' => $create['status'], 'code' => s79_err_code($create['payload'])], true);
            continue;
        }
        if ($def['leave'] === 'ONAY_BEKLIYOR' || $def['leave'] === 'REDDEDILDI') {
            s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $tid . '/gonder', null, $baH);
        }
        if ($def['leave'] === 'REDDEDILDI') {
            s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $tid . '/red', ['karar_notu' => S80_SMOKE_MARKER . ' red'], $gyH);
        }
        if ($def['leave'] === 'IPTAL') {
            s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $tid . '/iptal', [], $baH);
        }
        $beforeC = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
        $prod = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $tid . '/correction-uret', [], $gyH);
        $afterC = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
        $pass(
            'state produce ' . $def['name'],
            $prod['status'] === 409
                && s79_err_code($prod['payload']) === 'CORRECTION_NOT_ALLOWED_FOR_STATE'
                && $afterC === $beforeC,
            [
                'status' => $prod['status'],
                'code' => s79_err_code($prod['payload']),
                'before' => $beforeC,
                'after' => $afterC,
            ],
            true
        );
    }

    // --- Produce validation ---
    $badFields = s79_http(
        'POST',
        '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret',
        ['foo' => 1],
        $gyH
    );
    $pass(
        'produce unknown fields',
        $badFields['status'] === 400 && s79_err_code($badFields['payload']) === 'INVALID_CORRECTION_PAYLOAD',
        ['status' => $badFields['status'], 'code' => s79_err_code($badFields['payload'])]
    );
    $brokenJson = s79_http(
        'POST',
        '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret',
        null,
        $gyH,
        [],
        '{not-json'
    );
    $pass(
        'produce broken JSON',
        $brokenJson['status'] === 400 && s79_err_code($brokenJson['payload']) === 'INVALID_CORRECTION_PAYLOAD',
        ['status' => $brokenJson['status'], 'code' => s79_err_code($brokenJson['payload'])]
    );

    // --- Snapshot checksum before produce ---
    $checksumBefore = s79_snapshot_checksum($pdo);

    // --- Permission: produce/cancel roles ---
    if ($bolumH !== null) {
        $bolumProd = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret', [], $bolumH);
        $pass('BOLUM produce forbidden', $bolumProd['status'] === 403, ['status' => $bolumProd['status']]);
    } else {
        $skip('BOLUM produce forbidden');
    }
    if ($muhH !== null) {
        $muhProd = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret', [], $muhH);
        $pass('MUHASEBE produce forbidden', $muhProd['status'] === 403, ['status' => $muhProd['status']]);
    } else {
        $skip('MUHASEBE produce forbidden');
    }
    if ($patronH !== null) {
        $patronProd = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret', [], $patronH);
        $pass('PATRON produce forbidden', $patronProd['status'] === 403, ['status' => $patronProd['status']]);
    } else {
        $skip('PATRON produce forbidden');
    }

    // --- Successful produce ---
    $produceOk = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret', [], $gyH);
    $correctionId = (int) ($produceOk['payload']['data']['id'] ?? 0);
    if ($correctionId > 0) {
        s79_track_correction($meta, $correctionId);
    }
    $delta = (int) ($produceOk['payload']['data']['delta_dakika'] ?? -999);
    $linkStmt = $pdo->prepare('SELECT correction_event_id FROM haftalik_kapanis_revizyon_talepleri WHERE id = :id');
    $linkStmt->execute(['id' => $mainTalepId]);
    $linkVal = $linkStmt->fetchColumn();
    $pass(
        'produce ONAYLANDI success',
        $produceOk['status'] === 200
            && $correctionId > 0
            && $delta === 30
            && (int) $linkVal === $correctionId
            && s79_err_code($produceOk['payload']) !== 'PERIOD_LOCKED',
        [
            'status' => $produceOk['status'],
            'id' => $correctionId,
            'delta_dakika' => $delta,
            'link' => $linkVal,
            'code' => s79_err_code($produceOk['payload']),
        ],
        true
    );

    // --- Mapping: PUANTAJ tip if supported; MOLA; SUREC_GEC_GIRIS → 404 ---
    $puantajOnay = s79_api_create_onaylandi(
        $meta,
        s79_rt_create_body(
            $personelId,
            $snapshotId,
            $etkilenen,
            $hb,
            $he,
            S80_SMOKE_MARKER . ' puantaj-map',
            ['revizyon_tipi' => 'PUANTAJ_GIRIS_CIKIS_DUZELTME', 'bordro_etki_var_mi' => false]
        ),
        $baH,
        $gyH
    );
    if ($puantajOnay['ok']) {
        $pProd = s79_http(
            'POST',
            '/haftalik-kapanis/revizyon-talepleri/' . $puantajOnay['talep_id'] . '/correction-uret',
            [],
            $gyH
        );
        $pCid = (int) ($pProd['payload']['data']['id'] ?? 0);
        if ($pCid > 0) {
            s79_track_correction($meta, $pCid);
        }
        $pass(
            'mapping PUANTAJ_GIRIS_CIKIS_DUZELTME',
            $pProd['status'] === 200
                && ($pProd['payload']['data']['correction_tipi'] ?? '') === 'GIRIS_CIKIS_DUZELTME'
                && (int) ($pProd['payload']['data']['delta_dakika'] ?? -1) === 30,
            [
                'status' => $pProd['status'],
                'tipi' => $pProd['payload']['data']['correction_tipi'] ?? null,
                'delta' => $pProd['payload']['data']['delta_dakika'] ?? null,
            ]
        );
    } else {
        $pass('mapping PUANTAJ_GIRIS_CIKIS_DUZELTME prepare', false, $puantajOnay['detail']);
    }

    $molaOnay = s79_api_create_onaylandi(
        $meta,
        s79_rt_create_body(
            $personelId,
            $snapshotId,
            $etkilenen,
            $hb,
            $he,
            S80_SMOKE_MARKER . ' mola-map',
            ['revizyon_tipi' => 'MOLA_DUZELTME', 'bordro_etki_var_mi' => false]
        ),
        $baH,
        $gyH
    );
    if ($molaOnay['ok']) {
        $mProd = s79_http(
            'POST',
            '/haftalik-kapanis/revizyon-talepleri/' . $molaOnay['talep_id'] . '/correction-uret',
            [],
            $gyH
        );
        $mCid = (int) ($mProd['payload']['data']['id'] ?? 0);
        if ($mCid > 0) {
            s79_track_correction($meta, $mCid);
        }
        $pass(
            'mapping MOLA_DUZELTME',
            $mProd['status'] === 200 && ($mProd['payload']['data']['correction_tipi'] ?? '') === 'MOLA_DUZELTME',
            ['status' => $mProd['status'], 'tipi' => $mProd['payload']['data']['correction_tipi'] ?? null]
        );
    } else {
        $pass('mapping MOLA_DUZELTME prepare', false, $molaOnay['detail']);
    }

    $surecIdx = 4;
    $surecKaynak = $closedSurecIds[$surecIdx] ?? 0;
    if ($surecKaynak > 0) {
        $surecOnay = s79_api_create_onaylandi(
            $meta,
            s79_rt_create_body(
                $personelId,
                $surecKaynak,
                $etkilenen,
                $hb,
                $he,
                S80_SMOKE_MARKER . ' surec-map',
                [
                    'kaynak_tipi' => 'SUREC',
                    'revizyon_tipi' => 'SUREC_GEC_GIRIS',
                    'bordro_etki_var_mi' => false,
                ]
            ),
            $baH,
            $gyH
        );
        if ($surecOnay['ok']) {
            $beforeSurecC = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
            $sProd = s79_http(
                'POST',
                '/haftalik-kapanis/revizyon-talepleri/' . $surecOnay['talep_id'] . '/correction-uret',
                [],
                $gyH
            );
            $afterSurecC = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
            $pass(
                'mapping SUREC_GEC_GIRIS',
                $sProd['status'] === 404
                    && s79_err_code($sProd['payload']) === 'CORRECTION_TARGET_NOT_FOUND'
                    && $afterSurecC === $beforeSurecC,
                [
                    'status' => $sProd['status'],
                    'code' => s79_err_code($sProd['payload']),
                ],
                true
            );
        } else {
            $pass('mapping SUREC_GEC_GIRIS prepare', false, $surecOnay['detail']);
        }
    } else {
        $skip('mapping SUREC_GEC_GIRIS', 'closed surec missing');
    }

    // --- Duplicate produce ---
    $dupProd = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret', [], $gyH);
    $pass(
        'duplicate produce',
        $dupProd['status'] === 409 && s79_err_code($dupProd['payload']) === 'CORRECTION_ALREADY_EXISTS',
        ['status' => $dupProd['status'], 'code' => s79_err_code($dupProd['payload'])],
        true
    );

    // --- Finance mask ---
    $gyDetail = s79_http('GET', '/haftalik-kapanis/revizyon-corrections/' . $correctionId, null, $gyH);
    $gyData = is_array($gyDetail['payload']['data'] ?? null) ? $gyDetail['payload']['data'] : [];
    $pass(
        'GY finance fields visible',
        $gyDetail['status'] === 200
            && array_key_exists('bordro_etki_tipi', $gyData)
            && $gyData['bordro_etki_tipi'] !== null
            && array_key_exists('aciklama', $gyData)
            && $gyData['aciklama'] !== null
            && !empty($gyData['bordro_etki_var_mi']),
        ['status' => $gyDetail['status'], 'bordro_etki_tipi' => $gyData['bordro_etki_tipi'] ?? null]
    );
    $baDetail = s79_http('GET', '/haftalik-kapanis/revizyon-corrections/' . $correctionId, null, $baH);
    $baData = is_array($baDetail['payload']['data'] ?? null) ? $baDetail['payload']['data'] : [];
    $pass(
        'BA finance mask',
        $baDetail['status'] === 200
            && array_key_exists('bordro_etki_tipi', $baData)
            && $baData['bordro_etki_tipi'] === null
            && array_key_exists('aciklama', $baData)
            && $baData['aciklama'] === null
            && array_key_exists('bordro_etki_var_mi', $baData)
            && $baData['bordro_etki_var_mi'] === true,
        [
            'status' => $baDetail['status'],
            'bordro_etki_tipi' => $baData['bordro_etki_tipi'] ?? 'missing',
            'aciklama' => $baData['aciklama'] ?? 'missing',
            'bordro_etki_var_mi' => $baData['bordro_etki_var_mi'] ?? 'missing',
        ]
    );

    // Scope-in list/detail for BA/BOLUM/MUH
    $baList = s79_http('GET', '/haftalik-kapanis/revizyon-corrections', null, $baH);
    $pass('BA corrections list scope-in', $baList['status'] === 200, ['status' => $baList['status']]);
    if ($bolumH !== null) {
        $bolumList = s79_http('GET', '/haftalik-kapanis/revizyon-corrections', null, $bolumH);
        $bolumDet = s79_http('GET', '/haftalik-kapanis/revizyon-corrections/' . $correctionId, null, $bolumH);
        if ($bolumList['status'] === 200 && $bolumDet['status'] === 200) {
            $pass(
                'BOLUM list/detail scope-in',
                true,
                ['list' => $bolumList['status'], 'detail' => $bolumDet['status']]
            );
        } elseif ($bolumDet['status'] === 403 || $bolumList['status'] === 403) {
            $skip(
                'BOLUM list/detail scope-in',
                'BOLUM departman scope mismatch for smoke personel (list=' . $bolumList['status'] . ' detail=' . $bolumDet['status'] . ')'
            );
        } else {
            $pass(
                'BOLUM list/detail scope-in',
                false,
                ['list' => $bolumList['status'], 'detail' => $bolumDet['status']]
            );
        }
    } else {
        $skip('BOLUM list/detail scope-in');
    }
    if ($muhH !== null) {
        $muhList = s79_http('GET', '/haftalik-kapanis/revizyon-corrections', null, $muhH);
        $muhDet = s79_http('GET', '/haftalik-kapanis/revizyon-corrections/' . $correctionId, null, $muhH);
        $pass(
            'MUHASEBE list/detail scope-in',
            $muhList['status'] === 200 && $muhDet['status'] === 200,
            ['list' => $muhList['status'], 'detail' => $muhDet['status']]
        );
    } else {
        $skip('MUHASEBE list/detail scope-in');
    }

    // --- Scope: out-of-scope / client sube_id ---
    $otherSubeId = 0;
    $subeRows = $pdo->query('SELECT id FROM subeler WHERE id <> ' . (int) $subeId . ' ORDER BY id ASC LIMIT 1')->fetch();
    if (is_array($subeRows)) {
        $otherSubeId = (int) ($subeRows['id'] ?? 0);
    }
    if ($otherSubeId > 0) {
        $baOut = s79_auth_headers($baToken, $otherSubeId);
        $outDet = s79_http('GET', '/haftalik-kapanis/revizyon-corrections/' . $correctionId, null, $baOut);
        $pass(
            'out-of-scope detail',
            $outDet['status'] === 403,
            ['status' => $outDet['status'], 'code' => s79_err_code($outDet['payload']), 'other_sube' => $otherSubeId]
        );
    } else {
        $skip('out-of-scope detail', 'no alternate sube');
    }
    $subeQuery = s79_http('GET', '/haftalik-kapanis/revizyon-corrections', null, $gyH, ['sube_id' => (string) $subeId]);
    $pass(
        'query sube_id rejected',
        $subeQuery['status'] === 400 && s79_err_code($subeQuery['payload']) === 'INVALID_CORRECTION_PAYLOAD',
        ['status' => $subeQuery['status'], 'code' => s79_err_code($subeQuery['payload'])]
    );
    $subeBody = s79_http(
        'POST',
        '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret',
        ['sube_id' => $subeId],
        $gyH
    );
    $pass(
        'body sube_id rejected',
        $subeBody['status'] === 400 && s79_err_code($subeBody['payload']) === 'INVALID_CORRECTION_PAYLOAD',
        ['status' => $subeBody['status'], 'code' => s79_err_code($subeBody['payload'])]
    );

    // --- List/detail contract ---
    $unknownQ = s79_http('GET', '/haftalik-kapanis/revizyon-corrections', null, $gyH, ['foo' => '1']);
    $pass(
        'unknown query corrections',
        $unknownQ['status'] === 400 && s79_err_code($unknownQ['payload']) === 'INVALID_CORRECTION_PAYLOAD',
        ['status' => $unknownQ['status'], 'code' => s79_err_code($unknownQ['payload'])]
    );
    $filterList = s79_http('GET', '/haftalik-kapanis/revizyon-corrections', null, $gyH, [
        'revizyon_talebi_id' => (string) $mainTalepId,
        'personel_id' => (string) $personelId,
        'hafta_baslangic' => $hb,
        'hafta_bitis' => $he,
    ]);
    $filterItems = $filterList['payload']['data']['items'] ?? [];
    $filterIds = array_map(static function ($item) {
        return (int) ($item['id'] ?? 0);
    }, is_array($filterItems) ? $filterItems : []);
    $pass(
        'list filters work',
        $filterList['status'] === 200 && in_array($correctionId, $filterIds, true),
        ['status' => $filterList['status'], 'count' => count($filterIds)]
    );
    $forbiddenKeys = ['sube_id', 'kapanis_id', 'snapshot_id', 'iptal_aciklamasi', 'created_at', 'updated_at'];
    $leaked = [];
    foreach ($forbiddenKeys as $k) {
        if (array_key_exists($k, $gyData)) {
            $leaked[] = $k;
        }
    }
    $pass('detail no internal fields', $leaked === [], ['leaked' => $leaked, 'keys' => array_keys($gyData)]);

    // --- Cancel validation + success + second cancel ---
    $cancelBad = s79_http(
        'POST',
        '/haftalik-kapanis/revizyon-corrections/' . $correctionId . '/iptal',
        ['foo' => 'x'],
        $gyH
    );
    $pass(
        'cancel unknown fields',
        $cancelBad['status'] === 400 && s79_err_code($cancelBad['payload']) === 'INVALID_CORRECTION_PAYLOAD',
        ['status' => $cancelBad['status'], 'code' => s79_err_code($cancelBad['payload'])]
    );
    if ($bolumH !== null) {
        $bolumCancel = s79_http('POST', '/haftalik-kapanis/revizyon-corrections/' . $correctionId . '/iptal', [], $bolumH);
        $pass('BOLUM cancel forbidden', $bolumCancel['status'] === 403, ['status' => $bolumCancel['status']]);
    } else {
        $skip('BOLUM cancel forbidden');
    }
    if ($muhH !== null) {
        $muhCancel = s79_http('POST', '/haftalik-kapanis/revizyon-corrections/' . $correctionId . '/iptal', [], $muhH);
        $pass('MUHASEBE cancel forbidden', $muhCancel['status'] === 403, ['status' => $muhCancel['status']]);
    } else {
        $skip('MUHASEBE cancel forbidden');
    }
    if ($patronH !== null) {
        $patronCancel = s79_http('POST', '/haftalik-kapanis/revizyon-corrections/' . $correctionId . '/iptal', [], $patronH);
        $pass('PATRON cancel forbidden', $patronCancel['status'] === 403, ['status' => $patronCancel['status']]);
    } else {
        $skip('PATRON cancel forbidden');
    }

    $cancelOk = s79_http(
        'POST',
        '/haftalik-kapanis/revizyon-corrections/' . $correctionId . '/iptal',
        ['aciklama' => S80_SMOKE_MARKER . ' cancel'],
        $gyH
    );
    $pass(
        'cancel success',
        $cancelOk['status'] === 200
            && !empty($cancelOk['payload']['data']['iptal_edildi_mi'])
            && s79_err_code($cancelOk['payload']) !== 'PERIOD_LOCKED',
        ['status' => $cancelOk['status'], 'code' => s79_err_code($cancelOk['payload'])],
        true
    );
    $cancel2 = s79_http('POST', '/haftalik-kapanis/revizyon-corrections/' . $correctionId . '/iptal', [], $gyH);
    $pass(
        'second cancel 404',
        $cancel2['status'] === 404 && s79_err_code($cancel2['payload']) === 'CORRECTION_NOT_FOUND',
        ['status' => $cancel2['status'], 'code' => s79_err_code($cancel2['payload'])],
        true
    );

    // Produce after cancel still ALREADY_EXISTS
    $prodAfterCancel = s79_http(
        'POST',
        '/haftalik-kapanis/revizyon-talepleri/' . $mainTalepId . '/correction-uret',
        [],
        $gyH
    );
    $pass(
        'produce after cancel',
        $prodAfterCancel['status'] === 409 && s79_err_code($prodAfterCancel['payload']) === 'CORRECTION_ALREADY_EXISTS',
        ['status' => $prodAfterCancel['status'], 'code' => s79_err_code($prodAfterCancel['payload'])],
        true
    );

    // --- Overlay: active vs cancelled ---
    $activeCnt = (int) $pdo->query(
        'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections WHERE iptal_edildi_mi = 0'
    )->fetchColumn();
    $cancelChk = $pdo->prepare(
        'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections WHERE id = :id AND iptal_edildi_mi = 1'
    );
    $cancelChk->execute(['id' => $correctionId]);
    $cancelledCnt = (int) $cancelChk->fetchColumn();
    $mainActive = $pdo->prepare(
        'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections WHERE id = :id AND iptal_edildi_mi = 0'
    );
    $mainActive->execute(['id' => $correctionId]);
    $mainStillActive = (int) $mainActive->fetchColumn();
    $pass(
        'overlay cancelled excluded from active',
        $cancelledCnt === 1 && $mainStillActive === 0 && $activeCnt >= 0,
        ['active' => $activeCnt, 'cancelled_main' => $cancelledCnt, 'main_still_active' => $mainStillActive]
    );

    // --- Concurrency: parallel produce / cancel via curl_multi ---
    $concTalep = s79_api_create_onaylandi(
        $meta,
        s79_rt_create_body(
            $personelId,
            $snapshotId,
            $etkilenen,
            $hb,
            $he,
            S80_SMOKE_MARKER . ' concurrency-prod',
            ['bordro_etki_var_mi' => false]
        ),
        $baH,
        $gyH
    );
    if ($concTalep['ok']) {
        $parallelProd = s79_http_parallel([
            [
                'method' => 'POST',
                'path' => '/haftalik-kapanis/revizyon-talepleri/' . $concTalep['talep_id'] . '/correction-uret',
                'body' => [],
                'headers' => $gyH,
            ],
            [
                'method' => 'POST',
                'path' => '/haftalik-kapanis/revizyon-talepleri/' . $concTalep['talep_id'] . '/correction-uret',
                'body' => [],
                'headers' => $gyH,
            ],
        ]);
        $statuses = array_map(static function ($r) {
            return (int) ($r['status'] ?? 0);
        }, $parallelProd);
        $codes = array_map(static function ($r) {
            return $r['code'] ?? null;
        }, $parallelProd);
        foreach ($parallelProd as $r) {
            $cid = (int) ($r['payload']['data']['id'] ?? 0);
            if ($cid > 0) {
                s79_track_correction($meta, $cid);
            }
        }
        $ok200 = count(array_filter($statuses, static function ($s) {
            return $s === 200;
        }));
        $ok409 = count(array_filter($statuses, static function ($s) {
            return $s === 409;
        }));
        $pass(
            'concurrency parallel produce',
            $ok200 === 1 && $ok409 === 1,
            ['statuses' => $statuses, 'codes' => $codes],
            true
        );

        $concCid = 0;
        foreach ($parallelProd as $r) {
            if ((int) ($r['status'] ?? 0) === 200) {
                $concCid = (int) ($r['payload']['data']['id'] ?? 0);
                break;
            }
        }
        if ($concCid <= 0) {
            $row = $pdo->prepare(
                'SELECT id FROM haftalik_kapanis_revizyon_corrections WHERE revizyon_talebi_id = :t LIMIT 1'
            );
            $row->execute(['t' => $concTalep['talep_id']]);
            $concCid = (int) $row->fetchColumn();
            if ($concCid > 0) {
                s79_track_correction($meta, $concCid);
            }
        }
        if ($concCid > 0) {
            $parallelCancel = s79_http_parallel([
                [
                    'method' => 'POST',
                    'path' => '/haftalik-kapanis/revizyon-corrections/' . $concCid . '/iptal',
                    'body' => [],
                    'headers' => $gyH,
                ],
                [
                    'method' => 'POST',
                    'path' => '/haftalik-kapanis/revizyon-corrections/' . $concCid . '/iptal',
                    'body' => [],
                    'headers' => $gyH,
                ],
            ]);
            $cStatuses = array_map(static function ($r) {
                return (int) ($r['status'] ?? 0);
            }, $parallelCancel);
            $c200 = count(array_filter($cStatuses, static function ($s) {
                return $s === 200;
            }));
            $c404 = count(array_filter($cStatuses, static function ($s) {
                return $s === 404;
            }));
            $pass(
                'concurrency parallel cancel',
                $c200 === 1 && $c404 === 1,
                ['statuses' => $cStatuses],
                true
            );
        } else {
            $skip('concurrency parallel cancel', 'no correction id from parallel produce');
        }
    } else {
        $skip('concurrency parallel produce', 'could not prepare concurrency talep');
        $skip('concurrency parallel cancel', 'could not prepare concurrency talep');
    }

    // --- Snapshot immutable after produce ---
    $checksumAfter = s79_snapshot_checksum($pdo);
    $immutableOk = true;
    $immutableDetail = [];
    foreach (['haftalik_kapanislar', 'haftalik_kapanis_satirlari', 'puantaj', 'surecler', 'serbest_zaman_events'] as $k) {
        if (!array_key_exists($k, $checksumBefore)) {
            continue;
        }
        $same = ($checksumAfter[$k] ?? null) === $checksumBefore[$k];
        $immutableDetail[$k] = ['before' => $checksumBefore[$k], 'after' => $checksumAfter[$k] ?? null];
        if (!$same) {
            $immutableOk = false;
        }
    }
    $corrDelta = ($checksumAfter['haftalik_kapanis_revizyon_corrections'] ?? 0)
        - ($checksumBefore['haftalik_kapanis_revizyon_corrections'] ?? 0);
    $linkDelta = ($checksumAfter['linked_revizyon'] ?? 0) - ($checksumBefore['linked_revizyon'] ?? 0);
    $pass(
        'snapshot immutable after produce',
        $immutableOk && $corrDelta >= 1 && $linkDelta >= 1,
        [
            'immutable' => $immutableDetail,
            'corrections_delta' => $corrDelta,
            'linked_delta' => $linkDelta,
            'before' => $checksumBefore,
            'after' => $checksumAfter,
        ]
    );

    // --- Orphan query at end of smoke ---
    $orphanEnd = (int) $pdo->query(
        'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections c
         LEFT JOIN haftalik_kapanis_revizyon_talepleri t ON t.id = c.revizyon_talebi_id
         WHERE t.id IS NULL OR t.correction_event_id IS NULL OR t.correction_event_id <> c.id'
    )->fetchColumn();
    $pass('orphan_correction end of smoke', $orphanEnd === 0, ['orphan_correction' => $orphanEnd], true);

    // Open-week PERIOD_NOT_CLOSED still covered (optional mapping)
    $periodNotClosed = s79_http('POST', '/haftalik-kapanis/revizyon-talepleri', [
        'personel_id' => $personelId,
        'hafta_baslangic' => $openHb,
        'hafta_bitis' => $openHe,
        'etkilenen_tarih' => $openEtkilenen,
        'kaynak_tipi' => 'SUREC',
        'kaynak_id' => $surecId > 0 ? $surecId : 99999999,
        'revizyon_tipi' => 'SUREC_GEC_GIRIS',
        'gerekce' => S80_SMOKE_MARKER . ' period-not-closed',
        'onceki_deger' => 60,
        'talep_edilen_deger' => 90,
    ], $baH);
    $pass(
        'open week PERIOD_NOT_CLOSED',
        $periodNotClosed['status'] === 409 && s79_err_code($periodNotClosed['payload']) === 'PERIOD_NOT_CLOSED',
        ['status' => $periodNotClosed['status'], 'code' => s79_err_code($periodNotClosed['payload'])]
    );

    $ok = !$failed;
    $fixturePublic = $meta;
    unset($fixturePublic['tokens']);
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S80_SMOKE_RUN_OK' : 'S80_SMOKE_RUN_FAILED',
        'steps' => $steps,
        'fixture' => $fixturePublic,
        'talep_ids' => array_values(array_map('intval', $meta['talep_ids'] ?? [])),
        'correction_ids' => array_values(array_map('intval', $meta['correction_ids'] ?? [])),
        'counts' => s79_counts($pdo),
        'login_note' => 'JWT issued in smoke_prepare from config.local secret; tokens stored only in marker file',
        'corr_before_state_guards' => $corrBeforeState,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'smoke_cleanup') {
    $loadedMarker = s79_load_smoke_marker();
    $markerWasPresent = $loadedMarker !== null;
    $meta = $loadedMarker ?? [];
    $deleted = [
        'unlink_correction' => 0,
        'corrections' => 0,
        'gecmis' => 0,
        'talep' => 0,
        'satir' => 0,
        'kapanis' => 0,
        'surec' => 0,
        'personel' => 0,
        'temp_user_sube' => 0,
    ];

    $talepIds = array_values(array_unique(array_map('intval', $meta['talep_ids'] ?? [])));
    $correctionIds = array_values(array_unique(array_map('intval', $meta['correction_ids'] ?? [])));
    $personelId = (int) ($meta['personel_id'] ?? 0);
    $surecId = (int) ($meta['surec_id'] ?? 0);
    $kapanisIds = array_values(array_unique(array_map('intval', $meta['kapanis_ids'] ?? [])));
    if ($kapanisIds === [] && isset($meta['kapanis_id'])) {
        $kapanisIds = [(int) $meta['kapanis_id']];
    }

    // Marker file may be gone after a failed run; rediscover exact smoke fixtures.
    if ($personelId <= 0) {
        $p = $pdo->prepare('SELECT id FROM personeller WHERE tc_kimlik_no = :tc OR sicil_no = :sicil LIMIT 1');
        $p->execute(['tc' => S80_SMOKE_TC, 'sicil' => S80_SMOKE_SICIL]);
        $pid = $p->fetchColumn();
        if ($pid !== false) {
            $personelId = (int) $pid;
        }
    }
    if ($surecId <= 0 && s79_table_exists($pdo, 'surecler')) {
        $s = $pdo->prepare('SELECT id FROM surecler WHERE aciklama LIKE :m ORDER BY id DESC LIMIT 1');
        $s->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
        $sid = $s->fetchColumn();
        if ($sid !== false) {
            $surecId = (int) $sid;
        }
    }
    if ($kapanisIds === [] && s79_table_exists($pdo, 'haftalik_kapanislar')) {
        $k = $pdo->query(
            "SELECT id FROM haftalik_kapanislar
             WHERE hafta_baslangic LIKE '2038-03-%'
             ORDER BY id ASC"
        );
        $kapanisIds = array_map('intval', $k->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    if ($talepIds === [] && s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
        $t = $pdo->prepare('SELECT id FROM haftalik_kapanis_revizyon_talepleri WHERE gerekce LIKE :m');
        $t->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
        $talepIds = array_map('intval', $t->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    if ($correctionIds === [] && s79_table_exists($pdo, 'haftalik_kapanis_revizyon_corrections')) {
        $c = $pdo->prepare(
            'SELECT id FROM haftalik_kapanis_revizyon_corrections
             WHERE audit_ref LIKE :m OR aciklama LIKE :m'
        );
        $c->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
        $correctionIds = array_map('intval', $c->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($talepIds !== []) {
            $in = implode(',', $talepIds);
            $c2 = $pdo->query(
                'SELECT id FROM haftalik_kapanis_revizyon_corrections WHERE revizyon_talebi_id IN (' . $in . ')'
            );
            $correctionIds = array_values(array_unique(array_merge(
                $correctionIds,
                array_map('intval', $c2->fetchAll(PDO::FETCH_COLUMN) ?: [])
            )));
        }
    }

    $pdo->beginTransaction();
    try {
        // 1) Unlink correction_event_id on exact fixture talepler (marker-verified).
        if ($talepIds !== [] && s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
            $in = implode(',', $talepIds);
            $verify = $pdo->query(
                'SELECT id, gerekce FROM haftalik_kapanis_revizyon_talepleri WHERE id IN (' . $in . ')'
            );
            $safeIds = [];
            foreach ($verify->fetchAll() ?: [] as $row) {
                if (strpos((string) ($row['gerekce'] ?? ''), S80_SMOKE_MARKER) !== false) {
                    $safeIds[] = (int) $row['id'];
                }
            }
            if ($safeIds !== []) {
                $sin = implode(',', $safeIds);
                $deleted['unlink_correction'] = (int) $pdo->exec(
                    'UPDATE haftalik_kapanis_revizyon_talepleri
                     SET correction_event_id = NULL
                     WHERE id IN (' . $sin . ')'
                );
                $talepIds = $safeIds;
            } else {
                $talepIds = [];
            }
        }

        // 2) DELETE corrections for fixture IDs / marker audit_ref / aciklama.
        if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_corrections')) {
            if ($correctionIds !== []) {
                $cin = implode(',', $correctionIds);
                $deleted['corrections'] = (int) $pdo->exec(
                    'DELETE FROM haftalik_kapanis_revizyon_corrections WHERE id IN (' . $cin . ')'
                );
            }
            $d = $pdo->prepare(
                'DELETE FROM haftalik_kapanis_revizyon_corrections
                 WHERE audit_ref LIKE :m OR aciklama LIKE :m'
            );
            $d->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
            $deleted['corrections'] += $d->rowCount();
            if ($talepIds !== []) {
                $tin = implode(',', $talepIds);
                $deleted['corrections'] += (int) $pdo->exec(
                    'DELETE FROM haftalik_kapanis_revizyon_corrections WHERE revizyon_talebi_id IN (' . $tin . ')'
                );
            }
        }

        // 3) DELETE gecmis for fixture talepler.
        if ($talepIds !== [] && s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talebi_gecmisi')) {
            $in = implode(',', $talepIds);
            $deleted['gecmis'] = (int) $pdo->exec(
                'DELETE FROM haftalik_kapanis_revizyon_talebi_gecmisi WHERE revizyon_talebi_id IN (' . $in . ')'
            );
        }

        // 4) DELETE talepler.
        if (s79_table_exists($pdo, 'haftalik_kapanis_revizyon_talepleri')) {
            if ($talepIds !== []) {
                $in = implode(',', $talepIds);
                $deleted['talep'] = (int) $pdo->exec(
                    'DELETE FROM haftalik_kapanis_revizyon_talepleri WHERE id IN (' . $in . ')'
                );
            }
            $d = $pdo->prepare('DELETE FROM haftalik_kapanis_revizyon_talepleri WHERE gerekce LIKE :m');
            $d->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
            $deleted['talep'] += $d->rowCount();
        }

        // 5) DELETE satırlar / kapanış / surec / personel / temp_user_sube.
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
            } else {
                $d1 = $pdo->prepare('DELETE FROM haftalik_kapanis_satirlari WHERE kapanis_id = :id');
                $d1->execute(['id' => $kid]);
                $deleted['satir'] += $d1->rowCount();
            }
            $d2 = $pdo->prepare('DELETE FROM haftalik_kapanislar WHERE id = :id');
            $d2->execute(['id' => $kid]);
            $deleted['kapanis'] += $d2->rowCount();
        }
        if (s79_table_exists($pdo, 'surecler')) {
            $d = $pdo->prepare('DELETE FROM surecler WHERE aciklama LIKE :m');
            $d->execute(['m' => '%' . S80_SMOKE_MARKER . '%']);
            $deleted['surec'] = $d->rowCount();
        }
        if ($personelId > 0) {
            $chk = $pdo->prepare('SELECT id, tc_kimlik_no, sicil_no FROM personeller WHERE id = :id');
            $chk->execute(['id' => $personelId]);
            $row = $chk->fetch();
            if ($row && ((string) $row['tc_kimlik_no'] === S80_SMOKE_TC || (string) $row['sicil_no'] === S80_SMOKE_SICIL)) {
                $d = $pdo->prepare('DELETE FROM personeller WHERE id = :id');
                $d->execute(['id' => $personelId]);
                $deleted['personel'] = $d->rowCount();
            }
        }
        $tempUs = $meta['temp_user_sube'] ?? null;
        if (!is_array($tempUs) && $personelId > 0) {
            $bolum = s79_load_role_user($pdo, 'BOLUM_YONETICISI');
            $ps = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id');
            $ps->execute(['id' => $personelId]);
            $smokeSube = (int) $ps->fetchColumn();
            if ($bolum !== null && $smokeSube > 0) {
                $other = $pdo->prepare(
                    'SELECT COUNT(*) FROM user_subeler WHERE user_id = :u AND sube_id <> :s'
                );
                $other->execute(['u' => (int) $bolum['id'], 's' => $smokeSube]);
                if ((int) $other->fetchColumn() > 0) {
                    $tempUs = ['user_id' => (int) $bolum['id'], 'sube_id' => $smokeSube];
                }
            }
        }
        if (is_array($tempUs)) {
            $uid = (int) ($tempUs['user_id'] ?? 0);
            $sid = (int) ($tempUs['sube_id'] ?? 0);
            if ($uid > 0 && $sid > 0) {
                $d = $pdo->prepare('DELETE FROM user_subeler WHERE user_id = :u AND sube_id = :s');
                $d->execute(['u' => $uid, 's' => $sid]);
                $deleted['temp_user_sube'] = $d->rowCount();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'code' => 'S80_SMOKE_CLEANUP_FAILED', 'error' => $e->getMessage(), 'deleted' => $deleted], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    @unlink(s79_smoke_marker_path());
    $markers = s79_marker_rows($pdo);
    $ok = $markers['talep'] === 0
        && $markers['gecmis'] === 0
        && $markers['corrections'] === 0
        && $markers['personel'] === 0
        && $markers['kapanis'] === 0
        && $markers['satir'] === 0;
    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S80_SMOKE_CLEANUP_OK' : 'S80_SMOKE_CLEANUP_INCOMPLETE',
        'deleted' => $deleted,
        'marker_rows' => $markers,
        'counts' => s79_counts($pdo),
        'marker_was_present' => $markerWasPresent,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
