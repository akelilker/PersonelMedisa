<?php

declare(strict_types=1);

/**
 * Canonical yeniden aktif (PASIF -> AKTIF) owner MySQL acceptance:
 * exactly-one-open-exit, legal hold, test fixture, DIS_KAYNAK -> IC_PERSONEL
 * identity + SGK consistency, IPTAL (never DELETE) cancellation, append-only
 * audit/manifest evidence, transaction atomicity and fail-closed schema states.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Personel\PersonelSgkCompanyConsistency;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\Personel\PersonelYenidenAktifService;
use Medisa\Api\Services\Retention\PersonelArchiveGate;
use Medisa\Api\Services\Retention\RetentionPolicyService;

function pyaAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function pyaRootPdo(): PDO
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

function pyaBootstrap(PDO $root): PDO
{
    $database = 'pya_' . bin2hex(random_bytes(4));
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

    // --- organisation hierarchy (canonical schema owners: 040/065 shape) ---
    $pdo->exec("CREATE TABLE users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL,
        rol VARCHAR(32) NOT NULL DEFAULT 'PERSONEL',
        UNIQUE KEY uq_users_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE sirketler (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kod VARCHAR(32) NOT NULL,
        ad VARCHAR(160) NOT NULL,
        UNIQUE KEY uq_sirketler_kod (kod)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE sgk_isverenler (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kod VARCHAR(32) NOT NULL,
        ad VARCHAR(160) NOT NULL,
        sirket_id INT UNSIGNED NULL,
        durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
        UNIQUE KEY uq_sgk_isverenler_kod (kod),
        CONSTRAINT fk_sgk_isverenler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE subeler (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kod VARCHAR(32) NOT NULL,
        ad VARCHAR(120) NOT NULL,
        sgk_isveren_id INT UNSIGNED NULL,
        sirket_id INT UNSIGNED NULL,
        durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
        UNIQUE KEY uq_subeler_kod (kod),
        CONSTRAINT fk_subeler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id),
        CONSTRAINT fk_subeler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE calisma_lokasyonlari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kod VARCHAR(32) NOT NULL,
        ad VARCHAR(160) NOT NULL,
        sube_id INT UNSIGNED NULL,
        UNIQUE KEY uq_calisma_lokasyonlari_kod (kod),
        CONSTRAINT fk_calisma_lokasyonlari_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE user_subeler (
        user_id INT UNSIGNED NOT NULL,
        sube_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, sube_id),
        CONSTRAINT fk_user_subeler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_user_subeler_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE user_sirketler (
        user_id INT UNSIGNED NOT NULL,
        sirket_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, sirket_id),
        CONSTRAINT fk_user_sirketler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_user_sirketler_sirket FOREIGN KEY (sirket_id) REFERENCES sirketler (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE user_sgk_isverenler (
        user_id INT UNSIGNED NOT NULL,
        sgk_isveren_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, sgk_isveren_id),
        CONSTRAINT fk_user_sgk_isverenler_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
        CONSTRAINT fk_user_sgk_isverenler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // --- personel + lifecycle surfaces ---
    $pdo->exec("CREATE TABLE personeller (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        ad VARCHAR(80) NOT NULL,
        soyad VARCHAR(80) NULL,
        sicil_no VARCHAR(32) NOT NULL,
        ise_giris_tarihi DATE NOT NULL,
        aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
        calisan_kapsami VARCHAR(32) NOT NULL DEFAULT 'IC_PERSONEL',
        sube_id INT UNSIGNED NULL,
        sgk_isveren_id INT UNSIGNED NULL,
        calisma_lokasyonu_id INT UNSIGNED NULL,
        tc_kimlik_no CHAR(11) NULL,
        dogum_tarihi DATE NULL,
        telefon VARCHAR(32) NULL,
        departman_id INT UNSIGNED NULL,
        gorev_id INT UNSIGNED NULL,
        personel_tipi_id INT UNSIGNED NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE surecler (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        personel_id INT UNSIGNED NOT NULL,
        surec_turu VARCHAR(64) NOT NULL,
        alt_tur VARCHAR(64) NULL,
        baslangic_tarihi DATE NOT NULL,
        bitis_tarihi DATE NULL,
        aciklama TEXT NULL,
        state VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
        KEY idx_surecler_personel (personel_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE arsiv_manifestleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(64) NOT NULL,
        record_id INT UNSIGNED NOT NULL,
        personel_id INT UNSIGNED NULL,
        record_category VARCHAR(64) NOT NULL,
        source_version_identity VARCHAR(191) NOT NULL,
        trigger_type ENUM('PERIOD_CLOSURE','TERMINATION_DATE','TEST_FIXTURE_ARCHIVE') NOT NULL,
        trigger_date DATE NOT NULL,
        retention_until DATE NOT NULL,
        source_sha256 CHAR(64) NULL,
        integrity_status ENUM('OK','CHANGED','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        created_by INT UNSIGNED NULL,
        UNIQUE KEY uq_arsiv_manifest_entity_cat_src (entity_type, record_id, record_category, source_version_identity)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE legal_holdlar (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        target_domain VARCHAR(64) NOT NULL,
        target_category VARCHAR(64) NULL,
        target_record_id INT UNSIGNED NULL,
        personel_id INT UNSIGNED NULL,
        reason TEXT NOT NULL,
        hold_state ENUM('ACTIVE','RELEASED') NOT NULL DEFAULT 'ACTIVE',
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        released_by INT UNSIGNED NULL,
        released_at TIMESTAMP NULL DEFAULT NULL,
        release_reason TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE legal_hold_auditleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        legal_hold_id INT UNSIGNED NOT NULL,
        action VARCHAR(64) NOT NULL,
        actor_user_id INT UNSIGNED NOT NULL,
        reason TEXT NULL,
        metadata_json JSON NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE personel_test_fixture_siniflandirmalari (
        personel_id INT UNSIGNED NOT NULL PRIMARY KEY,
        sinif ENUM('TEST_FIXTURE') NOT NULL,
        evidence_kodu VARCHAR(64) NOT NULL,
        evidence_ref VARCHAR(191) NULL,
        state ENUM('AKTIF','IPTAL') NOT NULL DEFAULT 'AKTIF',
        classified_by INT UNSIGNED NULL,
        classified_at DATETIME(3) NOT NULL,
        aciklama VARCHAR(255) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 083 canonical append-only personnel change audit (verbatim owner shape).
    $pdo->exec("CREATE TABLE personel_organizasyon_degisiklik_auditleri (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        personel_id INT UNSIGNED NOT NULL,
        olay_tipi VARCHAR(64) NOT NULL,
        degisen_alanlar JSON NOT NULL,
        eski_degerler JSON NOT NULL,
        yeni_degerler JSON NOT NULL,
        gerekce VARCHAR(500) NOT NULL,
        actor_user_id INT UNSIGNED NOT NULL,
        request_hash CHAR(64) NOT NULL,
        idempotency_key VARCHAR(128) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_poda_personel_created (personel_id, created_at),
        CONSTRAINT fk_poda_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
        CONSTRAINT fk_poda_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TRIGGER trg_poda_no_update BEFORE UPDATE ON personel_organizasyon_degisiklik_auditleri
        FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only'");
    $pdo->exec("CREATE TRIGGER trg_poda_no_delete BEFORE DELETE ON personel_organizasyon_degisiklik_auditleri
        FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'append-only'");

    $pdo->exec("INSERT INTO users (id, username, rol) VALUES (100, 'gy', 'GENEL_YONETICI')");
    $pdo->exec("INSERT INTO sirketler (id, kod, ad) VALUES (1, 'MEDISA', 'Medisa'), (2, 'DIGER', 'Diger')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, sirket_id) VALUES
        (11, 'SGK-A', 'SGK Isveren A', 1), (12, 'SGK-B', 'SGK Isveren B', 2)");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id, sirket_id, durum) VALUES
        (21, 'SUB-A', 'Sube A', 11, 1, 'AKTIF'), (22, 'SUB-B', 'Sube B', 12, 2, 'AKTIF')");
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, sube_id) VALUES (31, 'LOK-A', 'Lokasyon A', 21)");

    // 1 happy path, 2 already AKTIF, 3 no open exit, 4 two open exits,
    // 5 legal hold, 6 test fixture, 7 DIS->IC with no SGK,
    // 8 DIS->IC complete identity with empty telefon, 9 SGK company mismatch,
    // 10 manifest preservation, 11 short gerekce, 12 audit actor FK failure,
    // 13 DIS->IC incomplete identity (missing TC), 14 DIS->IC missing dogum
    // (payload may fill atomically; telefon empty must not mask required fields),
    // 15 DIS->IC populated dogum rejects a differing payload.
    $pdo->exec("INSERT INTO personeller
        (id, ad, soyad, sicil_no, ise_giris_tarihi, aktif_durum, calisan_kapsami, sube_id, sgk_isveren_id,
         tc_kimlik_no, dogum_tarihi, telefon)
        VALUES
        (1, 'Gorkem', 'Vural', 'G1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (2, 'Zaten', 'Aktif', 'Z1', '2022-01-10', 'AKTIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (3, 'Surecsiz', 'Kayit', 'S1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (4, 'CiftSurec', 'Kayit', 'C1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (5, 'Hukuki', 'Hold', 'H1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (6, 'Test', 'Fixture', 'T1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (7, 'DisKaynak', 'SgkSiz', 'D1', '2022-01-10', 'PASIF', 'DIS_KAYNAK', 21, NULL, '10000000146', '1990-05-05', '5551112233'),
        (8, 'DisKaynak', 'TamKimlik', 'D2', '2022-01-10', 'PASIF', 'DIS_KAYNAK', 21, 11, '10000000146', '1990-05-05', NULL),
        (9, 'Sgk', 'Uyusmaz', 'U1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 12, '10000000146', '1990-05-05', '5551112233'),
        (10, 'Manifest', 'Korunum', 'M1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (11, 'Gerekce', 'Kisa', 'K1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (12, 'Atomik', 'Rollback', 'A1', '2022-01-10', 'PASIF', 'IC_PERSONEL', 21, 11, '10000000146', '1990-05-05', '5551112233'),
        (13, 'DisKaynak', 'EksikKimlik', 'D3', '2022-01-10', 'PASIF', 'DIS_KAYNAK', 21, 11, NULL, NULL, NULL),
        (14, 'DisKaynak', 'EksikDogum', 'D4', '2022-01-10', 'PASIF', 'DIS_KAYNAK', 21, 11, '10000000146', NULL, NULL),
        (15, 'DisKaynak', 'DogumCakisma', 'D5', '2022-01-10', 'PASIF', 'DIS_KAYNAK', 21, 11, '10000000146', '1991-08-12', NULL)
    ");

    $pdo->exec("INSERT INTO surecler (personel_id, surec_turu, baslangic_tarihi, aciklama, state) VALUES
        (1, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (2, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (4, 'ISTEN_AYRILMA', '2026-01-15', 'ilk', 'AKTIF'),
        (4, 'ISTEN_AYRILMA', '2026-02-15', 'ikinci', 'AKTIF'),
        (5, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (6, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (7, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (8, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (9, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (10, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (11, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (12, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (13, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (14, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF'),
        (15, 'ISTEN_AYRILMA', '2026-01-15', 'ayrilma', 'AKTIF')
    ");

    // Active legal hold on personel 5 only (target_record_id keeps it record-scoped;
    // a NULL target_record_id would be a domain-wide hold applying to every personel).
    $pdo->exec("INSERT INTO legal_holdlar (target_domain, target_category, target_record_id, personel_id, reason, hold_state, created_by)
        VALUES ('personel', NULL, 5, 5, 'dava', 'ACTIVE', 100)");

    // Active TEST_FIXTURE classification on personel 6.
    $pdo->exec("INSERT INTO personel_test_fixture_siniflandirmalari
        (personel_id, sinif, evidence_kodu, state, classified_by, classified_at)
        VALUES (6, 'TEST_FIXTURE', 'OPS_HIDDEN', 'AKTIF', 100, NOW(3))");

    // Pre-existing (unrelated) archive evidence that reactivation must not touch.
    $pdo->exec("INSERT INTO arsiv_manifestleri
        (entity_type, record_id, personel_id, record_category, source_version_identity, trigger_type,
         trigger_date, retention_until, source_sha256, integrity_status, created_by)
        VALUES ('personel', 10, 10, 'PERSONEL_OZLUK', 'legacy:baseline:10', 'PERIOD_CLOSURE',
                '2025-12-31', '2035-12-31', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'OK', 100)");

    return $pdo;
}

/** @param array<string, mixed> $body */
function pyaReactivate(PDO $pdo, int $personelId, array $body, int $actorId = 100): array
{
    $request = new Request();
    $context = new OrganizasyonAuditContext($actorId, str_repeat('a', 64));

    $pdo->beginTransaction();
    try {
        $result = PersonelYenidenAktifService::applyInTransaction($pdo, pyaUser(), $request, $personelId, $body, $context);
        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** @return array<string, mixed> */
function pyaUser(): array
{
    return ['id' => 100, 'rol' => 'GENEL_YONETICI', 'username' => 'gy', 'sube_ids' => []];
}

function pyaReject(PDO $pdo, int $personelId, array $body, string $expectedCode): void
{
    $actual = 'NO_ERROR';
    try {
        pyaReactivate($pdo, $personelId, $body);
    } catch (OrganizasyonException $e) {
        $actual = (string) $e->errorCode;
    } catch (PersonelValidationException $e) {
        $actual = (string) $e->getCodeString();
    }

    pyaAssert(
        $actual === $expectedCode,
        'reject personel ' . $personelId . ' with ' . $expectedCode . ' (got ' . $actual . ')'
    );
}

function pyaCount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function pyaScalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function pyaStatus(PDO $pdo, int $personelId): string
{
    return (string) pyaScalar($pdo, 'SELECT aktif_durum FROM personeller WHERE id = :id', ['id' => $personelId]);
}

function pyaSurecIds(PDO $pdo, int $personelId): array
{
    $stmt = $pdo->prepare("SELECT id FROM surecler WHERE personel_id = :id AND surec_turu = 'ISTEN_AYRILMA'");
    $stmt->execute(['id' => $personelId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function pyaSurecStates(PDO $pdo, int $personelId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, state FROM surecler WHERE personel_id = :id AND surec_turu = 'ISTEN_AYRILMA' ORDER BY id"
    );
    $stmt->execute(['id' => $personelId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Code-only source (comments stripped) so docblock prose cannot satisfy a contract check. */
function pyaCodeOnly(string $path): string
{
    $code = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $code .= $token[1];
            continue;
        }
        $code .= $token;
    }

    return $code;
}

$root = pyaRootPdo();
$database = null;
$failure = null;

try {
    $pdo = pyaBootstrap($root);
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

    // ---------------------------------------------------------------------
    // 0) Transaction ownership is mandatory.
    // ---------------------------------------------------------------------
    $needsTransaction = false;
    try {
        PersonelYenidenAktifService::applyInTransaction(
            $pdo,
            pyaUser(),
            new Request(),
            1,
            ['gerekce' => 'transaction disi cagri'],
            new OrganizasyonAuditContext(100, str_repeat('a', 64))
        );
    } catch (LogicException $e) {
        $needsTransaction = true;
    }
    pyaAssert($needsTransaction, 'reactivation requires an active transaction');
    pyaAssert(pyaStatus($pdo, 1) === 'PASIF', 'transaction guard leaves personel PASIF');

    // ---------------------------------------------------------------------
    // 1) PASIF -> AKTIF happy path (canonical exit surec cancelled in place).
    // ---------------------------------------------------------------------
    $exitId = pyaSurecIds($pdo, 1)[0];
    $result = pyaReactivate($pdo, 1, ['gerekce' => 'isletme karari ile yeniden aktif']);

    pyaAssert(($result['replay'] ?? true) === false, 'happy path is not a replay');
    pyaAssert(($result['already_active'] ?? true) === false, 'happy path is not already-active');
    pyaAssert(($result['onceki_aktif_durum'] ?? '') === 'PASIF', 'happy path reports previous PASIF');
    pyaAssert(($result['aktif_durum'] ?? '') === 'AKTIF', 'happy path reports AKTIF');
    pyaAssert((int) ($result['iptal_edilen_surec_id'] ?? 0) === $exitId, 'happy path reports cancelled exit surec');
    pyaAssert((int) ($result['audit_id'] ?? 0) > 0, 'happy path returns an audit id');
    pyaAssert(count($result['lifecycle_manifest_ids'] ?? []) >= 2, 'happy path mints lifecycle manifests');
    pyaAssert(pyaStatus($pdo, 1) === 'AKTIF', 'happy path flips aktif_durum to AKTIF');

    // Exit surec is preserved and cancelled, never deleted.
    pyaAssert(
        pyaCount($pdo, "SELECT COUNT(*) FROM surecler WHERE id = :id AND personel_id = 1", ['id' => $exitId]) === 1,
        'exit surec row is preserved (not deleted)'
    );
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT state FROM surecler WHERE id = :id', ['id' => $exitId]) === 'IPTAL',
        'exit surec state becomes IPTAL'
    );

    $audit = $pdo->query(
        'SELECT olay_tipi, degisen_alanlar, eski_degerler, yeni_degerler, gerekce, actor_user_id
         FROM personel_organizasyon_degisiklik_auditleri WHERE personel_id = 1 ORDER BY id DESC LIMIT 1'
    )->fetch();
    pyaAssert(is_array($audit), 'reactivation writes an append-only audit row');
    pyaAssert(($audit['olay_tipi'] ?? '') === PersonelYenidenAktifService::OPERATION_TYPE, 'audit olay_tipi');
    pyaAssert((int) ($audit['actor_user_id'] ?? 0) === 100, 'audit actor recorded');
    $changed = json_decode((string) $audit['degisen_alanlar'], true);
    pyaAssert(in_array('aktif_durum', is_array($changed) ? $changed : [], true), 'audit degisen_alanlar includes aktif_durum');
    $before = json_decode((string) $audit['eski_degerler'], true);
    $after = json_decode((string) $audit['yeni_degerler'], true);
    pyaAssert(($before['aktif_durum'] ?? '') === 'PASIF', 'audit preimage keeps the old value (PASIF)');
    pyaAssert(($after['aktif_durum'] ?? '') === 'AKTIF', 'audit postimage holds the new value (AKTIF)');

    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = 1') >= 2,
        'happy path persists lifecycle manifests'
    );
    pyaAssert(
        pyaCount(
            $pdo,
            "SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = 1 AND trigger_type = 'TERMINATION_DATE'"
        ) >= 2,
        'lifecycle manifests use the TERMINATION_DATE trigger'
    );

    // The audit owner is structurally append-only (real MySQL trigger evidence).
    $auditUpdateBlocked = false;
    try {
        $pdo->exec('UPDATE personel_organizasyon_degisiklik_auditleri SET gerekce = "x" WHERE personel_id = 1');
    } catch (Throwable $e) {
        $auditUpdateBlocked = true;
    }
    pyaAssert($auditUpdateBlocked, 'audit rows cannot be updated (append-only trigger)');

    $auditDeleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM personel_organizasyon_degisiklik_auditleri WHERE personel_id = 1');
    } catch (Throwable $e) {
        $auditDeleteBlocked = true;
    }
    pyaAssert($auditDeleteBlocked, 'audit rows cannot be deleted (append-only trigger)');

    // ---------------------------------------------------------------------
    // 2) Already AKTIF is an explicit no-op replay.
    // ---------------------------------------------------------------------
    $auditBeforeReplay = pyaCount($pdo, 'SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri');
    $manifestBeforeReplay = pyaCount($pdo, 'SELECT COUNT(*) FROM arsiv_manifestleri');
    $replay = pyaReactivate($pdo, 2, ['gerekce' => 'tekrar cagri']);

    pyaAssert(($replay['replay'] ?? false) === true, 'already AKTIF reports replay');
    pyaAssert(($replay['already_active'] ?? false) === true, 'already AKTIF reports already_active');
    pyaAssert(($replay['audit_id'] ?? null) === null, 'already AKTIF writes no audit');
    pyaAssert(($replay['lifecycle_manifest_ids'] ?? null) === [], 'already AKTIF mints no manifest');
    pyaAssert(pyaStatus($pdo, 2) === 'AKTIF', 'already AKTIF stays AKTIF');
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri') === $auditBeforeReplay,
        'already AKTIF appended no audit row'
    );
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM arsiv_manifestleri') === $manifestBeforeReplay,
        'already AKTIF appended no manifest'
    );
    $replaySurecStates = pyaSurecStates($pdo, 2);
    pyaAssert(
        count($replaySurecStates) === 1 && (string) $replaySurecStates[0]['state'] === 'AKTIF',
        'already AKTIF leaves the historical exit surec untouched'
    );

    // ---------------------------------------------------------------------
    // 3) Exactly one open ISTEN_AYRILMA is mandatory.
    // ---------------------------------------------------------------------
    pyaReject($pdo, 3, ['gerekce' => 'surecsiz kayit'], PersonelYenidenAktifService::ERROR_EXIT_SUREC_MISSING);
    pyaAssert(pyaStatus($pdo, 3) === 'PASIF', 'missing-exit rejection keeps PASIF');
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri WHERE personel_id = 3') === 0,
        'missing-exit rejection writes no audit'
    );

    pyaReject($pdo, 4, ['gerekce' => 'cift surec'], PersonelYenidenAktifService::ERROR_EXIT_SUREC_NOT_UNIQUE);
    pyaAssert(pyaStatus($pdo, 4) === 'PASIF', 'ambiguous-exit rejection keeps PASIF');
    foreach (pyaSurecStates($pdo, 4) as $row) {
        pyaAssert((string) $row['state'] === 'AKTIF', 'ambiguous-exit rejection cancels no surec (' . $row['id'] . ')');
    }

    // ---------------------------------------------------------------------
    // 4) Fail-closed guards: legal hold and TEST_FIXTURE classification.
    // ---------------------------------------------------------------------
    pyaReject($pdo, 5, ['gerekce' => 'legal hold var'], PersonelYenidenAktifService::ERROR_LEGAL_HOLD);
    pyaAssert(pyaStatus($pdo, 5) === 'PASIF', 'legal hold rejection keeps PASIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT state FROM surecler WHERE personel_id = 5') === 'AKTIF',
        'legal hold rejection leaves the exit surec open'
    );

    pyaReject($pdo, 6, ['gerekce' => 'test fixture kayit'], PersonelYenidenAktifService::ERROR_TEST_FIXTURE_HIDDEN);
    pyaAssert(pyaStatus($pdo, 6) === 'PASIF', 'test fixture rejection keeps PASIF');

    // ---------------------------------------------------------------------
    // 5) DIS_KAYNAK -> IC_PERSONEL identity + SGK requirements.
    // ---------------------------------------------------------------------
    pyaReject($pdo, 7, ['gerekce' => 'ic personel gecisi', 'calisan_kapsami' => 'IC_PERSONEL'], PersonelSgkCompanyConsistency::ERROR_REQUIRED);
    pyaAssert(pyaStatus($pdo, 7) === 'PASIF', 'SGK-required rejection keeps PASIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT calisan_kapsami FROM personeller WHERE id = 7') === 'DIS_KAYNAK',
        'SGK-required rejection rolls the kapsam axis back'
    );

    $incomplete = 'NO_ERROR';
    try {
        pyaReactivate($pdo, 13, ['gerekce' => 'eksik kimlik', 'calisan_kapsami' => 'IC_PERSONEL']);
    } catch (PersonelValidationException $e) {
        $incomplete = (string) $e->getField();
    } catch (OrganizasyonException $e) {
        $incomplete = (string) $e->field;
    }
    pyaAssert($incomplete === 'tc_kimlik_no', 'DIS -> IC rejects incomplete internal identity');
    pyaAssert(pyaStatus($pdo, 13) === 'PASIF', 'incomplete-identity rejection keeps PASIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT calisan_kapsami FROM personeller WHERE id = 13') === 'DIS_KAYNAK',
        'incomplete-identity rejection keeps DIS_KAYNAK'
    );

    $missingDogum = 'NO_ERROR';
    try {
        pyaReactivate($pdo, 14, ['gerekce' => 'eksik dogum', 'calisan_kapsami' => 'IC_PERSONEL']);
    } catch (PersonelValidationException $e) {
        $missingDogum = (string) $e->getField();
    } catch (OrganizasyonException $e) {
        $missingDogum = (string) $e->field;
    }
    pyaAssert($missingDogum === 'dogum_tarihi', 'DIS -> IC still requires dogum_tarihi when telefon is empty');
    pyaAssert(pyaStatus($pdo, 14) === 'PASIF', 'missing-dogum rejection keeps PASIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT calisan_kapsami FROM personeller WHERE id = 14') === 'DIS_KAYNAK',
        'missing-dogum rejection keeps DIS_KAYNAK'
    );
    pyaAssert(
        pyaScalar($pdo, 'SELECT telefon FROM personeller WHERE id = 14') === null,
        'missing-dogum rejection does not invent a telefon value'
    );
    pyaAssert(
        pyaScalar($pdo, 'SELECT dogum_tarihi FROM personeller WHERE id = 14') === null,
        'missing-dogum rejection leaves dogum_tarihi null'
    );

    // Synthetic fixture date only — never a live personnel document value.
    $payloadDogum = '1987-04-19';
    $filledDogum = pyaReactivate($pdo, 14, [
        'gerekce' => 'eksik dogum payload ile tamamlandi',
        'calisan_kapsami' => 'IC_PERSONEL',
        'dogum_tarihi' => $payloadDogum,
    ]);
    pyaAssert(pyaStatus($pdo, 14) === 'AKTIF', 'empty dogum + payload fills and activates');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT calisan_kapsami FROM personeller WHERE id = 14') === 'IC_PERSONEL',
        'empty dogum + payload persists IC_PERSONEL'
    );
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT dogum_tarihi FROM personeller WHERE id = 14') === $payloadDogum,
        'empty dogum + payload persists dogum_tarihi atomically'
    );
    pyaAssert(
        pyaScalar($pdo, 'SELECT telefon FROM personeller WHERE id = 14') === null,
        'empty dogum + payload leaves telefon null'
    );
    pyaAssert((int) ($filledDogum['audit_id'] ?? 0) > 0, 'empty dogum + payload writes audit');
    pyaAssert(count($filledDogum['lifecycle_manifest_ids'] ?? []) >= 2, 'empty dogum + payload mints manifests');
    $dogumAudit = $pdo->query(
        'SELECT degisen_alanlar, eski_degerler, yeni_degerler
         FROM personel_organizasyon_degisiklik_auditleri WHERE personel_id = 14 ORDER BY id DESC LIMIT 1'
    )->fetch();
    $dogumChanged = json_decode((string) ($dogumAudit['degisen_alanlar'] ?? '[]'), true);
    pyaAssert(
        in_array('dogum_tarihi', is_array($dogumChanged) ? $dogumChanged : [], true),
        'empty dogum + payload audit lists dogum_tarihi'
    );
    pyaAssert(
        (string) pyaScalar($pdo, "SELECT state FROM surecler WHERE personel_id = 14 AND surec_turu = 'ISTEN_AYRILMA'") === 'IPTAL',
        'empty dogum + payload cancels the exit surec'
    );

    pyaReject(
        $pdo,
        15,
        [
            'gerekce' => 'dogum cakismasi',
            'calisan_kapsami' => 'IC_PERSONEL',
            'dogum_tarihi' => '1980-01-01',
        ],
        PersonelYenidenAktifService::ERROR_DOGUM_CONFLICT
    );
    pyaAssert(pyaStatus($pdo, 15) === 'PASIF', 'dogum conflict keeps PASIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT calisan_kapsami FROM personeller WHERE id = 15') === 'DIS_KAYNAK',
        'dogum conflict keeps DIS_KAYNAK'
    );
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT dogum_tarihi FROM personeller WHERE id = 15') === '1991-08-12',
        'dogum conflict does not overwrite existing dogum_tarihi'
    );
    pyaAssert(
        (string) pyaScalar($pdo, "SELECT state FROM surecler WHERE personel_id = 15") === 'AKTIF',
        'dogum conflict leaves the exit surec open'
    );
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri WHERE personel_id = 15') === 0,
        'dogum conflict writes no audit'
    );

    $disToIc = pyaReactivate($pdo, 8, ['gerekce' => 'ic personel gecisi', 'calisan_kapsami' => 'IC_PERSONEL']);
    pyaAssert(pyaStatus($pdo, 8) === 'AKTIF', 'DIS -> IC happy path flips to AKTIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT calisan_kapsami FROM personeller WHERE id = 8') === 'IC_PERSONEL',
        'DIS -> IC happy path persists IC_PERSONEL'
    );
    pyaAssert((string) ($disToIc['calisan_kapsami'] ?? '') === 'IC_PERSONEL', 'DIS -> IC result reports kapsam');
    pyaAssert(
        pyaScalar($pdo, 'SELECT telefon FROM personeller WHERE id = 8') === null,
        'DIS -> IC with empty telefon leaves telefon null (no placeholder)'
    );

    // Unsupported kapsam direction is rejected (no IC -> DIS back door).
    pyaReject(
        $pdo,
        9,
        ['gerekce' => 'gecersiz kapsam', 'calisan_kapsami' => 'DIS_KAYNAK'],
        PersonelYenidenAktifService::ERROR_KAPSAM_TRANSITION
    );
    pyaAssert(pyaStatus($pdo, 9) === 'PASIF', 'invalid kapsam rejection keeps PASIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT calisan_kapsami FROM personeller WHERE id = 9') === 'IC_PERSONEL',
        'invalid kapsam rejection keeps IC_PERSONEL'
    );

    // Replay short-circuits before any axis parsing or guard: no new writes.
    $aktifReplay = pyaReactivate($pdo, 1, ['gerekce' => 'gecersiz kapsam', 'calisan_kapsami' => 'DIS_KAYNAK']);
    pyaAssert(($aktifReplay['replay'] ?? false) === true, 'already-AKTIF replay ignores axis payloads');

    // ---------------------------------------------------------------------
    // 6) SGK employer / branch company consistency is verified before flip.
    // ---------------------------------------------------------------------
    pyaReject($pdo, 9, ['gerekce' => 'sgk uyusmazligi'], PersonelSgkCompanyConsistency::ERROR_MISMATCH);
    pyaAssert(pyaStatus($pdo, 9) === 'PASIF', 'SGK mismatch rejection keeps PASIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT state FROM surecler WHERE personel_id = 9') === 'AKTIF',
        'SGK mismatch rejection leaves the exit surec open'
    );

    // ---------------------------------------------------------------------
    // 7) Existing manifest/audit evidence is never rewritten.
    // ---------------------------------------------------------------------
    $legacy = $pdo->query(
        "SELECT id, source_sha256, created_at FROM arsiv_manifestleri WHERE source_version_identity = 'legacy:baseline:10'"
    )->fetch();
    $auditIdBefore = pyaCount($pdo, 'SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri WHERE personel_id = 1');
    pyaReactivate($pdo, 10, ['gerekce' => 'manifest korunumu']);

    $legacyAfter = $pdo->query(
        "SELECT id, source_sha256, created_at FROM arsiv_manifestleri WHERE source_version_identity = 'legacy:baseline:10'"
    )->fetch();
    pyaAssert(is_array($legacyAfter), 'pre-existing manifest row still exists');
    pyaAssert(
        (int) $legacyAfter['id'] === (int) $legacy['id']
            && (string) $legacyAfter['source_sha256'] === (string) $legacy['source_sha256']
            && (string) $legacyAfter['created_at'] === (string) $legacy['created_at'],
        'pre-existing manifest row is byte-identical after reactivation'
    );
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = 10') >= 3,
        'new manifests are appended alongside the preserved one'
    );
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri WHERE personel_id = 1') === $auditIdBefore,
        'reactivating another personel never rewrites the earlier audit row'
    );

    // ---------------------------------------------------------------------
    // 8) Gerekce validation is fail-closed before any write.
    // ---------------------------------------------------------------------
    $shortGerekce = 'NO_ERROR';
    try {
        pyaReactivate($pdo, 11, ['gerekce' => 'kisa']);
    } catch (OrganizasyonException $e) {
        $shortGerekce = (string) $e->field;
    }
    pyaAssert($shortGerekce === 'gerekce', 'short gerekce is rejected on the gerekce field');
    pyaAssert(pyaStatus($pdo, 11) === 'PASIF', 'short gerekce rejection keeps PASIF');
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM personel_organizasyon_degisiklik_auditleri WHERE personel_id = 11') === 0,
        'short gerekce rejection writes no audit'
    );
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = 11') === 0,
        'short gerekce rejection writes no manifest'
    );

    // ---------------------------------------------------------------------
    // 9) Atomicity: a failure AFTER the flip rolls the whole transition back.
    //    (actor 999 violates the audit owner FK on the post-mutation insert)
    // ---------------------------------------------------------------------
    $rolledBack = false;
    try {
        pyaReactivate($pdo, 12, ['gerekce' => 'atomik rollback'], 999);
    } catch (Throwable $e) {
        $rolledBack = true;
    }
    pyaAssert($rolledBack, 'post-mutation audit failure aborts the reactivation');
    pyaAssert(pyaStatus($pdo, 12) === 'PASIF', 'atomic rollback restores PASIF');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT state FROM surecler WHERE personel_id = 12') === 'AKTIF',
        'atomic rollback leaves the exit surec open'
    );
    pyaAssert(
        pyaCount($pdo, 'SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = 12') === 0,
        'atomic rollback removes the pre-flip manifests'
    );

    // ---------------------------------------------------------------------
    // 10) Fail-closed when the retention/audit schema owners are absent.
    // ---------------------------------------------------------------------
    $pdo->exec('DROP TABLE legal_holdlar');
    pyaReject($pdo, 9, ['gerekce' => 'legal hold semasi yok'], PersonelYenidenAktifService::ERROR_LEGAL_HOLD_SCHEMA);
    pyaAssert(pyaStatus($pdo, 9) === 'PASIF', 'unverifiable legal hold keeps PASIF (fail-closed)');

    $pdo->exec('DROP TABLE personel_organizasyon_degisiklik_auditleri');
    OrganizasyonAuditWriter::resetCache();
    pyaReject(
        $pdo,
        9,
        ['gerekce' => 'audit semasi yok'],
        OrganizasyonAuditWriter::PERSONEL_ORG_CHANGE_SCHEMA_NOT_READY
    );
    pyaAssert(pyaStatus($pdo, 9) === 'PASIF', 'unauditable reactivation keeps PASIF (fail-closed)');
    pyaAssert(
        (string) pyaScalar($pdo, 'SELECT state FROM surecler WHERE personel_id = 9') === 'AKTIF',
        'unauditable reactivation leaves the exit surec open'
    );

    // ---------------------------------------------------------------------
    // 11) Source contract: archive gate untouched, one canonical owner.
    // ---------------------------------------------------------------------
    $gate = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Retention/PersonelArchiveGate.php');
    pyaAssert(
        strpos($gate, 'ARCHIVED_PERSONEL_READ_ONLY') !== false
            && strpos($gate, 'assertBusinessWriteAllowed') !== false,
        'PersonelArchiveGate keeps its read-only contract'
    );

    $serviceCode = pyaCodeOnly(__DIR__ . '/../../api/src/Services/Personel/PersonelYenidenAktifService.php');
    pyaAssert(
        strpos($serviceCode, 'PersonelArchiveGate') === false
            && strpos($serviceCode, 'assertBusinessWriteAllowed') === false,
        'reactivation owner never calls into the archive gate or bypasses its write lock'
    );
    pyaAssert(
        strpos($serviceCode, 'DELETE FROM surecler') === false
            && stripos($serviceCode, 'DELETE FROM `surecler`') === false,
        'reactivation owner never deletes the exit surec'
    );
    pyaAssert(
        PersonelArchiveGate::isOperationallyHidden($pdo, 1) === false,
        'canonical archive gate helper remains callable after reactivation'
    );

    echo 'verify-personel-yeniden-aktif-mysql: OK' . PHP_EOL;
} catch (Throwable $e) {
    $failure = $e;
    echo '[FAIL] ' . $e->getMessage() . PHP_EOL;
} finally {
    if ($database !== null) {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }
}

// The global handler keeps the process exit code at 0, so the runner owns its
// own failure signal for CI/unit wrappers.
if ($failure !== null) {
    exit(1);
}
