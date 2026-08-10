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

// Scenario 7: OFFICIAL_PROCESS_REQUIRED without evidence → BLOCK
$r7 = adrLate([
    'gec_kalma_dakika' => 45,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_OFFICIAL_PROCESS_REQUIRED,
]);
adrAssert($r7['block'] === true, 'S7 OFFICIAL without evidence blocks');
adrAssert($r7['reason'] === 'OFFICIAL_PROCESS_PENDING', 'S7 OFFICIAL pending reason');
adrAssert($r7['effective'] === 0, 'S7 OFFICIAL pending effective 0 interim');

// Scenario 7b: OFFICIAL with canonical approved evidence → no block, zero deduction
$r7b = adrLate([
    'gec_kalma_dakika' => 45,
    'dayanak' => 'Yillik_Izin',
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_OFFICIAL_PROCESS_REQUIRED,
]);
adrAssert($r7b['block'] === false, 'S7b OFFICIAL with evidence not blocked');
adrAssert($r7b['effective'] === 0, 'S7b approved official no duplicate deduction');

$r7c = adrEarly([
    'erken_cikis_dakika' => 30,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_OFFICIAL_PROCESS_REQUIRED,
]);
adrAssert($r7c['block'] === true, 'S7c early OFFICIAL without evidence blocks');

$r7d = adrEarly([
    'erken_cikis_dakika' => 30,
    'dayanak' => 'Ucretli_Izinli',
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_OFFICIAL_PROCESS_REQUIRED,
]);
adrAssert($r7d['block'] === false, 'S7d early OFFICIAL with evidence not blocked');
adrAssert($r7d['effective'] === 0, 'S7d early approved official no duplicate deduction');

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

// Scenario 17: early TOLERANS is forbidden at resolver — actual minutes kept
$r17 = adrEarly([
    'erken_cikis_dakika' => 25,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
]);
adrAssert($r17['effective'] === 25, 'S17 early TOLERANS ignored -> actual 25');

// Scenario 17b: late TOLERANS only <=35
$r17b = adrLate([
    'gec_kalma_dakika' => 35,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
]);
adrAssert($r17b['effective'] === 0, 'S17b late 35 TOLERANS effective 0');
$r17c = adrLate([
    'gec_kalma_dakika' => 36,
    'puantaj_olay_karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
]);
adrAssert($r17c['effective'] === 36, 'S17c late 36 TOLERANS rejected -> actual 36');

// Scenario 18: early 40/90 no rounding
$r18a = adrEarly(['erken_cikis_dakika' => 40]);
adrAssert($r18a['effective'] === 40, 'S18 early 40 no rounding');
$r18b = adrEarly(['erken_cikis_dakika' => 90]);
adrAssert($r18b['effective'] === 90, 'S18 early 90 no rounding');

// Authorized absence must not become full-day candidate
$kAuth = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
    'personel_id' => 1,
    'tarih' => '2026-08-03',
    'hareket_durumu' => 'Gelmedi',
    'dayanak' => 'Yillik_Izin',
    'gec_kalma_dakika' => 0,
    'erken_cikis_dakika' => 0,
    'net_calisma_suresi_dakika' => 0,
    'durumu_bildirdi_mi' => 0,
]);
adrAssert($kAuth === [], 'authorized Yillik_Izin no full-day candidate');

$kUnauthorized = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
    'personel_id' => 1,
    'tarih' => '2026-08-04',
    'hareket_durumu' => 'Gelmedi',
    'dayanak' => 'Yok_Izinsiz',
    'gec_kalma_dakika' => 0,
    'erken_cikis_dakika' => 0,
    'net_calisma_suresi_dakika' => 0,
    'durumu_bildirdi_mi' => 0,
]);
adrAssert(
    in_array(AttendanceDisciplineCatalog::CANDIDATE_TAM_GUN_DEVAMSIZLIK, $kUnauthorized, true),
    'Yok_Izinsiz unannounced full-day candidate'
);

$kEmptyDayanak = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
    'personel_id' => 1,
    'tarih' => '2026-08-04',
    'hareket_durumu' => 'Gelmedi',
    'dayanak' => '',
    'gec_kalma_dakika' => 0,
    'erken_cikis_dakika' => 0,
    'net_calisma_suresi_dakika' => 0,
    'durumu_bildirdi_mi' => 0,
]);
adrAssert($kEmptyDayanak === [], 'empty dayanak no full-day candidate');

$kNullNoticeFull = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
    'personel_id' => 1,
    'tarih' => '2026-08-04',
    'hareket_durumu' => 'Gelmedi',
    'dayanak' => 'Yok_Izinsiz',
    'gec_kalma_dakika' => 0,
    'erken_cikis_dakika' => 0,
    'net_calisma_suresi_dakika' => 0,
    'durumu_bildirdi_mi' => null,
]);
adrAssert($kNullNoticeFull === [], 'Yok_Izinsiz + notice NULL no candidate');

$kNullNoticeLate = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
    'personel_id' => 1,
    'tarih' => '2026-08-01',
    'gec_kalma_dakika' => 20,
    'durumu_bildirdi_mi' => null,
]);
adrAssert($kNullNoticeLate === [], 'NULL notice late no habersiz candidate');

$kAnnouncedLate = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
    'personel_id' => 1,
    'tarih' => '2026-08-01',
    'gec_kalma_dakika' => 20,
    'durumu_bildirdi_mi' => 1,
]);
adrAssert($kAnnouncedLate === [], 'announced late no habersiz candidate');

foreach (['Ucretli_Izinli', 'Raporlu_Hastalik', 'Raporlu_Is_Kazasi', 'Gorevde_Calisma'] as $authorizedDayanak) {
    $k = DisiplinAdayProjectionService::evaluateDailyCandidateKinds([
        'personel_id' => 1,
        'tarih' => '2026-08-05',
        'gun_tipi' => 'TAM_GUN_DEVAMSIZLIK',
        'dayanak' => $authorizedDayanak,
        'durumu_bildirdi_mi' => 0,
    ]);
    adrAssert($k === [], 'authorized ' . $authorizedDayanak . ' no candidate');
}

adrAssert(AttendanceDisciplineCatalog::olayKararDecideRoles() === ['BOLUM_YONETICISI'], 'decide owner BOLUM only');
adrAssert(AttendanceDisciplineCatalog::finalDecisionRoles() === ['BOLUM_YONETICISI'], 'final owner BOLUM only');
adrAssert(AttendanceDisciplineCatalog::LATE_TOLERANCE_MAX_MINUTE === 35, 'late tolerance max 35');

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

// Sealed snapshot karar must dominate live index after decision change simulation
$sealedRow = [
    'personel_id' => 7,
    'tarih' => '2026-08-20',
    'gec_kalma_dakika' => 20,
    'erken_cikis_dakika' => 0,
    'olay_kararlari' => [
        AttendanceDisciplineCatalog::OLAY_GEC_KALMA => [
            'id' => 99,
            'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
            'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
            'raw_dakika' => 20,
        ],
    ],
];
$liveIndex = [
    AttendancePayrollEffectResolver::kararKey(7, '2026-08-20', AttendanceDisciplineCatalog::OLAY_GEC_KALMA) => [
        'id' => 99,
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'karar' => AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA,
        'raw_dakika' => 20,
    ],
];
$sealedAnnotated = AttendancePayrollEffectResolver::annotatePuantajlar([$sealedRow], $liveIndex);
adrAssert((int) $sealedAnnotated[0]['gec_kalma_effective_dakika'] === 20, 'sealed KESINTI survives live TOLERANS change');

$reverseSealed = $sealedRow;
$reverseSealed['olay_kararlari'][AttendanceDisciplineCatalog::OLAY_GEC_KALMA]['karar'] =
    AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA;
$liveKesinti = [
    AttendancePayrollEffectResolver::kararKey(7, '2026-08-20', AttendanceDisciplineCatalog::OLAY_GEC_KALMA) => [
        'id' => 99,
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
        'raw_dakika' => 20,
    ],
];
$reverseAnnotated = AttendancePayrollEffectResolver::annotatePuantajlar([$reverseSealed], $liveKesinti);
adrAssert((int) $reverseAnnotated[0]['gec_kalma_effective_dakika'] === 0, 'sealed TOLERANS survives live KESINTI change');

// sealed notice must not be overwritten by decision metadata
$noticeSealed = AttendancePayrollEffectResolver::applyToPuantajRow(
    ['gec_kalma_dakika' => 20, 'erken_cikis_dakika' => 0, 'durumu_bildirdi_mi' => 1],
    [
        'karar' => AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA,
        'olay_turu' => AttendanceDisciplineCatalog::OLAY_GEC_KALMA,
        'durumu_bildirdi_mi' => 0,
    ]
);
adrAssert((int) $noticeSealed['durumu_bildirdi_mi'] === 1, 'sealed notice not overwritten by decision');
adrAssert((int) $noticeSealed['gec_kalma_effective_dakika'] === 20, 'kesinti still applies with sealed notice');

echo 'verify-attendance-discipline-resolver: OK' . PHP_EOL;
