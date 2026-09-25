<?php

declare(strict_types=1);

/**
 * Proves HTTP body cannot spoof occurred_at or skip late/early / early-exit confirm.
 * Uses the same SQLite harness as EarlyExitConfirmScanSemanticsTestRunner.
 */
require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceEventService;
use Medisa\Api\Services\Qr\QrTokenService;

function sbsAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] $name\n");
        exit(1);
    }
    echo "[PASS] $name\n";
}

function sbsNonce(int $n): string
{
    return sprintf('c0000000-0000-4000-8000-%012x', $n);
}

function sbsSeed(PDO $pdo): array
{
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, personel_id INTEGER)');
    $pdo->exec(
        'CREATE TABLE personeller (
            id INTEGER PRIMARY KEY,
            ad TEXT, soyad TEXT, sube_id INTEGER, departman_id INTEGER, bolum_id INTEGER, birim_id INTEGER,
            gorev_id INTEGER, aktif_durum TEXT, sicil_no TEXT, tc_kimlik_no TEXT, dogum_tarihi TEXT,
            telefon TEXT, ise_giris_tarihi TEXT, personel_tipi_id INTEGER, calisan_kapsami TEXT
         )'
    );
    $pdo->exec('CREATE TABLE personel_tipleri (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, ad TEXT, kod TEXT)');
    $pdo->exec('CREATE TABLE departmanlar (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE bolumler (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE birimler (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec('CREATE TABLE gorevler (id INTEGER PRIMARY KEY, ad TEXT)');
    $pdo->exec(
        'CREATE TABLE qr_attendance_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            sube_id INTEGER NOT NULL,
            event_type TEXT NOT NULL,
            occurred_at_utc TEXT NOT NULL,
            qr_version INTEGER NOT NULL,
            qr_jti TEXT NOT NULL,
            qr_issued_at_utc TEXT NOT NULL,
            qr_expires_at_utc TEXT NOT NULL,
            request_nonce TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
         )'
    );
    $pdo->exec('CREATE UNIQUE INDEX uq_nonce ON qr_attendance_events(user_id, request_nonce)');
    $pdo->exec('CREATE UNIQUE INDEX uq_jti_type ON qr_attendance_events(user_id, qr_jti, event_type)');
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

    $pdo->exec("INSERT INTO personel_tipleri (id, ad) VALUES (1, 'Mavi Yaka')");
    $pdo->exec("INSERT INTO subeler (id, ad, kod) VALUES (10, 'Test Sube', 'T10')");
    $pdo->exec(
        "INSERT INTO personeller (
            id, ad, soyad, sube_id, aktif_durum, personel_tipi_id, calisan_kapsami
         ) VALUES (100, 'Ali', 'Veli', 10, 'AKTIF', 1, 'IC_PERSONEL')"
    );
    $pdo->exec('INSERT INTO users (id, personel_id) VALUES (50, 100)');
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, beklenen_giris_saati, beklenen_cikis_saati)
         VALUES (100, '2026-09-25', '08:30', '17:40')"
    );

    return [
        'auth' => ['id' => 50, 'rol' => 'PERSONEL'],
        'personel_id' => 100,
        'sube_id' => 10,
        'user_id' => 50,
    ];
}

function sbsOpenShift(PDO $pdo, array $ctx): void
{
    $pdo->exec(
        "INSERT INTO qr_attendance_events
            (personel_id, user_id, sube_id, event_type, occurred_at_utc,
             qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce)
         VALUES
            ({$ctx['personel_id']}, {$ctx['user_id']}, {$ctx['sube_id']}, 'GIRIS',
             '2026-09-25 05:31:00.000000', 1, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
             '2026-09-25 05:00:00.000000', '2026-09-25 06:00:00.000000', '" . sbsNonce(1) . "')"
    );
}

global $config;
$config['qr_signing_secret'] = 'sbs-test-qr-signing-secret-32chars!!';
$config['qr_ttl_seconds'] = 60;

$spoofPast = '2020-01-01 10:00:00.000000';
$early18Utc = '2026-09-25 14:22:00.000000';
$early31Utc = '2026-09-25 14:09:00.000000';

// A — body __test_occurred_at cannot override real clock
$pdoA = new PDO('sqlite::memory:');
$pdoA->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ctxA = sbsSeed($pdoA);
$mintedA = QrTokenService::mint($ctxA['sube_id']);
$resA = QrAttendanceEventService::scan($pdoA, $ctxA['auth'], [
    'token' => $mintedA['token'],
    'event_type' => 'GIRIS',
    'request_nonce' => sbsNonce(10),
    '__test_occurred_at' => $spoofPast,
]);
sbsAssert(is_array($resA['event'] ?? null), 'A event written');
$storedA = $pdoA->query('SELECT occurred_at_utc FROM qr_attendance_events WHERE event_type = \'GIRIS\'')->fetchColumn();
sbsAssert(is_string($storedA) && $storedA !== '', 'A stored occurred_at');
sbsAssert(strpos((string) $storedA, '2020-01-01') === false, 'A spoof past rejected');
sbsAssert(strpos((string) $resA['event']['occurred_at'] ?? '', '2020') === false, 'A public event not spoofed');

// B — body __skip_late_early cannot bypass early-exit confirm
$pdoB = new PDO('sqlite::memory:');
$pdoB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ctxB = sbsSeed($pdoB);
sbsOpenShift($pdoB, $ctxB);
$mintedB = QrTokenService::mint($ctxB['sube_id']);
$beforeB = (int) $pdoB->query("SELECT COUNT(*) FROM qr_attendance_events WHERE event_type = 'CIKIS'")->fetchColumn();
$resB = QrAttendanceEventService::scan(
    $pdoB,
    $ctxB['auth'],
    [
        'token' => $mintedB['token'],
        'event_type' => 'CIKIS',
        'request_nonce' => sbsNonce(20),
        '__skip_late_early' => true,
    ],
    ['occurred_at_utc' => $early18Utc]
);
sbsAssert(!empty($resB['confirmation_required']), 'B confirmation still required');
sbsAssert($resB['event'] === null, 'B no event');
$afterB = (int) $pdoB->query("SELECT COUNT(*) FROM qr_attendance_events WHERE event_type = 'CIKIS'")->fetchColumn();
sbsAssert($afterB === $beforeB, 'B no CIKIS write');

// C — 31dk early confirmed real flow still yields post-info
$pdoC = new PDO('sqlite::memory:');
$pdoC->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ctxC = sbsSeed($pdoC);
sbsOpenShift($pdoC, $ctxC);
$mintedC = QrTokenService::mint($ctxC['sube_id']);
$resC = QrAttendanceEventService::scan(
    $pdoC,
    $ctxC['auth'],
    [
        'token' => $mintedC['token'],
        'event_type' => 'CIKIS',
        'request_nonce' => sbsNonce(30),
        'early_exit_confirmed' => true,
        '__skip_late_early' => true,
        '__test_occurred_at' => $early31Utc,
    ],
    ['occurred_at_utc' => $early31Utc]
);
sbsAssert(empty($resC['confirmation_required']), 'C confirmed write');
sbsAssert(is_array($resC['late_early_info'] ?? null), 'C late_early_info present despite body skip');
sbsAssert(($resC['late_early_info']['kind'] ?? '') === 'EARLY_EXIT_INFO', 'C EARLY_EXIT_INFO');
sbsAssert((int) ($resC['late_early_info']['delta_dakika'] ?? 0) === 31, 'C delta 31');

// D — public contract keys only (source lock)
$scanSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Qr/QrAttendanceEventService.php');
sbsAssert(is_string($scanSrc), 'D source readable');
sbsAssert(strpos($scanSrc, "__test_occurred_at") === false, 'D no __test_occurred_at body read');
sbsAssert(strpos($scanSrc, "__skip_late_early") === false, 'D no __skip_late_early body read');
sbsAssert(strpos($scanSrc, 'occurred_at_utc') !== false, 'D internal clock seam present');
sbsAssert(strpos($scanSrc, 'skip_late_early') !== false, 'D internal skip seam present');

echo "[OK] ScanBodyBypassSecurityTestRunner\n";
