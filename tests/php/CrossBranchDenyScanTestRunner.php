<?php

declare(strict_types=1);

/**
 * Post-verify cross-branch deny: token sube_id ≠ personel sube_id → QR_CROSS_BRANCH_DENIED, no INSERT.
 * SQLite harness — mirrors EarlyExitConfirmScanSemanticsTestRunner / ScanBodyBypassSecurityTestRunner.
 *
 * php tests/php/CrossBranchDenyScanTestRunner.php
 */
require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceEventService;
use Medisa\Api\Services\Qr\QrAttendanceException;
use Medisa\Api\Services\Qr\QrTokenService;

function cbdAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] $name\n");
        exit(1);
    }
    echo "[PASS] $name\n";
}

function cbdNonce(int $n): string
{
    return sprintf('d0000000-0000-4000-8000-%012x', $n);
}

function cbdEventCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events')->fetchColumn();
}

/** @return array{auth: array<string, mixed>, personel_id: int, sube_id: int, user_id: int} */
function cbdSeed(PDO $pdo): array
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
    $pdo->exec("INSERT INTO subeler (id, ad, kod) VALUES (10, 'Personel Sube', 'T10')");
    $pdo->exec("INSERT INTO subeler (id, ad, kod) VALUES (20, 'Other Sube', 'T20')");
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

/** @return string|null */
function cbdCatchCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (QrAttendanceException $e) {
        return $e->getErrorCode();
    }

    return null;
}

/** @return int|null */
function cbdCatchHttpStatus(callable $fn): ?int
{
    try {
        $fn();
    } catch (QrAttendanceException $e) {
        return $e->getHttpStatus();
    }

    return null;
}

global $config;
$config['qr_signing_secret'] = 'cbd-test-qr-signing-secret-32chars!!';
$config['qr_ttl_seconds'] = 60;

$otherSubeId = 20;
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ctx = cbdSeed($pdo);

$before = cbdEventCount($pdo);
$mintedOther = QrTokenService::mint($otherSubeId);
cbdAssert(is_string($mintedOther['token'] ?? null), 'mint other-branch token');

$denyCode = cbdCatchCode(static function () use ($pdo, $ctx, $mintedOther) {
    QrAttendanceEventService::scan($pdo, $ctx['auth'], [
        'token' => $mintedOther['token'],
        'event_type' => 'GIRIS',
        'request_nonce' => cbdNonce(1),
    ]);
});
$denyStatus = cbdCatchHttpStatus(static function () use ($pdo, $ctx, $mintedOther) {
    QrAttendanceEventService::scan($pdo, $ctx['auth'], [
        'token' => $mintedOther['token'],
        'event_type' => 'GIRIS',
        'request_nonce' => cbdNonce(2),
    ]);
});

cbdAssert($denyCode === 'QR_CROSS_BRANCH_DENIED', 'cross-branch error code');
cbdAssert($denyStatus === 403, 'cross-branch HTTP 403');
cbdAssert(cbdEventCount($pdo) === $before, 'zero qr_attendance_events rows after deny');
cbdAssert($before === 0, 'harness starts with zero events');

// Control: matching sube still writes
$mintedHome = QrTokenService::mint($ctx['sube_id']);
$resOk = QrAttendanceEventService::scan($pdo, $ctx['auth'], [
    'token' => $mintedHome['token'],
    'event_type' => 'GIRIS',
    'request_nonce' => cbdNonce(3),
]);
cbdAssert(is_array($resOk['event'] ?? null), 'matching sube writes GIRIS');
cbdAssert(cbdEventCount($pdo) === 1, 'exactly one row after valid scan');

echo "[OK] CrossBranchDenyScanTestRunner\n";
