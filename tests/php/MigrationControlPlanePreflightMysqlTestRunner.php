<?php

declare(strict_types=1);

/**
 * Migration 079 (sirket -> sgk/sube -> lokasyon hierarchy) — DB-backed acceptance
 * against a real MariaDB.
 *
 * Covers the three owners that make 079 apply-ready: the read-only production
 * preflight, the mandatory pre-migration backup, and the DDL itself across an
 * empty schema, a production-like 078 preimage, a compatible partial state,
 * incompatible drift and a second run.
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

const MCP_MIGRATION_079 = '079_sirket_sube_hiyerarsisi.sql';

/**
 * The 079 apply gate authorises exactly one migration, so this runner feeds it
 * the chain as it stood at that decision: 001→079. Later migrations are a
 * separate approval and must not silently ride through a gate that never
 * reviewed them — freezing the source here keeps this test measuring the gate
 * instead of measuring how far the repository has moved since.
 */
final class McpChainThrough079 implements Medisa\Api\Database\MigrationSourceProvider
{
    /** @var Medisa\Api\Database\MigrationSourceProvider */
    private $inner;

    public function __construct(Medisa\Api\Database\MigrationSourceProvider $inner)
    {
        $this->inner = $inner;
    }

    /** @return list<array{version: string, name: string, checksum: string, sql: string}> */
    public function all(): array
    {
        return array_values(array_filter(
            $this->inner->all(),
            static fn (array $migration): bool => (int) $migration['version'] <= 79
        ));
    }
}
const MCP_WITHDRAWN_079 = '079_aylik_kapanis_sube_scope_and_actor.sql';

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

/**
 * Pre-079 production shape: the organisation owners exist, none of them knows
 * about a company yet.
 */
function mcpCreatePreimage(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(80) NOT NULL,
            rol VARCHAR(40) NOT NULL DEFAULT 'PERSONEL',
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE sgk_isverenler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sgk_isverenler_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(120) NOT NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_subeler_kod (kod),
            CONSTRAINT fk_subeler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE calisma_lokasyonlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_calisma_lokasyonlari_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad_soyad VARCHAR(160) NOT NULL,
            sube_id INT UNSIGNED NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            calisma_lokasyonu_id INT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE user_subeler (
            user_id INT UNSIGNED NOT NULL,
            sube_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, sube_id),
            CONSTRAINT fk_user_subeler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_user_subeler_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Fixture shaped like the documented production reality: gapped branch ids, a
 * branch whose name already carries the company prefix, and a branch manager
 * scoped to exactly one branch.
 */
function mcpSeed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (50, 'branch_manager', 'SUBE_YONETICISI')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad) VALUES (1, 'SGK-1', 'Bordro Birimi Bir')");
    // Ids 1, 2, 4 with no 3: the gap is production reality and must survive.
    $pdo->exec(
        "INSERT INTO subeler (id, kod, ad, sgk_isveren_id) VALUES
            (1, 'SB-1', 'Fabrika', 1),
            (2, 'SB-2', 'Giresun', 1),
            (4, 'SB-4', 'Medisa Kayseri', NULL)"
    );
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad) VALUES (1, 'LOK-1', 'Fabrika Sahasi')");
    $pdo->exec(
        "INSERT INTO personeller (id, ad_soyad, sube_id, sgk_isveren_id, calisma_lokasyonu_id) VALUES
            (11, 'Fixture Personel A', 1, 1, 1),
            (12, 'Fixture Personel B', 2, 1, NULL)"
    );
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (50, 2)');
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
    foreach (
        ['users', 'subeler', 'sgk_isverenler', 'calisma_lokasyonlari', 'personeller', 'user_subeler', 'medisa_schema_migrations'] as $table
    ) {
        // ORDER BY 1: the ledger is keyed on version, the others on their first key column.
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

/** @return array<string, array{nullable: bool, type: string}> */
function mcpColumns(PDO $pdo, string $table): array
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $statement->execute([':t' => $table]);
    $columns = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[(string) $row['COLUMN_NAME']] = [
            'nullable' => (string) $row['IS_NULLABLE'] === 'YES',
            'type' => (string) $row['COLUMN_TYPE'],
        ];
    }

    return $columns;
}

/** @return array<string, array{table: string, referenced: string, delete_rule: string}> */
function mcpForeignKeys(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT rc.CONSTRAINT_NAME, rc.TABLE_NAME, rc.REFERENCED_TABLE_NAME, rc.DELETE_RULE
         FROM information_schema.REFERENTIAL_CONSTRAINTS rc
         WHERE rc.CONSTRAINT_SCHEMA = DATABASE()"
    )->fetchAll(PDO::FETCH_ASSOC);

    $keys = [];
    foreach ($rows as $row) {
        $keys[(string) $row['CONSTRAINT_NAME']] = [
            'table' => (string) $row['TABLE_NAME'],
            'referenced' => (string) $row['REFERENCED_TABLE_NAME'],
            'delete_rule' => (string) $row['DELETE_RULE'],
        ];
    }

    return $keys;
}

function mcpApply079(PDO $pdo): ?string
{
    $sql = (string) file_get_contents(__DIR__ . '/../../api/migrations/' . MCP_MIGRATION_079);
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
$driftDb = 'medisa_mcp_drift_' . $suffix;
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = mcpPdo($rootDsn);
foreach ([$db, $restoreDb, $driftDb] as $name) {
    $root->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}
$pdo = mcpPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));

$apiDirectory = realpath(__DIR__ . '/../../api');
$source = new McpChainThrough079(new FilesystemMigrationSourceProvider($apiDirectory . '/migrations'));
$migrations = $source->all();
$deployedSha = str_repeat('a', 40);

$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa-backup-' . $suffix;
$fakeApiDirectory = $sandbox . '/public_html/personelmedisa/api';
mkdir($fakeApiDirectory, 0777, true);

try {
    // ---------------------------------------------------------------------
    // 0) The withdrawn monthly-close 079 is gone from the canonical source
    // ---------------------------------------------------------------------
    mcpAssert(
        !is_file($apiDirectory . '/migrations/' . MCP_WITHDRAWN_079),
        'the withdrawn monthly-close 079 is absent from the migration source'
    );
    $slot079 = array_values(array_filter(
        $migrations,
        static fn (array $migration): bool => (string) $migration['version'] === '079'
    ));
    mcpAssert(count($slot079) === 1, 'the canonical source carries exactly one migration 079');
    mcpAssert((string) $slot079[0]['name'] === MCP_MIGRATION_079, 'the single 079 is the hierarchy migration');

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
        $report['ledger']['pending_names'] === [MCP_MIGRATION_079],
        'pending list contains only the hierarchy migration 079'
    );
    mcpAssert(
        $report['ledger']['checksum_mismatch_versions'] === []
            && $report['ledger']['gap_versions'] === [],
        'ledger has no checksum mismatch and no gap'
    );
    mcpAssert($report['bundle']['withdrawn_present'] === false, 'the withdrawn 079 is not pending');

    // The preflight owner authorises the current round, not this historical one:
    // a database that never received 079 must read as blocked, never as ready.
    mcpAssert($report['result'] === 'BLOCKED', 'a pre-079 database is not apply-ready for the current round');
    mcpAssert(
        in_array('PENDING_NOT_ROUND_SUFFIX', $report['blockers'], true)
            && in_array('CODE_TIP_UNEXPECTED', $report['blockers'], true),
        'the blocker names the chain shape it refused instead of failing silently'
    );

    // ---------------------------------------------------------------------
    // 2) Aggregate-only guards stay observable even on a refused chain
    // ---------------------------------------------------------------------
    $guards = $report['guards'];
    mcpAssert($guards['branch_table_resolved'] === true, 'guards resolved their canonical branch owner');
    mcpAssert($guards['sube_rows'] === 3, 'branch rows are reported as an aggregate');
    mcpAssert($guards['user_sube_assignment_rows'] === 1, 'explicit branch assignments are counted');
    mcpAssert(
        $guards['sirketler_table_present'] === false
            && $guards['user_sirketler_table_present'] === false
            && $guards['user_sgk_isverenler_table_present'] === false,
        'the new hierarchy tables are absent in the preimage'
    );
    mcpAssert($guards['relation_columns_present'] === 0, 'no relation column exists before apply');
    mcpAssert(
        $guards['sube_rows_expected_after_round'] === $guards['sube_rows']
            && $guards['user_sube_assignment_rows_expected_after_round'] === $guards['user_sube_assignment_rows'],
        'the round is schema-only, so every expected post-migration count equals the preimage count'
    );

    // ---------------------------------------------------------------------
    // 3) The report is publishable: aggregates only, never row content
    // ---------------------------------------------------------------------
    $encoded = (string) json_encode($report);
    foreach (['Fixture Personel', 'branch_manager', 'Fabrika', 'Giresun', 'Medisa Kayseri'] as $leak) {
        mcpAssert(strpos($encoded, $leak) === false, 'preflight report never carries row content: ' . $leak);
    }
    mcpAssert(strpos($encoded, 'sgk_isveren_id') !== false, 'column names are reported (schema readback is the point)');
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
        $backup['tables'] === ['subeler', 'sgk_isverenler', 'calisma_lokasyonlari', 'user_subeler', 'medisa_schema_migrations'],
        'backup scope is exactly what the hierarchy migration touches, plus the ledger preimage'
    );
    mcpAssert(
        $backup['row_counts']['subeler'] === 3 && $backup['row_counts']['user_subeler'] === 1,
        'backup metadata reports the row counts it captured'
    );
    mcpAssert(!array_key_exists('absolute_path', $backup), 'published metadata omits the server path');
    mcpAssert(is_file($backupPath . '.meta.json'), 'rollback metadata is stored next to the dump');

    $restore = mcpPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $restoreDb, $dsn) ?: $dsn));
    $restore->exec('SET FOREIGN_KEY_CHECKS = 0');
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
        (int) $restore->query('SELECT COUNT(*) FROM subeler')->fetchColumn() === 3
            && (int) $restore->query('SELECT COUNT(*) FROM user_subeler')->fetchColumn() === 1
            && (int) $restore->query("SELECT COUNT(*) FROM medisa_schema_migrations WHERE version = '078'")->fetchColumn() === 1,
        'the dump alone is a sufficient restore artifact for the whole backup scope'
    );
    mcpAssert(
        !isset(mcpColumns($restore, 'subeler')['sirket_id']),
        'restore brings back the pre-079 branch shape, so the migration is reversible'
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
    // 6) Fail-closed drift: an incompatible column wearing the same name
    // ---------------------------------------------------------------------
    $drift = mcpPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $driftDb, $dsn) ?: $dsn));
    mcpCreatePreimage($drift);
    mcpSeed($drift);
    $drift->exec('ALTER TABLE subeler ADD COLUMN sirket_id VARCHAR(32) NOT NULL DEFAULT ""');
    $driftFailure = mcpApply079($drift);
    mcpAssert($driftFailure !== null, 'an incompatible sirket_id column aborts the migration');
    mcpAssert(
        strpos((string) $driftFailure, 'incompatible hierarchy column already present') !== false,
        'the abort names the drift guard instead of reporting success'
    );
    mcpAssert(
        !isset(mcpColumns($drift, 'sirketler')['id']) && mcpColumns($drift, 'sirketler') === [],
        'the aborted run creates no hierarchy table'
    );
    $driftPreflight = MigrationPreflightReport::collect($drift, $source, $deployedSha);
    mcpAssert(
        in_array('PREIMAGE_RELATION_COLUMN_INCOMPATIBLE', $driftPreflight['blockers'], true)
            && $driftPreflight['result'] === 'BLOCKED',
        'the preflight refuses the same drift before an apply is ever requested'
    );

    // ---------------------------------------------------------------------
    // 7) Empty schema: the structural guard refuses to build half a hierarchy
    // ---------------------------------------------------------------------
    $drift->exec('DROP TABLE user_subeler');
    $drift->exec('DROP TABLE personeller');
    $drift->exec('DROP TABLE subeler');
    $drift->exec('DROP TABLE calisma_lokasyonlari');
    $drift->exec('DROP TABLE sgk_isverenler');
    $drift->exec('DROP TABLE users');
    $emptyFailure = mcpApply079($drift);
    mcpAssert($emptyFailure !== null, 'an empty schema aborts instead of creating orphan tables');
    mcpAssert(
        strpos((string) $emptyFailure, 'organisation owner tables missing') !== false,
        'the abort names the missing owner tables'
    );

    // ---------------------------------------------------------------------
    // 8) Apply on the production-like preimage
    // ---------------------------------------------------------------------
    mcpAssert(mcpApply079($pdo) === null, '079 applies cleanly on the 078 preimage');

    $subeColumns = mcpColumns($pdo, 'subeler');
    mcpAssert(
        isset($subeColumns['sirket_id'])
            && $subeColumns['sirket_id']['nullable'] === true
            && strpos($subeColumns['sirket_id']['type'], 'unsigned') !== false,
        'subeler.sirket_id is added as a nullable unsigned int'
    );
    mcpAssert(
        (mcpColumns($pdo, 'sgk_isverenler')['sirket_id']['nullable'] ?? null) === true
            && (mcpColumns($pdo, 'calisma_lokasyonlari')['sube_id']['nullable'] ?? null) === true,
        'the remaining relation columns are nullable so legacy rows stay valid'
    );
    mcpAssert(
        !isset(mcpColumns($pdo, 'personeller')['sirket_id']),
        'personeller gains no sirket_id: the company is derived from the branch'
    );
    mcpAssert(
        !isset($subeColumns['tam_ad']),
        'tam_ad is never stored; it is derived by the backend read model'
    );

    $sirketColumns = mcpColumns($pdo, 'sirketler');
    mcpAssert(
        isset($sirketColumns['id'], $sirketColumns['kod'], $sirketColumns['ad'], $sirketColumns['durum']),
        'sirketler carries id, kod, ad and durum'
    );
    $sirketIndexes = mcpIndexes($pdo, 'sirketler');
    mcpAssert(
        ($sirketIndexes['uq_sirketler_kod']['unique'] ?? false) === true
            && ($sirketIndexes['uq_sirketler_ad']['unique'] ?? false) === true,
        'company code and name are globally unique'
    );

    $foreignKeys = mcpForeignKeys($pdo);
    foreach ([
        'fk_subeler_sirket' => ['subeler', 'sirketler', 'RESTRICT'],
        'fk_sgk_isverenler_sirket' => ['sgk_isverenler', 'sirketler', 'RESTRICT'],
        'fk_calisma_lokasyonlari_sube' => ['calisma_lokasyonlari', 'subeler', 'RESTRICT'],
        'fk_user_sirketler_user' => ['user_sirketler', 'users', 'CASCADE'],
        'fk_user_sirketler_sirket' => ['user_sirketler', 'sirketler', 'RESTRICT'],
        'fk_user_sgk_isverenler_user' => ['user_sgk_isverenler', 'users', 'CASCADE'],
        'fk_user_sgk_isverenler_sgk' => ['user_sgk_isverenler', 'sgk_isverenler', 'RESTRICT'],
    ] as $name => $expected) {
        mcpAssert(
            isset($foreignKeys[$name])
                && $foreignKeys[$name]['table'] === $expected[0]
                && $foreignKeys[$name]['referenced'] === $expected[1]
                && $foreignKeys[$name]['delete_rule'] === $expected[2],
            'foreign key ' . $name . ' points at ' . $expected[1] . ' with ON DELETE ' . $expected[2]
        );
    }
    foreach (['idx_subeler_sirket', 'idx_sgk_isverenler_sirket'] as $index) {
        $table = $index === 'idx_subeler_sirket' ? 'subeler' : 'sgk_isverenler';
        mcpAssert(isset(mcpIndexes($pdo, $table)[$index]), 'relation index ' . $index . ' exists');
    }

    // ---------------------------------------------------------------------
    // 9) Data preservation: no seed, no mapping, no rename, no id shift
    // ---------------------------------------------------------------------
    mcpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM sirketler')->fetchColumn() === 0
            && (int) $pdo->query('SELECT COUNT(*) FROM user_sirketler')->fetchColumn() === 0
            && (int) $pdo->query('SELECT COUNT(*) FROM user_sgk_isverenler')->fetchColumn() === 0,
        'the migration seeds no company and copies no user scope'
    );
    mcpAssert(
        $pdo->query('SELECT GROUP_CONCAT(id ORDER BY id) FROM subeler')->fetchColumn() === '1,2,4',
        'branch ids survive verbatim, including the 3 that does not exist in production'
    );
    mcpAssert(
        $pdo->query("SELECT ad FROM subeler WHERE id = 4")->fetchColumn() === 'Medisa Kayseri',
        'no branch is renamed: a name that already carries the company prefix is left alone'
    );
    mcpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM subeler WHERE sirket_id IS NOT NULL')->fetchColumn() === 0
            && (int) $pdo->query('SELECT COUNT(*) FROM sgk_isverenler WHERE sirket_id IS NOT NULL')->fetchColumn() === 0,
        'no branch or payroll employer is mapped to a company by the migration'
    );
    mcpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM user_subeler')->fetchColumn() === 1
            && (int) $pdo->query('SELECT sube_id FROM user_subeler WHERE user_id = 50')->fetchColumn() === 2,
        'the branch manager keeps exactly the branch assignment it had'
    );
    mcpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE sube_id = 1 AND sgk_isveren_id = 1')->fetchColumn() === 1,
        'personnel branch and payroll axes stay independent and unmoved'
    );

    // ---------------------------------------------------------------------
    // 10) Idempotency and compatible partial-state resume
    // ---------------------------------------------------------------------
    $postApplyFingerprint = mcpFingerprint($pdo);
    mcpAssert(mcpApply079($pdo) === null, 'a second full rerun is a no-op');
    mcpAssert(
        $postApplyFingerprint === mcpFingerprint($pdo),
        'the idempotent rerun changes neither schema nor data'
    );

    $pdo->exec('ALTER TABLE user_sirketler DROP FOREIGN KEY fk_user_sirketler_sirket');
    $pdo->exec('DROP TABLE user_sirketler');
    $pdo->exec('ALTER TABLE calisma_lokasyonlari DROP FOREIGN KEY fk_calisma_lokasyonlari_sube');
    mcpAssert(mcpApply079($pdo) === null, 'a compatible partial state resumes instead of failing');
    mcpAssert(
        isset(mcpForeignKeys($pdo)['fk_calisma_lokasyonlari_sube'], mcpForeignKeys($pdo)['fk_user_sirketler_sirket']),
        'the resume restores exactly the missing pieces'
    );
    mcpAssert(
        $postApplyFingerprint === mcpFingerprint($pdo),
        'the resumed schema is identical to the one a single clean run produces'
    );

    // The applied hierarchy is the preimage the current round builds on, so the
    // relation columns must now read as present rather than as drift.
    $postReport = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mcpAssert(
        !in_array('PREIMAGE_HIERARCHY_COLUMN_MISSING', $postReport['blockers'], true)
            && !in_array('PREIMAGE_RELATION_COLUMN_INCOMPATIBLE', $postReport['blockers'], true),
        'an applied hierarchy reads as the expected preimage for the next round'
    );

    echo 'verify-migration-control-plane-preflight-mysql: OK' . PHP_EOL;
} finally {
    putenv('MEDISA_MIGRATION_BACKUP_DIR');
    mcpRemoveTree($sandbox);
    foreach ([$db, $restoreDb, $driftDb] as $name) {
        $root->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    }
}
