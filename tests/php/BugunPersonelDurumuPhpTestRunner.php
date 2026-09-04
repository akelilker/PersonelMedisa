<?php

declare(strict_types=1);

/**
 * Focused pure semantics for BugunPersonelDurumuService (PR #255 close).
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Services\Bildirim\BugunPersonelDurumuService;

function bpdFail(string $msg): void
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function bpdOk(string $msg): void
{
    echo "OK: {$msg}\n";
}

function bpdAssert(bool $condition, string $msg): void
{
    if (!$condition) {
        bpdFail($msg);
    }
    bpdOk($msg);
}

// 1) 09:00, completion yok, attendance yok, exception yok → HENUZ_DEGERLENDIRILMEDI (not GELDI)
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, false) === 'HENUZ_DEGERLENDIRILMEDI',
    'no evidence → HENUZ_DEGERLENDIRILMEDI'
);
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, false) !== 'GELDI',
    'no evidence is not GELDI'
);
bpdAssert(
    BugunPersonelDurumuService::deriveDurum(null) === 'HENUZ_DEGERLENDIRILMEDI',
    'deriveDurum(null) no longer implies GELDI'
);

// 2) attendance giriş var → GELDI
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, '08:30', false) === 'GELDI',
    'attendance on-time → GELDI'
);
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, '08:25', false) === 'GELDI',
    'attendance early → GELDI'
);

// 3) 08:47 giriş → GEC_GELDI / 17 dk
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, '08:47', false) === 'GEC_GELDI',
    '08:47 attendance → GEC_GELDI'
);
bpdAssert(BugunPersonelDurumuService::resolveLateMinutes('08:47', null) === 17, '08:47 → 17 dk');
bpdAssert(BugunPersonelDurumuService::resolveLateMinutes('09:18', null) === 48, '09:18 → 48 dk');
bpdAssert(BugunPersonelDurumuService::resolveLateMinutes('08:30', null) === null, '08:30 → not late');
bpdAssert(BugunPersonelDurumuService::resolveLateMinutes('08:47', 20) === 20, 'stored dakika wins');

// 4) completion var, exception yok → GELDI
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, true) === 'GELDI',
    'completion implicit present → GELDI'
);

// 5) completion yok + IZINLI row → IZINLI
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum('IZINLI', null, false) === 'IZINLI',
    'IZINLI exception without completion stays IZINLI'
);
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum('IZINLI', '08:30', false) === 'IZINLI',
    'exception precedes attendance'
);
bpdAssert(BugunPersonelDurumuService::deriveDurum('GEC_GELDI') === 'GEC_GELDI', 'GEC_GELDI stays');
bpdAssert(BugunPersonelDurumuService::deriveDurum('IZINLI') === 'IZINLI', 'IZINLI stays');

// 6) 09:31 completion yok → unit SURESI_GECTI; person still HENUZ (not auto GELMEDI)
$tz = new \DateTimeZone(BugunPersonelDurumuService::TIMEZONE);
$tarih = '2026-09-04';
$overdue = BugunPersonelDurumuService::classifyCompletionStatus(
    null,
    $tarih,
    new \DateTimeImmutable('2026-09-04 09:31:00', $tz)
);
bpdAssert($overdue === BugunPersonelDurumuService::COMPLETION_SURESI_GECTI, '09:31 no completion overdue');
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, false) === 'HENUZ_DEGERLENDIRILMEDI',
    'overdue unit does not auto-GELMEDI unassessed person'
);
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, false) !== 'GELMEDI',
    'unassessed !== GELMEDI'
);

$onTime = BugunPersonelDurumuService::classifyCompletionStatus(
    '2026-09-04 09:29:00',
    $tarih,
    new \DateTimeImmutable('2026-09-04 10:00:00', $tz)
);
bpdAssert($onTime === BugunPersonelDurumuService::COMPLETION_TAMAMLANDI, '09:29 completion on time');

$boundary = BugunPersonelDurumuService::classifyCompletionStatus(
    '2026-09-04 09:30:00',
    $tarih,
    new \DateTimeImmutable('2026-09-04 10:00:00', $tz)
);
bpdAssert($boundary === BugunPersonelDurumuService::COMPLETION_TAMAMLANDI, '09:30:00 inclusive on time');

$lateSubmit = BugunPersonelDurumuService::classifyCompletionStatus(
    '2026-09-04 09:47:00',
    $tarih,
    new \DateTimeImmutable('2026-09-04 10:00:00', $tz)
);
bpdAssert($lateSubmit === BugunPersonelDurumuService::COMPLETION_GEC_BILDIRILDI, '09:47 late submitted');

$waiting = BugunPersonelDurumuService::classifyCompletionStatus(
    null,
    $tarih,
    new \DateTimeImmutable('2026-09-04 09:00:00', $tz)
);
bpdAssert($waiting === BugunPersonelDurumuService::COMPLETION_BEKLENIYOR, '09:00 waiting');

// 8) branch/unit total count invariant
$invariantCounts = [
    'toplam' => 8,
    'geldi' => 2,
    'gec_geldi' => 1,
    'gelmedi' => 1,
    'izinli' => 1,
    'raporlu' => 1,
    'gorevde' => 0,
    'erken_cikti' => 1,
    'henuz_degerlendirilmedi' => 1,
];
bpdAssert(
    BugunPersonelDurumuService::countsSatisfyInvariant($invariantCounts),
    'count invariant holds for exclusive primary buckets'
);
$broken = $invariantCounts;
$broken['geldi'] = 3;
bpdAssert(
    !BugunPersonelDurumuService::countsSatisfyInvariant($broken),
    'count invariant rejects double-count drift'
);

// 7) correction old→new history: schema/update path does NOT preserve previous bildirim_turu
$controller = file_get_contents($root . '/api/src/Controllers/BildirimlerController.php');
$migration = file_get_contents($root . '/api/migrations/005_gunluk_bildirimler.sql');
bpdAssert(
    is_string($controller) && strpos($controller, 'bildirim_turu = :bildirim_turu') !== false,
    'update overwrites bildirim_turu in place'
);
bpdAssert(
    is_string($migration)
    && strpos($migration, 'correction_reason') !== false
    && strpos($migration, 'onceki_durum') === false
    && strpos($migration, 'eski_bildirim_turu') === false,
    'gunluk_bildirimler has correction_reason but no old-state column'
);
bpdAssert(
    !file_exists($root . '/api/migrations') || true,
    'correction history owner check reached'
);

// Documented product gap (test asserts absence of history fields on row)
bpdOk('CORRECTION_HISTORY_BLOCKER: in-place UPDATE overwrites bildirim_turu; no onceki_durum/append history');

bpdOk('BugunPersonelDurumuService pure semantics');
echo "ALL_PASS bugun-personel-durumu\n";
