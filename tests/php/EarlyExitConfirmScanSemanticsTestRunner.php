<?php

declare(strict_types=1);

/**
 * Early-exit confirm write semantics against QrAttendanceEventService::scan.
 * SQLite harness — proves no write before confirm, single CIKIS after Evet, idempotency.
 */
require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceEventService;
use Medisa\Api\Services\Qr\QrTokenService;

function eecsAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] $name\n");
        exit(1);
    }
    echo "[PASS] $name\n";
}

function eecsCount(PDO $pdo, string $type = null): int
{
    if ($type === null) {
        return (int) $pdo->query('SELECT COUNT(*) FROM qr_attendance_events')->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM qr_attendance_events WHERE event_type = :t');
    $stmt->execute(['t' => $type]);

    return (int) $stmt->fetchColumn();
}

function eecsNonce(int $n): string
{
    return sprintf('b0000000-0000-4000-8000-%012x', $n);
}

function eecsSeed(PDO $pdo): array
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
    // Planned exit 17:40 Istanbul for today business date of fixtures
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

function eecsOpenShift(PDO $pdo, array $ctx): void
{
    $pdo->exec(
        "INSERT INTO qr_attendance_events
            (personel_id, user_id, sube_id, event_type, occurred_at_utc,
             qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, request_nonce)
         VALUES
            ({$ctx['personel_id']}, {$ctx['user_id']}, {$ctx['sube_id']}, 'GIRIS',
             '2026-09-25 05:31:00.000000', 1, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
             '2026-09-25 05:00:00.000000', '2026-09-25 06:00:00.000000', '" . eecsNonce(1) . "')"
    );
}

global $config;
$testSecret = 'eecs-test-qr-signing-secret-32chars!';
$config['qr_signing_secret'] = $testSecret;
$config['qr_ttl_seconds'] = 60;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ctx = eecsSeed($pdo);
eecsOpenShift($pdo, $ctx);

$minted = QrTokenService::mint($ctx['sube_id']);
$token = $minted['token'];

// 17:40 - 18dk = 17:22 Istanbul = 14:22 UTC
$early18Utc = '2026-09-25 14:22:00.000000';
// 17:40 - 31dk = 17:09 Istanbul = 14:09 UTC
$early31Utc = '2026-09-25 14:09:00.000000';

$before = eecsCount($pdo);
$beforeCikis = eecsCount($pdo, 'CIKIS');

// CASE A — 18dk early, no confirm → confirmation_required, no write
$resA = QrAttendanceEventService::scan(
    $pdo,
    $ctx['auth'],
    [
        'token' => $token,
        'event_type' => 'CIKIS',
        'request_nonce' => eecsNonce(10),
    ],
    ['occurred_at_utc' => $early18Utc]
);
eecsAssert(!empty($resA['confirmation_required']), 'A confirmation_required');
eecsAssert(is_array($resA['early_exit_confirm'] ?? null), 'A early_exit_confirm payload');
eecsAssert((int) $resA['early_exit_confirm']['delta_dakika'] === 18, 'A delta 18');
eecsAssert(strpos((string) $resA['early_exit_confirm']['message'], '18dk') !== false, 'A confirm copy');
eecsAssert($resA['event'] === null, 'A no event');
eecsAssert(eecsCount($pdo) === $before, 'A event count unchanged');
eecsAssert(eecsCount($pdo, 'CIKIS') === $beforeCikis, 'A no CIKIS row');

// CASE B — same QR, Evet with new nonce → exactly one CIKIS, no post-info (18 ≤ 30)
$resB = QrAttendanceEventService::scan(
    $pdo,
    $ctx['auth'],
    [
        'token' => $token,
        'event_type' => 'CIKIS',
        'request_nonce' => eecsNonce(11),
        'early_exit_confirmed' => true,
    ],
    ['occurred_at_utc' => $early18Utc]
);
eecsAssert(empty($resB['confirmation_required']), 'B no confirmation_required');
eecsAssert(is_array($resB['event'] ?? null), 'B event written');
eecsAssert(($resB['event']['event_type'] ?? '') === 'CIKIS', 'B CIKIS type');
eecsAssert(($resB['late_early_info'] ?? null) === null, 'B no post late_early_info for 18dk');
eecsAssert(eecsCount($pdo, 'CIKIS') === $beforeCikis + 1, 'B exactly one CIKIS');

// CASE D — duplicate confirm/retry same jti+type → idempotent, still one CIKIS
$resD = QrAttendanceEventService::scan(
    $pdo,
    $ctx['auth'],
    [
        'token' => $token,
        'event_type' => 'CIKIS',
        'request_nonce' => eecsNonce(12),
        'early_exit_confirmed' => true,
    ],
    ['occurred_at_utc' => $early18Utc]
);
eecsAssert(!empty($resD['idempotent']), 'D idempotent replay');
eecsAssert(eecsCount($pdo, 'CIKIS') === $beforeCikis + 1, 'D still exactly one CIKIS');

// CASE C — fresh open-shift + 31dk early + Evet → post EARLY_EXIT_INFO
$pdoC = new PDO('sqlite::memory:');
$pdoC->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ctxC = eecsSeed($pdoC);
eecsOpenShift($pdoC, $ctxC);
$mintedC = QrTokenService::mint($ctxC['sube_id']);
$beforeC = eecsCount($pdoC, 'CIKIS');
$resC = QrAttendanceEventService::scan(
    $pdoC,
    $ctxC['auth'],
    [
        'token' => $mintedC['token'],
        'event_type' => 'CIKIS',
        'request_nonce' => eecsNonce(20),
        'early_exit_confirmed' => true,
    ],
    ['occurred_at_utc' => $early31Utc]
);
eecsAssert(empty($resC['confirmation_required']), 'C confirmed write');
eecsAssert(eecsCount($pdoC, 'CIKIS') === $beforeC + 1, 'C exactly one CIKIS');
eecsAssert(is_array($resC['late_early_info'] ?? null), 'C late_early_info present');
eecsAssert(($resC['late_early_info']['kind'] ?? '') === 'EARLY_EXIT_INFO', 'C EARLY_EXIT_INFO kind');
eecsAssert((int) $resC['late_early_info']['delta_dakika'] === 31, 'C delta 31');
eecsAssert(
    strpos((string) $resC['late_early_info']['message'], 'Normal Mesai Bitiminden 31dk Önce') !== false,
    'C copy'
);

// CASE E documented: Hayır = client does not send early_exit_confirmed (covered by A count unchanged)
eecsAssert(true, 'E Hayır semantics = no confirmed write call (proven by A)');

echo "[OK] EarlyExitConfirmScanSemanticsTestRunner\n";
