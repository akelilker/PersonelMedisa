<?php

declare(strict_types=1);

/**
 * PHASE SGK_EMPLOYER_SELF_SERVICE_ORGANIZATION_MANAGEMENT
 *
 * DB-backed acceptance for the SGK employer self-service catalog and its
 * company-consistent branch mapping, against a disposable MariaDB:
 *   - catalog create / update / soft state (AKTIF-PASIF) / guarded delete
 *   - N:1: one employer serves many branches, one company owns many employers
 *   - fail-closed: pasif employer, unsafe delete, company change that would
 *     invalidate a stored relation
 *   - company-independent branch mapping (2026-09-15): bir şube herhangi bir
 *     AKTIF SGK işverenini seçebilir — şirketi farklı olsa veya hiç şirketi
 *     olmasa bile (ör. Medisa şubesi Şenay/Karyapı SGK işverenini seçer)
 *   - şube create/update persists subeler.sgk_isveren_id
 *   - PersonelSgkCompanyConsistency keeps its unchanged same-company rule
 *
 * Nothing here touches production: every assertion runs against a database this
 * runner creates and drops.
 *
 * php tests/php/SgkIsverenSelfServiceMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use Medisa\Api\Services\Personel\PersonelSgkCompanyConsistency;

function sgkSelfAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function sgkSelfPdo(string $dsn): PDO
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

/** @return array{status:int, code:string}|null */
function sgkSelfFailure(callable $callable): ?array
{
    try {
        $callable();

        return null;
    } catch (OrganizasyonException $exception) {
        return ['status' => $exception->httpStatus, 'code' => $exception->errorCode];
    }
}

/** Asserts that the callable was refused with the exact domain code. */
function sgkSelfRefuses(callable $callable, string $expectedCode, string $name): void
{
    $failure = sgkSelfFailure($callable);
    sgkSelfAssert(
        $failure !== null && $failure['code'] === $expectedCode,
        $name . ' [' . ($failure === null ? 'no failure' : $failure['status'] . ' ' . $failure['code']) . ']'
    );
}

/** @return array<string, mixed>|null */
function sgkSelfRow(PDO $pdo, int $sgkIsverenId): ?array
{
    $stmt = $pdo->prepare('SELECT id, kod, ad, durum, sirket_id FROM sgk_isverenler WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $sgkIsverenId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return array<string, mixed>|null */
function sgkSelfSubeRow(PDO $pdo, int $subeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, kod, ad, durum, sirket_id, sgk_isveren_id FROM subeler WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $subeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** @return array<int, array<string, mixed>> */
function sgkSelfList(PDO $pdo): array
{
    $items = [];
    foreach (OrganizasyonService::listSgkIsverenleri($pdo) as $item) {
        $items[(int) $item['id']] = $item;
    }

    return $items;
}

/** Pre-079 organisation shape, then the real migration 079 on top of it. */
function sgkSelfSchema(PDO $pdo): void
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
            kod VARCHAR(64) NULL,
            ad VARCHAR(191) NOT NULL,
            durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_sgk_isverenler_kod (kod),
            UNIQUE KEY uq_sgk_isverenler_ad (ad)
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
            tc_kimlik_no VARCHAR(11) NULL,
            soyad VARCHAR(80) NULL,
            dogum_tarihi DATE NULL,
            telefon VARCHAR(32) NULL,
            sube_id INT UNSIGNED NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            calisma_lokasyonu_id INT UNSIGNED NULL,
            calisan_kapsami VARCHAR(16) NULL,
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

    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/079_sirket_sube_hiyerarsisi.sql'));
}

/**
 * Two companies, five employers (two of them sharing company 1, one PASIF, one
 * unmapped), three mapped branches and one personnel row.
 *
 * @return array<string, int>
 */
function sgkSelfSeed(PDO $pdo): array
{
    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (1, 'muhasebe', 'MUHASEBE')");
    $pdo->exec(
        "INSERT INTO sirketler (id, kod, ad, durum) VALUES
            (1, 'MED', 'Medisa', 'AKTIF'),
            (2, 'SNY', 'Senay', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES
            (1, 'MED-MRK', 'Medisa Merkez', 'AKTIF'),
            (2, 'MED-BRS', 'Medisa Bursa', 'AKTIF'),
            (3, 'SNY-MRK', 'Senay Merkez', 'AKTIF'),
            (4, 'MED-PSF', 'Medisa Pasif', 'PASIF'),
            (5, 'MED-NOMAP', 'Medisa Eslesmemis', 'AKTIF')"
    );
    $pdo->exec('UPDATE sgk_isverenler SET sirket_id = 1 WHERE id IN (1, 2, 4)');
    $pdo->exec('UPDATE sgk_isverenler SET sirket_id = 2 WHERE id = 3');

    $pdo->exec(
        "INSERT INTO subeler (id, kod, ad, durum, sirket_id, sgk_isveren_id) VALUES
            (1, 'MED-MRK', 'Merkez', 'AKTIF', 1, 1),
            (2, 'MED-IZM', 'Izmir', 'AKTIF', 1, 1),
            (3, 'SNY-MRK', 'Merkez', 'AKTIF', 2, 3)"
    );
    $pdo->exec("INSERT INTO personeller (id, ad_soyad, sube_id, sgk_isveren_id) VALUES (1, 'Test Personel', 1, 1)");
    $pdo->exec('INSERT INTO user_sgk_isverenler (user_id, sgk_isveren_id) VALUES (1, 1)');

    return [
        'medisa' => 1,
        'senay' => 2,
        'merkez' => 1,
        'bursa' => 2,
        'senayMerkez' => 3,
        'pasif' => 4,
        'unmapped' => 5,
    ];
}

// ---------------------------------------------------------------------------

$dsn = getenv('MEDISA_TEST_MYSQL_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo 'SKIP: Disposable MariaDB is not configured (MEDISA_TEST_MYSQL_DSN).' . PHP_EOL;
    exit(0);
}

$suffix = bin2hex(random_bytes(5));
$db = 'medisa_sgkself_' . $suffix;
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = sgkSelfPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdoDsn = preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn;
if ($pdoDsn === $dsn) {
    $pdoDsn = rtrim($dsn, ';') . ';dbname=' . $db;
}
$pdo = sgkSelfPdo($pdoDsn);

try {
    sgkSelfSchema($pdo);
    $ids = sgkSelfSeed($pdo);
    OrganizasyonSchema::resetCache();

    // ------------------------------------------------------------------ catalog

    $catalog = sgkSelfList($pdo);
    sgkSelfAssert(
        count($catalog) === 5
            && $catalog[$ids['merkez']]['sirket']['id'] === $ids['medisa']
            && $catalog[$ids['merkez']]['sube_sayisi'] === 2
            && $catalog[$ids['pasif']]['durum'] === 'PASIF'
            && $catalog[$ids['unmapped']]['sirket'] === null,
        'catalog read exposes company, soft state and branch count'
    );

    $medisaActive = 0;
    foreach ($catalog as $item) {
        if ($item['sirket'] !== null && $item['sirket']['id'] === $ids['medisa'] && $item['durum'] === 'AKTIF') {
            $medisaActive++;
        }
    }
    sgkSelfAssert($medisaActive === 2, 'one company may own several active employers');

    // --------------------------------------------------------------- create

    $created = OrganizasyonService::createSgkIsveren($pdo, [
        'sirket_id' => $ids['medisa'],
        'kod' => '  med-ank  ',
        'ad' => '  MEDISA ANKARA  ',
    ]);
    $createdId = (int) $created['id'];
    $createdRow = sgkSelfRow($pdo, $createdId);
    sgkSelfAssert(
        $created['kod'] === 'med-ank'
            && $created['ad'] === 'MEDISA ANKARA'
            && $created['durum'] === 'AKTIF'
            && $created['sirket']['id'] === $ids['medisa']
            && $created['sube_sayisi'] === 0
            && is_array($createdRow)
            && $createdRow['kod'] === 'med-ank'
            && $createdRow['ad'] === 'MEDISA ANKARA'
            && (int) $createdRow['sirket_id'] === $ids['medisa'],
        'an authorised create trims and persists the employer'
    );

    sgkSelfRefuses(
        static function () use ($pdo): void {
            OrganizasyonService::createSgkIsveren($pdo, ['kod' => 'NO-COMPANY', 'ad' => 'No Company']);
        },
        'VALIDATION_ERROR',
        'create fails closed without a company'
    );
    sgkSelfRefuses(
        static function () use ($pdo): void {
            OrganizasyonService::createSgkIsveren($pdo, ['sirket_id' => 999999, 'kod' => 'GHOST', 'ad' => 'Ghost']);
        },
        'VALIDATION_ERROR',
        'create fails closed for an unknown company id'
    );
    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::createSgkIsveren($pdo, ['sirket_id' => $ids['medisa'], 'kod' => '   ', 'ad' => 'Kodsuz']);
        },
        'VALIDATION_ERROR',
        'create fails closed without a code'
    );
    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::createSgkIsveren($pdo, ['sirket_id' => $ids['medisa'], 'kod' => 'ADSIZ', 'ad' => '   ']);
        },
        'VALIDATION_ERROR',
        'create fails closed without a name'
    );
    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::createSgkIsveren($pdo, [
                'sirket_id' => $ids['medisa'],
                'kod' => 'MED-MRK',
                'ad' => 'Medisa Merkez Kopya',
            ]);
        },
        'DUPLICATE_SGK_ISVEREN_KOD',
        'a duplicate employer code is refused'
    );
    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::createSgkIsveren($pdo, [
                'sirket_id' => $ids['medisa'],
                'kod' => 'MED-OTHER',
                'ad' => 'MEDISA MERKEZ',
            ]);
        },
        'DUPLICATE_SGK_ISVEREN_AD',
        'a duplicate employer name is refused regardless of case'
    );

    // --------------------------------------------------------------- update

    $deactivated = OrganizasyonService::updateSgkIsveren($pdo, $createdId, [
        'kod' => 'MED-ANK',
        'ad' => 'MEDISA ANKARA',
        'durum' => 'PASIF',
    ]);
    $deactivatedRow = sgkSelfRow($pdo, $createdId);
    sgkSelfAssert(
        $deactivated['kod'] === 'MED-ANK'
            && $deactivated['durum'] === 'PASIF'
            && is_array($deactivatedRow)
            && $deactivatedRow['durum'] === 'PASIF',
        'update writes code, name and the soft AKTIF/PASIF state'
    );
    sgkSelfAssert(
        OrganizasyonService::updateSgkIsveren($pdo, $createdId, ['durum' => 'AKTIF'])['durum'] === 'AKTIF',
        'a pasif employer can be activated again'
    );

    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::updateSgkIsveren($pdo, $ids['bursa'], ['kod' => 'MED-MRK']);
        },
        'DUPLICATE_SGK_ISVEREN_KOD',
        'update refuses a code already used by another employer'
    );
    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::updateSgkIsveren($pdo, $ids['bursa'], ['durum' => 'SILINDI']);
        },
        'VALIDATION_ERROR',
        'update refuses an unknown soft state'
    );
    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::updateSgkIsveren($pdo, $ids['bursa'], ['sirket_id' => null]);
        },
        'VALIDATION_ERROR',
        'update cannot unmap an employer from its company'
    );

    // ------------------------------------------------------- branch mapping

    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::createSube($pdo, [
                'kod' => 'MED-PSF-SB',
                'ad' => 'Pasif Sgk',
                'departman_ids' => [],
                'sgk_isveren_id' => $ids['pasif'],
            ], $ids['medisa']);
        },
        'SGK_ISVEREN_PASIF',
        'a pasif employer cannot be attached to a new branch'
    );
    // 2026-09-15 model: şube şirketi ile sgk_isveren.sirket_id farklı olabilir
    // (ör. Medisa şubesi Şenay/Karyapı SGK işverenini seçer), bu yüzden şirket
    // eşleşmesi ve şirketsiz kayıt şube tarafında reddedilmez.
    $crossCompanyBranch = OrganizasyonService::createSube($pdo, [
        'kod' => 'MED-CROSS-SB',
        'ad' => 'Yabanci Sgk',
        'departman_ids' => [],
        'sgk_isveren_id' => $ids['senayMerkez'],
    ], $ids['medisa']);
    $crossCompanyBranchId = (int) $crossCompanyBranch['id'];
    sgkSelfAssert(
        $crossCompanyBranch['sgk_isveren']['id'] === $ids['senayMerkez']
            && (int) sgkSelfSubeRow($pdo, $crossCompanyBranchId)['sgk_isveren_id'] === $ids['senayMerkez'],
        'another company employer can be attached to this branch'
    );
    $unmappedBranch = OrganizasyonService::createSube($pdo, [
        'kod' => 'SNY-NOMAP-SB',
        'ad' => 'Eslesmemis Sgk',
        'departman_ids' => [],
        'sgk_isveren_id' => $ids['unmapped'],
    ], $ids['senay']);
    sgkSelfAssert(
        $unmappedBranch['sgk_isveren']['id'] === $ids['unmapped'],
        'an employer without a company can be attached to a branch'
    );
    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::createSube($pdo, [
                'kod' => 'MED-GHOST-SB',
                'ad' => 'Hayalet Sgk',
                'departman_ids' => [],
                'sgk_isveren_id' => 999999,
            ], $ids['medisa']);
        },
        'VALIDATION_ERROR',
        'an unknown employer id is refused on branch create'
    );

    $bursaBranch = OrganizasyonService::createSube($pdo, [
        'kod' => 'MED-BRS',
        'ad' => 'Bursa',
        'departman_ids' => [],
        'sgk_isveren_id' => $ids['bursa'],
    ], $ids['medisa']);
    $bursaBranchId = (int) $bursaBranch['id'];
    $bursaBranchRow = sgkSelfSubeRow($pdo, $bursaBranchId);
    sgkSelfAssert(
        $bursaBranch['sgk_isveren']['id'] === $ids['bursa']
            && is_array($bursaBranchRow)
            && (int) $bursaBranchRow['sgk_isveren_id'] === $ids['bursa'],
        'branch create persists subeler.sgk_isveren_id'
    );

    OrganizasyonService::createSube($pdo, [
        'kod' => 'MED-BRS2',
        'ad' => 'Bursa 2',
        'departman_ids' => [],
        'sgk_isveren_id' => $ids['bursa'],
    ], $ids['medisa']);
    sgkSelfAssert(
        sgkSelfList($pdo)[$ids['bursa']]['sube_sayisi'] === 2,
        'one employer can serve many branches'
    );

    $moved = OrganizasyonService::updateSube(
        $pdo,
        $bursaBranchId,
        ['ad' => 'Bursa', 'sgk_isveren_id' => $ids['merkez']],
        $ids['medisa']
    );
    sgkSelfAssert(
        $moved['sgk_isveren']['id'] === $ids['merkez']
            && (int) sgkSelfSubeRow($pdo, $bursaBranchId)['sgk_isveren_id'] === $ids['merkez'],
        'branch update persists a changed employer'
    );

    OrganizasyonService::updateSgkIsveren($pdo, $ids['merkez'], ['durum' => 'PASIF']);
    $kept = OrganizasyonService::updateSube(
        $pdo,
        $bursaBranchId,
        ['ad' => 'Bursa Merkez', 'sgk_isveren_id' => $ids['merkez']],
        $ids['medisa']
    );
    sgkSelfAssert(
        $kept['sgk_isveren']['id'] === $ids['merkez'],
        'an unchanged pasif mapping is preserved instead of silently rewritten'
    );
    OrganizasyonService::updateSgkIsveren($pdo, $ids['merkez'], ['durum' => 'AKTIF']);

    sgkSelfRefuses(
        static function () use ($pdo, $bursaBranchId, $ids): void {
            OrganizasyonService::updateSube($pdo, $bursaBranchId, ['sgk_isveren_id' => $ids['pasif']], $ids['medisa']);
        },
        'SGK_ISVEREN_PASIF',
        'branch update refuses an explicit switch to a pasif employer'
    );
    $switched = OrganizasyonService::updateSube(
        $pdo,
        $bursaBranchId,
        ['sgk_isveren_id' => $ids['senayMerkez']],
        $ids['medisa']
    );
    sgkSelfAssert(
        $switched['sgk_isveren']['id'] === $ids['senayMerkez']
            && (int) sgkSelfSubeRow($pdo, $bursaBranchId)['sgk_isveren_id'] === $ids['senayMerkez'],
        'branch update accepts another company employer'
    );

    // --------------------------------------------- company change fail-closed

    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::updateSgkIsveren($pdo, $ids['bursa'], ['sirket_id' => $ids['senay']]);
        },
        'SGK_ISVEREN_SIRKET_CHANGE_BLOCKED',
        'moving an employer that company-1 branches point at is refused'
    );

    $unmappedMapped = OrganizasyonService::updateSgkIsveren($pdo, $ids['unmapped'], ['sirket_id' => $ids['senay']]);
    sgkSelfAssert(
        $unmappedMapped['sirket']['id'] === $ids['senay'],
        'an unmapped employer can be bound to a company from the management screen'
    );

    $loose = OrganizasyonService::createSgkIsveren($pdo, [
        'sirket_id' => $ids['medisa'],
        'kod' => 'MED-LOOSE',
        'ad' => 'Medisa Serbest',
    ]);
    $looseId = (int) $loose['id'];
    $looseRemapped = OrganizasyonService::updateSgkIsveren($pdo, $looseId, ['sirket_id' => $ids['senay']]);
    sgkSelfAssert(
        $looseRemapped['sirket']['id'] === $ids['senay'],
        'an unreferenced employer can be re-mapped to another company'
    );

    $personelOnly = OrganizasyonService::createSgkIsveren($pdo, [
        'sirket_id' => $ids['medisa'],
        'kod' => 'MED-P1',
        'ad' => 'Medisa Personel',
    ]);
    $personelOnlyId = (int) $personelOnly['id'];
    $pdo->exec('UPDATE personeller SET sgk_isveren_id = ' . $personelOnlyId . ' WHERE id = 1');
    sgkSelfRefuses(
        static function () use ($pdo, $personelOnlyId, $ids): void {
            OrganizasyonService::updateSgkIsveren($pdo, $personelOnlyId, ['sirket_id' => $ids['senay']]);
        },
        'SGK_ISVEREN_SIRKET_CHANGE_BLOCKED',
        'a company change that would desync a stored personnel relation is refused'
    );
    $pdo->exec('UPDATE personeller SET sgk_isveren_id = ' . $ids['merkez'] . ' WHERE id = 1');

    // Employment-scope parity (2026-09-13 model): the payroll/SGK source of a
    // DIS_KAYNAK personel is independent from its branch company, so a Harici row
    // must not freeze an employer's company mapping. Only IC_PERSONEL (the row
    // above) can block the change.
    $disEmployer = OrganizasyonService::createSgkIsveren($pdo, [
        'sirket_id' => $ids['medisa'],
        'kod' => 'MED-DIS1',
        'ad' => 'Medisa Harici Kaynak',
    ]);
    $disEmployerId = (int) $disEmployer['id'];
    $pdo->exec(
        "INSERT INTO personeller (id, ad_soyad, sube_id, sgk_isveren_id, calisan_kapsami)
         VALUES (2, 'Harici Personel', 1, " . $disEmployerId . ", 'DIS_KAYNAK')"
    );
    $disRemapped = OrganizasyonService::updateSgkIsveren($pdo, $disEmployerId, ['sirket_id' => $ids['senay']]);
    sgkSelfAssert(
        $disRemapped['sirket'] !== null && (int) $disRemapped['sirket']['id'] === $ids['senay'],
        'a DIS_KAYNAK personnel row does not block an employer company change'
    );

    // ------------------------------------------------------------ delete guard

    sgkSelfRefuses(
        static function () use ($pdo, $ids): void {
            OrganizasyonService::deleteSgkIsveren($pdo, $ids['merkez']);
        },
        'SGK_ISVEREN_HAS_DEPENDENTS',
        'an employer referenced by branches cannot be physically deleted'
    );
    // The personnel-only reference is re-attached here: the company-change check
    // above already proved it, and this keeps the delete guard deterministic.
    $pdo->exec('UPDATE personeller SET sgk_isveren_id = ' . $personelOnlyId . ' WHERE id = 1');
    sgkSelfRefuses(
        static function () use ($pdo, $personelOnlyId): void {
            OrganizasyonService::deleteSgkIsveren($pdo, $personelOnlyId);
        },
        'SGK_ISVEREN_HAS_DEPENDENTS',
        'an employer referenced only by personnel cannot be physically deleted'
    );
    $pdo->exec('UPDATE personeller SET sgk_isveren_id = ' . $ids['merkez'] . ' WHERE id = 1');

    $scopeOnly = OrganizasyonService::createSgkIsveren($pdo, [
        'sirket_id' => $ids['medisa'],
        'kod' => 'MED-SCP',
        'ad' => 'Medisa Kapsam',
    ]);
    $scopeOnlyId = (int) $scopeOnly['id'];
    $pdo->exec('INSERT INTO user_sgk_isverenler (user_id, sgk_isveren_id) VALUES (1, ' . $scopeOnlyId . ')');
    sgkSelfRefuses(
        static function () use ($pdo, $scopeOnlyId): void {
            OrganizasyonService::deleteSgkIsveren($pdo, $scopeOnlyId);
        },
        'SGK_ISVEREN_HAS_DEPENDENTS',
        'an employer referenced only by a user scope cannot be physically deleted'
    );

    $deletedLoose = OrganizasyonService::deleteSgkIsveren($pdo, $looseId);
    sgkSelfAssert(
        $deletedLoose['deleted'] === true && sgkSelfRow($pdo, $looseId) === null,
        'an unreferenced employer can still be removed physically'
    );
    sgkSelfRefuses(
        static function () use ($pdo, $looseId): void {
            OrganizasyonService::readSgkIsveren($pdo, $looseId);
        },
        'NOT_FOUND',
        'a deleted employer is no longer readable'
    );
    sgkSelfRefuses(
        static function () use ($pdo): void {
            OrganizasyonService::deleteSgkIsveren($pdo, 999999);
        },
        'NOT_FOUND',
        'deleting an unknown employer answers not found'
    );

    // ------------------------------------- personnel consistency (unchanged)

    $sameCompany = PersonelSgkCompanyConsistency::evaluate($pdo, $ids['merkez'], 1);
    sgkSelfAssert($sameCompany['ok'] === true, 'PersonelSgkCompanyConsistency still accepts a same-company employer');
    $branchDefault = PersonelSgkCompanyConsistency::evaluate($pdo, $ids['bursa'], 1);
    sgkSelfAssert(
        $branchDefault['ok'] === true,
        'branch-default employer equality is still not required for personnel'
    );
    $foreignCompany = PersonelSgkCompanyConsistency::evaluate($pdo, $ids['senayMerkez'], 1);
    sgkSelfAssert(
        $foreignCompany['ok'] === false && $foreignCompany['code'] === PersonelSgkCompanyConsistency::ERROR_MISMATCH,
        'PersonelSgkCompanyConsistency still fails closed on a foreign-company employer'
    );

    echo 'verify-sgk-isveren-self-service-mysql: OK' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
