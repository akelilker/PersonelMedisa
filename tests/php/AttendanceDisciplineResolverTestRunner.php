<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Attendance\AttendanceDisciplineCatalog;
use Medisa\Api\Services\Attendance\AttendancePayrollEffectResolver;
use Medisa\Api\Services\Attendance\DisiplinAdayProjectionService;

function adrAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** @param array<string, mixed> $row */
function adrLate(array $row)
{
    return AttendancePayrollEffectResolver::resolveLateDeduction($row);
}

/** @param array<string, mixed> $row */
function adrEarly(array $row)
{
    return AttendancePayrollEffectResolver::resolveEarlyDeduction($row);
}

// Scenario 1: raw 20 + TOLERANS → effective 0, raw preserved
$r1 = adrLate(['gec_kalma_dakika' => 20, 'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA]);
adrAssert($r1['raw'] === 20, 'S1 raw 20 preserved with TOLERANS');
adrAssert($r1['effective'] === 0, 'S1 effective 0 with TOLERANS');

// Scenario 2: KESINTI → effective 20
$r2 = adrLate(['gec_kalma_dakika' => 20, 'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA]);
adrAssert($r2['effective'] === 20, 'S2 KESINTI effective 20');

// Scenario 3: 40 minutes no rounding
$r3 = adrLate(['gec_kalma_dakika' => 40]);
adrAssert($r3['effective'] === 40, 'S3 raw 40 no rounding');

// Scenario 4: 90 minutes no rounding
$r4 = adrLate(['gec_kalma_dakika' => 90]);
adrAssert($r4['effective'] === 90, 'S4 raw 90 no rounding');

// Scenario 5: notified + no karar → block, effective 0
$r5 = adrLate(['gec_kalma_dakika' => 25, 'durumu_bildirdi_mi' => 1]);
adrAssert($r5['block'] === true, 'S5 notified no karar blocks');
adrAssert($r5['effective'] === 0, 'S5 notified no karar effective 0');

// Scenario 6: notified + BEKLIYOR → block
$r6 = adrLate([
    'gec_kalma_dakika' => 30,
    'durumu_bildirdi_mi' => 1,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_BEKLIYOR,
]);
adrAssert($r6['block'] === true, 'S6 notified BEKLIYOR blocks');

// Scenario 7: OFFICIAL_PROCESS_REQUIRED → effective 0
$r7 = adrLate([
    'gec_kalma_dakika' => 45,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_OFFICIAL_PROCESS_REQUIRED,
]);
adrAssert($r7['effective'] === 0, 'S7 OFFICIAL_PROCESS effective 0');

// Scenario 8: zero raw late
$r8 = adrLate(['gec_kalma_dakika' => 0]);
adrAssert($r8['effective'] === 0, 'S8 zero raw effective 0');

// Scenario 9: unannounced late default applies raw
$r9 = adrLate(['gec_kalma_dakika' => 15, 'durumu_bildirdi_mi' => 0]);
adrAssert($r9['effective'] === 15, 'S9 unannounced late effective equals raw');
adrAssert($r9['block'] === false, 'S9 unannounced late not blocked');

// Scenario 10: notified + KESINTI → effective raw, not blocked
$r10 = adrLate([
    'gec_kalma_dakika' => 22,
    'durumu_bildirdi_mi' => 1,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
]);
adrAssert($r10['block'] === false, 'S10 notified KESINTI not blocked');
adrAssert($r10['effective'] === 22, 'S10 notified KESINTI effective raw');

// Scenario 15: early 25 raw default
$r15 = adrEarly(['erken_cikis_dakika' => 25, 'durumu_bildirdi_mi' => 0]);
adrAssert($r15['raw'] === 25, 'S15 early raw 25 preserved');
adrAssert($r15['effective'] === 25, 'S15 early unannounced effective 25');

// Scenario 16: early notified no karar → block
$r16 = adrEarly(['erken_cikis_dakika' => 25, 'durumu_bildirdi_mi' => 1]);
adrAssert($r16['block'] === true, 'S16 early notified blocks');
adrAssert($r16['effective'] === 0, 'S16 early notified effective 0');

// Scenario 17: early TOLERANS → effective 0
$r17 = adrEarly([
    'erken_cikis_dakika' => 25,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
]);
adrAssert($r17['effective'] === 0, 'S17 early TOLERANS effective 0');

// Scenario 18: early 40/90 no rounding
$r18a = adrEarly(['erken_cikis_dakika' => 40]);
adrAssert($r18a['effective'] === 40, 'S18 early 40 no rounding');
$r18b = adrEarly(['erken_cikis_dakika' => 90]);
adrAssert($r18b['effective'] === 90, 'S18 early 90 no rounding');

// Scenario 33: daily candidate kinds include unannounced late
$k33 = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
    'personel_id' => 1,
    'tarih' => '2026-08-01',
    'gec_kalma_dakika' => 20,
    'durumu_bildirdi_mi' => 0,
]);
adrAssert(in_array(AttendanceDisciplineCatalog::CANDIDATE_GEC_KALMA, $k33, true), 'S33 unannounced late candidate');

// Scenario 34: unannounced early does NOT create candidate
$k34 = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
    'personel_id' => 1,
    'tarih' => '2026-08-02',
    'erken_cikis_dakika' => 25,
    'durumu_bildirdi_mi' => 0,
]);
adrAssert($k34 === [], 'S34 unannounced early no candidate');

// Scenario 35: monthly counter 3x60 yes, 59x3 no
$rows60 = [
    ['gec_kalma_dakika' => 60],
    ['gec_kalma_dakika' => 60],
    ['gec_kalma_dakika' => 60],
];
adrAssert(DisiplinAdayProjectionService::countMonthlyLateEvents($rows60) === 3, 'S35 count 3x60');
adrAssert(DisiplinAdayProjectionService::shouldCreateMonthlyCandidate(3) === true, 'S35 shouldCreate 3 events');

$rows59 = [
    ['gec_kalma_dakika' => 59],
    ['gec_kalma_dakika' => 59],
    ['gec_kalma_dakika' => 59],
];
adrAssert(DisiplinAdayProjectionService::countMonthlyLateEvents($rows59) === 0, 'S35 count 59x3 is zero');
adrAssert(DisiplinAdayProjectionService::shouldCreateMonthlyCandidate(2) === false, 'S35 shouldCreate false for 2');

// applyToPuantajRow preserves raw fields
$applied = AttendancePayrollEffectResolver::applyToPuantajRow(
    ['gec_kalma_dakika' => 20, 'erken_cikis_dakika' => 0],
    ['karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA, 'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA]
);
adrAssert((int) $applied['gec_kalma_effective_dakika'] === 0, 'applyToPuantajRow TOLERANS effective');
adrAssert((int) $applied['attendance_late_raw_dakika'] === 20, 'applyToPuantajRow raw preserved');

echo 'verify-attendance-discipline-resolver: OK' . PHP_EOL;
