<?php

declare(strict_types=1);

/**
 * Canonical migration round 080 + 081 — DB-backed acceptance against a real
 * MariaDB.
 *
 * The round is applied one migration per canonical request, so the control plane
 * has to stay apply-ready in three distinct chain states: before 080, between
 * 080 and 081, and after 081. This runner drives all three against a disposable
 * database and asserts the read-only preflight, the targeted runner and the
 * additive-only guarantee at every step.
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

/**
 * The round gate authorises 080 and 081 only. Freezing the source at 081 keeps
 * this runner measuring the round instead of measuring how far the repository
 * has moved since.
 */
final class MrcChainThrough081 implements MigrationSourceProvider
{
    private MigrationSourceProvider $inner;

    public function __construct(MigrationSourceProvider $inner)
    {
        $this->inner = $inner;
    }

    /** @return list<array{version: string, name: string, checksum: string, sql: string}> */
    public function all(): array
    {
        return array_values(array_filter(
            $this->inner->all(),
            static fn (array $migration): bool => (int) $migration['version'] <= 81
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
$source = new MrcChainThrough081(new FilesystemMigrationSourceProvider($apiDirectory . '/migrations'));
$migrations = $source->all();
$deployedSha = str_repeat('b', 40);

try {
    $pdo = mrcPdo($rootDsn . ';dbname=' . $db);
    mrcCreatePostO79Preimage($pdo);
    mrcSeed($pdo);
    mrcSeedLedger($pdo, $migrations, '079');

    $baselineCounts = mrcBusinessCounts($pdo);
    $baselineRoles = mrcRoleValues($pdo);

    // -----------------------------------------------------------------
    // 1) Before the round: both migrations pending, 080 authorized
    // -----------------------------------------------------------------
    $report = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($report['result'] === 'PASS', 'a production tip 079 database is apply-ready for the round');
    mrcAssert($report['ledger']['applied_tip'] === '079', 'preflight reports production tip 079');
    mrcAssert($report['bundle']['code_tip'] === '081', 'preflight reports code tip 081');
    mrcAssert(
        $report['ledger']['pending_names'] === [MRC_MIGRATION_080, MRC_MIGRATION_081],
        'the whole round is pending before the first apply'
    );
    mrcAssert(
        $report['bundle']['next_pending_name'] === MRC_MIGRATION_080,
        'the next authorized migration is 080'
    );
    mrcAssert(
        $report['bundle']['expected_pending_checksum']
            === hash_file('sha256', $apiDirectory . '/migrations/' . MRC_MIGRATION_080),
        'the authorized checksum is the sha256 of the 080 file on this ref'
    );
    mrcAssert(
        $report['guards']['round_audit_tables_present'] === 0
            && $report['guards']['new_role_already_present'] === false,
        'the clean preimage carries neither audit table nor the new role'
    );
    mrcAssert(
        $report['guards']['user_rows_expected_after_round'] === $report['guards']['user_rows'],
        'the round is schema-only, so the expected user count equals the preimage count'
    );

    // -----------------------------------------------------------------
    // 2) Apply exactly 080
    // -----------------------------------------------------------------
    $applied = MigrationExecutionService::apply($pdo, $source, null, '080');
    mrcAssert($applied['pending'] === ['080'], 'a targeted request applies exactly one migration');

    $ledger = MigrationExecutionService::ledgerFacts($pdo, $source);
    mrcAssert($ledger['tip'] === '080', 'production tip is 080 after the first apply');
    mrcAssert($ledger['pending_versions'] === ['081'], '081 stays pending behind its own request');
    mrcAssert(
        mrcTableExists($pdo, 'personel_sube_degisiklik_auditleri')
            && mrcTableExists($pdo, 'sube_olusturma_auditleri')
            && mrcTableExists($pdo, 'user_org_scope_auditleri'),
        '080 created its three organisation audit owners'
    );
    mrcAssert(
        !mrcTableExists($pdo, 'user_erisim_kaldirma_auditleri'),
        'nothing from 081 leaked into the 080 request'
    );
    mrcAssert(strpos(mrcRoleEnum($pdo), 'IK_PERSONELI') === false, 'the role catalog is untouched by 080');
    mrcAssert($baselineCounts === mrcBusinessCounts($pdo), '080 wrote no business row');

    // Verifying through the authorized target passes; demanding a drained chain
    // still fails, so a partial round can never read as complete.
    $verified = MigrationExecutionService::verify($pdo, $source, '080');
    mrcAssert($verified['pending'] === ['081'], 'targeted verify reports the remaining migration');
    $fullVerifyFailed = false;
    try {
        MigrationExecutionService::verify($pdo, $source);
    } catch (\Throwable $exception) {
        $fullVerifyFailed = true;
    }
    mrcAssert($fullVerifyFailed, 'a full-chain verify refuses a partially applied round');

    // -----------------------------------------------------------------
    // 3) Between the two applies the gate stays ready, now for 081
    // -----------------------------------------------------------------
    $midReport = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($midReport['result'] === 'PASS', 'the chain between the two applies is still apply-ready');
    mrcAssert($midReport['ledger']['applied_tip'] === '080', 'preflight reports the new tip 080');
    mrcAssert($midReport['ledger']['pending_names'] === [MRC_MIGRATION_081], 'only 081 is left pending');
    mrcAssert(
        $midReport['bundle']['expected_pending_checksum']
            === hash_file('sha256', $apiDirectory . '/migrations/' . MRC_MIGRATION_081),
        'the authorized checksum moved to the 081 file'
    );
    mrcAssert(
        $midReport['guards']['round_audit_tables_present'] === 3
            && in_array('PREIMAGE_PARTIAL_ROUND_AUDIT_TABLE_PRESENT', $midReport['warnings'], true),
        'the half-applied round is reported as resumable, not as clean'
    );

    // -----------------------------------------------------------------
    // 4) Apply exactly 081 and close the round
    // -----------------------------------------------------------------
    $applied = MigrationExecutionService::apply($pdo, $source, null, '081');
    mrcAssert($applied['pending'] === ['081'], 'the second request applies exactly the second migration');

    $ledger = MigrationExecutionService::ledgerFacts($pdo, $source);
    mrcAssert($ledger['tip'] === '081', 'production tip is 081 after the round');
    mrcAssert($ledger['pending_versions'] === [], 'no migration is left pending');
    mrcAssert(
        mrcTableExists($pdo, 'user_erisim_kaldirma_auditleri'),
        '081 created the access revocation audit owner'
    );
    mrcAssert(strpos(mrcRoleEnum($pdo), "'IK_PERSONELI'") !== false, 'IK_PERSONELI is storable after 081');
    mrcAssert($baselineCounts === mrcBusinessCounts($pdo), 'the round wrote no business row at all');
    mrcAssert($baselineRoles === mrcRoleValues($pdo), 'no existing user role value was remapped');
    mrcAssert(
        MigrationExecutionService::verify($pdo, $source)['pending'] === [],
        'the drained chain verifies without a target'
    );

    // -----------------------------------------------------------------
    // 5) A completed round authorizes nothing further
    // -----------------------------------------------------------------
    $doneReport = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($doneReport['result'] === 'BLOCKED', 'a completed round is not apply-ready again');
    mrcAssert(
        in_array('ROUND_ALREADY_COMPLETE', $doneReport['blockers'], true),
        'the blocker names the completed round instead of failing silently'
    );

    $overVerifyFailed = false;
    try {
        MigrationExecutionService::verify($pdo, $source, '080');
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
