<?php

declare(strict_types=1);

/**
 * Correction deadline: next work day + THAT day's planned mesai bitiş only.
 * Fail-closed calendar (no Mon–Fri invent). No event-day/recent clock copy.
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

function cdSeedCalendar(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE resmi_tatil_takvimi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tarih TEXT NOT NULL,
            durum TEXT NOT NULL,
            tatil_turu TEXT NOT NULL,
            revizyon_no INTEGER NOT NULL DEFAULT 1
         )'
    );
    $pdo->exec(
        'CREATE TABLE sirket_calisma_politikalari (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            revision_no INTEGER NOT NULL DEFAULT 1,
            parent_politika_id INTEGER,
            state TEXT NOT NULL,
            gecerlilik_baslangic TEXT NOT NULL,
            gecerlilik_bitis TEXT,
            aciklama TEXT,
            belge_id INTEGER,
            belge_sha256 TEXT,
            hazirlayan_id INTEGER,
            created_by INTEGER,
            updated_by INTEGER,
            policy_version_hash TEXT,
            onaylayan_id INTEGER,
            onay_zamani TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
         )'
    );
    $pdo->exec(
        'CREATE TABLE sirket_calisma_politika_degerleri (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            politika_id INTEGER NOT NULL,
            parametre_kodu TEXT NOT NULL,
            deger_tipi TEXT DEFAULT \'METIN\',
            metin_deger TEXT,
            sayisal_deger TEXT,
            birim TEXT
         )'
    );
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

    // Sun only rest day (0) — Sat is work day under this policy.
    $pdo->exec(
        "INSERT INTO sirket_calisma_politikalari
            (id, revision_no, state, gecerlilik_baslangic, gecerlilik_bitis, policy_version_hash)
         VALUES (1, 1, 'ONAYLANDI', '2020-01-01', NULL, 'test-hash')"
    );
    $pdo->exec(
        "INSERT INTO sirket_calisma_politika_degerleri (politika_id, parametre_kodu, metin_deger)
         VALUES (1, 'HAFTA_TATILI_GUNLERI', '0,6')"
    );
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
cdSeedCalendar($pdo);

// Monday 2026-09-21 event; next work Tue 22 must carry ITS OWN planned exit 16:00
// (event day has 17:40 — must NOT be copied).
$pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-21', '17:40')");
$pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-22', '16:00')");

$eventUtc = '2026-09-21 05:31:00.000000';
$deadline = AttendanceBusinessDayService::resolveCorrectionDeadlineUtc($pdo, 1, $eventUtc);
cdAssert($deadline instanceof DateTimeImmutable, 'monday→tuesday deadline resolved');
$deadlineIst = $deadline->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d H:i');
cdAssert($deadlineIst === '2026-09-22 16:00', 'uses NEXT work day clock 16:00 not event-day 17:40: ' . $deadlineIst);

$allowed = AttendanceBusinessDayService::isCorrectionAllowedNow(
    $pdo,
    1,
    $eventUtc,
    $deadline->format('Y-m-d H:i:s.u')
);
cdAssert($allowed === true, 'exact deadline allowed');

$plus1 = $deadline->modify('+1 second')->format('Y-m-d H:i:s.u');
$denied = AttendanceBusinessDayService::isCorrectionAllowedNow($pdo, 1, $eventUtc, $plus1);
cdAssert($denied === false, 'deadline +1s denied');

// Friday → Monday (Sat+Sun rest). Monday planned 15:30; Friday 17:40 must not copy.
$pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-25', '17:40')");
$pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-28', '15:30')");
$friUtc = '2026-09-25 05:31:00.000000';
$friDeadline = AttendanceBusinessDayService::resolveCorrectionDeadlineUtc($pdo, 1, $friUtc);
cdAssert($friDeadline instanceof DateTimeImmutable, 'friday deadline resolved');
$friIst = $friDeadline->setTimezone(new DateTimeZone('Europe/Istanbul'))->format('Y-m-d H:i');
cdAssert($friIst === '2026-09-28 15:30', 'friday→monday uses monday clock: ' . $friIst);

// Next work day exists but no planned exit on that day → fail closed (no event-day/recent copy)
$pdo3 = new PDO('sqlite::memory:');
$pdo3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
cdSeedCalendar($pdo3);
$pdo3->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_cikis_saati) VALUES (1, '2026-09-21', '17:40')");
// Tue exists as work day but no gunluk_puantaj row
$noNextClock = AttendanceBusinessDayService::resolveCorrectionDeadlineUtc($pdo3, 1, $eventUtc);
cdAssert($noNextClock === null, 'next work day without planned clock → unresolved');

// No politika → unresolved work day → fail closed
$pdo2 = new PDO('sqlite::memory:');
$pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo2->exec(
    'CREATE TABLE resmi_tatil_takvimi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tarih TEXT NOT NULL,
        durum TEXT NOT NULL,
        tatil_turu TEXT NOT NULL,
        revizyon_no INTEGER NOT NULL DEFAULT 1
     )'
);
$pdo2->exec(
    'CREATE TABLE sirket_calisma_politikalari (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        state TEXT NOT NULL,
        gecerlilik_baslangic TEXT NOT NULL,
        gecerlilik_bitis TEXT,
        revision_no INTEGER DEFAULT 1,
        policy_version_hash TEXT
     )'
);
$pdo2->exec(
    'CREATE TABLE sirket_calisma_politika_degerleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        politika_id INTEGER NOT NULL,
        parametre_kodu TEXT NOT NULL,
        metin_deger TEXT,
        sayisal_deger TEXT
     )'
);
$pdo2->exec(
    'CREATE TABLE gunluk_puantaj (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        personel_id INTEGER NOT NULL,
        tarih TEXT NOT NULL,
        beklenen_cikis_saati TEXT
     )'
);
$unresolved = AttendanceBusinessDayService::resolveCorrectionDeadlineUtc($pdo2, 9, $eventUtc);
cdAssert($unresolved === null, 'no politika → unresolved deadline');
$flag = AttendanceBusinessDayService::resolveWorkDay($pdo2, '2026-09-22');
cdAssert($flag === null, 'no politika → work day unresolved (not Mon-Fri invent)');

// Holiday table missing → fail closed
$pdo4 = new PDO('sqlite::memory:');
$pdo4->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$flagHoliday = AttendanceBusinessDayService::resolveWorkDay($pdo4, '2026-09-22');
cdAssert($flagHoliday === null, 'missing tatil table → unresolved');

$next = AttendanceBusinessDayService::nextWorkDay($pdo, '2026-09-21');
cdAssert($next === '2026-09-22', 'next work after monday');

$nextFri = AttendanceBusinessDayService::nextWorkDay($pdo, '2026-09-25');
cdAssert($nextFri === '2026-09-28', 'next work after friday skips weekend');

echo "[OK] AttendanceCorrectionDeadlinePureTestRunner\n";
