<?php

declare(strict_types=1);

/**
 * Canonical HISTORICAL_EXIT_DATE_CORRECTION owner tests (safety-hardened).
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelHistoricalExitDateCorrectionService;
use Medisa\Api\Services\Personel\PersonelIstenAyrilmaService;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionClock;
use Medisa\Api\Services\Retention\RetentionPolicyService;

function hecAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function hecRootPdo(): PDO
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

function hecBootstrap(PDO $root): PDO
{
    $database = 'hec_' . bin2hex(random_bytes(4));
    $root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $root->exec('USE `' . $database . '`');

    $dsn = (string) getenv('MEDISA_TEST_MYSQL_DSN');
    if (stripos($dsn, 'dbname=') !== false) {
        $dsn = preg_replace('/dbname=[^;]*/i', 'dbname=' . $database, $dsn);
    } else {
        $dsn = rtrim($dsn, ';') . ';dbname=' . $database;
    }
    $pdo = new PDO(
        $dsn,
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
        CREATE TABLE personel_historical_exit_date_correction_auditleri (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          operation_type VARCHAR(64) NOT NULL,
          actor_user_id INT UNSIGNED NOT NULL,
          mutation_id VARCHAR(191) NULL,
          personel_id INT UNSIGNED NOT NULL,
          surec_id INT UNSIGNED NOT NULL,
          old_baslangic_tarihi DATE NOT NULL,
          old_bitis_tarihi DATE NOT NULL,
          new_baslangic_tarihi DATE NOT NULL,
          new_bitis_tarihi DATE NOT NULL,
          old_aciklama TEXT NULL,
          reason TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        INSERT INTO personeller (id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum)
        VALUES
          (1, 'Aktif', 'Kisi', 'A1', '2024-01-15', 'AKTIF'),
          (2, 'Pasif', 'YanlisTarih', 'P2', '2023-01-01', 'PASIF'),
          (3, 'Pasif', 'CiftSurec', 'P3', '2022-01-01', 'PASIF'),
          (4, 'Pasif', 'Rollback', 'P4', '2023-06-01', 'PASIF'),
          (5, 'Pasif', 'WrongBitis', 'P5', '2023-01-01', 'PASIF'),
          (6, 'Pasif', 'ThirdDate', 'P6', '2023-01-01', 'PASIF')
    ");
    $pdo->exec("
        INSERT INTO surecler (id, personel_id, surec_turu, baslangic_tarihi, bitis_tarihi, aciklama, state)
        VALUES
          (38, 2, 'ISTEN_AYRILMA', '2026-07-30', '2026-07-30', 'İşveren feshi', 'AKTIF'),
          (50, 3, 'ISTEN_AYRILMA', '2026-07-30', '2026-07-30', 'one', 'AKTIF'),
          (51, 3, 'ISTEN_AYRILMA', '2026-08-01', '2026-08-01', 'two', 'AKTIF'),
          (60, 4, 'ISTEN_AYRILMA', '2026-07-30', '2026-07-30', 'İşveren feshi', 'AKTIF'),
          (70, 5, 'ISTEN_AYRILMA', '2026-07-30', '2026-08-01', 'İşveren feshi', 'AKTIF'),
          (80, 6, 'ISTEN_AYRILMA', '2026-06-15', '2026-06-15', 'İşveren feshi', 'AKTIF')
    ");
    // Pre-seed wrong-date lifecycle manifests (immutable prior identities).
    $pdo->exec("
        INSERT INTO arsiv_manifestleri
          (entity_type, record_id, personel_id, record_category, source_version_identity,
           trigger_type, trigger_date, retention_until, source_sha256, integrity_status, created_by)
        VALUES
          ('personel', 2, 2, 'PERSONEL_OZLUK', 'personel:2:termination:2026-07-30',
           'TERMINATION_DATE', '2026-07-30', '2036-07-30', REPEAT('a', 64), 'OK', 1),
          ('personel', 2, 2, 'ISE_GIRIS_CIKIS', 'personel:2:ise_giris_cikis:termination:2026-07-30',
           'TERMINATION_DATE', '2026-07-30', '2036-07-30', REPEAT('b', 64), 'OK', 1)
    ");

    return $pdo;
}

$root = hecRootPdo();
$database = null;

try {
    $pdo = hecBootstrap($root);
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    // old baslangic + old bitis => READY
    $plan = PersonelHistoricalExitDateCorrectionService::plan(
        $pdo,
        2,
        38,
        '2026-07-30',
        '2025-12-31',
        'HR correction'
    );
    hecAssert(
        $plan['action'] === PersonelHistoricalExitDateCorrectionService::ACTION_CORRECT_HISTORICAL_SUREC,
        'old baslangic + old bitis => READY'
    );
    hecAssert($plan['no_change'] === false, 'plan is mutating');
    hecAssert($plan['surec_id'] === 38, 'plan preserves surec_id');
    hecAssert($plan['old_aciklama'] === 'İşveren feshi', 'plan captures original aciklama');
    hecAssert(
        ($plan['retention_reconciliation']['action'] ?? '') === 'REMINT_PERSONEL_LIFECYCLE_MANIFESTS',
        'plan retention remint'
    );
    hecAssert(
        (string) $pdo->query("SELECT baslangic_tarihi FROM surecler WHERE id = 38")->fetchColumn() === '2026-07-30',
        'plan does not mutate surec'
    );

    // old baslangic + wrong bitis => FAIL
    $wrongBitis = false;
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 5, 70, '2026-07-30', '2025-12-31', 'x');
    } catch (PersonelValidationException $e) {
        $wrongBitis = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_PREIMAGE_MISMATCH;
    }
    hecAssert($wrongBitis, 'old baslangic + wrong bitis => FAIL');

    // wrong baslangic + old bitis => FAIL (personel 5 has wrong bitis; use seeded mismatch via expected_old)
    $wrongBaslangic = false;
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 2, 38, '2026-08-01', '2025-12-31', 'x');
    } catch (PersonelValidationException $e) {
        $wrongBaslangic = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_PREIMAGE_MISMATCH;
    }
    hecAssert($wrongBaslangic, 'wrong baslangic + old bitis => FAIL');

    // Apply updates same surec ID; PASIF remains; original aciklama preserved; durable audit
    $pdo->beginTransaction();
    $applied = PersonelHistoricalExitDateCorrectionService::applyInTransaction(
        $pdo,
        2,
        38,
        '2026-07-30',
        '2025-12-31',
        'HR correction',
        99,
        'mg-historical-exit-date-correction-202'
    );
    $pdo->commit();
    hecAssert($applied['surec_id'] === 38, 'correction same surec id preserves');
    hecAssert($applied['already_applied'] === false, 'first apply not already_applied');
    hecAssert(
        (string) $pdo->query("SELECT aktif_durum FROM personeller WHERE id = 2")->fetchColumn() === 'PASIF',
        'PASIF remains PASIF'
    );
    hecAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM surecler WHERE personel_id = 2 AND surec_turu = 'ISTEN_AYRILMA' AND state <> 'IPTAL'")->fetchColumn() === 1,
        'no duplicate ISTEN_AYRILMA'
    );
    $surec = $pdo->query('SELECT * FROM surecler WHERE id = 38')->fetch(PDO::FETCH_ASSOC);
    hecAssert((string) $surec['baslangic_tarihi'] === '2025-12-31', 'baslangic corrected');
    hecAssert((string) $surec['bitis_tarihi'] === '2025-12-31', 'bitis corrected');
    hecAssert((string) $surec['aciklama'] === 'İşveren feshi', 'original İşveren feshi preserved');
    hecAssert(
        strpos((string) $surec['aciklama'], PersonelHistoricalExitDateCorrectionService::CORRECTION_ACIKLAMA_PREFIX) === false,
        'surec aciklama not overwritten with correction prefix'
    );

    $auditRow = $pdo->query(
        'SELECT * FROM personel_historical_exit_date_correction_auditleri WHERE surec_id = 38 ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    hecAssert(is_array($auditRow), 'durable audit persisted');
    hecAssert(
        (string) ($auditRow['operation_type'] ?? '') === 'HISTORICAL_EXIT_DATE_CORRECTION'
            && (string) ($auditRow['mutation_id'] ?? '') === 'mg-historical-exit-date-correction-202'
            && (int) ($auditRow['actor_user_id'] ?? 0) === 99
            && (string) ($auditRow['old_baslangic_tarihi'] ?? '') === '2026-07-30'
            && (string) ($auditRow['old_bitis_tarihi'] ?? '') === '2026-07-30'
            && (string) ($auditRow['new_baslangic_tarihi'] ?? '') === '2025-12-31'
            && (string) ($auditRow['new_bitis_tarihi'] ?? '') === '2025-12-31'
            && (string) ($auditRow['old_aciklama'] ?? '') === 'İşveren feshi',
        'audit contains mutation_id + actor + old/new + old_aciklama'
    );

    // Retention: corrected date current; old manifest immutable/non-current
    $term = RetentionPolicyService::resolveTerminationDate($pdo, 2);
    hecAssert($term === '2025-12-31', 'resolveTerminationDate = corrected HR date');
    $currentOzluk = ArchiveManifestService::findCurrentLifecycleManifest(
        $pdo,
        'personel',
        2,
        RetentionCategories::PERSONEL_OZLUK,
        ['personel_id' => 2]
    );
    hecAssert(is_array($currentOzluk), 'current lifecycle manifest exists');
    hecAssert(
        (string) ($currentOzluk['trigger_date'] ?? '') === '2025-12-31',
        'corrected lifecycle manifest becomes current'
    );
    hecAssert(
        (string) ($currentOzluk['retention_until'] ?? '') === '2035-12-31',
        'retention_until from corrected date'
    );
    hecAssert(
        (string) ($currentOzluk['source_version_identity'] ?? '') === 'personel:2:termination:2025-12-31',
        'current identity uses corrected date'
    );
    $oldStillThere = (int) $pdo->query(
        "SELECT COUNT(*) FROM arsiv_manifestleri
         WHERE personel_id = 2 AND source_version_identity = 'personel:2:termination:2026-07-30'"
    )->fetchColumn();
    hecAssert($oldStillThere === 1, 'old manifest remains immutable');
    hecAssert(
        (string) ($currentOzluk['source_version_identity'] ?? '') !== 'personel:2:termination:2026-07-30',
        'old manifest current olarak seçilmez'
    );

    // same logical retry (expected_old still wrong date, new = corrected) => ALREADY_APPLIED
    $pdo->beginTransaction();
    $again = PersonelHistoricalExitDateCorrectionService::applyInTransaction(
        $pdo,
        2,
        38,
        '2026-07-30',
        '2025-12-31',
        'HR correction retry',
        99,
        'mg-historical-exit-date-correction-202-retry'
    );
    $pdo->commit();
    hecAssert($again['already_applied'] === true, 'same logical retry => ALREADY_APPLIED');
    hecAssert($again['action'] === PersonelHistoricalExitDateCorrectionService::ACTION_ALREADY_APPLIED, 'retry action');
    hecAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_historical_exit_date_correction_auditleri WHERE surec_id = 38')->fetchColumn() === 1,
        'idempotent retry does not append second audit'
    );

    // arbitrary third date => FAIL
    $thirdDate = false;
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 6, 80, '2026-07-30', '2025-12-31', 'x');
    } catch (PersonelValidationException $e) {
        $thirdDate = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_PREIMAGE_MISMATCH;
    }
    hecAssert($thirdDate, 'arbitrary third date => FAIL');

    // Locked apply revalidates both fields (simulate race: mutate bitis under lock path via direct update then apply)
    $pdo->exec("UPDATE surecler SET bitis_tarihi = '2026-08-15' WHERE id = 60");
    $lockedMismatch = false;
    try {
        $pdo->beginTransaction();
        PersonelHistoricalExitDateCorrectionService::applyInTransaction(
            $pdo,
            4,
            60,
            '2026-07-30',
            '2025-12-31',
            'locked',
            99,
            'mg-locked-bitis'
        );
        $pdo->commit();
    } catch (PersonelValidationException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $lockedMismatch = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_PREIMAGE_MISMATCH;
    }
    hecAssert($lockedMismatch, 'locked apply both fields revalidates');
    $pdo->exec("UPDATE surecler SET bitis_tarihi = '2026-07-30' WHERE id = 60");

    // Transaction failure => surec + audit + retention rollback
    $auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM personel_historical_exit_date_correction_auditleri')->fetchColumn();
    $manifestBefore = (int) $pdo->query("SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = 4")->fetchColumn();
    $pdo->beginTransaction();
    try {
        PersonelHistoricalExitDateCorrectionService::applyInTransaction(
            $pdo,
            4,
            60,
            '2026-07-30',
            '2025-12-31',
            'will rollback',
            99,
            'mg-rollback'
        );
        throw new RuntimeException('force_rollback');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'force_rollback') {
            throw $e;
        }
        $pdo->rollBack();
    }
    hecAssert(
        (string) $pdo->query('SELECT baslangic_tarihi FROM surecler WHERE id = 60')->fetchColumn() === '2026-07-30',
        'rollback restores surec date'
    );
    hecAssert(
        (string) $pdo->query('SELECT aciklama FROM surecler WHERE id = 60')->fetchColumn() === 'İşveren feshi',
        'rollback keeps original aciklama'
    );
    hecAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_historical_exit_date_correction_auditleri')->fetchColumn() === $auditBefore,
        'transaction failure rolls back audit'
    );
    hecAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = 4")->fetchColumn() === $manifestBefore,
        'transaction failure rolls back retention remint'
    );

    // Wrong surec / personel / hire / future / multi / AKTIF
    $wrongSurec = false;
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 2, 999, '2025-12-31', '2025-11-01', 'x');
    } catch (PersonelValidationException $e) {
        $wrongSurec = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_SUREC_MISMATCH;
    }
    hecAssert($wrongSurec, 'wrong surec_id rejected');

    $wrongPersonel = false;
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 999, 38, '2025-12-31', '2025-11-01', 'x');
    } catch (PersonelValidationException $e) {
        $wrongPersonel = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_PERSONEL_NOT_FOUND;
    }
    hecAssert($wrongPersonel, 'wrong personel_id rejected');

    $beforeHire = false;
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 2, 38, '2025-12-31', '2022-01-01', 'x');
    } catch (PersonelValidationException $e) {
        $beforeHire = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_EXIT_BEFORE_HIRE;
    }
    hecAssert($beforeHire, 'exit before hire rejected');

    $future = false;
    $futureDate = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 2, 38, '2025-12-31', $futureDate, 'x');
    } catch (PersonelValidationException $e) {
        $future = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_EXIT_DATE_IN_FUTURE;
    }
    hecAssert($future, 'future date rejected');

    $multi = false;
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 3, 50, '2026-07-30', '2025-12-31', 'x');
    } catch (PersonelValidationException $e) {
        $multi = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_CONFLICT;
    }
    hecAssert($multi, 'second conflicting ISTEN_AYRILMA rejected');

    $aktifOnly = false;
    try {
        $pdo->beginTransaction();
        PersonelIstenAyrilmaService::applyInTransaction($pdo, 2, '2025-12-31', 'normal', 1);
        $pdo->commit();
    } catch (PersonelValidationException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $aktifOnly = $e->getCodeString() === PersonelIstenAyrilmaService::ERROR_EXIT_NOT_AKTIF;
    }
    hecAssert($aktifOnly, 'normal exit owner unchanged');

    $aktifDenied = false;
    try {
        PersonelHistoricalExitDateCorrectionService::plan($pdo, 1, 38, '2026-07-30', '2025-12-31', 'x');
    } catch (PersonelValidationException $e) {
        $aktifDenied = $e->getCodeString() === PersonelHistoricalExitDateCorrectionService::ERROR_NOT_PASIF;
    }
    hecAssert($aktifDenied, 'AKTIF target rejected by historical correction');

    hecAssert(class_exists(RetentionClock::class), 'retention clock available');

    echo "ALL_PASS PersonelHistoricalExitDateCorrectionMysqlTestRunner\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($database !== null) {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        } catch (Throwable $ignore) {
        }
    }
}
