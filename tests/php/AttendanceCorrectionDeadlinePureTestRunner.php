<?php

declare(strict_types=1);

/**
 * Correction deadline: next work day + planned mesai bitiş (no hardcoded 17:40).
 */
require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Attendance\AttendanceBusinessDayService;

function cdAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] $name\n");
        exit(1);
    }
    echo "[PASS] $name\n";
}

/**
 * Minimal PDO stub is not enough for ResmiTatil / politika.
 * Pure calendar helpers: nextWorkDay with Mon–Fri fallback when politika unresolved.
 * We exercise isWorkDay weekend fallback without DB holiday rows via a fake that
 * throws on resolveActiveForDate — but real PDO is required for service methods.
 *
 * This runner focuses on DateTime deadline math with an in-memory SQLite stand-in
 * for gunluk_puantaj only, and treats resmi tatil service failures as non-holiday.
 */

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

// Monday 2026-09-21 event, planned exit 17:40 on that day → next work Tue 22 end 17:40 Istanbul
$pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-21', '17:40')");
$pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-22', '17:40')");

// Event Monday 08:31 Istanbul = 05:31 UTC
$eventUtc = '2026-09-21 05:31:00.000000';
$deadline = AttendanceBusinessDayService::resolveCorrectionDeadlineUtc($pdo, 1, $eventUtc);
cdAssert($deadline instanceof DateTimeImmutable, 'monday→tuesday deadline resolved');
$deadlineIst = $deadline->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d H:i');
cdAssert($deadlineIst === '2026-09-22 17:40', 'tuesday 17:40 istanbul deadline: ' . $deadlineIst);

// Exact deadline allowed
$allowed = AttendanceBusinessDayService::isCorrectionAllowedNow(
    $pdo,
    1,
    $eventUtc,
    $deadline->format('Y-m-d H:i:s.u')
);
cdAssert($allowed === true, 'exact deadline allowed');

// +1 second denied
$plus1 = $deadline->modify('+1 second')->format('Y-m-d H:i:s.u');
$denied = AttendanceBusinessDayService::isCorrectionAllowedNow($pdo, 1, $eventUtc, $plus1);
cdAssert($denied === false, 'deadline +1s denied');

// Friday 2026-09-25 → next work Monday 2026-09-28 (Sat/Sun skip via Mon–Fri fallback)
$pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-25', '17:40')");
$pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-28', '17:40')");
$friUtc = '2026-09-25 05:31:00.000000';
$friDeadline = AttendanceBusinessDayService::resolveCorrectionDeadlineUtc($pdo, 1, $friUtc);
cdAssert($friDeadline instanceof DateTimeImmutable, 'friday deadline resolved');
$friIst = $friDeadline->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d H:i');
cdAssert($friIst === '2026-09-28 17:40', 'friday→monday deadline: ' . $friIst);

// No planned times → unresolved → not allowed (fail closed, no invented clock)
$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->exec(
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
$unresolved = AttendanceBusinessDayService::resolveCorrectionDeadlineUtc($pdo2, 9, $eventUtc);
cdAssert($unresolved === null, 'no planned → unresolved deadline');
$closed = AttendanceBusinessDayService::isCorrectionAllowedNow($pdo2, 9, $eventUtc, '2026-09-21 06:00:00.000000');
cdAssert($closed === false, 'unresolved → correction not allowed');

// nextWorkDay Mon→Tue
$next = AttendanceBusinessDayService::nextWorkDay($pdo, '2026-09-21');
cdAssert($next === '2026-09-22', 'next work after monday');

$nextFri = AttendanceBusinessDayService::nextWorkDay($pdo, '2026-09-25');
cdAssert($nextFri === '2026-09-28', 'next work after friday skips weekend');

echo "[OK] AttendanceCorrectionDeadlinePureTestRunner\n";
