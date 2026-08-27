<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Attendance\AttendanceCorrectionApproverResolver;

function acaAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] $name\n");
        exit(1);
    }
    echo "[PASS] $name\n";
}

$personelChain = AttendanceCorrectionApproverResolver::chainForRequesterRole('PERSONEL');
acaAssert($personelChain === ['BIRIM_AMIRI', 'BOLUM_YONETICISI', 'GENEL_YONETICI'], 'personel chain');

$birimChain = AttendanceCorrectionApproverResolver::chainForRequesterRole('BIRIM_AMIRI');
acaAssert($birimChain === ['BOLUM_YONETICISI', 'GENEL_YONETICI'], 'birim yoneticisi chain');

$bolumChain = AttendanceCorrectionApproverResolver::chainForRequesterRole('BOLUM_YONETICISI');
acaAssert($bolumChain === ['GENEL_YONETICI'], 'bolum yoneticisi chain');

$gyChain = AttendanceCorrectionApproverResolver::chainForRequesterRole('GENEL_YONETICI');
acaAssert($gyChain === [], 'gy self has no higher approver');

acaAssert(!in_array('SUBE_YONETICISI', $personelChain, true), 'sube not in personel chain');
acaAssert(!in_array('SUBE_YONETICISI', $birimChain, true), 'sube not in birim chain');

$late = \Medisa\Api\Services\Attendance\LateEarlyInfoService::evaluateAfterScan(
    'GIRIS',
    '2026-08-25 05:42:00.000000', // 08:42 Istanbul (+03)
    ['beklenen_giris_saati' => '08:30'],
    0,
    0
);
acaAssert(is_array($late) && (int) $late['delta_dakika'] === 12, 'late entry delta 12');
acaAssert(strpos((string) $late['message'], '12') !== false, 'late message includes minutes');

$early = \Medisa\Api\Services\Attendance\LateEarlyInfoService::evaluateAfterScan(
    'CIKIS',
    '2026-08-25 14:05:00.000000', // 17:05 Istanbul
    ['beklenen_cikis_saati' => '17:30'],
    0,
    0
);
acaAssert(is_array($early) && (int) $early['delta_dakika'] === 25, 'early exit delta 25');

$capsDis = \Medisa\Api\Services\SelfService\PersonelMobileCapabilityService::resolve(
    null,
    1,
    ['calisan_kapsami' => 'DIS_KAYNAK']
);
acaAssert($capsDis['shell'] === true, 'dis shell');
acaAssert($capsDis['qr_scan'] === true, 'dis qr allowed');
acaAssert($capsDis['attendance_correct'] === true, 'dis attendance correction allowed');
acaAssert($capsDis['izin_write'] === false, 'dis izin fail-closed');
acaAssert($capsDis['coming_soon_message'] === null, 'dis no coming-soon blanket gate');
acaAssert(
    is_string($capsDis['info_only_notice'] ?? null) && strpos((string) $capsDis['info_only_notice'], 'BİLGİ AMAÇLIDIR') !== false,
    'dis info-only notice'
);

echo "[OK] AttendanceCorrectionApproverPureTestRunner\n";
