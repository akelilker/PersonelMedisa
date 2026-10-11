<?php

declare(strict_types=1);

/**
 * KullaniciKaliciSilMigrationService — 099 protected-account migration owner,
 * verified against a real disposable MariaDB.
 *
 * The four required scenarios (missing ids, invalid ids, orphaned reference,
 * successful apply) plus the error/recovery scenarios (partial-state detection,
 * refuse a second run) are all exercised here through the real service, which
 * is what the control-plane worker calls. Nothing touches production.
 *
 * php tests/php/KullaniciKaliciSilMigrationMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\FilesystemMigrationSourceProvider;
use Medisa\Api\Database\MigrationBackupService;
use Medisa\Api\Database\MigrationExecutionService;
use Medisa\Api\Services\Auth\KullaniciKaliciSilMigrationFailure;
use Medisa\Api\Services\Auth\KullaniciKaliciSilMigrationService;

const KSM_MIGRATION_TIP = '099_user_kalici_silme_auditleri.sql';
const KSM_AUDIT_TABLE = 'user_kalici_silme_auditleri';
const KSM_REGISTRY_TABLE = 'user_kalici_silme_korunan_hesaplar';

const KSM_FK_REFERENCES = [
    'ek_odeme_kesinti.created_by',
    'ek_odeme_kesinti.updated_by',
    'gunluk_bildirimler.created_by',
    'gunluk_bildirimler.updated_by',
    'gunluk_bildirimler.correction_requested_by',
    'legal_holdlar.released_by',
    'legal_hold_auditleri.actor_user_id',
    'offline_mutation_idempotency.actor_user_id',
    'personel_gecici_gorevlendirmeler.olusturan_user_id',
    'personel_gecici_gorevlendirmeler.sonlandiran_user_id',
    'personel_import_runs.actor_id',
    'personel_test_fixture_archive_kayitlari.archived_by',
    'personel_test_fixture_siniflandirmalari.classified_by',
    'personel_test_fixture_siniflandirmalari.iptal_edildi_by',
    'retention_imha_auditleri.actor_user_id',
];

const KSM_FK_TABLES = [
    'ek_odeme_kesinti',
    'gunluk_bildirimler',
    'legal_holdlar',
    'legal_hold_auditleri',
    'offline_mutation_idempotency',
    'personel_gecici_gorevlendirmeler',
    'personel_import_runs',
    'personel_test_fixture_archive_kayitlari',
    'personel_test_fixture_siniflandirmalari',
    'retention_imha_auditleri',
];

const KSM_BACKED_UP_TABLES = [
    'users',
    'ek_odeme_kesinti',
    'gunluk_bildirimler',
    'legal_holdlar',
    'legal_hold_auditleri',
    'offline_mutation_idempotency',
    'personel_gecici_gorevlendirmeler',
    'personel_import_runs',
    'personel_test_fixture_archive_kayitlari',
    'personel_test_fixture_siniflandirmalari',
    'retention_imha_auditleri',
    'medisa_schema_migrations',
];

function ksmAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function ksmPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
}

function ksmApplyFile(PDO $pdo, string $relative): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $relative);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $relative);
    }
    $pdo->exec($sql);
}

function ksmCount(PDO $pdo, string $sql): int
{
    return (int) $pdo->query($sql)->fetchColumn();
}

function ksmTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $statement->execute([':t' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function ksmColumnExists(PDO $pdo, string $table, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $statement->execute([':t' => $table, ':c' => $column]);

    return (int) $statement->fetchColumn() === 1;
}

function ksmRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);

        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        ksmRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

/**
 * Bootstrap the canonical ledger and mark 000..$tip applied with the real
 * checksums, matching the canonical runner's own expectations.
 *
 * @param list<array{version: string, checksum: string}> $migrations
 */
function ksmSeedLedger(PDO $pdo, array $migrations, string $tip): void
{
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/src/Database/migration_ledger.sql'));
    $insert = $pdo->prepare(
        'INSERT INTO medisa_schema_migrations (version, checksum, execution_ms) VALUES (:v, :c, 0)'
    );
    foreach ($migrations as $migration) {
        if ((int) $migration['version'] > (int) $tip) {
            continue;
        }
        $insert->execute([':v' => $migration['version'], ':c' => $migration['checksum']]);
    }
}

function ksmNewDatabase(PDO $root, string $baseDsn): string
{
    $db = 'medisa_ksm_' . bin2hex(random_bytes(5));
    $root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    return $db;
}

/**
 * Build a real production-shape preimage: canonical chain 001–098 (minus the
 * reference-data gate 067) applied as SQL, then the ledger seeded to 098.
 */
function ksmBuildPreimage(PDO $pdo, string $apiDirectory, FilesystemMigrationSourceProvider $source): void
{
    $chain = array_values(array_filter(
        scandir($apiDirectory . '/migrations') ?: [],
        static fn (string $name): bool => (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name)
            && $name !== '067_personel_canonical_reference_gate.sql'
            && strcmp((string) $name, KSM_MIGRATION_TIP) < 0
    ));
    sort($chain, SORT_STRING);
    foreach ($chain as $migration) {
        ksmApplyFile($pdo, (string) $migration);
    }
    ksmSeedLedger($pdo, $source->all(), '098');
}

$rootDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($rootDsn === '' || stripos($rootDsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $rootDsn, $hostMatch)
    && !in_array(strtolower($hostMatch[1]), ['127.0.0.1', 'localhost', '::1'], true)
) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}

$apiDirectory = dirname(__DIR__, 2) . '/api';
$baseDsn = preg_replace('/;?dbname=[^;]*/i', '', $rootDsn) ?: $rootDsn;
$root = ksmPdo($baseDsn);
// 099 apply sahibi tarihseldir (canlıda uygulandı): kaynak zinciri 099'da dondurulur;
// sonraki migration'lar (100+) bu sahibin "yalnız 099 bekliyor" sözleşmesine girmez.
$frozenRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa-ksm-chain-' . bin2hex(random_bytes(4));
$frozenMigrations = $frozenRoot . DIRECTORY_SEPARATOR . 'migrations';
mkdir($frozenMigrations, 0700, true);
mkdir($frozenRoot . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database', 0700, true);
copy($apiDirectory . '/src/Database/migration_ledger.sql', $frozenRoot . '/src/Database/migration_ledger.sql');
foreach (scandir($apiDirectory . '/migrations') ?: [] as $name) {
    if (preg_match('/^\d{3}_.+\.sql$/', (string) $name) && strcmp((string) $name, KSM_MIGRATION_TIP) <= 0) {
        copy($apiDirectory . '/migrations/' . $name, $frozenMigrations . DIRECTORY_SEPARATOR . $name);
    }
}
$source = new FilesystemMigrationSourceProvider($frozenMigrations);
$deployedSha = str_repeat('c', 40);

    $db = ksmNewDatabase($root, $baseDsn);
$partialDb = ksmNewDatabase($root, $baseDsn);
$backupDb = ksmNewDatabase($root, $baseDsn);

$backupDir = null;

try {
    // ---------------------------------------------------------------------
    // Primary database: full scenario matrix through the real service.
    // ---------------------------------------------------------------------
    $pdo = ksmPdo($baseDsn . ';dbname=' . $db);
    ksmBuildPreimage($pdo, $apiDirectory, $source);
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
            (21, 'verified.one', 'x', 'Verified One', 'SISTEM_YONETICISI', 'AKTIF'),
            (22, 'verified.two', 'x', 'Verified Two', 'SISTEM_YONETICISI', 'AKTIF')"
    );

    // Missing ids — blocked before any DDL.
    $missing = KullaniciKaliciSilMigrationService::preflight($pdo, $source, $deployedSha, 0, 0);
    ksmAssert(
        $missing['result'] === 'BLOCKED' && in_array('KALICI_SIL_IDS_INVALID', $missing['blockers'], true),
        'preflight blocks missing protected-account ids'
    );
    ksmAssert(
        ksmTableExists($pdo, KSM_AUDIT_TABLE) === false,
        'missing ids leave no audit table behind'
    );

    // Invalid ids — one id does not exist in users.
    $invalid = KullaniciKaliciSilMigrationService::preflight($pdo, $source, $deployedSha, 21, 9999);
    ksmAssert(
        $invalid['result'] === 'BLOCKED' && in_array('KALICI_SIL_IDS_NOT_FOUND', $invalid['blockers'], true),
        'preflight blocks a nonexistent protected-account id'
    );

    // Orphaned reference — blocked and apply refuses to start.
    $pdo->exec(
        "INSERT INTO offline_mutation_idempotency
            (actor_user_id, operation_scope, idempotency_key, payload_hash, state, created_at)
         VALUES (9999, 'orphan', 'orphan-key-1', '" . str_repeat('a', 64) . "', 'COMPLETED', NOW(3))"
    );
    $orphan = KullaniciKaliciSilMigrationService::preflight($pdo, $source, $deployedSha, 21, 22);
    ksmAssert(
        $orphan['result'] === 'BLOCKED' && in_array('KALICI_SIL_ORPHAN_PRESENT', $orphan['blockers'], true),
        'preflight blocks an orphaned FK-less reference'
    );
    $blockedApply = false;
    try {
        KullaniciKaliciSilMigrationService::apply(
            $pdo,
            $source,
            $deployedSha,
            21,
            22,
            ['file' => 'x', 'sha256' => str_repeat('0', 64), 'readback' => 'VERIFIED']
        );
    } catch (KullaniciKaliciSilMigrationFailure $exception) {
        $blockedApply = $exception->reason === 'KALICI_SIL_PREFLIGHT_BLOCKED';
    }
    ksmAssert($blockedApply, 'apply refuses to run while an orphan is present');
    ksmAssert(
        ksmTableExists($pdo, KSM_AUDIT_TABLE) === false,
        'an orphan leaves no audit table behind before the first DDL'
    );
    $pdo->exec("DELETE FROM offline_mutation_idempotency WHERE idempotency_key = 'orphan-key-1'");

    // Clean preimage — PASS.
    $clean = KullaniciKaliciSilMigrationService::preflight($pdo, $source, $deployedSha, 21, 22);
    ksmAssert($clean['result'] === 'PASS', 'preflight passes with verified ids and no orphan');

    // Successful apply.
    $backup = ['file' => 'test-dump.sql', 'sha256' => str_repeat('0', 64), 'readback' => 'VERIFIED'];
    $applied = KullaniciKaliciSilMigrationService::apply($pdo, $source, $deployedSha, 21, 22, $backup);
    ksmAssert($applied['applied_versions'] === ['099'], 'apply applied exactly migration 099');

    // Postcheck PASS + concrete postconditions.
    $postcheck = KullaniciKaliciSilMigrationService::postcheck($pdo, $source, $backup);
    ksmAssert($postcheck['result'] === 'PASS', 'postcheck confirms the applied 099 round');
    ksmAssert(
        ksmCount($pdo, "SELECT COUNT(*) FROM medisa_schema_migrations WHERE version = '099'") === 1,
        'the ledger records migration 099'
    );
    ksmAssert(
        ksmCount(
            $pdo,
            "SELECT COUNT(*) FROM " . KSM_REGISTRY_TABLE . "
             WHERE protection_key IN ('ILKER_A', 'SERHAN_KOSE') AND user_id IN (21, 22)"
        ) === 2,
        'the registry records only the verified protected-account ids'
    );
    ksmAssert(
        ksmCount($pdo, 'SELECT COUNT(*) FROM users WHERE id IN (21, 22) AND silinmesi_korunur = 1') === 2,
        'the verified accounts carry the protected flag'
    );
    foreach (KSM_FK_REFERENCES as $reference) {
        [$table, $column] = explode('.', $reference, 2);
        ksmAssert(
            ksmCount(
                $pdo,
                "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS rc
                 JOIN information_schema.KEY_COLUMN_USAGE kcu
                   ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
                  AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                  AND kcu.TABLE_NAME = rc.TABLE_NAME
                 WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
                   AND rc.TABLE_NAME = '" . $table . "'
                   AND kcu.COLUMN_NAME = '" . $column . "'
                   AND kcu.REFERENCED_TABLE_NAME = 'users'
                   AND rc.DELETE_RULE = 'RESTRICT'"
            ) === 1,
            'migration 099 protected ' . $reference . ' with a RESTRICT users FK'
        );
    }

    // Error scenario: a second run is refused, never silently re-applied.
    $after = KullaniciKaliciSilMigrationService::preflight($pdo, $source, $deployedSha, 21, 22);
    ksmAssert(
        $after['result'] === 'BLOCKED' && in_array('KALICI_SIL_LEDGER_TIP_UNEXPECTED', $after['blockers'], true),
        'a second run is refused on an already-applied ledger'
    );

    // ---------------------------------------------------------------------
    // Partial-state recovery: a pre-existing fk_p099_* is detected, so an
    // interrupted round is reconciled manually and never blind-retried.
    // ---------------------------------------------------------------------
    $partialPdo = ksmPdo($baseDsn . ';dbname=' . $partialDb);
    ksmBuildPreimage($partialPdo, $apiDirectory, $source);
    $partialPdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
            (21, 'verified.one', 'x', 'Verified One', 'SISTEM_YONETICISI', 'AKTIF'),
            (22, 'verified.two', 'x', 'Verified Two', 'SISTEM_YONETICISI', 'AKTIF')"
    );
    $partialPdo->exec(
        'ALTER TABLE offline_mutation_idempotency
         ADD CONSTRAINT fk_p099_partial_probe FOREIGN KEY (actor_user_id)
         REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT'
    );
    $partial = KullaniciKaliciSilMigrationService::preflight($partialPdo, $source, $deployedSha, 21, 22);
    ksmAssert(
        $partial['result'] === 'BLOCKED' && in_array('KALICI_SIL_PARTIAL_STATE', $partial['blockers'], true),
        'preflight flags a pre-existing partial fk_p099_* constraint'
    );
    ksmAssert(
        in_array('fk_p099_partial_probe', $partial['partial_state']['fk_constraints'], true),
        'the partial-state report names the exact pre-existing constraint'
    );

    // ---------------------------------------------------------------------
    // Real worker backup: MigrationBackupService::createForKaliciSil must cover
    // users + the ten FK tables + the ledger, capture the ten FK tables
    // schema-only, and restore to the exact pre-099 image.
    // ---------------------------------------------------------------------
    $backupPdo = ksmPdo($baseDsn . ';dbname=' . $backupDb);
    ksmBuildPreimage($backupPdo, $apiDirectory, $source);
    $backupPdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
            (21, 'verified.one', 'x', 'Verified One', 'SISTEM_YONETICISI', 'AKTIF'),
            (22, 'verified.two', 'x', 'Verified Two', 'SISTEM_YONETICISI', 'AKTIF')"
    );
    // A real row in a schema-only table: it must survive the 099 restore.
    $backupPdo->exec(
        "INSERT INTO offline_mutation_idempotency
            (actor_user_id, operation_scope, idempotency_key, payload_hash, state, created_at)
         VALUES (21, 'scope', 'backup-row-key', '" . str_repeat('b', 64) . "', 'COMPLETED', NOW(3))"
    );

    $backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa-ksm-backup-' . bin2hex(random_bytes(5));
    mkdir($backupDir, 0700, true);
    putenv('MEDISA_MIGRATION_BACKUP_DIR=' . $backupDir);

    $realBackup = MigrationBackupService::createForKaliciSil(
        $backupPdo,
        $apiDirectory,
        'BACKUP-SCOPE-TEST',
        KSM_MIGRATION_TIP
    );
    ksmAssert($realBackup['readback'] === 'VERIFIED', 'the 099 backup is read back and verified');
    ksmAssert(
        $realBackup['tables'] === KSM_BACKED_UP_TABLES,
        'the 099 backup scope covers users, the ten FK tables and the ledger'
    );
    ksmAssert(
        $realBackup['schema_only_tables'] === KSM_FK_TABLES,
        'the 099 backup captures the ten FK tables schema-only'
    );
    ksmAssert(
        $realBackup['row_counts']['users'] === ksmCount($backupPdo, 'SELECT COUNT(*) FROM users'),
        'the 099 backup captures the full users row set'
    );
    ksmAssert(
        $realBackup['row_counts']['medisa_schema_migrations']
            === ksmCount($backupPdo, 'SELECT COUNT(*) FROM medisa_schema_migrations'),
        'the 099 backup captures the full ledger preimage'
    );
    ksmAssert(
        !array_key_exists('absolute_path', $realBackup),
        'the 099 backup metadata never carries the server path'
    );

    $backupPath = $backupDir . DIRECTORY_SEPARATOR . $realBackup['file'];
    ksmAssert(is_file($backupPath), 'the 099 dump exists on disk outside the webroot');
    $backupSql = (string) file_get_contents($backupPath);
    ksmAssert(
        hash('sha256', $backupSql) === $realBackup['sha256'],
        'the 099 dump on disk hashes to the published digest'
    );
    ksmAssert(
        strpos($backupSql, 'INSERT INTO `users`') !== false,
        'the 099 dump carries user rows'
    );
    ksmAssert(
        strpos($backupSql, 'INSERT INTO `ek_odeme_kesinti`') === false,
        'the 099 dump does not copy ek_odeme_kesinti rows (schema-only)'
    );
    foreach (KSM_FK_TABLES as $fkTable) {
        ksmAssert(
            strpos($backupSql, '-- schema-only(' . $fkTable . '): 1') !== false,
            'the 099 dump marks ' . $fkTable . ' schema-only'
        );
        ksmAssert(
            strpos($backupSql, 'CREATE TABLE `' . $fkTable . '`') !== false,
            'the 099 dump carries the ' . $fkTable . ' preimage schema'
        );
        ksmAssert(
            strpos($backupSql, 'DROP TABLE IF EXISTS `' . $fkTable . '`') === false,
            'the 099 dump never drops the schema-only table ' . $fkTable
        );
        ksmAssert(
            strpos($backupSql, 'ALTER TABLE `' . $fkTable . '` DROP FOREIGN KEY') !== false,
            'the 099 dump carries the row-safe FK rollback for ' . $fkTable
        );
    }

    // Apply 099, then restore the real dump and prove the pre-099 image returns.
    $applied099 = KullaniciKaliciSilMigrationService::apply(
        $backupPdo,
        $source,
        $deployedSha,
        21,
        22,
        $realBackup
    );
    ksmAssert($applied099['applied_versions'] === ['099'], 'the 099 backup preimage apply succeeds');
    ksmAssert(
        ksmColumnExists($backupPdo, 'users', 'silinmesi_korunur'),
        '099 added the protected-account flag before restore'
    );

    $backupPdo->exec($backupSql);

    ksmAssert(
        !ksmColumnExists($backupPdo, 'users', 'silinmesi_korunur'),
        'restoring the 099 backup removes the protected-account flag'
    );
    ksmAssert(
        ksmCount(
            $backupPdo,
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME LIKE 'fk_p099_%'"
        ) === 0,
        'restoring the 099 backup removes every fk_p099_* constraint'
    );
    ksmAssert(
        ksmCount($backupPdo, "SELECT COUNT(*) FROM medisa_schema_migrations WHERE version = '099'") === 0,
        'restoring the 099 backup removes the 099 ledger row'
    );
    ksmAssert(
        ksmCount($backupPdo, 'SELECT COUNT(*) FROM users WHERE id IN (21, 22)') === 2,
        'restoring the 099 backup returns the verified users'
    );
    ksmAssert(
        ksmCount(
            $backupPdo,
            "SELECT COUNT(*) FROM offline_mutation_idempotency WHERE idempotency_key = 'backup-row-key'"
        ) === 1,
        'restoring the 099 backup preserves the offline_mutation_idempotency row'
    );
    foreach (KSM_FK_TABLES as $fkTable) {
        ksmAssert(
            ksmTableExists($backupPdo, $fkTable),
            'restoring the 099 backup keeps the ' . $fkTable . ' table'
        );
    }

    echo "verify-kullanici-kalici-sil-migration-mysql: OK\n";
} finally {
    if ($backupDir !== null) {
        putenv('MEDISA_MIGRATION_BACKUP_DIR');
        ksmRemoveTree($backupDir);
    }
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $root->exec('DROP DATABASE IF EXISTS `' . $partialDb . '`');
    $root->exec('DROP DATABASE IF EXISTS `' . $backupDb . '`');
}
