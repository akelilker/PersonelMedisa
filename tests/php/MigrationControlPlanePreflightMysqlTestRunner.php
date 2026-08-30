<?php

declare(strict_types=1);

/**
 * Migration 079 control plane — DB-backed acceptance against a real MariaDB.
 *
 * Covers the three owners that make 079 apply-ready: the read-only production
 * preflight, the mandatory pre-migration backup, and the key-transition order of
 * the 079 DDL itself.
 *
 * Nothing here touches production. Every assertion runs against a disposable
 * database created and dropped by this runner.
 *
 * php tests/php/MigrationControlPlanePreflightMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\FilesystemMigrationSourceProvider;
use Medisa\Api\Database\MigrationBackupService;
use Medisa\Api\Database\MigrationPreflightReport;

function mcpAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function mcpPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/** Pre-079 production shape: legacy month-only UNIQUE key, no actor columns. */
function mcpCreatePreimage(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(120) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        "CREATE TABLE aylik_kapanis_state (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ay CHAR(7) NOT NULL,
            state ENUM('BOLUM_ONAYINDA','BOLUM_ONAYLANDI','REVIZE_ISTENDI','KAPANDI')
                NOT NULL DEFAULT 'BOLUM_ONAYINDA',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_aylik_kapanis_state_ay (ay)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE aylik_ozet_satirlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ay CHAR(7) NOT NULL,
            personel_id INT UNSIGNED NOT NULL,
            ad_soyad VARCHAR(160) NOT NULL,
            sicil_no VARCHAR(32) NULL,
            sube_id INT UNSIGNED NULL,
            sube VARCHAR(120) NOT NULL,
            departman_id INT UNSIGNED NULL,
            bolum VARCHAR(120) NOT NULL,
            bolum_onay_durumu ENUM('BOLUM_ONAYINDA','BOLUM_ONAYLANDI','REVIZE_ISTENDI')
                NOT NULL DEFAULT 'BOLUM_ONAYINDA',
            revize_var_mi TINYINT(1) NOT NULL DEFAULT 0,
            son_islem VARCHAR(255) NULL,
            kapanis_durumu ENUM('ACIK','KAPANDI') NOT NULL DEFAULT 'ACIK',
            PRIMARY KEY (id),
            KEY idx_aylik_ozet_ay (ay),
            KEY idx_aylik_ozet_sube (sube_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function mcpSeed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO subeler (id, ad) VALUES (1, 'Sube Bir'), (2, 'Sube Iki')");
    $pdo->exec(
        "INSERT INTO aylik_kapanis_state (ay, state) VALUES
            ('2026-06', 'KAPANDI'),
            ('2026-07', 'BOLUM_ONAYLANDI')"
    );
    // One already-approved legacy row per branch plus the two shapes the guards
    // must count: a NULL branch and a branch id with no owner row.
    $pdo->exec(
        "INSERT INTO aylik_ozet_satirlari
            (ay, personel_id, ad_soyad, sicil_no, sube_id, sube, bolum, bolum_onay_durumu, son_islem, kapanis_durumu)
         VALUES
            ('2026-06', 11, 'Fixture Personel A', 'FX-1', 1, 'Sube Bir', 'Bolum', 'BOLUM_ONAYLANDI', 'onay', 'KAPANDI'),
            ('2026-06', 12, 'Fixture Personel B', 'FX-2', 2, 'Sube Iki', 'Bolum', 'BOLUM_ONAYLANDI', 'onay', 'KAPANDI'),
            ('2026-07', 13, 'Fixture Personel C', 'FX-3', 1, 'Sube Bir', 'Bolum', 'BOLUM_ONAYINDA', NULL, 'ACIK'),
            ('2026-07', 14, 'Fixture Personel D', 'FX-4', NULL, 'Bilinmiyor', 'Bolum', 'BOLUM_ONAYINDA', NULL, 'ACIK'),
            ('2026-07', 15, 'Fixture Personel E', 'FX-5', 99, 'Kapali Sube', 'Bolum', 'BOLUM_ONAYINDA', NULL, 'ACIK')"
    );
}

/**
 * Ledger at production tip 078: real checksums for 001..078, nothing for 079.
 *
 * @param list<array<string, mixed>> $migrations
 */
function mcpSeedLedger(PDO $pdo, array $migrations, string $tip): void
{
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/src/Database/migration_ledger.sql'));
    $insert = $pdo->prepare(
        'INSERT INTO medisa_schema_migrations (version, checksum, execution_ms) VALUES (:v, :c, 0)'
    );
    foreach ($migrations as $migration) {
        // 000 is the ledger bootstrap and is recorded in production too.
        if ((int) $migration['version'] > (int) $tip) {
            continue;
        }
        $insert->execute([':v' => $migration['version'], ':c' => $migration['checksum']]);
    }
}

/** Fingerprint of everything the preflight is allowed to look at but never change. */
function mcpFingerprint(PDO $pdo): string
{
    $parts = [];
    foreach (['aylik_kapanis_state', 'aylik_ozet_satirlari', 'medisa_schema_migrations', 'subeler'] as $table) {
        // ORDER BY 1: the ledger is keyed on version, the others on id.
        $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        $parts[] = $table . ':' . json_encode($rows);
    }
    $schema = $pdo->query(
        "SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, ORDINAL_POSITION"
    )->fetchAll(PDO::FETCH_ASSOC);
    $indexes = $pdo->query(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
         FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    )->fetchAll(PDO::FETCH_ASSOC);

    return hash('sha256', implode('|', $parts) . json_encode($schema) . json_encode($indexes));
}

/** @return array<string, array{unique: bool, columns: list<string>}> */
function mcpIndexes(PDO $pdo, string $table): array
{
    $statement = $pdo->prepare(
        'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
         ORDER BY INDEX_NAME, SEQ_IN_INDEX'
    );
    $statement->execute([':t' => $table]);
    $indexes = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = (string) $row['INDEX_NAME'];
        if (!isset($indexes[$name])) {
            $indexes[$name] = ['unique' => (int) $row['NON_UNIQUE'] === 0, 'columns' => []];
        }
        $indexes[$name]['columns'][] = (string) $row['COLUMN_NAME'];
    }

    return $indexes;
}

function mcpApply079(PDO $pdo): ?string
{
    $sql = (string) file_get_contents(
        __DIR__ . '/../../api/migrations/079_aylik_kapanis_sube_scope_and_actor.sql'
    );
    try {
        $pdo->exec($sql);

        return null;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return $exception->getMessage();
    }
}

function mcpRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);

        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        mcpRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '' || stripos($dsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $dsn, $hostMatch)
    && !in_array(strtolower($hostMatch[1]), ['127.0.0.1', 'localhost', '::1'], true)
) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}

$suffix = bin2hex(random_bytes(5));
$db = 'medisa_mcp_' . $suffix;
$restoreDb = 'medisa_mcp_restore_' . $suffix;
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = mcpPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec('CREATE DATABASE `' . $restoreDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = mcpPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));

$apiDirectory = realpath(__DIR__ . '/../../api');
$source = new FilesystemMigrationSourceProvider($apiDirectory . '/migrations');
$migrations = $source->all();
$deployedSha = str_repeat('a', 40);

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa-backup-' . $suffix;
$fakeApiDirectory = $sandbox . '/public_html/personelmedisa/api';
mkdir($fakeApiDirectory, 0777, true);

try {
    mcpCreatePreimage($pdo);
    mcpSeed($pdo);
    mcpSeedLedger($pdo, $migrations, '078');

    // ---------------------------------------------------------------------
    // 1) Read-only preflight on the canonical pre-079 preimage
    // ---------------------------------------------------------------------
    $before = mcpFingerprint($pdo);
    $report = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    $after = mcpFingerprint($pdo);

    mcpAssert($before === $after, 'preflight leaves schema, data and ledger byte-identical');
    mcpAssert($report['ledger']['applied_tip'] === '078', 'preflight reports production tip 078');
    mcpAssert($report['bundle']['code_tip'] === '079', 'preflight reports code tip 079');
    mcpAssert(
        $report['ledger']['pending_names'] === ['079_aylik_kapanis_sube_scope_and_actor.sql'],
        'pending list contains only migration 079'
    );
    mcpAssert(
        $report['ledger']['checksum_mismatch_versions'] === []
            && $report['ledger']['gap_versions'] === [],
        'ledger has no checksum mismatch and no gap'
    );
    mcpAssert(
        $report['bundle']['expected_pending_checksum']
            === hash_file('sha256', $apiDirectory . '/migrations/079_aylik_kapanis_sube_scope_and_actor.sql'),
        'pending checksum equals the sha256 of the 079 file on this ref'
    );
    mcpAssert($report['result'] === 'PASS', 'canonical preimage passes the preflight');

    // ---------------------------------------------------------------------
    // 2) Aggregate-only guards and the row transform they predict
    // ---------------------------------------------------------------------
    $guards = $report['guards'];
    mcpAssert($guards['ozet_rows'] === 5, 'ozet row count is reported as an aggregate');
    mcpAssert($guards['ozet_sube_null_rows'] === 1, 'null branch rows are counted');
    mcpAssert($guards['ozet_sube_orphan_rows'] === 1, 'orphan branch rows are counted');
    mcpAssert($guards['branch_table_resolved'] === true, 'orphan guard resolved its canonical branch owner');
    mcpAssert($guards['ozet_duplicate_ay_sube_personel'] === 0, 'duplicate (ay, sube, personel) rows are counted');
    mcpAssert($guards['state_rows'] === 2 && $guards['state_distinct_ay'] === 2, 'legacy state rows and months are counted');
    mcpAssert($guards['state_duplicate_ay'] === 0, 'legacy state has no duplicate month');
    mcpAssert(
        $guards['state_rows_expected_after_079'] === $guards['state_rows'],
        '079 is schema-only, so the expected post-migration state row count equals the preimage count'
    );
    mcpAssert(
        $guards['state_legacy_unique_present'] === true
            && $guards['state_composite_unique_present'] === false
            && $guards['state_sube_column_present'] === false,
        'preimage is still the legacy UNIQUE(ay) shape'
    );
    mcpAssert($guards['ozet_actor_columns_present'] === 0, 'actor columns are absent before apply');
    mcpAssert(
        in_array('OZET_SUBE_NULL_ROWS_PRESENT', $report['warnings'], true)
            && in_array('OZET_SUBE_ORPHAN_ROWS_PRESENT', $report['warnings'], true),
        'null and orphan branch rows surface as warnings, not as silent zeroes'
    );

    // ---------------------------------------------------------------------
    // 3) The report is publishable: aggregates only, never row content
    // ---------------------------------------------------------------------
    $encoded = (string) json_encode($report);
    foreach (['Fixture Personel', 'FX-1', 'FX-5', 'Sube Bir', 'Kapali Sube', 'BOLUM_ONAYLANDI'] as $leak) {
        mcpAssert(strpos($encoded, $leak) === false, 'preflight report never carries row content: ' . $leak);
    }
    mcpAssert(strpos($encoded, 'ad_soyad') !== false, 'column names are reported (schema readback is the point)');
    mcpAssert(
        preg_match('/password|passwd|secret|token|dsn|bearer/i', $encoded) !== 1,
        'preflight report carries no credential-shaped field'
    );

    // ---------------------------------------------------------------------
    // 4) Fail-closed: a migration already applied to production was edited
    // ---------------------------------------------------------------------
    $pdo->exec("UPDATE medisa_schema_migrations SET checksum = REPEAT('f', 64) WHERE version = '078'");
    $tampered = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mcpAssert(
        in_array('MIGRATION_CHECKSUM_MISMATCH', $tampered['blockers'], true)
            && $tampered['result'] === 'BLOCKED',
        'an edited applied migration blocks the preflight'
    );
    mcpAssert(
        $tampered['ledger']['checksum_mismatch_versions'] === ['078'],
        'the mismatching version is named without leaking anything else'
    );
    $pdo->exec('DROP TABLE medisa_schema_migrations');
    mcpSeedLedger($pdo, $migrations, '078');

    // ---------------------------------------------------------------------
    // 5) Backup owner: location policy, checksum, readback, restore artifact
    // ---------------------------------------------------------------------
    putenv('MEDISA_MIGRATION_BACKUP_DIR');
    $backup = MigrationBackupService::create($pdo, $fakeApiDirectory, 'req-1', '079');
    $backupDirectory = $sandbox . '/medisa-migration-backups';
    $backupPath = $backupDirectory . DIRECTORY_SEPARATOR . $backup['file'];

    mcpAssert(is_file($backupPath), 'backup lands outside the webroot, as a sibling of public_html');
    mcpAssert(strpos(realpath($backupPath), realpath($sandbox . '/public_html')) === false, 'backup path is not inside the webroot');
    mcpAssert(
        preg_match('/^medisa-pre-079-req-1-\d{8}-\d{6}\.sql$/', $backup['file']) === 1,
        'backup file name carries migration tip, request id and timestamp'
    );
    mcpAssert(
        $backup['sha256'] === hash_file('sha256', $backupPath)
            && $backup['bytes'] === filesize($backupPath),
        'published checksum and size match the file on disk'
    );
    mcpAssert($backup['readback'] === 'VERIFIED', 'backup readback is verified before the caller continues');
    mcpAssert(
        $backup['tables'] === ['aylik_kapanis_state', 'aylik_ozet_satirlari', 'medisa_schema_migrations'],
        'backup scope is the two closing tables plus the ledger preimage'
    );
    mcpAssert(
        $backup['row_counts']['aylik_ozet_satirlari'] === 5
            && $backup['row_counts']['aylik_kapanis_state'] === 2,
        'backup metadata reports the row counts it captured'
    );
    mcpAssert(!array_key_exists('absolute_path', $backup), 'published metadata omits the server path');
    mcpAssert(is_file($backupPath . '.meta.json'), 'rollback metadata is stored next to the dump');

    $restore = mcpPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $restoreDb, $dsn) ?: $dsn));
    // SHOW CREATE TABLE spans several lines, so statements are accumulated until
    // the terminating semicolon rather than executed line by line.
    $statement = '';
    foreach (explode("\n", (string) file_get_contents($backupPath)) as $line) {
        if ($statement === '' && (trim($line) === '' || strncmp(trim($line), '--', 2) === 0)) {
            continue;
        }
        $statement .= ($statement === '' ? '' : "\n") . $line;
        if (substr(rtrim($line), -1) === ';') {
            $restore->exec(rtrim(rtrim($statement), ';'));
            $statement = '';
        }
    }
    mcpAssert(
        (int) $restore->query('SELECT COUNT(*) FROM aylik_ozet_satirlari')->fetchColumn() === 5
            && (int) $restore->query('SELECT COUNT(*) FROM aylik_kapanis_state')->fetchColumn() === 2
            && (int) $restore->query("SELECT COUNT(*) FROM medisa_schema_migrations WHERE version = '078'")->fetchColumn() === 1,
        'the dump alone is a sufficient restore artifact for all three tables'
    );
    mcpAssert(
        isset(mcpIndexes($restore, 'aylik_kapanis_state')['uq_aylik_kapanis_state_ay']),
        'restore brings back the pre-079 key shape, so the index transition is reversible'
    );

    putenv('MEDISA_MIGRATION_BACKUP_DIR=' . $sandbox . '/public_html/dumps');
    $insideWebroot = null;
    try {
        MigrationBackupService::create($pdo, $fakeApiDirectory, 'req-2', '079');
    } catch (Throwable $exception) {
        $insideWebroot = $exception->getMessage();
    }
    mcpAssert(
        $insideWebroot === 'BACKUP_LOCATION_INSIDE_WEBROOT',
        'a webroot-reachable backup location fails closed instead of writing PII into the webroot'
    );
    mcpAssert(!is_file($sandbox . '/public_html/dumps'), 'no dump is left behind by the refused location');

    putenv('MEDISA_MIGRATION_BACKUP_DIR');
    $unresolved = null;
    try {
        MigrationBackupService::create($pdo, $sandbox, 'req-3', '079');
    } catch (Throwable $exception) {
        $unresolved = $exception->getMessage();
    }
    mcpAssert(
        $unresolved === 'BACKUP_LOCATION_UNRESOLVED',
        'an unresolvable safe location fails closed instead of falling back silently'
    );

    // ---------------------------------------------------------------------
    // 6) 079 key transition: composite first, legacy never dropped blindly
    // ---------------------------------------------------------------------
    $decoyFailure = null;
    $pdo->exec('ALTER TABLE aylik_kapanis_state ADD KEY uq_aylik_kapanis_state_ay_sube (ay)');
    $decoyFailure = mcpApply079($pdo);
    $decoyIndexes = mcpIndexes($pdo, 'aylik_kapanis_state');
    mcpAssert($decoyFailure !== null, 'a non-unique key wearing the composite name aborts the migration');
    mcpAssert(
        strpos((string) $decoyFailure, 'composite closing state key missing before legacy drop') !== false,
        'the abort names the pre-drop composite guard'
    );
    mcpAssert(
        isset($decoyIndexes['uq_aylik_kapanis_state_ay'])
            && $decoyIndexes['uq_aylik_kapanis_state_ay']['unique'] === true,
        'the legacy UNIQUE key survives the abort, so the table is never uniqueness-free'
    );
    $pdo->exec('ALTER TABLE aylik_kapanis_state DROP KEY uq_aylik_kapanis_state_ay_sube');

    // Mid-state resume: both keys present is the intended worst case.
    $pdo->exec('ALTER TABLE aylik_kapanis_state ADD UNIQUE KEY uq_aylik_kapanis_state_ay_sube (ay, sube_id)');
    $midIndexes = mcpIndexes($pdo, 'aylik_kapanis_state');
    mcpAssert(
        isset($midIndexes['uq_aylik_kapanis_state_ay'], $midIndexes['uq_aylik_kapanis_state_ay_sube']),
        'the interrupted state carries both unique keys instead of none'
    );
    mcpAssert(mcpApply079($pdo) === null, 'a rerun resumes from the interrupted state');
    $resumed = mcpIndexes($pdo, 'aylik_kapanis_state');
    mcpAssert(
        !isset($resumed['uq_aylik_kapanis_state_ay']) && isset($resumed['uq_aylik_kapanis_state_ay_sube']),
        'the resume finishes the transition: composite kept, legacy dropped'
    );

    // ---------------------------------------------------------------------
    // 7) Post-apply shape, data preservation and idempotency
    // ---------------------------------------------------------------------
    $composite = $resumed['uq_aylik_kapanis_state_ay_sube'];
    mcpAssert(
        $composite['unique'] === true && $composite['columns'] === ['ay', 'sube_id'],
        'the surviving key is UNIQUE (ay, sube_id)'
    );
    $stateColumns = $pdo->query(
        "SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aylik_kapanis_state'
           AND COLUMN_NAME = 'sube_id'"
    )->fetch(PDO::FETCH_ASSOC);
    mcpAssert(
        $stateColumns !== false
            && $stateColumns['IS_NULLABLE'] === 'NO'
            && (int) $stateColumns['COLUMN_DEFAULT'] === 0,
        'state sube_id is NOT NULL DEFAULT 0, so the unique key cannot be bypassed by NULL'
    );
    mcpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM aylik_kapanis_state')->fetchColumn() === 2
            && (int) $pdo->query('SELECT COUNT(*) FROM aylik_kapanis_state WHERE sube_id <> 0')->fetchColumn() === 0,
        'legacy state rows survive verbatim under the sentinel sube_id = 0'
    );
    mcpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM aylik_ozet_satirlari')->fetchColumn() === 5
            && (int) $pdo->query("SELECT COUNT(*) FROM aylik_ozet_satirlari WHERE bolum_onay_durumu = 'BOLUM_ONAYLANDI'")->fetchColumn() === 2,
        'no closing or approval row is changed by the migration'
    );
    $actorColumns = $pdo->query(
        "SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'aylik_ozet_satirlari'
           AND COLUMN_NAME IN (
             'bolum_onay_actor_user_id', 'bolum_onay_actor_identity_id', 'bolum_onay_at',
             'kapanis_actor_user_id', 'kapanis_actor_identity_id', 'kapanis_at'
           )"
    )->fetchAll(PDO::FETCH_ASSOC);
    mcpAssert(count($actorColumns) === 6, 'all six actor columns exist after apply');
    $nullable = array_filter($actorColumns, static fn (array $row): bool => $row['IS_NULLABLE'] !== 'YES');
    mcpAssert($nullable === [], 'actor columns are additive and nullable, so legacy rows stay valid');
    mcpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM aylik_ozet_satirlari WHERE bolum_onay_actor_user_id IS NULL')->fetchColumn() === 5,
        'existing approved rows keep a NULL actor instead of being back-filled'
    );

    $postApplyFingerprint = mcpFingerprint($pdo);
    mcpAssert(mcpApply079($pdo) === null, 'a second full rerun is a no-op');
    mcpAssert(
        $postApplyFingerprint === mcpFingerprint($pdo),
        'the idempotent rerun changes neither schema nor data'
    );

    // The post-079 schema must no longer read as an apply-ready preimage.
    $postReport = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mcpAssert(
        in_array('PREIMAGE_ACTOR_COLUMNS_ALREADY_PRESENT', $postReport['blockers'], true)
            && $postReport['result'] === 'BLOCKED',
        'an already-migrated schema is refused as an apply preimage'
    );

    echo 'verify-migration-control-plane-preflight-mysql: OK' . PHP_EOL;
} finally {
    putenv('MEDISA_MIGRATION_BACKUP_DIR');
    mcpRemoveTree($sandbox);
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $root->exec('DROP DATABASE IF EXISTS `' . $restoreDb . '`');
}
