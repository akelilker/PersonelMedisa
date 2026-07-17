<?php
/**
 * ONE-SHOT S77-D live schema migrate (022/023/024).
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S77D_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($tokenExpected === 'REPLACE_S77D_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'migrate';

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

function s77d_count(PDO $pdo, string $table): int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
    } catch (Throwable $e) {
        return -1;
    }
}

function s77d_inventory(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = $row[0];
    }
    sort($tables);
    $snap = $pdo->query('SELECT id, sube_id, yil, ay, state, revision_no, snapshot_hash, personel_sayisi, girdi_sayisi
        FROM maas_hesaplama_donem_snapshotlari WHERE id = 1')->fetch();
    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg_mh%'
         ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    return [
        'db' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'table_count' => count($tables),
        'tables' => $tables,
        'counts' => [
            'personeller' => s77d_count($pdo, 'personeller'),
            'maas_hesaplama_donem_snapshotlari' => s77d_count($pdo, 'maas_hesaplama_donem_snapshotlari'),
            'maas_hesaplama_personel_snapshotlari' => s77d_count($pdo, 'maas_hesaplama_personel_snapshotlari'),
            'maas_hesaplama_girdi_snapshotlari' => s77d_count($pdo, 'maas_hesaplama_girdi_snapshotlari'),
            'maas_hesaplama_snapshot_auditleri' => s77d_count($pdo, 'maas_hesaplama_snapshot_auditleri'),
            'personel_bordro_devirleri' => s77d_count($pdo, 'personel_bordro_devirleri'),
            'maas_hesaplama_calistirmalari' => s77d_count($pdo, 'maas_hesaplama_calistirmalari'),
            'maas_hesaplama_adaylari' => s77d_count($pdo, 'maas_hesaplama_adaylari'),
            'maas_hesaplama_aday_kalemleri' => s77d_count($pdo, 'maas_hesaplama_aday_kalemleri'),
            'maas_hesaplama_auditleri' => s77d_count($pdo, 'maas_hesaplama_auditleri'),
            'mevzuat_parametreleri' => s77d_count($pdo, 'mevzuat_parametreleri'),
        ],
        'snapshot_1' => $snap ?: null,
        'triggers' => $triggers,
    ];
}

function s77d_split_sql(string $sql): array
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

function s77d_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

if ($action === 'inventory') {
    echo json_encode(['ok' => true, 'inventory' => s77d_inventory($pdo)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'backup') {
    $stamp = gmdate('Ymd-His');
    $backupName = '_s77d_pre_migration_' . $stamp . '.sql';
    $backupPath = sys_get_temp_dir() . '/' . $backupName;
    $mysqldump = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    $backupMeta = ['method' => null, 'file' => $backupName, 'bytes' => 0, 'sha256' => null, 'path' => $backupPath];
    if ($mysqldump !== '') {
        $cmd = escapeshellarg($mysqldump)
            . ' --single-transaction --routines --triggers --hex-blob'
            . ' -h ' . escapeshellarg($host)
            . ' -u ' . escapeshellarg($user)
            . ' -p' . escapeshellarg($pass)
            . ' ' . escapeshellarg($name)
            . ' > ' . escapeshellarg($backupPath)
            . ' 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code === 0 && is_file($backupPath) && filesize($backupPath) > 0) {
            $backupMeta['method'] = 'mysqldump';
            $backupMeta['bytes'] = filesize($backupPath);
            $backupMeta['sha256'] = hash_file('sha256', $backupPath);
        }
    }
    if ($backupMeta['method'] === null) {
        // Fallback: inventory-only marker file (no full dump available on shared host)
        $payload = "-- S77-D inventory backup fallback\n" . json_encode(s77d_inventory($pdo), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($backupPath, $payload);
        $backupMeta['method'] = 'inventory_json';
        $backupMeta['bytes'] = strlen($payload);
        $backupMeta['sha256'] = hash('sha256', $payload);
    }
    echo json_encode([
        'ok' => true,
        'backup' => $backupMeta,
        'inventory' => s77d_inventory($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$files = [
    '022_personel_bordro_devirleri.sql',
    '023_maas_hesaplama_adaylari.sql',
    '024_maas_hesaplama_aday_guvenlik_indexleri.sql',
];
$dir = __DIR__;
$applied = [];
$before = s77d_inventory($pdo);
$protected = $before['snapshot_1'];

$pdo->exec('SET NAMES utf8mb4');
$pdo->exec("SET time_zone = '+00:00'");

foreach ($files as $file) {
    $path = $dir . '/' . $file;
    if (!is_file($path)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'MIGRATION_FILE_MISSING', 'file' => $file], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sql = file_get_contents($path);
    if ($sql === false) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'MIGRATION_READ_FAILED', 'file' => $file], JSON_UNESCAPED_UNICODE);
        exit;
    }
    foreach (s77d_split_sql($sql) as $statement) {
        $pdo->exec($statement);
    }
    $applied[] = [
        'file' => $file,
        'sha256' => hash('sha256', $sql),
        'bytes' => strlen($sql),
    ];
}

$after = s77d_inventory($pdo);
$ok =
    s77d_table_exists($pdo, 'personel_bordro_devirleri')
    && s77d_table_exists($pdo, 'maas_hesaplama_calistirmalari')
    && s77d_table_exists($pdo, 'maas_hesaplama_adaylari')
    && s77d_table_exists($pdo, 'maas_hesaplama_aday_kalemleri')
    && s77d_table_exists($pdo, 'maas_hesaplama_auditleri')
    && is_array($after['snapshot_1'])
    && is_array($protected)
    && (string) $after['snapshot_1']['snapshot_hash'] === (string) $protected['snapshot_hash']
    && (int) $after['snapshot_1']['personel_sayisi'] === (int) $protected['personel_sayisi']
    && (int) $after['snapshot_1']['girdi_sayisi'] === (int) $protected['girdi_sayisi']
    && (string) $after['snapshot_1']['state'] === 'OLUSTURULDU';

echo json_encode([
    'ok' => $ok,
    'code' => $ok ? 'S77_D_SCHEMA_FIRST_LIVE_OK' : 'S77_D_SCHEMA_FIRST_LIVE_FAILED',
    'applied' => $applied,
    'before' => $before,
    'after' => $after,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
