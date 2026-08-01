<?php

declare(strict_types=1);

/**
 * S87 PayrollComplianceGuard unit runner (no DB).
 * php tests/php/PayrollComplianceGuardTestRunner.php
 */

require_once __DIR__ . '/../../api/src/Services/Payroll/PayrollComplianceGuard.php';

use Medisa\Api\Services\Payroll\PayrollComplianceGuard;

function pcgAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$name}\n");
        exit(1);
    }
    echo "[PASS] {$name}\n";
}

// Age boundaries
$before = PayrollComplianceGuard::resolveUnder18('2008-08-02', '2026-08-01');
pcgAssert($before['under_18'] === true && $before['missing_dob'] === false, '18. dogum gununden 1 gun once block');

$onDay = PayrollComplianceGuard::resolveUnder18('2008-08-01', '2026-08-01');
pcgAssert($onDay['under_18'] === false && $onDay['missing_dob'] === false, '18. dogum gununde block yok');

$missing = PayrollComplianceGuard::resolveUnder18(null, '2026-08-01');
pcgAssert($missing['missing_dob'] === true, 'dogum tarihi yok fail-closed');

// Yillik 270 saat
$weeks = array_fill(0, 1, ['fazla_calisma_dakika' => 16199]);
$e1 = PayrollComplianceGuard::evaluateYillikLimit($weeks, 0);
pcgAssert($e1['asildi'] === false && $e1['projected'] === 16199, '16199 izin');

$e2 = PayrollComplianceGuard::evaluateYillikLimit([['fazla_calisma_dakika' => 16200]], 0);
pcgAssert($e2['asildi'] === false && $e2['projected'] === 16200, '16200 tam sinir izin');

$e3 = PayrollComplianceGuard::evaluateYillikLimit([['fazla_calisma_dakika' => 16200]], 1);
pcgAssert($e3['asildi'] === true, '16201 block');

$e4 = PayrollComplianceGuard::evaluateYillikLimit([['fazla_calisma_dakika' => 15600]], 0);
pcgAssert($e4['yaklasiyor'] === true && $e4['asildi'] === false, '15600 yaklasma');

// Haftalik band SIRKET_KARARI
$b1 = PayrollComplianceGuard::hesaplaHaftalikBantlarSirketKarari(2700);
pcgAssert($b1['fs_dk'] === 0 && $b1['fm_dk'] === 0, '2700 tam normal');

$b2 = PayrollComplianceGuard::hesaplaHaftalikBantlarSirketKarari(2701);
pcgAssert($b2['fs_dk'] === 0 && $b2['fm_dk'] === 1, '2701 FM; FSC yok');

pcgAssert(PayrollComplianceGuard::assertWeeklyMonthlyParity() === true, '225s / 2700dk parity');

// Serbest zaman kanit
$bad = PayrollComplianceGuard::validateSerbestZamanKanit(
    ['talep_tarihi' => '', 'imzali_talep_belge_id' => 0, 'gerekce' => ''],
    null,
    1
);
pcgAssert($bad['ok'] === false, 'belge yok reject');

$other = PayrollComplianceGuard::validateSerbestZamanKanit(
    ['talep_tarihi' => '2026-07-01', 'imzali_talep_belge_id' => 9, 'gerekce' => 'imzali talep'],
    ['personel_id' => 2, 'surec_turu' => 'BELGE', 'state' => 'AKTIF'],
    1
);
pcgAssert($other['ok'] === false, 'baska personel belgesi reject');

$ok = PayrollComplianceGuard::validateSerbestZamanKanit(
    ['talep_tarihi' => '2026-07-01', 'imzali_talep_belge_id' => 9, 'gerekce' => 'imzali talep'],
    ['personel_id' => 1, 'surec_turu' => 'BELGE', 'state' => 'AKTIF'],
    1
);
pcgAssert($ok['ok'] === true, 'gecerli kanit accept');

$iptal = PayrollComplianceGuard::validateSerbestZamanKanit(
    ['talep_tarihi' => '2026-07-01', 'imzali_talep_belge_id' => 9, 'gerekce' => 'x'],
    ['personel_id' => 1, 'surec_turu' => 'BELGE', 'state' => 'IPTAL'],
    1
);
pcgAssert($iptal['ok'] === false, 'iptal belge reject');

echo "ALL_PAYROLL_COMPLIANCE_GUARD_TESTS_PASSED\n";
exit(0);
