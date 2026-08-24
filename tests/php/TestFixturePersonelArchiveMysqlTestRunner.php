<?php

declare(strict_types=1);

/**
 * Focused MariaDB acceptance for TEST_FIXTURE classification + archive owners.
 * php tests/php/TestFixturePersonelArchiveMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Controllers\TestFixturePersonelArchiveController;
use Medisa\Api\Controllers\TestFixturePersonelClassificationController;
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
        if (preg_match('#^/personeller/(\d+)/test-fixture-classification$#', $path, $m)) {
            TestFixturePersonelClassificationController::classify(tfaRequest($method, $path, $body), $m[1]);
        } elseif (preg_match('#^/personeller/(\d+)/test-fixture-archive$#', $path, $m)) {
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

function tfaInsertDemoKapsam(PDO $pdo, int $personelId, int $actorId = 10): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO personel_bordro_kapsamlari
            (personel_id, sube_id, durum, neden_kodu, aciklama, gecerlilik_baslangic, state,
             hazirlayan_id, onaylayan_id, onay_zamani, created_by)
         VALUES
            (:pid, 1, 'HARIC', 'DEMO_TEST_VERISI', 'Demo test kapsami kaniti', '2020-01-01', 'ONAYLANDI',
             :actor, :actor2, NOW(), :actor3)"
    );
    $stmt->execute([
        'pid' => $personelId,
        'actor' => $actorId,
        'actor2' => $actorId,
        'actor3' => $actorId,
    ]);

    return (int) $pdo->lastInsertId();
}

/** @param array<string,mixed> $actor */
function tfaClassifyFixture(PDO $pdo, int $personelId, array $actor): array
{
    tfaInsertDemoKapsam($pdo, $personelId, (int) ($actor['id'] ?? 10));

    return TestFixturePersonelClassificationService::classifyViaHttp(
        $pdo,
        $personelId,
        TestFixturePersonelClassificationService::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI,
        $actor,
        'kapsam:' . $personelId
    );
}

$root = tfaRootPdo();
$database = 'medisa_tfa_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = tfaPdoForDb($database);
    tfaApply($pdo, '001_initial_schema.sql');
    tfaApply($pdo, '005_gunluk_bildirimler.sql');
    tfaApply($pdo, '018_personel_ucret_gecmisi.sql');
    tfaApply($pdo, '035_personel_bordro_kapsamlari.sql');
    tfaApply($pdo, '053_retention_legal_hold_arsiv.sql');
    tfaApply($pdo, '056_users_personel_binding.sql');
    tfaApply($pdo, '066_personel_calisan_kapsami.sql');
    tfaApply($pdo, '073_test_fixture_personel_archive.sql');
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
    $personelRole = [
        'id' => 12,
        'username' => 'personel_actor',
        'rol' => 'PERSONEL',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
    ];

    // ========== CLASSIFICATION ==========
    $pCls = tfaInsertPersonel($pdo, '10000000001', 'CLS-001');
    tfaInsertDemoKapsam($pdo, $pCls);
    $c1 = TestFixturePersonelClassificationService::classifyViaHttp(
        $pdo,
        $pCls,
        TestFixturePersonelClassificationService::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI,
        $gy,
        'demo-kapsam-1'
    );
    tfaAssert(($c1['status'] ?? '') === 'CLASSIFIED', '1 valid persisted DEMO_TEST evidence → classify PASS');
    tfaAssert(($c1['evidence_kodu'] ?? '') === 'BORDRO_KAPSAM_DEMO_TEST_VERISI', '9 classification evidence preserved');
    tfaAssert(($c1['classified_by'] ?? 0) === 10, '9 classification actor preserved');
    tfaAssert(isset($c1['classified_at']) && $c1['classified_at'] !== '', '9 classification timestamp preserved');

    $pRealCls = tfaInsertPersonel($pdo, '10000000002', 'REAL-CLS', 'Real', 'Employee');
    $denyRealCls = false;
    try {
        TestFixturePersonelClassificationService::classifyViaHttp(
            $pdo,
            $pRealCls,
            TestFixturePersonelClassificationService::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI,
            $gy
        );
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $denyRealCls = $e->getErrorCode() === 'EVIDENCE_MISSING';
    }
    tfaAssert($denyRealCls, '2 real employee → DENY');

    $pClient = tfaInsertPersonel($pdo, '10000000003', 'CLIENT-ONLY');
    $denyClient = false;
    try {
        TestFixturePersonelClassificationService::classifyViaHttp(
            $pdo,
            $pClient,
            'CLIENT_ASSERTION',
            $gy
        );
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $denyClient = in_array($e->getErrorCode(), ['EVIDENCE_NOT_HTTP_ELIGIBLE', 'INVALID_EVIDENCE_KODU'], true);
    }
    tfaAssert($denyClient, '3 client-only assertion → DENY');

    $pPattern = tfaInsertPersonel($pdo, '10000000004', 'TEST-001', 'Test', 'User');
    $denyPattern = false;
    try {
        TestFixturePersonelClassificationService::classifyViaHttp(
            $pdo,
            $pPattern,
            TestFixturePersonelClassificationService::EVIDENCE_SEED_SCHEMA_FIXTURE,
            $gy
        );
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $denyPattern = in_array($e->getErrorCode(), ['EVIDENCE_NOT_HTTP_ELIGIBLE', 'EVIDENCE_UNVERIFIABLE'], true);
    }
    tfaAssert($denyPattern, '4 name/sicil pattern alone → DENY');

    $pManual = tfaInsertPersonel($pdo, '10000000005', 'MANUAL-1');
    $denyManual = false;
    try {
        TestFixturePersonelClassificationService::classifyViaHttp(
            $pdo,
            $pManual,
            TestFixturePersonelClassificationService::EVIDENCE_MANUAL_OPS_CLASSIFIED,
            $gy
        );
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $denyManual = in_array($e->getErrorCode(), ['EVIDENCE_NOT_HTTP_ELIGIBLE', 'EVIDENCE_UNVERIFIABLE'], true);
    }
    tfaAssert($denyManual, '5 insufficient MANUAL_OPS evidence → DENY');

    tfaAssert(
        !RolePermissions::has($muhasebe, 'personeller.test_fixture.classify'),
        '6 unauthorized role → DENY'
    );
    tfaAssert(
        !RolePermissions::has($personelRole, 'personeller.test_fixture.classify'),
        '7 PERSONEL → DENY'
    );
    tfaAssert(
        RolePermissions::has($gy, 'personeller.test_fixture.classify'),
        '6 GY authorized for classify'
    );

    $c2 = TestFixturePersonelClassificationService::classifyViaHttp(
        $pdo,
        $pCls,
        TestFixturePersonelClassificationService::EVIDENCE_BORDRO_KAPSAM_DEMO_TEST_VERISI,
        $gy
    );
    tfaAssert(($c2['status'] ?? '') === TestFixturePersonelClassificationService::CODE_ALREADY_CORRECT, '8 second classification → ALREADY_CORRECT');
    $clsCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_test_fixture_siniflandirmalari WHERE personel_id = {$pCls}"
    )->fetchColumn();
    tfaAssert($clsCount === 1, '8 no duplicate classification row');

    // ========== ARCHIVE ==========
    $p1 = tfaInsertPersonel($pdo, '11111111111', 'TF-001');
    tfaClassifyFixture($pdo, $p1, $gy);
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, personel_id)
         VALUES ('tf_u1', 'x', 'Bound Pasif', 'MUHASEBE', 'PASIF', {$p1})"
    );
    $r1 = TestFixturePersonelArchiveService::archive($pdo, $p1, $gy);
    tfaAssert(($r1['status'] ?? '') === 'ARCHIVED', '17 P4 no-dependency → PASS');
    tfaAssert(array_key_exists('termination_date', $r1) && $r1['termination_date'] === null, '18 fake termination date absent');
    tfaAssert(($r1['fake_employment_exit_created'] ?? true) === false, '18 fake employment exit false');
    tfaAssert(isset($r1['manifest']['id']) && (int) $r1['manifest']['id'] > 0, 'archive manifest created');

    $r1b = TestFixturePersonelArchiveService::archive($pdo, $p1, $gy);
    tfaAssert(($r1b['status'] ?? '') === TestFixturePersonelArchiveService::CODE_ALREADY_CORRECT, 'archive idempotent');

    $aktifCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM personeller WHERE id = {$p1} AND aktif_durum = 'AKTIF'"
    )->fetchColumn();
    tfaAssert($aktifCount === 0, '20 active-set exclusion passes');

    $hist = $pdo->query("SELECT id, aktif_durum FROM personeller WHERE id = {$p1}")->fetch(PDO::FETCH_ASSOC);
    tfaAssert(is_array($hist) && (string) $hist['aktif_durum'] === 'PASIF', '19 historical refs retained');

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
    tfaAssert($qrDenied, 'QR denial for PASIF fixture');

    $pReal = tfaInsertPersonel($pdo, '22222222222', 'REAL-001', 'Real', 'Employee');
    $deniedReal = false;
    try {
        TestFixturePersonelArchiveService::archive($pdo, $pReal, $gy);
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $deniedReal = $e->getErrorCode() === TestFixturePersonelArchiveService::CODE_REAL_EMPLOYEE;
    }
    tfaAssert($deniedReal, 'archive real employee → DENY');

    $pUnk = tfaInsertPersonel($pdo, '33333333333', 'UNK-001');
    $deniedUnk = false;
    try {
        TestFixturePersonelArchiveService::archive($pdo, $pUnk, $gy);
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $deniedUnk = $e->getErrorCode() === TestFixturePersonelArchiveService::CODE_REAL_EMPLOYEE;
    }
    tfaAssert($deniedUnk, 'unknown classification → DENY');

    $pActiveUser = tfaInsertPersonel($pdo, '44444444444', 'TF-AU');
    tfaClassifyFixture($pdo, $pActiveUser, $gy);
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
    tfaAssert($deniedActiveUser, 'active bound user → DENY');

    $pPasifUser = tfaInsertPersonel($pdo, '55555555555', 'TF-PU');
    tfaClassifyFixture($pdo, $pPasifUser, $gy);
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, personel_id)
         VALUES ('tf_pasif_bound', 'x', 'Bound Pasif2', 'MUHASEBE', 'PASIF', {$pPasifUser})"
    );
    $r5 = TestFixturePersonelArchiveService::archive($pdo, $pPasifUser, $gy);
    tfaAssert(($r5['status'] ?? '') === 'ARCHIVED', 'PASIF bound user → PASS');

    // 10 HAFTALIK_MUTABAKATA_ALINDI fixture → cancelled with TEST_FIXTURE_ARCHIVE reason
    $pHaftalik = tfaInsertPersonel($pdo, '66666666666', 'TF-HAFT');
    tfaClassifyFixture($pdo, $pHaftalik, $gy);
    $pdo->exec(
        "INSERT INTO gunluk_bildirimler (personel_id, tarih, sube_id, bildirim_turu, state, aciklama)
         VALUES ({$pHaftalik}, '2026-07-01', 1, 'DIGER', 'HAFTALIK_MUTABAKATA_ALINDI', 'weekly fixture')"
    );
    $rHaft = TestFixturePersonelArchiveService::archive($pdo, $pHaftalik, $gy);
    tfaAssert(($rHaft['status'] ?? '') === 'ARCHIVED', '10 HAFTALIK fixture safely cancelled');
    $haftRow = $pdo->query(
        "SELECT state, aciklama FROM gunluk_bildirimler WHERE personel_id = {$pHaftalik} LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    tfaAssert(is_array($haftRow) && (string) $haftRow['state'] === 'IPTAL', '10 HAFTALIK → IPTAL');
    tfaAssert(
        is_array($haftRow) && strpos((string) $haftRow['aciklama'], 'TEST_FIXTURE_ARCHIVE') !== false,
        '12 cancellation audit reason = TEST_FIXTURE_ARCHIVE'
    );

    // 11 ordinary cancel path still rejects HAFTALIK
    $bildirimSrc = file_get_contents(__DIR__ . '/../../api/src/Controllers/BildirimlerController.php');
    tfaAssert(
        strpos((string) $bildirimSrc, "state === 'HAFTALIK_MUTABAKATA_ALINDI'") !== false
        && strpos((string) $bildirimSrc, 'haftalık mutabakata alındığı için doğrudan değiştirilemez') !== false,
        '11 ordinary real personnel HAFTALIK cancel remains blocked'
    );

    // 13 P1-style dependency set atomic archive
    $pP1 = tfaInsertPersonel($pdo, '77777777771', 'TF-P1');
    tfaClassifyFixture($pdo, $pP1, $gy);
    $pdo->exec(
        "INSERT INTO gunluk_bildirimler (personel_id, tarih, sube_id, bildirim_turu, state)
         VALUES
         ({$pP1}, '2026-06-01', 1, 'DIGER', 'TASLAK'),
         ({$pP1}, '2026-06-02', 1, 'DIGER', 'HAFTALIK_MUTABAKATA_ALINDI')"
    );
    $futureBas = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
    $pdo->prepare(
        "INSERT INTO personel_ucret_gecmisi
            (personel_id, ucret_tutari, ucret_turu, gecerlilik_baslangic, gecerlilik_bitis, state, kaynak)
         VALUES (:pid, 2000.00, 'NET', :future_bas, NULL, 'AKTIF', 'MANUEL')"
    )->execute(['pid' => $pP1, 'future_bas' => $futureBas]);
    $rP1 = TestFixturePersonelArchiveService::archive($pdo, $pP1, $gy);
    tfaAssert(($rP1['status'] ?? '') === 'ARCHIVED', '13 P1-style dependency set archives atomically');
    $bildirimIptal = (int) $pdo->query(
        "SELECT COUNT(*) FROM gunluk_bildirimler WHERE personel_id = {$pP1} AND state = 'IPTAL'"
    )->fetchColumn();
    tfaAssert($bildirimIptal === 2, '13 all P1 bildirim cancelled');
    $futureState = (string) $pdo->query(
        "SELECT state FROM personel_ucret_gecmisi WHERE personel_id = {$pP1} AND gecerlilik_baslangic = '{$futureBas}'"
    )->fetchColumn();
    tfaAssert($futureState === 'IPTAL', '13 future ucret cancelled');

    // 14 incompatible state fail-closed + rollback
    $pBlock = tfaInsertPersonel($pdo, '88888888888', 'TF-BLOCK');
    tfaClassifyFixture($pdo, $pBlock, $gy);
    $pdo->exec(
        "INSERT INTO gunluk_bildirimler (personel_id, tarih, sube_id, bildirim_turu, state)
         VALUES ({$pBlock}, '2026-07-01', 1, 'DIGER', 'GONDERILDI')"
    );
    $blocked = false;
    try {
        TestFixturePersonelArchiveService::archive($pdo, $pBlock, $gy);
    } catch (\Medisa\Api\Services\Personel\TestFixturePersonelArchiveException $e) {
        $blocked = $e->getErrorCode() === TestFixturePersonelArchiveService::CODE_OPEN_WORKFLOW_UNSUPPORTED;
    }
    tfaAssert($blocked, '14 incompatible workflow fail closed');
    $stillAktif = (string) $pdo->query(
        "SELECT aktif_durum FROM personeller WHERE id = {$pBlock}"
    )->fetchColumn();
    tfaAssert($stillAktif === 'AKTIF', '14 dependency failure transaction rollback');
    $stillGonderildi = (string) $pdo->query(
        "SELECT state FROM gunluk_bildirimler WHERE personel_id = {$pBlock} LIMIT 1"
    )->fetchColumn();
    tfaAssert($stillGonderildi === 'GONDERILDI', '14 bildirim not partially cancelled');

    // 15 P2 RAPOR
    $pP2 = tfaInsertPersonel($pdo, '99999999992', 'TF-P2');
    tfaClassifyFixture($pdo, $pP2, $gy);
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, state)
         VALUES
         ({$pP2}, 'RAPOR', 'Raporlu_Hastalik', '2026-07-06', '2026-07-10', 'AKTIF'),
         ({$pP2}, 'RAPOR', 'Raporlu_Hastalik', '2026-07-11', '2026-07-12', 'AKTIF')"
    );
    $rP2 = TestFixturePersonelArchiveService::archive($pdo, $pP2, $gy);
    tfaAssert(($rP2['status'] ?? '') === 'ARCHIVED', '15 P2-style RAPOR passes');
    $raporIptal = (int) $pdo->query(
        "SELECT COUNT(*) FROM surecler WHERE personel_id = {$pP2} AND state = 'IPTAL'"
    )->fetchColumn();
    tfaAssert($raporIptal === 2, '15 RAPOR surecler IPTAL');

    // 16 P3 IZIN/POZISYON/BELGE
    $pP3 = tfaInsertPersonel($pdo, '99999999993', 'TF-P3');
    tfaClassifyFixture($pdo, $pP3, $gy);
    $pdo->exec(
        "INSERT INTO surecler (personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, state)
         VALUES
         ({$pP3}, 'IZIN', 'Yillik', '2026-08-01', '2026-08-05', 'AKTIF'),
         ({$pP3}, 'POZISYON', 'Degisim', '2026-08-01', '2026-08-01', 'AKTIF'),
         ({$pP3}, 'BELGE', 'Kimlik', '2026-08-01', '2026-08-01', 'AKTIF')"
    );
    $rP3 = TestFixturePersonelArchiveService::archive($pdo, $pP3, $gy);
    tfaAssert(($rP3['status'] ?? '') === 'ARCHIVED', '16 P3-style IZIN/POZISYON/BELGE passes');
    $p3Iptal = (int) $pdo->query(
        "SELECT COUNT(*) FROM surecler WHERE personel_id = {$pP3} AND state = 'IPTAL'"
    )->fetchColumn();
    tfaAssert($p3Iptal === 3, '16 P3 surecler IPTAL');

    // historical puantaj preserved
    $pHist = tfaInsertPersonel($pdo, '66666666661', 'TF-HIST');
    tfaClassifyFixture($pdo, $pHist, $gy);
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, gun_tipi)
         VALUES ({$pHist}, '2026-03-02', 'Normal_Is_Gunu')"
    );
    $rHist = TestFixturePersonelArchiveService::archive($pdo, $pHist, $gy);
    tfaAssert(($rHist['status'] ?? '') === 'ARCHIVED', 'historical archive PASS');
    $puantajLeft = (int) $pdo->query(
        "SELECT COUNT(*) FROM gunluk_puantaj WHERE personel_id = {$pHist}"
    )->fetchColumn();
    tfaAssert($puantajLeft === 1, '19 historical puantaj preserved');

    // sealed preserved
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
    tfaClassifyFixture($pdo, $pSeal, $gy);
    $pdo->exec(
        "INSERT INTO maas_hesaplama_personel_snapshotlari (personel_id, seal_hash, payload_json)
         VALUES ({$pSeal}, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '{\"sealed\":true}')"
    );
    $beforeSeal = $pdo->query(
        "SELECT seal_hash, payload_json FROM maas_hesaplama_personel_snapshotlari WHERE personel_id = {$pSeal}"
    )->fetch(PDO::FETCH_ASSOC);
    $r7 = TestFixturePersonelArchiveService::archive($pdo, $pSeal, $gy);
    tfaAssert(($r7['status'] ?? '') === 'ARCHIVED', 'sealed present archive PASS');
    $afterSeal = $pdo->query(
        "SELECT seal_hash, payload_json FROM maas_hesaplama_personel_snapshotlari WHERE personel_id = {$pSeal}"
    )->fetch(PDO::FETCH_ASSOC);
    tfaAssert(
        (string) $beforeSeal['seal_hash'] === (string) $afterSeal['seal_hash']
        && (string) $beforeSeal['payload_json'] === (string) $afterSeal['payload_json'],
        'sealed payroll preserved'
    );

    tfaAssert(
        !RolePermissions::has($muhasebe, 'personeller.test_fixture.archive'),
        'unauthorized archive role denied'
    );

    $svc = file_get_contents(__DIR__ . '/../../api/src/Services/Personel/TestFixturePersonelArchiveService.php');
    tfaAssert(strpos((string) $svc, 'personel_id === 1') === false, 'no hardcoded personel id eligibility');
    $surecSrc = file_get_contents(__DIR__ . '/../../api/src/Controllers/SureclerController.php');
    tfaAssert(strpos((string) $surecSrc, "surec_turu'] === 'ISTEN_AYRILMA'") !== false, 'ISTEN_AYRILMA path preserved');
    $manifestSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Retention/ArchiveManifestService.php');
    tfaAssert(
        strpos((string) $manifestSrc, 'CODE_TERMINATION_DATE_MISSING') !== false,
        'ISTEN_AYRILMA termination-date requirement preserved'
    );

    echo 'verify-test-fixture-personel-archive-mysql: OK' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
