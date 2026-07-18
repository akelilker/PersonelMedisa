<?php
/**
 * ONE-SHOT S78-C2-R3 live migrate for 026_zimmetler.sql.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S78C2_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_S78C2_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_S78C2_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
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

function s78c2_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_host' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s78c2_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE :t');
    $stmt->execute(['t' => $table]);
    return (bool) $stmt->fetchColumn();
}

function s78c2_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s78c2_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function s78c2_php_sql_dump(PDO $pdo, string $dbName): string
{
    $out = [];
    $out[] = '-- S78-C2-R3 PHP SQL dump (shared-host fallback)';
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
        $create = $pdo->query('SHOW CREATE TABLE ' . s78c2_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . s78c2_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . s78c2_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            continue;
        }
        $cols = array_map('s78c2_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = s78c2_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . s78c2_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $out[] = 'COMMIT;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $out[] = '';

    return implode("\n", $out);
}

function s78c2_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd-His');
    $path = __DIR__ . '/karmotor_medisa_pre_026_' . $stamp . '.sql';
    return $path;
}

function s78c2_personel_id_column(PDO $pdo): ?array
{
    foreach ($pdo->query('SHOW FULL COLUMNS FROM personeller')->fetchAll() as $col) {
        if (($col['Field'] ?? '') === 'id') {
            return $col;
        }
    }
    return null;
}

function s78c2_zimmet_schema(PDO $pdo): array
{
    if (!s78c2_table_exists($pdo, 'zimmetler')) {
        return [
            'exists' => false,
            'create_table' => null,
            'columns' => [],
            'indexes' => [],
            'fks' => [],
        ];
    }

    $create = $pdo->query('SHOW CREATE TABLE zimmetler')->fetch();
    $columns = $pdo->query('SHOW FULL COLUMNS FROM zimmetler')->fetchAll();
    $indexes = $pdo->query('SHOW INDEX FROM zimmetler')->fetchAll();
    $fkStmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = 'zimmetler'"
    );
    $fkStmt->execute();
    $fks = $fkStmt->fetchAll();

    return [
        'exists' => true,
        'create_table' => $create['Create Table'] ?? null,
        'columns' => $columns,
        'indexes' => $indexes,
        'fks' => $fks,
    ];
}

function s78c2_expected_columns(): array
{
    return [
        'id', 'personel_id', 'urun_turu', 'teslim_tarihi', 'teslim_eden', 'aciklama',
        'teslim_durumu', 'zimmet_durumu', 'iade_tarihi', 'created_at', 'updated_at',
    ];
}

function s78c2_normalize_type(string $type): string
{
    $type = strtolower(trim($type));
    $type = preg_replace('/^int\(\d+\)/', 'int', $type) ?? $type;
    $type = preg_replace('/\s+/', ' ', $type) ?? $type;
    return $type;
}

function s78c2_schema_matches_026(array $schema): array
{
    if (!$schema['exists']) {
        return ['ok' => false, 'reason' => 'missing'];
    }

    $byField = [];
    foreach ($schema['columns'] as $col) {
        $byField[(string) ($col['Field'] ?? '')] = $col;
    }
    foreach (s78c2_expected_columns() as $field) {
        if (!isset($byField[$field])) {
            return ['ok' => false, 'reason' => 'missing_column_' . $field];
        }
    }
    if (isset($byField['sube_id'])) {
        return ['ok' => false, 'reason' => 'unexpected_sube_id'];
    }

    $checks = [
        ['id', 'int unsigned', false, null],
        ['personel_id', 'int unsigned', false, null],
        ['urun_turu', 'varchar(32)', false, null],
        ['teslim_tarihi', 'date', false, null],
        ['teslim_eden', 'varchar(120)', false, null],
        ['aciklama', 'text', true, null],
        ['teslim_durumu', 'varchar(32)', false, null],
        ['zimmet_durumu', 'varchar(32)', false, 'AKTIF'],
        ['iade_tarihi', 'date', true, null],
    ];
    foreach ($checks as [$field, $typeNeedle, $nullable, $default]) {
        $col = $byField[$field];
        $type = s78c2_normalize_type((string) ($col['Type'] ?? ''));
        if (strpos($type, $typeNeedle) === false) {
            return ['ok' => false, 'reason' => 'type_' . $field];
        }
        $null = strtoupper((string) ($col['Null'] ?? ''));
        if ($nullable && $null !== 'YES') {
            return ['ok' => false, 'reason' => 'null_' . $field];
        }
        if (!$nullable && $null !== 'NO') {
            return ['ok' => false, 'reason' => 'notnull_' . $field];
        }
        if ($default !== null && (string) ($col['Default'] ?? '') !== $default) {
            return ['ok' => false, 'reason' => 'default_' . $field];
        }
    }

    $indexNames = [];
    foreach ($schema['indexes'] as $idx) {
        $indexNames[(string) ($idx['Key_name'] ?? '')] = true;
    }
    foreach (['PRIMARY', 'idx_zimmetler_personel', 'idx_zimmetler_personel_durum'] as $need) {
        if (!isset($indexNames[$need])) {
            return ['ok' => false, 'reason' => 'index_' . $need];
        }
    }

    $fkOk = false;
    foreach ($schema['fks'] as $fk) {
        if ((string) ($fk['CONSTRAINT_NAME'] ?? '') !== 'fk_zimmetler_personel') {
            continue;
        }
        $rule = strtoupper((string) ($fk['DELETE_RULE'] ?? ''));
        if (in_array($rule, ['RESTRICT', 'NO ACTION'], true)) {
            $fkOk = true;
        }
    }
    if (!$fkOk) {
        return ['ok' => false, 'reason' => 'fk'];
    }

    $create = (string) ($schema['create_table'] ?? '');
    if (stripos($create, 'ON DELETE CASCADE') !== false) {
        return ['ok' => false, 'reason' => 'cascade'];
    }
    if (stripos($create, 'ON DELETE RESTRICT') === false && stripos($create, 'NO ACTION') === false) {
        // Some SHOW CREATE omit explicit RESTRICT; FK table already checked.
    }

    return ['ok' => true, 'reason' => 'match'];
}

function s78c2_counts(PDO $pdo): array
{
    $personel = (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
    $aktif = (int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE aktif_durum = 'AKTIF'")->fetchColumn();
    $pasif = (int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE aktif_durum = 'PASIF'")->fetchColumn();
    $zimmet = s78c2_table_exists($pdo, 'zimmetler')
        ? (int) $pdo->query('SELECT COUNT(*) FROM zimmetler')->fetchColumn()
        : null;

    return [
        'personel_count' => $personel,
        'aktif_personel' => $aktif,
        'pasif_personel' => $pasif,
        'zimmet_count' => $zimmet,
    ];
}

function s78c2_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'identity') {
    $identity = s78c2_identity($pdo);
    $ok = $identity['aktif_veritabani'] === 'karmotor_medisa';
    s78c2_json([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'S78_C2_R3_BLOCKED_DB_IDENTITY',
        'identity' => $identity,
        'expected_db' => 'karmotor_medisa',
        'config_db_name' => $name,
    ], $ok ? 200 : 409);
}

if ($action === 'preflight') {
    $identity = s78c2_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        s78c2_json([
            'ok' => false,
            'code' => 'S78_C2_R3_BLOCKED_DB_IDENTITY',
            'identity' => $identity,
        ], 409);
    }

    if (!s78c2_table_exists($pdo, 'personeller')) {
        s78c2_json([
            'ok' => false,
            'code' => 'S78_C2_R3_BLOCKED_PARENT_SCHEMA',
            'error' => 'personeller_missing',
            'identity' => $identity,
        ], 409);
    }

    $idCol = s78c2_personel_id_column($pdo);
    $idType = strtolower((string) ($idCol['Type'] ?? ''));
    $parentOk = $idCol !== null && preg_match('/^int(\(\d+\))?\s+unsigned$/i', $idType) === 1;
    $engineRow = $pdo->query(
        "SELECT ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personeller'"
    )->fetch();
    $engine = strtoupper((string) ($engineRow['ENGINE'] ?? ''));
    if (!$parentOk || $engine !== 'INNODB') {
        s78c2_json([
            'ok' => false,
            'code' => 'S78_C2_R3_BLOCKED_PARENT_SCHEMA',
            'personel_id_column' => $idCol,
            'engine' => $engine,
            'identity' => $identity,
        ], 409);
    }

    $schema = s78c2_zimmet_schema($pdo);
    $counts = s78c2_counts($pdo);
    $already = false;
    $code = 'S78_C2_PREFLIGHT_OK';

    if ($schema['exists']) {
        $match = s78c2_schema_matches_026($schema);
        if ($match['ok']) {
            $already = true;
            $code = 'S78_C2_ALREADY_APPLIED';
        } else {
            s78c2_json([
                'ok' => false,
                'code' => 'S78_C2_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
                'drift_reason' => $match['reason'],
                'schema' => $schema,
                'counts' => $counts,
                'identity' => $identity,
                'already_applied' => false,
            ], 409);
        }
    }

    s78c2_json([
        'ok' => true,
        'code' => $code,
        'identity' => $identity,
        'already_applied' => $already,
        'parent' => [
            'id_column' => $idCol,
            'engine' => $engine,
            'create_table' => ($pdo->query('SHOW CREATE TABLE personeller')->fetch()['Create Table'] ?? null),
        ],
        'schema' => $schema,
        'counts' => $counts,
    ]);
}

if ($action === 'backup') {
    $identity = s78c2_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        s78c2_json(['ok' => false, 'code' => 'S78_C2_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], 409);
    }

    $backupPath = s78c2_backup_path();
    $meta = [
        'method' => null,
        'file' => basename($backupPath),
        'bytes' => 0,
        'sha256' => null,
        'path' => $backupPath,
        'contains_create_personeller' => false,
        'contains_insert_personeller' => false,
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
        $sql = s78c2_php_sql_dump($pdo, $name);
        if ($sql === '' || strlen($sql) < 100) {
            s78c2_json(['ok' => false, 'error' => 'BACKUP_EMPTY', 'code' => 'S78_C2_R3_BLOCKED_BACKUP'], 500);
        }
        file_put_contents($backupPath, $sql);
        $meta['method'] = 'php_sql_dump';
    }

    if (!is_file($backupPath) || filesize($backupPath) <= 0) {
        s78c2_json(['ok' => false, 'error' => 'BACKUP_FILE_MISSING', 'code' => 'S78_C2_R3_BLOCKED_BACKUP'], 500);
    }

    $contents = (string) file_get_contents($backupPath);
    $meta['bytes'] = filesize($backupPath);
    $meta['sha256'] = hash_file('sha256', $backupPath);
    $meta['contains_create_personeller'] = stripos($contents, 'CREATE TABLE') !== false && stripos($contents, 'personeller') !== false;
    $meta['contains_insert_personeller'] = stripos($contents, 'INSERT INTO') !== false && stripos($contents, 'personeller') !== false;
    $meta['contains_commit'] = stripos($contents, 'COMMIT') !== false || stripos($contents, '-- Dump completed') !== false;

    $ok = $meta['bytes'] > 0 && $meta['contains_create_personeller'] && $meta['contains_insert_personeller'] && $meta['contains_commit'];
    file_put_contents(__DIR__ . '/s78c2_latest_backup_path.txt', basename($backupPath));

    s78c2_json([
        'ok' => $ok,
        'code' => $ok ? 'S78_C2_BACKUP_OK' : 'S78_C2_R3_BLOCKED_BACKUP',
        'backup' => $meta,
        'identity' => $identity,
        'counts' => s78c2_counts($pdo),
    ], $ok ? 200 : 500);
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s78c2_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_026_*.sql') ?: [];
        rsort($matches);
        $backupPath = $matches[0] ?? '';
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        s78c2_json(['ok' => false, 'error' => 'BACKUP_NOT_FOUND'], 404);
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($backupPath) . '"');
    header('Content-Length: ' . (string) filesize($backupPath));
    readfile($backupPath);
    exit;
}

if ($action === 'migrate') {
    $identity = s78c2_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        s78c2_json(['ok' => false, 'code' => 'S78_C2_R3_BLOCKED_DB_IDENTITY', 'identity' => $identity], 409);
    }

    $before = s78c2_counts($pdo);
    $schema = s78c2_zimmet_schema($pdo);
    if ($schema['exists']) {
        $match = s78c2_schema_matches_026($schema);
        if ($match['ok']) {
            s78c2_json([
                'ok' => true,
                'code' => 'S78_C2_ALREADY_APPLIED',
                'skipped_apply' => true,
                'before' => $before,
                'after' => $before,
                'schema' => $schema,
                'identity' => $identity,
            ]);
        }
        s78c2_json([
            'ok' => false,
            'code' => 'S78_C2_R3_BLOCKED_EXISTING_SCHEMA_DRIFT',
            'drift_reason' => $match['reason'],
            'schema' => $schema,
            'identity' => $identity,
        ], 409);
    }

    $file = '026_zimmetler.sql';
    $path = __DIR__ . '/' . $file;
    if (!is_file($path)) {
        s78c2_json(['ok' => false, 'error' => 'MIGRATION_FILE_MISSING', 'file' => $file, 'code' => 'S78_C2_R3_BLOCKED_MIGRATION_APPLY'], 500);
    }
    $sql = file_get_contents($path);
    if ($sql === false || trim($sql) === '') {
        s78c2_json(['ok' => false, 'error' => 'MIGRATION_READ_FAILED', 'code' => 'S78_C2_R3_BLOCKED_MIGRATION_APPLY'], 500);
    }

    // Safety: refuse destructive statements. Do not flag ON UPDATE CURRENT_TIMESTAMP / ON DELETE RESTRICT.
    if (preg_match('/\b(DROP\s+TABLE|DELETE\s+FROM|TRUNCATE\s+TABLE|\bUPDATE\s+[A-Za-z0-9_\x60]+\s+SET\b)/i', $sql)) {
        s78c2_json(['ok' => false, 'error' => 'MIGRATION_UNSAFE_KEYWORDS', 'code' => 'S78_C2_R3_BLOCKED_MIGRATION_DRIFT'], 500);
    }
    if (!preg_match('/CREATE\s+TABLE\s+zimmetler\b/i', $sql)) {
        s78c2_json(['ok' => false, 'error' => 'MIGRATION_CREATE_SHAPE', 'code' => 'S78_C2_R3_BLOCKED_MIGRATION_DRIFT'], 500);
    }
    if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+zimmetler\b/i', $sql)) {
        s78c2_json(['ok' => false, 'error' => 'MIGRATION_IF_NOT_EXISTS', 'code' => 'S78_C2_R3_BLOCKED_MIGRATION_DRIFT'], 500);
    }
    if (stripos($sql, 'ON DELETE CASCADE') !== false || !preg_match('/ON DELETE RESTRICT/i', $sql)) {
        s78c2_json(['ok' => false, 'error' => 'MIGRATION_FK_SHAPE', 'code' => 'S78_C2_R3_BLOCKED_MIGRATION_DRIFT'], 500);
    }

    try {
        $pdo->exec('SET NAMES utf8mb4');
        $pdo->exec("SET time_zone = '+00:00'");
        // Exact CREATE TABLE body from uploaded migration file (no rewrite).
        $createPos = stripos($sql, 'CREATE TABLE zimmetler');
        if ($createPos === false) {
            throw new RuntimeException('CREATE TABLE not found');
        }
        $createSql = trim(substr($sql, $createPos));
        $createSql = rtrim($createSql, " \t\r\n;");
        $pdo->exec($createSql);
    } catch (Throwable $e) {
        s78c2_json([
            'ok' => false,
            'code' => 'S78_C2_R3_BLOCKED_MIGRATION_APPLY',
            'error' => $e->getMessage(),
            'sqlstate' => ($e instanceof PDOException && isset($e->errorInfo[0])) ? $e->errorInfo[0] : null,
            'driver_code' => ($e instanceof PDOException && isset($e->errorInfo[1])) ? $e->errorInfo[1] : null,
        ], 500);
    }

    $afterSchema = s78c2_zimmet_schema($pdo);
    $match = s78c2_schema_matches_026($afterSchema);
    $after = s78c2_counts($pdo);
    $ok = $match['ok']
        && $after['personel_count'] === $before['personel_count']
        && (int) $after['zimmet_count'] === 0;

    s78c2_json([
        'ok' => $ok,
        'code' => $ok ? 'S78_C2_MIGRATE_OK' : 'S78_C2_MIGRATION_APPLIED_SCHEMA_ACCEPTANCE_BLOCKED',
        'skipped_apply' => false,
        'applied' => [
            'file' => $file,
            'sha256' => hash('sha256', $sql),
            'bytes' => strlen($sql),
        ],
        'match' => $match,
        'before' => $before,
        'after' => $after,
        'schema' => $afterSchema,
        'identity' => $identity,
    ], $ok ? 200 : 500);
}

if ($action === 'postcheck') {
    $identity = s78c2_identity($pdo);
    $schema = s78c2_zimmet_schema($pdo);
    $match = s78c2_schema_matches_026($schema);
    $counts = s78c2_counts($pdo);
    $orphan = 0;
    $byState = [];
    if ($schema['exists']) {
        $orphan = (int) $pdo->query(
            'SELECT COUNT(*) FROM zimmetler z LEFT JOIN personeller p ON p.id = z.personel_id WHERE p.id IS NULL'
        )->fetchColumn();
        $byState = $pdo->query(
            'SELECT zimmet_durumu, COUNT(*) AS adet FROM zimmetler GROUP BY zimmet_durumu'
        )->fetchAll();
    }

    $ok = $identity['aktif_veritabani'] === 'karmotor_medisa' && $match['ok'] && $orphan === 0;
    s78c2_json([
        'ok' => $ok,
        'code' => $ok ? 'S78_C2_POSTCHECK_OK' : 'S78_C2_MIGRATION_APPLIED_SCHEMA_ACCEPTANCE_BLOCKED',
        'identity' => $identity,
        'match' => $match,
        'schema' => $schema,
        'counts' => $counts,
        'orphan_count' => $orphan,
        'by_state' => $byState,
    ], $ok ? 200 : 500);
}

if ($action === 'final_integrity') {
    $identity = s78c2_identity($pdo);
    $counts = s78c2_counts($pdo);
    $orphan = (int) $pdo->query(
        'SELECT COUNT(*) FROM zimmetler z LEFT JOIN personeller p ON p.id = z.personel_id WHERE p.id IS NULL'
    )->fetchColumn();
    $byState = $pdo->query(
        'SELECT zimmet_durumu, COUNT(*) AS adet FROM zimmetler GROUP BY zimmet_durumu'
    )->fetchAll();
    $smokeLeft = (int) $pdo->query(
        "SELECT COUNT(*) FROM zimmetler WHERE aciklama = 'S78-C2-R3 geçici kabul kaydı'"
    )->fetchColumn();
    $ok = $orphan === 0 && $smokeLeft === 0;
    s78c2_json([
        'ok' => $ok,
        'code' => $ok ? 'S78_C2_FINAL_INTEGRITY_OK' : 'S78_C2_FINAL_INTEGRITY_FAILED',
        'identity' => $identity,
        'counts' => $counts,
        'orphan_count' => $orphan,
        'by_state' => $byState,
        'smoke_rows_left' => $smokeLeft,
    ], $ok ? 200 : 500);
}

s78c2_json(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], 400);
