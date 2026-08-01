<?php
/**
 * ONE-SHOT S87 live migrate for 043_payroll_compliance_critical_gaps.sql only.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * No seed, no policy write, no puantaj/backfill. UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S87_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_S87_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_S87_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'identity';
$expected043 = '1a602a6b17db05122cf98ffac69f7035d2aedf1e0c07b8c5d55cd0bcf02056b8';
$migrationFile = '043_payroll_compliance_critical_gaps.sql';

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

function s87_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
        'charset' => (string) $pdo->query('SELECT @@character_set_database')->fetchColumn(),
        'collation' => (string) $pdo->query('SELECT @@collation_database')->fetchColumn(),
    ];
}

function s87_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s87_count(PDO $pdo, string $table): int
{
    if (!s87_table_exists($pdo, $table)) {
        return -1;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
}

function s87_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s87_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function s87_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function s87_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"
    );
    $stmt->execute(['t' => $table, 'i' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

/** @return array<int, array<string, mixed>> */
function s87_fk_rules(PDO $pdo, string $table, string $constraintName): array
{
    $stmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE, REFERENCED_TABLE_NAME
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND CONSTRAINT_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $constraintName]);

    return $stmt->fetchAll();
}

/** @return array<int, string> */
function s87_check_constraints(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND CONSTRAINT_TYPE = 'CHECK'
         ORDER BY CONSTRAINT_NAME"
    );
    $stmt->execute(['t' => $table]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** @return list<string> */
function s87_projection_columns(): array
{
    return [
        'talep_tarihi',
        'imzali_talep_belge_id',
        'sisteme_giren_kullanici_id',
        'sisteme_giris_zamani',
    ];
}

/** @return array<string, bool> */
function s87_audit_columns(): array
{
    return [
        'imzali_talep_belge_id' => true,
        'talep_tarihi' => true,
    ];
}

/** @return array<string, mixed> */
function s87_counts(PDO $pdo): array
{
    $counts = [
        'personeller' => s87_count($pdo, 'personeller'),
        'gunluk_puantaj' => s87_count($pdo, 'gunluk_puantaj'),
        'fazla_calisma_odeme_tercihleri' => s87_count($pdo, 'fazla_calisma_odeme_tercihleri'),
        'fazla_calisma_odeme_tercihi_audit' => s87_count($pdo, 'fazla_calisma_odeme_tercihi_audit'),
        'puantaj_aylik_muhur_satirlari' => s87_count($pdo, 'puantaj_aylik_muhur_satirlari'),
        'puantaj_aylik_muhurler' => s87_count($pdo, 'puantaj_aylik_muhurler'),
        'maas_hesaplama_snapshotlari' => s87_count($pdo, 'maas_hesaplama_snapshotlari'),
    ];
    if (s87_table_exists($pdo, 'yillik_fazla_calisma_kilitleri')) {
        $counts['yillik_fazla_calisma_kilitleri'] = s87_count($pdo, 'yillik_fazla_calisma_kilitleri');
    } else {
        $counts['yillik_fazla_calisma_kilitleri'] = null;
    }

    return $counts;
}

/** @return array<string, mixed> */
function s87_schema_probe(PDO $pdo): array
{
    $fcotCols = [];
    foreach (s87_projection_columns() as $col) {
        $fcotCols[$col] = s87_column_exists($pdo, 'fazla_calisma_odeme_tercihleri', $col);
    }
    $auditCols = [];
    foreach (array_keys(s87_audit_columns()) as $col) {
        $auditCols[$col] = s87_column_exists($pdo, 'fazla_calisma_odeme_tercihi_audit', $col);
    }

    $fks = [
        'fk_fcot_imzali_belge' => count(s87_fk_rules($pdo, 'fazla_calisma_odeme_tercihleri', 'fk_fcot_imzali_belge')) === 1,
        'fk_fcot_sisteme_giren' => count(s87_fk_rules($pdo, 'fazla_calisma_odeme_tercihleri', 'fk_fcot_sisteme_giren')) === 1,
        'fk_fcota_imzali_belge' => count(s87_fk_rules($pdo, 'fazla_calisma_odeme_tercihi_audit', 'fk_fcota_imzali_belge')) === 1,
        'fk_yfck_personel' => count(s87_fk_rules($pdo, 'yillik_fazla_calisma_kilitleri', 'fk_yfck_personel')) === 1,
        'fk_yfck_locked_by' => count(s87_fk_rules($pdo, 'yillik_fazla_calisma_kilitleri', 'fk_yfck_locked_by')) === 1,
    ];
    $indexes = [
        'idx_fcot_imzali_belge' => s87_index_exists($pdo, 'fazla_calisma_odeme_tercihleri', 'idx_fcot_imzali_belge'),
        'uq_yfck_personel_yil' => s87_index_exists($pdo, 'yillik_fazla_calisma_kilitleri', 'uq_yfck_personel_yil'),
    ];
    $hasLockTable = s87_table_exists($pdo, 'yillik_fazla_calisma_kilitleri');
    $allFcot = !in_array(false, $fcotCols, true);
    $anyFcot = in_array(true, $fcotCols, true);
    $allAudit = !in_array(false, $auditCols, true);
    $anyAudit = in_array(true, $auditCols, true);
    $coreFks = $fks['fk_fcot_imzali_belge'] && $fks['fk_fcot_sisteme_giren'] && $fks['fk_fcota_imzali_belge'];
    $lockOk = $hasLockTable && $indexes['uq_yfck_personel_yil'] && $fks['fk_yfck_personel'] && $fks['fk_yfck_locked_by'];
    $already = $allFcot && $allAudit && $coreFks && $indexes['idx_fcot_imzali_belge'] && $lockOk;
    $partial = (!$already) && (
        $anyFcot || $anyAudit || $hasLockTable
        || $fks['fk_fcot_imzali_belge'] || $fks['fk_fcot_sisteme_giren'] || $fks['fk_fcota_imzali_belge']
        || $indexes['idx_fcot_imzali_belge']
    );

    return [
        'fcot_columns' => $fcotCols,
        'audit_columns' => $auditCols,
        'foreign_keys' => $fks,
        'indexes' => $indexes,
        'yillik_fazla_calisma_kilitleri_exists' => $hasLockTable,
        'already_applied' => $already,
        'partial_043_trace' => $partial,
        'fresh_apply_ready' => !$anyFcot && !$anyAudit && !$hasLockTable
            && s87_table_exists($pdo, 'fazla_calisma_odeme_tercihleri')
            && s87_table_exists($pdo, 'fazla_calisma_odeme_tercihi_audit')
            && s87_table_exists($pdo, 'personeller')
            && s87_table_exists($pdo, 'users')
            && s87_table_exists($pdo, 'surecler'),
    ];
}

/** @return array<string, mixed> */
function s87_preflight(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    $probe = s87_schema_probe($pdo);

    return array_merge([
        'table_count' => count($tables),
        'counts' => s87_counts($pdo),
        'fazla_calisma_odeme_tercihleri_exists' => s87_table_exists($pdo, 'fazla_calisma_odeme_tercihleri'),
        'fazla_calisma_odeme_tercihi_audit_exists' => s87_table_exists($pdo, 'fazla_calisma_odeme_tercihi_audit'),
    ], $probe);
}

function s87_php_sql_dump(PDO $pdo, string $dbName): string
{
    $out = [];
    $out[] = '-- S87 PHP SQL dump (shared-host fallback; restoreable)';
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
        $create = $pdo->query('SHOW CREATE TABLE ' . s87_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . s87_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . s87_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            continue;
        }
        $cols = array_map('s87_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = s87_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . s87_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($triggers as $triggerName) {
        $row = $pdo->query('SHOW CREATE TRIGGER ' . s87_quote_ident((string) $triggerName))->fetch();
        $sql = (string) ($row['SQL Original Statement'] ?? $row['Create Trigger'] ?? '');
        if ($sql !== '') {
            $out[] = 'DROP TRIGGER IF EXISTS ' . s87_quote_ident((string) $triggerName) . ';';
            $out[] = $sql . ';';
            $out[] = '';
        }
    }

    $routines = $pdo->query(
        "SELECT ROUTINE_NAME, ROUTINE_TYPE FROM information_schema.ROUTINES
         WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME"
    )->fetchAll();
    foreach ($routines as $routine) {
        $rName = (string) ($routine['ROUTINE_NAME'] ?? '');
        $rType = strtoupper((string) ($routine['ROUTINE_TYPE'] ?? ''));
        if ($rName === '' || !in_array($rType, ['PROCEDURE', 'FUNCTION'], true)) {
            continue;
        }
        $show = $rType === 'FUNCTION' ? 'SHOW CREATE FUNCTION ' : 'SHOW CREATE PROCEDURE ';
        $row = $pdo->query($show . s87_quote_ident($rName))->fetch();
        $key = $rType === 'FUNCTION' ? 'Create Function' : 'Create Procedure';
        $sql = (string) ($row[$key] ?? '');
        if ($sql !== '') {
            $out[] = 'DROP ' . $rType . ' IF EXISTS ' . s87_quote_ident($rName) . ';';
            $out[] = $sql . ';';
            $out[] = '';
        }
    }

    $events = $pdo->query(
        "SELECT EVENT_NAME FROM information_schema.EVENTS
         WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($events as $eventName) {
        $row = $pdo->query('SHOW CREATE EVENT ' . s87_quote_ident((string) $eventName))->fetch();
        $sql = (string) ($row['Create Event'] ?? '');
        if ($sql !== '') {
            $out[] = 'DROP EVENT IF EXISTS ' . s87_quote_ident((string) $eventName) . ';';
            $out[] = $sql . ';';
            $out[] = '';
        }
    }

    $out[] = 'COMMIT;';
    $out[] = 'SET UNIQUE_CHECKS=1;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '';

    return implode("\n", $out);
}

function s87_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd_His');
    $path = __DIR__ . '/karmotor_medisa_pre_043_' . $stamp . '.sql';

    return $path;
}

/** @return array<int, string> */
function s87_split_sql(string $sql): array
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

function s87_apply_043(PDO $pdo, string $file, string $expectedSha): array
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
    foreach (s87_split_sql($sql) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }

    return ['file' => basename($file), 'sha256' => $sha, 'statements' => $count];
}

/** @return array<string, mixed> */
function s87_postcheck(PDO $pdo, array $beforeCounts): array
{
    $probe = s87_schema_probe($pdo);
    $after = s87_counts($pdo);

    $countsUnchanged =
        ($beforeCounts['personeller'] ?? null) === $after['personeller']
        && ($beforeCounts['gunluk_puantaj'] ?? null) === $after['gunluk_puantaj']
        && ($beforeCounts['fazla_calisma_odeme_tercihleri'] ?? null) === $after['fazla_calisma_odeme_tercihleri']
        && ($beforeCounts['fazla_calisma_odeme_tercihi_audit'] ?? null) === $after['fazla_calisma_odeme_tercihi_audit']
        && ($beforeCounts['puantaj_aylik_muhur_satirlari'] ?? null) === $after['puantaj_aylik_muhur_satirlari']
        && ($beforeCounts['puantaj_aylik_muhurler'] ?? null) === $after['puantaj_aylik_muhurler']
        && ($beforeCounts['maas_hesaplama_snapshotlari'] ?? null) === $after['maas_hesaplama_snapshotlari'];

    $ok = !empty($probe['already_applied']) && $countsUnchanged;

    return [
        'ok' => $ok,
        'schema' => $probe,
        'row_counts' => $after,
        'before_counts' => $beforeCounts,
        'counts_unchanged' => $countsUnchanged,
    ];
}

if ($action === 'identity') {
    $identity = s87_identity($pdo);
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
    $identity = s87_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = s87_preflight($pdo);
    $code = 'S87_PREFLIGHT_OK';
    if (empty($pre['fazla_calisma_odeme_tercihleri_exists']) || empty($pre['fazla_calisma_odeme_tercihi_audit_exists'])) {
        $code = 'S87_PREFLIGHT_BASE_TABLES_MISSING';
    } elseif ($pre['partial_043_trace']) {
        $code = 'S87_PREFLIGHT_PARTIAL_043_TRACE';
    } elseif ($pre['already_applied']) {
        $code = 'S87_ALREADY_APPLIED';
    }

    $ok = in_array($code, ['S87_PREFLIGHT_OK', 'S87_ALREADY_APPLIED'], true);
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
    $identity = s87_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $backupPath = s87_backup_path();
    $meta = [
        'method' => null,
        'file' => basename($backupPath),
        'bytes' => 0,
        'sha256' => null,
        'table_count' => 0,
        'insert_block_count' => 0,
        'contains_create_table' => false,
        'contains_insert' => false,
        'contains_personeller_insert' => false,
        'contains_gunluk_puantaj_insert' => false,
        'contains_commit' => false,
        'trigger_count' => 0,
        'routine_count' => 0,
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
        $sql = s87_php_sql_dump($pdo, $name);
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
    $meta['contains_personeller_insert'] = (bool) preg_match('/INSERT INTO [`"]?personeller[`"]?/i', $contents);
    $meta['contains_gunluk_puantaj_insert'] = (bool) preg_match('/INSERT INTO [`"]?gunluk_puantaj[`"]?/i', $contents);
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;
    $meta['insert_block_count'] = preg_match_all('/INSERT INTO/i', $contents);
    $meta['table_count'] = preg_match_all('/CREATE TABLE/i', $contents);
    $meta['trigger_count'] = preg_match_all('/CREATE TRIGGER/i', $contents);
    $meta['routine_count'] = preg_match_all('/CREATE (PROCEDURE|FUNCTION)/i', $contents);

    file_put_contents(__DIR__ . '/s87_latest_backup_path.txt', basename($backupPath));

    $ok =
        $meta['bytes'] > 0
        && $meta['contains_create_table']
        && $meta['contains_insert']
        && $meta['contains_personeller_insert']
        && $meta['contains_gunluk_puantaj_insert']
        && $meta['contains_commit'];

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S87_BACKUP_OK' : 'S87_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'identity' => $identity,
        'preflight' => s87_preflight($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s87_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '') {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_043_*.sql') ?: [];
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
    $identity = s87_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = s87_preflight($pdo);
    if (empty($pre['fazla_calisma_odeme_tercihleri_exists']) || empty($pre['fazla_calisma_odeme_tercihi_audit_exists'])) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S87_PREFLIGHT_BASE_TABLES_MISSING', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['partial_043_trace']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S87_PREFLIGHT_PARTIAL_043_TRACE', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['already_applied']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S87_ALREADY_APPLIED', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $before = $pre['counts'];
    $path = __DIR__ . '/' . $migrationFile;
    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        $applied = s87_apply_043($pdo, $path, $expected043);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S87_MIGRATE_FAILED',
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $post = s87_postcheck($pdo, $before);
    echo json_encode([
        'ok' => $post['ok'],
        'code' => $post['ok'] ? 'S87_MIGRATE_OK' : 'S87_MIGRATE_POSTCHECK_FAILED',
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
        'personeller' => isset($_GET['before_personeller']) ? (int) $_GET['before_personeller'] : null,
        'gunluk_puantaj' => isset($_GET['before_gunluk_puantaj']) ? (int) $_GET['before_gunluk_puantaj'] : null,
        'puantaj_aylik_muhur_satirlari' => isset($_GET['before_muhur_satirlari']) ? (int) $_GET['before_muhur_satirlari'] : null,
    ];
    $check = s87_postcheck($pdo, $before);
    echo json_encode([
        'ok' => $check['ok'],
        'code' => $check['ok'] ? 'S87_POSTCHECK_OK' : 'S87_POSTCHECK_FAILED',
        'identity' => s87_identity($pdo),
        'postcheck' => $check,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
