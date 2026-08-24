<?php

declare(strict_types=1);

/**
 * Focused MariaDB acceptance for TEST_FIXTURE_ARCHIVE owner.
 * php tests/php/TestFixturePersonelArchiveMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\TestFixturePersonelArchiveController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Auth\ActorIdentityService;
use Medisa\Api\Services\Personel\TestFixturePersonelArchiveService;
use Medisa\Api\Services\Personel\TestFixturePersonelClassificationService;
use Medisa\Api\Services\Retention\RetentionCategories;

function tfaAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function tfaRootPdo(): PDO
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
function tfaSplitSql(string $sql): array
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

function tfaApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (tfaSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function tfaPdoForDb(string $database): PDO
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

function tfaSetConnection(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function tfaResetAuth($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function tfaRequest(string $method, string $path, array $body = [], array $headers = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'jsonBody' => $body,
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }

    return $request;
}

/** @return array{status:int, payload:array<string,mixed>} */
function tfaHttp(PDO $pdo, $user, string $method, string $path, array $body = []): array
{
    tfaSetConnection($pdo);
    tfaResetAuth($user);
    $statusFile = tempnam(sys_get_temp_dir(), 'tfa_http_');
    $prev = getenv('MEDISA_TEST_HTTP_STATUS_FILE');
    putenv('MEDISA_TEST_HTTP_STATUS_FILE=' . $statusFile);
    ob_start();
    try {
        if (preg_match('#^/personeller/(\d+)/test-fixture-archive$#', $path, $m)) {
            TestFixturePersonelArchiveController::archive(tfaRequest($method, $path, $body), $m[1]);
        } else {
            throw new RuntimeException('Unexpected path');
        }
    } catch (\Throwable $e) {
        ob_end_clean();
        putenv('MEDISA_TEST_HTTP_STATUS_FILE=' . ($prev === false ? '' : $prev));
        throw $e;
    }
    $raw = ob_get_clean();
    putenv('MEDISA_TEST_HTTP_STATUS_FILE=' . ($prev === false ? '' : $prev));
    $status = (int) trim((string) file_get_contents($statusFile));
    @unlink($statusFile);
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        $payload = ['raw' => $raw];
    }

    return ['status' => $status, 'payload' => $payload];
}

function tfaSeedBase(PDO $pdo): void
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

/** @return int personel id */
function tfaInsertPersonel(PDO $pdo, string $tc, string $sicil, string $ad = 'Fixture', string $soyad = 'Person'): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO personeller
            (tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
             sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id, aktif_durum)
         VALUES
            (:tc, :ad, :soyad, '1990-01-01', '05550000000', 'X', '05550000001',
             :sicil, '2020-01-01', 1, 1, 1, 1, 'AKTIF')"
    );
    $stmt->execute(['tc' => $tc, 'ad' => $ad, 'soyad' => $soyad, 'sicil' => $sicil]);

    return (int) $pdo->lastInsertId();
}

$root = tfaRootPdo();
$database = 'medisa_tfa_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = tfaPdoForDb($database);
    tfaApply($pdo, '001_initial_schema.sql');
    tfaApply($pdo, '005_gunluk_bildirimler.sql');
    tfaApply($pdo, '018_personel_ucret_gecmisi.sql');
    tfaApply($pdo, '053_retention_legal_hold_arsiv.sql');
    tfaApply($pdo, '056_users_personel_binding.sql');
    tfaApply($pdo, '066_personel_calisan_kapsami.sql');
    tfaApply($pdo, '073_test_fixture_personel_archive.sql');
    // Idempotent re-apply
    tfaApply($pdo, '073_test_fixture_personel_archive.sql');

    tfaSeedBase($pdo);
    $gy = [
        'id' => 10,
        'username' => 'gy_actor',
        'rol' => 'GENEL_YONETICI',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
    ];
    $muhasebe = [
        'id' => 11,
        'username' => 'muhasebe_actor',
        'rol' => 'MUHASEBE',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
    ];

    // --- 1 valid fixture / no deps → PASS ---
    $p1 = tfaInsertPersonel($pdo, '11111111111', 'TF-001');
    TestFixturePersonelClassificationService::classify(
        $pdo,
        $p1,
        TestFixturePersonelClassificationService::EVIDENCE_SEED_SCHEMA_FIXTURE,
        $gy
    );
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, personel_id)
         VALUES ('tf_u1', 'x', 'Bound Pasif', 'MUHASEBE', 'PASIF', {$p1})"
    );
    $r1 = TestFixturePersonelArchiveService::archive($pdo, $p1, $gy);
    tfaAssert(($r1['status'] ?? '') === 'ARCHIVED', '1 valid fixture/no deps → PASS');
    tfaAssert(($r1['aktif_durum'] ?? '') === 'PASIF', '1 personel PASIF');
    tfaAssert(array_key_exists('termination_date', $r1) && $r1['termination_date'] === null, '12 fake termination date absent');
    tfaAssert(($r1['fake_employment_exit_created'] ?? true) === false, '12 fake employment exit false');
    $istenCol = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personeller' AND COLUMN_NAME = 'isten_cikis_tarihi'"
    )->fetchColumn();
    if ($istenCol === 1) {
        $exitDate = $pdo->query("SELECT isten_cikis_tarihi FROM personeller WHERE id = {$p1}")->fetchColumn();
        tfaAssert($exitDate === null || $exitDate === '', '12 no isten_cikis_tarihi written');
    }
    tfaAssert(isset($r1['manifest']['id']) && (int) $r1['manifest']['id'] > 0, '11 archive manifest created');
    tfaAssert(
        ($r1['manifest']['trigger_type'] ?? '') === RetentionCategories::TRIGGER_TEST_FIXTURE_ARCHIVE,
        '11 manifest trigger TEST_FIXTURE_ARCHIVE'
    );

    // --- 13 idempotent second call ---
    $r1b = TestFixturePersonelArchiveService::archive($pdo, $p1, $gy);
    tfaAssert(($r1b['status'] ?? '') === TestFixturePersonelArchiveService::CODE_ALREADY_CORRECT, '13 idempotent second call');
    $manifestCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM arsiv_manifestleri WHERE personel_id = {$p1}
         AND trigger_type = 'TEST_FIXTURE_ARCHIVE'"
    )->fetchColumn();
    tfaAssert($manifestCount === 1, '13 no duplicate manifest');

    // --- 14 active list exclusion ---
    $aktifCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM personeller WHERE id = {$p1} AND aktif_durum = 'AKTIF'"
    )->fetchColumn();
    tfaAssert($aktifCount === 0, '14 active list exclusion');

    // --- 16 historical read preserved (row still exists) ---
    $hist = $pdo->query("SELECT id, aktif_durum, sicil_no FROM personeller WHERE id = {$p1}")->fetch(PDO::FETCH_ASSOC);
    tfaAssert(is_array($hist) && (string) $hist['aktif_durum'] === 'PASIF', '16 historical read preserved');

    // --- 15 QR / formal actor denial ---
    $ref = new ReflectionClass(ActorIdentityService::class);
    $m = $ref->getMethod('loadActivePersonel');
    $m->setAccessible(true);
    $qrDenied = false;
    try {
        $m->invoke(null, $pdo, $p1);
    } catch (\Medisa\Api\Services\Auth\ActorIdentityException $e) {
        $qrDenied = ($e->errorCode === 'PERSONEL_INACTIVE')
            || strpos($e->getMessage(), 'Pasif personel') !== false;
    }
    tfaAssert($qrDenied, '15 QR/new workflow denial for PASIF personel');

    // --- 2 real employee → DENY ---
    $pReal = tfaInsertPersonel($pdo, '22222222222', 'REAL-001', 'Real', 'Employee');
    $deniedReal = false;
    try {
        TestFixturePersonelArchiveService::archive($pdo, $pReal, $gy);
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $deniedReal = $e->getErrorCode() === TestFixturePersonelArchiveService::CODE_REAL_EMPLOYEE;
    }
    tfaAssert($deniedReal, '2 real employee → DENY');

    // --- 3 unknown classification → DENY ---
    $pUnk = tfaInsertPersonel($pdo, '33333333333', 'UNK-001');
    $deniedUnk = false;
    try {
        TestFixturePersonelArchiveService::archive($pdo, $pUnk, $gy);
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $deniedUnk = $e->getErrorCode() === TestFixturePersonelArchiveService::CODE_REAL_EMPLOYEE;
    }
    tfaAssert($deniedUnk, '3 unknown classification → DENY');

    // --- 4 active bound user → DENY ---
    $pActiveUser = tfaInsertPersonel($pdo, '44444444444', 'TF-AU');
    TestFixturePersonelClassificationService::classify(
        $pdo,
        $pActiveUser,
        TestFixturePersonelClassificationService::EVIDENCE_SEED_SCHEMA_FIXTURE,
        $gy
    );
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, personel_id)
         VALUES ('tf_active_bound', 'x', 'Bound Aktif', 'MUHASEBE', 'AKTIF', {$pActiveUser})"
    );
    $deniedActiveUser = false;
    try {
        TestFixturePersonelArchiveService::archive($pdo, $pActiveUser, $gy);
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $deniedActiveUser = $e->getErrorCode() === TestFixturePersonelArchiveService::CODE_ACTIVE_BOUND_USER;
    }
    tfaAssert($deniedActiveUser, '4 active bound user → DENY');

    // --- 5 PASIF bound user → PASS ---
    $pPasifUser = tfaInsertPersonel($pdo, '55555555555', 'TF-PU');
    TestFixturePersonelClassificationService::classify(
        $pdo,
        $pPasifUser,
        TestFixturePersonelClassificationService::EVIDENCE_LIVE_API_SMOKE_CREATE,
        $gy
    );
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, personel_id)
         VALUES ('tf_pasif_bound', 'x', 'Bound Pasif2', 'MUHASEBE', 'PASIF', {$pPasifUser})"
    );
    $r5 = TestFixturePersonelArchiveService::archive($pdo, $pPasifUser, $gy);
    tfaAssert(($r5['status'] ?? '') === 'ARCHIVED', '5 PASIF bound user → PASS');

    // --- 6 historical refs preserved ---
    $pHist = tfaInsertPersonel($pdo, '66666666666', 'TF-HIST');
    TestFixturePersonelClassificationService::classify(
        $pdo,
        $pHist,
        TestFixturePersonelClassificationService::EVIDENCE_SEED_SCHEMA_FIXTURE,
        $gy
    );
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, gun_tipi)
         VALUES ({$pHist}, '2026-03-02', 'Normal_Is_Gunu')"
    );
    $pdo->exec(
        "INSERT INTO gunluk_bildirimler (personel_id, tarih, sube_id, bildirim_turu, state)
         VALUES ({$pHist}, '2026-03-02', 1, 'DIGER', 'TASLAK')"
    );
    $r6 = TestFixturePersonelArchiveService::archive($pdo, $pHist, $gy);
    tfaAssert(($r6['status'] ?? '') === 'ARCHIVED', '6 archive with historical refs');
    $puantajLeft = (int) $pdo->query(
        "SELECT COUNT(*) FROM gunluk_puantaj WHERE personel_id = {$pHist}"
    )->fetchColumn();
    tfaAssert($puantajLeft === 1, '6 historical puantaj preserved');
    $bildirimState = (string) $pdo->query(
        "SELECT state FROM gunluk_bildirimler WHERE personel_id = {$pHist} LIMIT 1"
    )->fetchColumn();
    tfaAssert($bildirimState === 'IPTAL', '8 cancellable workflow cancelled (TASLAK→IPTAL)');

    // --- 7 sealed payroll/SGK unsafe rewrite rejected / preserved ---
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS maas_hesaplama_personel_snapshotlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            personel_id INT UNSIGNED NOT NULL,
            seal_hash CHAR(64) NOT NULL,
            payload_json JSON NULL,
            PRIMARY KEY (id)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $pSeal = tfaInsertPersonel($pdo, '77777777777', 'TF-SEAL');
    TestFixturePersonelClassificationService::classify(
        $pdo,
        $pSeal,
        TestFixturePersonelClassificationService::EVIDENCE_SEED_SCHEMA_FIXTURE,
        $gy
    );
    $pdo->exec(
        "INSERT INTO maas_hesaplama_personel_snapshotlari (personel_id, seal_hash, payload_json)
         VALUES ({$pSeal}, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '{\"sealed\":true}')"
    );
    $beforeSeal = $pdo->query(
        "SELECT seal_hash, payload_json FROM maas_hesaplama_personel_snapshotlari WHERE personel_id = {$pSeal}"
    )->fetch(PDO::FETCH_ASSOC);
    $r7 = TestFixturePersonelArchiveService::archive($pdo, $pSeal, $gy);
    tfaAssert(($r7['status'] ?? '') === 'ARCHIVED', '7 archive with sealed snapshot present');
    $afterSeal = $pdo->query(
        "SELECT seal_hash, payload_json FROM maas_hesaplama_personel_snapshotlari WHERE personel_id = {$pSeal}"
    )->fetch(PDO::FETCH_ASSOC);
    tfaAssert(
        (string) $beforeSeal['seal_hash'] === (string) $afterSeal['seal_hash']
        && (string) $beforeSeal['payload_json'] === (string) $afterSeal['payload_json'],
        '7 sealed payroll/SGK preserved (no rewrite)'
    );

    // --- 9 incompatible workflow fail closed ---
    $pBlock = tfaInsertPersonel($pdo, '88888888888', 'TF-BLOCK');
    TestFixturePersonelClassificationService::classify(
        $pdo,
        $pBlock,
        TestFixturePersonelClassificationService::EVIDENCE_SEED_SCHEMA_FIXTURE,
        $gy
    );
    $pdo->exec(
        "INSERT INTO gunluk_bildirimler (personel_id, tarih, sube_id, bildirim_turu, state)
         VALUES ({$pBlock}, '2026-07-01', 1, 'DIGER', 'HAFTALIK_MUTABAKATA_ALINDI')"
    );
    $blocked = false;
    try {
        TestFixturePersonelArchiveService::archive($pdo, $pBlock, $gy);
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $blocked = $e->getErrorCode() === TestFixturePersonelArchiveService::CODE_OPEN_WORKFLOW_UNSUPPORTED;
    }
    tfaAssert($blocked, '9 incompatible workflow fail closed');
    $stillAktif = (string) $pdo->query(
        "SELECT aktif_durum FROM personeller WHERE id = {$pBlock}"
    )->fetchColumn();
    tfaAssert($stillAktif === 'AKTIF', '18 dependency failure transaction rollback (personel still AKTIF)');

    // --- 8 surec cancel + 10 future ucret ---
    $pDeps = tfaInsertPersonel($pdo, '99999999999', 'TF-DEPS');
    TestFixturePersonelClassificationService::classify(
        $pdo,
        $pDeps,
        TestFixturePersonelClassificationService::EVIDENCE_SEED_SCHEMA_FIXTURE,
        $gy
    );
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, state)
         VALUES ({$pDeps}, 'RAPOR', 'Raporlu_Hastalik', '2026-07-06', '2026-07-10', 'AKTIF')"
    );
    $futureBas = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
    $pastBas = (new DateTimeImmutable('today'))->modify('-60 days')->format('Y-m-d');
    $pastBit = (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
    $pdo->prepare(
        "INSERT INTO personel_ucret_gecmisi
            (personel_id, ucret_tutari, ucret_turu, gecerlilik_baslangic, gecerlilik_bitis, state, kaynak)
         VALUES
            (:pid, 1000.00, 'NET', :past_bas, :past_bit, 'AKTIF', 'MANUEL'),
            (:pid2, 2000.00, 'NET', :future_bas, NULL, 'AKTIF', 'MANUEL')"
    )->execute([
        'pid' => $pDeps,
        'past_bas' => $pastBas,
        'past_bit' => $pastBit,
        'pid2' => $pDeps,
        'future_bas' => $futureBas,
    ]);
    // unique open_ended: only one open-ended AKTIF allowed — past has bitis, future open OK
    $rDeps = TestFixturePersonelArchiveService::archive($pdo, $pDeps, $gy);
    tfaAssert(($rDeps['status'] ?? '') === 'ARCHIVED', '8/10 deps archive PASS');
    $surecState = (string) $pdo->query(
        "SELECT state FROM surecler WHERE personel_id = {$pDeps} LIMIT 1"
    )->fetchColumn();
    tfaAssert($surecState === 'IPTAL', '8 cancellable surec cancelled');
    $futureState = (string) $pdo->query(
        "SELECT state FROM personel_ucret_gecmisi
         WHERE personel_id = {$pDeps} AND gecerlilik_baslangic = '{$futureBas}'"
    )->fetchColumn();
    tfaAssert($futureState === 'IPTAL', '10 future ucret safe cancel');
    $pastState = (string) $pdo->query(
        "SELECT state FROM personel_ucret_gecmisi
         WHERE personel_id = {$pDeps} AND gecerlilik_baslangic = '{$pastBas}'"
    )->fetchColumn();
    tfaAssert($pastState === 'AKTIF', '10 historical wage row preserved');

    // --- 17 unauthorized role denied ---
    tfaAssert(
        !\Medisa\Api\Auth\RolePermissions::has($muhasebe, 'personeller.test_fixture.archive'),
        '17 unauthorized role denied'
    );
    tfaAssert(
        \Medisa\Api\Auth\RolePermissions::has($gy, 'personeller.test_fixture.archive'),
        '17 GY authorized for archive'
    );

    // No hardcoded production IDs in service source
    $svc = file_get_contents(__DIR__ . '/../../api/src/Services/Personel/TestFixturePersonelArchiveService.php');
    tfaAssert(strpos($svc, 'personel_id === 1') === false, 'no hardcoded personel id eligibility');
    tfaAssert(strpos((string) $svc, 'P-0001') === false, 'no sicil pattern eligibility');

    // ISTEN_AYRILMA termination requirement untouched
    $surecSrc = file_get_contents(__DIR__ . '/../../api/src/Controllers/SureclerController.php');
    tfaAssert(strpos($surecSrc, "surec_turu'] === 'ISTEN_AYRILMA'") !== false, 'ISTEN_AYRILMA path preserved');
    $manifestSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Retention/ArchiveManifestService.php');
    tfaAssert(
        strpos($manifestSrc, 'CODE_TERMINATION_DATE_MISSING') !== false,
        'ISTEN_AYRILMA termination-date requirement preserved'
    );

    echo 'verify-test-fixture-personel-archive-mysql: OK' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
