<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelCalisanKapsamSchema;
use Medisa\Api\Services\Personel\PersonelCalisanKapsamService;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\BordroOnIzlemeService;
use Medisa\Api\Services\MaasHesaplamaAdayService;
use Medisa\Api\Services\SgkPrimGunuService;

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
        sube_id INT UNSIGNED NOT NULL DEFAULT 1,
        departman_id INT UNSIGNED NULL,
        ise_giris_tarihi DATE NOT NULL,
        aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
        PRIMARY KEY (id),
        UNIQUE KEY uq_personeller_tc (tc_kimlik_no)
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

    $sicilUniqueCount = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'personeller'
          AND column_name = 'sicil_no' AND non_unique = 0")->fetchColumn();
    p7fAssert($sicilUniqueCount >= 1, 'sicil unique index created by migration 066');

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
        p7fAssert($e->getCode() === '23000' && PersonelCreateService::isDuplicateTcException($e), 'duplicate non-null TC rejected');
    }
    // Duplicate sicil uniqueness is provided by migration 066 and tested below.
    p7fAssert(PersonelCalisanKapsamService::isDisKaynak($pdo, $externalId), 'external row resolved');
    p7fAssert(PersonelCalisanKapsamService::formatAdSoyad('Tekad', null) === 'Tekad', 'single token name null safe');
    p7fAssert((int) $pdo->query("SELECT COUNT(*) FROM personeller
        WHERE calisan_kapsami = 'DIS_KAYNAK' AND LOWER(ad) LIKE '%tekad%'")->fetchColumn() === 1, 'external search by ad works');

    // After migration created UNIQUE on sicil_no, duplicate sicil must be rejected by DB.
    try {
        $insert->execute(['tc' => null, 'ad' => 'SicilTekrar', 'soyad' => null, 'dogum' => null, 'telefon' => null,
            'sicil' => 'P7F-DIS-2', 'giris' => '2026-08-01', 'kapsam' => 'DIS_KAYNAK']);
        p7fAssert(false, 'duplicate sicil rejected by DB after migration');
    } catch (PDOException $e) {
        p7fAssert($e->getCode() === '23000' && PersonelCreateService::isDuplicateSicilException($e), 'duplicate sicil rejected by DB after migration');
    }

    $raceA = p7fPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));
    $raceB = p7fPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));
    $raceSicil = 'P7F-RACE-' . bin2hex(random_bytes(3));
    $raceInsertA = $raceA->prepare("INSERT INTO personeller
        (tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, sicil_no, ise_giris_tarihi, calisan_kapsami)
        VALUES (NULL, 'RaceA', NULL, NULL, NULL, :sicil, '2026-08-01', 'DIS_KAYNAK')");
    $raceInsertB = $raceB->prepare("INSERT INTO personeller
        (tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, sicil_no, ise_giris_tarihi, calisan_kapsami)
        VALUES (NULL, 'RaceB', NULL, NULL, NULL, :sicil, '2026-08-01', 'DIS_KAYNAK')");
    $raceInsertA->execute(['sicil' => $raceSicil]);
    try {
        $raceInsertB->execute(['sicil' => $raceSicil]);
        p7fAssert(false, 'sicil race second writer rejected');
    } catch (PDOException $e) {
        p7fAssert(PersonelCreateService::isDuplicateSicilException($e), 'sicil race second writer rejected');
    }
    $raceCountStmt = $pdo->prepare('SELECT COUNT(*) FROM personeller WHERE sicil_no = :sicil');
    $raceCountStmt->execute(['sicil' => $raceSicil]);
    p7fAssert((int) $raceCountStmt->fetchColumn() === 1, 'sicil race leaves exactly one persistent row');

    $uniqueA = 'P7F-NULL-TC-A-' . bin2hex(random_bytes(2));
    $uniqueB = 'P7F-NULL-TC-B-' . bin2hex(random_bytes(2));
    $insert->execute(['tc' => null, 'ad' => 'NullTcA', 'soyad' => null, 'dogum' => null, 'telefon' => null,
        'sicil' => $uniqueA, 'giris' => '2026-08-01', 'kapsam' => 'DIS_KAYNAK']);
    $insert->execute(['tc' => null, 'ad' => 'NullTcB', 'soyad' => null, 'dogum' => null, 'telefon' => null,
        'sicil' => $uniqueB, 'giris' => '2026-08-01', 'kapsam' => 'DIS_KAYNAK']);
    p7fAssert((int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE tc_kimlik_no IS NULL AND sicil_no IN ('$uniqueA', '$uniqueB')")->fetchColumn() === 2, 'multiple null TC with unique sicil coexist');

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

    $pdo->exec("CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(120) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE departmanlar (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(120) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO subeler (id, ad) VALUES (1, 'Merkez')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'Operasyon')");
    $histInsert = $pdo->prepare("INSERT INTO personeller
        (tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, sicil_no, sube_id, departman_id, ise_giris_tarihi, calisan_kapsami)
        VALUES ('44444444444', 'Hist', 'Personel', '1990-01-01', '05000000000', :sicil, 1, 1, '2020-01-01', 'IC_PERSONEL')");
    $histInsert->execute(['sicil' => 'P7F-HIST-1']);
    $histId = (int) $pdo->lastInsertId();

    $pdo->exec("CREATE TABLE maas_hesaplama_donem_snapshotlari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sube_id INT UNSIGNED NOT NULL, yil INT NOT NULL, ay INT NOT NULL, donem VARCHAR(7) NOT NULL,
        revision_no INT NOT NULL DEFAULT 1, state VARCHAR(32) NOT NULL DEFAULT 'OLUSTURULDU'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE maas_hesaplama_personel_snapshotlari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        donem_snapshot_id INT UNSIGNED NOT NULL, personel_id INT UNSIGNED NOT NULL,
        personel_snapshot_json JSON NULL, istihdam_baslangic DATE NOT NULL, istihdam_bitis DATE NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE maas_hesaplama_calistirmalari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        snapshot_id INT UNSIGNED NOT NULL, sube_id INT UNSIGNED NOT NULL, yil INT NOT NULL, ay INT NOT NULL,
        revision_no INT NOT NULL DEFAULT 1, parent_calistirma_id INT NULL, state VARCHAR(32) NOT NULL DEFAULT 'HESAPLANDI',
        engine_version VARCHAR(64) NOT NULL DEFAULT 'TEST', contract_version VARCHAR(64) NOT NULL DEFAULT 'TEST',
        snapshot_hash CHAR(64) NOT NULL DEFAULT REPEAT('a',64), parameter_set_hash CHAR(64) NOT NULL DEFAULT REPEAT('b',64),
        carryover_set_hash CHAR(64) NOT NULL DEFAULT REPEAT('c',64), correction_projection_hash CHAR(64) NULL,
        policy_version_hash CHAR(64) NULL, request_hash CHAR(64) NOT NULL DEFAULT REPEAT('d',64),
        source_hash CHAR(64) NOT NULL DEFAULT REPEAT('e',64), result_hash CHAR(64) NOT NULL DEFAULT REPEAT('f',64),
        calculation_input_hash CHAR(64) NOT NULL DEFAULT REPEAT('1',64), personel_sayisi INT NOT NULL DEFAULT 1,
        basarili_aday_sayisi INT NOT NULL DEFAULT 1, hatali_aday_sayisi INT NOT NULL DEFAULT 0,
        blocker_count INT NOT NULL DEFAULT 0, warning_count INT NOT NULL DEFAULT 0,
        bordro_onay_durumu VARCHAR(32) NOT NULL DEFAULT 'HESAPLANDI', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE maas_hesaplama_adaylari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        calistirma_id INT UNSIGNED NOT NULL, personel_snapshot_id INT UNSIGNED NOT NULL, personel_id INT UNSIGNED NOT NULL,
        revision_no INT NOT NULL DEFAULT 1, state VARCHAR(32) NOT NULL DEFAULT 'HESAPLANDI',
        ucret_turu VARCHAR(16) NOT NULL DEFAULT 'NET', para_birimi CHAR(3) NOT NULL DEFAULT 'TRY',
        hedef_net_tutar DECIMAL(12,2) NULL, sozlesme_brut_tutar DECIMAL(12,2) NULL,
        hesaplanan_brut_tutar DECIMAL(12,2) NOT NULL DEFAULT 1000.00, sgk_matrahi DECIMAL(12,2) NOT NULL DEFAULT 1000.00,
        gelir_vergisi_matrahi DECIMAL(12,2) NOT NULL DEFAULT 1000.00, damga_vergisi_matrahi DECIMAL(12,2) NOT NULL DEFAULT 1000.00,
        sgk_isci_primi DECIMAL(12,2) NOT NULL DEFAULT 100.00, issizlik_isci_primi DECIMAL(12,2) NOT NULL DEFAULT 10.00,
        gelir_vergisi DECIMAL(12,2) NOT NULL DEFAULT 100.00, damga_vergisi DECIMAL(12,2) NOT NULL DEFAULT 10.00,
        toplam_ek_odeme DECIMAL(12,2) NOT NULL DEFAULT 0.00, toplam_kesinti DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        net_odenecek DECIMAL(12,2) NOT NULL DEFAULT 780.00, sonraki_kumulatif_vergi_matrahi DECIMAL(12,2) NOT NULL DEFAULT 1000.00,
        input_hash CHAR(64) NOT NULL DEFAULT REPEAT('2',64), result_hash CHAR(64) NOT NULL DEFAULT REPEAT('3',64),
        engine_version VARCHAR(64) NOT NULL DEFAULT 'TEST', carryover_json JSON NULL, solver_json JSON NULL,
        correction_projection_json JSON NULL, bordro_onay_durumu VARCHAR(32) NOT NULL DEFAULT 'HESAPLANDI',
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE maas_hesaplama_aday_kalemleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, aday_id INT UNSIGNED NOT NULL, sira_no INT NOT NULL,
        kalem_grubu VARCHAR(32) NOT NULL, kalem_kodu VARCHAR(64) NOT NULL, yon VARCHAR(16) NOT NULL,
        miktar DECIMAL(12,2) NULL, birim VARCHAR(16) NULL, oran DECIMAL(12,4) NULL, matrah DECIMAL(12,2) NULL,
        tutar DECIMAL(12,2) NOT NULL, kaynak_turu VARCHAR(32) NULL, kaynak_id INT NULL, aciklama VARCHAR(255) NULL,
        payload_json JSON NULL, payload_hash CHAR(64) NOT NULL DEFAULT REPEAT('4',64)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE maas_hesaplama_sgk_snapshotlari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        donem_snapshot_id INT UNSIGNED NOT NULL, personel_snapshot_id INT UNSIGNED NOT NULL, personel_id INT UNSIGNED NOT NULL,
        hesaplanan_prim_gunu INT NULL, eksik_gun_sayisi INT NULL, eksik_gun_kodu VARCHAR(8) NULL, eksik_gun_aciklamasi VARCHAR(255) NULL,
        kaynak_surec_idleri_json JSON NULL, kaynak_puantaj_idleri_json JSON NULL, kaynak_belge_idleri_json JSON NULL,
        katalog_surum_id INT NULL, katalog_surumu VARCHAR(64) NULL, kaynak_manifest_hash CHAR(64) NULL,
        sgk_hesap_hash CHAR(64) NOT NULL DEFAULT REPEAT('5',64), gunluk_karar_dokumu_hash CHAR(64) NOT NULL DEFAULT REPEAT('6',64),
        gunluk_karar_dokumu_json JSON NULL, manuel_inceleme_gerekli_mi TINYINT(1) NOT NULL DEFAULT 0,
        blocker_kodlari_json JSON NULL, blocker_detaylari_json JSON NULL, ucret_modeli VARCHAR(32) NOT NULL DEFAULT 'MAKTU_AYLIK',
        ilk_iki_gun_politika_ozeti_json JSON NULL, sirket_politika_surum_id INT NULL, sirket_politika_hash CHAR(64) NULL,
        sgk_odenek_durumu VARCHAR(32) NOT NULL DEFAULT 'UYGULANMAZ', is_goremezlik_finans_ozeti_json JSON NULL,
        gunluk_alt_sinir DECIMAL(12,2) NULL, gunluk_ust_sinir DECIMAL(12,2) NULL, donem_alt_sinir DECIMAL(12,2) NULL,
        donem_ust_sinir DECIMAL(12,2) NULL, sinir_mevzuat_surumu VARCHAR(64) NULL, source_hash CHAR(64) NOT NULL DEFAULT REPEAT('7',64),
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT INTO maas_hesaplama_donem_snapshotlari (id, sube_id, yil, ay, donem) VALUES (1, 1, 2026, 8, '2026-08')");
    $pdo->prepare("INSERT INTO maas_hesaplama_personel_snapshotlari
        (id, donem_snapshot_id, personel_id, personel_snapshot_json, istihdam_baslangic)
        VALUES (1, 1, :pid, JSON_OBJECT('ad_soyad', 'Hist Personel'), '2020-01-01')")->execute(['pid' => $histId]);
    $pdo->exec("INSERT INTO maas_hesaplama_calistirmalari (id, snapshot_id, sube_id, yil, ay) VALUES (1, 1, 1, 2026, 8)");
    $pdo->prepare("INSERT INTO maas_hesaplama_adaylari (id, calistirma_id, personel_snapshot_id, personel_id) VALUES (1, 1, 1, :pid)")->execute(['pid' => $histId]);
    $pdo->exec("INSERT INTO maas_hesaplama_aday_kalemleri
        (aday_id, sira_no, kalem_grubu, kalem_kodu, yon, tutar, kaynak_turu, aciklama, payload_json)
        VALUES (1, 1, 'BRUT', 'TEST', 'ARTI', 1000.00, 'HESAP', 'Test kalem', JSON_OBJECT())");
    $pdo->prepare("INSERT INTO maas_hesaplama_sgk_snapshotlari
        (donem_snapshot_id, personel_snapshot_id, personel_id, hesaplanan_prim_gunu, eksik_gun_sayisi,
         kaynak_surec_idleri_json, kaynak_puantaj_idleri_json, kaynak_belge_idleri_json, gunluk_karar_dokumu_json,
         blocker_kodlari_json, blocker_detaylari_json, ilk_iki_gun_politika_ozeti_json, is_goremezlik_finans_ozeti_json)
        VALUES (1, 1, :pid, 30, 0, JSON_ARRAY(), JSON_ARRAY(), JSON_ARRAY(), JSON_ARRAY(), JSON_ARRAY(), JSON_ARRAY(), JSON_OBJECT(), JSON_ARRAY())")->execute(['pid' => $histId]);

    p7fAssert(count(MaasHesaplamaAdayService::listAdaylar($pdo, 1)) === 1, 'maas materialized read visible before scope change');
    p7fAssert(count(BordroOnIzlemeService::listPersonelSatirlari($pdo, 1)) === 1, 'bordro materialized read visible before scope change');
    p7fAssert(count(SgkPrimGunuService::listCanonicalResults($pdo, 1, 2026, 8, $histId)) === 1, 'sgk materialized read visible before scope change');
    $pdo->prepare("UPDATE personeller SET calisan_kapsami = 'DIS_KAYNAK' WHERE id = :id")->execute(['id' => $histId]);
    p7fAssert(count(MaasHesaplamaAdayService::listAdaylar($pdo, 1)) === 1 && MaasHesaplamaAdayService::getAday($pdo, 1) !== null, 'maas materialized read preserved after external transition');
    p7fAssert(count(BordroOnIzlemeService::listPersonelSatirlari($pdo, 1)) === 1 && BordroOnIzlemeService::getAdayDetay($pdo, 1) !== null, 'bordro materialized read preserved after external transition');
    p7fAssert(count(SgkPrimGunuService::listCanonicalResults($pdo, 1, 2026, 8, $histId)) === 1, 'sgk materialized read preserved after external transition');

    p7fAssert((int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE tc_kimlik_no IS NULL")->fetchColumn() >= 4, 'multiple null TC rows allowed');
    echo "verify-pack7f-external-worker-mysql: OK\n";
} finally {
    $pdo = null;
    $root->exec('DROP DATABASE `' . $db . '`');
}
