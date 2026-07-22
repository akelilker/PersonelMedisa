<?php
/**
 * ONE-SHOT S88-B live migrate for 039_ubgt_gun_kapsami_tatil_takvimi.sql only.
 * Uploaded temporarily to api/public/, executed via HTTPS, then deleted.
 * No seed, no policy write, no puantaj/backfill. UTF-8 without BOM.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$tokenExpected = 'REPLACE_S88B_MIGRATE_TOKEN';
$tokenProvided = isset($_GET['token']) ? (string) $_GET['token'] : '';
// Sentinel must stay literally "UNSET_S88B_MIGRATE_TOKEN" after token injection.
if ($tokenExpected === 'UNSET_S88B_MIGRATE_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'identity';
$expected039 = 'a803b757ff9ddeae29937bb6ee86f54e6064fd4499543b0240da788ae7eb2d73';
$migrationFile = '039_ubgt_gun_kapsami_tatil_takvimi.sql';

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

function s88_identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
        'charset' => (string) $pdo->query('SELECT @@character_set_database')->fetchColumn(),
        'collation' => (string) $pdo->query('SELECT @@collation_database')->fetchColumn(),
    ];
}

function s88_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function s88_count(PDO $pdo, string $table): int
{
    if (!s88_table_exists($pdo, $table)) {
        return -1;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`')->fetchColumn();
}

function s88_quote_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function s88_sql_literal($value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

function s88_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function s88_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i"
    );
    $stmt->execute(['t' => $table, 'i' => $index]);

    return (int) $stmt->fetchColumn() > 0;
}

/** @return array<int, array<string, mixed>> */
function s88_fk_rules(PDO $pdo, string $table, string $constraintName): array
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
function s88_check_constraints(PDO $pdo, string $table): array
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
function s88_projection_columns(): array
{
    return [
        'tatil_takvim_id',
        'tatil_turu',
        'tatil_gun_kapsami',
        'tatil_interval_baslangic',
        'tatil_interval_bitis',
        'tatil_siniflandirma_durumu',
        'tatil_snapshot_hash',
        'tatil_kaynak_referansi',
        'tatil_donemi_brut_calisma_dakika',
        'tatil_donemi_ara_dinlenme_dakika',
        'tatil_donemi_net_calisma_dakika',
    ];
}

/** @return array<string, mixed> */
function s88_counts(PDO $pdo): array
{
    return [
        'personeller' => s88_count($pdo, 'personeller'),
        'gunluk_puantaj' => s88_count($pdo, 'gunluk_puantaj'),
        'puantaj_aylik_muhur_satirlari' => s88_count($pdo, 'puantaj_aylik_muhur_satirlari'),
        'puantaj_aylik_muhurler' => s88_count($pdo, 'puantaj_aylik_muhurler'),
        'maas_hesaplama_snapshotlari' => s88_count($pdo, 'maas_hesaplama_snapshotlari'),
        'resmi_tatil_takvimi' => s88_count($pdo, 'resmi_tatil_takvimi'),
        'resmi_tatil_takvim_auditleri' => s88_count($pdo, 'resmi_tatil_takvim_auditleri'),
    ];
}

/** @return array<string, mixed> */
function s88_policy_probe(PDO $pdo): array
{
    $hasPolicy = s88_table_exists($pdo, 'sirket_calisma_politikalari');
    $hasValues = s88_table_exists($pdo, 'sirket_calisma_politika_degerleri');
    $activeHoliday = [];
    $yargitayWrites = [];
    if ($hasPolicy && $hasValues) {
        $stmt = $pdo->query(
            "SELECT p.id, p.state, d.parametre_kodu, d.deger
             FROM sirket_calisma_politikalari p
             INNER JOIN sirket_calisma_politika_degerleri d ON d.politika_id = p.id
             WHERE d.parametre_kodu = 'TATIL_FSC_FM_CAKISMA_HESAP_MODU'
               AND p.state = 'ONAYLANDI'
               AND p.gecerlilik_bitis IS NULL"
        );
        $activeHoliday = $stmt ? $stmt->fetchAll() : [];
        $stmt2 = $pdo->query(
            "SELECT p.id, p.state, d.parametre_kodu, d.deger
             FROM sirket_calisma_politikalari p
             INNER JOIN sirket_calisma_politika_degerleri d ON d.politika_id = p.id
             WHERE d.deger = 'YARGITAY_7_5_SAAT_AYRIMI'
                OR d.parametre_kodu = 'TATIL_FSC_FM_CAKISMA_HESAP_MODU'"
        );
        $yargitayWrites = $stmt2 ? $stmt2->fetchAll() : [];
    }

    return [
        'policy_tables_exist' => $hasPolicy && $hasValues,
        'active_tatil_fsc_fm_rows' => $activeHoliday,
        'related_policy_rows' => $yargitayWrites,
        'active_tatil_fsc_fm_count' => count($activeHoliday),
    ];
}

/** @return array<string, mixed> */
function s88_preflight(PDO $pdo): array
{
    $tables = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string) $row[0];
    }
    $hasTakvim = s88_table_exists($pdo, 'resmi_tatil_takvimi');
    $hasAudit = s88_table_exists($pdo, 'resmi_tatil_takvim_auditleri');
    $has038 = s88_table_exists($pdo, 'personel_belge_dosya_surumleri');
    $has040 = false;
    foreach ($tables as $t) {
        if (stripos($t, '040') !== false) {
            $has040 = true;
        }
    }
    $projGp = [];
    $projMs = [];
    foreach (s88_projection_columns() as $col) {
        $projGp[$col] = s88_column_exists($pdo, 'gunluk_puantaj', $col);
        $projMs[$col] = s88_column_exists($pdo, 'puantaj_aylik_muhur_satirlari', $col);
    }
    $anyProj = in_array(true, $projGp, true) || in_array(true, $projMs, true);
    $allProj = !in_array(false, $projGp, true) && !in_array(false, $projMs, true);

    return [
        'table_count' => count($tables),
        'personel_belge_038_present' => $has038,
        'migration_040_trace' => $has040,
        'resmi_tatil_takvimi_exists' => $hasTakvim,
        'resmi_tatil_takvim_auditleri_exists' => $hasAudit,
        'projection_columns_gunluk_puantaj' => $projGp,
        'projection_columns_muhur' => $projMs,
        'counts' => s88_counts($pdo),
        'policy_probe' => s88_policy_probe($pdo),
        'already_applied' => $hasTakvim && $hasAudit && $allProj,
        'partial_039_trace' => ($hasTakvim xor $hasAudit) || ($anyProj && !$allProj),
        'fresh_apply_ready' => !$hasTakvim && !$hasAudit && !$anyProj && $has038,
    ];
}

function s88_php_sql_dump(PDO $pdo, string $dbName): string
{
    $out = [];
    $out[] = '-- S88-B PHP SQL dump (shared-host fallback; restoreable)';
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
        $create = $pdo->query('SHOW CREATE TABLE ' . s88_quote_ident($table))->fetch();
        $createSql = (string) ($create['Create Table'] ?? '');
        $out[] = 'DROP TABLE IF EXISTS ' . s88_quote_ident($table) . ';';
        $out[] = $createSql . ';';
        $out[] = '';

        $rows = $pdo->query('SELECT * FROM ' . s88_quote_ident($table))->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            continue;
        }
        $cols = array_map('s88_quote_ident', array_keys($rows[0]));
        $colList = '(' . implode(', ', $cols) . ')';
        foreach (array_chunk($rows, 50) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = s88_sql_literal($v);
                }
                $values[] = '(' . implode(', ', $vals) . ')';
            }
            $out[] = 'INSERT INTO ' . s88_quote_ident($table) . ' ' . $colList . ' VALUES';
            $out[] = implode(",\n", $values) . ';';
            $out[] = '';
        }
    }

    $triggers = $pdo->query(
        "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($triggers as $triggerName) {
        $row = $pdo->query('SHOW CREATE TRIGGER ' . s88_quote_ident((string) $triggerName))->fetch();
        $sql = (string) ($row['SQL Original Statement'] ?? $row['Create Trigger'] ?? '');
        if ($sql !== '') {
            $out[] = 'DROP TRIGGER IF EXISTS ' . s88_quote_ident((string) $triggerName) . ';';
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
        $row = $pdo->query($show . s88_quote_ident($rName))->fetch();
        $key = $rType === 'FUNCTION' ? 'Create Function' : 'Create Procedure';
        $sql = (string) ($row[$key] ?? '');
        if ($sql !== '') {
            $out[] = 'DROP ' . $rType . ' IF EXISTS ' . s88_quote_ident($rName) . ';';
            $out[] = $sql . ';';
            $out[] = '';
        }
    }

    $events = $pdo->query(
        "SELECT EVENT_NAME FROM information_schema.EVENTS
         WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($events as $eventName) {
        $row = $pdo->query('SHOW CREATE EVENT ' . s88_quote_ident((string) $eventName))->fetch();
        $sql = (string) ($row['Create Event'] ?? '');
        if ($sql !== '') {
            $out[] = 'DROP EVENT IF EXISTS ' . s88_quote_ident((string) $eventName) . ';';
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

function s88_backup_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $stamp = gmdate('Ymd_His');
    $path = __DIR__ . '/karmotor_medisa_pre_039_' . $stamp . '.sql';

    return $path;
}

/** @return array<int, string> */
function s88_split_sql(string $sql): array
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

function s88_apply_039(PDO $pdo, string $file, string $expectedSha): array
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
    foreach (s88_split_sql($sql) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }

    return ['file' => basename($file), 'sha256' => $sha, 'statements' => $count];
}

/** @return array<string, mixed> */
function s88_postcheck(PDO $pdo, array $beforeCounts): array
{
    $hasTakvim = s88_table_exists($pdo, 'resmi_tatil_takvimi');
    $hasAudit = s88_table_exists($pdo, 'resmi_tatil_takvim_auditleri');
    $projGp = [];
    $projMs = [];
    foreach (s88_projection_columns() as $col) {
        $projGp[$col] = s88_column_exists($pdo, 'gunluk_puantaj', $col);
        $projMs[$col] = s88_column_exists($pdo, 'puantaj_aylik_muhur_satirlari', $col);
    }
    $after = s88_counts($pdo);
    $auditFk = s88_fk_rules($pdo, 'resmi_tatil_takvim_auditleri', 'fk_rtta_kayit');
    $auditDelete = strtoupper((string) ($auditFk[0]['DELETE_RULE'] ?? ''));
    $gpFk = s88_fk_rules($pdo, 'gunluk_puantaj', 'fk_gp_tatil_takvim');
    $msFk = s88_fk_rules($pdo, 'puantaj_aylik_muhur_satirlari', 'fk_pams_tatil_takvim');
    $revFk = s88_fk_rules($pdo, 'resmi_tatil_takvimi', 'fk_rtt_onceki');
    $checksRtt = s88_check_constraints($pdo, 'resmi_tatil_takvimi');
    $checksAudit = s88_check_constraints($pdo, 'resmi_tatil_takvim_auditleri');
    $checksGp = s88_check_constraints($pdo, 'gunluk_puantaj');
    $checksMs = s88_check_constraints($pdo, 'puantaj_aylik_muhur_satirlari');

    $nonNullProjGp = 0;
    $nonNullProjMs = 0;
    if ($hasTakvim && s88_column_exists($pdo, 'gunluk_puantaj', 'tatil_takvim_id')) {
        $nonNullProjGp = (int) $pdo->query(
            'SELECT COUNT(*) FROM gunluk_puantaj WHERE tatil_takvim_id IS NOT NULL OR tatil_turu IS NOT NULL OR tatil_siniflandirma_durumu IS NOT NULL'
        )->fetchColumn();
    }
    if ($hasTakvim && s88_column_exists($pdo, 'puantaj_aylik_muhur_satirlari', 'tatil_takvim_id')) {
        $nonNullProjMs = (int) $pdo->query(
            'SELECT COUNT(*) FROM puantaj_aylik_muhur_satirlari WHERE tatil_takvim_id IS NOT NULL OR tatil_turu IS NOT NULL OR tatil_siniflandirma_durumu IS NOT NULL'
        )->fetchColumn();
    }

    $requiredGpChecks = [
        'chk_gp_tatil_turu', 'chk_gp_tatil_gun_kapsami', 'chk_gp_tatil_sinif',
        'chk_gp_tatil_hash', 'chk_gp_tatil_dakika', 'chk_gp_tatil_interval',
    ];
    $requiredMsChecks = [
        'chk_pams_tatil_turu', 'chk_pams_tatil_gun_kapsami', 'chk_pams_tatil_sinif',
        'chk_pams_tatil_hash', 'chk_pams_tatil_dakika', 'chk_pams_tatil_interval',
    ];
    $requiredRttChecks = ['chk_rtt_interval_kapsam', 'chk_rtt_iptal', 'chk_rtt_kaynak_dolu'];

    $countsUnchanged =
        ($beforeCounts['personeller'] ?? null) === $after['personeller']
        && ($beforeCounts['gunluk_puantaj'] ?? null) === $after['gunluk_puantaj']
        && ($beforeCounts['puantaj_aylik_muhur_satirlari'] ?? null) === $after['puantaj_aylik_muhur_satirlari'];

    $ok =
        $hasTakvim
        && $hasAudit
        && !in_array(false, $projGp, true)
        && !in_array(false, $projMs, true)
        && $after['resmi_tatil_takvimi'] === 0
        && $after['resmi_tatil_takvim_auditleri'] === 0
        && $countsUnchanged
        && $nonNullProjGp === 0
        && $nonNullProjMs === 0
        && s88_index_exists($pdo, 'resmi_tatil_takvimi', 'uq_rtt_aktif_ubgt_tarih')
        && count($auditFk) === 1
        && in_array($auditDelete, ['RESTRICT', 'NO ACTION'], true)
        && count($gpFk) === 1
        && count($msFk) === 1
        && count($revFk) === 1
        && count(array_intersect($requiredRttChecks, $checksRtt)) === count($requiredRttChecks)
        && count(array_intersect($requiredGpChecks, $checksGp)) === count($requiredGpChecks)
        && count(array_intersect($requiredMsChecks, $checksMs)) === count($requiredMsChecks)
        && in_array('chk_rtta_request_hash', $checksAudit, true);

    return [
        'ok' => $ok,
        'tables' => [
            'resmi_tatil_takvimi' => $hasTakvim,
            'resmi_tatil_takvim_auditleri' => $hasAudit,
        ],
        'projection_columns_gunluk_puantaj' => $projGp,
        'projection_columns_muhur' => $projMs,
        'indexes' => [
            'uq_rtt_aktif_ubgt_tarih' => s88_index_exists($pdo, 'resmi_tatil_takvimi', 'uq_rtt_aktif_ubgt_tarih'),
            'idx_gp_tatil_sinif' => s88_index_exists($pdo, 'gunluk_puantaj', 'idx_gp_tatil_sinif'),
        ],
        'foreign_keys' => [
            'fk_rtta_kayit' => $auditFk,
            'fk_gp_tatil_takvim' => $gpFk,
            'fk_pams_tatil_takvim' => $msFk,
            'fk_rtt_onceki' => $revFk,
        ],
        'check_constraints' => [
            'resmi_tatil_takvimi' => $checksRtt,
            'resmi_tatil_takvim_auditleri' => $checksAudit,
            'gunluk_puantaj' => $checksGp,
            'puantaj_aylik_muhur_satirlari' => $checksMs,
        ],
        'row_counts' => $after,
        'before_counts' => $beforeCounts,
        'counts_unchanged' => $countsUnchanged,
        'non_null_projection_rows' => [
            'gunluk_puantaj' => $nonNullProjGp,
            'puantaj_aylik_muhur_satirlari' => $nonNullProjMs,
        ],
        'policy_probe' => s88_policy_probe($pdo),
    ];
}

if ($action === 'identity') {
    $identity = s88_identity($pdo);
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
    $identity = s88_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = s88_preflight($pdo);
    $code = 'S88B_PREFLIGHT_OK';
    if (!$pre['personel_belge_038_present']) {
        $code = 'S88B_PREFLIGHT_038_MISSING';
    } elseif ($pre['partial_039_trace']) {
        $code = 'S88B_PREFLIGHT_PARTIAL_039_TRACE';
    } elseif ($pre['already_applied']) {
        $code = 'S88B_ALREADY_APPLIED';
    }

    $ok = in_array($code, ['S88B_PREFLIGHT_OK', 'S88B_ALREADY_APPLIED'], true);
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
    $identity = s88_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $backupPath = s88_backup_path();
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
        $sql = s88_php_sql_dump($pdo, $name);
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

    file_put_contents(__DIR__ . '/s88b_latest_backup_path.txt', basename($backupPath));

    $ok =
        $meta['bytes'] > 0
        && $meta['contains_create_table']
        && $meta['contains_insert']
        && $meta['contains_personeller_insert']
        && $meta['contains_gunluk_puantaj_insert']
        && $meta['contains_commit'];

    echo json_encode([
        'ok' => $ok,
        'code' => $ok ? 'S88B_BACKUP_OK' : 'S88B_BACKUP_INCOMPLETE',
        'backup' => $meta,
        'identity' => $identity,
        'preflight' => s88_preflight($pdo),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'download_backup') {
    $marker = __DIR__ . '/s88b_latest_backup_path.txt';
    $backupPath = '';
    if (is_file($marker)) {
        $base = basename(trim((string) file_get_contents($marker)));
        if ($base !== '' && is_file(__DIR__ . '/' . $base)) {
            $backupPath = __DIR__ . '/' . $base;
        }
    }
    if ($backupPath === '') {
        $matches = glob(__DIR__ . '/karmotor_medisa_pre_039_*.sql') ?: [];
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
    $identity = s88_identity($pdo);
    if ($identity['aktif_veritabani'] !== 'karmotor_medisa') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'PRODUCTION_DB_IDENTITY_MISMATCH', 'identity' => $identity], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pre = s88_preflight($pdo);
    if (!$pre['personel_belge_038_present']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S88B_PREFLIGHT_038_MISSING', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['partial_039_trace']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S88B_PREFLIGHT_PARTIAL_039_TRACE', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($pre['already_applied']) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'code' => 'S88B_ALREADY_APPLIED', 'preflight' => $pre], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $before = $pre['counts'];
    $path = __DIR__ . '/' . $migrationFile;
    $pdo->exec('SET NAMES utf8mb4');
    $pdo->exec("SET time_zone = '+00:00'");

    try {
        $applied = s88_apply_039($pdo, $path, $expected039);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'code' => 'S88B_MIGRATE_FAILED',
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $post = s88_postcheck($pdo, $before);
    echo json_encode([
        'ok' => $post['ok'],
        'code' => $post['ok'] ? 'S88B_MIGRATE_OK' : 'S88B_MIGRATE_POSTCHECK_FAILED',
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
    $check = s88_postcheck($pdo, $before);
    echo json_encode([
        'ok' => $check['ok'],
        'code' => $check['ok'] ? 'S88B_POSTCHECK_OK' : 'S88B_POSTCHECK_FAILED',
        'identity' => s88_identity($pdo),
        'postcheck' => $check,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'UNKNOWN_ACTION', 'action' => $action], JSON_UNESCAPED_UNICODE);
