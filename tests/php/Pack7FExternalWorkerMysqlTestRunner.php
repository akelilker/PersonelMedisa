<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelCalisanKapsamSchema;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamService;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelValidationException;

function p7fAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function p7fPdo(string $dsn): PDO
{
    return new PDO($dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function p7fApplyMigration(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/066_personel_calisan_kapsami.sql');
    if ($sql === false) {
        throw new RuntimeException('Migration 066 okunamadi.');
    }
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $pdo->exec(trim($buffer));
            $buffer = '';
        }
    }
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

$db = 'medisa_pack7f_' . bin2hex(random_bytes(5));
$root = p7fPdo(preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = p7fPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));

try {
    $pdo->exec("CREATE TABLE personeller (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        tc_kimlik_no CHAR(11) NOT NULL,
        ad VARCHAR(80) NOT NULL,
        soyad VARCHAR(80) NOT NULL,
        dogum_tarihi DATE NOT NULL,
        telefon VARCHAR(32) NOT NULL,
        sicil_no VARCHAR(32) NOT NULL,
        ise_giris_tarihi DATE NOT NULL,
        aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
        PRIMARY KEY (id),
        UNIQUE KEY uq_personeller_tc (tc_kimlik_no),
        UNIQUE KEY uq_personeller_sicil (sicil_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO personeller
        (tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, sicil_no, ise_giris_tarihi)
        VALUES ('11111111111', 'Mevcut', 'Personel', '1990-01-01', '05000000000', 'P7F-IC-1', '2020-01-01')");

    p7fAssert(!PersonelCalisanKapsamSchema::isReady($pdo), 'schema 065 not ready');
    try {
        PersonelCalisanKapsamSchema::assertReadyForDisKaynakWrite($pdo, ['calisan_kapsami' => 'DIS_KAYNAK']);
        p7fAssert(false, 'schema 065 external write rejected');
    } catch (PersonelValidationException $e) {
        p7fAssert($e->getCodeString() === 'SCHEMA_NOT_READY', 'schema 065 external write rejected');
    }
    PersonelCalisanKapsamSchema::assertReadyForDisKaynakWrite($pdo, ['calisan_kapsami' => 'IC_PERSONEL']);
    p7fAssert(PersonelCalisanKapsamService::sqlIcPersonelPredicate($pdo, 'p') === '1=1', 'schema 065 query compatibility');

    p7fApplyMigration($pdo);
    p7fApplyMigration($pdo);
    p7fAssert(PersonelCalisanKapsamSchema::isReady($pdo), 'migration 066 ready and idempotent');
    p7fAssert($pdo->query("SELECT calisan_kapsami FROM personeller WHERE sicil_no = 'P7F-IC-1'")->fetchColumn() === 'IC_PERSONEL', 'existing row defaults internal');
    $nullableCount = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'personeller'
          AND column_name IN ('tc_kimlik_no','soyad','dogum_tarihi','telefon') AND is_nullable = 'YES'")->fetchColumn();
    p7fAssert($nullableCount === 4, 'external identity columns nullable');
    $tcUniqueCount = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'personeller'
          AND column_name = 'tc_kimlik_no' AND non_unique = 0")->fetchColumn();
    p7fAssert($tcUniqueCount >= 1, 'non-null TC unique index preserved');

    $external = PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
        'calisan_kapsami' => 'DIS_KAYNAK', 'ad' => 'Tekad', 'sicil_no' => 'P7F-DIS-1',
        'ise_giris_tarihi' => '2026-08-01', 'sube_id' => 1, 'departman_id' => 1,
        'gorev_id' => 1, 'personel_tipi_id' => 1, 'aktif_durum' => 'AKTIF'
    ]);
    p7fAssert($external['tc_kimlik_no'] === null && $external['soyad'] === null
        && $external['dogum_tarihi'] === null && $external['telefon'] === null, 'external nullable identity accepted');
    $externalWithTc = PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
        'calisan_kapsami' => 'DIS_KAYNAK', 'tc_kimlik_no' => '22222222222',
        'ad' => 'TcVar', 'sicil_no' => 'P7F-DIS-TC', 'ise_giris_tarihi' => '2026-08-01',
        'sube_id' => 1, 'departman_id' => 1, 'gorev_id' => 1,
        'personel_tipi_id' => 1, 'aktif_durum' => 'AKTIF'
    ]);
    p7fAssert($externalWithTc['tc_kimlik_no'] === '22222222222', 'valid supplied external TC accepted');

    $insert = $pdo->prepare("INSERT INTO personeller
        (tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, sicil_no, ise_giris_tarihi, calisan_kapsami)
        VALUES (:tc, :ad, :soyad, :dogum, :telefon, :sicil, :giris, :kapsam)");
    $insert->execute(['tc' => null, 'ad' => 'Tekad', 'soyad' => null, 'dogum' => null, 'telefon' => null,
        'sicil' => 'P7F-DIS-1', 'giris' => '2026-08-01', 'kapsam' => 'DIS_KAYNAK']);
    $externalId = (int) $pdo->lastInsertId();
    $insert->execute(['tc' => null, 'ad' => 'İkinci', 'soyad' => null, 'dogum' => null, 'telefon' => null,
        'sicil' => 'P7F-DIS-2', 'giris' => '2026-08-01', 'kapsam' => 'DIS_KAYNAK']);
    $insert->execute(['tc' => '22222222222', 'ad' => 'TcVar', 'soyad' => null, 'dogum' => null, 'telefon' => null,
        'sicil' => 'P7F-DIS-TC', 'giris' => '2026-08-01', 'kapsam' => 'DIS_KAYNAK']);
    try {
        $insert->execute(['tc' => '22222222222', 'ad' => 'TcTekrar', 'soyad' => null, 'dogum' => null, 'telefon' => null,
            'sicil' => 'P7F-DIS-TC-2', 'giris' => '2026-08-01', 'kapsam' => 'DIS_KAYNAK']);
        p7fAssert(false, 'duplicate non-null TC rejected');
    } catch (PDOException $e) {
        p7fAssert($e->getCode() === '23000', 'duplicate non-null TC rejected');
    }
    try {
        $insert->execute(['tc' => null, 'ad' => 'SicilTekrar', 'soyad' => null, 'dogum' => null, 'telefon' => null,
            'sicil' => 'P7F-DIS-2', 'giris' => '2026-08-01', 'kapsam' => 'DIS_KAYNAK']);
        p7fAssert(false, 'duplicate sicil rejected');
    } catch (PDOException $e) {
        p7fAssert($e->getCode() === '23000', 'duplicate sicil rejected');
    }
    p7fAssert(PersonelCalisanKapsamService::isDisKaynak($pdo, $externalId), 'external row resolved');
    p7fAssert(PersonelCalisanKapsamService::formatAdSoyad('Tekad', null) === 'Tekad', 'single token name null safe');
    p7fAssert((int) $pdo->query("SELECT COUNT(*) FROM personeller
        WHERE calisan_kapsami = 'DIS_KAYNAK' AND LOWER(ad) LIKE '%tekad%'")->fetchColumn() === 1, 'external search by ad works');

    try {
        PersonelCalisanKapsamService::assertOperationalEligibleOrThrow($pdo, $externalId);
        p7fAssert(false, 'external operation rejected');
    } catch (PersonelValidationException $e) {
        p7fAssert($e->getCodeString() === 'PERSONEL_OPERASYON_KAPSAM_DISI', 'external operation rejected');
    }

    try {
        PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
            'calisan_kapsami' => 'IC_PERSONEL', 'ad' => 'Eksik', 'sicil_no' => 'P7F-IC-2',
            'ise_giris_tarihi' => '2026-08-01', 'sube_id' => 1, 'departman_id' => 1,
            'gorev_id' => 1, 'personel_tipi_id' => 1, 'aktif_durum' => 'AKTIF'
        ]);
        p7fAssert(false, 'internal identity requirements preserved');
    } catch (PersonelValidationException $e) {
        p7fAssert($e->getField() === 'tc_kimlik_no', 'internal identity requirements preserved');
    }

    $requiredInternal = [
        'soyad' => ['tc_kimlik_no' => '33333333333', 'ad' => 'Ic', 'dogum_tarihi' => '1990-01-01', 'telefon' => '05000000000'],
        'dogum_tarihi' => ['tc_kimlik_no' => '33333333333', 'ad' => 'Ic', 'soyad' => 'Personel', 'telefon' => '05000000000'],
        'telefon' => ['tc_kimlik_no' => '33333333333', 'ad' => 'Ic', 'soyad' => 'Personel', 'dogum_tarihi' => '1990-01-01'],
    ];
    foreach ($requiredInternal as $missingField => $identityFields) {
        try {
            PersonelCanonicalValidator::normalizeAndValidateCreatePayload($identityFields + [
                'calisan_kapsami' => 'IC_PERSONEL', 'sicil_no' => 'P7F-IC-' . $missingField,
                'ise_giris_tarihi' => '2026-08-01', 'sube_id' => 1, 'departman_id' => 1,
                'gorev_id' => 1, 'personel_tipi_id' => 1, 'aktif_durum' => 'AKTIF'
            ]);
            p7fAssert(false, 'internal ' . $missingField . ' required');
        } catch (PersonelValidationException $e) {
            p7fAssert($e->getField() === $missingField, 'internal ' . $missingField . ' required');
        }
    }

    try {
        PersonelCalisanKapsamService::assertInternalIdentityComplete([
            'tc_kimlik_no' => null, 'soyad' => null, 'dogum_tarihi' => null, 'telefon' => null,
        ]);
        p7fAssert(false, 'external to internal requires full identity');
    } catch (PersonelValidationException $e) {
        p7fAssert($e->getField() === 'tc_kimlik_no', 'external to internal requires full identity');
    }

    try {
        PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
            'calisan_kapsami' => 'DIS_KAYNAK', 'tc_kimlik_no' => '123', 'ad' => 'Hata',
            'sicil_no' => 'P7F-DIS-3', 'ise_giris_tarihi' => '2026-08-01', 'sube_id' => 1,
            'departman_id' => 1, 'gorev_id' => 1, 'personel_tipi_id' => 1, 'aktif_durum' => 'AKTIF'
        ]);
        p7fAssert(false, 'supplied invalid external TC rejected');
    } catch (PersonelValidationException $e) {
        p7fAssert($e->getField() === 'tc_kimlik_no', 'supplied invalid external TC rejected');
    }

    try {
        PersonelCalisanKapsamService::assertSgkIsverenAllowed('DIS_KAYNAK', 1);
        p7fAssert(false, 'external SGK owner rejected');
    } catch (PersonelValidationException $e) {
        p7fAssert($e->getCodeString() === 'DIS_KAYNAK_SGK_ISVEREN_YASAK', 'external SGK owner rejected');
    }

    p7fAssert((int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE tc_kimlik_no IS NULL")->fetchColumn() === 2, 'multiple null TC rows allowed');
    echo "verify-pack7f-external-worker-mysql: OK\n";
} finally {
    $pdo = null;
    $root->exec('DROP DATABASE `' . $db . '`');
}
