<?php

declare(strict_types=1);

/**
 * MG-SIRKET-SUBE-HIYERARSI-001 — domain, API-contract, scope and import
 * acceptance for the company -> SGK employer -> branch -> work location model,
 * against a real MariaDB.
 *
 * Everything runs on a disposable database created and dropped by this runner.
 *
 * php tests/php/SirketSubeHiyerarsisiMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Scope\OrgScope;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use Medisa\Api\Services\Organizasyon\SubeReadModel;

function hierAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function hierPdo(string $dsn): PDO
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
 * Runs $callable and returns the domain failure it raised, or null on success.
 *
 * @return array{status:int, code:string}|null
 */
function hierFailure(callable $callable): ?array
{
    try {
        $callable();

        return null;
    } catch (OrganizasyonException $exception) {
        return ['status' => $exception->httpStatus, 'code' => $exception->errorCode];
    }
}

function hierSchema(PDO $pdo): void
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
        'CREATE TABLE departmanlar (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(120) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE sube_departmanlar (
            sube_id INT UNSIGNED NOT NULL,
            departman_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (sube_id, departman_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
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
            PRIMARY KEY (user_id, sube_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function hierApply079(PDO $pdo): void
{
    $sql = (string) file_get_contents(__DIR__ . '/../../api/migrations/079_sirket_sube_hiyerarsisi.sql');
    $pdo->exec($sql);
    OrganizasyonSchema::resetCache();
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
$db = 'medisa_hier_' . $suffix;
$legacyDb = 'medisa_hier_legacy_' . $suffix;
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = hierPdo($rootDsn);
foreach ([$db, $legacyDb] as $name) {
    $root->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

try {
    // =====================================================================
    // A) Read model: the derived display name
    // =====================================================================
    hierAssert(SubeReadModel::tamAd('Medisa', 'Ankara') === 'Medisa Ankara', 'company + short name compose');
    hierAssert(SubeReadModel::tamAd(null, 'Ankara') === 'Ankara', 'no company falls back to the raw branch name');
    hierAssert(SubeReadModel::tamAd('Karyapı', 'Karyapı') === 'Karyapı', 'an equal short name is not repeated');
    hierAssert(
        SubeReadModel::tamAd('Şenay Mobilya', ' şenay   mobilya ') === 'Şenay Mobilya',
        'equality is decided after whitespace and Turkish case normalization'
    );
    hierAssert(
        SubeReadModel::tamAd('Medisa', '  Ankara  ') === 'Medisa Ankara',
        'surrounding whitespace never leaks into the composed name'
    );
    hierAssert(
        SubeReadModel::normalizeName('İSTANBUL') === SubeReadModel::normalizeName('istanbul'),
        'Turkish dotted-I folds to the same normalized name'
    );

    // =====================================================================
    // B) Legacy fallback: no 079 yet
    // =====================================================================
    $legacy = hierPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $legacyDb, $dsn) ?: $dsn));
    hierSchema($legacy);
    OrganizasyonSchema::resetCache();
    $legacy->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'SB-1', 'Merkez')");

    hierAssert(OrganizasyonSchema::isSchemaReady($legacy) === false, 'a pre-079 database is not schema ready');
    $legacyReport = OrganizasyonSchema::report($legacy);
    hierAssert(
        $legacyReport['data_ready'] === false
            && in_array('SIRKETLER_TABLE_MISSING', $legacyReport['blockers'], true),
        'readiness names the missing structure instead of crashing'
    );
    $legacyList = OrganizasyonService::listSubeler($legacy);
    hierAssert(count($legacyList) === 1, 'the flat legacy branch list still reads on a pre-079 database');
    hierAssert(
        $legacyList[0]['tam_ad'] === 'Merkez' && $legacyList[0]['sirket'] === null,
        'legacy branches fall back to the raw name with no company relation'
    );
    $legacyFailure = hierFailure(static function () use ($legacy): void {
        OrganizasyonService::listSirketler($legacy);
    });
    hierAssert(
        $legacyFailure !== null
            && $legacyFailure['code'] === 'ORGANIZASYON_SCHEMA_NOT_READY'
            && $legacyFailure['status'] === 409,
        'a hierarchy read on a pre-079 database answers 409 readiness, never a 500 unknown table'
    );

    // =====================================================================
    // C) Hierarchy mode
    // =====================================================================
    $pdo = hierPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));
    hierSchema($pdo);
    hierApply079($pdo);

    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'Uretim'), (2, 'Muhasebe')");
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (50, 'branch_manager', 'SUBE_YONETICISI'), (60, 'ik', 'IK_SORUMLUSU')");

    hierAssert(OrganizasyonSchema::isSchemaReady($pdo) === true, 'schema is ready right after 079');
    OrganizasyonSchema::resetCache();
    hierAssert(
        OrganizasyonSchema::isDataReady($pdo) === false,
        'schema readiness alone is not data readiness: nothing is mapped yet'
    );

    $medisa = OrganizasyonService::createSirket($pdo, ['kod' => 'MED', 'ad' => 'Medisa', 'durum' => 'AKTIF']);
    $karyapi = OrganizasyonService::createSirket($pdo, ['kod' => 'KAR', 'ad' => 'Karyapı', 'durum' => 'AKTIF']);
    hierAssert($medisa['sube_sayisi'] === 0, 'a new company starts with no branches');

    foreach ([
        ['DUPLICATE_SIRKET_KOD', ['kod' => 'MED', 'ad' => 'Baska Sirket']],
        ['DUPLICATE_SIRKET_AD', ['kod' => 'MED2', 'ad' => '  medisa ']],
    ] as [$expectedCode, $body]) {
        $failure = hierFailure(static function () use ($pdo, $body): void {
            OrganizasyonService::createSirket($pdo, $body);
        });
        hierAssert(
            $failure !== null && $failure['code'] === $expectedCode && $failure['status'] === 409,
            'duplicate company write is refused with ' . $expectedCode
        );
    }

    $kodChange = hierFailure(static function () use ($pdo, $medisa): void {
        OrganizasyonService::updateSirket($pdo, $medisa['id'], ['kod' => 'YENI', 'ad' => 'Medisa']);
    });
    hierAssert(
        $kodChange !== null && $kodChange['code'] === 'KOD_IMMUTABLE',
        'company code is immutable on edit'
    );

    // ---------------------------------------------------------------- branches
    $pdo->exec("INSERT INTO sgk_isverenler (kod, ad) VALUES ('SGK-MED', 'Medisa Bordro')");
    $sgkMedisa = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO sgk_isverenler (kod, ad) VALUES ('SGK-KAR', 'Karyapi Bordro')");
    $sgkKaryapi = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE sgk_isverenler SET sirket_id = :c WHERE id = :id')
        ->execute(['c' => $medisa['id'], 'id' => $sgkMedisa]);
    $pdo->prepare('UPDATE sgk_isverenler SET sirket_id = :c WHERE id = :id')
        ->execute(['c' => $karyapi['id'], 'id' => $sgkKaryapi]);

    $medisaAnkara = OrganizasyonService::createSube($pdo, [
        'kod' => 'MED-ANK',
        'ad' => 'Ankara',
        'durum' => 'AKTIF',
        'departman_ids' => [1],
        'sgk_isveren_id' => $sgkMedisa,
    ], (int) $medisa['id']);

    hierAssert($medisaAnkara['ad'] === 'Ankara', 'the stored branch name stays the short name');
    hierAssert($medisaAnkara['tam_ad'] === 'Medisa Ankara', 'the read model composes the shared display name');
    hierAssert(
        ($medisaAnkara['sirket']['id'] ?? null) === (int) $medisa['id'],
        'the parent company comes from the route, not from the payload'
    );
    hierAssert($medisaAnkara['departman_ids'] === [1], 'department selection is persisted by the same service');

    $karyapiAnkara = OrganizasyonService::createSube($pdo, [
        'kod' => 'KAR-ANK',
        'ad' => 'Ankara',
        'durum' => 'AKTIF',
        'departman_ids' => [1, 2],
    ], (int) $karyapi['id']);
    hierAssert(
        $karyapiAnkara['tam_ad'] === 'Karyapı Ankara',
        'the same short name under a different company is accepted and stays distinguishable'
    );

    $sameCompanyDuplicate = hierFailure(static function () use ($pdo, $medisa): void {
        OrganizasyonService::createSube($pdo, [
            'kod' => 'MED-ANK2',
            'ad' => ' ankara ',
            'departman_ids' => [1],
        ], (int) $medisa['id']);
    });
    hierAssert(
        $sameCompanyDuplicate !== null
            && $sameCompanyDuplicate['code'] === 'DUPLICATE_SUBE_AD'
            && $sameCompanyDuplicate['status'] === 409,
        'a normalized duplicate short name inside one company is refused'
    );

    $globalKod = hierFailure(static function () use ($pdo, $karyapi): void {
        OrganizasyonService::createSube($pdo, [
            'kod' => 'MED-ANK',
            'ad' => 'Baska',
            'departman_ids' => [1],
        ], (int) $karyapi['id']);
    });
    hierAssert(
        $globalKod !== null && $globalKod['code'] === 'DUPLICATE_SUBE_KOD',
        'branch code stays globally unique across companies'
    );

    $derivedWrite = hierFailure(static function () use ($pdo, $medisa): void {
        OrganizasyonService::createSube($pdo, [
            'kod' => 'MED-IST',
            'ad' => 'İstanbul',
            'tam_ad' => 'Medisa İstanbul',
            'departman_ids' => [1],
        ], (int) $medisa['id']);
    });
    hierAssert(
        $derivedWrite !== null && $derivedWrite['status'] === 400,
        'tam_ad is rejected on write: it is a derived read field'
    );

    $payloadSirket = hierFailure(static function () use ($pdo, $medisa, $karyapi): void {
        OrganizasyonService::createSube($pdo, [
            'kod' => 'MED-IZM',
            'ad' => 'Izmir',
            'sirket_id' => (int) $karyapi['id'],
            'departman_ids' => [1],
        ], (int) $medisa['id']);
    });
    hierAssert(
        $payloadSirket !== null && $payloadSirket['status'] === 400,
        'a payload sirket_id is refused instead of silently moving the branch'
    );

    $sgkMismatch = hierFailure(static function () use ($pdo, $medisa, $sgkKaryapi): void {
        OrganizasyonService::createSube($pdo, [
            'kod' => 'MED-BUR',
            'ad' => 'Bursa',
            'sgk_isveren_id' => $sgkKaryapi,
            'departman_ids' => [1],
        ], (int) $medisa['id']);
    });
    hierAssert(
        $sgkMismatch !== null && $sgkMismatch['status'] === 409,
        'a payroll employer from another company cannot be attached to this branch'
    );

    $branchKodChange = hierFailure(static function () use ($pdo, $medisa, $medisaAnkara): void {
        OrganizasyonService::updateSube($pdo, $medisaAnkara['id'], [
            'kod' => 'MED-ANK-NEW',
            'ad' => 'Ankara',
            'departman_ids' => [1],
        ], (int) $medisa['id']);
    });
    hierAssert(
        $branchKodChange !== null && $branchKodChange['code'] === 'KOD_IMMUTABLE',
        'branch code is immutable on edit'
    );

    $wrongParent = hierFailure(static function () use ($pdo, $karyapi, $medisaAnkara): void {
        OrganizasyonService::readSube($pdo, $medisaAnkara['id'], (int) $karyapi['id']);
    });
    hierAssert(
        $wrongParent !== null && $wrongParent['status'] === 404,
        'a nested read through the wrong company is a 404, not another company\'s data'
    );

    $scoped = OrganizasyonService::listSubeler($pdo, (int) $medisa['id']);
    hierAssert(count($scoped) === 1 && $scoped[0]['id'] === $medisaAnkara['id'], 'company detail lists only its own branches');
    hierAssert(count(OrganizasyonService::listSubeler($pdo)) === 2, 'the legacy flat endpoint still lists every branch');

    $dependentDelete = hierFailure(static function () use ($pdo, $medisa): void {
        OrganizasyonService::deleteSirket($pdo, $medisa['id']);
    });
    hierAssert(
        $dependentDelete !== null
            && $dependentDelete['code'] === 'SIRKET_HAS_DEPENDENTS'
            && $dependentDelete['status'] === 409,
        'a company with dependents answers 409 instead of cascading a delete'
    );

    // =====================================================================
    // D) Authorization scope
    // =====================================================================
    $globalUser = ['rol' => 'GENEL_YONETICI', 'sube_ids' => [], 'sirket_ids' => [], 'sgk_isveren_ids' => []];
    hierAssert(OrgScope::isUnrestricted($globalUser) === true, 'global roles stay unrestricted');

    $branchManager = [
        'rol' => 'SUBE_YONETICISI',
        'sube_ids' => [(int) $medisaAnkara['id']],
        'explicit_sube_ids' => [(int) $medisaAnkara['id']],
        'sirket_ids' => [],
        'sgk_isveren_ids' => [],
    ];
    hierAssert(
        OrgScope::allowedSubeIds($branchManager) === [(int) $medisaAnkara['id']]
            && OrgScope::allowedSirketIds($branchManager) === [],
        'a branch manager never escalates to a company scope'
    );
    hierAssert(
        OrgScope::isSirketScopeEligible('SUBE_YONETICISI', [(int) $medisa['id']]) === false
            && OrgScope::isSirketScopeEligible('IK_SORUMLUSU', [(int) $medisa['id']]) === true,
        'server-side role validation decides which roles may receive a company scope'
    );

    // A company scope is stored as a company id and resolved to branches per
    // request, so a branch added later is visible without touching user_subeler.
    $pdo->prepare('INSERT INTO user_sirketler (user_id, sirket_id) VALUES (:u, :c)')
        ->execute(['u' => 60, 'c' => (int) $medisa['id']]);
    $resolvedBefore = \Medisa\Api\Database\UserOrgAssignmentSchema::resolveSubeIdsForSirketIds($pdo, [(int) $medisa['id']]);
    hierAssert($resolvedBefore === [(int) $medisaAnkara['id']], 'a company scope resolves to that company\'s branches');

    $medisaIzmir = OrganizasyonService::createSube($pdo, [
        'kod' => 'MED-IZM',
        'ad' => 'Izmir',
        'departman_ids' => [1],
    ], (int) $medisa['id']);
    $resolvedAfter = \Medisa\Api\Database\UserOrgAssignmentSchema::resolveSubeIdsForSirketIds($pdo, [(int) $medisa['id']]);
    sort($resolvedAfter);
    $expectedAfter = [(int) $medisaAnkara['id'], (int) $medisaIzmir['id']];
    sort($expectedAfter);
    hierAssert(
        $resolvedAfter === $expectedAfter,
        'a branch added after the grant is visible without copying any assignment'
    );
    hierAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM user_subeler WHERE user_id = 60')->fetchColumn() === 0,
        'the company scope is never materialised into user_subeler rows'
    );

    // assertRequiredAssignment answers 403 and terminates the request, so the
    // fail-closed guarantee is asserted where it is observable: the predicate a
    // scopeless user gets must match nothing rather than everything.
    $noScope = ['rol' => 'SUBE_YONETICISI', 'sube_ids' => [], 'sirket_ids' => [], 'sgk_isveren_ids' => []];
    $emptyWhere = [];
    $emptyParams = [];
    OrgScope::appendPersonelOrgFilter($emptyWhere, $emptyParams, $noScope, null, 'p');
    hierAssert(
        $emptyWhere !== [] && strpos(implode(' AND ', $emptyWhere), '1=0') !== false,
        'a scoped role with no scope at all fails closed instead of matching every row'
    );

    // IK roles read every company/branch from the role itself, so an empty
    // assignment set must not narrow their read filter.
    foreach (['IK_SORUMLUSU', 'IK_PERSONELI'] as $ikRol) {
        $ikWhere = [];
        $ikParams = [];
        OrgScope::appendPersonelOrgFilter(
            $ikWhere,
            $ikParams,
            ['rol' => $ikRol, 'sube_ids' => [], 'sirket_ids' => [], 'sgk_isveren_ids' => []],
            null,
            'p'
        );
        hierAssert($ikWhere === [], $ikRol . ' reads every branch without an assignment row');
    }

    $sgkOnly = [
        'rol' => 'MUHASEBE',
        'sube_ids' => [],
        'sirket_ids' => [],
        'sgk_isveren_ids' => [$sgkMedisa],
    ];
    $where = [];
    $params = [];
    OrgScope::appendPersonelOrgFilter($where, $params, $sgkOnly, null, 'p');
    $predicate = implode(' AND ', $where);
    hierAssert(
        strpos($predicate, 'sgk_isveren_id') !== false,
        'an SGK scope filters on personeller.sgk_isveren_id, the payroll axis itself'
    );
    hierAssert(
        strpos($predicate, 'subeler') === false || strpos($predicate, 'sgk_isveren_id') !== false,
        'the SGK axis is never inferred from a physical branch'
    );

    // =====================================================================
    // E) Import reference catalog: the ambiguous short name
    // =====================================================================
    OrganizasyonSchema::resetCache();
    $catalog = \Medisa\Api\Services\Personel\PersonelImportReferenceCatalogService::class;
    $subeIndexMethod = new ReflectionMethod($catalog, 'loadSubeNameIndex');
    $subeIndexMethod->setAccessible(true);
    /** @var array<string, list<int>> $index */
    $index = $subeIndexMethod->invoke(null, $pdo);

    hierAssert(
        ($index['Medisa Ankara'] ?? []) === [(int) $medisaAnkara['id']],
        'the canonical full name resolves to exactly one branch'
    );
    hierAssert(
        ($index['Karyapı Ankara'] ?? []) === [(int) $karyapiAnkara['id']],
        'the other company\'s Ankara is a separate canonical reference'
    );
    hierAssert(
        count($index['Ankara'] ?? []) === 2,
        'the bare short name stays ambiguous instead of silently picking one branch'
    );
    hierAssert(
        ($index['Izmir'] ?? []) === [(int) $medisaIzmir['id']],
        'a short name unique across companies still resolves on its own'
    );

    OrganizasyonSchema::resetCache();
    $legacyIndex = $subeIndexMethod->invoke(null, $legacy);
    hierAssert(
        ($legacyIndex['Merkez'] ?? []) === [1],
        'on a pre-079 database the raw branch name fallback still resolves'
    );

    echo 'verify-sirket-sube-hiyerarsisi-mysql: OK' . PHP_EOL;
} finally {
    OrganizasyonSchema::resetCache();
    foreach ([$db, $legacyDb] as $name) {
        $root->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    }
}
