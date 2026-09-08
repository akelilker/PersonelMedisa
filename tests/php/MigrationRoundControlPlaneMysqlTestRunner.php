<?php

declare(strict_types=1);

/**
 * Canonical migration chain 084–088 — DB-backed acceptance against a real MariaDB.
 *
 * Production preimage: tip 083 with 084+085+086+087+088 pending. Setup applies 080–083 from
 * real files; subjects under test are 084 then 085 then 086 then 087 then 088.
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

const MRC_MIGRATION_084 = '084_gunluk_bildirim_tamamlama_header_summary.sql';
const MRC_MIGRATION_085 = '085_gunluk_bildirim_duzeltme_auditleri.sql';
const MRC_MIGRATION_086 = '086_personel_historical_exit_date_correction_auditleri.sql';
const MRC_MIGRATION_087 = '087_sube_muhasebe_yetkilileri.sql';
const MRC_MIGRATION_088 = '088_sube_sorumlu_yoneticiler.sql';
const MRC_AUTHORIZED_CHECKSUM = '8918827085503147024e5b2fa51c0374a054f24660e618ac562da8d00bcf4be7';
const MRC_AUTHORIZED_CHECKSUM_086 = 'e8effb50b17319acb9deaa5e4b8fca8257c7d4850648953f998b5bf0232012c5';
const MRC_AUTHORIZED_CHECKSUM_087 = 'f9affaf648d869c5c192fba95f8f0014f53e9f71ca482e335125a4ce3951962f';
const MRC_AUTHORIZED_CHECKSUM_088 = '1dcaad0c105b2248a2bac7a0c17db3b3bd64102f20fc45c177c3fc7f48251354';

/** Audit owners the completed 080–084 rounds left behind (084 added no audit table). */
const MRC_PREDECESSOR_AUDIT_TABLES = [
    'personel_sube_degisiklik_auditleri',
    'sube_olusturma_auditleri',
    'user_org_scope_auditleri',
    'user_erisim_kaldirma_auditleri',
    'user_erisim_degisiklik_auditleri',
    'personel_organizasyon_degisiklik_auditleri',
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
    // 005-shaped owner required by 085 FK / fail-closed gate.
    $pdo->exec(
        "CREATE TABLE gunluk_bildirimler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            personel_id INT UNSIGNED NOT NULL,
            tarih DATE NOT NULL,
            sube_id INT UNSIGNED NOT NULL,
            bildirim_turu VARCHAR(32) NOT NULL,
            state VARCHAR(32) NOT NULL DEFAULT 'TASLAK',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_gb_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // Minimal surecler owner required by 086 FK / fail-closed gate.
    $pdo->exec(
        "CREATE TABLE surecler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            personel_id INT UNSIGNED NOT NULL,
            surec_turu VARCHAR(64) NOT NULL,
            baslangic_tarihi DATE NOT NULL,
            bitis_tarihi DATE NULL,
            state VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            CONSTRAINT fk_surecler_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    // 032-shaped owner that 084 extends with additive columns only.
    $pdo->exec(
        "CREATE TABLE gunluk_bildirim_tamamlamalari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            sube_id INT UNSIGNED NOT NULL,
            birim_amiri_user_id INT UNSIGNED NOT NULL,
            tarih DATE NOT NULL,
            state VARCHAR(32) NOT NULL DEFAULT 'TAMAMLANDI',
            tamamlayan_user_id INT UNSIGNED NOT NULL,
            tamamlandi_at TIMESTAMP NULL DEFAULT NULL,
            not_metni TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_gbt_sube_amir_tarih (sube_id, birim_amiri_user_id, tarih),
            CONSTRAINT fk_gbt_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
            CONSTRAINT fk_gbt_birim_amiri FOREIGN KEY (birim_amiri_user_id) REFERENCES users (id),
            CONSTRAINT fk_gbt_tamamlayan FOREIGN KEY (tamamlayan_user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
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
            'personeller', 'user_subeler', 'user_sirketler', 'user_sgk_isverenler',
            'gunluk_bildirimler'] as $table
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

function mrcTriggerExists(PDO $pdo, string $trigger): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TRIGGERS
         WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :t'
    );
    $statement->execute([':t' => $trigger]);

    return (int) $statement->fetchColumn() === 1;
}

$rootDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4';
$rootDsn = preg_replace('/;dbname=[^;]*/', '', $rootDsn) ?? $rootDsn;
$suffix = bin2hex(random_bytes(4));
$db = 'medisa_round_' . $suffix;

$root = mrcPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

$apiDirectory = dirname(__DIR__, 2) . '/api';
$filesystemSource = new FilesystemMigrationSourceProvider($apiDirectory . '/migrations');
$sourceThrough084 = new MrcChainThrough($filesystemSource, 84);
$source = new MrcChainThrough($filesystemSource, 88);
$deployedSha = str_repeat('b', 40);

try {
    $pdo = mrcPdo($rootDsn . ';dbname=' . $db);
    mrcCreatePostO79Preimage($pdo);
    mrcSeed($pdo);
    mrcSeedLedger($pdo, $sourceThrough084->all(), '079');

    MigrationExecutionService::apply($pdo, $sourceThrough084, null, '080');
    MigrationExecutionService::apply($pdo, $sourceThrough084, null, '081');
    MigrationExecutionService::apply($pdo, $sourceThrough084, null, '082');
    MigrationExecutionService::apply($pdo, $sourceThrough084, null, '083');

    // -----------------------------------------------------------------
    // 1) tip 083, 084+085+086+087+088 pending → apply ready
    // -----------------------------------------------------------------
    $before084 = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($before084['result'] === 'PASS', 'a production tip 083 database is apply-ready for the canonical pending chain');
    mrcAssert($before084['ledger']['applied_tip'] === '083', 'preflight reports production tip 083');
    mrcAssert(
        $before084['ledger']['pending_names'] === [
            MRC_MIGRATION_084,
            MRC_MIGRATION_085,
            MRC_MIGRATION_086,
            MRC_MIGRATION_087,
            MRC_MIGRATION_088,
        ],
        '084 through 088 are pending before the first apply'
    );
    mrcAssert(
        $before084['bundle']['next_pending_name'] === MRC_MIGRATION_084
            && $before084['bundle']['expected_pending_checksum'] !== 'NONE',
        'preflight resolves the next canonical pending migration checksum'
    );

    $applied084 = MigrationExecutionService::apply($pdo, $source, null, '084');
    mrcAssert($applied084['pending'] === ['084'], 'a targeted request applies exactly 084');
    mrcAssert(
        MigrationExecutionService::ledgerFacts($pdo, $sourceThrough084)['tip'] === '084',
        'the preimage database is at production tip 084'
    );

    $baselineCounts = mrcBusinessCounts($pdo);
    $baselineRoles = mrcRoleValues($pdo);

    // -----------------------------------------------------------------
    // 1) tip 084, 085+086+087+088 pending → apply ready
    // -----------------------------------------------------------------
    $report = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($report['result'] === 'PASS', 'a production tip 084 database is apply-ready for the 085-088 round');
    mrcAssert($report['ledger']['applied_tip'] === '084', 'preflight reports production tip 084');
    mrcAssert($report['bundle']['code_tip'] === '088', 'preflight reports code tip 088');
    mrcAssert(
        $report['ledger']['pending_names'] === [MRC_MIGRATION_085, MRC_MIGRATION_086, MRC_MIGRATION_087, MRC_MIGRATION_088],
        '085, 086, 087 and 088 are pending before the apply'
    );
    mrcAssert(
        $report['bundle']['next_pending_name'] === MRC_MIGRATION_085,
        'the next authorized migration is 085'
    );
    mrcAssert(
        is_string($report['bundle']['expected_pending_checksum'])
            && $report['bundle']['expected_pending_checksum'] === MRC_AUTHORIZED_CHECKSUM,
        'preflight resolves the pending 085 checksum'
    );
    mrcAssert(
        $report['guards']['round_audit_tables_present'] === 0,
        'the clean preimage does not yet carry round audit tables'
    );
    mrcAssert(
        $report['guards']['predecessor_audit_tables_present'] === 6,
        'the preimage proves the completed predecessor audit owners are really present'
    );

    // -----------------------------------------------------------------
    // 2) Apply exactly 085
    // -----------------------------------------------------------------
    $applied = MigrationExecutionService::apply($pdo, $source, null, '085');
    mrcAssert($applied['pending'] === ['085'], 'a targeted request applies exactly one migration');

    $ledger = MigrationExecutionService::ledgerFacts($pdo, $source);
    mrcAssert($ledger['tip'] === '085', 'production tip is 085 after the apply');
    mrcAssert($ledger['pending_versions'] === ['086', '087', '088'], '086, 087 and 088 remain pending after 085');
    mrcAssert(
        mrcTableExists($pdo, 'gunluk_bildirim_duzeltme_auditleri'),
        '085 created the correction audit owner'
    );
    mrcAssert(
        mrcTriggerExists($pdo, 'trg_gbda_no_update'),
        '085 installed append-only UPDATE protection'
    );
    mrcAssert(
        mrcTriggerExists($pdo, 'trg_gbda_no_delete'),
        '085 installed append-only DELETE protection'
    );
    foreach (MRC_PREDECESSOR_AUDIT_TABLES as $predecessor) {
        mrcAssert(mrcTableExists($pdo, $predecessor), 'the previous round owner ' . $predecessor . ' survived 085');
    }
    mrcAssert($baselineCounts === mrcBusinessCounts($pdo), '085 wrote no business row');
    mrcAssert($baselineRoles === mrcRoleValues($pdo), 'no existing user role value was remapped');

    // -----------------------------------------------------------------
    // 3) Apply exactly 086
    // -----------------------------------------------------------------
    $midReport = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($midReport['result'] === 'PASS', 'tip 085 with 086+087+088 pending remains apply-ready');
    mrcAssert(
        $midReport['bundle']['expected_pending_checksum'] === MRC_AUTHORIZED_CHECKSUM_086,
        'preflight resolves the pending 086 checksum'
    );

    $applied086 = MigrationExecutionService::apply($pdo, $source, null, '086');
    mrcAssert($applied086['pending'] === ['086'], 'a targeted request applies exactly 086');
    $ledger086 = MigrationExecutionService::ledgerFacts($pdo, $source);
    mrcAssert($ledger086['tip'] === '086', 'production tip is 086 after the apply');
    mrcAssert($ledger086['pending_versions'] === ['087', '088'], '087 and 088 remain pending after 086');
    mrcAssert(
        mrcTableExists($pdo, 'personel_historical_exit_date_correction_auditleri'),
        '086 created the historical exit correction audit owner'
    );
    mrcAssert(
        mrcTriggerExists($pdo, 'trg_phedca_no_update'),
        '086 installed append-only UPDATE protection'
    );
    mrcAssert(
        mrcTriggerExists($pdo, 'trg_phedca_no_delete'),
        '086 installed append-only DELETE protection'
    );
    mrcAssert($baselineCounts === mrcBusinessCounts($pdo), '086 wrote no business row');

    // -----------------------------------------------------------------
    // 4) Apply exactly 087
    // -----------------------------------------------------------------
    $mid087Report = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($mid087Report['result'] === 'PASS', 'tip 086 with 087+088 pending remains apply-ready');
    mrcAssert(
        $mid087Report['bundle']['expected_pending_checksum'] === MRC_AUTHORIZED_CHECKSUM_087,
        'preflight resolves the pending 087 checksum'
    );

    $applied087 = MigrationExecutionService::apply($pdo, $source, null, '087');
    mrcAssert($applied087['pending'] === ['087'], 'a targeted request applies exactly 087');
    $ledger087 = MigrationExecutionService::ledgerFacts($pdo, $source);
    mrcAssert($ledger087['tip'] === '087', 'production tip is 087 after the apply');
    mrcAssert($ledger087['pending_versions'] === ['088'], '088 remains pending after 087');
    mrcAssert(
        mrcTableExists($pdo, 'sube_muhasebe_yetkilileri'),
        '087 created the branch accounting ACL owner'
    );
    mrcAssert($baselineCounts === mrcBusinessCounts($pdo), '087 wrote no business row');

    // -----------------------------------------------------------------
    // 5) Apply exactly 088
    // -----------------------------------------------------------------
    $mid088Report = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($mid088Report['result'] === 'PASS', 'tip 087 with 088 pending remains apply-ready');
    mrcAssert(
        $mid088Report['bundle']['expected_pending_checksum'] === MRC_AUTHORIZED_CHECKSUM_088,
        'preflight resolves the pending 088 checksum'
    );

    $applied088 = MigrationExecutionService::apply($pdo, $source, null, '088');
    mrcAssert($applied088['pending'] === ['088'], 'a targeted request applies exactly 088');
    $ledger088 = MigrationExecutionService::ledgerFacts($pdo, $source);
    mrcAssert($ledger088['tip'] === '088', 'production tip is 088 after the apply');
    mrcAssert($ledger088['pending_versions'] === [], 'no migration is left pending');
    mrcAssert(
        mrcTableExists($pdo, 'sube_sorumlu_yoneticiler'),
        '088 created the branch manager assignment owner'
    );
    mrcAssert($baselineCounts === mrcBusinessCounts($pdo), '088 wrote no business row');

    // -----------------------------------------------------------------
    // 6) tip 088, pending 0 → no migration request may proceed
    // -----------------------------------------------------------------
    $doneReport = MigrationPreflightReport::collect($pdo, $source, $deployedSha);
    mrcAssert($doneReport['result'] === 'BLOCKED', 'a completed round is not apply-ready again');
    mrcAssert(
        in_array('NO_PENDING_MIGRATIONS', $doneReport['blockers'], true),
        'the blocker names the empty canonical pending chain instead of failing silently'
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
