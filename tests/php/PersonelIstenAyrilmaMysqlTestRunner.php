<?php

declare(strict_types=1);

/**
 * Canonical ISTEN_AYRILMA owner: date/duplicate/PASIF gates + atomic surec+PASIF.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelIstenAyrilmaService;
use Medisa\Api\Services\Personel\PersonelValidationException;

function piaAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function piaRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }
    if (stripos($dsn, 'karmotor_medisa') !== false) {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function piaBootstrap(PDO $root): PDO
{
    $database = 'pia_' . bin2hex(random_bytes(4));
    $root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $root->exec('USE `' . $database . '`');

    $dsn = (string) getenv('MEDISA_TEST_MYSQL_DSN');
    $pdo = new PDO(
        preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $dsn),
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $pdo->exec("
        CREATE TABLE personeller (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          ad VARCHAR(80) NOT NULL,
          soyad VARCHAR(80) NOT NULL,
          sicil_no VARCHAR(32) NOT NULL,
          ise_giris_tarihi DATE NOT NULL,
          aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          calisan_kapsami VARCHAR(32) NOT NULL DEFAULT 'IC_PERSONEL',
          sube_id INT UNSIGNED NULL,
          tc_kimlik_no CHAR(11) NULL,
          dogum_tarihi DATE NULL,
          telefon VARCHAR(32) NULL,
          departman_id INT UNSIGNED NULL,
          gorev_id INT UNSIGNED NULL,
          personel_tipi_id INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE surecler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          personel_id INT UNSIGNED NOT NULL,
          surec_turu VARCHAR(64) NOT NULL,
          alt_tur VARCHAR(64) NULL,
          baslangic_tarihi DATE NOT NULL,
          bitis_tarihi DATE NULL,
          ucretli_mi TINYINT(1) NOT NULL DEFAULT 0,
          tam_gun_mu TINYINT(1) NULL,
          ilk_iki_gun_firma_oder_mi TINYINT(1) NULL,
          aciklama TEXT NULL,
          state VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
          KEY idx_surecler_personel (personel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE arsiv_manifestleri (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          entity_type VARCHAR(64) NOT NULL,
          record_id INT UNSIGNED NOT NULL,
          personel_id INT UNSIGNED NULL,
          record_category VARCHAR(64) NOT NULL,
          source_version_identity VARCHAR(191) NOT NULL,
          trigger_type ENUM('PERIOD_CLOSURE', 'TERMINATION_DATE', 'TEST_FIXTURE_ARCHIVE') NOT NULL,
          trigger_date DATE NOT NULL,
          retention_until DATE NOT NULL,
          source_sha256 CHAR(64) NULL,
          integrity_status ENUM('OK', 'CHANGED', 'UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          created_by INT UNSIGNED NULL,
          UNIQUE KEY uq_arsiv_manifest_entity_cat_src (entity_type, record_id, record_category, source_version_identity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        INSERT INTO personeller (id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum)
        VALUES
          (1, 'Aktif', 'Personel', 'A1', '2024-01-15', 'AKTIF'),
          (2, 'Pasif', 'Personel', 'P1', '2023-01-01', 'PASIF'),
          (3, 'Rollback', 'Personel', 'R1', '2024-06-01', 'AKTIF')
    ");
    $pdo->exec("
        INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, bitis_tarihi, aciklama, state)
        VALUES (2, 'ISTEN_AYRILMA', '2025-12-01', '2025-12-01', 'onceki ayrilma', 'AKTIF')
    ");

    return $pdo;
}

$root = piaRootPdo();
$database = null;

try {
    $pdo = piaBootstrap($root);
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    // Invalid date deny
    $invalid = false;
    try {
        $pdo->beginTransaction();
        PersonelIstenAyrilmaService::applyInTransaction($pdo, 1, '2024-13-40', 'x', 1);
        $pdo->commit();
    } catch (PersonelValidationException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $invalid = $e->getField() === 'baslangic_tarihi';
    }
    piaAssert($invalid, 'invalid exit date denied');

    // Exit before hire deny
    $beforeHire = false;
    try {
        $pdo->beginTransaction();
        PersonelIstenAyrilmaService::applyInTransaction($pdo, 1, '2023-12-01', 'erken', 1);
        $pdo->commit();
    } catch (PersonelValidationException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $beforeHire = $e->getCodeString() === PersonelIstenAyrilmaService::ERROR_EXIT_BEFORE_HIRE;
    }
    piaAssert($beforeHire, 'exit before hire denied');
    piaAssert(
        (string) $pdo->query("SELECT aktif_durum FROM personeller WHERE id = 1")->fetchColumn() === 'AKTIF',
        'before-hire deny keeps AKTIF'
    );
    piaAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE personel_id = 1")->fetchColumn() === 0,
        'before-hire deny writes no surec'
    );

    // PASIF duplicate deny
    $pasifDeny = false;
    try {
        $pdo->beginTransaction();
        PersonelIstenAyrilmaService::applyInTransaction($pdo, 2, '2026-01-01', 'ikinci', 1);
        $pdo->commit();
    } catch (PersonelValidationException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pasifDeny = $e->getCodeString() === PersonelIstenAyrilmaService::ERROR_EXIT_NOT_AKTIF;
    }
    piaAssert($pasifDeny, 'PASIF personel second exit denied');
    piaAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE personel_id = 2 AND surec_turu = 'ISTEN_AYRILMA'")->fetchColumn() === 1,
        'history ISTEN_AYRILMA preserved on duplicate deny'
    );

    // Happy path: surec + PASIF + no user delete (users table absent / untouched)
    $pdo->beginTransaction();
    $applied = PersonelIstenAyrilmaService::applyInTransaction($pdo, 1, '2026-05-01', 'normal ayrilma', 9);
    $pdo->commit();
    piaAssert((int) ($applied['surec_id'] ?? 0) > 0, 'normal exit returns surec_id');
    piaAssert(
        (string) $pdo->query('SELECT aktif_durum FROM personeller WHERE id = 1')->fetchColumn() === 'PASIF',
        'normal exit sets PASIF'
    );
    $surec = $pdo->query('SELECT surec_turu, baslangic_tarihi, aciklama, state FROM surecler WHERE id = ' . (int) $applied['surec_id'])->fetch();
    piaAssert(is_array($surec) && ($surec['surec_turu'] ?? '') === 'ISTEN_AYRILMA', 'normal exit surec turu');
    piaAssert(($surec['baslangic_tarihi'] ?? '') === '2026-05-01', 'normal exit date stored');
    piaAssert(($surec['aciklama'] ?? '') === 'normal ayrilma', 'normal exit aciklama stored');
    piaAssert(($surec['state'] ?? '') === 'AKTIF', 'normal exit surec AKTIF');
    piaAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = 1')->fetchColumn() >= 1,
        'normal exit mints retention manifests'
    );

    // Duplicate active exit on now-PASIF
    $dupAfter = false;
    try {
        $pdo->beginTransaction();
        PersonelIstenAyrilmaService::applyInTransaction($pdo, 1, '2026-06-01', 'tekrar', 9);
        $pdo->commit();
    } catch (PersonelValidationException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $dupAfter = $e->getCodeString() === PersonelIstenAyrilmaService::ERROR_EXIT_NOT_AKTIF;
    }
    piaAssert($dupAfter, 'second exit after PASIF denied');
    piaAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE personel_id = 1 AND surec_turu = 'ISTEN_AYRILMA'")->fetchColumn() === 1,
        'no second ISTEN_AYRILMA row'
    );

    // Atomicity: missing retention host fails closed — drop manifests table mid-flight via invalid actor path
    // Simulate by terminating personel 3 then forcing SCHEMA_NOT_READY via dropping arsiv table in nested attempt.
    $pdo->exec('DROP TABLE arsiv_manifestleri');
    $rolled = false;
    try {
        $pdo->beginTransaction();
        PersonelIstenAyrilmaService::applyInTransaction($pdo, 3, '2026-07-01', 'schema fail', 1);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $rolled = true;
    }
    piaAssert($rolled, 'schema failure rolls back transaction');
    piaAssert(
        (string) $pdo->query('SELECT aktif_durum FROM personeller WHERE id = 3')->fetchColumn() === 'AKTIF',
        'atomic rollback keeps personel AKTIF'
    );
    piaAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM surecler WHERE personel_id = 3')->fetchColumn() === 0,
        'atomic rollback writes no orphan surec'
    );

    $controller = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/SureclerController.php');
    piaAssert(strpos($controller, 'PersonelIstenAyrilmaService::applyInTransaction') !== false, 'controller uses canonical exit owner');
    piaAssert(strpos($controller, 'function deactivatePersonel') === false, 'controller has no parallel deactivatePersonel');

    echo 'verify-personel-isten-ayrilma-mysql: OK' . PHP_EOL;
} finally {
    if ($database !== null) {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }
}
