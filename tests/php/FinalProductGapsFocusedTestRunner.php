<?php

declare(strict_types=1);

/**
 * FINAL PRODUCT GAPS — focused local validation (no DB for pure decision,
 * SQLite in-memory for the two production owner paths).
 *
 *  1) AFTER_HOURS_REENTRY audience resolution + dedupe + exclusions, and the
 *     contract that İK is scoped by real branch/company assignment — never
 *     org-wide by role name.
 *  2) FINAL_EARLY_EXIT_NOTIFICATION decision + idempotent materialisation,
 *     including the bounded next-day lookback recovery.
 */
require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Attendance\LateEarlyInfoService;
use Medisa\Api\Services\Qr\QrAttendanceEventService;
use Medisa\Api\Services\SelfService\PersonelInboxNotificationService;

function fpgAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] $name\n");
        exit(1);
    }
    echo "[PASS] $name\n";
}

$planned = [
    'beklenen_giris_saati' => '08:30',
    'beklenen_cikis_saati' => '17:40',
];

// ---------------------------------------------------------------------------
// PART 1 — AFTER_HOURS_REENTRY AUDIENCE (SQLite in-memory)
// ---------------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, rol TEXT, durum TEXT)');
$pdo->exec('CREATE TABLE personeller (id INTEGER PRIMARY KEY, bagli_amir_id INTEGER)');
$pdo->exec('CREATE TABLE user_birimler (user_id INTEGER, birim_id INTEGER)');
$pdo->exec('CREATE TABLE user_bolumler (user_id INTEGER, bolum_id INTEGER)');
$pdo->exec('CREATE TABLE user_subeler (user_id INTEGER, sube_id INTEGER)');
$pdo->exec('CREATE TABLE user_sirketler (user_id INTEGER, sirket_id INTEGER)');
$pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, sirket_id INTEGER)');

$seedUsers = [
    [1, 'BIRIM_AMIRI', 'AKTIF'],          // direct amir + BIRIM_AMIRI of birim 20 (dedupe)
    [3, 'BOLUM_YONETICISI', 'AKTIF'],     // bolum 30
    [4, 'SUBE_YONETICISI', 'AKTIF'],      // sube 10
    [5, 'SUBE_YONETICISI', 'AKTIF'],      // sube 77 — unrelated branch
    [6, 'IK_SORUMLUSU', 'AKTIF'],         // İK assigned to sube 10 (scope covers personel)
    [7, 'IK_PERSONELI', 'AKTIF'],         // İK with NO assignment — role alone is not enough
    [8, 'GENEL_YONETICI', 'AKTIF'],       // canonical global role
    [9, 'MUHASEBE', 'AKTIF'],             // non-managing role — must be excluded
    [10, 'SISTEM_YONETICISI', 'AKTIF'],   // IT — must be excluded
    [11, 'AUTH_SMOKE_READONLY', 'AKTIF'], // smoke role — must be excluded
    [12, 'BIRIM_AMIRI', 'AKTIF'],         // birim 99 — unrelated birim
    [13, 'IK_PERSONELI', 'AKTIF'],        // İK assigned to sube 77 — unrelated branch
    [14, 'IK_SORUMLUSU', 'AKTIF'],        // İK granted company 500 (sube 10 belongs to it)
    [50, 'GENEL_YONETICI', 'AKTIF'],      // the scanning user — must be excluded
];
foreach ($seedUsers as [$id, $rol, $durum]) {
    $pdo->exec("INSERT INTO users (id, rol, durum) VALUES ({$id}, '{$rol}', '{$durum}')");
}
$pdo->exec("INSERT INTO personeller (id, bagli_amir_id) VALUES (100, 1)");
// sube 10 → sirket 500 ; sube 77 → sirket 600
$pdo->exec('INSERT INTO subeler (id, sirket_id) VALUES (10, 500), (77, 600)');
$pdo->exec('INSERT INTO user_birimler (user_id, birim_id) VALUES (1, 20), (12, 99)');
$pdo->exec('INSERT INTO user_bolumler (user_id, bolum_id) VALUES (3, 30)');
$pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (4, 10), (5, 77), (6, 10), (13, 77)');
$pdo->exec('INSERT INTO user_sirketler (user_id, sirket_id) VALUES (14, 500)');

$ctx = [
    'personel_id' => 100,
    'ad_soyad' => 'Ali Veli',
    'sube_id' => 10,
    'birim_id' => 20,
    'bolum_id' => 30,
];

$recipients = QrAttendanceEventService::resolveOperationalManagerRecipients($pdo, $ctx, 50);
sort($recipients);

fpgAssert(
    $recipients === [1, 3, 4, 6, 8, 14],
    'AFTER_HOURS_REENTRY audience = [amir/birim, bolum, sube, scoped İK x2, genel] exactly'
);
fpgAssert(count($recipients) === count(array_unique($recipients)), 'AFTER_HOURS_REENTRY dedupe (user 1 amir+birim once)');
fpgAssert(!in_array(5, $recipients, true), 'AFTER_HOURS_REENTRY excludes unrelated sube (5)');
fpgAssert(!in_array(12, $recipients, true), 'AFTER_HOURS_REENTRY excludes unrelated birim (12)');
fpgAssert(!in_array(9, $recipients, true), 'AFTER_HOURS_REENTRY excludes MUHASEBE');
fpgAssert(!in_array(10, $recipients, true), 'AFTER_HOURS_REENTRY excludes SISTEM_YONETICISI');
fpgAssert(!in_array(11, $recipients, true), 'AFTER_HOURS_REENTRY excludes AUTH_SMOKE_READONLY');
fpgAssert(!in_array(50, $recipients, true), 'AFTER_HOURS_REENTRY excludes scanning user (50)');
fpgAssert(in_array(6, $recipients, true), 'AFTER_HOURS_REENTRY includes İK assigned to personel branch (6)');
fpgAssert(in_array(14, $recipients, true), 'AFTER_HOURS_REENTRY includes İK granted personel company (14)');
fpgAssert(!in_array(7, $recipients, true), 'AFTER_HOURS_REENTRY excludes İK with no assignment (7) — role alone is not global');
fpgAssert(!in_array(13, $recipients, true), 'AFTER_HOURS_REENTRY excludes İK assigned to unrelated branch (13)');

// ---------------------------------------------------------------------------
// PART 2 — FINAL_EARLY_EXIT_NOTIFICATION (pure decision)
// ---------------------------------------------------------------------------
// 17:40 planned. Final CIKIS 14:09 UTC = 17:09 Istanbul → 31 dk early.
$seq31 = [
    ['id' => 1, 'event_type' => 'GIRIS', 'occurred_at_utc' => '2026-09-25 05:31:00.000000'],
    ['id' => 2, 'event_type' => 'CIKIS', 'occurred_at_utc' => '2026-09-25 14:09:00.000000'],
];
$info = QrAttendanceEventService::finalEarlyExitInfo($seq31, $planned, 1100);
fpgAssert(is_array($info), 'final early exit 31dk → info present');
fpgAssert(($info['kind'] ?? '') === 'EARLY_EXIT_INFO', 'final early exit kind');
fpgAssert((int) $info['delta_dakika'] === 31, 'final early exit delta 31');
fpgAssert($info['notification_title'] === 'Erken Çıkış', 'final early exit exact title');
fpgAssert(
    $info['notification_body'] === "Normal Mesai Bitiminden 31dk Önce Çıkış Yaptınız.\nÜcret Kesintisi Durumunu Amirinizle Görüşün.",
    'final early exit exact body copy'
);

// 0–30 dk early → no notification (20 dk).
$seq20 = [
    ['id' => 1, 'event_type' => 'GIRIS', 'occurred_at_utc' => '2026-09-25 05:31:00.000000'],
    ['id' => 2, 'event_type' => 'CIKIS', 'occurred_at_utc' => '2026-09-25 14:20:00.000000'],
];
fpgAssert(QrAttendanceEventService::finalEarlyExitInfo($seq20, $planned, 1100) === null, '0–30dk early → no notification');

// Last event GIRIS (open shift) → no notification.
$seqOpen = [
    ['id' => 1, 'event_type' => 'GIRIS', 'occurred_at_utc' => '2026-09-25 05:31:00.000000'],
    ['id' => 2, 'event_type' => 'CIKIS', 'occurred_at_utc' => '2026-09-25 08:00:00.000000'],
    ['id' => 3, 'event_type' => 'GIRIS', 'occurred_at_utc' => '2026-09-25 15:00:00.000000'],
];
fpgAssert(QrAttendanceEventService::finalEarlyExitInfo($seqOpen, $planned, 1100) === null, 're-GIRIS after mid-day exit → no notification');

// Planned end not yet passed → no notification.
fpgAssert(QrAttendanceEventService::finalEarlyExitInfo($seq31, $planned, 1040) === null, 'planned end not passed → no notification');

// ---------------------------------------------------------------------------
// PART 3 — FINAL_EARLY_EXIT_NOTIFICATION idempotent materialisation (SQLite)
// ---------------------------------------------------------------------------
function fpgSeedDay(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE qr_attendance_events (id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER, event_type TEXT, occurred_at_utc TEXT)');
    $pdo->exec('CREATE TABLE gunluk_puantaj (id INTEGER PRIMARY KEY AUTOINCREMENT, personel_id INTEGER, tarih TEXT, beklenen_giris_saati TEXT, beklenen_cikis_saati TEXT, giris_saati TEXT, cikis_saati TEXT)');
    $pdo->exec(
        'CREATE TABLE personel_inbox_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipient_user_id INTEGER,
            personel_id INTEGER,
            kind TEXT,
            title TEXT,
            body TEXT,
            payload_json TEXT,
            related_correction_id INTEGER,
            status TEXT,
            popup_required INTEGER,
            reminder_of_notification_id INTEGER,
            created_at_utc TEXT
        )'
    );
}

function fpgCountInbox(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personel_inbox_notifications')->fetchColumn();
}

$pdoB = new PDO('sqlite::memory:');
$pdoB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
fpgSeedDay($pdoB);
$pdoB->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (100, '2026-09-25', '08:30', '17:40')");
$pdoB->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'GIRIS', '2026-09-25 05:31:00.000000')");
$pdoB->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'CIKIS', '2026-09-25 14:09:00.000000')");

$created = QrAttendanceEventService::ensureFinalEarlyExitNotification(
    $pdoB,
    100,
    50,
    '2026-09-25',
    '2026-09-25 16:00:00.000000'
);
fpgAssert($created === true, 'final early exit materialised once');
fpgAssert(fpgCountInbox($pdoB) === 1, 'final early exit exactly one row');

$row = $pdoB->query('SELECT kind, title, body, payload_json FROM personel_inbox_notifications')->fetch(PDO::FETCH_ASSOC);
fpgAssert($row['kind'] === 'EARLY_EXIT_INFO', 'final early exit row kind');
fpgAssert($row['title'] === 'Erken Çıkış', 'final early exit row title');
fpgAssert(
    $row['body'] === "Normal Mesai Bitiminden 31dk Önce Çıkış Yaptınız.\nÜcret Kesintisi Durumunu Amirinizle Görüşün.",
    'final early exit row body copy'
);
fpgAssert(strpos((string) $row['payload_json'], '"source_event_id":2') !== false, 'final early exit row carries source_event_id');

// Idempotency: second run must not create a duplicate.
$again = QrAttendanceEventService::ensureFinalEarlyExitNotification(
    $pdoB,
    100,
    50,
    '2026-09-25',
    '2026-09-25 16:00:00.000000'
);
fpgAssert($again === false, 'final early exit second run is a no-op');
fpgAssert(fpgCountInbox($pdoB) === 1, 'final early exit no duplicate');

// Non-final day (only open GIRIS) → nothing materialised.
$pdoC = new PDO('sqlite::memory:');
$pdoC->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
fpgSeedDay($pdoC);
$pdoC->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (100, '2026-09-25', '08:30', '17:40')");
$pdoC->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'GIRIS', '2026-09-25 05:31:00.000000')");
$createdC = QrAttendanceEventService::ensureFinalEarlyExitNotification(
    $pdoC,
    100,
    50,
    '2026-09-25',
    '2026-09-25 16:00:00.000000'
);
fpgAssert($createdC === false, 'open-shift day → no final early exit');
fpgAssert(fpgCountInbox($pdoC) === 0, 'open-shift day → zero rows');

// ---------------------------------------------------------------------------
// PART 4 — FINAL_EARLY_EXIT next-day recovery (bounded lookback, SQLite)
// ---------------------------------------------------------------------------
// 25 Sep-style gap: the final early exit settled on a past day the personel
// never re-opened the app on. It must be materialised on the next open.
$pdoD = new PDO('sqlite::memory:');
$pdoD->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
fpgSeedDay($pdoD);
$pdoD->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (100, '2026-01-08', '08:30', '17:40')");
$pdoD->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'GIRIS', '2026-01-08 05:31:00.000000')");
$pdoD->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'CIKIS', '2026-01-08 14:09:00.000000')");

$recovered = QrAttendanceEventService::ensureRecentFinalEarlyExitNotifications($pdoD, 100, 50, '2026-01-10');
fpgAssert($recovered === true, 'lookback materialises previous-day final early exit (8 Jan seen from 10 Jan)');
fpgAssert(fpgCountInbox($pdoD) === 1, 'lookback previous-day early exit exactly one row');
$rowD = $pdoD->query('SELECT kind, payload_json FROM personel_inbox_notifications')->fetch(PDO::FETCH_ASSOC);
fpgAssert($rowD['kind'] === 'EARLY_EXIT_INFO', 'lookback row kind');
fpgAssert(strpos((string) $rowD['payload_json'], '"source_event_id":2') !== false, 'lookback row carries source_event_id');

$recoveredAgain = QrAttendanceEventService::ensureRecentFinalEarlyExitNotifications($pdoD, 100, 50, '2026-01-10');
fpgAssert($recoveredAgain === false, 'lookback recovery is idempotent (second open is a no-op)');
fpgAssert(fpgCountInbox($pdoD) === 1, 'lookback recovery never duplicates');

// A past day whose mid-day exit was closed by a later re-GIRIS is not an early
// exit: only the day's LAST movement counts.
$pdoE = new PDO('sqlite::memory:');
$pdoE->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
fpgSeedDay($pdoE);
$pdoE->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati) VALUES (100, '2026-01-10', '08:30', '17:40')");
$pdoE->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'GIRIS', '2026-01-10 05:31:00.000000')");
$pdoE->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'CIKIS', '2026-01-10 08:20:00.000000')"); // 11:20 mid-day exit
$pdoE->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'GIRIS', '2026-01-10 09:05:00.000000')"); // 12:05 re-entry
$pdoE->exec("INSERT INTO qr_attendance_events (personel_id, event_type, occurred_at_utc) VALUES (100, 'CIKIS', '2026-01-10 14:40:00.000000')"); // 17:40 on time
$recoveredE = QrAttendanceEventService::ensureRecentFinalEarlyExitNotifications($pdoE, 100, 50, '2026-01-10');
fpgAssert($recoveredE === false, 'mid-day exit closed by re-GIRIS → no early-exit notification');
fpgAssert(fpgCountInbox($pdoE) === 0, 'mid-day exit → zero rows');

echo "[OK] FinalProductGapsFocusedTestRunner\n";
