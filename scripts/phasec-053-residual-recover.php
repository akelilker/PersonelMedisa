<?php
/**
 * Phase C 053 residual recovery helper (OPS ONLY — not product code).
 * Applies ONLY missing 053 DDL after partial production apply.
 * No PREPARE/EXECUTE/DEALLOCATE via PDO::exec. One statement per PDO::exec.
 * UTF-8 without BOM.
 */
declare(strict_types=1);

$cli = (PHP_SAPI === 'cli');

if (!$cli) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

$tokenExpected = 'REPLACE_PHASEC053_RESIDUAL_TOKEN';
$tokenProvided = $cli
    ? (string) (getenv('PHASEC053_RESIDUAL_TOKEN') ?: '')
    : (isset($_GET['token']) ? (string) $_GET['token'] : '');
$action = $cli
    ? (string) (getenv('PHASEC053_ACTION') ?: ($argv[1] ?? 'identity'))
    : (isset($_GET['action']) ? (string) $_GET['action'] : 'identity');

if ($tokenExpected === 'UNSET_PHASEC053_RESIDUAL_TOKEN' || $tokenProvided === '' || !hash_equals($tokenExpected, $tokenProvided)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
    exit(1);
}

$expected053 = '5969a777ffd9d59f315139c57b86ee76402084943ce97f26a97f11521947d1af';
$expectedTables = [
    'arsiv_manifestleri',
    'legal_holdlar',
    'legal_hold_auditleri',
    'arsiv_erisim_auditleri',
    'retention_imha_talepleri',
    'retention_imha_auditleri',
];
$missingTables = [
    'legal_holdlar',
    'legal_hold_auditleri',
    'arsiv_erisim_auditleri',
    'retention_imha_talepleri',
    'retention_imha_auditleri',
];
$requiredRoles = [
    'GENEL_YONETICI', 'MUHASEBE', 'BIRIM_AMIRI', 'BOLUM_YONETICISI', 'PATRON',
    'AUTH_SMOKE_READONLY', 'IK_BORDRO', 'SGK_KARAR_ONAY_YETKILISI',
    'IDARI_ISLER', 'SISTEM_YONETICISI',
];
$snapCols = [
    'retention_until_snapshot', 'source_identity_snapshot', 'trigger_type_snapshot',
    'trigger_date_snapshot', 'source_version_identity_snapshot', 'source_sha256_snapshot',
    'canonical_sube_id', 'period_yil', 'period_ay',
];

$assetsPath = __DIR__ . '/phasec-053-residual-assets.json';
if (!is_file($assetsPath)) {
    fail(500, 'ASSETS_MISSING');
}
$assets = json_decode((string) file_get_contents($assetsPath), true);
if (!is_array($assets) || ($assets['sha'] ?? '') !== $expected053) {
    fail(500, 'ASSETS_SHA_MISMATCH');
}

function fail(int $code, string $err, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $err], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(1);
}

function respond(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit(($payload['ok'] ?? false) ? 0 : 1);
}

function connect_pdo(): PDO
{
    $cliDsn = getenv('PHASEC053_DSN');
    if (is_string($cliDsn) && $cliDsn !== '') {
        return new PDO($cliDsn, (string) getenv('PHASEC053_DB_USER'), (string) getenv('PHASEC053_DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
    }
    $configCandidates = [
        dirname(__DIR__) . '/config.local.php',
        dirname(__DIR__) . '/src/Config/config.local.php',
        __DIR__ . '/config.local.php',
    ];
    $config = null;
    foreach ($configCandidates as $path) {
        if (is_file($path)) {
            $config = require $path;
            break;
        }
    }
    if (!is_array($config)) {
        fail(500, 'CONFIG_MISSING');
    }
    $host = (string) ($config['db_host'] ?? 'localhost');
    $name = (string) ($config['db_name'] ?? '');
    $user = (string) ($config['db_user'] ?? '');
    $pass = (string) ($config['db_password'] ?? '');
    if ($name === '' || $user === '') {
        fail(500, 'DB_CONFIG_INCOMPLETE');
    }
    return new PDO(
        'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
}

function qident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $st->execute(['t' => $table]);
    return (int) $st->fetchColumn() === 1;
}

function column_exists(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c');
    $st->execute(['t' => $table, 'c' => $col]);
    return (int) $st->fetchColumn() === 1;
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i');
    $st->execute(['t' => $table, 'i' => $index]);
    return (int) $st->fetchColumn() > 0;
}

function fk_exists(PDO $pdo, string $table, string $name): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = :t AND constraint_name = :n AND constraint_type = 'FOREIGN KEY'");
    $st->execute(['t' => $table, 'n' => $name]);
    return (int) $st->fetchColumn() === 1;
}

function count_table(PDO $pdo, string $table): int
{
    if (!table_exists($pdo, $table)) {
        return -1;
    }
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . qident($table))->fetchColumn();
}

function business_counts(PDO $pdo): array
{
    return [
        'personeller' => count_table($pdo, 'personeller'),
        'gunluk_puantaj' => count_table($pdo, 'gunluk_puantaj'),
        'users' => count_table($pdo, 'users'),
        'surecler' => count_table($pdo, 'surecler'),
    ];
}

function role_distribution(PDO $pdo): array
{
    if (!table_exists($pdo, 'users') || !column_exists($pdo, 'users', 'rol')) {
        return [];
    }
    $rows = $pdo->query('SELECT rol, COUNT(*) AS c FROM users GROUP BY rol ORDER BY rol')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[(string) $r['rol']] = (int) $r['c'];
    }
    return $out;
}

function users_rol_type(PDO $pdo): string
{
    $row = $pdo->query("SHOW COLUMNS FROM users LIKE 'rol'")->fetch();
    return (string) ($row['Type'] ?? '');
}

function identity(PDO $pdo): array
{
    return [
        'aktif_veritabani' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'db_version' => (string) $pdo->query('SELECT @@version')->fetchColumn(),
        'db_now' => (string) $pdo->query('SELECT NOW()')->fetchColumn(),
        'charset' => (string) $pdo->query('SELECT @@character_set_database')->fetchColumn(),
        'collation' => (string) $pdo->query('SELECT @@collation_database')->fetchColumn(),
    ];
}

function manifest_probe(PDO $pdo): array
{
    $cols = [];
    if (table_exists($pdo, 'arsiv_manifestleri')) {
        $st = $pdo->query('SHOW COLUMNS FROM arsiv_manifestleri');
        foreach ($st->fetchAll() as $r) {
            $cols[] = $r['Field'];
        }
    }
    $uniqueCols = [];
    if (index_exists($pdo, 'arsiv_manifestleri', 'uq_arsiv_manifest_entity_cat_src')) {
        $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'arsiv_manifestleri' AND index_name = 'uq_arsiv_manifest_entity_cat_src' ORDER BY SEQ_IN_INDEX");
        $st->execute();
        $uniqueCols = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    $create = null;
    if (table_exists($pdo, 'arsiv_manifestleri')) {
        $create = $pdo->query('SHOW CREATE TABLE arsiv_manifestleri')->fetch();
    }
    return [
        'exists' => table_exists($pdo, 'arsiv_manifestleri'),
        'rows' => count_table($pdo, 'arsiv_manifestleri'),
        'columns' => $cols,
        'has_new_unique' => index_exists($pdo, 'arsiv_manifestleri', 'uq_arsiv_manifest_entity_cat_src'),
        'has_old_unique' => index_exists($pdo, 'arsiv_manifestleri', 'uq_arsiv_manifest_entity_cat'),
        'new_unique_columns' => $uniqueCols,
        'has_idx_personel' => index_exists($pdo, 'arsiv_manifestleri', 'idx_arsiv_manifest_personel'),
        'has_idx_retention' => index_exists($pdo, 'arsiv_manifestleri', 'idx_arsiv_manifest_retention'),
        'has_idx_category' => index_exists($pdo, 'arsiv_manifestleri', 'idx_arsiv_manifest_category'),
        'has_idx_entity_cat' => index_exists($pdo, 'arsiv_manifestleri', 'idx_arsiv_manifest_entity_cat'),
        'show_create' => is_array($create) ? ($create['Create Table'] ?? null) : null,
    ];
}

function schema_presence(PDO $pdo, array $tables): array
{
    $p = [];
    foreach ($tables as $t) {
        $p[$t] = table_exists($pdo, $t);
    }
    return $p;
}

function run_op(PDO $pdo, int $index, string $operation, string $target, string $sql): array
{
    $started = gmdate('c');
    $row = [
        'statement_index' => $index,
        'operation' => $operation,
        'target' => $target,
        'started_at' => $started,
        'finished_at' => null,
        'result' => 'PENDING',
        'SQLSTATE' => null,
        'driver_error_code' => null,
        'message' => null,
    ];
    try {
        $pdo->exec($sql);
        $row['result'] = 'APPLIED';
        $row['finished_at'] = gmdate('c');
    } catch (Throwable $e) {
        $info = $e instanceof PDOException ? $e->errorInfo : [null, null, $e->getMessage()];
        $row['result'] = 'FAILED';
        $row['SQLSTATE'] = $info[0] ?? null;
        $row['driver_error_code'] = $info[1] ?? null;
        $row['message'] = $info[2] ?? $e->getMessage();
        $row['finished_at'] = gmdate('c');
    }
    return $row;
}

try {
    $pdo = connect_pdo();
} catch (Throwable $e) {
    fail(500, 'DB_CONNECT_FAILED', ['message' => $e->getMessage()]);
}

if ($action === 'identity') {
    $id = identity($pdo);
    respond([
        'ok' => $id['aktif_veritabani'] === 'karmotor_medisa' || getenv('PHASEC053_ALLOW_NONPROD') === '1',
        'identity' => $id,
        'expected_db' => 'karmotor_medisa',
    ]);
}

if ($action === 'preflight') {
    $id = identity($pdo);
    $presence = schema_presence($pdo, $expectedTables);
    $manifest = manifest_probe($pdo);
    $rol = users_rol_type($pdo);
    $roleOk = true;
    foreach ($requiredRoles as $r) {
        if (stripos($rol, $r) === false) {
            $roleOk = false;
            break;
        }
    }
    $partialExpected =
        !empty($presence['arsiv_manifestleri'])
        && empty($presence['legal_holdlar'])
        && empty($presence['legal_hold_auditleri'])
        && empty($presence['arsiv_erisim_auditleri'])
        && empty($presence['retention_imha_talepleri'])
        && empty($presence['retention_imha_auditleri']);
    $allPresent = !in_array(false, $presence, true);
    $manifestOk =
        $manifest['exists']
        && $manifest['rows'] === 0
        && $manifest['has_new_unique']
        && !$manifest['has_old_unique']
        && $manifest['new_unique_columns'] === ['entity_type', 'record_id', 'record_category', 'source_version_identity'];
    $expectedCols = [
        'id', 'entity_type', 'record_id', 'personel_id', 'record_category', 'source_version_identity',
        'trigger_type', 'trigger_date', 'retention_until', 'source_sha256', 'integrity_status', 'created_at', 'created_by',
    ];
    $colsOk = empty(array_diff($expectedCols, $manifest['columns'] ?? []));
    $ok = ($partialExpected || $allPresent) && $roleOk && $manifestOk && $colsOk;
    $code = $allPresent ? 'ALREADY_COMPLETE' : ($partialExpected && $manifestOk && $colsOk && $roleOk ? 'PARTIAL_READY_FOR_RESIDUAL' : 'UNEXPECTED_STATE');
    respond([
        'ok' => $ok && $code !== 'UNEXPECTED_STATE',
        'code' => $code,
        'identity' => $id,
        'business_counts' => business_counts($pdo),
        'user_role_distribution' => role_distribution($pdo),
        'users_rol_type' => $rol,
        'role_enum_ok' => $roleOk,
        'table_presence' => $presence,
        'manifest' => $manifest,
        'manifest_columns_ok' => $colsOk,
        'manifest_unique_ok' => $manifestOk,
        'partial_expected' => $partialExpected,
        'already_complete' => $allPresent,
    ], $ok && $code !== 'UNEXPECTED_STATE' ? 200 : 409);
}

if ($action === 'backup') {
    // Lightweight php_sql_dump of all tables (structure+data)
    $id = identity($pdo);
    $stamp = gmdate('Ymd_His');
    $fname = 'karmotor_medisa_partial_053_pre_residual_' . $stamp . '.sql';
    $path = __DIR__ . '/' . $fname;
    $out = [];
    $out[] = '-- phasec053 residual pre-backup';
    $out[] = '-- db=' . $id['aktif_veritabani'];
    $out[] = '-- version=' . $id['db_version'];
    $out[] = '-- created_at_utc=' . gmdate('c');
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_NUM);
    foreach ($tables as $row) {
        $t = $row[0];
        $create = $pdo->query('SHOW CREATE TABLE ' . qident($t))->fetch();
        $out[] = 'DROP TABLE IF EXISTS ' . qident($t) . ';';
        $out[] = ($create['Create Table'] ?? '') . ';';
        $rows = $pdo->query('SELECT * FROM ' . qident($t))->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cols = array_map('qident', array_keys($r));
            $vals = [];
            foreach ($r as $v) {
                if ($v === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = $pdo->quote((string) $v);
                }
            }
            $out[] = 'INSERT INTO ' . qident($t) . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ');';
        }
    }
    $out[] = 'SET FOREIGN_KEY_CHECKS=1;';
    $sqlDump = implode("\n", $out) . "\n";
    file_put_contents($path, $sqlDump);
    respond([
        'ok' => true,
        'backup_filename' => $fname,
        'backup_bytes' => strlen($sqlDump),
        'backup_sha256' => hash('sha256', $sqlDump),
        'backup_created_at' => gmdate('c'),
        'backup_tool' => 'php_sql_dump',
        'identity' => $id,
    ]);
}

if ($action === 'download_backup') {
    $fname = isset($_GET['file']) ? basename((string) $_GET['file']) : '';
    if ($fname === '' || !preg_match('/^karmotor_medisa_partial_053_pre_residual_\d{8}_\d{6}\.sql$/', $fname)) {
        fail(400, 'BAD_BACKUP_NAME');
    }
    $path = __DIR__ . '/' . $fname;
    if (!is_file($path)) {
        fail(404, 'BACKUP_NOT_FOUND');
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    readfile($path);
    @unlink($path);
    exit(0);
}

if ($action === 'residual') {
    $id = identity($pdo);
    $allowNonprod = getenv('PHASEC053_ALLOW_NONPROD') === '1';
    if ($id['aktif_veritabani'] !== 'karmotor_medisa' && !$allowNonprod) {
        fail(409, 'PRODUCTION_DB_IDENTITY_MISMATCH', ['identity' => $id]);
    }

    $beforeCounts = business_counts($pdo);
    $beforeRoles = role_distribution($pdo);
    $presence = schema_presence($pdo, $expectedTables);
    $manifest = manifest_probe($pdo);

    if (!$manifest['exists'] || $manifest['rows'] !== 0 || !$manifest['has_new_unique'] || $manifest['has_old_unique']) {
        fail(409, 'MANIFEST_STATE_UNSAFE', ['manifest' => $manifest]);
    }
    if (!empty($presence['legal_holdlar']) && !empty($presence['retention_imha_auditleri'])) {
        // already complete path
        respond([
            'ok' => true,
            'code' => 'ALREADY_COMPLETE',
            'skipped' => true,
            'identity' => $id,
        ]);
    }
    foreach ($missingTables as $t) {
        if (!empty($presence[$t])) {
            fail(409, 'UNEXPECTED_PARTIAL_SUBSET', ['table_presence' => $presence]);
        }
    }

    $ops = [];
    $idx = 0;
    $creates = $assets['creates'];
    foreach ($missingTables as $t) {
        $idx++;
        if (!isset($creates[$t])) {
            fail(500, 'CREATE_SQL_MISSING', ['table' => $t]);
        }
        if (table_exists($pdo, $t)) {
            $ops[] = [
                'statement_index' => $idx,
                'operation' => 'CREATE_TABLE',
                'target' => $t,
                'result' => 'SKIPPED_ALREADY_EFFECTIVE',
                'started_at' => gmdate('c'),
                'finished_at' => gmdate('c'),
            ];
            continue;
        }
        $row = run_op($pdo, $idx, 'CREATE_TABLE', $t, $creates[$t]);
        $ops[] = $row;
        if ($row['result'] === 'FAILED') {
            respond([
                'ok' => false,
                'code' => 'RESIDUAL_FAILED',
                'failed' => $row,
                'ops' => $ops,
                'schema_after_failure' => schema_presence($pdo, $expectedTables),
            ], 500);
        }
        if (!table_exists($pdo, $t)) {
            respond([
                'ok' => false,
                'code' => 'CREATE_NOT_VISIBLE',
                'failed' => $row,
                'ops' => $ops,
            ], 500);
        }
    }

    // Snapshot cols should exist via CREATE — verify, do not ALTER
    foreach ($snapCols as $col) {
        $idx++;
        if (column_exists($pdo, 'retention_imha_talepleri', $col)) {
            $ops[] = [
                'statement_index' => $idx,
                'operation' => 'VERIFY_COLUMN',
                'target' => 'retention_imha_talepleri.' . $col,
                'result' => 'SKIPPED_ALREADY_EFFECTIVE',
                'started_at' => gmdate('c'),
                'finished_at' => gmdate('c'),
            ];
        } else {
            respond([
                'ok' => false,
                'code' => 'SNAPSHOT_COLUMN_MISSING_AFTER_CREATE',
                'column' => $col,
                'ops' => $ops,
            ], 500);
        }
    }

    foreach ($assets['fks'] as $fk) {
        $idx++;
        $target = $fk['table'] . '.' . $fk['name'];
        if (!table_exists($pdo, $fk['table'])) {
            respond(['ok' => false, 'code' => 'FK_TARGET_TABLE_MISSING', 'target' => $target, 'ops' => $ops], 500);
        }
        if (!table_exists($pdo, $fk['needs'])) {
            $ops[] = [
                'statement_index' => $idx,
                'operation' => 'ADD_FK',
                'target' => $target,
                'result' => 'SKIPPED_DEPENDENCY_MISSING',
                'started_at' => gmdate('c'),
                'finished_at' => gmdate('c'),
            ];
            continue;
        }
        if (fk_exists($pdo, $fk['table'], $fk['name'])) {
            $ops[] = [
                'statement_index' => $idx,
                'operation' => 'ADD_FK',
                'target' => $target,
                'result' => 'SKIPPED_ALREADY_EFFECTIVE',
                'started_at' => gmdate('c'),
                'finished_at' => gmdate('c'),
            ];
            continue;
        }
        $row = run_op($pdo, $idx, 'ADD_FK', $target, $fk['sql']);
        $ops[] = $row;
        if ($row['result'] === 'FAILED') {
            respond([
                'ok' => false,
                'code' => 'RESIDUAL_FAILED',
                'failed' => $row,
                'ops' => $ops,
                'schema_after_failure' => schema_presence($pdo, $expectedTables),
            ], 500);
        }
    }

    // Postcheck
    $presence2 = schema_presence($pdo, $expectedTables);
    $manifest2 = manifest_probe($pdo);
    $afterCounts = business_counts($pdo);
    $afterRoles = role_distribution($pdo);
    $rol = users_rol_type($pdo);
    $roleOk = true;
    foreach ($requiredRoles as $r) {
        if (stripos($rol, $r) === false) {
            $roleOk = false;
            break;
        }
    }
    $snapOk = true;
    foreach ($snapCols as $col) {
        if (!column_exists($pdo, 'retention_imha_talepleri', $col)) {
            $snapOk = false;
            break;
        }
    }
    $newCounts = [];
    $emptyOk = true;
    foreach ($expectedTables as $t) {
        $c = count_table($pdo, $t);
        $newCounts[$t] = $c;
        if ($c !== 0) {
            $emptyOk = false;
        }
    }
    $allPresent = !in_array(false, $presence2, true);
    $countsUnchanged = $beforeCounts === $afterCounts;
    $rolesUnchanged = $beforeRoles === $afterRoles;
    $ok = $allPresent && $manifest2['has_new_unique'] && !$manifest2['has_old_unique'] && $snapOk && $roleOk && $emptyOk && $countsUnchanged && $rolesUnchanged;

    respond([
        'ok' => $ok,
        'code' => $ok ? 'RESIDUAL_COMPLETE' : 'RESIDUAL_POSTCHECK_FAIL',
        'identity' => $id,
        'ops' => $ops,
        'applied' => count(array_filter($ops, fn ($o) => ($o['result'] ?? '') === 'APPLIED')),
        'skipped' => count(array_filter($ops, function ($o) {
            return strpos((string) ($o['result'] ?? ''), 'SKIPPED') === 0;
        })),
        'table_presence' => $presence2,
        'manifest' => $manifest2,
        'destruction_snapshot_columns_ok' => $snapOk,
        'role_enum_ok' => $roleOk,
        'users_rol_type' => $rol,
        'new_table_counts' => $newCounts,
        'new_tables_empty_ok' => $emptyOk,
        'business_counts_before' => $beforeCounts,
        'business_counts_after' => $afterCounts,
        'business_counts_unchanged' => $countsUnchanged,
        'user_role_distribution_before' => $beforeRoles,
        'user_role_distribution_after' => $afterRoles,
        'user_role_distribution_unchanged' => $rolesUnchanged,
        'authorized_053_sha256' => $expected053,
    ], $ok ? 200 : 500);
}

if ($action === 'postcheck') {
    $presence = schema_presence($pdo, $expectedTables);
    $manifest = manifest_probe($pdo);
    $rol = users_rol_type($pdo);
    $roleOk = true;
    foreach ($requiredRoles as $r) {
        if (stripos($rol, $r) === false) {
            $roleOk = false;
            break;
        }
    }
    $snapOk = true;
    foreach ($snapCols as $col) {
        if (!column_exists($pdo, 'retention_imha_talepleri', $col)) {
            $snapOk = false;
            break;
        }
    }
    $counts = [];
    foreach ($expectedTables as $t) {
        $counts[$t] = count_table($pdo, $t);
    }
    $ok = !in_array(false, $presence, true)
        && $manifest['has_new_unique']
        && !$manifest['has_old_unique']
        && $snapOk
        && $roleOk
        && !in_array(false, array_map(fn ($c) => $c === 0, $counts), true);
    respond([
        'ok' => $ok,
        'table_presence' => $presence,
        'manifest' => $manifest,
        'users_rol_type' => $rol,
        'role_enum_ok' => $roleOk,
        'destruction_snapshot_columns_ok' => $snapOk,
        'new_table_counts' => $counts,
        'business_counts' => business_counts($pdo),
        'user_role_distribution' => role_distribution($pdo),
    ], $ok ? 200 : 409);
}

fail(400, 'UNKNOWN_ACTION', ['action' => $action]);
