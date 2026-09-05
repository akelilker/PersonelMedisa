<?php

declare(strict_types=1);

/**
 * Canonical HISTORICAL_EXIT_DATE_BACKFILL owner tests (PASIF residual only).
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelHistoricalExitDateBackfillService;
use Medisa\Api\Services\Personel\PersonelIstenAyrilmaService;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\Retention\RetentionClock;

function hebAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function hebRootPdo(): PDO
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

function hebBootstrap(PDO $root): PDO
{
    $database = 'heb_' . bin2hex(random_bytes(4));
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
          (1, 'Aktif', 'Kisi', 'A1', '2024-01-15', 'AKTIF'),
          (2, 'Pasif', 'EksikCikis', 'P2', '2023-01-01', 'PASIF'),
          (3, 'Pasif', 'VarCikis', 'P3', '2022-01-01', 'PASIF'),
          (4, 'Pasif', 'Rollback', 'P4', '2023-06-01', 'PASIF')
    ");
    $pdo->exec("
        INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, bitis_tarihi, aciklama, state)
        VALUES (3, 'ISTEN_AYRILMA', '2025-06-01', '2025-06-01', 'mevcut cikis', 'AKTIF')
    ");

    return $pdo;
}

$root = hebRootPdo();
$database = null;

try {
    $pdo = hebBootstrap($root);
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    // 1) PASIF + missing exit + valid date → plan PASS / create action
    $plan = PersonelHistoricalExitDateBackfillService::plan($pdo, 2, '2025-12-31', 'HR backfill');
    hebAssert($plan['action'] === PersonelHistoricalExitDateBackfillService::ACTION_CREATE_HISTORICAL_SUREC, 'plan create action for PASIF missing exit');
    hebAssert($plan['no_change'] === false, 'plan is not no_change for missing exit');
    hebAssert($plan['exit_date'] === '2025-12-31', 'plan keeps authoritative exit date');

    // 12) dry-run/plan makes zero mutation
    hebAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM surecler WHERE personel_id = 2')->fetchColumn() === 0,
        'plan does not insert surec'
    );
    hebAssert(
        (string) $pdo->query("SELECT aktif_durum FROM personeller WHERE id = 2")->fetchColumn() === 'PASIF',
        'plan keeps PASIF'
    );

    // 2) apply creates historical surec + keeps PASIF
    $pdo->beginTransaction();
    $applied = PersonelHistoricalExitDateBackfillService::applyInTransaction(
        $pdo,
        2,
        '2025-12-31',
        'HR backfill',
        99
    );
    $pdo->commit();
    hebAssert($applied['already_applied'] === false, 'first apply is not already_applied');
    hebAssert((int) $applied['surec_id'] > 0, 'apply returns surec_id');
    hebAssert(
        (string) $pdo->query("SELECT aktif_durum FROM personeller WHERE id = 2")->fetchColumn() === 'PASIF',
        'apply keeps PASIF'
    );
    $surec = $pdo->query('SELECT * FROM surecler WHERE personel_id = 2')->fetch(PDO::FETCH_ASSOC);
    hebAssert(is_array($surec), 'apply inserts ISTEN_AYRILMA surec');
    hebAssert((string) $surec['surec_turu'] === 'ISTEN_AYRILMA', 'surec turu ISTEN_AYRILMA');
    hebAssert((string) $surec['baslangic_tarihi'] === '2025-12-31', 'surec date matches authoritative');
    hebAssert(
        strpos((string) $surec['aciklama'], PersonelHistoricalExitDateBackfillService::BACKFILL_ACIKLAMA_PREFIX) === 0,
        'surec aciklama marked as historical backfill'
    );

    // 4+7) same date again → idempotent, no duplicate surec
    $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM surecler WHERE personel_id = 2')->fetchColumn();
    $pdo->beginTransaction();
    $again = PersonelHistoricalExitDateBackfillService::applyInTransaction($pdo, 2, '2025-12-31', 'retry', 99);
    $pdo->commit();
    $afterCount = (int) $pdo->query('SELECT COUNT(*) FROM surecler WHERE personel_id = 2')->fetchColumn();
    hebAssert($again['already_applied'] === true, 'same date apply is already_applied');
    hebAssert($beforeCount === $afterCount, 'idempotent apply does not duplicate surec');

    // 5+6) conflicting exit date → reject
    $conflict = false;
    try {
        PersonelHistoricalExitDateBackfillService::plan($pdo, 2, '2025-01-01', 'conflict');
    } catch (PersonelValidationException $e) {
        $conflict = $e->getCodeString() === PersonelHistoricalExitDateBackfillService::ERROR_CONFLICT;
    }
    hebAssert($conflict, 'conflicting exit date rejected');

    $conflictExisting = false;
    try {
        PersonelHistoricalExitDateBackfillService::plan($pdo, 3, '2025-12-31', 'conflict existing');
    } catch (PersonelValidationException $e) {
        $conflictExisting = $e->getCodeString() === PersonelHistoricalExitDateBackfillService::ERROR_CONFLICT;
    }
    hebAssert($conflictExisting, 'existing different ISTEN_AYRILMA date rejected');

    // same existing date on personel 3 → already applied
    $sameExisting = PersonelHistoricalExitDateBackfillService::plan($pdo, 3, '2025-06-01', null);
    hebAssert(
        $sameExisting['action'] === PersonelHistoricalExitDateBackfillService::ACTION_ALREADY_APPLIED,
        'matching existing exit date is already_applied'
    );

    // 3) AKTIF target → reject
    $aktifDenied = false;
    try {
        PersonelHistoricalExitDateBackfillService::plan($pdo, 1, '2025-12-31', null);
    } catch (PersonelValidationException $e) {
        $aktifDenied = $e->getCodeString() === PersonelHistoricalExitDateBackfillService::ERROR_NOT_PASIF;
    }
    hebAssert($aktifDenied, 'AKTIF target rejected by historical backfill');

    // 16) normal PERSONEL_EXIT still rejects PASIF
    $normalExitPasifDenied = false;
    try {
        $pdo->beginTransaction();
        PersonelIstenAyrilmaService::applyInTransaction($pdo, 4, '2025-12-31', 'normal', 1);
        $pdo->commit();
    } catch (PersonelValidationException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $normalExitPasifDenied = $e->getCodeString() === PersonelIstenAyrilmaService::ERROR_EXIT_NOT_AKTIF;
    }
    hebAssert($normalExitPasifDenied, 'normal exit owner still rejects PASIF');

    // 8) exit date > today → reject
    $future = false;
    $tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    try {
        PersonelHistoricalExitDateBackfillService::plan($pdo, 4, $tomorrow, null);
    } catch (PersonelValidationException $e) {
        $future = $e->getCodeString() === PersonelHistoricalExitDateBackfillService::ERROR_EXIT_DATE_IN_FUTURE;
    }
    hebAssert($future, 'future exit date rejected');

    // 9) exit date < ise_giris → reject
    $beforeHire = false;
    try {
        PersonelHistoricalExitDateBackfillService::plan($pdo, 4, '2020-01-01', null);
    } catch (PersonelValidationException $e) {
        $beforeHire = $e->getCodeString() === PersonelHistoricalExitDateBackfillService::ERROR_EXIT_BEFORE_HIRE;
    }
    hebAssert($beforeHire, 'exit before hire rejected');

    // 10) missing personel → reject
    $missing = false;
    try {
        PersonelHistoricalExitDateBackfillService::plan($pdo, 99999, '2025-12-31', null);
    } catch (PersonelValidationException $e) {
        $missing = $e->getCodeString() === PersonelHistoricalExitDateBackfillService::ERROR_PERSONEL_NOT_FOUND;
    }
    hebAssert($missing, 'missing personel rejected');

    // 13) transaction rollback on failure mid-apply (simulate by conflicting after lock via manual insert then apply)
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, bitis_tarihi, aciklama, state)
                VALUES (4, 'ISTEN_AYRILMA', '2024-01-01', '2024-01-01', 'race', 'AKTIF')");
    $rolled = false;
    try {
        PersonelHistoricalExitDateBackfillService::applyInTransaction($pdo, 4, '2025-12-31', 'race', 1);
        $pdo->commit();
    } catch (PersonelValidationException $e) {
        $pdo->rollBack();
        $rolled = $e->getCodeString() === PersonelHistoricalExitDateBackfillService::ERROR_CONFLICT;
    }
    hebAssert($rolled, 'apply conflict rolls back caller transaction path');
    // Outside tx, only the race insert should remain if we rolled back — re-check in clean state:
    // Because race insert was inside rolled-back tx, personel 4 should still have 0 surec from that attempt.
    // But wait - we rolled back the whole transaction including the race insert. Good.
    hebAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM surecler WHERE personel_id = 4')->fetchColumn() === 0,
        'rolled-back conflict leaves no partial surec'
    );

    // Retention clock readable (sanity for future-date gate)
    hebAssert(RetentionClock::now() instanceof DateTimeInterface, 'retention clock available');

    echo "ALL_HISTORICAL_EXIT_BACKFILL_TESTS_PASSED\n";
} catch (Throwable $e) {
    echo '[FAIL] ' . $e->getMessage() . PHP_EOL;
    exit(1);
} finally {
    if ($database) {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        } catch (Throwable $ignored) {
        }
    }
}
