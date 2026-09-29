<?php

declare(strict_types=1);

/**
 * Canonical unresolved attendance anomaly contract.
 * php tests/php/AttendanceAnomalyFlowTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceCorrectionService;
use Medisa\Api\Services\Qr\QrAttendanceEventService;
use Medisa\Api\Services\Qr\QrAttendanceException;
use Medisa\Api\Services\Qr\QrAttendanceIntervalDerivationService;
use Medisa\Api\Services\Qr\QrAttendanceUnresolvedAnomalyService;
use Medisa\Api\Services\SelfService\PersonelInboxNotificationService;

function aaAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
        exit(1);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function aaIstanbul(string $local): DateTimeImmutable
{
    $dt = new DateTimeImmutable($local, new DateTimeZone('Europe/Istanbul'));

    return $dt;
}

$svc = QrAttendanceUnresolvedAnomalyService::class;

putenv('MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES');
aaAssert($svc::THRESHOLD_MINUTES === 180, 'threshold is 180 minutes');
aaAssert($svc::thresholdMinutes() === 180, 'default thresholdMinutes is 180');
putenv('MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES=5');
aaAssert($svc::thresholdMinutes() === 5, 'env override thresholdMinutes is 5');
$overrideWindow = $svc::buildWindow('2026-09-28', '08:30', '17:40');
aaAssert($overrideWindow !== null && $overrideWindow['threshold']->format('Y-m-d H:i') === '2026-09-28 17:45', 'env 5 builds exit + 5');
putenv('MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES=2000');
aaAssert($svc::thresholdMinutes() === 1440, 'env above 1440 clamps to 1440');
putenv('MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES=0');
aaAssert($svc::thresholdMinutes() === 180, 'non-positive env falls back to 180');
putenv('MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES');
aaAssert($svc::thresholdMinutes() === 180, 'cleared env restores 180');
aaAssert($svc::NO_EVENT_DAY_FINALIZATION_MINUTES === 30, 'NO_EVENT_DAY finalization is 30 minutes');
$noEventPure = $svc::buildNoEventFinalization('2026-09-28', '08:30', '17:40');
aaAssert($noEventPure !== null && $noEventPure['finalization']->format('Y-m-d H:i') === '2026-09-28 18:10', 'planned exit + 30');
aaAssert($svc::isOperationalNoEventDay(aaIstanbul('2026-09-28 18:09:00'), $noEventPure, true, false, false) === false, 'NO_EVENT +29 → no anomaly');
aaAssert($svc::isOperationalNoEventDay(aaIstanbul('2026-09-28 18:10:00'), $noEventPure, true, false, false) === true, 'NO_EVENT +30 → anomaly');
aaAssert($svc::isOperationalNoEventDay(aaIstanbul('2026-09-28 18:10:00'), $noEventPure, false, false, false) === false, 'NO_EVENT work not expected');
aaAssert($svc::isOperationalNoEventDay(aaIstanbul('2026-09-28 18:10:00'), $noEventPure, null, false, false) === false, 'NO_EVENT unknown expectation');
aaAssert($svc::isOperationalNoEventDay(aaIstanbul('2026-09-28 18:10:00'), $noEventPure, true, true, false) === false, 'NO_EVENT suppressed when an event exists');
aaAssert($svc::buildNoEventFinalization('2026-09-28', null, null) === null, 'NO_EVENT missing planned exit');
putenv('MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES=5');
aaAssert($svc::thresholdMinutes() === 5, 'open giriş env still overrides +180');
$openOverride = $svc::buildWindow('2026-09-28', '08:30', '17:40');
aaAssert($openOverride !== null && $openOverride['threshold']->format('H:i') === '17:45', 'env 5 moves only the open giriş threshold');
$noEventWhileEnv = $svc::buildNoEventFinalization('2026-09-28', '08:30', '17:40');
aaAssert($noEventWhileEnv !== null && $noEventWhileEnv['finalization']->format('H:i') === '18:10', 'env does not move NO_EVENT_DAY');
putenv('MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES');
aaAssert($svc::thresholdMinutes() === 180, 'env cleared again before sqlite');
aaAssert(!str_contains(file_get_contents(__DIR__ . '/../../api/src/Services/Qr/QrAttendanceUnresolvedAnomalyService.php') ?: '', '20:40'), 'no hardcoded 20:40');
aaAssert(QrAttendanceIntervalDerivationService::CORRECTION_HINT === 'GIRIS_CIKIS_DUZELTME', 'correction hint unchanged');

$day = $svc::buildWindow('2026-09-28', '08:30', '17:40');
aaAssert($day !== null, 'day window builds');
aaAssert($day['exit']->format('Y-m-d H:i') === '2026-09-28 17:40', 'day planned exit datetime');
aaAssert($day['threshold']->format('Y-m-d H:i') === '2026-09-28 20:40', 'day threshold = exit + 180');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-28 20:39:00'), $day, true, false) === false, 'threshold -1 min → no anomaly');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-28 20:40:00'), $day, true, false) === true, 'threshold instant → anomaly');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-28 20:41:00'), $day, true, false) === true, 'threshold +1 stays the same anomaly');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-28 20:40:00'), $day, true, true) === false, 'approved correction resolves anomaly');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-28 20:40:00'), $day, false, false) === false, 'work not expected → no anomaly');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-28 20:40:00'), $day, null, false) === false, 'unknown expectation → no anomaly');
aaAssert($svc::buildWindow('2026-09-28', '08:30', null) === null, 'missing planned exit → no window');
aaAssert($svc::buildWindow('2026-09-28', null, '17:40') === null, 'missing planned start → no invented overnight date');

$night = $svc::buildWindow('2026-09-28', '22:00', '06:00');
aaAssert($night !== null && $night['exit']->format('Y-m-d H:i') === '2026-09-29 06:00', 'night exit is next day 06:00');
aaAssert($night['threshold']->format('Y-m-d H:i') === '2026-09-29 09:00', 'night threshold is next day 09:00');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-29 02:00:00'), $night, true, false) === false, 'calendar change alone is not an anomaly');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-29 08:59:00'), $night, true, false) === false, 'night threshold -1 min → no anomaly');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-29 09:00:00'), $night, true, false) === true, 'night threshold instant → anomaly');

$nextNight = $svc::buildWindow('2026-09-29', '22:00', '06:00');
$afterMidnight = aaIstanbul('2026-09-29 00:30:00');
$matched = $svc::matchWindow($afterMidnight, [$night, $nextNight]);
aaAssert($matched !== null && $matched['anchor_date'] === '2026-09-28', 'post-midnight entry stays on the shift that contains it');

$early = aaIstanbul('2026-09-28 08:00:00');
$earlyMatch = $svc::matchWindow($early, [$day]);
aaAssert($earlyMatch !== null && $earlyMatch['anchor_date'] === '2026-09-28', 'early arrival still uses that day planned exit');
$overtimeEntry = aaIstanbul('2026-09-28 18:00:00');
$overtimeMatch = $svc::matchWindow($overtimeEntry, [$day]);
aaAssert($overtimeMatch !== null && $overtimeMatch['anchor_date'] === '2026-09-28', 'entry after planned exit stays on that shift');
aaAssert($overtimeMatch['threshold']->format('Y-m-d H:i') === '2026-09-28 20:40', 'after-exit entry uses planned exit + 180');
$yesterday = $svc::buildWindow('2026-09-27', '08:30', '17:40');
$nextMorning = $svc::matchWindow(aaIstanbul('2026-09-28 08:00:00'), [$yesterday, $day]);
aaAssert($nextMorning !== null && $nextMorning['anchor_date'] === '2026-09-28', 'next-morning early arrival does not bind to the previous shift');
$afterNightExit = $svc::matchWindow(aaIstanbul('2026-09-29 07:00:00'), [$night]);
aaAssert($afterNightExit !== null && $afterNightExit['anchor_date'] === '2026-09-28', 'entry after night exit stays on the night shift');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-29 08:59:00'), $afterNightExit, true, false) === false, 'night overtime re-entry before threshold stays live');
aaAssert($svc::isOperationalMissingCikis(aaIstanbul('2026-09-29 09:00:00'), $afterNightExit, true, false) === true, 'night overtime re-entry at threshold is an anomaly');
$amirBody = $svc::amirBody(
    ['ad_soyad' => 'Ayşe Demir'],
    [
        'business_date_label' => '28.09.2026',
        'context_local_time' => '08:30',
        'planned_exit_label' => '17:40',
        'problem' => 'Çıkış kaydı bulunamadı',
    ]
);
aaAssert(str_contains($amirBody, 'Ayşe Demir'), 'amir body names the personel');
aaAssert(str_contains($amirBody, '28.09.2026'), 'amir body names the date');
aaAssert(str_contains($amirBody, '08:30'), 'amir body names the open giriş');
aaAssert(str_contains($amirBody, '17:40'), 'amir body names planned exit');
aaAssert(str_contains($amirBody, 'Çıkış kaydı bulunamadı'), 'amir body names the missing exit');
aaAssert(str_contains($amirBody, 'Düzeltme gerekli'), 'amir body asks for a correction');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE qr_attendance_events (
        id INTEGER PRIMARY KEY,
        personel_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        occurred_at_utc TEXT NOT NULL,
        sube_id INTEGER NOT NULL
    )'
);
$pdo->exec(
    'CREATE TABLE gunluk_puantaj (
        id INTEGER PRIMARY KEY,
        personel_id INTEGER NOT NULL,
        tarih TEXT NOT NULL,
        beklenen_giris_saati TEXT,
        beklenen_cikis_saati TEXT,
        giris_saati TEXT,
        cikis_saati TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE surecler (
        id INTEGER PRIMARY KEY,
        personel_id INTEGER NOT NULL,
        surec_turu TEXT NOT NULL,
        alt_tur TEXT,
        state TEXT NOT NULL,
        baslangic_tarihi TEXT NOT NULL,
        bitis_tarihi TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE qr_attendance_correction_requests (
        id INTEGER PRIMARY KEY,
        personel_id INTEGER NOT NULL,
        source_event_id INTEGER,
        event_type TEXT NOT NULL,
        anomaly_type TEXT,
        business_date TEXT,
        status TEXT NOT NULL,
        explanation TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE personeller (
        id INTEGER PRIMARY KEY,
        ad TEXT,
        soyad TEXT,
        sube_id INTEGER,
        bolum_id INTEGER,
        birim_id INTEGER
    )'
);
$pdo->exec(
    'CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        rol TEXT,
        durum TEXT,
        personel_id INTEGER
    )'
);
$pdo->exec(
    'CREATE TABLE personel_inbox_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        recipient_user_id INTEGER NOT NULL,
        personel_id INTEGER,
        kind TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT NOT NULL,
        payload_json TEXT,
        anomaly_source_event_id INTEGER,
        anomaly_audience TEXT,
        anomaly_type TEXT,
        anomaly_business_date TEXT,
        related_correction_id INTEGER,
        status TEXT NOT NULL DEFAULT \'ACTIVE\',
        popup_required INTEGER NOT NULL DEFAULT 1,
        popup_consumed_at_utc TEXT,
        reminder_of_notification_id INTEGER,
        created_at_utc TEXT NOT NULL,
        UNIQUE (kind, anomaly_source_event_id, anomaly_audience)
    )'
);
$pdo->exec(
    "CREATE UNIQUE INDEX uq_pin_no_event_day
     ON personel_inbox_notifications (kind, personel_id, anomaly_type, anomaly_business_date, anomaly_audience)
     WHERE anomaly_type = 'NO_EVENT_DAY'"
);
$pdo->exec(
    "CREATE UNIQUE INDEX uq_qacr_pending_day
     ON qr_attendance_correction_requests (personel_id, business_date, anomaly_type)
     WHERE status = 'BEKLIYOR' AND source_event_id IS NULL AND anomaly_type IS NOT NULL"
);
$pdo->exec(
    'CREATE TABLE resmi_tatil_takvimi (
        id INTEGER PRIMARY KEY,
        tarih TEXT,
        tatil_kodu TEXT,
        tatil_adi TEXT,
        tatil_turu TEXT,
        gun_kapsami TEXT,
        tatil_interval_baslangic TEXT,
        tatil_interval_bitis TEXT,
        durum TEXT,
        kaynak_turu TEXT,
        kaynak_referansi TEXT,
        kaynak_tarihi TEXT,
        aciklama TEXT,
        revizyon_no INTEGER,
        onceki_kayit_id INTEGER,
        yapan_kullanici_id INTEGER,
        yapan_ad TEXT,
        iptal_edildi_at TEXT,
        iptal_eden_kullanici_id INTEGER,
        iptal_gerekcesi TEXT,
        created_at TEXT,
        updated_at TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE sirket_calisma_politikalari (
        id INTEGER PRIMARY KEY,
        revision_no INTEGER,
        parent_politika_id INTEGER,
        state TEXT,
        gecerlilik_baslangic TEXT,
        gecerlilik_bitis TEXT,
        aciklama TEXT,
        belge_id TEXT,
        belge_sha256 TEXT,
        policy_version_hash TEXT,
        hazirlayan_id INTEGER,
        onaylayan_id INTEGER,
        onay_zamani TEXT,
        created_at TEXT,
        updated_at TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE sirket_calisma_politika_degerleri (
        id INTEGER PRIMARY KEY,
        politika_id INTEGER,
        parametre_kodu TEXT,
        deger_tipi TEXT,
        sayisal_deger TEXT,
        metin_deger TEXT,
        birim TEXT
    )'
);
$pdo->exec("INSERT INTO sirket_calisma_politikalari (id, revision_no, state, gecerlilik_baslangic, created_at, updated_at) VALUES (1, 3, 'ONAYLANDI', '2020-01-01', '2020-01-01', '2020-01-01')");
$pdo->exec("INSERT INTO sirket_calisma_politika_degerleri (id, politika_id, parametre_kodu, deger_tipi, metin_deger) VALUES (1, 1, 'HAFTA_TATILI_GUNLERI', 'METIN', '0')");

$pdo->exec("INSERT INTO personeller (id, ad, soyad, sube_id) VALUES (7, 'Ayşe', 'Demir', 1)");
$pdo->exec("INSERT INTO users (id, rol, durum, personel_id) VALUES (15, 'PERSONEL', 'AKTIF', 7)");
$pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (1, 7, '2026-09-28', '08:30', '17:40')");
$pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (2, 7, '2026-09-29', '08:30', '17:40')");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (101, 7, 15, 'GIRIS', '2026-09-28 05:30:00.000000', 1)");

$before = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:39:00'));
aaAssert(count($before) === 0, 'sqlite threshold -1 → empty list');
$at = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'));
aaAssert(count($at) === 1, 'sqlite threshold instant → 1 anomaly');
aaAssert($at[0]['identity'] === 'MISSING_CIKIS:101', 'identity is anomaly type plus source event');
aaAssert($at[0]['correction_hint'] === 'GIRIS_CIKIS_DUZELTME', 'list keeps correction hint');
aaAssert($at[0]['problem'] === 'Çıkış kaydı bulunamadı', 'missing exit problem label');
aaAssert($at[0]['context_local_time'] === '08:30', 'existing giriş is context');
aaAssert($at[0]['pending_request_id'] === null, 'no pending request yet');

$first = $svc::ensureNotifications($pdo, 7, $at, aaIstanbul('2026-09-28 20:40:00'));
$second = $svc::ensureNotifications($pdo, 7, $at, aaIstanbul('2026-09-28 20:41:00'));
aaAssert((int) $first['created'] === 1, 'first tick creates the personel notification');
aaAssert((int) $second['created'] === 0, 'next tick does not duplicate');
$personelNotes = (int) $pdo->query("SELECT COUNT(*) FROM personel_inbox_notifications WHERE anomaly_audience = 'PERSONEL'")->fetchColumn();
$amirNotes = (int) $pdo->query("SELECT COUNT(*) FROM personel_inbox_notifications WHERE anomaly_audience = 'AMIR'")->fetchColumn();
aaAssert($personelNotes === 1, 'one personel notification');
aaAssert($amirNotes === 0, 'approver unresolved → no amir notification');
$note = $pdo->query('SELECT title, body, payload_json FROM personel_inbox_notifications LIMIT 1')->fetch(PDO::FETCH_ASSOC);
aaAssert(is_array($note) && $note['title'] === 'Olağan Dışı Giriş/Çıkış Kaydı', 'personel notification title');
aaAssert($note['body'] === 'Talep oluşturarak amirinizle görüşebilirsiniz.', 'personel notification body');
aaAssert(!str_contains((string) $note['body'], 'Günlük Çalışma Süresi Doldu'), 'inbox body is not the home warning');
$payload = json_decode((string) $note['payload_json'], true);
aaAssert(is_array($payload) && (int) $payload['source_event_id'] === 101, 'payload source_event_id');
aaAssert(($payload['anomaly_type'] ?? '') === 'MISSING_CIKIS', 'payload anomaly type');

$again = PersonelInboxNotificationService::createAnomaly(
    $pdo,
    15,
    $svc::NOTIFICATION_KIND,
    $svc::PERSONEL_TITLE,
    $svc::PERSONEL_BODY,
    7,
    101,
    $svc::AUDIENCE_PERSONEL,
    ['source_event_id' => 101]
);
aaAssert($again === 0, 'unique key rejects a racing second insert');
$amir = PersonelInboxNotificationService::createAnomaly(
    $pdo,
    90,
    $svc::NOTIFICATION_KIND,
    $svc::PERSONEL_TITLE,
    'Ayşe Demir bağlamı',
    7,
    101,
    $svc::AUDIENCE_AMIR,
    ['source_event_id' => 101]
);
aaAssert($amir > 0, 'amir audience is a separate notification');
$amirAgain = PersonelInboxNotificationService::createAnomaly(
    $pdo,
    90,
    $svc::NOTIFICATION_KIND,
    $svc::PERSONEL_TITLE,
    'Ayşe Demir bağlamı',
    7,
    101,
    $svc::AUDIENCE_AMIR,
    ['source_event_id' => 101]
);
aaAssert($amirAgain === 0, 'amir notification is also unique per source event');

$pdo->exec("INSERT INTO qr_attendance_correction_requests (id, personel_id, source_event_id, event_type, status) VALUES (5, 7, 101, 'CIKIS', 'BEKLIYOR')");
$pending = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'));
aaAssert(count($pending) === 1 && (int) $pending[0]['pending_request_id'] === 5, 'pending request keeps the anomaly and exposes its id');
$pdo->exec("UPDATE qr_attendance_correction_requests SET status = 'ONAYLANDI' WHERE id = 5");
$resolved = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'));
aaAssert(count($resolved) === 0, 'approved çıkış correction drops the anomaly');
$eventCount = (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events')->fetchColumn();
$cikisCount = (int) $pdo->query("SELECT COUNT(*) FROM qr_attendance_events WHERE event_type = 'CIKIS'")->fetchColumn();
aaAssert($eventCount === 1 && $cikisCount === 0, 'raw QR events were not mutated');

$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (102, 7, 15, 'GIRIS', '2026-09-29 05:30:00.000000', 1)");
$two = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-29 20:40:00'));
aaAssert(count($two) === 1 && $two[0]['source_event_id'] === 102, 'resolved source stays closed; the new open shift can anomaly on its own day');

$pdo->exec('DELETE FROM qr_attendance_events');
$pdo->exec('DELETE FROM qr_attendance_correction_requests');
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (201, 7, 15, 'GIRIS', '2026-09-28 05:30:00.000000', 1)");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (202, 7, 15, 'CIKIS', '2026-09-28 09:00:00.000000', 1)");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (203, 7, 15, 'GIRIS', '2026-09-28 10:00:00.000000', 1)");
$reentry = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 14:00:00'));
aaAssert(count($reentry) === 0, 'same-day re-entry before threshold is not an anomaly');
aaAssert($svc::openGirisBlocksNextGiris($pdo, 7, '2026-09-28 10:00:00.000000', aaIstanbul('2026-09-28 14:00:00')) === true, 'live open shift still blocks the next giriş');

$pdo->exec('DELETE FROM qr_attendance_events');
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (301, 7, 15, 'GIRIS', '2026-09-28 05:30:00.000000', 1)");
$staleBlocks = $svc::openGirisBlocksNextGiris($pdo, 7, '2026-09-28 05:30:00.000000', aaIstanbul('2026-09-29 08:00:00'));
aaAssert($staleBlocks === false, 'previous-day open giriş past threshold does not block today');
$staleList = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-29 08:00:00'));
aaAssert(count($staleList) === 1 && $staleList[0]['business_date'] === '2026-09-28', 'previous day remains a missing-exit anomaly');

$pdo->exec('DELETE FROM qr_attendance_events');
$pdo->exec("UPDATE gunluk_puantaj SET beklenen_giris_saati = '22:00', beklenen_cikis_saati = '06:00' WHERE id = 1");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (401, 7, 15, 'GIRIS', '2026-09-28 19:00:00.000000', 1)");
aaAssert($svc::openGirisBlocksNextGiris($pdo, 7, '2026-09-28 19:00:00.000000', aaIstanbul('2026-09-29 02:00:00')) === true, 'cross-midnight shift is still live at 02:00');
aaAssert(count($svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-29 02:00:00'))) === 0, 'cross-midnight 02:00 → no anomaly');
aaAssert($svc::openGirisBlocksNextGiris($pdo, 7, '2026-09-28 19:00:00.000000', aaIstanbul('2026-09-29 09:00:00')) === false, 'cross-midnight threshold releases the next giriş');

$pdo->exec("UPDATE gunluk_puantaj SET beklenen_cikis_saati = NULL WHERE personel_id = 7");
aaAssert($svc::openGirisBlocksNextGiris($pdo, 7, '2026-09-28 19:00:00.000000', aaIstanbul('2026-09-29 12:00:00')) === true, 'no planned exit keeps the open shift blocking');
aaAssert(count($svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-29 12:00:00'))) === 0, 'no planned exit → no +180 anomaly');

$pdo->exec("UPDATE gunluk_puantaj SET beklenen_giris_saati = '08:30', beklenen_cikis_saati = '17:40' WHERE id = 1");
$pdo->exec("INSERT INTO surecler (id, personel_id, surec_turu, alt_tur, state, baslangic_tarihi, bitis_tarihi) VALUES (1, 7, 'IZIN', NULL, 'AKTIF', '2026-09-28', '2026-09-28')");
$pdo->exec('DELETE FROM qr_attendance_events');
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (501, 7, 15, 'GIRIS', '2026-09-28 05:30:00.000000', 1)");
aaAssert(count($svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'))) === 0, 'IZINLI → no anomaly');
$pdo->exec("UPDATE surecler SET surec_turu = 'RAPOR' WHERE id = 1");
aaAssert(count($svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'))) === 0, 'RAPORLU → no anomaly');
$pdo->exec("UPDATE surecler SET surec_turu = 'IS_KAZASI' WHERE id = 1");
aaAssert(count($svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'))) === 0, 'IS_KAZASI → no anomaly');
$pdo->exec("UPDATE surecler SET surec_turu = 'DEVAMSIZLIK', alt_tur = 'IZINSIZ_GELMEDI' WHERE id = 1");
aaAssert(count($svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'))) === 0, 'GELMEDI → no anomaly');

$pdo->exec('DELETE FROM surecler');
$pdo->exec('DELETE FROM qr_attendance_events');
$pdo->exec('DELETE FROM qr_attendance_correction_requests');
$pdo->exec('DELETE FROM personel_inbox_notifications');
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (601, 7, 15, 'GIRIS', '2026-09-28 05:30:00.000000', 1)");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (602, 7, 15, 'CIKIS', '2026-09-28 14:40:00.000000', 1)");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (603, 7, 15, 'GIRIS', '2026-09-28 15:00:00.000000', 1)");
aaAssert(count($svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:39:00'))) === 0, '18:00 re-entry at threshold -1 → no anomaly');
aaAssert($svc::openGirisBlocksNextGiris($pdo, 7, '2026-09-28 15:00:00.000000', aaIstanbul('2026-09-28 20:39:00')) === true, '18:00 re-entry at threshold -1 still blocks');
$overtimeAnomaly = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'));
aaAssert(count($overtimeAnomaly) === 1 && (int) $overtimeAnomaly[0]['source_event_id'] === 603, '18:00 re-entry at threshold → missing çıkış');
aaAssert($svc::openGirisBlocksNextGiris($pdo, 7, '2026-09-28 15:00:00.000000', aaIstanbul('2026-09-28 20:40:00')) === false, '18:00 re-entry at threshold does not block the next giriş');
aaAssert($svc::openGirisBlocksNextGiris($pdo, 7, '2026-09-28 15:00:00.000000', aaIstanbul('2026-09-29 08:30:00')) === false, 'stale 18:00 open shift does not block the next shift giriş');
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (604, 7, 15, 'GIRIS', '2026-09-29 05:30:00.000000', 1)");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (605, 7, 15, 'CIKIS', '2026-09-29 14:40:00.000000', 1)");
$closedOnNewShift = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-29 20:40:00'));
aaAssert(count($closedOnNewShift) === 1 && (int) $closedOnNewShift[0]['source_event_id'] === 603, 'next shift çıkış does not close the stale 18:00 giriş');

$pdo->exec('DELETE FROM qr_attendance_events');
$pdo->exec('DELETE FROM qr_attendance_correction_requests');
$pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (9, 7, '2026-09-19', '08:30', '17:40')");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (701, 7, 15, 'GIRIS', '2026-09-19 05:30:00.000000', 1)");
$agedNow = aaIstanbul('2026-09-28 20:40:00');
$agedCutoff = $agedNow->setTimezone(new DateTimeZone('UTC'))->modify('-8 days');
$agedEvent = new DateTimeImmutable('2026-09-19 05:30:00', new DateTimeZone('UTC'));
aaAssert($agedEvent < $agedCutoff, 'aged fixture is outside the 8-day discovery window');
aaAssert($svc::LOOKBACK_DAYS === 8, 'discovery window stays 8 days');
$aged = $svc::listForPersonel($pdo, 7, $agedNow);
$agedIdentities = array_map(static function (array $item): string {
    return (string) $item['identity'];
}, $aged);
aaAssert(in_array('MISSING_CIKIS:701', $agedIdentities, true), '9-day unresolved anomaly stays visible');
aaAssert(in_array('NO_EVENT_DAY:2026-09-28', $agedIdentities, true), 'zero-scan workday inside the window is NO_EVENT_DAY');
$later = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-10-05 20:40:00'));
$laterIdentities = array_map(static function (array $item): string {
    return (string) $item['identity'];
}, $later);
aaAssert(in_array('MISSING_CIKIS:701', $laterIdentities, true), 'time passing alone does not close an unresolved anomaly');
$firstScan = $svc::scan($pdo, $agedNow);
$secondScan = $svc::scan($pdo, aaIstanbul('2026-09-28 20:45:00'));
aaAssert((int) $firstScan['created'] === 2, 'scan notifies the aged missing çıkış and the zero-scan day once each');
aaAssert((int) $secondScan['created'] === 0, 'later scan does not duplicate the aged anomaly notification');
$agedNotes = (int) $pdo->query("SELECT COUNT(*) FROM personel_inbox_notifications WHERE anomaly_source_event_id = 701")->fetchColumn();
aaAssert($agedNotes === 1, 'aged anomaly still has one personel notification');
$dayNotes = (int) $pdo->query("SELECT COUNT(*) FROM personel_inbox_notifications WHERE anomaly_type = 'NO_EVENT_DAY' AND anomaly_business_date = '2026-09-28'")->fetchColumn();
aaAssert($dayNotes === 1, 'zero-scan day has one notification chain');
$pdo->exec("INSERT INTO qr_attendance_correction_requests (id, personel_id, source_event_id, event_type, status) VALUES (9, 7, 701, 'CIKIS', 'ONAYLANDI')");
$closedAged = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-10-05 20:40:00'));
$closedAgedIdentities = array_map(static function (array $item): string {
    return (string) $item['identity'];
}, $closedAged);
aaAssert(!in_array('MISSING_CIKIS:701', $closedAgedIdentities, true), 'approved correction closes the aged anomaly');
$agedRaw = (int) $pdo->query("SELECT COUNT(*) FROM qr_attendance_events WHERE id = 701 AND event_type = 'GIRIS'")->fetchColumn();
aaAssert($agedRaw === 1, 'closing an aged anomaly does not mutate the raw giriş');

function aaIdentities(array $items): array
{
    return array_map(static function (array $item): string {
        return (string) $item['identity'];
    }, $items);
}

$pdo->exec("INSERT INTO personeller (id, ad, soyad, sube_id, bolum_id, birim_id) VALUES (8, 'Can', 'Yılmaz', 1, 2, 3)");
$pdo->exec("INSERT INTO users (id, rol, durum, personel_id) VALUES (16, 'PERSONEL', 'AKTIF', 8)");
$pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (26, 8, '2026-09-26', '09:00', '18:00')");
$pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (27, 8, '2026-09-27', '09:00', '18:00')");
$pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (28, 8, '2026-09-21', '09:00', '18:00')");

$satBefore = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:29:00'));
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($satBefore), true), 'Saturday before planned exit + 30 → no NO_EVENT_DAY');
$satAt = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'));
aaAssert(in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($satAt), true), 'Saturday at planned exit + 30 → NO_EVENT_DAY');
aaAssert($satAt[0]['problem'] === 'Giriş kaydı bulunamadı', 'Saturday problem is missing giriş');
aaAssert($satAt[0]['source_event_id'] === null, 'NO_EVENT_DAY has no source QR event');

$sun = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-27 23:00:00'));
aaAssert(!in_array('NO_EVENT_DAY:2026-09-27', aaIdentities($sun), true), 'Sunday → no NO_EVENT_DAY');

$pdo->exec("INSERT INTO resmi_tatil_takvimi (id, tarih, tatil_kodu, tatil_adi, tatil_turu, gun_kapsami, durum, kaynak_turu, kaynak_referansi, revizyon_no, created_at, updated_at) VALUES (1, '2026-09-21', 'UBGT', 'Test UBGT', 'UBGT', 'TAM_GUN', 'AKTIF', 'TEST', 'TEST', 1, '2026-01-01', '2026-01-01')");
$ubgt = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-21 19:00:00'));
aaAssert(!in_array('NO_EVENT_DAY:2026-09-21', aaIdentities($ubgt), true), 'UBGT → no NO_EVENT_DAY');
$pdo->exec('DELETE FROM resmi_tatil_takvimi');
$ubgtCleared = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-21 19:00:00'));
aaAssert(in_array('NO_EVENT_DAY:2026-09-21', aaIdentities($ubgtCleared), true), 'same Monday without UBGT → NO_EVENT_DAY');

$pdo->exec("INSERT INTO surecler (id, personel_id, surec_turu, alt_tur, state, baslangic_tarihi, bitis_tarihi) VALUES (8, 8, 'IZIN', NULL, 'AKTIF', '2026-09-26', '2026-09-26')");
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'))), true), 'izin → no NO_EVENT_DAY');
$pdo->exec("UPDATE surecler SET surec_turu = 'RAPOR' WHERE id = 8");
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'))), true), 'rapor → no NO_EVENT_DAY');
$pdo->exec("UPDATE surecler SET surec_turu = 'IS_KAZASI' WHERE id = 8");
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'))), true), 'iş kazası → no NO_EVENT_DAY');
$pdo->exec("UPDATE surecler SET surec_turu = 'DEVAMSIZLIK', alt_tur = 'IZINSIZ_GELMEDI' WHERE id = 8");
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'))), true), 'recorded absence → no NO_EVENT_DAY');
$pdo->exec('DELETE FROM surecler WHERE id = 8');

$pdo->exec("UPDATE sirket_calisma_politikalari SET state = 'TASLAK' WHERE id = 1");
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'))), true), 'unresolved calendar → no NO_EVENT_DAY');
$pdo->exec("UPDATE sirket_calisma_politikalari SET state = 'ONAYLANDI' WHERE id = 1");
aaAssert(in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'))), true), 'resolved calendar restores Saturday NO_EVENT_DAY');

$pdo->exec("UPDATE gunluk_puantaj SET beklenen_cikis_saati = NULL WHERE id = 26");
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'))), true), 'no planned exit → no NO_EVENT_DAY');
$pdo->exec("UPDATE gunluk_puantaj SET beklenen_cikis_saati = '18:00' WHERE id = 26");

$rawBeforeOpen = (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events')->fetchColumn();
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (801, 8, 16, 'GIRIS', '2026-09-26 06:00:00.000000', 1)");
$openEarly = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 20:59:00'));
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($openEarly), true), 'open giriş is not NO_EVENT_DAY');
aaAssert(!in_array('MISSING_CIKIS:801', aaIdentities($openEarly), true), 'open giriş at planned exit +179 → no missing çıkış');
$openAt = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 21:00:00'));
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($openAt), true), 'open giriş at +180 is still not NO_EVENT_DAY');
aaAssert(in_array('MISSING_CIKIS:801', aaIdentities($openAt), true), 'open giriş at planned exit +180 → missing çıkış');

$pdo->exec('DELETE FROM qr_attendance_events WHERE id = 801');
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (802, 8, 16, 'CIKIS', '2026-09-26 15:00:00.000000', 1)");
$orphan = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 21:00:00'));
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($orphan), true), 'orphan çıkış is not NO_EVENT_DAY');
aaAssert(in_array('MISSING_GIRIS:802', aaIdentities($orphan), true), 'orphan çıkış stays MISSING_GIRIS');

$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (803, 8, 16, 'GIRIS', '2026-09-26 06:00:00.000000', 1)");
$complete = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 21:00:00'));
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($complete), true), 'complete attendance is not NO_EVENT_DAY');
aaAssert(!in_array('MISSING_CIKIS:803', aaIdentities($complete), true), 'paired giriş is not missing çıkış');
aaAssert(!in_array('MISSING_GIRIS:802', aaIdentities($complete), true), 'paired çıkış is not missing giriş');
$pdo->exec('DELETE FROM qr_attendance_events WHERE personel_id = 8');
aaAssert((int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events')->fetchColumn() === $rawBeforeOpen, 'matrix did not leave synthetic QR rows');

$zero = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'));
aaAssert(in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($zero), true), 'zero events after cleanup → NO_EVENT_DAY again');
$notesBefore = (int) $pdo->query("SELECT COUNT(*) FROM personel_inbox_notifications WHERE personel_id = 8 AND anomaly_type = 'NO_EVENT_DAY'")->fetchColumn();
$svc::scan($pdo, aaIstanbul('2026-09-26 18:30:00'));
$svc::scan($pdo, aaIstanbul('2026-09-26 18:35:00'));
$notesAfter = (int) $pdo->query("SELECT COUNT(*) FROM personel_inbox_notifications WHERE personel_id = 8 AND anomaly_type = 'NO_EVENT_DAY' AND anomaly_business_date = '2026-09-26'")->fetchColumn();
aaAssert($notesBefore === 0 && $notesAfter === 1, 'repeated scan creates one NO_EVENT_DAY notification');
$unresolvedCount = 0;
foreach ($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00')) as $item) {
    if ((string) $item['anomaly_type'] === 'NO_EVENT_DAY' && (string) $item['business_date'] === '2026-09-26') {
        $unresolvedCount++;
    }
}
aaAssert($unresolvedCount === 1, 'one unresolved NO_EVENT_DAY item');

$agedDay = $svc::listForPersonel($pdo, 8, aaIstanbul('2026-10-20 18:30:00'));
aaAssert(in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($agedDay), true), 'notified NO_EVENT_DAY stays visible outside lookback');

$pdo->exec("INSERT INTO qr_attendance_correction_requests (id, personel_id, source_event_id, event_type, anomaly_type, business_date, status, explanation) VALUES (80, 8, NULL, 'GIRIS', 'NO_EVENT_DAY', '2026-09-26', 'BEKLIYOR', '08:32 de geldim, okutmayi unuttum')");
$pendingDay = null;
foreach ($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00')) as $item) {
    if ((string) $item['identity'] === 'NO_EVENT_DAY:2026-09-26') {
        $pendingDay = $item;
    }
}
aaAssert(is_array($pendingDay) && (int) $pendingDay['pending_request_id'] === 80, 'pending day correction stays on the anomaly');
$duplicateBlocked = false;
try {
    $pdo->exec("INSERT INTO qr_attendance_correction_requests (id, personel_id, source_event_id, event_type, anomaly_type, business_date, status, explanation) VALUES (81, 8, NULL, 'GIRIS', 'NO_EVENT_DAY', '2026-09-26', 'BEKLIYOR', 'ikinci talep')");
} catch (Throwable $e) {
    $duplicateBlocked = true;
}
aaAssert($duplicateBlocked, 'second active NO_EVENT_DAY request is rejected');
$storedExplanation = (string) $pdo->query("SELECT explanation FROM qr_attendance_correction_requests WHERE id = 80")->fetchColumn();
aaAssert(str_contains($storedExplanation, 'okutmayi unuttum'), 'intended explanation is stored on the correction');
$pdo->exec("UPDATE qr_attendance_correction_requests SET status = 'ONAYLANDI' WHERE id = 80");
$pdo->exec("UPDATE gunluk_puantaj SET giris_saati = '08:32' WHERE id = 26");
aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($svc::listForPersonel($pdo, 8, aaIstanbul('2026-09-26 18:30:00'))), true), 'approved giriş correction clears NO_EVENT_DAY');
aaAssert((int) $pdo->query("SELECT COUNT(*) FROM qr_attendance_events WHERE personel_id = 8")->fetchColumn() === 0, 'approved NO_EVENT_DAY correction creates no raw QR');
aaAssert($svc::dayKeySchemaReady($pdo) === true, 'schema 094 day-key capability is ready');

// BL-POST-THRESHOLD-REENTRY: canonical open-shift owner (QrAttendanceEventService::resolveOpenShiftState).
$pdo->exec('DELETE FROM qr_attendance_events');
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (901, 7, 15, 'GIRIS', '2026-09-28 05:30:00.000000', 1)");
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (902, 7, 15, 'CIKIS', '2026-09-28 14:40:00.000000', 1)");
$afterCompleted = QrAttendanceEventService::resolveOpenShiftState($pdo, 7, aaIstanbul('2026-09-28 22:00:00'));
aaAssert(
    $afterCompleted['next_action'] === 'GIRIS'
        && ($afterCompleted['stale_missing_cikis'] ?? true) === false,
    'completed GIRIS→CIKIS same day allows a new independent GIRIS (not continuation)'
);
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (903, 7, 15, 'GIRIS', '2026-09-28 15:00:00.000000', 1)");
$liveOvertime = QrAttendanceEventService::resolveOpenShiftState($pdo, 7, aaIstanbul('2026-09-28 20:39:00'));
aaAssert(
    $liveOvertime['next_action'] === 'CIKIS'
        && ($liveOvertime['stale_missing_cikis'] ?? false) === false,
    'live open GIRIS before threshold still requires CIKIS first'
);
$staleOvertime = QrAttendanceEventService::resolveOpenShiftState($pdo, 7, aaIstanbul('2026-09-28 20:40:00'));
aaAssert(
    $staleOvertime['next_action'] === 'GIRIS'
        && ($staleOvertime['stale_missing_cikis'] ?? false) === true,
    'post-threshold stale open GIRIS does not block the next GIRIS'
);
$pdo->exec('DELETE FROM qr_attendance_events');
$pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (904, 7, 15, 'GIRIS', '2026-09-28 05:30:00.000000', 1)");
$stalePriorDay = QrAttendanceEventService::resolveOpenShiftState($pdo, 7, aaIstanbul('2026-09-29 08:00:00'));
aaAssert(
    $stalePriorDay['next_action'] === 'GIRIS'
        && ($stalePriorDay['stale_missing_cikis'] ?? false) === true,
    'prior-day missing exit past threshold releases next GIRIS'
);

aaRunSchema093($svc);

echo '[OK] AttendanceAnomalyFlowTestRunner' . PHP_EOL;

/**
 * Production window: code deployed, migration 094 not applied.
 * Event anomalies stay live. NO_EVENT_DAY does not discover, notify, or insert.
 */
function aaRunSchema093(string $svc): void
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE qr_attendance_events (
            id INTEGER PRIMARY KEY,
            personel_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            occurred_at_utc TEXT NOT NULL,
            sube_id INTEGER NOT NULL
        )'
    );
    $pdo->exec(
        'CREATE TABLE gunluk_puantaj (
            id INTEGER PRIMARY KEY,
            personel_id INTEGER NOT NULL,
            tarih TEXT NOT NULL,
            beklenen_giris_saati TEXT,
            beklenen_cikis_saati TEXT,
            giris_saati TEXT,
            cikis_saati TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE surecler (
            id INTEGER PRIMARY KEY,
            personel_id INTEGER NOT NULL,
            surec_turu TEXT NOT NULL,
            alt_tur TEXT,
            state TEXT NOT NULL,
            baslangic_tarihi TEXT NOT NULL,
            bitis_tarihi TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE qr_attendance_correction_requests (
            id INTEGER PRIMARY KEY,
            personel_id INTEGER NOT NULL,
            source_event_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            business_date TEXT,
            status TEXT NOT NULL,
            explanation TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE personeller (
            id INTEGER PRIMARY KEY,
            ad TEXT,
            soyad TEXT,
            sube_id INTEGER,
            bolum_id INTEGER,
            birim_id INTEGER
        )'
    );
    $pdo->exec(
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            rol TEXT,
            durum TEXT,
            personel_id INTEGER
        )'
    );
    $pdo->exec(
        'CREATE TABLE personel_inbox_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_user_id INTEGER NOT NULL,
            personel_id INTEGER,
            kind TEXT NOT NULL,
            title TEXT NOT NULL,
            body TEXT NOT NULL,
            payload_json TEXT,
            anomaly_source_event_id INTEGER,
            anomaly_audience TEXT,
            related_correction_id INTEGER,
            status TEXT NOT NULL DEFAULT \'ACTIVE\',
            popup_required INTEGER NOT NULL DEFAULT 1,
            popup_consumed_at_utc TEXT,
            reminder_of_notification_id INTEGER,
            created_at_utc TEXT NOT NULL,
            UNIQUE (kind, anomaly_source_event_id, anomaly_audience)
        )'
    );
    $pdo->exec(
        'CREATE TABLE resmi_tatil_takvimi (
            id INTEGER PRIMARY KEY,
            tarih TEXT,
            tatil_kodu TEXT,
            tatil_adi TEXT,
            tatil_turu TEXT,
            gun_kapsami TEXT,
            tatil_interval_baslangic TEXT,
            tatil_interval_bitis TEXT,
            durum TEXT,
            kaynak_turu TEXT,
            kaynak_referansi TEXT,
            kaynak_tarihi TEXT,
            aciklama TEXT,
            revizyon_no INTEGER,
            onceki_kayit_id INTEGER,
            yapan_kullanici_id INTEGER,
            yapan_ad TEXT,
            iptal_edildi_at TEXT,
            iptal_eden_kullanici_id INTEGER,
            iptal_gerekcesi TEXT,
            created_at TEXT,
            updated_at TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE sirket_calisma_politikalari (
            id INTEGER PRIMARY KEY,
            revision_no INTEGER,
            parent_politika_id INTEGER,
            state TEXT,
            gecerlilik_baslangic TEXT,
            gecerlilik_bitis TEXT,
            aciklama TEXT,
            belge_id TEXT,
            belge_sha256 TEXT,
            policy_version_hash TEXT,
            hazirlayan_id INTEGER,
            onaylayan_id INTEGER,
            onay_zamani TEXT,
            created_at TEXT,
            updated_at TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE sirket_calisma_politika_degerleri (
            id INTEGER PRIMARY KEY,
            politika_id INTEGER,
            parametre_kodu TEXT,
            deger_tipi TEXT,
            sayisal_deger TEXT,
            metin_deger TEXT,
            birim TEXT
        )'
    );
    $pdo->exec("INSERT INTO sirket_calisma_politikalari (id, revision_no, state, gecerlilik_baslangic, created_at, updated_at) VALUES (1, 3, 'ONAYLANDI', '2020-01-01', '2020-01-01', '2020-01-01')");
    $pdo->exec("INSERT INTO sirket_calisma_politika_degerleri (id, politika_id, parametre_kodu, deger_tipi, metin_deger) VALUES (1, 1, 'HAFTA_TATILI_GUNLERI', 'METIN', '0')");
    $pdo->exec("INSERT INTO personeller (id, ad, soyad, sube_id) VALUES (7, 'Ayşe', 'Demir', 1)");
    $pdo->exec("INSERT INTO personeller (id, ad, soyad, sube_id) VALUES (9, 'Deniz', 'Kaya', 1)");
    $pdo->exec("INSERT INTO users (id, rol, durum, personel_id) VALUES (15, 'PERSONEL', 'AKTIF', 7)");
    $pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (1, 7, '2026-09-28', '08:30', '17:40')");
    $pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (2, 7, '2026-09-26', '09:00', '18:00')");
    $pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (3, 9, '2026-09-26', '09:00', '18:00')");
    $pdo->exec("INSERT INTO qr_attendance_events (id, personel_id, user_id, event_type, occurred_at_utc, sube_id) VALUES (901, 7, 15, 'GIRIS', '2026-09-28 05:30:00.000000', 1)");

    aaAssert($svc::dayKeySchemaReady($pdo) === false, 'schema 093 day-key capability is not ready');
    $before = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:39:00'));
    aaAssert($before === [], 'schema 093 +179 → no anomaly');
    $at = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'));
    aaAssert(aaIdentities($at) === ['MISSING_CIKIS:901'], 'schema 093 +180 keeps the open giriş anomaly');
    aaAssert(!in_array('NO_EVENT_DAY:2026-09-26', aaIdentities($at), true), 'schema 093 does not surface NO_EVENT_DAY');
    aaAssert($svc::listForPersonel($pdo, 9, aaIstanbul('2026-09-26 18:30:00')) === [], 'schema 093 zero-event Saturday stays empty');

    $first = $svc::ensureNotifications($pdo, 7, $at, aaIstanbul('2026-09-28 20:40:00'));
    $second = $svc::ensureNotifications($pdo, 7, $at, aaIstanbul('2026-09-28 20:41:00'));
    aaAssert((int) $first['created'] === 1 && (int) $first['skipped_schema'] === 0, 'schema 093 creates the event notification');
    aaAssert((int) $second['created'] === 0, 'schema 093 does not duplicate the event notification');
    $eventNotes = (int) $pdo->query('SELECT COUNT(*) FROM personel_inbox_notifications WHERE anomaly_source_event_id = 901')->fetchColumn();
    aaAssert($eventNotes === 1, 'schema 093 notification is source-event keyed');
    $dayInsert = PersonelInboxNotificationService::createAnomaly(
        $pdo,
        15,
        $svc::NOTIFICATION_KIND,
        $svc::PERSONEL_TITLE,
        $svc::PERSONEL_BODY,
        7,
        0,
        $svc::AUDIENCE_PERSONEL,
        ['anomaly_type' => 'NO_EVENT_DAY', 'business_date' => '2026-09-26'],
        '2026-09-26',
        'NO_EVENT_DAY'
    );
    aaAssert($dayInsert === 0, 'schema 093 does not attempt the day-key notification insert');
    aaAssert((int) $pdo->query('SELECT COUNT(*) FROM personel_inbox_notifications')->fetchColumn() === 1, 'schema 093 notification count stays on the event row');

    $scan = $svc::scan($pdo, aaIstanbul('2026-09-28 20:40:00'));
    aaAssert((int) $scan['skipped_schema'] === 0, 'schema 093 does not skip the event scanner');
    aaAssert((int) $scan['personel_count'] === 1, 'schema 093 scan does not pick up a zero-event personel');
    aaAssert((int) $scan['created'] === 0, 'schema 093 rescan does not create another notification');

    $pdo->exec("INSERT INTO qr_attendance_correction_requests (id, personel_id, source_event_id, event_type, business_date, status) VALUES (91, 7, 901, 'CIKIS', '2026-09-28', 'BEKLIYOR')");
    $pending = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00'));
    aaAssert(count($pending) === 1 && (int) $pending[0]['pending_request_id'] === 91, 'schema 093 event correction stays pending on the open giriş');
    aaAssert(aaIdentities($pending) === ['MISSING_CIKIS:901'], 'schema 093 pending event correction does not add NO_EVENT_DAY');
    $pdo->exec("UPDATE qr_attendance_correction_requests SET status = 'ONAYLANDI' WHERE id = 91");
    aaAssert($svc::listForPersonel($pdo, 7, aaIstanbul('2026-09-28 20:40:00')) === [], 'schema 093 approved event correction clears the anomaly');

    $qrBefore = (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events')->fetchColumn();
    $correctionBefore = (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_correction_requests')->fetchColumn();
    $caught = null;
    $sql = null;
    try {
        QrAttendanceCorrectionService::createRequest($pdo, ['id' => 15, 'rol' => 'PERSONEL'], [
            'anomaly_type' => 'NO_EVENT_DAY',
            'business_date' => '2026-09-26',
            'requested_local_time' => '08:32',
            'explanation' => 'okutmayi unuttum',
        ]);
    } catch (QrAttendanceException $e) {
        $caught = $e;
    } catch (Throwable $e) {
        $sql = $e;
    }
    aaAssert($sql === null, 'schema 093 direct NO_EVENT request does not raise a SQL exception');
    aaAssert(
        $caught instanceof QrAttendanceException
            && $caught->getErrorCode() === 'NO_EVENT_DAY_SCHEMA_NOT_READY'
            && $caught->getHttpStatus() === 503,
        'schema 093 direct NO_EVENT request is fail-closed'
    );
    aaAssert((int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events')->fetchColumn() === $qrBefore, 'schema 093 direct NO_EVENT request does not mutate QR');
    aaAssert((int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_correction_requests')->fetchColumn() === $correctionBefore, 'schema 093 direct NO_EVENT request inserts no correction');
}
