<?php

declare(strict_types=1);

/**
 * Canonical migration round 083 — DB-backed acceptance against a real MariaDB.
 *
 * Production preimage: tip 082 with 083 pending. Setup applies 080–082 from
 * real files; subject under test is 083 only.
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
const MRC_MIGRATION_083 = '083_personel_organizasyon_degisiklik_auditleri.sql';

/** Audit owners the completed 080/081 round left behind. */
const MRC_PREDECESSOR_AUDIT_TABLES = [
    'personel_sube_degisiklik_auditleri',
    'sube_olusturma_auditleri',
    'user_org_scope_auditleri',
    'user_erisim_kaldirma_auditleri',
    'user_erisim_degisiklik_auditleri',
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
$sourceThrough082 = new MrcChainThrough($filesystemSource, 82);
$source = new MrcChainThrough($filesystemSource, 83);
$deployedSha = str_repeat('b', 40);

try {
    $pdo = mrcPdo($rootDsn . ';dbname=' . $db);
    mrcCreatePostO79Preimage($pdo);
    mrcSeed($pdo);
    mrcSeedLedger($pdo, $sourceThrough082->all(), '079');

    MigrationExecutionService::apply($pdo, $sourceThrough082, null, '080');
    MigrationExecutionService::apply($pdo, $sourceThrough082, null, '081');
    MigrationExecutionService::apply($pdo, $sourceThrough082, null, '082');
    mrcAssert(
        MigrationExecutionService::ledgerFacts($pdo, $sourceThrough082)['tip'] === '082',
        'the preimage database is at production tip 082'
    );

    $baselineCounts = mrcBusinessCounts($pdo);
    $baselineRoles = mrcRoleValues($pdo);

    // -----------------------------------------------------------------
    // 1) tip 082, only 083 pending → apply ready
    // -----------------------------------------------------------------
    $report = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($report['result'] === 'PASS', 'a production tip 082 database is apply-ready for the 083 round');
    mrcAssert($report['ledger']['applied_tip'] === '082', 'preflight reports production tip 082');
    mrcAssert($report['bundle']['code_tip'] === '083', 'preflight reports code tip 083');
    mrcAssert(
        $report['ledger']['pending_names'] === [MRC_MIGRATION_083],
        'only 083 is pending before the apply'
    );
    mrcAssert(
        $report['bundle']['next_pending_name'] === MRC_MIGRATION_083,
        'the next authorized migration is 083'
    );
    mrcAssert(
        $report['guards']['round_audit_tables_present'] === 0,
        'the clean preimage does not yet carry the personnel org audit table'
    );
    mrcAssert(
        $report['guards']['predecessor_audit_tables_present'] === 5,
        'the preimage proves the completed 082 round is really present'
    );

    // -----------------------------------------------------------------
    // 2) Apply exactly 083
    // -----------------------------------------------------------------
    $applied = MigrationExecutionService::apply($pdo, $source, null, '083');
    mrcAssert($applied['pending'] === ['083'], 'a targeted request applies exactly one migration');

    $ledger = MigrationExecutionService::ledgerFacts($pdo, $source);
    mrcAssert($ledger['tip'] === '083', 'production tip is 083 after the apply');
    mrcAssert($ledger['pending_versions'] === [], 'no migration is left pending');
    mrcAssert(
        mrcTableExists($pdo, 'personel_organizasyon_degisiklik_auditleri'),
        '083 created the personnel organisation audit owner'
    );
    foreach (MRC_PREDECESSOR_AUDIT_TABLES as $predecessor) {
        mrcAssert(mrcTableExists($pdo, $predecessor), 'the previous round owner ' . $predecessor . ' survived 083');
    }
    mrcAssert($baselineCounts === mrcBusinessCounts($pdo), '083 wrote no business row');
    mrcAssert($baselineRoles === mrcRoleValues($pdo), 'no existing user role value was remapped');

    // -----------------------------------------------------------------
    // 3) tip 083, pending 0 → round complete
    // -----------------------------------------------------------------
    $doneReport = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($doneReport['result'] === 'BLOCKED', 'a completed round is not apply-ready again');
    mrcAssert(
        in_array('ROUND_ALREADY_COMPLETE', $doneReport['blockers'], true),
        'the blocker names the completed round instead of failing silently'
    );

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
