<?php

declare(strict_types=1);

/**
 * Canonical migration round 082 — DB-backed acceptance against a real MariaDB.
 *
 * The round the control plane authorises is now the single access-change audit
 * migration, so the gate has to be exact about three chain states and nothing
 * else: production tip 081 with only 082 pending is apply-ready, tip 082 with an
 * empty pending set is a completed round, and any other tip, order or pending
 * set is blocked. This runner drives all three against a disposable database.
 *
 * The tip-081 preimage is built by actually applying 080 and 081 from their real
 * files rather than by hand-writing their result, so "production is at 081" is
 * proven rather than assumed. Those two migrations are setup here, never the
 * subject: their ledger rows and checksums are written once and never rewritten.
 *
 * Nothing here touches production.
 *
 * php tests/php/MigrationRoundControlPlaneMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\FilesystemMigrationSourceProvider;
use Medisa\Api\Database\MigrationExecutionService;
use Medisa\Api\Database\MigrationPreflightReport;
use Medisa\Api\Database\MigrationRunner;
use Medisa\Api\Database\MigrationSourceProvider;

const MRC_MIGRATION_080 = '080_organizasyon_audit_owners.sql';
const MRC_MIGRATION_081 = '081_ik_personeli_rolu.sql';
const MRC_MIGRATION_082 = '082_user_erisim_degisiklik_auditleri.sql';

/** Audit owners the completed 080/081 round left behind. */
const MRC_PREDECESSOR_AUDIT_TABLES = [
    'personel_sube_degisiklik_auditleri',
    'sube_olusturma_auditleri',
    'user_org_scope_auditleri',
    'user_erisim_kaldirma_auditleri',
];

/**
 * Freezes the canonical source at a version so this runner keeps measuring the
 * round instead of measuring how far the repository has moved since.
 */
final class MrcChainThrough implements MigrationSourceProvider
{
    private MigrationSourceProvider $inner;

    private int $maxVersion;

    public function __construct(MigrationSourceProvider $inner, int $maxVersion)
    {
        $this->inner = $inner;
        $this->maxVersion = $maxVersion;
    }

    /** @return list<array{version: string, name: string, checksum: string, sql: string}> */
    public function all(): array
    {
        $max = $this->maxVersion;

        return array_values(array_filter(
            $this->inner->all(),
            static fn (array $migration): bool => (int) $migration['version'] <= $max
        ));
    }
}

function mrcAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function mrcPdo(string $dsn): PDO
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
 * Production shape at tip 079: the hierarchy owners exist and users.rol is the
 * canonical pre-081 catalog.
 */
function mrcCreatePostO79Preimage(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(80) NOT NULL,
            rol ENUM('GENEL_YONETICI','SISTEM_YONETICISI','SUBE_YONETICISI','BOLUM_YONETICISI','BIRIM_AMIRI','IK_SORUMLUSU','MUHASEBE','PERSONEL','AUTH_SMOKE_READONLY') NOT NULL DEFAULT 'PERSONEL',
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE sirketler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sirketler_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE sgk_isverenler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            sirket_id INT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sgk_isverenler_kod (kod),
            CONSTRAINT fk_sgk_isverenler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(120) NOT NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            sirket_id INT UNSIGNED NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_subeler_kod (kod),
            CONSTRAINT fk_subeler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id),
            CONSTRAINT fk_subeler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE calisma_lokasyonlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            sube_id INT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_calisma_lokasyonlari_kod (kod),
            CONSTRAINT fk_calisma_lokasyonlari_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT
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
    $pdo->exec(
        'CREATE TABLE user_sirketler (
            user_id INT UNSIGNED NOT NULL,
            sirket_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, sirket_id),
            CONSTRAINT fk_user_sirketler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_user_sirketler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE user_sgk_isverenler (
            user_id INT UNSIGNED NOT NULL,
            sgk_isveren_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, sgk_isveren_id),
            CONSTRAINT fk_user_sgk_isverenler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_user_sgk_isverenler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function mrcSeed(PDO $pdo): void
{
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (50, 'branch_manager', 'SUBE_YONETICISI'), (60, 'ik', 'IK_SORUMLUSU')");
    $pdo->exec("INSERT INTO sirketler (id, kod, ad) VALUES (1, 'SRK-1', 'Sirket Bir')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, sirket_id) VALUES (1, 'SGK-1', 'Bordro Birimi Bir', 1)");
    $pdo->exec(
        "INSERT INTO subeler (id, kod, ad, sgk_isveren_id, sirket_id) VALUES
            (1, 'SB-1', 'Fabrika', 1, 1),
            (2, 'SB-2', 'Giresun', 1, 1)"
    );
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad) VALUES (1, 'LOK-1', 'Fabrika Sahasi')");
    $pdo->exec(
        "INSERT INTO personeller (id, ad_soyad, sube_id, sgk_isveren_id, calisma_lokasyonu_id) VALUES
            (11, 'Fixture Personel A', 1, 1, 1),
            (12, 'Fixture Personel B', 2, 1, NULL)"
    );
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (50, 2)');
    $pdo->exec('INSERT INTO user_sirketler (user_id, sirket_id) VALUES (60, 1)');
}

/**
 * @param list<array<string, mixed>> $migrations
 */
function mrcSeedLedger(PDO $pdo, array $migrations, string $tip): void
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

/** @return array<string, int> */
function mrcBusinessCounts(PDO $pdo): array
{
    $counts = [];
    foreach (
        ['users', 'sirketler', 'sgk_isverenler', 'subeler', 'calisma_lokasyonlari',
            'personeller', 'user_subeler', 'user_sirketler', 'user_sgk_isverenler'] as $table
    ) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }

    return $counts;
}

/** @return array<string, string> */
function mrcRoleValues(PDO $pdo): array
{
    return $pdo->query('SELECT username, rol FROM users ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function mrcTableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $statement->execute([':t' => $table]);

    return (int) $statement->fetchColumn() === 1;
}

function mrcRoleEnum(PDO $pdo): string
{
    $type = $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rol'"
    )->fetchColumn();

    return is_string($type) ? $type : '';
}

$rootDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$rootDsn = preg_replace('/;dbname=[^;]*/', '', $rootDsn) ?? $rootDsn;
$suffix = bin2hex(random_bytes(4));
$db = 'medisa_round_' . $suffix;

$root = mrcPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$apiDirectory = dirname(__DIR__, 2) . '/api';
$filesystemSource = new FilesystemMigrationSourceProvider($apiDirectory . '/migrations');
$sourceThrough081 = new MrcChainThrough($filesystemSource, 81);
$source = new MrcChainThrough($filesystemSource, 82);
$deployedSha = str_repeat('b', 40);

try {
    $pdo = mrcPdo($rootDsn . ';dbname=' . $db);
    mrcCreatePostO79Preimage($pdo);
    mrcSeed($pdo);
    mrcSeedLedger($pdo, $sourceThrough081->all(), '079');

    // Setup, not subject: reach the real production tip by applying the two
    // migrations of the previous round from their own files.
    MigrationExecutionService::apply($pdo, $sourceThrough081, null, '080');
    MigrationExecutionService::apply($pdo, $sourceThrough081, null, '081');
    mrcAssert(
        MigrationExecutionService::ledgerFacts($pdo, $sourceThrough081)['tip'] === '081',
        'the preimage database is at production tip 081'
    );

    $baselineCounts = mrcBusinessCounts($pdo);
    $baselineRoles = mrcRoleValues($pdo);
    $ledgerBefore082 = $pdo->query(
        'SELECT version, checksum FROM medisa_schema_migrations ORDER BY version'
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    // -----------------------------------------------------------------
    // 1) State one: production tip 081, only 082 pending → apply ready
    // -----------------------------------------------------------------
    $report = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($report['result'] === 'PASS', 'a production tip 081 database is apply-ready for the 082 round');
    mrcAssert($report['ledger']['applied_tip'] === '081', 'preflight reports production tip 081');
    mrcAssert($report['bundle']['code_tip'] === '082', 'preflight reports code tip 082');
    mrcAssert(
        $report['ledger']['pending_names'] === [MRC_MIGRATION_082],
        'only 082 is pending before the apply'
    );
    mrcAssert(
        $report['bundle']['next_pending_name'] === MRC_MIGRATION_082,
        'the next authorized migration is 082'
    );
    mrcAssert(
        $report['bundle']['expected_pending_checksum']
            === hash_file('sha256', $apiDirectory . '/migrations/' . MRC_MIGRATION_082),
        'the authorized checksum is the sha256 of the 082 file on this ref'
    );
    mrcAssert(
        $report['guards']['round_audit_tables_present'] === 0,
        'the clean preimage does not yet carry the access change audit table'
    );
    mrcAssert(
        $report['guards']['predecessor_audit_tables_present'] === 4
            && $report['guards']['predecessor_role_present'] === true,
        'the preimage proves the completed 080/081 round is really present'
    );
    mrcAssert(
        $report['guards']['user_rows_expected_after_round'] === $report['guards']['user_rows'],
        'the round is schema-only, so the expected user count equals the preimage count'
    );

    // -----------------------------------------------------------------
    // 2) A tip that is not 081 with nothing pending is refused
    // -----------------------------------------------------------------
    $staleRoundReport = MigrationPreflightReport::collect($pdo, $sourceThrough081, $deployedSha);
    mrcAssert($staleRoundReport['result'] === 'BLOCKED', 'a source frozen before the round is not apply-ready');
    mrcAssert(
        in_array('APPLIED_TIP_UNEXPECTED', $staleRoundReport['blockers'], true),
        'an empty pending set on a non-round tip is named rather than read as complete'
    );
    mrcAssert(
        in_array('CODE_TIP_UNEXPECTED', $staleRoundReport['blockers'], true),
        'a code tip that is not the round tip is refused'
    );

    // -----------------------------------------------------------------
    // 3) A database missing a predecessor audit owner is refused
    // -----------------------------------------------------------------
    $pdo->exec('DROP TABLE user_erisim_kaldirma_auditleri');
    $missingPredecessor = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert(
        $missingPredecessor['result'] === 'BLOCKED'
            && in_array('PREIMAGE_PREDECESSOR_AUDIT_TABLE_MISSING', $missingPredecessor['blockers'], true),
        'a ledger that claims 081 without its audit owner is refused'
    );
    // 081 is idempotent, so re-running its file restores the owner without
    // touching the ledger row or the checksum recorded for it.
    $pdo->exec((string) file_get_contents($apiDirectory . '/migrations/' . MRC_MIGRATION_081));
    mrcAssert(
        MigrationPreflightReport::collect($pdo, $source, $deployedSha)['result'] === 'PASS',
        'restoring the predecessor owner makes the round apply-ready again'
    );

    // -----------------------------------------------------------------
    // 4) Apply exactly 082
    // -----------------------------------------------------------------
    $applied = MigrationExecutionService::apply($pdo, $source, null, '082');
    mrcAssert($applied['pending'] === ['082'], 'a targeted request applies exactly one migration');

    $ledger = MigrationExecutionService::ledgerFacts($pdo, $source);
    mrcAssert($ledger['tip'] === '082', 'production tip is 082 after the apply');
    mrcAssert($ledger['pending_versions'] === [], 'no migration is left pending');
    mrcAssert(
        mrcTableExists($pdo, 'user_erisim_degisiklik_auditleri'),
        '082 created the access change audit owner'
    );
    foreach (MRC_PREDECESSOR_AUDIT_TABLES as $predecessor) {
        mrcAssert(mrcTableExists($pdo, $predecessor), 'the previous round owner ' . $predecessor . ' survived 082');
    }
    mrcAssert($baselineCounts === mrcBusinessCounts($pdo), '082 wrote no business row');
    mrcAssert($baselineRoles === mrcRoleValues($pdo), 'no existing user role value was remapped');
    mrcAssert(strpos(mrcRoleEnum($pdo), "'IK_PERSONELI'") !== false, 'the 081 role catalog is untouched by 082');

    $ledgerAfter082 = $pdo->query(
        'SELECT version, checksum FROM medisa_schema_migrations ORDER BY version'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    unset($ledgerAfter082['082']);
    mrcAssert(
        $ledgerBefore082 === $ledgerAfter082,
        'no already-applied ledger row or checksum was rewritten by the round'
    );

    // -----------------------------------------------------------------
    // 5) State two: tip 082, pending 0 → round complete, nothing further
    // -----------------------------------------------------------------
    $doneReport = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($doneReport['result'] === 'BLOCKED', 'a completed round is not apply-ready again');
    mrcAssert(
        in_array('ROUND_ALREADY_COMPLETE', $doneReport['blockers'], true),
        'the blocker names the completed round instead of failing silently'
    );
    mrcAssert(
        MigrationExecutionService::verify($pdo, $source)['pending'] === [],
        'the drained chain verifies without a target'
    );

    $overVerifyFailed = false;
    try {
        MigrationExecutionService::verify($pdo, $source, '081');
    } catch (\Throwable $exception) {
        $overVerifyFailed = true;
    }
    mrcAssert($overVerifyFailed, 'verify refuses a chain applied beyond its authorized target');

    // -----------------------------------------------------------------
    // 6) An unknown target is refused before anything is applied
    // -----------------------------------------------------------------
    $unknownTargetFailed = false;
    try {
        MigrationRunner::run($pdo, $source, null, '999');
    } catch (\Throwable $exception) {
        $unknownTargetFailed = strpos($exception->getMessage(), 'not in the canonical chain') !== false;
    }
    mrcAssert($unknownTargetFailed, 'a target outside the canonical chain is refused');

    echo 'verify-migration-round-control-plane-mysql: OK' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
