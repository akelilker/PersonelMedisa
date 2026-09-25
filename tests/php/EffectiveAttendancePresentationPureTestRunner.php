<?php

declare(strict_types=1);

/**
 * Approved correction → PERSONEL effective time + status (Today/History shared owner).
 */
require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendancePresentationService;

function eapAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] $name\n");
        exit(1);
    }
    echo "[PASS] $name\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(
    'CREATE TABLE gunluk_puantaj (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        personel_id INTEGER NOT NULL,
        tarih TEXT NOT NULL,
        beklenen_giris_saati TEXT,
        beklenen_cikis_saati TEXT,
        giris_saati TEXT,
        cikis_saati TEXT
     )'
);
$pdo->exec(
    'CREATE TABLE qr_attendance_correction_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_event_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        effective_local_time TEXT,
        requested_local_time TEXT,
        original_occurred_at_utc TEXT
     )'
);

$pdo->exec(
    "INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati)
     VALUES (100, '2026-09-25', '08:30', '17:40')"
);

$rawUtc = '2026-09-25 06:05:00.000000'; // 09:05 Istanbul
$eventId = 501;

// No correction → late 35dk
$raw = QrAttendancePresentationService::presentDayEvent($pdo, 100, $eventId, 'GIRIS', $rawUtc, '2026-09-25');
eapAssert($raw['time'] === '09:05', 'raw display 09:05');
eapAssert(is_array($raw['status']), 'raw status present');
eapAssert((int) $raw['status']['delta_dakika'] === 35, 'raw delta 35');
eapAssert(strpos((string) $raw['status']['label'], '35dk') !== false, 'raw 35dk Gecikme');

// Approved 08:30 → no gecikme
$pdo->exec(
    "INSERT INTO qr_attendance_correction_requests
        (source_event_id, status, effective_local_time, requested_local_time, original_occurred_at_utc)
     VALUES (501, 'ONAYLANDI', '08:30', '08:30', '{$rawUtc}')"
);
$ok830 = QrAttendancePresentationService::presentDayEvent($pdo, 100, $eventId, 'GIRIS', $rawUtc, '2026-09-25');
eapAssert($ok830['time'] === '08:30', 'approved 08:30 display');
eapAssert($ok830['status'] === null, 'approved 08:30 no late status');

// Today box shape matches
$todayBox = QrAttendancePresentationService::presentTodayBoxEvent($pdo, 100, $eventId, 'GIRIS', $rawUtc, '2026-09-25');
eapAssert($todayBox['local_time'] === '09:05', 'today local_time remains raw');
eapAssert($todayBox['display_local_time'] === '08:30', 'today display effective');
eapAssert(($todayBox['status'] ?? null) === null, 'today status null');

// Approved 08:45 within 30dk of 08:30 → no status
$pdo->exec('DELETE FROM qr_attendance_correction_requests');
$pdo->exec(
    "INSERT INTO qr_attendance_correction_requests
        (source_event_id, status, effective_local_time, requested_local_time, original_occurred_at_utc)
     VALUES (501, 'ONAYLANDI', '08:45', '08:45', '{$rawUtc}')"
);
$ok845 = QrAttendancePresentationService::presentDayEvent($pdo, 100, $eventId, 'GIRIS', $rawUtc, '2026-09-25');
eapAssert($ok845['time'] === '08:45', 'approved 08:45 display');
eapAssert($ok845['status'] === null, 'approved 08:45 within tolerance');

// Approved 09:02 → 32dk late
$pdo->exec('DELETE FROM qr_attendance_correction_requests');
$pdo->exec(
    "INSERT INTO qr_attendance_correction_requests
        (source_event_id, status, effective_local_time, requested_local_time, original_occurred_at_utc)
     VALUES (501, 'ONAYLANDI', '09:02', '09:02', '{$rawUtc}')"
);
$ok902 = QrAttendancePresentationService::presentDayEvent($pdo, 100, $eventId, 'GIRIS', $rawUtc, '2026-09-25');
eapAssert($ok902['time'] === '09:02', 'approved 09:02 display');
eapAssert(is_array($ok902['status']), 'approved 09:02 status');
eapAssert((int) $ok902['status']['delta_dakika'] === 32, 'approved delta 32');
eapAssert(strpos((string) $ok902['status']['label'], '32dk') !== false, 'approved 32dk Gecikme');

// Pending → correction_allowed false
$pdo->exec('DELETE FROM qr_attendance_correction_requests');
$pdo->exec(
    "INSERT INTO qr_attendance_correction_requests
        (source_event_id, status, effective_local_time, requested_local_time, original_occurred_at_utc)
     VALUES (501, 'BEKLIYOR', NULL, '08:40', '{$rawUtc}')"
);
$pending = QrAttendancePresentationService::presentDayEvent($pdo, 100, $eventId, 'GIRIS', $rawUtc, '2026-09-25');
eapAssert($pending['correction_allowed'] === false, 'pending blocks pencil');
eapAssert(is_array($pending['pending_correction']), 'pending payload');
eapAssert($pending['time'] === '09:05', 'pending still shows raw until approved');

echo "[OK] EffectiveAttendancePresentationPureTestRunner\n";
