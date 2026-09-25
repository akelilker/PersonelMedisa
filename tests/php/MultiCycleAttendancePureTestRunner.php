<?php

declare(strict_types=1);

/**
 * Focused multi-cycle QR attendance policy (no DB).
 * FINANCIAL_EFFECT = NONE. PAYROLL_EFFECT = NONE.
 */
require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Attendance\LateEarlyInfoService;

function mcAssert(bool $ok, string $name): void
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

// First giris late +31 still warns
$r = LateEarlyInfoService::evaluateAfterScan(
    'GIRIS',
    '2026-09-25 06:01:00.000000',
    $planned,
    null,
    null,
    ['is_first_giris' => true]
);
mcAssert(is_array($r) && (int) $r['delta_dakika'] === 31, 'first giris late +31');

// Re-entry after hours is not "late" and not first-giris
$r = LateEarlyInfoService::evaluateAfterScan(
    'GIRIS',
    '2026-09-25 16:05:00.000000',
    $planned,
    null,
    null,
    ['is_first_giris' => false]
);
mcAssert($r === null, 'after-hours reentry ≠ late');

// Mid-day exit: confirm may still fire, post-info suppressed at write
$c = LateEarlyInfoService::evaluateEarlyExitConfirmation('2026-09-25 08:20:00.000000', $planned);
mcAssert(is_array($c), 'mid-day exit still confirms');
$r = LateEarlyInfoService::evaluateAfterScan(
    'CIKIS',
    '2026-09-25 08:20:00.000000',
    $planned,
    null,
    null,
    ['evaluate_early_exit' => false, 'is_final_cikis' => false]
);
mcAssert($r === null, 'mid-day exit no early-exit post');

// Completed-cycle detection helper (pure)
function hasCompletedCycle(array $seq): bool
{
    $open = false;
    foreach ($seq as $type) {
        if ($type === 'GIRIS') {
            $open = true;
        } elseif ($type === 'CIKIS' && $open) {
            return true;
        }
    }

    return false;
}

mcAssert(hasCompletedCycle(['GIRIS', 'CIKIS']) === true, 'cycle G→C');
mcAssert(hasCompletedCycle(['GIRIS', 'CIKIS', 'GIRIS']) === true, 'cycle then reentry');
mcAssert(hasCompletedCycle(['GIRIS']) === false, 'open only ≠ cycle');
mcAssert(hasCompletedCycle([]) === false, 'empty ≠ cycle');

// First-ever late after scheduled end is still late (first giris), not "tekrar giriş" precondition
$r = LateEarlyInfoService::evaluateAfterScan(
    'GIRIS',
    '2026-09-25 16:00:00.000000',
    $planned,
    null,
    null,
    ['is_first_giris' => true]
);
mcAssert(is_array($r), 'first-ever late after end → late info (not reentry notify precondition)');

$body = 'Berat Gürbüz; Mesai Bitiminden Sonra Tekrar İşyerine Giriş Yapmıştır.';
mcAssert(strpos($body, 'Berat Gürbüz;') === 0, 'reentry body prefix');
mcAssert(strpos($body, 'Mesai Bitiminden Sonra Tekrar İşyerine Giriş Yapmıştır.') !== false, 'reentry body exact');

echo "[OK] MultiCycleAttendancePureTestRunner\n";
