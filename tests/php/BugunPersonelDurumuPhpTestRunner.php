<?php

declare(strict_types=1);

/**
 * SQLite-free pure checks + small in-memory roster for BugunPersonelDurumuService.
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

bpdAssert(BugunPersonelDurumuService::deriveDurum(null) === 'GELDI', 'null → GELDI');
bpdAssert(BugunPersonelDurumuService::deriveDurum('GEC_GELDI') === 'GEC_GELDI', 'GEC_GELDI stays');
bpdAssert(BugunPersonelDurumuService::deriveDurum('IZINLI') === 'IZINLI', 'IZINLI stays');

bpdAssert(BugunPersonelDurumuService::resolveLateMinutes('08:47', null) === 17, '08:47 → 17 dk');
bpdAssert(BugunPersonelDurumuService::resolveLateMinutes('09:18', null) === 48, '09:18 → 48 dk');
bpdAssert(BugunPersonelDurumuService::resolveLateMinutes('08:30', null) === null, '08:30 → not late');
bpdAssert(BugunPersonelDurumuService::resolveLateMinutes('08:47', 20) === 20, 'stored dakika wins');

$tz = new \DateTimeZone(BugunPersonelDurumuService::TIMEZONE);
$tarih = '2026-09-04';

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

$overdue = BugunPersonelDurumuService::classifyCompletionStatus(
    null,
    $tarih,
    new \DateTimeImmutable('2026-09-04 09:31:00', $tz)
);
bpdAssert($overdue === BugunPersonelDurumuService::COMPLETION_SURESI_GECTI, '09:31 no completion overdue');

$waiting = BugunPersonelDurumuService::classifyCompletionStatus(
    null,
    $tarih,
    new \DateTimeImmutable('2026-09-04 09:00:00', $tz)
);
bpdAssert($waiting === BugunPersonelDurumuService::COMPLETION_BEKLENIYOR, '09:00 waiting');

bpdOk('BugunPersonelDurumuService pure semantics');
echo "ALL_PASS bugun-personel-durumu\n";
