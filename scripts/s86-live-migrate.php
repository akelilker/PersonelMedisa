<?php
/**
 * ONE-SHOT S86 live migrate for 038_personel_belge_yonetimi.sql only.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * No seed, no SGK writes, no personel data writes except schema migrate. UTF-8 without BOM.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S86_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_S86_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_S86_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'identity';
$expected038 = 'e72ccad0231722c0d846017c9f54bc6b29f62720901efb43c0af56d3e2ae30ed';
$migrationFile = '038_personel_belge_yonetimi.sql';

function s86_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

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
    s86_json(['ok' => false, 'error' => 'CONFIG_MISSING'], 500);
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

function s86_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s86_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s86_count(PDO $pdo, string $table): int
{
    if (!s86_table_exists($pdo, $table)) {
        return -1;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
}

function s86_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s86_default_storage_root(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'personel-belgeler';
}

function s86_configured_storage_root(array $config): ?string
{
    $configured = $config['personel_belge_storage_root'] ?? '';
    if (!is_string($configured) || trim($configured) === '') {
        return null;
    }

    return rtrim($configured, "\\/");
}

function s86_recommended_storage_path(): string
{
    return '/home/karmotor/personelmedisa-belgeler';
}

/** @return array<int, string> */
function s86_public_web_roots(): array
{
    $roots = [];
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
    if ($docRoot !== '') {
        $roots[] = $docRoot;
    }
    $apiPublic = realpath(__DIR__) ?: __DIR__;
    $roots[] = $apiPublic;
    $roots[] = dirname($apiPublic);
    $personelmedisaPublic = dirname($apiPublic, 2) . DIRECTORY_SEPARATOR . 'public';
    if (is_dir($personelmedisaPublic)) {
        $roots[] = $personelmedisaPublic;
    }
    $normalized = [];
    foreach ($roots as $root) {
        $real = realpath($root);
        if ($real !== false) {
            $normalized[] = rtrim(str_replace('\\', '/', $real), '/');
        }
    }

    return array_values(array_unique($normalized));
}

function s86_path_under_public_web_root(string $path): bool
{
    $real = realpath($path);
    if ($real === false) {
        $real = $path;
    }
    $candidate = rtrim(str_replace('\\', '/', $real), '/');
    foreach (s86_public_web_roots() as $root) {
        if ($candidate === $root || str_starts_with($candidate . '/', $root . '/')) {
            return true;
        }
    }

    return false;
}

/** @return array<string, mixed> */
function s86_preflight(PDO $pdo, array $config): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }

    $hasSurum = s86_table_exists($pdo, 'personel_belge_dosya_surumleri');
    $hasAudit = s86_table_exists($pdo, 'personel_belge_auditleri');
    $hasSgkManifest = s86_table_exists($pdo, 'sgk_kaynak_manifestleri');
    $configuredRoot = s86_configured_storage_root($config);

    return [
        'table_count' => count($tables),
        'personel_belge_dosya_surumleri_exists' => $hasSurum,
        'personel_belge_auditleri_exists' => $hasAudit,
        'sgk_kaynak_manifestleri_exists' => $hasSgkManifest,
        'counts' => [
            'personeller' => s86_count($pdo, 'personeller'),
            'surecler' => s86_count($pdo, 'surecler'),
            'sgk_kaynak_manifestleri' => $hasSgkManifest ? s86_count($pdo, 'sgk_kaynak_manifestleri') : -1,
        ],
        'personel_belge_storage_root_configured' => $configuredRoot,
        'default_storage_root_detected' => s86_default_storage_root(),
        'recommended_storage_root_outside_public_html' => s86_recommended_storage_path(),
        'already_applied' => $hasSurum && $hasAudit,
        'fresh_apply_ready' => !$hasSurum && !$hasAudit,
    ];
}

/** @return array<string, mixed> */
function s86_inventory_json(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    sort($tables);

    $createTables = [];
    foreach ($tables as $table) {
        $create = $pdo->query('SHOW CREATE TABLE ' . s86_quote_ident($table))->fetch();
        $createTables[$table] = (string) ($create['Create Table'] ?? '');
    }

    $keyCounts = [
        'personeller' => s86_count($pdo, 'personeller'),
        'surecler' => s86_count($pdo, 'surecler'),
        'sgk_kaynak_manifestleri' => s86_count($pdo, 'sgk_kaynak_manifestleri'),
        'personel_belge_dosya_surumleri' => s86_count($pdo, 'personel_belge_dosya_surumleri'),
        'personel_belge_auditleri' => s86_count($pdo, 'personel_belge_auditleri'),
    ];

    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING
         FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE()
         ORDER BY TRIGGER_NAME"
    )->fetchAll();

    return [
        'generated_at_utc' => gmdate('c'),
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'table_count' => count($tables),
        'tables' => $tables,
        'create_tables' => $createTables,
        'key_row_counts' => $keyCounts,
        'triggers' => $triggers,
    ];
}

function s86_backup_paths(): array
{
    static $paths = null;
    if ($paths !== null) {
        return $paths;
    }
    $stamp = gmdate('Ymd_His');
    $base = 'karmotor_medisa_pre_038_' . $stamp;
    $paths = [
        'sql' => __DIR__ . '/' . $base . '.sql',
        'json' => __DIR__ . '/' . $base . '.json',
        'base' => $base,
    ];

    return $paths;
}

/** @return array<int, string> */
function s86_split_sql(string $sql): array
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

function s86_apply_038(PDO $pdo, string $file, string $expectedSha): array
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
    foreach (s86_split_sql($sql) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }

    return ['file' => basename($file), 'sha256' => $sha, 'statements' => $count];
}

function s86_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"
    );
    $stmt->execute(['t' => $table, 'i' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

function s86_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() > 0;
}

/** @return array<int, array<string, mixed>> */
function s86_fk_rules(PDO $pdo, string $table, string $prefix): array
{
    $stmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND CONSTRAINT_NAME LIKE :p"
    );
    $stmt->execute(['t' => $table, 'p' => $prefix . '%']);

    return $stmt->fetchAll();
}

/** @return array<int, string> */
function s86_check_constraints(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND CONSTRAINT_TYPE = 'CHECK'"
    );
    $stmt->execute(['t' => $table]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** @return array<string, mixed> */
function s86_postcheck(PDO $pdo, ?int $beforePersoneller, ?int $beforeSurecler): array
{
    $surumExists = s86_table_exists($pdo, 'personel_belge_dosya_surumleri');
    $auditExists = s86_table_exists($pdo, 'personel_belge_auditleri');
    $surumCount = s86_count($pdo, 'personel_belge_dosya_surumleri');
    $auditCount = s86_count($pdo, 'personel_belge_auditleri');
    $personeller = s86_count($pdo, 'personeller');
    $surecler = s86_count($pdo, 'surecler');

    $indexesOk =
        s86_index_exists($pdo, 'personel_belge_dosya_surumleri', 'uq_pbds_storage_key')
        && s86_index_exists($pdo, 'personel_belge_dosya_surumleri', 'uq_pbds_surec_surum')
        && s86_index_exists($pdo, 'personel_belge_dosya_surumleri', 'uq_pbds_tek_aktif');

    $fkPbds = s86_fk_rules($pdo, 'personel_belge_dosya_surumleri', 'fk_pbds_');
    $fkPbaud = s86_fk_rules($pdo, 'personel_belge_auditleri', 'fk_pbaud_');
    $fkOk = true;
    foreach (array_merge($fkPbds, $fkPbaud) as $fk) {
        $deleteRule = strtoupper((string) ($fk['DELETE_RULE'] ?? ''));
        if (!in_array($deleteRule, ['RESTRICT', 'NO ACTION'], true)) {
            $fkOk = false;
            break;
        }
    }
    $fkOk = $fkOk && count($fkPbds) >= 3 && count($fkPbaud) >= 4;

    $checksSurum = s86_check_constraints($pdo, 'personel_belge_dosya_surumleri');
    $checksAudit = s86_check_constraints($pdo, 'personel_belge_auditleri');
    $checksOk = count($checksSurum) >= 3 && count($checksAudit) >= 1;

    $countsUnchanged = true;
    if ($beforePersoneller !== null && $personeller !== $beforePersoneller) {
        $countsUnchanged = false;
    }
    if ($beforeSurecler !== null && $surecler !== $beforeSurecler) {
        $countsUnchanged = false;
    }

    $ok =
        $surumExists
        && $auditExists
        && s86_column_exists($pdo, 'personel_belge_dosya_surumleri', 'aktif_surec_key')
        && $indexesOk
        && $fkOk
        && $checksOk
        && $surumCount === 0
        && $auditCount === 0
        && $countsUnchanged;

    return [
        'ok' => $ok,
        'tables' => [
            'personel_belge_dosya_surumleri' => $surumExists,
            'personel_belge_auditleri' => $auditExists,
        ],
        'aktif_surec_key' => s86_column_exists($pdo, 'personel_belge_dosya_surumleri', 'aktif_surec_key'),
        'indexes' => [
            'uq_pbds_storage_key' => s86_index_exists($pdo, 'personel_belge_dosya_surumleri', 'uq_pbds_storage_key'),
            'uq_pbds_surec_surum' => s86_index_exists($pdo, 'personel_belge_dosya_surumleri', 'uq_pbds_surec_surum'),
            'uq_pbds_tek_aktif' => s86_index_exists($pdo, 'personel_belge_dosya_surumleri', 'uq_pbds_tek_aktif'),
        ],
        'foreign_keys' => [
            'fk_pbds' => $fkPbds,
            'fk_pbaud' => $fkPbaud,
        ],
        'check_constraints' => [
            'personel_belge_dosya_surumleri' => $checksSurum,
            'personel_belge_auditleri' => $checksAudit,
        ],
        'row_counts' => [
            'personel_belge_dosya_surumleri' => $surumCount,
            'personel_belge_auditleri' => $auditCount,
            'personeller' => $personeller,
            'surecler' => $surecler,
        ],
        'before_snapshot_match' => [
            'before_personeller' => $beforePersoneller,
            'before_surecler' => $beforeSurecler,
            'personeller_unchanged' => $beforePersoneller === null || $personeller === $beforePersoneller,
            'surecler_unchanged' => $beforeSurecler === null || $surecler === $beforeSurecler,
        ],
    ];
}

/** @return array<string, mixed> */
function s86_storage_probe(array $config): array
{
    $configured = s86_configured_storage_root($config);
    $default = s86_default_storage_root();
    $recommended = s86_recommended_storage_path();

    if ($configured === null) {
        return [
            'ok' => false,
            'code' => 'S86_STORAGE_PROBE_BLOCKED',
            'status' => 'BLOCKED',
            'reason' => 'personel_belge_storage_root config empty',
            'personel_belge_storage_root_configured' => null,
            'default_storage_root_detected' => $default,
            'recommended_path_outside_public_html' => $recommended,
        ];
    }

    $exists = is_dir($configured);
    $writable = $exists && is_writable($configured);
    $real = realpath($configured);
    $underPublic = s86_path_under_public_web_root($configured);
    $testWriteOk = false;
    $testFile = null;
    $tmpOrphans = [];

    if ($exists && $writable && !$underPublic) {
        $testFile = rtrim($configured, "\\/") . DIRECTORY_SEPARATOR . '.s86_probe_' . bin2hex(random_bytes(4));
        $testWriteOk = @file_put_contents($testFile, 's86') !== false;
        if ($testWriteOk) {
            @unlink($testFile);
        }
    }

    if ($exists) {
        $glob = glob(rtrim($configured, "\\/") . DIRECTORY_SEPARATOR . '*.tmp.*') ?: [];
        foreach ($glob as $path) {
            $tmpOrphans[] = basename($path);
        }
    }

    $perms = null;
    if ($exists) {
        $oct = @fileperms($configured);
        if ($oct !== false) {
            $perms = substr(sprintf('%o', $oct), -4);
        }
    }

    $ok = $exists && $writable && !$underPublic && $testWriteOk;

    return [
        'ok' => $ok,
        'code' => $ok ? 'S86_STORAGE_PROBE_OK' : 'S86_STORAGE_PROBE_BLOCKED',
        'status' => $ok ? 'OK' : 'BLOCKED',
        'personel_belge_storage_root_configured' => $configured,
        'default_storage_root_detected' => $default,
        'recommended_path_outside_public_html' => $recommended,
        'is_dir' => $exists,
        'is_writable' => $writable,
        'realpath' => $real !== false ? $real : null,
        'under_public_web_root' => $underPublic,
        'public_web_roots_checked' => s86_public_web_roots(),
        'test_write_ok' => $testWriteOk,
        'tmp_orphans' => $tmpOrphans,
        'permissions_octal' => $perms,
    ];
}

if ($action === 'identity') {
    $identity = s86_identity($pdo);
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
    $identity = s86_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = s86_preflight($pdo, $config);
    $code = 'S86_PREFLIGHT_OK';
    if (!$pre['sgk_kaynak_manifestleri_exists']) {
        $code = 'S86_PREFLIGHT_036_037_MISSING';
    } elseif ($pre['personel_belge_dosya_surumleri_exists'] xor $pre['personel_belge_auditleri_exists']) {
        $code = 'S86_PREFLIGHT_PARTIAL_038_TRACE';
    } elseif ($pre['already_applied']) {
        $code = 'S86_ALREADY_APPLIED';
    }

    $ok = in_array($code, ['S86_PREFLIGHT_OK', 'S86_ALREADY_APPLIED'], true);
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
    $identity = s86_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $paths = s86_backup_paths();
    $meta = [
        'method' => null,
        'file' => null,
        'bytes' => 0,
        'sha256' => null,
        'contains_create_table' => false,
        'contains_insert' => false,
        'inventory_json' => false,
    ];

    $mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    if ($mysqldump !== '') {
        $backupPath = $paths['sql'];
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
            $meta['file'] = basename($backupPath);
        }
    }

    if ($meta['method'] === null) {
        $inventory = s86_inventory_json($pdo);
        $payload = json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload) || $payload === '') {
            s86_json(['ok' => false, 'error' => 'INVENTORY_JSON_ENCODE_FAILED'], 500);
        }
        $backupPath = $paths['json'];
        file_put_contents($backupPath, $payload);
        $meta['method'] = 'inventory_json';
        $meta['file'] = basename($backupPath);
        $meta['inventory_json'] = true;
        $meta['contains_create_table'] = count($inventory['create_tables'] ?? []) > 0;
        $meta['key_row_counts'] = $inventory['key_row_counts'] ?? [];
        $meta['table_count'] = $inventory['table_count'] ?? 0;
        $meta['trigger_count'] = count($inventory['triggers'] ?? []);
    }

    if ($meta['file'] === null || !is_file(__DIR__ . '/' . $meta['file']) || filesize(__DIR__ . '/' . $meta['file']) <= 0) {
        s86_json(['ok' => false, 'error' => 'BACKUP_FILE_MISSING'], 500);
    }

    $fullPath = __DIR__ . '/' . $meta['file'];
    $contents = (string) file_get_contents($fullPath);
    $meta['bytes'] = filesize($fullPath);
    $meta['sha256'] = hash_file('sha256', $fullPath);
    if ($meta['method'] === 'mysqldump') {
        $meta['contains_create_table'] = stripos($contents, 'CREATE TABLE') !== false;
        $meta['contains_insert'] = stripos($contents, 'INSERT INTO') !== false;
        $meta['single_transaction'] = true;
    }

    file_put_contents(__DIR__ . '/s86_latest_backup_path.txt', $meta['file']);
    $ok = $meta['bytes'] > 0 && (
        ($meta['method'] === 'mysqldump' && $meta['contains_create_table'] && $meta['contains_insert'])
        || ($meta['method'] === 'inventory_json' && $meta['contains_create_table'])
    );

    $response = [
        'ok' => $ok,
        'code' => $ok ? 'S86_BACKUP_OK' : 'S86_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'identity' => $identity,
        'preflight' => s86_preflight($pdo, $config),
    ];
    if ($meta['method'] === 'inventory_json') {
        $response['inventory'] = json_decode($contents, true);
    }
    s86_json($response);
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s86_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '') {
        $matches = array_merge(
            glob(__DIR__ . '/karmotor_medisa_pre_038_*.sql') ?: [],
            glob(__DIR__ . '/karmotor_medisa_pre_038_*.json') ?: []
        );
        rsort($matches);
        $backupPath = $matches[0] ?? '';
    }
    if ($backupPath === '' || !is_file($backupPath)) {
        s86_json(['ok' => false, 'error' => 'BACKUP_NOT_FOUND'], 404);
    }

    $size = filesize($backupPath);
    if ($size === false || $size <= 0) {
        s86_json(['ok' => false, 'error' => 'BACKUP_EMPTY'], 500);
    }

    $isJson = substr(strtolower($backupPath), -5) === '.json';
    header('Content-Type: ' . ($isJson ? 'application/json' : 'application/sql') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($backupPath) . '"');
    header('Content-Length: ' . (string) $size);
    header('Cache-Control: no-store');
    readfile($backupPath);
    exit;
}

if ($action === 'migrate') {
    $identity = s86_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = s86_preflight($pdo, $config);
    if (!$pre['sgk_kaynak_manifestleri_exists']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S86_PREFLIGHT_036_037_MISSING', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['personel_belge_dosya_surumleri_exists'] xor $pre['personel_belge_auditleri_exists']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S86_PREFLIGHT_PARTIAL_038_TRACE', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['already_applied']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S86_ALREADY_APPLIED', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $beforePersoneller = $pre['counts']['personeller'];
    $beforeSurecler = $pre['counts']['surecler'];
    $path = __DIR__ . '/' . $migrationFile;

    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        $applied = s86_apply_038($pdo, $path, $expected038);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S86_MIGRATE_FAILED',
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $afterPersoneller = s86_count($pdo, 'personeller');
    $afterSurecler = s86_count($pdo, 'surecler');
    $newCounts = [
        'personel_belge_dosya_surumleri' => s86_count($pdo, 'personel_belge_dosya_surumleri'),
        'personel_belge_auditleri' => s86_count($pdo, 'personel_belge_auditleri'),
    ];
    $ok =
        $afterPersoneller === $beforePersoneller
        && $afterSurecler === $beforeSurecler
        && $newCounts['personel_belge_dosya_surumleri'] === 0
        && $newCounts['personel_belge_auditleri'] === 0;

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S86_MIGRATE_OK' : 'S86_MIGRATE_POSTCHECK_FAILED',
        'applied' => $applied,
        'before' => [
            'personeller' => $beforePersoneller,
            'surecler' => $beforeSurecler,
        ],
        'after' => [
            'personeller' => $afterPersoneller,
            'surecler' => $afterSurecler,
        ],
        'new_table_row_counts' => $newCounts,
        'identity' => $identity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'postcheck') {
    $beforePersoneller = isset($_GET['before_personeller']) ? (int) $_GET['before_personeller'] : null;
    $beforeSurecler = isset($_GET['before_surecler']) ? (int) $_GET['before_surecler'] : null;
    $check = s86_postcheck($pdo, $beforePersoneller, $beforeSurecler);
    echo json_encode([
        'ok' => $check['ok'],
        'code' => $check['ok'] ? 'S86_POSTCHECK_OK' : 'S86_POSTCHECK_FAILED',
        'identity' => s86_identity($pdo),
        'postcheck' => $check,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'storage_probe') {
    $probe = s86_storage_probe($config);
    if (!$probe['ok']) {
        http_response_code(409);
    }
    echo json_encode($probe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'storage_ensure') {
    if (!isset($_GET['mkdir']) || (string) $_GET['mkdir'] !== '1') {
        s86_json(['ok' => false, 'error' => 'MKDIR_PARAM_REQUIRED', 'hint' => 'Use action=storage_ensure&mkdir=1'], 400);
    }

    $configured = s86_configured_storage_root($config);
    if ($configured === null) {
        s86_json([
            'ok' => false,
            'code' => 'S86_STORAGE_ENSURE_BLOCKED',
            'error' => 'CONFIG_EMPTY',
            'message' => 'personel_belge_storage_root not configured; will not invent path',
        ], 409);
    }
    if (s86_path_under_public_web_root($configured)) {
        s86_json([
            'ok' => false,
            'code' => 'S86_STORAGE_ENSURE_BLOCKED',
            'error' => 'UNDER_PUBLIC_WEB_ROOT',
            'path' => $configured,
        ], 409);
    }

    $created = false;
    if (!is_dir($configured)) {
        $created = @mkdir($configured, 0750, true);
        if (!$created && !is_dir($configured)) {
            s86_json(['ok' => false, 'code' => 'S86_STORAGE_ENSURE_FAILED', 'path' => $configured], 500);
        }
        $created = true;
    }

    @chmod($configured, 0750);
    $probe = s86_storage_probe($config);
    s86_json([
        'ok' => $probe['ok'],
        'code' => $probe['ok'] ? 'S86_STORAGE_ENSURE_OK' : 'S86_STORAGE_ENSURE_INCOMPLETE',
        'created' => $created,
        'storage_probe' => $probe,
    ]);
}

if ($action === 'storage_configure') {
    if (!isset($_GET['confirm']) || (string) $_GET['confirm'] !== 'SET_RECOMMENDED_ROOT') {
        s86_json(['ok' => false, 'error' => 'CONFIRM_REQUIRED', 'hint' => 'confirm=SET_RECOMMENDED_ROOT'], 400);
    }

    $configPath = null;
    foreach ($configCandidates as $path) {
        if (is_file($path)) {
            $configPath = $path;
            break;
        }
    }
    if ($configPath === null || !is_writable($configPath)) {
        s86_json(['ok' => false, 'code' => 'S86_STORAGE_CONFIGURE_BLOCKED', 'error' => 'CONFIG_NOT_WRITABLE'], 500);
    }

    $recommended = s86_recommended_storage_path();
    if (s86_path_under_public_web_root($recommended)) {
        s86_json(['ok' => false, 'code' => 'S86_STORAGE_CONFIGURE_BLOCKED', 'error' => 'RECOMMENDED_UNDER_PUBLIC'], 409);
    }

    $raw = (string) file_get_contents($configPath);
    if ($raw === '') {
        s86_json(['ok' => false, 'error' => 'CONFIG_READ_FAILED'], 500);
    }

    $updated = false;
    $patterns = [
        "/('personel_belge_storage_root'\\s*=>\\s*)''/",
        '/("personel_belge_storage_root"\\s*=>\\s*)""/',
        "/('personel_belge_storage_root'\\s*=>\\s*)null/i",
    ];
    foreach ($patterns as $pattern) {
        $next = preg_replace($pattern, '${1}' . var_export($recommended, true), $raw, 1, $count);
        if (is_string($next) && $count === 1) {
            $raw = $next;
            $updated = true;
            break;
        }
    }
    if (!$updated && strpos($raw, 'personel_belge_storage_root') === false) {
        $raw = preg_replace(
            '/(return\\s*\\[)/',
            "$1\n    'personel_belge_storage_root' => " . var_export($recommended, true) . ',',
            $raw,
            1,
            $count
        );
        $updated = is_string($raw) && $count === 1;
    }
    if (!$updated) {
        // Already set — do not overwrite existing non-empty value.
        $current = s86_configured_storage_root($config);
        if ($current !== null) {
            s86_json([
                'ok' => true,
                'code' => 'S86_STORAGE_ALREADY_CONFIGURED',
                'path' => $current,
                'changed' => false,
            ]);
        }
        s86_json(['ok' => false, 'code' => 'S86_STORAGE_CONFIGURE_BLOCKED', 'error' => 'PATTERN_NOT_MATCHED'], 409);
    }

    $backupCfg = $configPath . '.s86bak.' . gmdate('YmdHis');
    if (!@copy($configPath, $backupCfg)) {
        s86_json(['ok' => false, 'error' => 'CONFIG_BACKUP_FAILED'], 500);
    }
    if (@file_put_contents($configPath, $raw) === false) {
        s86_json(['ok' => false, 'error' => 'CONFIG_WRITE_FAILED'], 500);
    }

    $dirCreated = false;
    if (!is_dir($recommended)) {
        $dirCreated = @mkdir($recommended, 0750, true);
        if (!$dirCreated && !is_dir($recommended)) {
            s86_json(['ok' => false, 'code' => 'S86_STORAGE_DIR_CREATE_FAILED', 'path' => $recommended], 500);
        }
        $dirCreated = true;
    }
    @chmod($recommended, 0750);

    $fresh = $config;
    $fresh['personel_belge_storage_root'] = $recommended;
    $probe = s86_storage_probe($fresh);
    s86_json([
        'ok' => $probe['ok'],
        'code' => $probe['ok'] ? 'S86_STORAGE_CONFIGURE_OK' : 'S86_STORAGE_CONFIGURE_INCOMPLETE',
        'path' => $recommended,
        'changed' => true,
        'dir_created' => $dirCreated,
        'config_backup_basename' => basename($backupCfg),
        'storage_probe' => $probe,
    ]);
}

s86_json(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], 400);
