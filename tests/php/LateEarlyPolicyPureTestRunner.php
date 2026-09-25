<?php

declare(strict_types=1);

/**
 * Focused Late/Early 30dk policy + early-exit confirmation + duration copy.
 * FINANCIAL_EFFECT = NONE.
 */
require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Attendance\LateEarlyInfoService;

function leAssert(bool $ok, string $name): void
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

// Istanbul = UTC+3 in these fixtures (no DST in Sep).

// Early arrival -60 → no warning
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 04:30:00.000000', $planned);
leAssert($r === null, 'early arrival -60 no warning');

// +0
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 05:30:00.000000', $planned);
leAssert($r === null, 'late +0 no warning');

// +1
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 05:31:00.000000', $planned);
leAssert($r === null, 'late +1 no warning');

// +29
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 05:59:00.000000', $planned);
leAssert($r === null, 'late +29 no warning');

// +30
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 06:00:00.000000', $planned);
leAssert($r === null, 'late +30 no warning');

// +31
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 06:01:00.000000', $planned);
leAssert(is_array($r) && (int) $r['delta_dakika'] === 31, 'late +31 warning');
leAssert(strpos((string) $r['message'], '31dk Geç Geldiniz') !== false, 'late +31 copy');
leAssert((string) $r['card_label'] === '31dk Gecikme', 'late +31 card');
leAssert((string) $r['notification_title'] === 'Geç Giriş', 'late notification title');
leAssert(strpos((string) $r['notification_body'], 'Ücret Kesintisi') !== false, 'late wage info sentence');

// +65 → 1 Saat 5dk
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 06:35:00.000000', $planned);
leAssert(is_array($r) && (int) $r['delta_dakika'] === 65, 'late +65 delta');
leAssert(strpos((string) $r['message'], '1 Saat 5dk Geç Geldiniz') !== false, 'late +65 human copy');

// Late exit +60 → no warning
$r = LateEarlyInfoService::evaluateAfterScan('CIKIS', '2026-09-25 15:40:00.000000', $planned);
leAssert($r === null, 'late exit +60 no warning');

// Late exit +1
$r = LateEarlyInfoService::evaluateAfterScan('CIKIS', '2026-09-25 14:41:00.000000', $planned);
leAssert($r === null, 'late exit +1 no warning');

// Exact exit
$r = LateEarlyInfoService::evaluateAfterScan('CIKIS', '2026-09-25 14:40:00.000000', $planned);
leAssert($r === null, 'exact exit no warning');

// Early exit 1 → no post-warning, but confirm YES
$r = LateEarlyInfoService::evaluateAfterScan('CIKIS', '2026-09-25 14:39:00.000000', $planned);
leAssert($r === null, 'early exit 1 no post-warning');
$c = LateEarlyInfoService::evaluateEarlyExitConfirmation('2026-09-25 14:39:00.000000', $planned);
leAssert(is_array($c) && (int) $c['delta_dakika'] === 1, 'early exit 1 confirm');
leAssert(strpos((string) $c['message'], 'Çıkış Saatine 1dk Var') !== false, 'early exit 1 confirm copy');

// Early 29 / 30 → confirm, no post
$r = LateEarlyInfoService::evaluateAfterScan('CIKIS', '2026-09-25 14:11:00.000000', $planned);
leAssert($r === null, 'early exit 29 no post-warning');
$c = LateEarlyInfoService::evaluateEarlyExitConfirmation('2026-09-25 14:11:00.000000', $planned);
leAssert(is_array($c) && (int) $c['delta_dakika'] === 29, 'early exit 29 confirm');

$r = LateEarlyInfoService::evaluateAfterScan('CIKIS', '2026-09-25 14:10:00.000000', $planned);
leAssert($r === null, 'early exit 30 no post-warning');
$c = LateEarlyInfoService::evaluateEarlyExitConfirmation('2026-09-25 14:10:00.000000', $planned);
leAssert(is_array($c) && (int) $c['delta_dakika'] === 30, 'early exit 30 confirm');

// Early 31 → confirm + post
$r = LateEarlyInfoService::evaluateAfterScan('CIKIS', '2026-09-25 14:09:00.000000', $planned);
leAssert(is_array($r) && (int) $r['delta_dakika'] === 31, 'early exit 31 post-warning');
leAssert(strpos((string) $r['message'], 'Normal Mesai Bitiminden 31dk Önce') !== false, 'early exit 31 copy');
$c = LateEarlyInfoService::evaluateEarlyExitConfirmation('2026-09-25 14:09:00.000000', $planned);
leAssert(is_array($c), 'early exit 31 still confirms');

// 4s12dk = 252 minutes early → 17:40 - 4h12m = 13:28
$r = LateEarlyInfoService::evaluateAfterScan('CIKIS', '2026-09-25 10:28:00.000000', $planned);
leAssert(is_array($r) && (int) $r['delta_dakika'] === 252, 'early exit 4s12dk delta');
leAssert(strpos((string) $r['message'], '4 Saat 12dk') !== false, 'early exit 4s12dk copy');
$c = LateEarlyInfoService::evaluateEarlyExitConfirmation('2026-09-25 10:28:00.000000', $planned);
leAssert(is_array($c) && strpos((string) $c['message'], '4 Saat 12dk') !== false, 'early exit 4s12dk confirm copy');

// No planned → no invented warning / confirm
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 06:01:00.000000', null);
leAssert($r === null, 'no planned late → null');
$c = LateEarlyInfoService::evaluateEarlyExitConfirmation('2026-09-25 10:28:00.000000', null);
leAssert($c === null, 'no planned early confirm → null');

// Defaults are 30
leAssert(LateEarlyInfoService::DEFAULT_GEC_TOLERANS_DK === 30, 'default gec 30');
leAssert(LateEarlyInfoService::DEFAULT_ERKEN_TOLERANS_DK === 30, 'default erken 30');

// Explicit 0 tolerance still works for legacy callers
$r = LateEarlyInfoService::evaluateAfterScan('GIRIS', '2026-09-25 05:42:00.000000', $planned, 0, 0);
leAssert(is_array($r) && (int) $r['delta_dakika'] === 12, 'explicit 0 tolerance late 12');

// Multi-cycle: second GIRIS is not late
$r = LateEarlyInfoService::evaluateAfterScan(
    'GIRIS',
    '2026-09-25 09:05:00.000000',
    $planned,
    null,
    null,
    ['is_first_giris' => false]
);
leAssert($r === null, 'second giris same day → no late');

// Multi-cycle: mid-day CIKIS write path suppresses early-exit info
$r = LateEarlyInfoService::evaluateAfterScan(
    'CIKIS',
    '2026-09-25 08:20:00.000000',
    $planned,
    null,
    null,
    ['evaluate_early_exit' => false]
);
leAssert($r === null, 'mid-day cikis write → no early-exit info');

// Final CIKIS early still evaluates when flagged
$r = LateEarlyInfoService::evaluateAfterScan(
    'CIKIS',
    '2026-09-25 14:09:00.000000',
    $planned,
    null,
    null,
    ['is_final_cikis' => true, 'evaluate_early_exit' => true]
);
leAssert(is_array($r) && (int) $r['delta_dakika'] === 31, 'final cikis early 31 still warns');

echo "[OK] LateEarlyPolicyPureTestRunner\n";
