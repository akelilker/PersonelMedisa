<?php
/**
 * ONE-SHOT S84-R2 production accept:
 * identity + preflight + backup + migrate 035 + P-0001/P-0002 dry-run + snapshot integrity.
 * No HARIC write. No snapshot cancel/create. No candidate.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S84R2_ACCEPT_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
if (
    strpos($tokenExpected, 'REPLACE_') === 0
    || $tokenExpected === 'UNSET_S84R2_ACCEPT_TOKEN'
    || $tokenProvided === ''
    || !hash_equals($tokenExpected, $tokenProvided)
) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'identity';
$apiRoot = dirname(__DIR__);
$migrationFile = __DIR__ . '/035_personel_bordro_kapsamlari.sql';
$expectedMigrationSha = 'REPLACE_S84R2_MIGRATION_SHA256';
$expectedDb = 'karmotor_medisa';
$expectedSnapshotHash = '0ec67db7834c2f4a1afa3869927bc06041f707a099af1c64bcac74e99f38c7ee';

$configCandidates = [
    $apiRoot . '/config.local.php',
    $apiRoot . '/src/Config/config.local.php',
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

function s84r2_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_host' => (string) $pdo->query('SELECT @@hostname')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
    ];
}

function s84r2_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function s84r2_counts(PDO $pdo): array
{
    $out = [
        'personeller' => (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn(),
        'maas_hesaplama_donem_snapshotlari' => s84r2_table_exists($pdo, 'maas_hesaplama_donem_snapshotlari')
            ? (int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn()
            : null,
        'personel_bordro_kapsamlari' => s84r2_table_exists($pdo, 'personel_bordro_kapsamlari')
            ? (int) $pdo->query('SELECT COUNT(*) FROM personel_bordro_kapsamlari')->fetchColumn()
            : 0,
        'personel_bordro_kapsam_auditleri' => s84r2_table_exists($pdo, 'personel_bordro_kapsam_auditleri')
            ? (int) $pdo->query('SELECT COUNT(*) FROM personel_bordro_kapsam_auditleri')->fetchColumn()
            : 0,
    ];

    return $out;
}

function s84r2_snapshot1(PDO $pdo): ?array
{
    if (!s84r2_table_exists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
        return null;
    }
    $stmt = $pdo->query(
        "SELECT id, sube_id, yil, ay, revision_no, state, snapshot_hash, source_hash
         FROM maas_hesaplama_donem_snapshotlari WHERE id = 1 LIMIT 1"
    );
    $row = $stmt->fetch();

    return $row ?: null;
}

function s84r2_find_personel(PDO $pdo, string $sicil): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, sicil_no, ad, soyad, sube_id, aktif_durum
         FROM personeller WHERE sicil_no = :sicil LIMIT 1'
    );
    $stmt->execute(['sicil' => $sicil]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function s84r2_respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'identity') {
    $identity = s84r2_identity($pdo);
    $ok = $identity['aktif_veritabani'] === $expectedDb;
    s84r2_respond([
        'ok' => $ok,
        'code' => $ok ? 'PRODUCTION_DB_IDENTITY_OK' : 'PRODUCTION_DB_IDENTITY_MISMATCH',
        'identity' => $identity,
        'expected_db' => $expectedDb,
    ], $ok ? 200 : 409);
}

if ($action === 'preflight') {
    $identity = s84r2_identity($pdo);
    if ($identity['aktif_veritabani'] !== $expectedDb) {
        s84r2_respond(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], 409);
    }
    if (!is_file($migrationFile)) {
        s84r2_respond(['ok' => false, 'code' => 'MIGRATION_FILE_MISSING'], 500);
    }
    $sql = file_get_contents($migrationFile);
    $sha = hash('sha256', $sql);
    $hashOk = strpos($expectedMigrationSha, 'REPLACE_') !== 0 && hash_equals($expectedMigrationSha, $sha);
    $already = s84r2_table_exists($pdo, 'personel_bordro_kapsamlari')
        && s84r2_table_exists($pdo, 'personel_bordro_kapsam_auditleri');
    $snapshot = s84r2_snapshot1($pdo);
    $snapOk = $snapshot !== null && (string) $snapshot['snapshot_hash'] === $expectedSnapshotHash;
    s84r2_respond([
        'ok' => $hashOk && $snapOk,
        'code' => $hashOk && $snapOk ? 'S84R2_PREFLIGHT_OK' : 'S84R2_PREFLIGHT_FAILED',
        'already_applied' => $already,
        'migration_sha256' => $sha,
        'expected_migration_sha256' => $expectedMigrationSha,
        'migration_hash_ok' => $hashOk,
        'snapshot_1' => $snapshot,
        'snapshot_hash_ok' => $snapOk,
        'counts' => s84r2_counts($pdo),
        'identity' => $identity,
    ], $hashOk && $snapOk ? 200 : 409);
}

if ($action === 'backup') {
    $identity = s84r2_identity($pdo);
    if ($identity['aktif_veritabani'] !== $expectedDb) {
        s84r2_respond(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH'], 409);
    }
    $backupName = 'karmotor_medisa_pre_s84r2_035_' . date('Ymd_His') . '.sql';
    $backupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $backupName;
    $lines = [
        '-- S84-R2 pre-migrate backup (schema + integrity snapshot)',
        '-- generated_at=' . gmdate('c'),
        'SET NAMES utf8mb4;',
    ];
    foreach (['personel_bordro_kapsamlari', 'personel_bordro_kapsam_auditleri'] as $table) {
        if (s84r2_table_exists($pdo, $table)) {
            $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch();
            $lines[] = $create['Create Table'] . ';';
        } else {
            $lines[] = '-- table missing (expected before migrate): ' . $table;
        }
    }
    $snap = s84r2_snapshot1($pdo);
    $lines[] = '-- snapshot_1=' . json_encode($snap, JSON_UNESCAPED_UNICODE);
    $lines[] = '-- counts=' . json_encode(s84r2_counts($pdo), JSON_UNESCAPED_UNICODE);
    foreach (['P-0001', 'P-0002'] as $sicil) {
        $p = s84r2_find_personel($pdo, $sicil);
        $lines[] = '-- personel ' . $sicil . '=' . json_encode($p, JSON_UNESCAPED_UNICODE);
    }
    file_put_contents($backupPath, implode("\n", $lines) . "\n");
    // Stash path for download_backup within same request lifetime is not possible across requests;
    // return inline content hash + store under api/public temp with tokenized name for same ops window.
    $publicBackup = __DIR__ . '/_s84r2_backup_tmp.sql';
    copy($backupPath, $publicBackup);
    s84r2_respond([
        'ok' => true,
        'code' => 'S84R2_BACKUP_OK',
        'backup' => [
            'file' => $backupName,
            'bytes' => filesize($backupPath),
            'sha256' => hash_file('sha256', $backupPath),
            'public_tmp' => '_s84r2_backup_tmp.sql',
        ],
        'identity' => $identity,
    ]);
}

if ($action === 'download_backup') {
    $publicBackup = __DIR__ . '/_s84r2_backup_tmp.sql';
    if (!is_file($publicBackup)) {
        s84r2_respond(['ok' => false, 'code' => 'BACKUP_TMP_MISSING'], 404);
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="karmotor_medisa_pre_s84r2_035.sql"');
    readfile($publicBackup);
    exit;
}

if ($action === 'migrate') {
    $identity = s84r2_identity($pdo);
    if ($identity['aktif_veritabani'] !== $expectedDb) {
        s84r2_respond(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH'], 409);
    }
    if (!is_file($migrationFile)) {
        s84r2_respond(['ok' => false, 'code' => 'MIGRATION_FILE_MISSING'], 500);
    }
    $sql = file_get_contents($migrationFile);
    $sha = hash('sha256', $sql);
    if (strpos($expectedMigrationSha, 'REPLACE_') === 0 || !hash_equals($expectedMigrationSha, $sha)) {
        s84r2_respond(['ok' => false, 'code' => 'MIGRATION_HASH_MISMATCH', 'got' => $sha, 'expected' => $expectedMigrationSha], 409);
    }
    $already = s84r2_table_exists($pdo, 'personel_bordro_kapsamlari')
        && s84r2_table_exists($pdo, 'personel_bordro_kapsam_auditleri');
    if ($already) {
        s84r2_respond([
            'ok' => true,
            'code' => 'S84R2_MIGRATE_ALREADY_APPLIED',
            'already_applied' => true,
            'counts' => s84r2_counts($pdo),
            'snapshot_1' => s84r2_snapshot1($pdo),
        ]);
    }
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        s84r2_respond(['ok' => false, 'code' => 'MIGRATE_FAILED', 'message' => $e->getMessage()], 500);
    }
    $applied = s84r2_table_exists($pdo, 'personel_bordro_kapsamlari')
        && s84r2_table_exists($pdo, 'personel_bordro_kapsam_auditleri');
    s84r2_respond([
        'ok' => $applied,
        'code' => $applied ? 'S84R2_MIGRATE_OK' : 'S84R2_MIGRATE_INCOMPLETE',
        'already_applied' => false,
        'migration_sha256' => $sha,
        'counts' => s84r2_counts($pdo),
        'snapshot_1' => s84r2_snapshot1($pdo),
    ], $applied ? 200 : 500);
}

if ($action === 'dry_run') {
    $identity = s84r2_identity($pdo);
    if ($identity['aktif_veritabani'] !== $expectedDb) {
        s84r2_respond(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH'], 409);
    }
    require $apiRoot . '/src/bootstrap.php';
    $beforeSnap = s84r2_snapshot1($pdo);
    $beforeCounts = s84r2_counts($pdo);
    $results = [];
    foreach (['P-0001', 'P-0002'] as $sicil) {
        $personel = s84r2_find_personel($pdo, $sicil);
        if ($personel === null) {
            $results[$sicil] = ['ok' => false, 'error' => 'PERSONEL_NOT_FOUND'];
            continue;
        }
        $payload = [
            'personel_id' => (int) $personel['id'],
            'durum' => 'HARIC',
            'neden_kodu' => 'DEMO_TEST_VERISI',
            'aciklama' => 'S84-R2 production dry-run preview only; no write',
            'gecerlilik_baslangic' => '2026-03-01',
            'gecerlilik_bitis' => null,
            'yil' => 2026,
            'ay' => 3,
            'preview_state' => 'ONAYLANDI',
        ];
        $user = ['id' => 0, 'rol' => 'GENEL_YONETICI'];
        $preview = \Medisa\Api\Services\PersonelBordroKapsamService::dryRun($pdo, $payload, $user);
        $results[$sicil] = [
            'ok' => true,
            'personel' => [
                'sicil_no' => (string) $personel['sicil_no'],
                'ad_soyad' => trim((string) $personel['ad'] . ' ' . (string) $personel['soyad']),
                'sube_id' => (int) $personel['sube_id'],
            ],
            'dry_run' => $preview,
        ];
    }
    $afterSnap = s84r2_snapshot1($pdo);
    $afterCounts = s84r2_counts($pdo);
    $unchanged = $beforeSnap
        && $afterSnap
        && (string) $beforeSnap['snapshot_hash'] === (string) $afterSnap['snapshot_hash']
        && (string) $beforeSnap['source_hash'] === (string) $afterSnap['source_hash']
        && (string) $afterSnap['snapshot_hash'] === $expectedSnapshotHash
        && (int) $beforeCounts['personel_bordro_kapsamlari'] === (int) $afterCounts['personel_bordro_kapsamlari']
        && (int) $beforeCounts['personel_bordro_kapsam_auditleri'] === (int) $afterCounts['personel_bordro_kapsam_auditleri'];

    $p1 = $results['P-0001']['dry_run']['effects'] ?? [];
    $p2 = $results['P-0002']['dry_run']['effects'] ?? [];
    $expectations = [
        'p0001_would_exclude' => !empty($p1['would_exclude_from_new_snapshot']),
        'p0001_snapshot_unchanged_flag' => !empty($p1['existing_snapshot_unchanged']),
        'p0001_revision_required' => !empty($p1['explicit_snapshot_revision_required']),
        'p0001_source_hash_would_change' => !empty($p1['source_hash_would_change']),
        'p0001_carryover_suppressed' => !empty($p1['carryover_blocker_suppressed']),
        'p0002_would_exclude' => !empty($p2['would_exclude_from_new_snapshot']),
        'p0002_no_active_snapshot' => empty($p2['existing_snapshot']),
        'p0002_revision_not_required' => empty($p2['explicit_snapshot_revision_required']),
        'write_performed_false' => (($results['P-0001']['dry_run']['write_performed'] ?? true) === false)
            && (($results['P-0002']['dry_run']['write_performed'] ?? true) === false),
        'integrity_unchanged' => $unchanged,
    ];
    $allOk = !in_array(false, $expectations, true)
        && ($results['P-0001']['ok'] ?? false)
        && ($results['P-0002']['ok'] ?? false);

    s84r2_respond([
        'ok' => $allOk,
        'code' => $allOk ? 'S84R2_DRY_RUN_ACCEPT_OK' : 'S84R2_DRY_RUN_ACCEPT_FAILED',
        'write_performed' => false,
        'expectations' => $expectations,
        'results' => $results,
        'snapshot_1_before' => $beforeSnap,
        'snapshot_1_after' => $afterSnap,
        'counts_before' => $beforeCounts,
        'counts_after' => $afterCounts,
        'identity' => $identity,
    ], $allOk ? 200 : 409);
}

if ($action === 'integrity') {
    $snap = s84r2_snapshot1($pdo);
    $ok = $snap !== null && (string) $snap['snapshot_hash'] === $expectedSnapshotHash;
    s84r2_respond([
        'ok' => $ok,
        'code' => $ok ? 'S84R2_INTEGRITY_OK' : 'S84R2_INTEGRITY_FAILED',
        'snapshot_1' => $snap,
        'counts' => s84r2_counts($pdo),
        'identity' => s84r2_identity($pdo),
    ], $ok ? 200 : 409);
}

s84r2_respond(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], 400);
