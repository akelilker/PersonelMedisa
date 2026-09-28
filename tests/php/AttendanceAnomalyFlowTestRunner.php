<?php

declare(strict_types=1);

/**
 * Canonical unresolved attendance anomaly contract.
 * php tests/php/AttendanceAnomalyFlowTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

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

aaAssert($svc::THRESHOLD_MINUTES === 180, 'threshold is 180 minutes');
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
        source_event_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        status TEXT NOT NULL
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
aaAssert(count($aged) === 1 && (int) $aged[0]['source_event_id'] === 701, '9-day unresolved anomaly stays visible');
$later = $svc::listForPersonel($pdo, 7, aaIstanbul('2026-10-05 20:40:00'));
aaAssert(count($later) === 1 && (int) $later[0]['source_event_id'] === 701, 'time passing alone does not close an unresolved anomaly');
$firstScan = $svc::scan($pdo, $agedNow);
$secondScan = $svc::scan($pdo, aaIstanbul('2026-09-28 20:45:00'));
aaAssert((int) $firstScan['created'] === 1, 'scan still notifies an unresolved anomaly outside the discovery window');
aaAssert((int) $secondScan['created'] === 0, 'later scan does not duplicate the aged anomaly notification');
$agedNotes = (int) $pdo->query("SELECT COUNT(*) FROM personel_inbox_notifications WHERE anomaly_source_event_id = 701")->fetchColumn();
aaAssert($agedNotes === 1, 'aged anomaly still has one personel notification');
$pdo->exec("INSERT INTO qr_attendance_correction_requests (id, personel_id, source_event_id, event_type, status) VALUES (9, 7, 701, 'CIKIS', 'ONAYLANDI')");
aaAssert(count($svc::listForPersonel($pdo, 7, aaIstanbul('2026-10-05 20:40:00'))) === 0, 'approved correction closes the aged anomaly');
$agedRaw = (int) $pdo->query("SELECT COUNT(*) FROM qr_attendance_events WHERE id = 701 AND event_type = 'GIRIS'")->fetchColumn();
aaAssert($agedRaw === 1, 'closing an aged anomaly does not mutate the raw giriş');

echo '[OK] AttendanceAnomalyFlowTestRunner' . PHP_EOL;
