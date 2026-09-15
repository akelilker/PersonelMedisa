<?php

declare(strict_types=1);

/**
 * MG-SIRKET-SUBE-PROD-MAPPING-001 — DB-backed acceptance for the two new owners:
 * the SELECT-only row-level organisation inventory and the operations-only
 * initial mapping (preflight, backup, single-transaction apply, postcheck).
 *
 * The fixture is shaped like production after migration 079: the real branch id
 * set with its gap at 3, three payroll employers, seven work locations, and every
 * hierarchy relation still NULL. Company codes and names are test-only.
 *
 * Nothing here touches production. Every assertion runs against a disposable
 * database created and dropped by this runner.
 *
 * php tests/php/OrganizationMappingMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\FilesystemMigrationSourceProvider;
use Medisa\Api\Database\MigrationBackupService;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Organizasyon\OrganizationInitialMappingService;
use Medisa\Api\Services\Organizasyon\OrganizationMappingFailure;
use Medisa\Api\Services\Organizasyon\OrganizationMappingInventoryReport;
use Medisa\Api\Services\Organizasyon\OrganizationMappingSpec;

const OMAP_SHA = 'abcdef0123456789abcdef0123456789abcdef01';
const OMAP_TIP = '079';

function omapAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function omapPdo(string $dsn): PDO
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

/** Returns the mapping failure a callable raised, or null on success. */
function omapFailure(callable $callable): ?OrganizationMappingFailure
{
    try {
        $callable();

        return null;
    } catch (OrganizationMappingFailure $exception) {
        return $exception;
    }
}

/** Pre-079 organisation shape, then the real migration on top of it. */
function omapCreateSchema(PDO $pdo): void
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
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
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
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
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
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/079_sirket_sube_hiyerarsisi.sql'));
    // 080 owns `sube_olusturma_auditleri`, which is the only proof an extension
    // branch is legitimate. The inventory must be exercised against the real
    // audit schema, triggers included, not a hand-written stand-in.
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/080_organizasyon_audit_owners.sql'));
    omapSeedLedger($pdo);
}

/**
 * A canonical branch-create audit row, exactly as OrganizasyonAuditWriter writes
 * one. `$kod` is passed separately so a test can record an identity that
 * disagrees with the live branch row.
 */
function omapRecordBranchCreateAudit(PDO $pdo, int $subeId, string $kod, int $actorUserId = 52): void
{
    $pdo->prepare(
        'INSERT INTO sube_olusturma_auditleri
            (sube_id, sirket_id, kod, ad, durum, sgk_isveren_id, departman_ids, departman_ids_hash,
             actor_user_id, request_hash)
         VALUES (:sube_id, NULL, :kod, :ad, :durum, NULL, :departman_ids, :departman_ids_hash,
                 :actor_user_id, :request_hash)'
    )->execute([
        'sube_id' => $subeId,
        'kod' => $kod,
        'ad' => 'Fixture Extension ' . $subeId,
        'durum' => 'AKTIF',
        'departman_ids' => '',
        'departman_ids_hash' => hash('sha256', ''),
        'actor_user_id' => $actorUserId,
        'request_hash' => hash('sha256', 'fixture-' . $subeId . '-' . $kod),
    ]);
}

/** Fingerprint including the audit owner: the inventory may not write there either. */
function omapBranchFingerprint(PDO $pdo): string
{
    $parts = [];
    foreach (['subeler', 'sube_olusturma_auditleri', 'personeller', 'user_subeler'] as $table) {
        $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        $parts[] = $table . ':' . json_encode($rows);
    }

    return hash('sha256', implode('|', $parts));
}

/**
 * Ledger at production tip 079. The mapping backup includes the ledger preimage,
 * and the operation asserts the tip, so the fixture needs a real ledger rather
 * than a stub.
 */
function omapSeedLedger(PDO $pdo): void
{
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/src/Database/migration_ledger.sql'));
    $insert = $pdo->prepare(
        'INSERT INTO medisa_schema_migrations (version, checksum, execution_ms) VALUES (:v, :c, 0)'
    );
    $source = new FilesystemMigrationSourceProvider(realpath(__DIR__ . '/../../api') . '/migrations');
    foreach ($source->all() as $migration) {
        if ((int) $migration['version'] > (int) OMAP_TIP) {
            continue;
        }
        $insert->execute(['v' => $migration['version'], 'c' => $migration['checksum']]);
    }
}

/**
 * Production-shaped data: branch ids 1,2,4..11 (no 3), three payroll employers,
 * seven work locations, every new relation NULL. Branch 1 and 11 carry a legacy
 * name that already contains the company, which is exactly the case that must not
 * be renamed by a guess.
 */
function omapSeed(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO users (id, username, rol) VALUES
            (50, 'branch_manager', 'SUBE_YONETICISI'),
            (51, 'unit_manager', 'BIRIM_AMIRI'),
            (52, 'general_manager', 'GENEL_YONETICI')"
    );
    $pdo->exec(
        "INSERT INTO sgk_isverenler (id, kod, ad) VALUES
            (1, 'TEST-SGK-1', 'Test Payroll One'),
            (2, 'TEST-SGK-2', 'Test Payroll Two'),
            (3, 'TEST-SGK-3', 'Test Payroll Three')"
    );
    $pdo->exec(
        "INSERT INTO subeler (id, kod, ad, sgk_isveren_id) VALUES
            (1, 'TEST-SB-1', 'Test Company A Fabrika', 1),
            (2, 'TEST-SB-2', 'Test Company A Giresun', 1),
            (4, 'TEST-SB-4', 'Test Company A Kayseri', 1),
            (5, 'TEST-SB-5', 'Test Company A Ankara', 1),
            (6, 'TEST-SB-6', 'Test Company A Istanbul', 1),
            (7, 'TEST-SB-7', 'Test Company B', 2),
            (8, 'TEST-SB-8', 'Test Company B Ankara', 2),
            (9, 'TEST-SB-9', 'Test Company B Kayseri', 2),
            (10, 'TEST-SB-10', 'Test Company B Istanbul', 2),
            (11, 'TEST-SB-11', 'Test Company C', 3)"
    );
    $pdo->exec(
        "INSERT INTO calisma_lokasyonlari (id, kod, ad) VALUES
            (1, 'TEST-LOC-1', 'Test Location One'),
            (2, 'TEST-LOC-2', 'Test Location Two'),
            (3, 'TEST-LOC-3', 'Test Location Three'),
            (4, 'TEST-LOC-4', 'Test Location Four'),
            (5, 'TEST-LOC-5', 'Test Location Five'),
            (6, 'TEST-LOC-6', 'Test Location Six'),
            (7, 'TEST-LOC-7', 'Test Location Seven')"
    );
    $pdo->exec(
        "INSERT INTO personeller (id, ad_soyad, sube_id, sgk_isveren_id, calisma_lokasyonu_id) VALUES
            (11, 'Fixture Personel A', 1, 1, 1),
            (12, 'Fixture Personel B', 1, 1, 1),
            (13, 'Fixture Personel C', 2, 1, NULL),
            (14, 'Fixture Personel D', 4, 1, 2),
            (15, 'Fixture Personel E', 5, 1, NULL),
            (16, 'Fixture Personel F', 6, 1, NULL),
            (17, 'Fixture Personel G', 7, 2, 3),
            (18, 'Fixture Personel H', 8, 2, NULL),
            (19, 'Fixture Personel I', 9, 2, NULL),
            (20, 'Fixture Personel J', 10, 2, NULL),
            (21, 'Fixture Personel K', 11, 3, 4),
            (22, 'Fixture Personel L', 11, 3, NULL)"
    );
    $pdo->exec(
        'INSERT INTO user_subeler (user_id, sube_id) VALUES (50, 2), (51, 7), (51, 8)'
    );
}
/**
 * The canonical personnel/reference shape the remediation evidence reads
 * (001 + 065 + 066 columns, plus the user columns the manager link needs).
 *
 * It is seeded into a *separate* database on purpose: the production-shaped
 * fixture the mapping sections use must stay exactly as it was, so widened
 * personnel evidence can never be proven by weakening the mapping fixture.
 *
 * The identity rows are inserted from the owner's own allowlist constant, not
 * from a copy of it, so the fixture cannot drift from the contract it proves.
 */
function omapSeedPersonnelEvidenceSchema(PDO $pdo): void
{
    $pdo->exec(
        "ALTER TABLE personeller
            ADD COLUMN ad VARCHAR(80) NULL,
            ADD COLUMN soyad VARCHAR(80) NULL,
            ADD COLUMN aktif_durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            ADD COLUMN calisan_kapsami VARCHAR(16) NOT NULL DEFAULT 'IC_PERSONEL',
            ADD COLUMN personel_tipi_id INT UNSIGNED NULL,
            ADD COLUMN departman_id INT UNSIGNED NULL,
            ADD COLUMN bolum_id INT UNSIGNED NULL,
            ADD COLUMN birim_id INT UNSIGNED NULL,
            ADD COLUMN gorev_id INT UNSIGNED NULL,
            ADD COLUMN pozisyon_id INT UNSIGNED NULL,
            ADD COLUMN bagli_amir_id INT UNSIGNED NULL"
    );
    foreach ([
        'personel_tipleri' => 'Test Personnel Type',
        'departmanlar' => 'Test Department',
        'bolumler' => 'Test Section',
        'birimler' => 'Test Unit',
        'gorevler' => 'Test Job Title',
        'pozisyonlar' => 'Test Position',
    ] as $table => $label) {
        $pdo->exec(
            'CREATE TABLE ' . $table . ' (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ad VARCHAR(120) NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec("INSERT INTO " . $table . " (id, ad) VALUES (1, '" . $label . "')");
    }

    $pdo->exec(
        'CREATE TABLE actor_identities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            status VARCHAR(32) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        "ALTER TABLE users
            ADD COLUMN ad_soyad VARCHAR(120) NULL,
            ADD COLUMN personel_id INT UNSIGNED NULL,
            ADD COLUMN durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            ADD COLUMN actor_identity_id INT UNSIGNED NULL"
    );
    $pdo->exec("INSERT INTO actor_identities (id, status) VALUES (1, 'VERIFIED')");
    $pdo->exec(
        "INSERT INTO users (id, username, rol, ad_soyad, personel_id, durum, actor_identity_id)
         VALUES (110, 'sinemH', 'GENEL_YONETICI', 'Sinem Hamaloğlu', 173, 'AKTIF', 1)"
    );

    // Two id-allowlisted rows, a fully populated row outside both allowlist keys
    // (the decoy: same shape, different id), one identity-resolvable row and one
    // deliberate duplicate pair that must stay unpublished as ambiguous.
    $pdo->exec(
        "INSERT INTO personeller
            (id, ad_soyad, ad, soyad, sube_id, sgk_isveren_id, calisma_lokasyonu_id,
             calisan_kapsami, personel_tipi_id, departman_id, bolum_id, birim_id, gorev_id,
             pozisyon_id, bagli_amir_id)
         VALUES
            (203, 'Fixture Bounded A', 'Muhammed', 'Mahmud', 1, 1, 1,
             'IC_PERSONEL', 1, 1, 1, 1, 1, 1, 110),
            (210, 'Fixture Bounded B', 'Fahri Taylan', 'Mercan', 6, 1, NULL,
             'DIS_KAYNAK', 1, NULL, NULL, NULL, NULL, NULL, 52),
            (999, 'Decoy Personel', 'Decoy', 'Personel', 1, 1, 1,
             'IC_PERSONEL', 1, 1, 1, 1, 1, 1, 110)"
    );

    $identities = (new ReflectionClassConstant(
        OrganizationMappingInventoryReport::class,
        'REMEDIATION_PERSONNEL_IDENTITIES'
    ))->getValue();
    $insert = $pdo->prepare(
        'INSERT INTO personeller (id, ad_soyad, ad, soyad, sube_id, sgk_isveren_id, bagli_amir_id)
         VALUES (:id, :ad_soyad, :ad, :soyad, 1, 1, 110)'
    );
    $resolved = $identities[0];
    $insert->execute([
        'id' => 220,
        'ad_soyad' => $resolved[0] . ' ' . $resolved[1],
        'ad' => $resolved[0],
        'soyad' => $resolved[1],
    ]);
    $ambiguous = $identities[1];
    foreach ([230, 231] as $ambiguousId) {
        $insert->execute([
            'id' => $ambiguousId,
            'ad_soyad' => $ambiguous[0] . ' ' . $ambiguous[1],
            'ad' => $ambiguous[0],
            'soyad' => $ambiguous[1],
        ]);
    }
}



/** Byte-level fingerprint of every owner the mapping could possibly touch. */
function omapFingerprint(PDO $pdo): string
{
    $parts = [];
    foreach ([
        'users',
        'sirketler',
        'subeler',
        'sgk_isverenler',
        'calisma_lokasyonlari',
        'personeller',
        'user_subeler',
        'user_sirketler',
        'user_sgk_isverenler',
    ] as $table) {
        $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        $parts[] = $table . ':' . json_encode($rows);
    }

    return hash('sha256', implode('|', $parts));
}

/** Fingerprint of exactly the axes this operation must never write. */
function omapPreservedFingerprint(PDO $pdo): string
{
    $parts = [];
    foreach (['personeller', 'user_subeler', 'user_sirketler', 'user_sgk_isverenler'] as $table) {
        $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        $parts[] = $table . ':' . json_encode($rows);
    }

    return hash('sha256', implode('|', $parts));
}

/**
 * The approved decision for this fixture, expressed the way a real operator
 * would: preimage values copied from the inventory, company codes test-only, and
 * the two company-named branches left with no approved short name.
 *
 * @param array<string, mixed> $inventory
 * @return array<string, mixed>
 */
function omapSpecArray(array $inventory): array
{
    $branchCompany = [1 => 'TESTCO-A', 2 => 'TESTCO-A', 4 => 'TESTCO-A', 5 => 'TESTCO-A', 6 => 'TESTCO-A',
        7 => 'TESTCO-B', 8 => 'TESTCO-B', 9 => 'TESTCO-B', 10 => 'TESTCO-B', 11 => 'TESTCO-C'];
    // Ids 7 and 11 are deliberately absent: their short name was never approved.
    $approved = [1 => 'Fabrika', 2 => 'Giresun', 4 => 'Kayseri', 5 => 'Ankara', 6 => 'Istanbul',
        8 => 'Ankara', 9 => 'Kayseri', 10 => 'Istanbul'];
    $sgkCompany = [1 => 'TESTCO-A', 2 => 'TESTCO-B', 3 => 'TESTCO-C'];
    // Locations 5, 6 and 7 are not provably tied to a branch, so they stay
    // deferred instead of being guessed from a name.
    $locationBranch = [1 => 1, 2 => 4, 3 => 7, 4 => 11, 5 => null, 6 => null, 7 => null];

    $branchMappings = [];
    foreach ($inventory['data']['branches'] as $branch) {
        $branchMappings[] = [
            'sube_id' => $branch['id'],
            'expected_kod' => $branch['kod'],
            'expected_ad' => $branch['ad'],
            'expected_sirket_id' => $branch['sirket_id'],
            'expected_sgk_isveren_id' => $branch['sgk_isveren_id'],
            'target_company_kod' => $branchCompany[$branch['id']],
            'approved_ad' => $approved[$branch['id']] ?? null,
        ];
    }

    $sgkMappings = [];
    foreach ($inventory['data']['sgk_employers'] as $employer) {
        $sgkMappings[] = [
            'sgk_isveren_id' => $employer['id'],
            'expected_kod' => $employer['kod'],
            'expected_ad' => $employer['ad'],
            'expected_sirket_id' => $employer['sirket_id'],
            'target_company_kod' => $sgkCompany[$employer['id']],
        ];
    }

    $locationMappings = [];
    foreach ($inventory['data']['work_locations'] as $location) {
        $locationMappings[] = [
            'calisma_lokasyonu_id' => $location['id'],
            'expected_kod' => $location['kod'],
            'expected_ad' => $location['ad'],
            'expected_sube_id' => $location['sube_id'],
            'target_sube_id' => $locationBranch[$location['id']],
        ];
    }

    $counts = $inventory['data']['row_counts'];

    return [
        'schema_version' => '1',
        'metadata' => [
            'authorized_deploy_sha' => OMAP_SHA,
            'expected_production_tip' => OMAP_TIP,
            'inventory_checksum' => $inventory['inventory_checksum'],
            'inventory_generated_at' => $inventory['generated_at'],
            'expected_row_counts' => $counts,
            'expected_branch_ids' => $inventory['data']['branch_ids'],
            'operation_id' => 'TEST-ONLY-MAPPING-001',
        ],
        'companies' => [
            ['kod' => 'TESTCO-A', 'ad' => 'Test Company A', 'durum' => 'AKTIF'],
            ['kod' => 'TESTCO-B', 'ad' => 'Test Company B', 'durum' => 'AKTIF'],
            ['kod' => 'TESTCO-C', 'ad' => 'Test Company C', 'durum' => 'AKTIF'],
        ],
        'sgk_mappings' => $sgkMappings,
        'branch_mappings' => $branchMappings,
        'location_mappings' => $locationMappings,
        'preservation' => [
            'expected_personel_count' => $counts['personeller'],
            'expected_user_sube_count' => $counts['user_subeler'],
            'expected_user_sirket_count' => $counts['user_sirketler'],
            'expected_user_sgk_isveren_count' => $counts['user_sgk_isverenler'],
        ],
    ];
}

/** @return array<string, mixed> */
function omapVerifiedBackup(PDO $pdo, string $apiDirectory, OrganizationMappingSpec $spec): array
{
    return MigrationBackupService::createForOrganizationMapping(
        $pdo,
        $apiDirectory,
        $spec->operationId(),
        OMAP_TIP,
        $spec->authorizedDeploySha(),
        $spec->inventoryChecksum(),
        $spec->checksum()
    );
}

function omapRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);

        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        omapRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
}

// ---------------------------------------------------------------------------

$dsn = getenv('MEDISA_TEST_MYSQL_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo 'SKIP: Disposable MariaDB is not configured (MEDISA_TEST_MYSQL_DSN).' . PHP_EOL;
    exit(0);
}

$suffix = bin2hex(random_bytes(5));
$db = 'medisa_orgmap_' . $suffix;
$conflictDb = 'medisa_orgmap_conflict_' . $suffix;
$partialDb = 'medisa_orgmap_partial_' . $suffix;
$extDb = 'medisa_orgmap_ext_' . $suffix;
$extBlockedDb = 'medisa_orgmap_extblocked_' . $suffix;
$evidenceDb = 'medisa_orgmap_evidence_' . $suffix;

$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = omapPdo($rootDsn);
foreach ([$db, $conflictDb, $partialDb, $extDb, $extBlockedDb, $evidenceDb] as $name) {
    $root->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

$apiDirectory = realpath(__DIR__ . '/../../api');
$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa-orgmap-' . $suffix;
mkdir($sandbox . DIRECTORY_SEPARATOR . 'backups', 0700, true);
putenv('MEDISA_MIGRATION_BACKUP_DIR=' . $sandbox . DIRECTORY_SEPARATOR . 'backups');

$pdo = omapPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));

try {
    omapCreateSchema($pdo);
    omapSeed($pdo);
    OrganizasyonSchema::resetCache();

    // -----------------------------------------------------------------------
    // 1) Inventory: read-only, exact, deterministic
    // -----------------------------------------------------------------------
    $before = omapFingerprint($pdo);
    $inventory = OrganizationMappingInventoryReport::collect($pdo, OMAP_SHA, OMAP_TIP);
    omapAssert(
        omapFingerprint($pdo) === $before,
        'the inventory leaves every organisation row byte-identical'
    );

    omapAssert($inventory['result'] === 'PASS', 'the production-shaped inventory reports PASS');
    omapAssert(
        $inventory['schema_ready'] === true && $inventory['data_ready'] === false,
        'schema is ready and data is not, exactly as production reports after 079'
    );
    omapAssert(
        $inventory['data']['branch_ids'] === [1, 2, 4, 5, 6, 7, 8, 9, 10, 11],
        'the exact branch id set is reported, gap included'
    );
    omapAssert($inventory['data']['id_3_present'] === false, 'branch id 3 is reported absent, never invented');
    omapAssert(
        $inventory['data']['unexpected_branch_ids'] === [] && $inventory['data']['missing_branch_ids'] === [],
        'the observed branch set matches the documented one with no difference'
    );
    omapAssert(
        $inventory['data']['baseline_branch_ids'] === [1, 2, 4, 5, 6, 7, 8, 9, 10, 11]
            && $inventory['data']['audited_extension_branch_ids'] === []
            && $inventory['data']['expected_branch_count'] === 10
            && $inventory['data']['branch_set_valid'] === true,
        'a baseline-only database is provable with no extension at all'
    );

    $branchOne = $inventory['data']['branches'][0];
    omapAssert(
        $branchOne['id'] === 1
            && $branchOne['kod'] === 'TEST-SB-1'
            && $branchOne['ad'] === 'Test Company A Fabrika'
            && $branchOne['durum'] === 'AKTIF'
            && $branchOne['sirket_id'] === null
            && $branchOne['sgk_isveren_id'] === 1,
        'each branch row carries its exact id, kod, ad, durum and both relations'
    );
    omapAssert(
        $branchOne['personel_count'] === 2
            && $branchOne['calisma_lokasyonu_count'] === 0
            && $branchOne['user_sube_assignment_count'] === 0,
        'each branch row carries its personnel, location and assignment counts'
    );
    $branchTwo = $inventory['data']['branches'][1];
    omapAssert(
        $branchTwo['id'] === 2 && $branchTwo['user_sube_assignment_count'] === 1,
        'an existing branch assignment is counted on the branch that holds it'
    );

    $employerOne = $inventory['data']['sgk_employers'][0];
    omapAssert(
        $employerOne['id'] === 1
            && $employerOne['kod'] === 'TEST-SGK-1'
            && $employerOne['sirket_id'] === null
            && $employerOne['personel_count'] === 6
            && $employerOne['linked_branch_ids'] === [1, 2, 4, 5, 6]
            && $employerOne['linked_branch_count'] === 5,
        'each payroll employer carries its provable branch links and personnel count'
    );

    $locationOne = $inventory['data']['work_locations'][0];
    omapAssert(
        $locationOne['id'] === 1
            && $locationOne['kod'] === 'TEST-LOC-1'
            && $locationOne['sube_id'] === null
            && $locationOne['personel_count'] === 2,
        'each work location carries its exact row plus its personnel count'
    );
    omapAssert(count($inventory['data']['work_locations']) === 7, 'every work location is inventoried');

    omapAssert(
        $inventory['data']['personnel_location_branch_matrix'] === [
            ['calisma_lokasyonu_id' => 1, 'sube_id' => 1, 'personel_count' => 2],
            ['calisma_lokasyonu_id' => 2, 'sube_id' => 4, 'personel_count' => 1],
            ['calisma_lokasyonu_id' => 3, 'sube_id' => 7, 'personel_count' => 1],
            ['calisma_lokasyonu_id' => 4, 'sube_id' => 11, 'personel_count' => 1],
        ],
        'the location x branch matrix is exact, anonymous and deterministically ordered'
    );
    omapAssert(
        $inventory['data']['personnel_without_location_by_branch'] === [
            ['sube_id' => 2, 'personel_count' => 1],
            ['sube_id' => 5, 'personel_count' => 1],
            ['sube_id' => 6, 'personel_count' => 1],
            ['sube_id' => 8, 'personel_count' => 1],
            ['sube_id' => 9, 'personel_count' => 1],
            ['sube_id' => 10, 'personel_count' => 1],
            ['sube_id' => 11, 'personel_count' => 1],
        ],
        'personnel without a work location are aggregated per branch, exactly'
    );
    omapAssert(
        $inventory['data']['personnel_location_matrix_total'] === 5
            && $inventory['data']['personnel_without_location_total'] === 7
            && $inventory['data']['personnel_location_matrix_total']
                + $inventory['data']['personnel_without_location_total']
                === $inventory['data']['row_counts']['personeller'],
        'matrix total plus without-location total equals every personnel row'
    );
    omapAssert(
        $inventory['data']['personnel_matrix_reconciled'] === true
            && !in_array('INVENTORY_PERSONNEL_MATRIX_COUNT_MISMATCH', $inventory['blockers'], true),
        'the matrix reconciles against the personnel count and raises no mismatch blocker'
    );
    $matrixKeys = [];
    foreach ($inventory['data']['personnel_location_branch_matrix'] as $entry) {
        $matrixKeys = array_merge($matrixKeys, array_keys($entry));
    }
    foreach ($inventory['data']['personnel_without_location_by_branch'] as $entry) {
        $matrixKeys = array_merge($matrixKeys, array_keys($entry));
    }
    omapAssert(
        array_values(array_unique($matrixKeys)) === ['calisma_lokasyonu_id', 'sube_id', 'personel_count'],
        'the matrix publishes relation ids and a count, and nothing else'
    );
    omapAssert(
        $inventory['schema_version'] === '6',
        'the extended inventory contract is published as schema version 6'
    );
    omapAssert(
        $inventory['data']['allowed_personnel'] === []
            && $inventory['data']['allowed_users'] === []
            && $inventory['data']['personnel_evidence']['available'] === false
            && $inventory['data']['personnel_evidence']['boundary_violation_ids'] === []
            && $inventory['data']['personnel_evidence']['id_allowlist']
                === [160, 173, 200, 201, 203, 204, 205, 206, 209, 210, 211, 212, 213, 217],
        'the allowlisted evidence sections stay empty when the required source schema is absent'
    );
    omapAssert(
        $inventory['data']['manager_evidence'] === ['exists' => false, 'row_count' => 0, 'rows' => []]
            && $inventory['data']['a1_policy_evidence'] === [],
        'optional manager and A1 owners report their absent schema without a false failure'
    );

    omapAssert(
        $inventory['data']['scope_summary']['user_sube_total'] === 3
            && $inventory['data']['scope_summary']['user_sirket_total'] === 0
            && $inventory['data']['scope_summary']['user_sgk_isveren_total'] === 0,
        'scope totals are reported and both new scope tables are proven empty'
    );
    $byRole = [];
    foreach ($inventory['data']['scope_summary']['user_sube_by_role'] as $entry) {
        $byRole[$entry['rol']] = $entry['assignment_count'];
    }
    omapAssert(
        $byRole === ['BIRIM_AMIRI' => 2, 'SUBE_YONETICISI' => 1],
        'branch assignments are grouped per role without naming a user'
    );
    $serialized = json_encode($inventory);
    omapAssert(
        strpos((string) $serialized, 'Fixture Personel') === false
            && strpos((string) $serialized, 'branch_manager') === false,
        'no personnel name and no username reaches the inventory payload'
    );

    $rerun = OrganizationMappingInventoryReport::collect($pdo, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $rerun['inventory_checksum'] === $inventory['inventory_checksum'],
        'two inventories of an unchanged database produce the same checksum'
    );
    omapAssert(
        OrganizationMappingInventoryReport::checksum($inventory['data']) === $inventory['inventory_checksum'],
        'the published checksum is reproducible from the data section alone'
    );

    // -----------------------------------------------------------------------
    // 1b) Bounded personnel remediation evidence
    // -----------------------------------------------------------------------
    // A dedicated database: the remediation evidence needs the canonical
    // personnel/reference shape, and proving it must not touch the
    // production-shaped fixture the mapping sections below rely on.
    $evidence = omapPdo(preg_replace('/dbname=[^;]+/i', 'dbname=' . $evidenceDb, $dsn) ?: $dsn);
    omapCreateSchema($evidence);
    omapSeed($evidence);
    omapSeedPersonnelEvidenceSchema($evidence);
    OrganizasyonSchema::resetCache();

    $evidenceBefore = omapFingerprint($evidence);
    $evidenceInventory = OrganizationMappingInventoryReport::collect($evidence, OMAP_SHA, OMAP_TIP);
    omapAssert(
        omapFingerprint($evidence) === $evidenceBefore,
        'the personnel evidence read is SELECT-only: every bounded organisation row stays byte-identical'
    );

    $evidenceSummary = $evidenceInventory['data']['personnel_evidence'];
    $publishedIds = [];
    $publishedById = [];
    foreach ($evidenceInventory['data']['allowed_personnel'] as $row) {
        $publishedIds[] = $row['id'];
        $publishedById[$row['id']] = $row;
    }
    omapAssert(
        $publishedIds === [203, 210, 220],
        'only the bounded allowlist rows are published: two by canonical id, one resolved from its identity'
    );
    omapAssert(
        $evidenceSummary['available'] === true
            && $evidenceSummary['published_count'] === 3
            && $publishedById[203]['provenance'] === 'id_allowlist'
            && $publishedById[220]['provenance'] === 'identity_resolution',
        'each published row states which allowlist key admitted it'
    );
    omapAssert(
        strpos((string) json_encode($evidenceInventory), 'Decoy Personel') === false
            && !in_array(999, $publishedIds, true),
        'a fully populated personnel row outside both allowlist keys never reaches the payload'
    );
    omapAssert(
        !in_array(230, $publishedIds, true) && !in_array(231, $publishedIds, true),
        'an ambiguous identity publishes no row at all'
    );
    omapAssert(
        $evidenceSummary['boundary_violation_ids'] === []
            && !in_array('REMEDIATION_PERSONNEL_ALLOWLIST_BOUNDARY_VIOLATION', $evidenceInventory['blockers'], true),
        'a read that stays inside the allowlist raises no boundary violation blocker'
    );

    $resolution = [];
    foreach ($evidenceSummary['identity_resolution'] as $entry) {
        $resolution[$entry['key']] = [$entry['state'], $entry['matched_row_ids']];
    }
    omapAssert(
        $resolution === [
            'Ahmet Kaçar' => ['RESOLVED', [220]],
            'Aysun Özdemir' => ['AMBIGUOUS', [230, 231]],
            'Esat Kaçar' => ['UNRESOLVED', []],
            'Melih Güler' => ['UNRESOLVED', []],
            'Mustafa Mahmud' => ['UNRESOLVED', []],
        ],
        'a business-truth identity resolves once, is reported unresolved, or publishes nothing as ambiguous'
    );
    omapAssert(
        $evidenceSummary['id_allowlist'] === [160, 173, 200, 201, 203, 204, 205, 206, 209, 210, 211, 212, 213, 217]
            && $evidenceSummary['id_allowlist_matched_ids'] === [203, 210]
            && $evidenceSummary['id_allowlist_missing_ids'] === [160, 173, 200, 201, 204, 205, 206, 209, 211, 212, 213, 217]
            && $evidenceSummary['evidence_complete'] === false,
        'the bounded id allowlist reports every member it could not find instead of widening itself'
    );

    omapAssert(
        $publishedById[203]['bagli_amir_id'] === 110
            && $publishedById[203]['bagli_amir_user_id'] === 110
            && $publishedById[203]['bagli_amir_username'] === 'sinemH'
            && $publishedById[203]['bagli_amir_personel_id'] === 173
            && $publishedById[203]['bagli_amir_ad_soyad'] === 'Sinem Hamaloğlu'
            && $publishedById[203]['bagli_amir_identity_published'] === true,
        'bagli_amir_id is a users.id and its identity is copied from the published user allowlist'
    );
    omapAssert(
        (int) $evidence->query('SELECT COUNT(*) FROM personeller WHERE id = 110')->fetchColumn() === 0,
        'the published bagli_amir_id is a users.id, not a personnel id'
    );
    omapAssert(
        $publishedById[210]['bagli_amir_id'] === 52
            && $publishedById[210]['bagli_amir_user_id'] === null
            && $publishedById[210]['bagli_amir_username'] === null
            && $publishedById[210]['bagli_amir_personel_id'] === null
            && $publishedById[210]['bagli_amir_ad_soyad'] === null
            && $publishedById[210]['bagli_amir_identity_published'] === false,
        'a manager outside the user allowlist contributes the relation id and no identity'
    );
    omapAssert(
        $publishedById[203]['durum'] === 'AKTIF'
            && $publishedById[203]['calisan_kapsami'] === 'IC_PERSONEL'
            && $publishedById[203]['sirket_id'] === null
            && $publishedById[203]['sube_id'] === 1
            && $publishedById[203]['personel_tipi_id'] === 1
            && $publishedById[203]['personel_tipi_label'] === 'Test Personnel Type'
            && $publishedById[203]['calisma_lokasyonu_id'] === 1
            && $publishedById[203]['calisma_lokasyonu_label'] === 'Test Location One'
            && $publishedById[203]['sgk_isveren_id'] === 1
            && $publishedById[203]['sgk_isveren_label'] === 'Test Payroll One'
            && $publishedById[203]['departman_id'] === 1
            && $publishedById[203]['departman_label'] === 'Test Department'
            && $publishedById[203]['bolum_id'] === 1
            && $publishedById[203]['bolum_label'] === 'Test Section'
            && $publishedById[203]['birim_id'] === 1
            && $publishedById[203]['birim_label'] === 'Test Unit'
            && $publishedById[203]['gorev_unvan'] === 'Test Job Title'
            && $publishedById[203]['pozisyon_id'] === 1
            && $publishedById[203]['pozisyon_label'] === 'Test Position'
            && $publishedById[210]['calisan_kapsami'] === 'DIS_KAYNAK'
            && $publishedById[210]['calisma_lokasyonu_id'] === null,
        'each bounded row publishes its canonical org relations with catalogue labels only'
    );
    // The evidence database is evidence only: the mapping sections below must
    // keep reading the production-shaped fixture they were pinned to.
    OrganizasyonSchema::resetCache();

    // -----------------------------------------------------------------------
    // 2) Spec validation
    // -----------------------------------------------------------------------
    $specArray = omapSpecArray($inventory);
    $spec = OrganizationMappingSpec::parse($specArray);
    omapAssert(
        $spec->inventoryChecksum() === $inventory['inventory_checksum'],
        'a valid spec pins the exact inventory it was written against'
    );

    $unknown = $specArray;
    $unknown['branch_mappings'][0]['sirket_id'] = 9;
    omapAssert(
        (omapFailure(static fn () => OrganizationMappingSpec::parse($unknown)))?->reason === 'SPEC_UNKNOWN_FIELD',
        'an unknown field is rejected instead of silently ignored'
    );

    $duplicateCompany = $specArray;
    $duplicateCompany['companies'][] = ['kod' => 'TESTCO-A', 'ad' => 'Another Name', 'durum' => 'AKTIF'];
    omapAssert(
        (omapFailure(static fn () => OrganizationMappingSpec::parse($duplicateCompany)))?->reason
            === 'SPEC_DUPLICATE_COMPANY_KOD',
        'a duplicate company code is rejected'
    );

    $duplicateBranch = $specArray;
    $duplicateBranch['branch_mappings'][] = $duplicateBranch['branch_mappings'][0];
    omapAssert(
        (omapFailure(static fn () => OrganizationMappingSpec::parse($duplicateBranch)))?->reason
            === 'SPEC_DUPLICATE_BRANCH_MAPPING',
        'a duplicate branch mapping is rejected'
    );

    $withIdThree = $specArray;
    $withIdThree['branch_mappings'][] = [
        'sube_id' => 3,
        'expected_kod' => 'TEST-SB-3',
        'expected_ad' => 'Does Not Exist',
        'expected_sirket_id' => null,
        'expected_sgk_isveren_id' => null,
        'target_company_kod' => 'TESTCO-A',
        'approved_ad' => null,
    ];
    omapAssert(
        (omapFailure(static fn () => OrganizationMappingSpec::parse($withIdThree)))?->reason
            === 'SPEC_FORBIDDEN_BRANCH_ID',
        'branch id 3 is refused by the spec, so it can never be created'
    );

    $incomplete = $specArray;
    array_pop($incomplete['branch_mappings']);
    omapAssert(
        (omapFailure(static fn () => OrganizationMappingSpec::parse($incomplete)))?->reason
            === 'SPEC_BRANCH_MAPPING_INCOMPLETE',
        'a spec that decides only some branches is rejected'
    );

    // 2026-09-15 model: şube şirketi ile SGK işvereninin şirketi farklı olabilir;
    // cross-company eşleşme spec conflict'i değildir. Yalnız işverenin şirket
    // kararı eksikse spec reddedilir (SPEC_SGK_MAPPING_INCOMPLETE).
    $crossCompany = $specArray;
    $crossCompany['branch_mappings'][0]['target_company_kod'] = 'TESTCO-B';
    $crossCompanySpec = OrganizationMappingSpec::parse($crossCompany);
    omapAssert(
        $crossCompanySpec->branchMappings()[0]['target_company_kod'] === 'TESTCO-B',
        'a branch may target a company other than its payroll employer company'
    );

    $withoutEmployerDecision = $specArray;
    array_pop($withoutEmployerDecision['sgk_mappings']);
    omapAssert(
        (omapFailure(static fn () => OrganizationMappingSpec::parse($withoutEmployerDecision)))?->reason
            === 'SPEC_SGK_MAPPING_INCOMPLETE',
        'a branch employer the spec never decides is rejected'
    );

    $unknownCompany = $specArray;
    $unknownCompany['branch_mappings'][0]['target_company_kod'] = 'TESTCO-Z';
    omapAssert(
        (omapFailure(static fn () => OrganizationMappingSpec::parse($unknownCompany)))?->reason
            === 'SPEC_UNKNOWN_COMPANY_REFERENCE',
        'a mapping that targets an undeclared company is rejected'
    );

    $withoutLocations = $specArray;
    unset($withoutLocations['location_mappings']);
    $deferredSpec = OrganizationMappingSpec::parse($withoutLocations);
    omapAssert(
        $deferredSpec->locationMappings() === [],
        'a spec with no location section at all is valid: locations may be deferred entirely'
    );

    $staticFixture = json_decode(
        (string) file_get_contents(__DIR__ . '/../fixtures/organization-mapping-spec.test-only.json'),
        true
    );
    omapAssert(
        OrganizationMappingSpec::parse($staticFixture)->operationId() === 'TEST-ONLY-MAPPING-001',
        'the committed test-only fixture is a structurally valid spec'
    );

    // -----------------------------------------------------------------------
    // 3) Preflight gates
    // -----------------------------------------------------------------------
    $preflight = OrganizationInitialMappingService::preflight($pdo, $spec, OMAP_SHA, OMAP_TIP, 0, $inventory);
    omapAssert($preflight['result'] === 'PASS', 'the preflight passes on the exact inventory it pins');
    omapAssert(
        omapFingerprint($pdo) === $before,
        'the preflight writes nothing at all'
    );
    omapAssert(
        $preflight['planned_changes']['companies_to_create'] === ['TESTCO-A', 'TESTCO-B', 'TESTCO-C']
            && $preflight['planned_changes']['branches_to_map'] === [1, 2, 4, 5, 6, 7, 8, 9, 10, 11]
            && $preflight['planned_changes']['sgk_to_map'] === [1, 2, 3],
        'the preflight publishes the exact delta it would apply'
    );
    omapAssert(
        $preflight['planned_changes']['approved_renames'] === [1, 2, 4, 5, 6, 8, 9, 10]
            && !in_array(7, $preflight['planned_changes']['approved_renames'], true)
            && !in_array(11, $preflight['planned_changes']['approved_renames'], true),
        'only approved short names are planned for rename; ids 7 and 11 keep their name'
    );
    omapAssert(
        $preflight['planned_changes']['locations_deferred'] === [5, 6, 7],
        'unresolved work locations are planned as deferred, not guessed'
    );

    $wrongSha = OrganizationInitialMappingService::preflight(
        $pdo,
        $spec,
        str_repeat('b', 40),
        OMAP_TIP,
        0,
        $inventory
    );
    omapAssert(
        in_array('MAPPING_DEPLOY_SHA_MISMATCH', $wrongSha['blockers'], true) && $wrongSha['result'] === 'BLOCKED',
        'a deploy sha other than the authorized one blocks the operation'
    );
    $wrongTip = OrganizationInitialMappingService::preflight($pdo, $spec, OMAP_SHA, '078', 0, $inventory);
    omapAssert(
        in_array('MAPPING_PROD_TIP_UNEXPECTED', $wrongTip['blockers'], true),
        'an unexpected production migration tip blocks the operation'
    );
    $pending = OrganizationInitialMappingService::preflight($pdo, $spec, OMAP_SHA, OMAP_TIP, 1, $inventory);
    omapAssert(
        in_array('MAPPING_PENDING_MIGRATION_PRESENT', $pending['blockers'], true),
        'a pending migration blocks the mapping operation'
    );

    $tamperedInventory = $inventory;
    $tamperedInventory['data']['row_counts']['personeller'] = 999;
    $tampered = OrganizationInitialMappingService::preflight(
        $pdo,
        $spec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        $tamperedInventory
    );
    omapAssert(
        in_array('MAPPING_INVENTORY_CHECKSUM_MISMATCH', $tampered['blockers'], true),
        'an edited inventory payload fails the checksum instead of being trusted'
    );

    $pdo->exec("UPDATE subeler SET ad = 'Renamed Behind Our Back' WHERE id = 5");
    $drifted = OrganizationInitialMappingService::preflight($pdo, $spec, OMAP_SHA, OMAP_TIP, 0, $inventory);
    omapAssert(
        in_array('MAPPING_BRANCH_PREIMAGE_MISMATCH', $drifted['blockers'], true),
        'a branch whose name moved after the inventory fails the preimage check'
    );
    $pdo->exec("UPDATE subeler SET ad = 'Test Company A Ankara' WHERE id = 5");

    omapAssert(
        omapFailure(static fn () => OrganizationInitialMappingService::apply(
            $pdo,
            $spec,
            OMAP_SHA,
            OMAP_TIP,
            0,
            $inventory,
            ['file' => 'x.sql', 'sha256' => 'not-a-digest', 'readback' => 'VERIFIED']
        ))?->reason === 'MAPPING_BACKUP_EVIDENCE_MISSING',
        'apply refuses to start without a real backup digest'
    );
    omapAssert(
        omapFailure(static fn () => OrganizationInitialMappingService::apply(
            $pdo,
            $spec,
            OMAP_SHA,
            OMAP_TIP,
            0,
            $inventory,
            ['file' => 'x.sql', 'sha256' => str_repeat('a', 64), 'readback' => 'PENDING']
        ))?->reason === 'MAPPING_BACKUP_NOT_VERIFIED',
        'apply refuses to start on an unverified backup'
    );
    omapAssert(
        omapFingerprint($pdo) === $before,
        'a backup-blocked apply leaves the database untouched'
    );

    // -----------------------------------------------------------------------
    // 4) Backup owner
    // -----------------------------------------------------------------------
    $backup = omapVerifiedBackup($pdo, (string) $apiDirectory, $spec);
    omapAssert($backup['readback'] === 'VERIFIED', 'the mapping backup is read back and verified');
    omapAssert(
        $backup['tables'] === [
            'sirketler',
            'subeler',
            'sgk_isverenler',
            'calisma_lokasyonlari',
            'user_subeler',
            'user_sirketler',
            'user_sgk_isverenler',
            'medisa_schema_migrations',
        ],
        'the mapping backup scope covers every table the operation can change'
    );
    omapAssert(
        $backup['row_counts']['subeler'] === 10
            && $backup['row_counts']['sirketler'] === 0
            && $backup['row_counts']['user_subeler'] === 3,
        'the backup manifest records the exact preimage row counts'
    );
    omapAssert(
        $backup['operation'] === 'ORGANIZATION_INITIAL_MAPPING'
            && $backup['operation_id'] === 'TEST-ONLY-MAPPING-001'
            && $backup['authorized_deploy_sha'] === OMAP_SHA
            && $backup['inventory_checksum'] === $inventory['inventory_checksum']
            && $backup['spec_checksum'] === $spec->checksum(),
        'the manifest pins the operation, the authorized sha and both checksums'
    );
    omapAssert(
        $backup['schema_objects']['subeler']['foreign_keys'] >= 2
            && $backup['schema_objects']['subeler']['indexes'] >= 2,
        'the manifest carries index and foreign-key metadata for the backed-up owners'
    );
    omapAssert(
        !array_key_exists('absolute_path', $backup),
        'the published metadata never carries the server path'
    );
    $backupPath = $sandbox . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $backup['file'];
    omapAssert(is_file($backupPath), 'the dump exists on disk outside the webroot');
    omapAssert(
        hash('sha256', (string) file_get_contents($backupPath)) === $backup['sha256'],
        'the dump on disk hashes to the published digest'
    );

    // -----------------------------------------------------------------------
    // 5) Transactional apply
    // -----------------------------------------------------------------------
    $preservedBefore = omapPreservedFingerprint($pdo);
    $applied = OrganizationInitialMappingService::apply(
        $pdo,
        $spec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        $inventory,
        $backup
    );
    omapAssert(
        $applied['applied']['companies_created'] === 3 && $applied['applied']['companies_reused'] === 0,
        'exactly the approved companies are created'
    );
    omapAssert(
        $applied['applied']['branches_mapped'] === 10 && $applied['applied']['sgk_mapped'] === 3,
        'every branch and payroll employer is mapped in one operation'
    );
    omapAssert(
        $applied['applied']['branches_renamed'] === 8,
        'exactly the eight approved short names are applied'
    );
    omapAssert(
        $applied['applied']['locations_mapped'] === 4 && $applied['applied']['locations_deferred'] === 3,
        'provable locations are mapped and unresolved ones stay deferred'
    );

    $names = [];
    foreach ($pdo->query('SELECT id, kod, ad FROM subeler ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $names[(int) $row['id']] = ['kod' => $row['kod'], 'ad' => $row['ad']];
    }
    omapAssert($names[1]['ad'] === 'Fabrika', 'the company prefix is removed only where a short name was approved');
    omapAssert(
        $names[7]['ad'] === 'Test Company B' && $names[11]['ad'] === 'Test Company C',
        'the two branches with no approved short name keep their exact original name'
    );
    omapAssert($names[1]['kod'] === 'TEST-SB-1' && $names[11]['kod'] === 'TEST-SB-11', 'no branch code is changed');
    omapAssert(
        array_keys($names) === [1, 2, 4, 5, 6, 7, 8, 9, 10, 11],
        'every branch id survives and no id 3 is created'
    );
    omapAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM subeler')->fetchColumn() === 10
            && (int) $pdo->query('SELECT COUNT(*) FROM sirketler')->fetchColumn() === 3
            && (int) $pdo->query('SELECT COUNT(*) FROM sgk_isverenler')->fetchColumn() === 3
            && (int) $pdo->query('SELECT COUNT(*) FROM calisma_lokasyonlari')->fetchColumn() === 7,
        'row counts change only where the operation creates a company'
    );
    omapAssert(
        omapPreservedFingerprint($pdo) === $preservedBefore,
        'personeller and every user scope table survive byte-identical'
    );
    omapAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM user_sirketler')->fetchColumn() === 0
            && (int) $pdo->query('SELECT COUNT(*) FROM user_sgk_isverenler')->fetchColumn() === 0,
        'no user scope is granted by the mapping operation'
    );

    // -----------------------------------------------------------------------
    // 6) Postcheck and readiness
    // -----------------------------------------------------------------------
    $postcheck = OrganizationInitialMappingService::postcheck($pdo, $spec, $backup);
    omapAssert($postcheck['result'] === 'PASS', 'the postcheck reports PASS with no unexpected delta');
    omapAssert($postcheck['unexpected_deltas'] === [], 'the postcheck finds no unexpected delta');
    omapAssert(
        $postcheck['readiness']['schema_ready'] === true
            && $postcheck['readiness']['data_ready'] === true
            && $postcheck['readiness']['blocker_count'] === 0,
        'data_ready turns true once companies, branches and payroll employers are mapped'
    );
    omapAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM calisma_lokasyonlari WHERE sube_id IS NULL')->fetchColumn() === 3,
        'three work locations are still unmapped'
    );
    omapAssert(
        $postcheck['readiness']['data_ready'] === true,
        'deferred work locations do not block readiness'
    );
    omapAssert(
        $postcheck['backup_reference']['sha256'] === $backup['sha256']
            && $postcheck['backup_reference']['readback'] === 'VERIFIED',
        'the postcheck carries the backup reference of the operation that produced it'
    );
    omapAssert(
        $postcheck['company_rows'] === 3 && count($postcheck['branch_company_mappings']) === 10,
        'the postcheck evidences every company and every branch decision'
    );

    // -----------------------------------------------------------------------
    // 7) Idempotence and convergent partial state
    // -----------------------------------------------------------------------
    $afterFirstApply = omapFingerprint($pdo);
    $secondInventory = OrganizationMappingInventoryReport::collect($pdo, OMAP_SHA, OMAP_TIP);
    $rerunSpecArray = omapSpecArray($secondInventory);
    // The rerun spec asserts the post-apply state as its preimage and the same
    // targets, which is exactly what a replayed operation looks like.
    $rerunSpec = OrganizationMappingSpec::parse($rerunSpecArray);
    $rerunBackup = omapVerifiedBackup($pdo, (string) $apiDirectory, $rerunSpec);
    $rerunApplied = OrganizationInitialMappingService::apply(
        $pdo,
        $rerunSpec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        $secondInventory,
        $rerunBackup
    );
    omapAssert(
        $rerunApplied['applied']['companies_reused'] === 3
            && $rerunApplied['applied']['companies_created'] === 0
            && $rerunApplied['applied']['branches_already_mapped'] === 10
            && $rerunApplied['applied']['sgk_already_mapped'] === 3,
        'a replay of the same operation is an idempotent no-op'
    );
    omapAssert(
        omapFingerprint($pdo) === $afterFirstApply,
        'the idempotent replay changes no row'
    );

    // -----------------------------------------------------------------------
    // 8) Fail-closed: an incompatible partial state rolls back
    // -----------------------------------------------------------------------
    $partial = omapPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $partialDb, $dsn) ?: $dsn));
    omapCreateSchema($partial);
    omapSeed($partial);
    OrganizasyonSchema::resetCache();
    $partialInventory = OrganizationMappingInventoryReport::collect($partial, OMAP_SHA, OMAP_TIP);
    $partialSpec = OrganizationMappingSpec::parse(omapSpecArray($partialInventory));
    $partialBackup = omapVerifiedBackup($partial, (string) $apiDirectory, $partialSpec);

    // Branch 4 was already attached to a DIFFERENT company by an earlier, partial
    // operation. This owner must refuse rather than re-parent it.
    $partial->exec("INSERT INTO sirketler (id, kod, ad) VALUES (90, 'TESTCO-B', 'Test Company B')");
    $partial->exec('UPDATE subeler SET sirket_id = 90 WHERE id = 4');
    $partialBefore = omapFingerprint($partial);
    OrganizasyonSchema::resetCache();
    $conflictFailure = omapFailure(static fn () => OrganizationInitialMappingService::apply(
        $partial,
        $partialSpec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        $partialInventory,
        $partialBackup
    ));
    omapAssert(
        $conflictFailure?->reason === 'MAPPING_PREFLIGHT_BLOCKED',
        'an incompatible partial state blocks the apply'
    );
    omapAssert(
        strpos((string) $conflictFailure?->detail, 'MAPPING_BRANCH_PREIMAGE_MISMATCH') !== false,
        'the blocker names the branch preimage that no longer matches'
    );
    omapAssert(
        omapFingerprint($partial) === $partialBefore,
        'the blocked apply rolls nothing forward: the partial state is untouched'
    );

    // A branch already pointing at the SAME company the spec targets is the
    // compatible case: it converges instead of failing.
    $partial->exec('UPDATE subeler SET sirket_id = NULL WHERE id = 4');
    $partial->exec('DELETE FROM sirketler WHERE id = 90');
    OrganizasyonSchema::resetCache();
    $convergentInventory = OrganizationMappingInventoryReport::collect($partial, OMAP_SHA, OMAP_TIP);
    $convergentSpec = OrganizationMappingSpec::parse(omapSpecArray($convergentInventory));
    $partial->exec("INSERT INTO sirketler (id, kod, ad) VALUES (91, 'TESTCO-A', 'Test Company A')");
    $partial->exec('UPDATE subeler SET sirket_id = 91 WHERE id = 4');
    $partial->exec('UPDATE sgk_isverenler SET sirket_id = 91 WHERE id = 1');
    OrganizasyonSchema::resetCache();
    // The preimage now says branch 4 already belongs to TESTCO-A, which is what
    // the spec wants, so the operation completes the remaining rows.
    $convergentArray = omapSpecArray(OrganizationMappingInventoryReport::collect($partial, OMAP_SHA, OMAP_TIP));
    $convergentSpec = OrganizationMappingSpec::parse($convergentArray);
    $convergentBackup = omapVerifiedBackup($partial, (string) $apiDirectory, $convergentSpec);
    $convergentApplied = OrganizationInitialMappingService::apply(
        $partial,
        $convergentSpec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        OrganizationMappingInventoryReport::collect($partial, OMAP_SHA, OMAP_TIP),
        $convergentBackup
    );
    omapAssert(
        $convergentApplied['applied']['branches_already_mapped'] === 1
            && $convergentApplied['applied']['branches_mapped'] === 9
            && $convergentApplied['applied']['companies_reused'] === 1,
        'a compatible same-target partial state converges and finishes the rest'
    );
    OrganizasyonSchema::resetCache();
    omapAssert(
        OrganizasyonSchema::report($partial)['data_ready'] === true,
        'the converged database reaches data_ready as well'
    );

    // -----------------------------------------------------------------------
    // 9) Fail-closed: company conflicts and pinned-employer rollback
    // -----------------------------------------------------------------------
    $conflict = omapPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $conflictDb, $dsn) ?: $dsn));
    omapCreateSchema($conflict);
    omapSeed($conflict);
    OrganizasyonSchema::resetCache();
    $conflictInventory = OrganizationMappingInventoryReport::collect($conflict, OMAP_SHA, OMAP_TIP);
    $conflictSpec = OrganizationMappingSpec::parse(omapSpecArray($conflictInventory));

    // Same company code, different name: reusing it would silently rename a
    // company other rows already reference.
    $conflict->exec("INSERT INTO sirketler (id, kod, ad) VALUES (80, 'TESTCO-A', 'Totally Different Name')");
    OrganizasyonSchema::resetCache();
    $conflictBefore = omapFingerprint($conflict);
    $codeConflict = OrganizationInitialMappingService::preflight(
        $conflict,
        $conflictSpec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        $conflictInventory
    );
    omapAssert(
        in_array('MAPPING_COMPANY_CODE_NAME_CONFLICT', $codeConflict['blockers'], true),
        'an existing company code with a different name is a blocker'
    );
    $conflictBackup = omapVerifiedBackup($conflict, (string) $apiDirectory, $conflictSpec);
    omapAssert(
        omapFailure(static fn () => OrganizationInitialMappingService::apply(
            $conflict,
            $conflictSpec,
            OMAP_SHA,
            OMAP_TIP,
            0,
            $conflictInventory,
            $conflictBackup
        ))?->reason === 'MAPPING_PREFLIGHT_BLOCKED',
        'the company conflict blocks the apply'
    );
    omapAssert(
        omapFingerprint($conflict) === $conflictBefore,
        'the company-conflict rollback leaves every row as it was'
    );

    // A company name already taken under a different code is equally refused.
    $conflict->exec("UPDATE sirketler SET kod = 'TESTCO-X', ad = 'Test Company A' WHERE id = 80");
    OrganizasyonSchema::resetCache();
    $nameConflict = OrganizationInitialMappingService::preflight(
        $conflict,
        $conflictSpec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        $conflictInventory
    );
    omapAssert(
        in_array('MAPPING_COMPANY_NAME_TAKEN', $nameConflict['blockers'], true),
        'a company name already used under another code is a blocker'
    );
    $conflict->exec('DELETE FROM sirketler WHERE id = 80');

    // A payroll employer already pinned to another company must roll the whole
    // operation back rather than silently re-parent a mapped row.
    $conflict->exec("INSERT INTO sirketler (id, kod, ad) VALUES (81, 'TESTCO-C', 'Test Company C')");
    $conflict->exec('UPDATE sgk_isverenler SET sirket_id = 81 WHERE id = 1');
    OrganizasyonSchema::resetCache();
    $mismatchInventory = OrganizationMappingInventoryReport::collect($conflict, OMAP_SHA, OMAP_TIP);
    $mismatchSpecArray = omapSpecArray($mismatchInventory);
    // Employer 1 is already in TESTCO-C while the spec decides TESTCO-A for it.
    $mismatchSpecArray['sgk_mappings'][0]['target_company_kod'] = 'TESTCO-A';
    $mismatchSpec = OrganizationMappingSpec::parse($mismatchSpecArray);
    $mismatchBefore = omapFingerprint($conflict);
    $mismatchBackup = omapVerifiedBackup($conflict, (string) $apiDirectory, $mismatchSpec);
    $mismatchFailure = omapFailure(static fn () => OrganizationInitialMappingService::apply(
        $conflict,
        $mismatchSpec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        $mismatchInventory,
        $mismatchBackup
    ));
    omapAssert(
        $mismatchFailure !== null,
        'an employer already pinned to another company is refused'
    );
    omapAssert(
        omapFingerprint($conflict) === $mismatchBefore,
        'the employer-company conflict rollback restores the exact preimage'
    );

    // -----------------------------------------------------------------------
    // 10) Fail-closed: a mid-transaction error rolls back
    // -----------------------------------------------------------------------
    $conflict->exec('UPDATE sgk_isverenler SET sirket_id = NULL WHERE id = 1');
    $conflict->exec('DELETE FROM sirketler WHERE id = 81');
    OrganizasyonSchema::resetCache();
    $errorInventory = OrganizationMappingInventoryReport::collect($conflict, OMAP_SHA, OMAP_TIP);
    $errorSpecArray = omapSpecArray($errorInventory);
    // A company name longer than the column allows makes the INSERT fail after the
    // transaction has already opened, which is the generic mid-apply error case.
    $errorSpecArray['companies'][2]['ad'] = str_repeat('C', 191);
    foreach ($errorSpecArray['branch_mappings'] as $index => $mapping) {
        if ($mapping['target_company_kod'] === 'TESTCO-C') {
            $errorSpecArray['branch_mappings'][$index]['target_company_kod'] = 'TESTCO-C';
        }
    }
    $errorSpec = OrganizationMappingSpec::parse($errorSpecArray);
    $errorBackup = omapVerifiedBackup($conflict, (string) $apiDirectory, $errorSpec);
    $errorBefore = omapFingerprint($conflict);
    $conflict->exec('ALTER TABLE sirketler MODIFY ad VARCHAR(20) NOT NULL');
    $errorFailure = omapFailure(static fn () => OrganizationInitialMappingService::apply(
        $conflict,
        $errorSpec,
        OMAP_SHA,
        OMAP_TIP,
        0,
        $errorInventory,
        $errorBackup
    ));
    omapAssert($errorFailure !== null, 'a mid-transaction database error aborts the operation');
    omapAssert(
        !$conflict->inTransaction(),
        'no transaction is left open after the failure'
    );
    omapAssert(
        omapFingerprint($conflict) === $errorBefore,
        'the mid-transaction error rolls every write back'
    );

    // -----------------------------------------------------------------------
    // 11) Baseline + audited branch extensions
    //
    // The inventory must accept a branch created after the 079 baseline when the
    // canonical create owner recorded it, and must keep blocking everything it
    // cannot prove. Two disposable databases: one walks the passing progression,
    // the other collects the fail-closed cases.
    // -----------------------------------------------------------------------
    $ext = omapPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $extDb, $dsn) ?: $dsn));
    omapCreateSchema($ext);
    omapSeed($ext);
    OrganizasyonSchema::resetCache();

    $extBefore = omapBranchFingerprint($ext);
    $baselineOnly = OrganizationMappingInventoryReport::collect($ext, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $baselineOnly['result'] === 'PASS'
            && $baselineOnly['data']['branch_create_audit_ready'] === true
            && $baselineOnly['data']['audited_extension_branch_ids'] === []
            && $baselineOnly['data']['unaudited_extension_branch_ids'] === []
            && $baselineOnly['data']['expected_branch_count'] === 10
            && $baselineOnly['data']['row_counts']['subeler'] === 10,
        'baseline only: PASS with an empty extension set'
    );

    // An extension branch with no create audit at all.
    $ext->exec(
        "INSERT INTO subeler (id, kod, ad, sgk_isveren_id) VALUES
            (12, 'TEST-SB-12', 'Fixture Extension 12', 1),
            (13, 'TEST-SB-13', 'Fixture Extension 13', 1)"
    );
    OrganizasyonSchema::resetCache();
    $unaudited = OrganizationMappingInventoryReport::collect($ext, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $unaudited['result'] === 'BLOCKED'
            && $unaudited['data']['unaudited_extension_branch_ids'] === [12, 13]
            && $unaudited['data']['audited_extension_branch_ids'] === []
            && $unaudited['data']['unexpected_branch_ids'] === [12, 13]
            && $unaudited['data']['branch_set_valid'] === false
            && in_array('UNEXPECTED_BRANCH_IDS_PRESENT', $unaudited['blockers'], true)
            && in_array('BRANCH_SET_NOT_PROVABLE', $unaudited['blockers'], true),
        'an extension branch without a create audit is a blocker'
    );
    omapAssert(
        $unaudited['data']['expected_branch_count'] === 10,
        'an unprovable extension never grows the expected branch count'
    );

    // The same two branches, now with the canonical create audit behind them:
    // exactly the GATE 3 production shape.
    omapRecordBranchCreateAudit($ext, 12, 'TEST-SB-12');
    omapRecordBranchCreateAudit($ext, 13, 'TEST-SB-13');
    OrganizasyonSchema::resetCache();
    $audited = OrganizationMappingInventoryReport::collect($ext, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $audited['result'] === 'PASS'
            && $audited['blockers'] === []
            && $audited['data']['audited_extension_branch_ids'] === [12, 13]
            && $audited['data']['unaudited_extension_branch_ids'] === []
            && $audited['data']['duplicate_extension_audit_branch_ids'] === []
            && $audited['data']['missing_baseline_branch_ids'] === []
            && $audited['data']['branch_set_valid'] === true,
        'audited extensions 12 and 13 are accepted with no blocker'
    );
    omapAssert(
        $audited['data']['expected_branch_ids'] === [1, 2, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13]
            && $audited['data']['expected_branch_count'] === 12
            && $audited['data']['row_counts']['subeler'] === 12,
        'the expected branch set and count are derived: baseline plus audited extensions'
    );

    // A future legitimate branch must pass without touching this owner again.
    $ext->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id) VALUES (14, 'TEST-SB-14', 'Fixture Extension 14', 1)");
    omapRecordBranchCreateAudit($ext, 14, 'TEST-SB-14');
    OrganizasyonSchema::resetCache();
    $future = OrganizationMappingInventoryReport::collect($ext, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $future['result'] === 'PASS'
            && $future['data']['audited_extension_branch_ids'] === [12, 13, 14]
            && $future['data']['expected_branch_count'] === 13,
        'a future audited extension passes with no code change'
    );
    omapAssert(
        $future['data']['branch_create_audit_ready'] === true,
        'the audit owner is readable, so extension proof is evaluable'
    );

    $extAfter = omapBranchFingerprint($ext);
    OrganizationMappingInventoryReport::collect($ext, OMAP_SHA, OMAP_TIP);
    omapAssert(
        omapBranchFingerprint($ext) === $extAfter && $extBefore !== $extAfter,
        'the classification is SELECT-only: only the fixture changed rows'
    );

    // ---- fail-closed cases ----
    $extBlocked = omapPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $extBlockedDb, $dsn) ?: $dsn));
    omapCreateSchema($extBlocked);
    omapSeed($extBlocked);
    OrganizasyonSchema::resetCache();

    // Duplicate create audit for one branch. The unique key normally makes this
    // impossible, which is exactly why the guard must not depend on it: a legacy
    // or tampered schema without the index still has to be caught.
    $extBlocked->exec('ALTER TABLE sube_olusturma_auditleri DROP FOREIGN KEY fk_soa_sube');
    $extBlocked->exec('ALTER TABLE sube_olusturma_auditleri DROP INDEX uq_soa_sube');
    $extBlocked->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id) VALUES (12, 'TEST-SB-12', 'Fixture Extension 12', 1)");
    omapRecordBranchCreateAudit($extBlocked, 12, 'TEST-SB-12');
    omapRecordBranchCreateAudit($extBlocked, 12, 'TEST-SB-12');
    OrganizasyonSchema::resetCache();
    $duplicate = OrganizationMappingInventoryReport::collect($extBlocked, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $duplicate['result'] === 'BLOCKED'
            && $duplicate['data']['duplicate_extension_audit_branch_ids'] === [12]
            && $duplicate['data']['audited_extension_branch_ids'] === []
            && in_array('DUPLICATE_BRANCH_CREATE_AUDIT', $duplicate['blockers'], true),
        'two create audits for one branch block instead of counting as proof'
    );

    // An audit whose recorded branch identity disagrees with the live row.
    $extBlocked->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id) VALUES (13, 'TEST-SB-13', 'Fixture Extension 13', 1)");
    omapRecordBranchCreateAudit($extBlocked, 13, 'TEST-SB-OTHER');
    OrganizasyonSchema::resetCache();
    $mismatch = OrganizationMappingInventoryReport::collect($extBlocked, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $mismatch['result'] === 'BLOCKED'
            && $mismatch['data']['mismatched_extension_audit_branch_ids'] === [13]
            && !in_array(13, $mismatch['data']['audited_extension_branch_ids'], true)
            && in_array('BRANCH_CREATE_AUDIT_MISMATCH', $mismatch['blockers'], true),
        'a create audit that does not match the live branch identity blocks'
    );

    // A lawful post-creation update must NOT look like tampering: only the branch
    // id and the immutable kod are compared, and the audit row is immutable.
    $extBlocked->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id) VALUES (14, 'TEST-SB-14', 'Fixture Extension 14', 1)");
    omapRecordBranchCreateAudit($extBlocked, 14, 'TEST-SB-14');
    $extBlocked->exec("UPDATE subeler SET ad = 'Renamed Later', durum = 'PASIF' WHERE id = 14");
    OrganizasyonSchema::resetCache();
    $renamed = OrganizationMappingInventoryReport::collect($extBlocked, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $renamed['data']['audited_extension_branch_ids'] === [14]
            && !in_array(14, $renamed['data']['unaudited_extension_branch_ids'], true),
        'a later rename or status change does not invalidate the create audit'
    );

    // Şube şirketi ile SGK işvereninin şirketi farklı olabilir (2026-09-15 model):
    // cross-company eşleşme ne inventory ne readiness blocker'ıdır.
    $extBlocked->exec("INSERT INTO sirketler (id, kod, ad) VALUES (70, 'TESTCO-X', 'Test Company X'), (71, 'TESTCO-Y', 'Test Company Y')");
    $extBlocked->exec('UPDATE subeler SET sirket_id = 70 WHERE id = 1');
    $extBlocked->exec('UPDATE sgk_isverenler SET sirket_id = 71 WHERE id = 1');
    OrganizasyonSchema::resetCache();
    $crossCompanyMapped = OrganizationMappingInventoryReport::collect($extBlocked, OMAP_SHA, OMAP_TIP);
    omapAssert(
        !in_array('BRANCH_SGK_COMPANY_MISMATCH', $crossCompanyMapped['blockers'], true)
            && !in_array('SUBE_SGK_SIRKET_MISMATCH', $crossCompanyMapped['readiness_blockers'], true),
        'a branch mapped to another company payroll employer is not a blocker'
    );
    omapAssert(
        array_keys($crossCompanyMapped['data']['orphan_counts']) === [
            'orphan_sube_sirket_count',
            'orphan_lokasyon_sube_count',
            'unmapped_sube_count',
            'unmapped_sgk_isveren_count',
        ],
        'every orphan counter is still published'
    );

    // A vanished baseline branch stays a blocker.
    $extBlocked->exec('DELETE FROM personeller WHERE sube_id = 6');
    $extBlocked->exec('DELETE FROM subeler WHERE id = 6');
    OrganizasyonSchema::resetCache();
    $missingBaseline = OrganizationMappingInventoryReport::collect($extBlocked, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $missingBaseline['result'] === 'BLOCKED'
            && $missingBaseline['data']['missing_baseline_branch_ids'] === [6]
            && $missingBaseline['data']['missing_branch_ids'] === [6]
            && in_array('DOCUMENTED_BRANCH_IDS_MISSING', $missingBaseline['blockers'], true)
            && $missingBaseline['data']['branch_set_valid'] === false,
        'a missing baseline branch is still a blocker'
    );

    // Branch id 3 is still refused, even if someone audits its creation.
    $extBlocked->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id) VALUES (3, 'TEST-SB-3', 'Never Existed', 1)");
    omapRecordBranchCreateAudit($extBlocked, 3, 'TEST-SB-3');
    OrganizasyonSchema::resetCache();
    $idThree = OrganizationMappingInventoryReport::collect($extBlocked, OMAP_SHA, OMAP_TIP);
    omapAssert(
        $idThree['data']['id_3_present'] === true
            && !in_array(3, $idThree['data']['audited_extension_branch_ids'], true)
            && in_array('UNEXPECTED_BRANCH_ID_3_PRESENT', $idThree['blockers'], true)
            && $idThree['result'] === 'BLOCKED',
        'an audit cannot legitimise branch id 3'
    );

    echo 'verify-organization-mapping-mysql: OK' . PHP_EOL;
} finally {
    putenv('MEDISA_MIGRATION_BACKUP_DIR');
    omapRemoveTree($sandbox);
    foreach ([$db, $conflictDb, $partialDb, $extDb, $extBlockedDb, $evidenceDb] as $name) {
        $root->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    }
}
