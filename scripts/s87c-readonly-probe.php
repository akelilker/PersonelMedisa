<?php
/**
 * ONE-SHOT S87-C production READ-ONLY policy/migration/count gate.
 * Temporary upload to api/public/, HTTPS invoke, then delete.
 * No writes. No policy upsert. No migration apply. UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S87C_READONLY_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
if ($tokenExpected === 'UNSET_S87C_READONLY_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'inventory';

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
    echo json_encode(['ok' => false, 'error' => 'DB_CONNECT_FAILED'], JSON_UNESCAPED_UNICODE);
    exit;
}

function s87c_scalar(PDO $pdo, string $sql): string
{
    $v = $pdo->query($sql)->fetchColumn();
    return $v === false || $v === null ? '' : (string) $v;
}

function s87c_count(PDO $pdo, string $table): ?int
{
    try {
        return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

function s87c_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);
    return ((int) $stmt->fetchColumn()) > 0;
}

function s87c_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return ((int) $stmt->fetchColumn()) > 0;
}

if ($action === 'identity') {
    echo json_encode([
        'ok' => true,
        'code' => 'PRODUCTION_DB_IDENTITY_OK',
        'identity' => [
            'aktif_veritabani' => s87c_scalar($pdo, 'SELECT DATABASE()'),
            'config_db_name' => $name,
            'db_hostname' => s87c_scalar($pdo, 'SELECT @@hostname'),
            'db_version' => s87c_scalar($pdo, 'SELECT VERSION()'),
            'db_now' => s87c_scalar($pdo, 'SELECT NOW()'),
        ],
        'expected_db' => 'karmotor_medisa',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action !== 'inventory') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION'], JSON_UNESCAPED_UNICODE);
    exit;
}

$policyRows = [];
if (s87c_table_exists($pdo, 'sirket_calisma_politika_degerleri') && s87c_table_exists($pdo, 'sirket_calisma_politikalari')) {
    $sql = "SELECT d.parametre_kodu, d.metin_deger, d.sayisal_deger, p.state, p.id AS politika_id
            FROM sirket_calisma_politika_degerleri d
            INNER JOIN sirket_calisma_politikalari p ON p.id = d.politika_id
            WHERE d.parametre_kodu = 'TATIL_FSC_FM_CAKISMA_HESAP_MODU'
               OR UPPER(TRIM(COALESCE(d.metin_deger, ''))) = 'YARGITAY_7_5_SAAT_AYRIMI'
            ORDER BY p.id ASC, d.parametre_kodu ASC";
    $policyRows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

$migrationCandidates = [
    'schema_migrations',
    'uygulanan_migrationlar',
    'migration_history',
    'schema_migration_history',
    'medisa_schema_migrations',
];
$migrationTables = [];
foreach ($migrationCandidates as $t) {
    if (s87c_table_exists($pdo, $t)) {
        $migrationTables[] = $t;
    }
}

$migration038Evidence = [
    'personel_belge_dosya_surumleri' => s87c_table_exists($pdo, 'personel_belge_dosya_surumleri'),
    'personel_belge_auditleri' => s87c_table_exists($pdo, 'personel_belge_auditleri'),
];
$migration039Evidence = [
    'ubgt_gun_kapsami_on_gunluk_puantaj' => s87c_column_exists($pdo, 'gunluk_puantaj', 'ubgt_gun_kapsami'),
    'tatil_gun_kapsami_on_gunluk_puantaj' => s87c_column_exists($pdo, 'gunluk_puantaj', 'tatil_gun_kapsami'),
    'ubgt_gun_kapsami_on_muhur' => s87c_column_exists($pdo, 'puantaj_aylik_muhur_satirlari', 'ubgt_gun_kapsami'),
];

$counts = [
    'personeller' => s87c_count($pdo, 'personeller'),
    'gunluk_puantaj' => s87c_count($pdo, 'gunluk_puantaj'),
    'maas_hesaplama_snapshotlari' => s87c_count($pdo, 'maas_hesaplama_snapshotlari'),
    'sirket_calisma_politikalari' => s87c_count($pdo, 'sirket_calisma_politikalari'),
    'sirket_calisma_politika_degerleri' => s87c_count($pdo, 'sirket_calisma_politika_degerleri'),
];

$yargitayActive = false;
foreach ($policyRows as $row) {
    $metin = strtoupper(trim((string) ($row['metin_deger'] ?? '')));
    $kod = (string) ($row['parametre_kodu'] ?? '');
    if ($kod === 'TATIL_FSC_FM_CAKISMA_HESAP_MODU' && $metin === 'YARGITAY_7_5_SAAT_AYRIMI') {
        $yargitayActive = true;
    }
    if ($metin === 'YARGITAY_7_5_SAAT_AYRIMI') {
        $yargitayActive = true;
    }
}

echo json_encode([
    'ok' => true,
    'code' => 'S87C_READONLY_INVENTORY_OK',
    'read_only' => true,
    'phase' => 'S87-C',
    'db' => s87c_scalar($pdo, 'SELECT DATABASE()'),
    'db_now' => s87c_scalar($pdo, 'SELECT NOW()'),
    'policy' => [
        'tatil_fsc_fm_rows' => $policyRows,
        'tatil_fsc_fm_row_count' => count($policyRows),
        'yargitay_active_unexpected' => $yargitayActive,
    ],
    'migration' => [
        'history_tables_present' => $migrationTables,
        '038_evidence_tables' => $migration038Evidence,
        '039_evidence' => $migration039Evidence,
        '039_applied_suspected' => in_array(true, array_values($migration039Evidence), true),
    ],
    'counts' => $counts,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
