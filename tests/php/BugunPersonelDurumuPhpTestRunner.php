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

$justLate = BugunPersonelDurumuService::classifyCompletionStatus(
    '2026-09-04 09:30:01',
    $tarih,
    new \DateTimeImmutable('2026-09-04 10:00:00', $tz)
);
bpdAssert($justLate === BugunPersonelDurumuService::COMPLETION_GEC_BILDIRILDI, '09:30:01 late');

// Missing entry ≠ GELMEDI; completion does not clear live missing-entry evidence
bpdAssert(
    BugunPersonelDurumuService::isMissingEntryEvidence(null, null) === true,
    'no exception + no attendance → missing entry'
);
bpdAssert(
    BugunPersonelDurumuService::isMissingEntryEvidence(null, '08:45') === false,
    'attendance clears missing entry'
);
bpdAssert(
    BugunPersonelDurumuService::isMissingEntryEvidence('IZINLI', null) === false,
    'exception clears missing entry'
);
bpdAssert(
    BugunPersonelDurumuService::resolvePersonDurum(null, null, false) !== 'GELMEDI',
    'missing entry person !== GELMEDI'
);

// Sunday: normal 09:30 overdue does not apply; Monday 12:00 review deadline
$sunday = '2026-09-06';
bpdAssert(BugunPersonelDurumuService::isSundayDate($sunday), '2026-09-06 is Sunday');
$sundayDeadline = BugunPersonelDurumuService::deadlineDateTime($sunday);
bpdAssert(
    $sundayDeadline->format('Y-m-d H:i:s') === '2026-09-07 12:00:00',
    'Sunday review deadline = Monday 12:00'
);
$sundayMorning = BugunPersonelDurumuService::classifyCompletionStatus(
    null,
    $sunday,
    new \DateTimeImmutable('2026-09-06 09:31:00', $tz)
);
bpdAssert(
    $sundayMorning === BugunPersonelDurumuService::COMPLETION_BEKLENIYOR,
    'Sunday 09:31 no normal overdue'
);
$monday1159 = BugunPersonelDurumuService::classifyCompletionStatus(
    null,
    $sunday,
    new \DateTimeImmutable('2026-09-07 11:59:00', $tz)
);
bpdAssert(
    $monday1159 === BugunPersonelDurumuService::COMPLETION_BEKLENIYOR,
    'Monday 11:59 Sunday review still waiting'
);
$monday1200Complete = BugunPersonelDurumuService::classifyCompletionStatus(
    '2026-09-07 12:00:00',
    $sunday,
    new \DateTimeImmutable('2026-09-07 13:00:00', $tz)
);
bpdAssert(
    $monday1200Complete === BugunPersonelDurumuService::COMPLETION_TAMAMLANDI,
    'Monday 12:00:00 Sunday completion on time'
);
$monday120001 = BugunPersonelDurumuService::classifyCompletionStatus(
    null,
    $sunday,
    new \DateTimeImmutable('2026-09-07 12:00:01', $tz)
);
bpdAssert(
    $monday120001 === BugunPersonelDurumuService::COMPLETION_SURESI_GECTI,
    'Monday 12:00:01 Sunday review overdue'
);
bpdAssert(
    BugunPersonelDurumuService::completionStatusLabel(
        BugunPersonelDurumuService::COMPLETION_SURESI_GECTI,
        null,
        $sunday
    ) === 'Pazar Mesaisi Bildirimi Süresi Geçti',
    'Sunday overdue label exact'
);
$sundayLateComplete = BugunPersonelDurumuService::classifyCompletionStatus(
    '2026-09-07 12:30:00',
    $sunday,
    new \DateTimeImmutable('2026-09-07 13:00:00', $tz)
);
bpdAssert(
    $sundayLateComplete === BugunPersonelDurumuService::COMPLETION_GEC_BILDIRILDI,
    'Monday late Sunday completion still allowed as GEC_BILDIRILDI'
);

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

// 7) correction old→new history lives on append-only audit owner (migration 085)
$controller = file_get_contents($root . '/api/src/Controllers/BildirimlerController.php');
$migration005 = file_get_contents($root . '/api/migrations/005_gunluk_bildirimler.sql');
$migration085 = file_get_contents($root . '/api/migrations/085_gunluk_bildirim_duzeltme_auditleri.sql');
bpdAssert(
    is_string($controller) && strpos($controller, 'GunlukBildirimDuzeltmeAuditService') !== false,
    'update wires audit service'
);
bpdAssert(
    is_string($controller) && strpos($controller, 'gunluk_bildirim.correct_scoped') !== false,
    'scoped correction permission gated on update/create'
);
bpdAssert(
    is_string($migration005)
    && strpos($migration005, 'correction_reason') !== false
    && strpos($migration005, 'onceki_durum') === false,
    'row table still overwrites tur; history not on gunluk_bildirimler'
);
bpdAssert(
    is_string($migration085) && strpos($migration085, 'eski_bildirim_turu') !== false,
    '085 audit table carries old→new'
);

// 8) resmi surec → Bugün exception overlay (read-only; no parallel gunluk write)
bpdAssert(
    BugunPersonelDurumuService::mapSurecToBugunExceptionTur('IZIN', 'YILLIK_IZIN') === 'IZINLI',
    'IZIN surec → IZINLI'
);
bpdAssert(
    BugunPersonelDurumuService::mapSurecToBugunExceptionTur('RAPOR', 'Raporlu_Hastalik') === 'RAPORLU',
    'RAPOR surec → RAPORLU'
);
bpdAssert(
    BugunPersonelDurumuService::mapSurecToBugunExceptionTur('IS_KAZASI', 'IS_KAZASI_BILDIRIMI') === 'RAPORLU',
    'IS_KAZASI surec → RAPORLU'
);
bpdAssert(
    BugunPersonelDurumuService::mapSurecToBugunExceptionTur('DEVAMSIZLIK', 'IZINSIZ_GELMEDI') === 'GELMEDI',
    'IZINSIZ DEVAMSIZLIK → GELMEDI'
);
bpdAssert(
    BugunPersonelDurumuService::mapSurecToBugunExceptionTur('DEVAMSIZLIK', 'MAZERETSIZ_GEC_GELDI') === null,
    'GEC surec does not invent Bugün saatli status'
);
bpdAssert(
    BugunPersonelDurumuService::effectiveExceptionTur(null, 'IZINLI') === 'IZINLI',
    'surec overlay fills missing bildirim'
);
bpdAssert(
    BugunPersonelDurumuService::effectiveExceptionTur('GEC_GELDI', 'IZINLI') === 'GEC_GELDI',
    'bildirim exception wins over surec overlay'
);

bpdOk('BugunPersonelDurumuService pure semantics');
echo "ALL_PASS bugun-personel-durumu\n";
