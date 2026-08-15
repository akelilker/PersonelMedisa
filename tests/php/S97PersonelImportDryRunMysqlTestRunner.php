<?php

declare(strict_types=1);

/**
 * S97-A MariaDB acceptance: personel import dry-run (no write).
 * Requires MEDISA_TEST_MYSQL_DSN.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelImportDryRunService;
use Medisa\Api\Services\Personel\PersonelImportException;

function s97Assert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s97Pdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function s97CountPersonel(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
}

function s97CountSalary(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personel_ucret_gecmisi')->fetchColumn();
}

function s97CountAudit(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personel_ucret_auditleri')->fetchColumn();
}

function s97HeaderCsv(array $extra = []): string
{
    $cols = PersonelImportDryRunService::TEMPLATE_COLUMNS;
    if (count($extra) > 0) {
        $cols = array_merge($cols, $extra);
    }

    return implode(';', $cols);
}

function s97ValidRow(array $overrides = []): string
{
    $row = [
        'tc_kimlik_no' => '10000000146',
        'sicil_no' => 'IMP-001',
        'ad' => 'Ayşe',
        'soyad' => 'Yılmaz',
        'dogum_tarihi' => '1990-05-15',
        'dogum_yeri' => 'Ankara',
        'telefon' => '0532 111 22 33',
        'kan_grubu' => 'A Rh+',
        'acil_durum_kisi' => 'Ali Yılmaz',
        'acil_durum_telefon' => '0532 444 55 66',
        'ise_giris_tarihi' => '2024-01-10',
        'sube' => 'Merkez',
        'departman' => 'İdari İşler',
        'gorev' => 'Asistan',
        'personel_tipi' => 'Tam Zamanli',
    ];
    foreach ($overrides as $key => $value) {
        $row[$key] = $value;
    }
    $ordered = [];
    foreach (PersonelImportDryRunService::TEMPLATE_COLUMNS as $col) {
        $ordered[] = (string) ($row[$col] ?? '');
    }

    return implode(';', $ordered);
}

$adminDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$userName = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
if ($adminDsn === '' || $userName === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN/USER required for S97 personel import dry-run MariaDB acceptance\n");
    exit(1);
}

if (!extension_loaded('pdo_mysql') && !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "pdo_mysql driver missing\n");
    exit(1);
}

$admin = s97Pdo($adminDsn);
$database = 'medisa_s97_personel_import_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $adminDsn);
    putenv('MEDISA_TEST_MYSQL_DSN=' . $dsn);
    $_ENV['MEDISA_TEST_MYSQL_DSN'] = $dsn;
    $pdo = s97Pdo((string) $dsn);

    $pdo->exec("
        CREATE TABLE subeler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          kod VARCHAR(32) NOT NULL,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id),
          UNIQUE KEY uq_subeler_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE departmanlar (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE gorevler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_tipleri (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE sube_departmanlar (
          sube_id INT UNSIGNED NOT NULL,
          departman_id INT UNSIGNED NOT NULL,
          PRIMARY KEY (sube_id, departman_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personeller (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          tc_kimlik_no CHAR(11) NOT NULL,
          ad VARCHAR(80) NOT NULL,
          soyad VARCHAR(80) NOT NULL,
          dogum_tarihi DATE NOT NULL,
          telefon VARCHAR(32) NOT NULL,
          acil_durum_kisi VARCHAR(120) NULL,
          acil_durum_telefon VARCHAR(32) NULL,
          sicil_no VARCHAR(32) NOT NULL,
          ise_giris_tarihi DATE NOT NULL,
          sube_id INT UNSIGNED NOT NULL,
          departman_id INT UNSIGNED NULL,
          gorev_id INT UNSIGNED NULL,
          personel_tipi_id INT UNSIGNED NULL,
          bagli_amir_id INT UNSIGNED NULL,
          aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          dogum_yeri VARCHAR(80) NULL,
          kan_grubu VARCHAR(8) NULL,
          ucret_tipi_id INT UNSIGNED NULL,
          maas_tutari DECIMAL(12,2) NULL,
          prim_kurali_id INT UNSIGNED NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_personeller_tc (tc_kimlik_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_ucret_gecmisi (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          personel_id INT UNSIGNED NOT NULL,
          ucret_tutari DECIMAL(12,2) NOT NULL,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_ucret_auditleri (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          personel_id INT UNSIGNED NOT NULL,
          aksiyon VARCHAR(40) NOT NULL,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        ALTER TABLE personeller
          MODIFY tc_kimlik_no CHAR(11) NULL,
          MODIFY soyad VARCHAR(80) NULL,
          MODIFY dogum_tarihi DATE NULL,
          MODIFY telefon VARCHAR(32) NULL,
          ADD calisan_kapsami VARCHAR(20) NOT NULL DEFAULT 'IC_PERSONEL'
    ");

    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'İdari İşler'), (2, 'Klinik')");
    $pdo->exec("INSERT INTO gorevler (id, ad) VALUES (1, 'Uzman'), (2, 'Asistan'), (3, 'Uzman')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad) VALUES (1, 'Tam Zamanli'), (2, 'Yari Zamanli')");
    $pdo->exec('INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1, 1), (1, 2), (2, 2)');
    $pdo->exec("
        INSERT INTO personeller (
          tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
          sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id, aktif_durum
        ) VALUES (
          '11111111110', 'Mevcut', 'Personel', '1985-01-01', '05320000000', 'Acil', '05320000001',
          'EXIST-001', '2020-01-01', 1, 1, 1, 1, 'AKTIF'
        )
    ");
    $pdo->exec('INSERT INTO personel_ucret_gecmisi (personel_id, ucret_tutari) VALUES (1, 1000.00)');
    $pdo->exec("INSERT INTO personel_ucret_auditleri (personel_id, aksiyon) VALUES (1, 'CREATE')");

    $gyUser = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $scopedUser = ['id' => 2, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];
    $beforePersonel = s97CountPersonel($pdo);
    $beforeSalary = s97CountSalary($pdo);
    $beforeAudit = s97CountAudit($pdo);

    // 1) Valid CSV dry-run PASS (Turkish reference name exact match)
    $validCsv = s97HeaderCsv() . "\r\n" . s97ValidRow() . "\r\n";
    $result = PersonelImportDryRunService::dryRun($pdo, $validCsv, $gyUser, null);
    s97Assert(($result['ozet']['gecerli_satir'] ?? 0) === 1, 'valid csv dry-run PASS');
    s97Assert(($result['ozet']['hatali_satir'] ?? 0) === 0, 'valid csv dry-run zero hatali');
    s97Assert(($result['yazma']['personel_write'] ?? true) === false, 'personel_write false');
    s97Assert(($result['yazma']['salary_write'] ?? true) === false, 'salary_write false');
    s97Assert(($result['satirlar'][0]['tc_kimlik_no_masked'] ?? '') === '100******46', 'turkish/valid row masked TC');

    // IC phone is a missing-info warning, not an import blocker.
    $missingPhoneCsv = s97HeaderCsv() . "\r\n" . s97ValidRow([
        'tc_kimlik_no' => '10000000276',
        'sicil_no' => 'PHONE-DEFER',
        'telefon' => '',
    ]) . "\r\n";
    $missingPhone = PersonelImportDryRunService::dryRun($pdo, $missingPhoneCsv, $gyUser, null);
    s97Assert(($missingPhone['ozet']['gecerli_satir'] ?? 0) === 1, 'missing IC phone remains import-valid');
    s97Assert(($missingPhone['ozet']['hatali_satir'] ?? 1) === 0, 'missing IC phone has no hard error');
    s97Assert(($missingPhone['ozet']['warning_sayisi'] ?? 0) === 1, 'missing IC phone warning counted');
    s97Assert(
        in_array('PERSONEL_IMPORT_EKSIK_TELEFON', $missingPhone['satirlar'][0]['uyarilar'] ?? [], true),
        'missing IC phone deferred warning'
    );
    s97Assert(($missingPhone['can_apply'] ?? false) === true, 'missing IC phone can_apply');

    $nullableExternalCsv = s97HeaderCsv() . ";calisan_kapsami\r\n" . s97ValidRow([
        'tc_kimlik_no' => '',
        'sicil_no' => 'DIS-NULLABLE',
        'soyad' => '',
        'dogum_tarihi' => '',
        'telefon' => '',
    ]) . ";DIS_KAYNAK\r\n";
    $nullableExternal = PersonelImportDryRunService::dryRun($pdo, $nullableExternalCsv, $gyUser, null);
    s97Assert(($nullableExternal['ozet']['gecerli_satir'] ?? 0) === 1, 'DIS_KAYNAK nullable phone remains valid');
    s97Assert(($nullableExternal['ozet']['warning_sayisi'] ?? 1) === 0, 'DIS_KAYNAK phone has no IC warning');

    // 2) Zero-write deltas
    s97Assert(s97CountPersonel($pdo) === $beforePersonel, 'PERSONEL_ROW_DELTA = 0');
    s97Assert(s97CountSalary($pdo) === $beforeSalary, 'SALARY_ROW_DELTA = 0');
    s97Assert(s97CountAudit($pdo) === $beforeAudit, 'AUDIT_ROW_DELTA = 0');

    // 3) Missing required column
    $missingCol = "tc_kimlik_no;sicil_no;ad\r\n1;2;3\r\n";
    try {
        PersonelImportDryRunService::dryRun($pdo, $missingCol, $gyUser, null);
        s97Assert(false, 'missing required column should throw');
    } catch (PersonelImportException $e) {
        s97Assert($e->getCodeString() === 'PERSONEL_IMPORT_EKSIK_ZORUNLU_KOLON', 'missing required column');
    }

    // 4) Invalid TC
    $badTcCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['tc_kimlik_no' => '123']) . "\r\n";
    $badTc = PersonelImportDryRunService::dryRun($pdo, $badTcCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_GECERSIZ_TC', $badTc['satirlar'][0]['hata_kodlari'], true), 'invalid TC');

    // 5) Invalid date (slash + local dotted format rejected; no auto-guess)
    $badDateCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['dogum_tarihi' => '15/05/1990']) . "\r\n";
    $badDate = PersonelImportDryRunService::dryRun($pdo, $badDateCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_GECERSIZ_TARIH', $badDate['satirlar'][0]['hata_kodlari'], true), 'invalid date');
    $localDateCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['dogum_tarihi' => '01.02.2026']) . "\r\n";
    $localDate = PersonelImportDryRunService::dryRun($pdo, $localDateCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_GECERSIZ_TARIH', $localDate['satirlar'][0]['hata_kodlari'], true), 'local dotted date rejected');

    // 6) In-file duplicate TC
    $dupTcCsv = s97HeaderCsv() . "\r\n"
        . s97ValidRow(['tc_kimlik_no' => '10000000146', 'sicil_no' => 'A1']) . "\r\n"
        . s97ValidRow(['tc_kimlik_no' => '10000000146', 'sicil_no' => 'A2']) . "\r\n";
    $dupTc = PersonelImportDryRunService::dryRun($pdo, $dupTcCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_DOSYA_ICI_DUPLICATE_TC', $dupTc['satirlar'][1]['hata_kodlari'], true), 'infile duplicate TC');

    // 7) Existing TC in DB
    $existTcCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['tc_kimlik_no' => '11111111110', 'sicil_no' => 'NEW-9']) . "\r\n";
    $existTc = PersonelImportDryRunService::dryRun($pdo, $existTcCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_TC_MEVCUT', $existTc['satirlar'][0]['hata_kodlari'], true), 'existing TC in DB');
    s97Assert(($existTc['ozet']['veritabaninda_mevcut'] ?? 0) >= 1, 'veritabaninda_mevcut counted');

    // 8) Duplicate sicil in file
    $dupSicilCsv = s97HeaderCsv() . "\r\n"
        . s97ValidRow(['tc_kimlik_no' => '10000000146', 'sicil_no' => 'SAME']) . "\r\n"
        . s97ValidRow(['tc_kimlik_no' => '10000000154', 'sicil_no' => 'SAME']) . "\r\n";
    $dupSicil = PersonelImportDryRunService::dryRun($pdo, $dupSicilCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_DOSYA_ICI_DUPLICATE_SICIL', $dupSicil['satirlar'][1]['hata_kodlari'], true), 'infile duplicate sicil');

    // Existing sicil in DB
    $existSicilCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['tc_kimlik_no' => '10000000162', 'sicil_no' => 'EXIST-001']) . "\r\n";
    $existSicil = PersonelImportDryRunService::dryRun($pdo, $existSicilCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_SICIL_MEVCUT', $existSicil['satirlar'][0]['hata_kodlari'], true), 'existing sicil in DB');

    // 9) Unknown reference
    $unknownRefCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['sube' => 'Yok Sube']) . "\r\n";
    $unknownRef = PersonelImportDryRunService::dryRun($pdo, $unknownRefCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_REFERANS_BULUNAMADI', $unknownRef['satirlar'][0]['hata_kodlari'], true), 'unknown reference');

    // 10) Ambiguous reference (gorev "Uzman" exists twice)
    $ambCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['gorev' => 'Uzman']) . "\r\n";
    $amb = PersonelImportDryRunService::dryRun($pdo, $ambCsv, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_REFERANS_BELIRSIZ', $amb['satirlar'][0]['hata_kodlari'], true), 'ambiguous reference');

    // 11) Sube scope violation
    $scopeCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['sube' => 'Sube 2', 'departman' => 'Klinik', 'gorev' => 'Asistan']) . "\r\n";
    $scope = PersonelImportDryRunService::dryRun($pdo, $scopeCsv, $scopedUser, 1);
    s97Assert(in_array('PERSONEL_IMPORT_SUBE_SCOPE_IHLALI', $scope['satirlar'][0]['hata_kodlari'], true), 'sube scope ihlali');

    // Pack7B TEST_A: sparse matrix, ACTIVE branch + ACTIVE department without a pair.
    $openPairCsv = s97HeaderCsv() . "\r\n" . s97ValidRow([
        'tc_kimlik_no' => '10000000250',
        'sicil_no' => 'OPEN-001',
        'sube' => 'Sube 2',
        'departman' => 'İdari İşler',
        'gorev' => 'Asistan',
    ]) . "\r\n";
    $openPair = PersonelImportDryRunService::dryRun($pdo, $openPairCsv, $gyUser, null);
    s97Assert(($openPair['ozet']['gecerli_satir'] ?? 0) === 1, 'open pair unmapped ACTIVE dry-run PASS');
    s97Assert(($openPair['can_apply'] ?? false) === true, 'open pair unmapped ACTIVE can_apply');
    s97Assert(
        !in_array('PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI', $openPair['satirlar'][0]['hata_kodlari'] ?? [], true),
        'no ILISKISI for unmapped ACTIVE pair'
    );

    // Pack7B TEST_B: open department model does not bypass branch scope / identity checks.
    $openScope = PersonelImportDryRunService::dryRun($pdo, $openPairCsv, $scopedUser, 1);
    s97Assert(
        in_array('PERSONEL_IMPORT_SUBE_SCOPE_IHLALI', $openScope['satirlar'][0]['hata_kodlari'], true),
        'open pair still sube scope ihlali'
    );
    $openDup = s97HeaderCsv() . "\r\n" . s97ValidRow([
        'tc_kimlik_no' => '11111111110',
        'sicil_no' => 'OPEN-DUP',
        'sube' => 'Sube 2',
        'departman' => 'İdari İşler',
        'gorev' => 'Asistan',
    ]) . "\r\n";
    $openDupResult = PersonelImportDryRunService::dryRun($pdo, $openDup, $gyUser, null);
    s97Assert(
        in_array('PERSONEL_IMPORT_TC_MEVCUT', $openDupResult['satirlar'][0]['hata_kodlari'], true),
        'open pair still existing TC'
    );
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (9, 'Pasif Dep', 'PASIF')");
    $pasifCsv = s97HeaderCsv() . "\r\n" . s97ValidRow([
        'tc_kimlik_no' => '10000000268',
        'sicil_no' => 'OPEN-PASIF',
        'sube' => 'Sube 2',
        'departman' => 'Pasif Dep',
        'gorev' => 'Asistan',
    ]) . "\r\n";
    $pasifResult = PersonelImportDryRunService::dryRun($pdo, $pasifCsv, $gyUser, null);
    s97Assert(
        in_array('PERSONEL_IMPORT_REFERANS_BULUNAMADI', $pasifResult['satirlar'][0]['hata_kodlari'], true),
        'open pair still rejects inactive department'
    );

    // 12) Wage field reject (case + whitespace bypass blocked by header normalize)
    try {
        $wageCsv = "tc_kimlik_no;sicil_no;ad;soyad;dogum_tarihi;dogum_yeri;telefon;kan_grubu;acil_durum_kisi;acil_durum_telefon;ise_giris_tarihi;sube;departman;gorev;personel_tipi; Maas_Tutari \r\n"
            . s97ValidRow() . ";1000\r\n";
        PersonelImportDryRunService::dryRun($pdo, $wageCsv, $gyUser, null);
        s97Assert(false, 'wage column should throw');
    } catch (PersonelImportException $e) {
        s97Assert($e->getCodeString() === 'PERSONEL_IMPORT_UCRET_KARARI_BEKLENIYOR', 'wage field reject');
    }

    // Header case-normalization accepts mixed case required headers
    $mixedHeader = strtoupper(s97HeaderCsv()) . "\r\n" . s97ValidRow() . "\r\n";
    $mixed = PersonelImportDryRunService::dryRun($pdo, $mixedHeader, $gyUser, null);
    s97Assert(($mixed['ozet']['gecerli_satir'] ?? 0) === 1, 'mixed-case headers accepted');

    // Emergency contact optional: both empty → PASS (NULL), no placeholder
    $emptyAcilCsv = s97HeaderCsv() . "\r\n" . s97ValidRow([
        'tc_kimlik_no' => '10000000170',
        'sicil_no' => 'ACIL-EMPTY',
        'acil_durum_kisi' => '',
        'acil_durum_telefon' => '',
    ]) . "\r\n";
    $emptyAcil = PersonelImportDryRunService::dryRun($pdo, $emptyAcilCsv, $gyUser, null);
    s97Assert(($emptyAcil['ozet']['gecerli_satir'] ?? 0) === 1, 'empty emergency contact dry-run PASS');
    s97Assert(($emptyAcil['ozet']['hatali_satir'] ?? 1) === 0, 'empty emergency contact no errors');
    s97Assert(($emptyAcil['can_apply'] ?? false) === true, 'empty emergency contact can_apply');

    // One filled / one empty still PASS
    $partialAcilCsv = s97HeaderCsv() . "\r\n" . s97ValidRow([
        'tc_kimlik_no' => '10000000188',
        'sicil_no' => 'ACIL-PARTIAL',
        'acil_durum_kisi' => 'Ayşe Veli',
        'acil_durum_telefon' => '',
    ]) . "\r\n";
    $partialAcil = PersonelImportDryRunService::dryRun($pdo, $partialAcilCsv, $gyUser, null);
    s97Assert(($partialAcil['ozet']['gecerli_satir'] ?? 0) === 1, 'partial emergency contact dry-run PASS');

    // Both filled preserved
    $fullAcilCsv = s97HeaderCsv() . "\r\n" . s97ValidRow([
        'tc_kimlik_no' => '10000000196',
        'sicil_no' => 'ACIL-FULL',
        'acil_durum_kisi' => 'Ali Yılmaz',
        'acil_durum_telefon' => '0532 444 55 66',
    ]) . "\r\n";
    $fullAcil = PersonelImportDryRunService::dryRun($pdo, $fullAcilCsv, $gyUser, null);
    s97Assert(($fullAcil['ozet']['gecerli_satir'] ?? 0) === 1, 'full emergency contact dry-run PASS');

    // Deterministic: empty-acil dry-run twice → identical manifest
    $emptyA = PersonelImportDryRunService::dryRun($pdo, $emptyAcilCsv, $gyUser, null);
    $emptyB = PersonelImportDryRunService::dryRun($pdo, $emptyAcilCsv, $gyUser, null);
    s97Assert($emptyA['manifest_hash'] === $emptyB['manifest_hash'], 'empty emergency contact manifest deterministic');

    // Jagged row fail-closed
    $jagged = s97HeaderCsv() . "\r\n" . s97ValidRow() . ";EXTRA\r\n";
    $jaggedResult = PersonelImportDryRunService::dryRun($pdo, $jagged, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_SATIR_KOLON_UYUMSUZ', $jaggedResult['satirlar'][0]['hata_kodlari'], true), 'jagged row fail-closed');

    // Turkish reference exact-only: ASCII lookalike must fail
    $asciiDept = s97HeaderCsv() . "\r\n" . s97ValidRow(['departman' => 'Idari Isler']) . "\r\n";
    $asciiDeptResult = PersonelImportDryRunService::dryRun($pdo, $asciiDept, $gyUser, null);
    s97Assert(in_array('PERSONEL_IMPORT_REFERANS_BULUNAMADI', $asciiDeptResult['satirlar'][0]['hata_kodlari'], true), 'turkish reference exact match');

    // 13) 500 row limit
    $lines = [s97HeaderCsv()];
    for ($i = 0; $i < 501; $i++) {
        $tcBase = 10000000000 + $i;
        $lines[] = s97ValidRow([
            'tc_kimlik_no' => (string) $tcBase,
            'sicil_no' => 'R' . $i,
            'gorev' => 'Asistan',
        ]);
    }
    try {
        PersonelImportDryRunService::dryRun($pdo, implode("\r\n", $lines) . "\r\n", $gyUser, null);
        s97Assert(false, '500 row limit should throw');
    } catch (PersonelImportException $e) {
        s97Assert($e->getCodeString() === 'PERSONEL_IMPORT_SATIR_SINIRI', '500 row limit');
    }

    // 14) File size limit
    $huge = str_repeat('a', PersonelImportDryRunService::MAX_BYTES + 1);
    try {
        PersonelImportDryRunService::dryRun($pdo, $huge, $gyUser, null);
        s97Assert(false, 'file size limit should throw');
    } catch (PersonelImportException $e) {
        s97Assert($e->getCodeString() === 'PERSONEL_IMPORT_DOSYA_BOYUTU', 'file size limit');
    }

    // 15) Raw TC not logged in response payload fields; only masked form
    $maskCsv = s97HeaderCsv() . "\r\n" . s97ValidRow(['tc_kimlik_no' => '123']) . "\r\n";
    $maskResult = PersonelImportDryRunService::dryRun($pdo, $maskCsv, $gyUser, null);
    $encoded = json_encode($maskResult, JSON_UNESCAPED_UNICODE);
    s97Assert(is_string($encoded) && strpos($encoded, '"tc_kimlik_no"') === false, 'raw TC field absent in dry-run JSON');
    s97Assert(($maskResult['satirlar'][0]['tc_kimlik_no_masked'] ?? '') === '*23', 'masked TC shown');
    s97Assert(PersonelCanonicalValidator::maskTcKimlikNo('10000000146') === '100******46', 'mask helper first3+last2');
    s97Assert(PersonelCanonicalValidator::maskTcKimlikNo('12345678901') === '123******01', 'mask helper example shape');
    s97Assert(strpos(PersonelCanonicalValidator::maskTcKimlikNo('10000000146'), '10000000146') === false, 'raw TC not in mask');

    // Create validator regression (shared owner)
    $createPayload = PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
        'tc_kimlik_no' => '10000000170',
        'ad' => 'Test',
        'soyad' => 'Kisi',
        'dogum_tarihi' => '1991-02-02',
        'telefon' => '05321112233',
        'acil_durum_kisi' => 'X',
        'acil_durum_telefon' => '05321112234',
        'sicil_no' => 'C-1',
        'ise_giris_tarihi' => '2025-01-01',
        'sube_id' => 1,
        'departman_id' => 1,
        'gorev_id' => 2,
        'personel_tipi_id' => 1,
        'aktif_durum' => 'AKTIF',
    ]);
    s97Assert($createPayload['ad'] === 'Test', 'create validator regression');

    $updatePayload = PersonelCanonicalValidator::normalizeAndValidateUpdatePayload([
        'ad' => 'YeniAd',
        'telefon' => '05329998877',
    ]);
    s97Assert($updatePayload['ad'] === 'YeniAd', 'update validator regression');

    // Final no-write guarantee
    s97Assert(s97CountPersonel($pdo) === $beforePersonel, 'final PERSONEL_ROW_DELTA = 0');
    s97Assert(s97CountSalary($pdo) === $beforeSalary, 'final SALARY_ROW_DELTA = 0');
    s97Assert(s97CountAudit($pdo) === $beforeAudit, 'final AUDIT_ROW_DELTA = 0');

    // Template UTF-8 BOM + semicolon
    $template = PersonelImportDryRunService::buildTemplateCsv();
    s97Assert(strncmp($template, "\xEF\xBB\xBF", 3) === 0, 'template has UTF-8 BOM');
    s97Assert(strpos($template, ';') !== false, 'template uses semicolon');

    echo 'verify-s97-personel-import-dry-run-mysql: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
