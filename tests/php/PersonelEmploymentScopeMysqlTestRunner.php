<?php

declare(strict_types=1);

/**
 * PHASE EMPLOYMENT_SCOPE_WORKPLACE_INSURANCE_MODEL_CORRECTION
 *
 * DB-backed acceptance for dört bağımsız personel ekseni:
 *   A) çalışan kapsamı  (calisan_kapsami: IC_PERSONEL / DIS_KAYNAK)
 *   B) statü            (personel_tipi_id: Mavi / Beyaz Yaka)
 *   C) fiili çalışma yeri (calisma_lokasyonu_id)
 *   D) SGK / bordro kaynağı (sgk_isveren_id — şirketten bağımsız olabilir)
 *
 * Kapsanan kabul kriterleri:
 *   - Dahili: aynı şirket SGK işvereni kabul, farklı şirket reddedilir
 *   - Harici: farklı şirket SGK işvereni kabul, SGK işvereni opsiyonel
 *   - Harici + Mavi Yaka ve Harici + Beyaz Yaka birlikte geçerli
 *   - Otomatik SGK seçimi yok; şube adından tahmin yok
 *   - Fiili çalışma yeri ile bordro/SGK kaynağı bağımsız
 *   - Aynı fiili çalışma yerinde farklı SGK kaynağındaki personel birlikte bulunur
 *   - Import satırı aynı kontrata uyar (Harici + farklı şirket SGK, Harici + null)
 *   - DIS_KAYNAK yokluk/QR takibinden dışlanmaz (izin/finans kapalı)
 *
 * Üretim verisine dokunulmaz: her assertion bu runner'ın oluşturup düşürdüğü
 * geçici veritabanında çalışır.
 *
 * php tests/php/PersonelEmploymentScopeMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelSgkCompanyConsistency;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityService;

function espAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function espPdo(string $dsn): PDO
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

/** Runs the callable and returns the validation code it failed with (null = accepted). */
function espCode(callable $callable): ?string
{
    try {
        $callable();

        return null;
    } catch (PersonelValidationException $exception) {
        return $exception->getCodeString() !== '' ? $exception->getCodeString() : 'VALIDATION';
    }
}

/** Asserts the callable was refused with the exact domain code. */
function espRefuses(callable $callable, string $expectedCode, string $name): void
{
    $code = espCode($callable);
    espAssert($code === $expectedCode, $name . ' [' . ($code ?? 'accepted') . ']');
}

/** Asserts the callable was accepted (no validation failure). */
function espAccepts(callable $callable, string $name): void
{
    $code = espCode($callable);
    espAssert($code === null, $name . ' [' . ($code ?? 'accepted') . ']');
}

/**
 * Pre-079 organisation shape (nullable identity columns so that
 * calisan_kapsami schema readiness is true) + real migration 079 on top.
 */
function espSchema(PDO $pdo): void
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
    // Personel kimliği nullable (065/066 sözleşmesi) + dört bağımsız eksen.
    $pdo->exec(
        "CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tc_kimlik_no VARCHAR(11) NULL,
            ad VARCHAR(80) NOT NULL DEFAULT '',
            soyad VARCHAR(80) NULL,
            dogum_tarihi DATE NULL,
            telefon VARCHAR(32) NULL,
            sicil_no VARCHAR(32) NULL,
            aktif_durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            calisan_kapsami VARCHAR(16) NULL,
            personel_tipi_id INT UNSIGNED NULL,
            sube_id INT UNSIGNED NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            calisma_lokasyonu_id INT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
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
 * Üç şirket, dört SGK işvereni, iki şube ve Fabrika'da fiilen çalışan üç kişi:
 * Medisa bordrolu, Şenay bordrolu ve hiçbir kurum SGK işverenine bağlı olmayan
 * (Bağ-Kur) harici personel.
 *
 * @return array<string, int>
 */
function espSeed(PDO $pdo): array
{
    $pdo->exec(
        "INSERT INTO sirketler (id, kod, ad, durum) VALUES
            (1, 'MED', 'Medisa', 'AKTIF'),
            (2, 'SNY', 'Senay Mobilya', 'AKTIF'),
            (3, 'KRY', 'Karyapi', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES
            (1, 'MED-001', 'Medisa', 'AKTIF'),
            (2, 'SNY-001', 'Senay Mobilya', 'AKTIF'),
            (3, 'KRY-001', 'Karyapi', 'AKTIF'),
            (4, 'MED-PSF', 'Medisa Pasif', 'PASIF')"
    );
    $pdo->exec('UPDATE sgk_isverenler SET sirket_id = 1 WHERE id IN (1, 4)');
    $pdo->exec('UPDATE sgk_isverenler SET sirket_id = 2 WHERE id = 2');
    $pdo->exec('UPDATE sgk_isverenler SET sirket_id = 3 WHERE id = 3');

    $pdo->exec(
        "INSERT INTO subeler (id, kod, ad, durum, sirket_id, sgk_isveren_id) VALUES
            (1, 'MED-FAB', 'Fabrika', 'AKTIF', 1, 1),
            (2, 'KRY-MRK', 'Karyapi Merkez', 'AKTIF', 3, 3)"
    );
    $pdo->exec(
        "INSERT INTO calisma_lokasyonlari (id, kod, ad, durum, sube_id) VALUES
            (1, 'FAB', 'Fabrika / Karabük', 'AKTIF', 1),
            (2, 'KRY', 'Karyapi Merkez', 'AKTIF', 2)"
    );

    $pdo->exec(
        "INSERT INTO personeller
            (id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, sicil_no, aktif_durum,
             calisan_kapsami, personel_tipi_id, sube_id, sgk_isveren_id, calisma_lokasyonu_id)
         VALUES
            (1, '11111111110', 'Dahili', 'Mavi', '1990-01-01', '05000000001', 'IC-1', 'AKTIF',
             'IC_PERSONEL', 1, 1, 1, 1),
            (2, NULL, 'Senay', 'Bordrolu', NULL, NULL, 'DIS-1', 'AKTIF',
             'DIS_KAYNAK', 1, 1, 2, 1),
            (3, NULL, 'Bagkur', 'Harici', NULL, NULL, 'DIS-2', 'AKTIF',
             'DIS_KAYNAK', 2, 1, NULL, 1)"
    );

    return [
        'medisa' => 1,
        'senayMobilya' => 2,
        'karyapi' => 3,
        'pasif' => 4,
        'fabrika' => 1,
        'karyapiMerkez' => 2,
    ];
}

/**
 * Harici personel create/import gövdesi (SGK kaynağı ve statü serbest).
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function espDisBody(array $overrides = []): array
{
    return array_merge([
        'calisan_kapsami' => 'DIS_KAYNAK',
        'ad' => 'Harici',
        'ise_giris_tarihi' => '2026-01-05',
        'aktif_durum' => 'AKTIF',
    ], $overrides);
}

// ---------------------------------------------------------------------------

$dsn = getenv('MEDISA_TEST_MYSQL_DSN');
if (!is_string($dsn) || $dsn === '') {
    echo 'SKIP: Disposable MariaDB is not configured (MEDISA_TEST_MYSQL_DSN).' . PHP_EOL;
    exit(0);
}

$suffix = bin2hex(random_bytes(5));
$db = 'medisa_empscope_' . $suffix;
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = espPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdoDsn = preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn;
if ($pdoDsn === $dsn) {
    $pdoDsn = rtrim($dsn, ';') . ';dbname=' . $db;
}
$pdo = espPdo($pdoDsn);

try {
    espSchema($pdo);
    $ids = espSeed($pdo);
    OrganizasyonSchema::resetCache();

    espAssert(OrganizasyonSchema::isSchemaReady($pdo) === true, 'sirket/sube/SGK hiyerarşisi hazır');

    // ------------------------------------------------- A) Dahili Personel (IC)

    espAccepts(
        static function () use ($pdo, $ids): void {
            PersonelSgkCompanyConsistency::assertCompatibleForKapsam(
                $pdo,
                'IC_PERSONEL',
                $ids['medisa'],
                $ids['fabrika']
            );
        },
        'dahili: aynı şirket SGK işvereni kabul'
    );

    espRefuses(
        static function () use ($pdo, $ids): void {
            PersonelSgkCompanyConsistency::assertCompatibleForKapsam(
                $pdo,
                'IC_PERSONEL',
                $ids['senayMobilya'],
                $ids['fabrika']
            );
        },
        PersonelSgkCompanyConsistency::ERROR_MISMATCH,
        'dahili: farklı şirket SGK işvereni reddedilir'
    );

    espRefuses(
        static function (): void {
            PersonelSgkCompanyConsistency::assertRequiredForActiveIc('IC_PERSONEL', 'AKTIF', null);
        },
        PersonelSgkCompanyConsistency::ERROR_REQUIRED,
        'dahili: aktif IC personelde SGK işvereni zorunlu'
    );

    $icBody = [
        'calisan_kapsami' => 'IC_PERSONEL',
        'tc_kimlik_no' => '11111111110',
        'ad' => 'Dahili',
        'soyad' => 'Personel',
        'dogum_tarihi' => '1990-01-01',
        'telefon' => '05000000001',
        'ise_giris_tarihi' => '2026-01-05',
        'aktif_durum' => 'AKTIF',
        'sube_id' => $ids['fabrika'],
        'departman_id' => 1,
        'gorev_id' => 1,
        'personel_tipi_id' => 1,
        'sgk_isveren_id' => $ids['medisa'],
    ];
    $icMavi = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(
        array_merge($icBody, ['personel_tipi_id' => 1])
    );
    $icBeyaz = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(
        array_merge($icBody, ['personel_tipi_id' => 2])
    );
    espAssert(
        $icMavi['personel_tipi_id'] === 1 && $icBeyaz['personel_tipi_id'] === 2,
        'dahili: Mavi Yaka ve Beyaz Yaka statüsü kapsamdan bağımsız'
    );

    // ------------------------------------------------- B) Harici Personel (DIS)

    espAccepts(
        static function () use ($pdo, $ids): void {
            PersonelSgkCompanyConsistency::assertCompatibleForKapsam(
                $pdo,
                'DIS_KAYNAK',
                $ids['senayMobilya'],
                $ids['fabrika']
            );
        },
        'harici: farklı şirket SGK işvereni kabul (Şenay Mobilya bordrolu, Medisa fabrikasında)'
    );

    espAccepts(
        static function () use ($pdo, $ids): void {
            PersonelSgkCompanyConsistency::assertCompatibleForKapsam(
                $pdo,
                'DIS_KAYNAK',
                $ids['karyapi'],
                $ids['karyapiMerkez']
            );
        },
        'harici: Karyapı SGK işvereni kabul'
    );

    espAccepts(
        static function () use ($pdo, $ids): void {
            PersonelSgkCompanyConsistency::assertCompatibleForKapsam($pdo, 'DIS_KAYNAK', null, $ids['fabrika']);
            PersonelSgkCompanyConsistency::assertCompatibleForKapsam($pdo, 'DIS_KAYNAK', null, null);
        },
        'harici: SGK işvereni olmadan (Bağ-Kur) kabul'
    );

    espAccepts(
        static function (): void {
            PersonelSgkCompanyConsistency::assertRequiredForActiveIc('DIS_KAYNAK', 'AKTIF', null);
        },
        'harici: SGK işvereni zorunlu değil'
    );

    $disSenay = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(
        espDisBody([
            'sicil_no' => 'DIS-YENI-1',
            'sube_id' => $ids['fabrika'],
            'sgk_isveren_id' => $ids['senayMobilya'],
            'calisma_lokasyonu_id' => $ids['fabrika'],
        ])
    );
    espAssert(
        (int) $disSenay['sgk_isveren_id'] === $ids['senayMobilya']
            && (int) $disSenay['calisma_lokasyonu_id'] === $ids['fabrika'],
        'harici: SGK kaynağı ve fiili çalışma yeri birlikte taşınır'
    );

    $disBos = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(
        espDisBody(['sicil_no' => 'DIS-YENI-2', 'sube_id' => $ids['fabrika']])
    );
    espAssert(
        !array_key_exists('sgk_isveren_id', $disBos),
        'harici: otomatik SGK seçimi yok (boş kaynak boş kalır)'
    );

    $disMavi = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(
        espDisBody(['sicil_no' => 'DIS-MAVI', 'personel_tipi_id' => 1])
    );
    $disBeyaz = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(
        espDisBody(['sicil_no' => 'DIS-BEYAZ', 'personel_tipi_id' => 2])
    );
    espAssert($disMavi['personel_tipi_id'] === 1, 'harici: Mavi Yaka statüsü kabul');
    espAssert($disBeyaz['personel_tipi_id'] === 2, 'harici: Beyaz Yaka statüsü kabul');

    // -------------------------------------- C) Fabrika: fiili çalışan envanteri

    $fabrikaRows = $pdo->query(
        'SELECT p.id, p.calisan_kapsami, p.personel_tipi_id, p.sgk_isveren_id,
                p.calisma_lokasyonu_id, e.sirket_id
         FROM personeller p
         LEFT JOIN sgk_isverenler e ON e.id = p.sgk_isveren_id
         WHERE p.aktif_durum = \'AKTIF\' AND p.calisma_lokasyonu_id = ' . (int) $ids['fabrika'] . '
         ORDER BY p.id ASC'
    )->fetchAll();

    $sirketler = [];
    foreach ($fabrikaRows as $row) {
        $sirketler[$row['sirket_id'] === null ? 'yok' : (string) $row['sirket_id']] = true;
    }
    espAssert(
        count($fabrikaRows) === 3,
        'fabrika: aynı fiili çalışma yerinde üç farklı bordro kaynağı birlikte bulunur'
    );
    espAssert(
        count($sirketler) === 3,
        'fabrika: Medisa, Şenay Mobilya ve SGK kaynağı olmayan personel aynı lokasyonda'
    );
    espAssert(
        (int) $fabrikaRows[0]['sgk_isveren_id'] !== (int) $fabrikaRows[1]['sgk_isveren_id'],
        'fabrika: fiili çalışma yeri ile SGK işvereni bağımsız eksenlerdir'
    );

    // ------------------------------------------------------- D) Import parity

    $importDis = PersonelCanonicalValidator::validateImportAnaVeriRow([
        'calisan_kapsami' => 'DIS_KAYNAK',
        'ad' => 'Import Harici',
        'sicil_no' => 'IMP-DIS-1',
        'ise_giris_tarihi' => '2026-02-01',
        'sube_id' => $ids['fabrika'],
        'personel_tipi_id' => 1,
        'sgk_isveren_id' => $ids['senayMobilya'],
        'calisma_lokasyonu_id' => $ids['fabrika'],
    ]);
    espAssert($importDis['errors'] === [], 'import: harici satır doğrulanır');
    espAssert(
        $importDis['payload'] !== null && (int) $importDis['payload']['sgk_isveren_id'] === $ids['senayMobilya'],
        'import: harici + farklı şirket SGK işvereni geçerli'
    );
    espAssert(
        (int) $importDis['payload']['calisma_lokasyonu_id'] === $ids['fabrika']
            && (int) $importDis['payload']['personel_tipi_id'] === 1,
        'import: harici + Mavi Yaka + fiili çalışma yeri geçerli'
    );

    $importDisBeyaz = PersonelCanonicalValidator::validateImportAnaVeriRow([
        'calisan_kapsami' => 'DIS_KAYNAK',
        'ad' => 'Import Beyaz',
        'sicil_no' => 'IMP-DIS-2',
        'ise_giris_tarihi' => '2026-02-01',
        'personel_tipi_id' => 2,
    ]);
    espAssert(
        $importDisBeyaz['errors'] === [] && (int) $importDisBeyaz['payload']['personel_tipi_id'] === 2,
        'import: harici + Beyaz Yaka geçerli'
    );
    espAssert(
        !array_key_exists('sgk_isveren_id', $importDisBeyaz['payload']),
        'import: harici + SGK işveren boş geçerli'
    );

    $importIcForeign = PersonelCanonicalValidator::validateImportAnaVeriRow([
        'calisan_kapsami' => 'IC_PERSONEL',
        'tc_kimlik_no' => '22222222220',
        'ad' => 'Import Dahili',
        'soyad' => 'Personel',
        'dogum_tarihi' => '1991-01-01',
        'telefon' => '05000000002',
        'sicil_no' => 'IMP-IC-1',
        'ise_giris_tarihi' => '2026-02-01',
        'sube_id' => $ids['fabrika'],
        'departman_id' => 1,
        'gorev_id' => 1,
        'personel_tipi_id' => 1,
        'sgk_isveren_id' => $ids['senayMobilya'],
    ]);
    espRefuses(
        static function () use ($pdo, $importIcForeign, $ids): void {
            PersonelSgkCompanyConsistency::assertCompatibleForKapsam(
                $pdo,
                (string) ($importIcForeign['payload']['calisan_kapsami'] ?? 'IC_PERSONEL'),
                $ids['senayMobilya'],
                $ids['fabrika']
            );
        },
        PersonelSgkCompanyConsistency::ERROR_MISMATCH,
        'import: dahili + farklı şirket SGK işvereni reddedilir'
    );

    // ------------------------------------------- F) Attendance / mobil kapsam

    espAssert(
        \Medisa\Api\Services\Personel\PersonelCalisanKapsamService::isDisKaynak($pdo, 2) === true,
        'DIS_KAYNAK personel kapsam ekseninden çözülür'
    );

    $disCaps = PersonelMobileCapabilityService::resolve($pdo, 2, ['calisan_kapsami' => 'DIS_KAYNAK']);
    espAssert(
        $disCaps['shell'] === true && $disCaps['qr_scan'] === true && $disCaps['attendance_correct'] === true,
        'DIS_KAYNAK QR ve bugün durumu kapsamında (attendance dışlaması yok)'
    );
    espAssert(
        $disCaps['puantaj_write'] === false && $disCaps['izin_write'] === false,
        'DIS_KAYNAK izin/finans yazımı fail-closed'
    );

    echo 'verify-personel-employment-scope-mysql: OK' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
