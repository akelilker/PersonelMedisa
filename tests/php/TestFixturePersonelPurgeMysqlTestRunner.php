<?php

declare(strict_types=1);

/**
 * Focused MariaDB acceptance for TEST_FIXTURE purge owner + create-PASIF archive invariant.
 * php tests/php/TestFixturePersonelPurgeMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelValidationException;
use Medisa\Api\Services\Personel\TestFixturePersonelArchiveException;
use Medisa\Api\Services\Personel\TestFixturePersonelClassificationService;
use Medisa\Api\Services\Personel\TestFixturePersonelPurgeService;
use Medisa\Api\Services\Retention\PersonelArchiveGate;
use Medisa\Api\Services\Retention\RetentionCategories;

function tfpAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function tfpRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Disposable MariaDB credentials are required.');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** @return list<string> */
function tfpSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function tfpApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (tfpSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function tfpPdoForDb(string $database): PDO
{
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN'));

    return new PDO(
        (string) $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
}

function tfpSetConnection(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function tfpSeedBase(PDO $pdo): void
{
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES (1, 'Tip', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum)
         VALUES
         (10, 'gy_actor', 'x', 'GY Actor', 'GENEL_YONETICI', 'AKTIF'),
         (11, 'muhasebe_actor', 'x', 'Muh Actor', 'MUHASEBE', 'AKTIF')"
    );
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (10, 1), (11, 1)');
}

function tfpInsertPersonel(PDO $pdo, string $tc, string $sicil): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO personeller
            (tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
             sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id, aktif_durum)
         VALUES
            (:tc, 'Fixture', 'Person', '1990-01-01', '05550000000', 'X', '05550000001',
             :sicil, '2020-01-01', 1, 1, 1, 1, 'AKTIF')"
    );
    $stmt->execute(['tc' => $tc, 'sicil' => $sicil]);

    return (int) $pdo->lastInsertId();
}

function tfpClassify(PDO $pdo, int $personelId, array $actor): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO personel_bordro_kapsamlari
            (personel_id, sube_id, durum, neden_kodu, aciklama, gecerlilik_baslangic, state,
             hazirlayan_id, onaylayan_id, onay_zamani, created_by)
         VALUES
            (:pid, 1, 'HARIC', 'DEMO_TEST_VERISI', 'Demo', '2020-01-01', 'ONAYLANDI',
             :a, :a2, NOW(), :a3)"
    );
    $stmt->execute([
        'pid' => $personelId,
        'a' => (int) $actor['id'],
        'a2' => (int) $actor['id'],
        'a3' => (int) $actor['id'],
    ]);
    TestFixturePersonelClassificationService::classifyViaHttp(
        $pdo,
        $personelId,
        TestFixturePersonelClassificationService::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI,
        $actor,
        'kapsam:' . $personelId
    );
}

/**
 * @param array<string, mixed> $plan
 * @return list<array<string, mixed>>
 */
function tfpBlockersForTable(array $plan, string $table): array
{
    $out = [];
    foreach (($plan['blockers'] ?? []) as $blocker) {
        if ((string) ($blocker['table'] ?? '') === $table) {
            $out[] = $blocker;
        }
    }

    return $out;
}

$root = tfpRootPdo();
$database = 'medisa_tfp_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = tfpPdoForDb($database);
    tfpSetConnection($pdo);
    tfpApply($pdo, '001_initial_schema.sql');
    tfpApply($pdo, '018_personel_ucret_gecmisi.sql');
    tfpApply($pdo, '035_personel_bordro_kapsamlari.sql');
    tfpApply($pdo, '053_retention_legal_hold_arsiv.sql');
    tfpApply($pdo, '056_users_personel_binding.sql');
    tfpApply($pdo, '073_test_fixture_personel_archive.sql');
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS maas_hesaplama_personel_snapshotlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            donem_snapshot_id INT UNSIGNED NOT NULL DEFAULT 1,
            personel_id INT UNSIGNED NOT NULL,
            seal_hash CHAR(64) NULL,
            payload_json JSON NULL
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS puantaj_aylik_muhurleri (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sube_id INT UNSIGNED NOT NULL,
            yil SMALLINT UNSIGNED NOT NULL,
            ay TINYINT UNSIGNED NOT NULL,
            donem CHAR(7) NOT NULL,
            muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0
        )"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS puantaj_aylik_muhur_satirlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            muhur_id INT UNSIGNED NOT NULL,
            personel_id INT UNSIGNED NOT NULL,
            tarih DATE NOT NULL,
            kontrol_durumu VARCHAR(32) NOT NULL DEFAULT 'BEKLIYOR',
            CONSTRAINT fk_tfp_muhur_satir_muhur FOREIGN KEY (muhur_id)
                REFERENCES puantaj_aylik_muhurleri (id) ON DELETE CASCADE,
            CONSTRAINT fk_tfp_muhur_satir_personel FOREIGN KEY (personel_id)
                REFERENCES personeller (id) ON DELETE CASCADE
        )"
    );

    tfpSeedBase($pdo);
    $gy = [
        'id' => 10,
        'username' => 'gy_actor',
        'rol' => 'GENEL_YONETICI',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
    ];

    $unclassified = tfpInsertPersonel($pdo, '10000000001', 'UNC-1');
    $deniedUnclassified = false;
    try {
        TestFixturePersonelPurgeService::purge($pdo, $unclassified, $gy, true, null);
    } catch (TestFixturePersonelArchiveException $e) {
        $deniedUnclassified = $e->getErrorCode() === TestFixturePersonelPurgeService::CODE_REAL_EMPLOYEE;
    }
    tfpAssert($deniedUnclassified, 'unclassified personel purge DENY');

    $real = tfpInsertPersonel($pdo, '10000000002', 'REAL-1');
    $deniedReal = false;
    try {
        TestFixturePersonelPurgeService::purge($pdo, $real, $gy, true, null);
    } catch (TestFixturePersonelArchiveException $e) {
        $deniedReal = $e->getErrorCode() === TestFixturePersonelPurgeService::CODE_REAL_EMPLOYEE;
    }
    tfpAssert($deniedReal, 'real personel purge DENY');
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE id = ' . $real)->fetchColumn() === 1,
        'real personel row preserved'
    );

    $safe = tfpInsertPersonel($pdo, '10000000003', 'SAFE-1');
    tfpClassify($pdo, $safe, $gy);
    $dry = TestFixturePersonelPurgeService::purge($pdo, $safe, $gy, true, null);
    tfpAssert(($dry['purge_safe'] ?? false) === true, 'confirmed fixture + only demo deps dry-run PASS');
    tfpAssert(($dry['status'] ?? '') === 'DRY_RUN', 'dry-run status DRY_RUN');
    tfpAssert(($dry['executed'] ?? true) === false, 'dry-run does not execute');
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE id = ' . $safe)->fetchColumn() === 1,
        'dry-run preserves personel'
    );
    $dry2 = TestFixturePersonelPurgeService::purge($pdo, $safe, $gy, true, null);
    tfpAssert(
        json_encode($dry['delete_order']) === json_encode($dry2['delete_order'])
        && json_encode($dry['dependencies']) === json_encode($dry2['dependencies']),
        'purge plan deterministic'
    );

    $shared = tfpInsertPersonel($pdo, '10000000004', 'SHR-1');
    $other = tfpInsertPersonel($pdo, '10000000005', 'SHR-2');
    tfpClassify($pdo, $shared, $gy);
    $pdo->exec(
        "INSERT INTO maas_hesaplama_personel_snapshotlari (donem_snapshot_id, personel_id)
         VALUES (9, {$shared}), (9, {$other})"
    );
    $sharedPlan = TestFixturePersonelPurgeService::purge($pdo, $shared, $gy, true, null);
    tfpAssert(($sharedPlan['purge_safe'] ?? true) === false, 'shared dependency → FAIL CLOSED');
    $sharedCodes = array_map(static function ($b) {
        return (string) ($b['code'] ?? '');
    }, $sharedPlan['blockers'] ?? []);
    tfpAssert(
        in_array(TestFixturePersonelPurgeService::CODE_HISTORICAL, $sharedCodes, true),
        'shared dependency blocker is historical/shared'
    );
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE id = ' . $shared)->fetchColumn() === 1,
        'shared fixture row preserved'
    );

    $withUser = tfpInsertPersonel($pdo, '10000000006', 'USR-1');
    tfpClassify($pdo, $withUser, $gy);
    $pdo->prepare('UPDATE users SET personel_id = :pid WHERE id = 11')->execute(['pid' => $withUser]);
    $userPlan = TestFixturePersonelPurgeService::purge($pdo, $withUser, $gy, false, TestFixturePersonelPurgeService::CONFIRM_TOKEN);
    tfpAssert(($userPlan['purge_safe'] ?? true) === false, 'user not fixture → purge blocked');
    tfpAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM users WHERE id = 11 AND username = 'muhasebe_actor'")->fetchColumn() === 1,
        'user not fixture → user preserved'
    );
    tfpAssert(
        (int) $pdo->query('SELECT personel_id FROM users WHERE id = 11')->fetchColumn() === $withUser,
        'business user binding preserved'
    );

    $hist = tfpInsertPersonel($pdo, '10000000007', 'HIST-1');
    tfpClassify($pdo, $hist, $gy);
    $pdo->exec(
        "INSERT INTO maas_hesaplama_personel_snapshotlari (donem_snapshot_id, personel_id)
         VALUES (3, {$hist})"
    );
    $histPlan = TestFixturePersonelPurgeService::purge($pdo, $hist, $gy, true, null);
    tfpAssert(($histPlan['purge_safe'] ?? true) === false, 'historical real dependency → purge blocked');

    $exec = tfpInsertPersonel($pdo, '10000000008', 'EXEC-1');
    tfpClassify($pdo, $exec, $gy);
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, gun_tipi, hareket_durumu, dayanak, hesap_etkisi, kontrol_durumu)
        VALUES ({$exec}, '2026-06-15', 'Normal_Is_Gunu', 'Geldi', NULL, 'Tam_Yevmiye_Ver', 'BEKLIYOR')");
    $done = TestFixturePersonelPurgeService::purge(
        $pdo,
        $exec,
        $gy,
        false,
        TestFixturePersonelPurgeService::CONFIRM_TOKEN
    );
    tfpAssert(($done['executed'] ?? false) === true, 'safe fixture execute PURGED');
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE id = ' . $exec)->fetchColumn() === 0,
        'executed purge removes fixture personel'
    );

    tfpAssert(
        PersonelCanonicalValidator::requireCreateAktifDurum('AKTIF') === 'AKTIF',
        'create AKTIF PASS'
    );
    $deniedPasif = false;
    try {
        PersonelCanonicalValidator::requireCreateAktifDurum('PASIF');
    } catch (PersonelValidationException $e) {
        $deniedPasif = $e->getCodeString() === 'CREATE_PASIF_FORBIDDEN';
    }
    tfpAssert($deniedPasif, 'real personel no exit → archive DENY (create PASIF)');

    $audit = tfpInsertPersonel($pdo, '10000000009', 'AUD-1');
    tfpClassify($pdo, $audit, $gy);
    $pdo->exec(
        "INSERT INTO arsiv_erisim_auditleri
            (actor_user_id, target_type, target_id, personel_id, action, route_source)
         VALUES (10, 'personel', {$audit}, {$audit}, 'VIEW', 'purge-test-runner')"
    );
    $auditPlan = TestFixturePersonelPurgeService::purge($pdo, $audit, $gy, true, null);
    tfpAssert(($auditPlan['purge_safe'] ?? true) === false, 'archive access audit → purge FAIL CLOSED');
    $auditBlockers = tfpBlockersForTable($auditPlan, 'arsiv_erisim_auditleri');
    tfpAssert(
        ($auditBlockers[0]['code'] ?? '') === TestFixturePersonelPurgeService::CODE_AUDIT_IMMUTABLE,
        'audit blocker uses canonical append-only audit code'
    );
    tfpAssert(
        ($auditBlockers[0]['owner'] ?? '') === TestFixturePersonelPurgeService::OWNER_ARCHIVE_ACCESS_SERVICE
        && ($auditBlockers[0]['handoff'] ?? '') === TestFixturePersonelPurgeService::HANDOFF_ARCHIVE_ACCESS_AUDIT,
        'audit blocker names archive access audit owner'
    );
    tfpAssert(
        ($auditPlan['delete_order'] ?? [1]) === [],
        'audit-blocked plan has empty delete order'
    );
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM arsiv_erisim_auditleri')->fetchColumn() === 1,
        'audit row preserved (audit integrity)'
    );

    $openOzet = tfpInsertPersonel($pdo, '10000000010', 'OZT-1');
    $otherOzet = tfpInsertPersonel($pdo, '10000000011', 'OZT-2');
    tfpClassify($pdo, $openOzet, $gy);
    $pdo->exec(
        "INSERT INTO aylik_ozet_satirlari (ay, personel_id, ad_soyad, sube, bolum, kapanis_durumu)
         VALUES ('2026-06', {$openOzet}, 'Fixture Person', 'Sube A', 'Dep', 'ACIK'),
                ('2026-06', {$otherOzet}, 'Other Person', 'Sube A', 'Dep', 'ACIK')"
    );
    $openPlan = TestFixturePersonelPurgeService::purge(
        $pdo,
        $openOzet,
        $gy,
        false,
        TestFixturePersonelPurgeService::CONFIRM_TOKEN
    );
    tfpAssert(($openPlan['executed'] ?? false) === true, 'open period summary is fixture-owned → purge PASS');
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM aylik_ozet_satirlari WHERE personel_id = ' . $openOzet)->fetchColumn() === 0,
        'open fixture summary row removed with personel'
    );
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM aylik_ozet_satirlari WHERE personel_id = ' . $otherOzet)->fetchColumn() === 1,
        'other personel summary row preserved'
    );

    $closedOzet = tfpInsertPersonel($pdo, '10000000012', 'OZT-3');
    tfpClassify($pdo, $closedOzet, $gy);
    $pdo->exec(
        "INSERT INTO aylik_ozet_satirlari (ay, personel_id, ad_soyad, sube, bolum, kapanis_durumu)
         VALUES ('2026-07', {$closedOzet}, 'Fixture Person', 'Sube A', 'Dep', 'KAPANDI')"
    );
    $closedPlan = TestFixturePersonelPurgeService::purge($pdo, $closedOzet, $gy, true, null);
    tfpAssert(($closedPlan['purge_safe'] ?? true) === false, 'closed period summary → purge FAIL CLOSED');
    $closedBlockers = tfpBlockersForTable($closedPlan, 'aylik_ozet_satirlari');
    tfpAssert(
        ($closedBlockers[0]['code'] ?? '') === TestFixturePersonelPurgeService::CODE_HISTORICAL
        && ($closedBlockers[0]['class'] ?? '') === TestFixturePersonelPurgeService::CLASS_CLOSED_PERIOD_ARTIFACT,
        'closed period summary blocker is closed-period artifact'
    );
    tfpAssert(
        ($closedBlockers[0]['handoff'] ?? '') === TestFixturePersonelPurgeService::HANDOFF_AYLIK_KAPANIS
        && ($closedBlockers[0]['owner'] ?? '') === TestFixturePersonelPurgeService::OWNER_AYLIK_KAPANIS,
        'closed period blocker hands off to aylik kapanis owner'
    );
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM aylik_ozet_satirlari WHERE personel_id = ' . $closedOzet)->fetchColumn() === 1,
        'closed period summary preserved'
    );

    $sealed = tfpInsertPersonel($pdo, '10000000013', 'MHR-1');
    tfpClassify($pdo, $sealed, $gy);
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, muhurlenen_kayit_sayisi)
         VALUES (1, 2026, 8, '2026-08', 1)"
    );
    $muhurId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih)
         VALUES ({$muhurId}, {$sealed}, '2026-08-15')"
    );
    $sealedPlan = TestFixturePersonelPurgeService::purge($pdo, $sealed, $gy, true, null);
    tfpAssert(($sealedPlan['purge_safe'] ?? true) === false, 'sealed muhur line → purge FAIL CLOSED');
    $sealedBlockers = tfpBlockersForTable($sealedPlan, 'puantaj_aylik_muhur_satirlari');
    tfpAssert(
        ($sealedBlockers[0]['class'] ?? '') === TestFixturePersonelPurgeService::CLASS_SEALED_HISTORICAL
        && ($sealedBlockers[0]['retention_category'] ?? '') === RetentionCategories::PUANTAJ,
        'sealed muhur line blocker is retention-owned (PUANTAJ)'
    );
    tfpAssert(
        ($sealedBlockers[0]['handoff'] ?? '') === TestFixturePersonelPurgeService::HANDOFF_RETENTION_IMHA,
        'sealed muhur line blocker hands off to retention imha'
    );

    $payroll = tfpInsertPersonel($pdo, '10000000014', 'PAY-1');
    tfpClassify($pdo, $payroll, $gy);
    $pdo->exec(
        "INSERT INTO maas_hesaplama_personel_snapshotlari (donem_snapshot_id, personel_id) VALUES (11, {$payroll})"
    );
    $payrollPlan = TestFixturePersonelPurgeService::purge($pdo, $payroll, $gy, true, null);
    tfpAssert(($payrollPlan['purge_safe'] ?? true) === false, 'payroll snapshot ledger → purge FAIL CLOSED');
    $payrollBlockers = tfpBlockersForTable($payrollPlan, 'maas_hesaplama_personel_snapshotlari');
    tfpAssert(
        ($payrollBlockers[0]['class'] ?? '') === TestFixturePersonelPurgeService::CLASS_SEALED_HISTORICAL
        && ($payrollBlockers[0]['retention_category'] ?? '') === RetentionCategories::BORDRO
        && ($payrollBlockers[0]['owner'] ?? '') === TestFixturePersonelPurgeService::OWNER_MAAS_SNAPSHOT_SERVICE,
        'payroll snapshot blocker is sealed ledger owned by snapshot service (BORDRO)'
    );

    // --- Retention-safe tombstone (de-identify, no hard delete) ------------------------------
    $tomb = tfpInsertPersonel($pdo, '10000000015', 'TMB-1');
    tfpClassify($pdo, $tomb, $gy);
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih)
         VALUES ({$muhurId}, {$tomb}, '2026-08-16')"
    );
    $pdo->exec(
        "INSERT INTO maas_hesaplama_personel_snapshotlari (donem_snapshot_id, personel_id) VALUES (12, {$tomb})"
    );

    $tombDry = TestFixturePersonelPurgeService::tombstone($pdo, $tomb, $gy, true, null);
    tfpAssert(($tombDry['status'] ?? '') === 'DRY_RUN', 'tombstone dry-run status DRY_RUN');
    tfpAssert(
        ($tombDry['executed'] ?? true) === false
        && ($tombDry['hard_delete'] ?? true) === false
        && ($tombDry['rows_deleted'] ?? 1) === 0,
        'tombstone dry-run deletes nothing'
    );
    tfpAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM personeller WHERE id = {$tomb} AND ad = 'Fixture'")->fetchColumn() === 1,
        'tombstone dry-run keeps PII preimage'
    );

    $deniedTombConfirm = false;
    try {
        TestFixturePersonelPurgeService::tombstone($pdo, $tomb, $gy, false, TestFixturePersonelPurgeService::CONFIRM_TOKEN);
    } catch (TestFixturePersonelArchiveException $e) {
        $deniedTombConfirm = $e->getErrorCode() === TestFixturePersonelPurgeService::CODE_TOMBSTONE_CONFIRM_REQUIRED;
    }
    tfpAssert($deniedTombConfirm, 'tombstone with hard-purge confirm token DENY');

    $deniedTombReal = false;
    try {
        TestFixturePersonelPurgeService::tombstone(
            $pdo,
            $real,
            $gy,
            false,
            TestFixturePersonelPurgeService::CONFIRM_TOKEN_TOMBSTONE
        );
    } catch (TestFixturePersonelArchiveException $e) {
        $deniedTombReal = $e->getErrorCode() === TestFixturePersonelPurgeService::CODE_REAL_EMPLOYEE;
    }
    tfpAssert($deniedTombReal, 'real personel tombstone DENY');

    $tombDone = TestFixturePersonelPurgeService::tombstone(
        $pdo,
        $tomb,
        $gy,
        false,
        TestFixturePersonelPurgeService::CONFIRM_TOKEN_TOMBSTONE
    );
    tfpAssert(
        ($tombDone['status'] ?? '') === TestFixturePersonelPurgeService::STATUS_TOMBSTONED,
        'tombstone executes for classified fixture'
    );
    $tombRow = $pdo->query(
        'SELECT ad, soyad, tc_kimlik_no, sicil_no, aktif_durum FROM personeller WHERE id = ' . $tomb
    )->fetch(PDO::FETCH_ASSOC);
    tfpAssert(
        is_array($tombRow)
        && $tombRow['ad'] === 'DESTROYED'
        && $tombRow['soyad'] === 'PERSONEL'
        && $tombRow['tc_kimlik_no'] === '9' . str_pad((string) $tomb, 10, '0', STR_PAD_LEFT)
        && $tombRow['sicil_no'] === 'D-' . $tomb
        && strtoupper((string) $tombRow['aktif_durum']) === 'PASIF',
        'tombstone de-identifies PII and forces PASIF'
    );
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE id = ' . $tomb)->fetchColumn() === 1,
        'tombstone keeps the personel row (no hard delete)'
    );
    tfpAssert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM personel_test_fixture_siniflandirmalari WHERE personel_id = {$tomb} AND state = 'AKTIF'"
        )->fetchColumn() === 1,
        'classification evidence stays AKTIF after tombstone'
    );
    tfpAssert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM personel_test_fixture_archive_kayitlari
             WHERE personel_id = {$tomb} AND lifecycle_type = 'TEST_FIXTURE_TOMBSTONE'"
        )->fetchColumn() === 1,
        'tombstone writes id-preserving lifecycle evidence'
    );
    tfpAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM puantaj_aylik_muhur_satirlari WHERE personel_id = ' . $tomb)->fetchColumn() === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_personel_snapshotlari WHERE personel_id = ' . $tomb)->fetchColumn() === 1,
        'sealed puantaj + payroll snapshot evidence preserved'
    );

    $tombAgain = TestFixturePersonelPurgeService::tombstone(
        $pdo,
        $tomb,
        $gy,
        false,
        TestFixturePersonelPurgeService::CONFIRM_TOKEN_TOMBSTONE
    );
    tfpAssert(($tombAgain['status'] ?? '') === 'ALREADY_TOMBSTONED', 'tombstone is idempotent');
    tfpAssert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM personel_test_fixture_archive_kayitlari
             WHERE personel_id = {$tomb} AND lifecycle_type = 'TEST_FIXTURE_TOMBSTONE'"
        )->fetchColumn() === 1,
        'repeated tombstone does not duplicate evidence'
    );

    // --- Canonical operational read exclusion (list / archive / count / detail) ---------------
    $listWhere = [];
    PersonelArchiveGate::appendOperationalExclusion($pdo, $listWhere);
    $listSql = 'SELECT COUNT(*) FROM personeller p WHERE ' . implode(' AND ', $listWhere);
    tfpAssert((int) $pdo->query($listSql)->fetchColumn() > 0, 'operational list still returns real personel');
    tfpAssert(
        (int) $pdo->query($listSql . ' AND p.id = ' . $tomb)->fetchColumn() === 0,
        'tombstoned fixture missing from operational list/count'
    );
    tfpAssert(
        (int) $pdo->query($listSql . ' AND p.id = ' . $real)->fetchColumn() === 1,
        'real personel NOT excluded from operational surfaces'
    );
    tfpAssert(
        (int) $pdo->query($listSql . ' AND p.id = ' . $unclassified)->fetchColumn() === 1,
        'unclassified personel NOT excluded from operational surfaces'
    );

    $archiveWhere = ["p.aktif_durum = 'PASIF'"];
    PersonelArchiveGate::appendOperationalExclusion($pdo, $archiveWhere);
    $archiveSql = 'SELECT COUNT(*) FROM personeller p WHERE ' . implode(' AND ', $archiveWhere);
    tfpAssert(
        (int) $pdo->query($archiveSql . ' AND p.id = ' . $tomb)->fetchColumn() === 0,
        'tombstoned fixture missing from archive search'
    );
    tfpAssert(
        PersonelArchiveGate::isOperationallyHidden($pdo, $tomb) === true
        && PersonelArchiveGate::isOperationallyHidden($pdo, $real) === false,
        'detail exclusion applies to fixture only'
    );

    $svc = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Personel/TestFixturePersonelPurgeService.php');
    tfpAssert(strpos($svc, 'personel_id === 1') === false, 'no hardcoded personel id');
    tfpAssert(strpos($svc, 'DELETE FROM personeller') !== false, 'personel delete only inside fixture purge owner');
    tfpAssert(
        strpos($svc, 'PersonelOzlukDestructionHandler::tombstonePersonelIdentity') !== false,
        'tombstone reuses canonical PERSONEL_OZLUK de-identify primitive'
    );
    tfpAssert(strpos($svc, "'hard_delete' => false") !== false, 'tombstone never claims a delete');

    echo 'verify-test-fixture-personel-purge-mysql: OK' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
