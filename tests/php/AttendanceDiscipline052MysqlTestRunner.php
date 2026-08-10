<?php

declare(strict_types=1);

/**
 * Phase B: disposable MariaDB — disiplin vaka lifecycle + olay karar.
 * php tests/php/AttendanceDiscipline052MysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Attendance\AttendanceDisciplineCatalog;
use Medisa\Api\Services\Attendance\DisiplinAdayProjectionService;
use Medisa\Api\Services\Attendance\DisiplinVakaService;
use Medisa\Api\Services\Attendance\PuantajOlayKararService;

function ad052Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function ad052RootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function ad052SplitSql(string $sql): array
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

function ad052Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (ad052SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function ad052PdoForDb(string $database): PDO
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
        ]
    );
}

function ad052Seed(PDO $pdo): void
{
    $hash = password_hash('Ad052TestPass-24chars!!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec(
        "ALTER TABLE users MODIFY COLUMN rol ENUM(
            'GENEL_YONETICI','MUHASEBE','BIRIM_AMIRI','BOLUM_YONETICISI','IK_BORDRO'
        ) NOT NULL"
    );
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (1, 'bolum', '{$hash}', 'Bolum Yon', 'BOLUM_YONETICISI', 'AKTIF'),
        (2, 'ik', '{$hash}', 'IK User', 'IK_BORDRO', 'AKTIF'),
        (3, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES (
            10, '11111111111', 'Test', 'Personel', '1990-01-01', '05000000000', 'Acil', '05000000001',
            'S001', '2026-01-01', 1, 'AKTIF'
         )"
    );
}

function ad052InsertPuantaj(PDO $pdo, string $tarih, int $gec, int $bildirdi = 0): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO gunluk_puantaj (personel_id, tarih, state, durumu_bildirdi_mi, gec_kalma_dakika)
         VALUES (10, :tarih, \'ACIK\', :bildirdi, :gec)'
    );
    $stmt->execute(['tarih' => $tarih, 'bildirdi' => $bildirdi, 'gec' => $gec]);

    return (int) $pdo->lastInsertId();
}

function ad052ClearCases(PDO $pdo): void
{
    $pdo->exec('DELETE FROM disiplin_vaka_auditleri');
    $pdo->exec('DELETE FROM disiplin_vakalar');
    $pdo->exec('DELETE FROM surecler');
    $pdo->exec('DELETE FROM puantaj_olay_karar_auditleri');
    $pdo->exec('DELETE FROM puantaj_olay_kararlari');
    $pdo->exec('DELETE FROM gunluk_puantaj');
}

/** @return array<string, mixed> */
function ad052User($id, $rol)
{
    return ['id' => $id, 'rol' => $rol];
}

$root = ad052RootPdo();
$database = 'medisa_ad052_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = ad052PdoForDb($database);
    ad052Apply($pdo, '001_initial_schema.sql');
    ad052Apply($pdo, '002_puantaj_aylik_muhurleme.sql');
    ad052Apply($pdo, '005_gunluk_bildirimler.sql');
    ad052Apply($pdo, '012_gunluk_puantaj_gec_erken_dakika.sql');
    ad052Apply($pdo, '052_puantaj_tolerans_ve_disiplin.sql');
    // Idempotent re-apply of 052
    ad052Apply($pdo, '052_puantaj_tolerans_ve_disiplin.sql');
    ad052Seed($pdo);

    // projectForMonth: candidate created once, second skipped
    ad052InsertPuantaj($pdo, '2026-08-05', 25, 0);
    $userGenel = ad052User(3, 'GENEL_YONETICI');
    $first = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-08', 1, 10);
    ad052Assert($first['created_count'] === 1, 'projectForMonth creates one candidate');
    $second = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-08', 1, 10);
    ad052Assert($second['created_count'] === 0, 'projectForMonth second run skipped');
    ad052Assert($second['skipped_count'] >= 1, 'projectForMonth second run skipped count');

    // 60x3 monthly candidate
    ad052ClearCases($pdo);
    ad052InsertPuantaj($pdo, '2026-07-01', 60, 0);
    ad052InsertPuantaj($pdo, '2026-07-02', 60, 0);
    ad052InsertPuantaj($pdo, '2026-07-03', 60, 0);
    $monthly = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-07', 1, 10);
    $monthlyCount = 0;
    foreach ($monthly['items'] as $item) {
        if (($item['olay_turu'] ?? '') === AttendanceDisciplineCatalog::CANDIDATE_AYLIK_TEKRARLAYAN_GEC_KALMA) {
            $monthlyCount++;
        }
    }
    ad052Assert($monthlyCount === 1, '60x3 creates monthly candidate');

    // 59x3 no monthly
    ad052ClearCases($pdo);
    ad052InsertPuantaj($pdo, '2026-06-01', 59, 0);
    ad052InsertPuantaj($pdo, '2026-06-02', 59, 0);
    ad052InsertPuantaj($pdo, '2026-06-03', 59, 0);
    $noMonthly = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-06', 1, 10);
    $noMonthlyCount = 0;
    foreach ($noMonthly['items'] as $item) {
        if (($item['olay_turu'] ?? '') === AttendanceDisciplineCatalog::CANDIDATE_AYLIK_TEKRARLAYAN_GEC_KALMA) {
            $noMonthlyCount++;
        }
    }
    ad052Assert($noMonthlyCount === 0, '59x3 no monthly candidate');

    // lifecycle flow
    ad052ClearCases($pdo);
    ad052InsertPuantaj($pdo, '2026-08-10', 30, 0);
    $proj = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-08', 1, 10);
    ad052Assert(count($proj['items']) >= 1, 'lifecycle seed vaka');
    $vakaId = (int) $proj['items'][0]['id'];
    $userIk = ad052User(2, 'IK_BORDRO');
    $userBolum = ad052User(1, 'BOLUM_YONETICISI');

    $vaka = DisiplinVakaService::ikReview($pdo, $userIk, $vakaId);
    ad052Assert($vaka['lifecycle_state'] === AttendanceDisciplineCatalog::LIFECYCLE_IK_INCELEME, 'ikReview state');

    $vaka = DisiplinVakaService::requestDefense($pdo, $userIk, $vakaId, [
        'deadline_at' => '2020-01-01 09:00:00',
        'yer' => 'IK Ofis',
        'konu' => 'Gec kalma',
    ]);
    ad052Assert($vaka['lifecycle_state'] === AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR, 'requestDefense state');

    $vaka = DisiplinVakaService::getById($pdo, $vakaId);
    ad052Assert(
        in_array($vaka['lifecycle_state'], [
            AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI,
            AttendanceDisciplineCatalog::LIFECYCLE_KARAR_BEKLIYOR,
        ], true),
        'deadline past -> SAVUNMA_SUNULMADI or KARAR_BEKLIYOR'
    );

    $vaka = DisiplinVakaService::finalDecision($pdo, $userBolum, $vakaId, AttendanceDisciplineCatalog::NIHAI_KARAR_UYARI, 'test');
    ad052Assert($vaka['lifecycle_state'] === AttendanceDisciplineCatalog::LIFECYCLE_KAPANDI, 'finalDecision closes vaka');

    // IK cannot finalDecision
    ad052ClearCases($pdo);
    ad052InsertPuantaj($pdo, '2026-08-11', 20, 0);
    $proj2 = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-08', 1, 10);
    $vakaId2 = (int) $proj2['items'][0]['id'];
    DisiplinVakaService::ikReview($pdo, $userIk, $vakaId2);
    $pdo->prepare(
        'UPDATE disiplin_vakalar SET lifecycle_state = :state WHERE id = :id'
    )->execute(['state' => AttendanceDisciplineCatalog::LIFECYCLE_KARAR_BEKLIYOR, 'id' => $vakaId2]);
    $ikFinalFailed = false;
    try {
        DisiplinVakaService::finalDecision($pdo, $userIk, $vakaId2, AttendanceDisciplineCatalog::NIHAI_KARAR_UYARI);
    } catch (RuntimeException $e) {
        $ikFinalFailed = strpos($e->getMessage(), 'Nihai karar yetkisi yok') !== false;
    }
    ad052Assert($ikFinalFailed, 'IK cannot finalDecision');

    // closeNoAction works
    ad052ClearCases($pdo);
    ad052InsertPuantaj($pdo, '2026-08-12', 15, 0);
    $proj3 = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-08', 1, 10);
    $vakaId3 = (int) $proj3['items'][0]['id'];
    $closed = DisiplinVakaService::closeNoAction($pdo, $userIk, $vakaId3, 'test close');
    ad052Assert(
        $closed['lifecycle_state'] === AttendanceDisciplineCatalog::LIFECYCLE_ISLEMSIZ_KAPATILDI,
        'closeNoAction works'
    );

    // TOLERANS olay karar does not mutate gunluk_puantaj.gec_kalma_dakika
    $pdo->exec('DELETE FROM puantaj_olay_karar_auditleri');
    $pdo->exec('DELETE FROM puantaj_olay_kararlari');
    $gpId = ad052InsertPuantaj($pdo, '2026-08-15', 33, 1);
    PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
        'personel_id' => 10,
        'tarih' => '2026-08-15',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 33,
        'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'durumu_bildirdi_mi' => 1,
        'gunluk_puantaj_id' => $gpId,
    ]);
    $rawAfter = (int) $pdo->query(
        'SELECT gec_kalma_dakika FROM gunluk_puantaj WHERE id = ' . (int) $gpId
    )->fetchColumn();
    ad052Assert($rawAfter === 33, 'TOLERANS does not update gec_kalma_dakika');

    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_karar_auditleri')->fetchColumn();
    ad052Assert($auditCount >= 1, 'decision create writes audit');

    // late 36 TOLERANS rejected
    $pdo->exec('DELETE FROM puantaj_olay_karar_auditleri');
    $pdo->exec('DELETE FROM puantaj_olay_kararlari');
    ad052InsertPuantaj($pdo, '2026-08-16', 36, 1);
    $late36Failed = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2026-08-16',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 36,
            'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        ]);
    } catch (RuntimeException $e) {
        $late36Failed = strpos($e->getMessage(), 'VALIDATION_ERROR') !== false;
    }
    ad052Assert($late36Failed, 'late 36 TOLERANS rejected');

    // early TOLERANS rejected
    $pdo->prepare(
        'INSERT INTO gunluk_puantaj (personel_id, tarih, state, durumu_bildirdi_mi, gec_kalma_dakika, erken_cikis_dakika)
         VALUES (10, :tarih, \'ACIK\', 0, 0, 12)'
    )->execute(['tarih' => '2026-08-17']);
    $earlyFailed = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2026-08-17',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_ERKEN_CIKIS,
            'raw_dakika' => 12,
            'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        ]);
    } catch (RuntimeException $e) {
        $earlyFailed = strpos($e->getMessage(), 'VALIDATION_ERROR') !== false;
    }
    ad052Assert($earlyFailed, 'early TOLERANS rejected');

    // client raw mismatch rejected
    $mismatchFailed = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2026-08-15',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 10,
            'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
        ]);
    } catch (RuntimeException $e) {
        $mismatchFailed = strpos($e->getMessage(), 'VALIDATION_ERROR') !== false;
    }
    ad052Assert($mismatchFailed, 'client raw mismatch rejected');

    // GENEL_YONETICI cannot decide tolerance / final discipline
    $gyDecideFailed = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userGenel, [
            'personel_id' => 10,
            'tarih' => '2026-08-15',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 33,
            'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        ]);
    } catch (RuntimeException $e) {
        $gyDecideFailed = strpos($e->getMessage(), 'yetkisi yok') !== false;
    }
    ad052Assert($gyDecideFailed, 'GENEL_YONETICI cannot olay karar decide');

    ad052ClearCases($pdo);
    ad052InsertPuantaj($pdo, '2026-08-18', 22, 0);
    $projGy = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-08', 1, 10);
    $vakaGy = (int) $projGy['items'][0]['id'];
    $pdo->prepare(
        'UPDATE disiplin_vakalar SET lifecycle_state = :state WHERE id = :id'
    )->execute(['state' => AttendanceDisciplineCatalog::LIFECYCLE_KARAR_BEKLIYOR, 'id' => $vakaGy]);
    $gyFinalFailed = false;
    try {
        DisiplinVakaService::finalDecision($pdo, $userGenel, $vakaGy, AttendanceDisciplineCatalog::NIHAI_KARAR_UYARI, 'x');
    } catch (RuntimeException $e) {
        $gyFinalFailed = strpos($e->getMessage(), 'Nihai karar yetkisi yok') !== false;
    }
    ad052Assert($gyFinalFailed, 'GENEL_YONETICI cannot final discipline');

    // missing event cannot create decision
    $missingFailed = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2099-01-01',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 5,
            'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
        ]);
    } catch (RuntimeException $e) {
        $missingFailed = strpos($e->getMessage(), 'VALIDATION_ERROR') !== false;
    }
    ad052Assert($missingFailed, 'missing canonical event rejected');

    // audit table exists and empty of business seed after migration (checked via re-apply path)
    ad052Assert(PuantajOlayKararService::auditTableExists($pdo), 'puantaj_olay_karar_auditleri exists');

    // no statutory hardcode strings in Attendance services
    $attendanceDir = __DIR__ . '/../../api/src/Services/Attendance';
    foreach (glob($attendanceDir . '/*.php') ?: [] as $file) {
        $source = file_get_contents($file);
        if ($source === false) {
            continue;
        }
        ad052Assert(stripos($source, '3 gün') === false, 'no 3 gun in ' . basename($file));
        ad052Assert(stripos($source, 'kanuni') === false, 'no kanuni in ' . basename($file));
    }

    echo 'verify-attendance-discipline-052-mysql: OK' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
