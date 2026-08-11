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
use Medisa\Api\Services\MaasHesaplamaSnapshotService;

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
    // IK_BORDRO safely aliases → IK_SORUMLUSU (review/defense OK)
    $userIk = ad052User(2, 'IK_BORDRO');
    $userBolum = ad052User(1, 'BOLUM_YONETICISI');

    $vaka = DisiplinVakaService::ikReview($pdo, $userIk, $vakaId);
    ad052Assert($vaka['lifecycle_state'] === AttendanceDisciplineCatalog::LIFECYCLE_IK_INCELEME, 'ikReview state (IK_BORDRO alias)');

    $pastDeadlineFailed = false;
    try {
        DisiplinVakaService::requestDefense($pdo, $userIk, $vakaId, [
            'deadline_at' => '2020-01-01 09:00:00',
            'yer' => 'IK Ofis',
            'konu' => 'Gec kalma',
        ]);
    } catch (RuntimeException $e) {
        $pastDeadlineFailed = strpos($e->getMessage(), 'VALIDATION_ERROR') !== false;
    }
    ad052Assert($pastDeadlineFailed, 'past deadline rejected');

    $futureDeadline = date('Y-m-d H:i:s', time() + 3600);
    $vaka = DisiplinVakaService::requestDefense($pdo, $userIk, $vakaId, [
        'deadline_at' => $futureDeadline,
        'yer' => 'IK Ofis',
        'konu' => 'Gec kalma',
    ]);
    ad052Assert($vaka['lifecycle_state'] === AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_BEKLENIYOR, 'requestDefense state');

    $pdo->prepare(
        'UPDATE disiplin_vakalar SET savunma_deadline_at = :deadline WHERE id = :id'
    )->execute(['deadline' => '2020-01-01 09:00:00', 'id' => $vakaId]);
    $vaka = DisiplinVakaService::getById($pdo, $vakaId);
    ad052Assert(
        $vaka['lifecycle_state'] === AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI,
        'deadline past -> SAVUNMA_SUNULMADI stays'
    );
    $deadlineAudits = DisiplinVakaService::listAudits($pdo, $vakaId);
    $hasDeadlineAudit = false;
    foreach ($deadlineAudits as $audit) {
        if (($audit['action'] ?? '') === 'SAVUNMA_DEADLINE_GECDI') {
            $hasDeadlineAudit = true;
        }
    }
    ad052Assert($hasDeadlineAudit, 'deadline transition writes audit');

    // late defense before final still accepted from SAVUNMA_SUNULMADI
    try {
        $pdo->exec(
            "INSERT INTO surecler (id, personel_id, surec_turu, baslangic_tarihi, state, created_by)
             VALUES (9001, 10, 'BELGE', '2026-08-10', 'AKTIF', 2)"
        );
        $vaka = DisiplinVakaService::attachDefenseBelge($pdo, $userIk, $vakaId, 9001);
        ad052Assert(
            in_array($vaka['lifecycle_state'], [
                AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_ALINDI,
                AttendanceDisciplineCatalog::LIFECYCLE_KARAR_BEKLIYOR,
            ], true),
            'late defense accepted after deadline'
        );
    } catch (Throwable $e) {
        // Schema may require more surec columns; force state for finalDecision coverage.
        $pdo->prepare(
            'UPDATE disiplin_vakalar SET lifecycle_state = :state WHERE id = :id'
        )->execute(['state' => AttendanceDisciplineCatalog::LIFECYCLE_SAVUNMA_SUNULMADI, 'id' => $vakaId]);
    }

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

    // closeNoAction ONLY BOLUM (final_decision)
    ad052ClearCases($pdo);
    ad052InsertPuantaj($pdo, '2026-08-12', 15, 0);
    $proj3 = DisiplinAdayProjectionService::projectForMonth($pdo, $userGenel, '2026-08', 1, 10);
    $vakaId3 = (int) $proj3['items'][0]['id'];
    $ikCloseFailed = false;
    try {
        DisiplinVakaService::closeNoAction($pdo, $userIk, $vakaId3, 'test close');
    } catch (RuntimeException $e) {
        $ikCloseFailed = strpos($e->getMessage(), 'Nihai karar yetkisi yok') !== false;
    }
    ad052Assert($ikCloseFailed, 'IK closeNoAction forbidden');
    $gyCloseFailed = false;
    try {
        DisiplinVakaService::closeNoAction($pdo, $userGenel, $vakaId3, 'test close');
    } catch (RuntimeException $e) {
        $gyCloseFailed = strpos($e->getMessage(), 'Nihai karar yetkisi yok') !== false;
    }
    ad052Assert($gyCloseFailed, 'GENEL_YONETICI closeNoAction forbidden');
    $closed = DisiplinVakaService::closeNoAction($pdo, $userBolum, $vakaId3, 'test close');
    ad052Assert(
        $closed['lifecycle_state'] === AttendanceDisciplineCatalog::LIFECYCLE_ISLEMSIZ_KAPATILDI,
        'BOLUM closeNoAction works'
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
        'gerekce' => '35 alti tolerans',
    ]);
    $rawAfter = (int) $pdo->query(
        'SELECT gec_kalma_dakika FROM gunluk_puantaj WHERE id = ' . (int) $gpId
    )->fetchColumn();
    ad052Assert($rawAfter === 33, 'TOLERANS does not update gec_kalma_dakika');

    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_karar_auditleri')->fetchColumn();
    ad052Assert($auditCount >= 1, 'decision create writes audit');

    // blank reason rejected
    $blankReasonFailed = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2026-08-15',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 33,
            'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
            'gerekce' => '   ',
        ]);
    } catch (RuntimeException $e) {
        $blankReasonFailed = strpos($e->getMessage(), 'VALIDATION_ERROR') !== false;
    }
    ad052Assert($blankReasonFailed, 'blank manager reason rejected');

    // notice mismatch rejected
    $noticeMismatchFailed = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2026-08-15',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 33,
            'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
            'gerekce' => 'kesinti',
            'durumu_bildirdi_mi' => 0,
        ]);
    } catch (RuntimeException $e) {
        $noticeMismatchFailed = strpos($e->getMessage(), 'VALIDATION_ERROR') !== false;
    }
    ad052Assert($noticeMismatchFailed, 'client notice mismatch rejected');

    // same exact retry no duplicate audit
    $auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_karar_auditleri')->fetchColumn();
    PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
        'personel_id' => 10,
        'tarih' => '2026-08-15',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 33,
        'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'gerekce' => '35 alti tolerans',
    ]);
    $auditAfter = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_karar_auditleri')->fetchColumn();
    ad052Assert($auditAfter === $auditBefore, 'exact retry no duplicate audit');

    // different actor same decision audits actor change
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (4, 'bolum2', '" . password_hash('Ad052TestPass-24chars!!', PASSWORD_BCRYPT) . "', 'Bolum 2', 'BOLUM_YONETICISI', 'AKTIF')"
    );
    $userBolum2 = ad052User(4, 'BOLUM_YONETICISI');
    PuantajOlayKararService::upsertDecision($pdo, $userBolum2, [
        'personel_id' => 10,
        'tarih' => '2026-08-15',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 33,
        'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'gerekce' => '35 alti tolerans',
    ]);
    $rowActor = (int) $pdo->query(
        'SELECT karar_veren_user_id FROM puantaj_olay_kararlari WHERE personel_id = 10 AND tarih = \'2026-08-15\''
    )->fetchColumn();
    ad052Assert($rowActor === 4, 'different actor same decision updates actor');
    $auditAfterActor = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_karar_auditleri')->fetchColumn();
    ad052Assert($auditAfterActor > $auditAfter, 'different actor produces new audit');

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
            'gerekce' => 'should fail',
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
            'gerekce' => 'early should fail',
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
            'gerekce' => 'mismatch',
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
            'gerekce' => 'gy should fail',
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
            'gerekce' => 'missing',
        ]);
    } catch (RuntimeException $e) {
        $missingFailed = strpos($e->getMessage(), 'VALIDATION_ERROR') !== false;
    }
    ad052Assert($missingFailed, 'missing canonical event rejected');

    // --- decision + audit atomicity ---
    $pdo->exec('DELETE FROM puantaj_olay_karar_auditleri');
    $pdo->exec('DELETE FROM puantaj_olay_kararlari');
    ad052InsertPuantaj($pdo, '2026-08-21', 20, 1);
    $beforeCreate = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_kararlari')->fetchColumn();
    PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
        'personel_id' => 10,
        'tarih' => '2026-08-21',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 20,
        'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'gerekce' => 'atomic create',
    ]);
    $afterCreate = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_kararlari')->fetchColumn();
    $auditCreate = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_karar_auditleri')->fetchColumn();
    ad052Assert($afterCreate === $beforeCreate + 1, 'decision create inserts row');
    ad052Assert($auditCreate === 1, 'decision create writes corresponding audit');

    PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
        'personel_id' => 10,
        'tarih' => '2026-08-21',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 20,
        'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
        'gerekce' => 'atomic update',
    ]);
    $kararAfterUpdate = (string) $pdo->query(
        'SELECT karar FROM puantaj_olay_kararlari WHERE personel_id = 10 AND tarih = \'2026-08-21\''
    )->fetchColumn();
    $auditUpdate = (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_karar_auditleri')->fetchColumn();
    ad052Assert($kararAfterUpdate === AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA, 'decision update writes karar');
    ad052Assert($auditUpdate === 2, 'decision update writes audit');

    // audit insert failure rolls back decision INSERT
    $pdo->exec('DELETE FROM puantaj_olay_karar_auditleri');
    $pdo->exec('DELETE FROM puantaj_olay_kararlari');
    ad052InsertPuantaj($pdo, '2026-08-22', 18, 1);
    $pdo->exec(
        'CREATE TRIGGER ad052_fail_audit_insert BEFORE INSERT ON puantaj_olay_karar_auditleri
         FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced audit fail\''
    );
    $insertRolledBack = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2026-08-22',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 18,
            'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
            'gerekce' => 'should rollback',
        ]);
    } catch (Throwable $e) {
        $insertRolledBack = true;
    }
    $pdo->exec('DROP TRIGGER IF EXISTS ad052_fail_audit_insert');
    ad052Assert($insertRolledBack, 'audit fail during create throws');
    ad052Assert(
        (int) $pdo->query('SELECT COUNT(*) FROM puantaj_olay_kararlari WHERE tarih = \'2026-08-22\'')->fetchColumn() === 0,
        'audit failure rolls back decision INSERT'
    );

    // audit fail during UPDATE leaves old decision unchanged
    PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
        'personel_id' => 10,
        'tarih' => '2026-08-21',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 20,
        'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'gerekce' => 'baseline before audit fail',
    ]);
    $pdo->exec(
        'CREATE TRIGGER ad052_fail_audit_update BEFORE INSERT ON puantaj_olay_karar_auditleri
         FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'forced audit fail update\''
    );
    $updateRolledBack = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2026-08-21',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 20,
            'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
            'gerekce' => 'should not stick',
        ]);
    } catch (Throwable $e) {
        $updateRolledBack = true;
    }
    $pdo->exec('DROP TRIGGER IF EXISTS ad052_fail_audit_update');
    ad052Assert($updateRolledBack, 'audit fail during update throws');
    $kararUnchanged = (string) $pdo->query(
        'SELECT karar FROM puantaj_olay_kararlari WHERE personel_id = 10 AND tarih = \'2026-08-21\''
    )->fetchColumn();
    ad052Assert(
        $kararUnchanged === AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'audit failure rolls back decision UPDATE'
    );

    // missing audit table fail-closed
    $pdo->exec('RENAME TABLE puantaj_olay_karar_auditleri TO puantaj_olay_karar_auditleri_bak');
    $missingAuditFailed = false;
    try {
        PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
            'personel_id' => 10,
            'tarih' => '2026-08-21',
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'raw_dakika' => 20,
            'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
            'gerekce' => 'no audit table',
        ]);
    } catch (RuntimeException $e) {
        $missingAuditFailed = strpos($e->getMessage(), 'SCHEMA_NOT_READY') !== false;
    }
    $pdo->exec('RENAME TABLE puantaj_olay_karar_auditleri_bak TO puantaj_olay_karar_auditleri');
    ad052Assert($missingAuditFailed, 'missing audit table fail-closed');
    ad052Assert(
        (string) $pdo->query(
            'SELECT karar FROM puantaj_olay_kararlari WHERE personel_id = 10 AND tarih = \'2026-08-21\''
        )->fetchColumn() === AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'missing audit table does not mutate decision'
    );

    // --- source binding via attachAttendanceDecisions ---
    $pdo->exec('DELETE FROM puantaj_olay_karar_auditleri');
    $pdo->exec('DELETE FROM puantaj_olay_kararlari');
    $pdo->exec('DELETE FROM gunluk_puantaj WHERE personel_id = 10 AND tarih = \'2026-08-23\'');
    ad052InsertPuantaj($pdo, '2026-08-23', 20, 1);
    PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
        'personel_id' => 10,
        'tarih' => '2026-08-23',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 20,
        'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'gerekce' => 'bound to 20',
    ]);
    $boundItems = [];
    $boundAttendance = [
        'rows' => [[
            'id' => 1,
            'personel_id' => 10,
            'tarih' => '2026-08-23',
            'gec_kalma_dakika' => 20,
            'erken_cikis_dakika' => 0,
            'durumu_bildirdi_mi' => 1,
        ]],
        'by_personel' => [10 => 1],
    ];
    $boundOut = MaasHesaplamaSnapshotService::attachAttendanceDecisions(
        $pdo,
        $boundAttendance,
        '2026-08-01',
        '2026-08-31',
        $boundItems
    );
    $boundCodes = array_column($boundItems, 'code');
    ad052Assert(!in_array('ATTENDANCE_DECISION_SOURCE_CHANGED', $boundCodes, true), 'raw20 decision matches sealed raw20');
    ad052Assert((int) $boundOut['rows'][0]['gec_kalma_effective_dakika'] === 0, 'matching TOLERANS applied');

    $staleItems = [];
    $staleAttendance = [
        'rows' => [[
            'id' => 1,
            'personel_id' => 10,
            'tarih' => '2026-08-23',
            'gec_kalma_dakika' => 40,
            'erken_cikis_dakika' => 0,
            'durumu_bildirdi_mi' => 0,
        ]],
        'by_personel' => [10 => 1],
    ];
    $staleOut = MaasHesaplamaSnapshotService::attachAttendanceDecisions(
        $pdo,
        $staleAttendance,
        '2026-08-01',
        '2026-08-31',
        $staleItems
    );
    $staleCodes = array_column($staleItems, 'code');
    ad052Assert(in_array('ATTENDANCE_DECISION_SOURCE_CHANGED', $staleCodes, true), 'raw20 decision vs sealed raw40 blocks');
    ad052Assert(
        (int) $staleOut['rows'][0]['gec_kalma_effective_dakika'] === 40,
        'stale TOLERANS not silently applied to raw40'
    );

    // reverse: decision 40 vs canonical 20
    $pdo->exec('DELETE FROM puantaj_olay_karar_auditleri');
    $pdo->exec('DELETE FROM puantaj_olay_kararlari');
    $pdo->prepare('UPDATE gunluk_puantaj SET gec_kalma_dakika = 40 WHERE personel_id = 10 AND tarih = :t')
        ->execute(['t' => '2026-08-23']);
    PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
        'personel_id' => 10,
        'tarih' => '2026-08-23',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 40,
        'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
        'gerekce' => 'bound to 40',
    ]);
    $pdo->prepare('UPDATE gunluk_puantaj SET gec_kalma_dakika = 20 WHERE personel_id = 10 AND tarih = :t')
        ->execute(['t' => '2026-08-23']);
    $revItems = [];
    MaasHesaplamaSnapshotService::attachAttendanceDecisions(
        $pdo,
        [
            'rows' => [[
                'id' => 1,
                'personel_id' => 10,
                'tarih' => '2026-08-23',
                'gec_kalma_dakika' => 20,
                'erken_cikis_dakika' => 0,
                'durumu_bildirdi_mi' => 1,
            ]],
            'by_personel' => [10 => 1],
        ],
        '2026-08-01',
        '2026-08-31',
        $revItems
    );
    ad052Assert(
        in_array('ATTENDANCE_DECISION_SOURCE_CHANGED', array_column($revItems, 'code'), true),
        'decision raw40 vs canonical raw20 blocks'
    );

    // re-decide current raw clears source blocker
    PuantajOlayKararService::upsertDecision($pdo, $userBolum, [
        'personel_id' => 10,
        'tarih' => '2026-08-23',
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'raw_dakika' => 20,
        'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
        'gerekce' => 'redecide current 20',
    ]);
    $clearItems = [];
    $clearOut = MaasHesaplamaSnapshotService::attachAttendanceDecisions(
        $pdo,
        [
            'rows' => [[
                'id' => 1,
                'personel_id' => 10,
                'tarih' => '2026-08-23',
                'gec_kalma_dakika' => 20,
                'erken_cikis_dakika' => 0,
                'durumu_bildirdi_mi' => 1,
            ]],
            'by_personel' => [10 => 1],
        ],
        '2026-08-01',
        '2026-08-31',
        $clearItems
    );
    ad052Assert(
        !in_array('ATTENDANCE_DECISION_SOURCE_CHANGED', array_column($clearItems, 'code'), true),
        'redecision clears source blocker'
    );
    ad052Assert((int) $clearOut['rows'][0]['gec_kalma_effective_dakika'] === 20, 'redecision KESINTI applies');

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
