<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiProjectionService.php';

use Medisa\Api\Services\BildirimPuantajEtkiProjectionService as S;

function failScenario($number, $message)
{
    fwrite(STDERR, "SCENARIO:{$number}:FAIL:{$message}\n");
    exit(1);
}

function passScenario($number, $summary)
{
    fwrite(STDOUT, "SCENARIO:{$number}:PASS:{$summary}\n");
}

function baseBildirim($tur, $overrides = [])
{
    return array_merge([
        'id' => 1,
        'personel_id' => 10,
        'tarih' => '2026-06-15',
        'sube_id' => 1,
        'bildirim_turu' => $tur,
        'alt_tur' => null,
        'dakika' => null,
        'aciklama' => null,
        'state' => 'HAFTALIK_MUTABAKATA_ALINDI',
        'haftalik_mutabakat_id' => 2,
        'created_at' => '2026-06-20 10:00:00',
        'updated_at' => '2026-06-20 10:00:00',
    ], $overrides);
}

function baseContext($overrides = [])
{
    return array_merge([
        'has_puantaj_row' => false,
        'multi_conflict_code' => '',
        'day_bildirim_turleri' => [],
        'resmi_surecler' => [],
    ], $overrides);
}

function izinSurec($id = 5)
{
    return [
        'id' => $id,
        'surec_turu' => 'IZIN',
        'alt_tur' => null,
        'baslangic_tarihi' => '2026-06-15',
        'bitis_tarihi' => '2026-06-15',
        'ucretli_mi' => 1,
        'state' => 'AKTIF',
    ];
}

function raporSurec($id = 6)
{
    return [
        'id' => $id,
        'surec_turu' => 'RAPOR',
        'alt_tur' => null,
        'baslangic_tarihi' => '2026-06-15',
        'bitis_tarihi' => '2026-06-15',
        'ucretli_mi' => 0,
        'state' => 'AKTIF',
    ];
}

// 1. GELMEDI → HAZIR / DEVAMSIZLIK_GUN / 1 GUN
$result = S::projectCandidate(baseBildirim('GELMEDI'), baseContext());
if ($result['state'] !== 'HAZIR' || $result['etki_turu'] !== 'DEVAMSIZLIK_GUN' || $result['etki_miktari'] !== 1 || $result['etki_birimi'] !== 'GUN') {
    failScenario(1, 'GELMEDI HAZIR DEVAMSIZLIK_GUN 1 GUN bekleniyordu');
}
passScenario(1, 'GELMEDI → HAZIR / DEVAMSIZLIK_GUN / 1 GUN');

// 2. GEC_GELDI pozitif dakika
$result = S::projectCandidate(
    baseBildirim('GEC_GELDI', ['dakika' => 15]),
    baseContext(['day_bildirim_turleri' => ['GEC_GELDI']])
);
if ($result['state'] !== 'HAZIR' || $result['etki_miktari'] !== 15 || $result['etki_birimi'] !== 'DAKIKA') {
    failScenario(2, 'GEC_GELDI pozitif dakika HAZIR bekleniyordu');
}
passScenario(2, 'GEC_GELDI pozitif dakika → HAZIR / 15 DAKIKA');

// 3. GEC_GELDI dakika eksik
$result = S::projectCandidate(baseBildirim('GEC_GELDI'), baseContext());
if ($result['state'] !== 'INCELEME_GEREKLI' || $result['conflict_code'] !== 'DAKIKA_EKSIK') {
    failScenario(3, 'GEC_GELDI dakika eksik DAKIKA_EKSIK bekleniyordu');
}
passScenario(3, 'GEC_GELDI dakika eksik → INCELEME_GEREKLI / DAKIKA_EKSIK');

// 4. ERKEN_CIKTI pozitif dakika
$result = S::projectCandidate(
    baseBildirim('ERKEN_CIKTI', ['dakika' => 8, 'id' => 2]),
    baseContext(['day_bildirim_turleri' => ['ERKEN_CIKTI']])
);
if ($result['state'] !== 'HAZIR' || $result['etki_turu'] !== 'ERKEN_CIKIS_DAKIKA' || $result['etki_miktari'] !== 8) {
    failScenario(4, 'ERKEN_CIKTI pozitif dakika bekleniyordu');
}
passScenario(4, 'ERKEN_CIKTI pozitif dakika → HAZIR / 8 DAKIKA');

// 5. IZINLI + tek resmî izin süreci
$result = S::projectCandidate(baseBildirim('IZINLI'), baseContext(['resmi_surecler' => [izinSurec()]]));
if ($result['state'] !== 'HAZIR' || $result['etki_turu'] !== 'IZIN_GUNU' || !isset($result['matched_surec'])) {
    failScenario(5, 'IZINLI tek surec HAZIR bekleniyordu');
}
passScenario(5, 'IZINLI + tek resmi izin → HAZIR / IZIN_GUNU / 1 GUN');

// 6. IZINLI + süreç yok
$result = S::projectCandidate(baseBildirim('IZINLI'), baseContext());
if ($result['state'] !== 'INCELEME_GEREKLI' || $result['conflict_code'] !== 'IZIN_SURECI_YOK') {
    failScenario(6, 'IZINLI surec yok IZIN_SURECI_YOK bekleniyordu');
}
passScenario(6, 'IZINLI + surec yok → INCELEME_GEREKLI / IZIN_SURECI_YOK');

// 7. RAPORLU + tek resmî rapor süreci
$result = S::projectCandidate(baseBildirim('RAPORLU'), baseContext(['resmi_surecler' => [raporSurec()]]));
if ($result['state'] !== 'HAZIR' || $result['etki_turu'] !== 'RAPOR_GUNU') {
    failScenario(7, 'RAPORLU tek surec HAZIR bekleniyordu');
}
passScenario(7, 'RAPORLU + tek resmi rapor → HAZIR / RAPOR_GUNU / 1 GUN');

// 8. RAPORLU + süreç yok
$result = S::projectCandidate(baseBildirim('RAPORLU'), baseContext());
if ($result['state'] !== 'INCELEME_GEREKLI' || $result['conflict_code'] !== 'RAPOR_SURECI_YOK') {
    failScenario(8, 'RAPORLU surec yok RAPOR_SURECI_YOK bekleniyordu');
}
passScenario(8, 'RAPORLU + surec yok → INCELEME_GEREKLI / RAPOR_SURECI_YOK');

// 9. GOREVDE
$result = S::projectCandidate(
    baseBildirim('GOREVDE'),
    baseContext(['day_bildirim_turleri' => ['GOREVDE']])
);
if ($result['state'] !== 'HAZIR' || $result['etki_turu'] !== 'GOREVDE_CALISILMIS_GUN' || $result['etki_miktari'] !== 1) {
    failScenario(9, 'GOREVDE HAZIR bekleniyordu');
}
passScenario(9, 'GOREVDE → HAZIR / GOREVDE_CALISILMIS_GUN / 1 GUN');

// 10. DIGER
$result = S::projectCandidate(baseBildirim('DIGER'), baseContext());
if ($result['state'] !== 'INCELEME_GEREKLI' || $result['conflict_code'] !== 'DIGER_MANUEL_INCELEME') {
    failScenario(10, 'DIGER DIGER_MANUEL_INCELEME bekleniyordu');
}
passScenario(10, 'DIGER → INCELEME_GEREKLI / DIGER_MANUEL_INCELEME');

// 11. GEC_GELDI + ERKEN_CIKTI uyumlu
$dayRows = [
    baseBildirim('GEC_GELDI', ['id' => 1, 'dakika' => 10]),
    baseBildirim('ERKEN_CIKTI', ['id' => 2, 'dakika' => 5]),
];
$compat = S::evaluateDayCompatibility($dayRows);
$gec = S::projectCandidate($dayRows[0], baseContext([
    'multi_conflict_code' => $compat[1] ?? '',
    'day_bildirim_turleri' => ['GEC_GELDI', 'ERKEN_CIKTI'],
]));
$erken = S::projectCandidate($dayRows[1], baseContext([
    'multi_conflict_code' => $compat[2] ?? '',
    'day_bildirim_turleri' => ['GEC_GELDI', 'ERKEN_CIKTI'],
]));
if ($compat[1] !== null || $compat[2] !== null || $gec['state'] !== 'HAZIR' || $erken['state'] !== 'HAZIR') {
    failScenario(11, 'GEC+ERKEN uyumlu cift bekleniyordu');
}
passScenario(11, 'GEC_GELDI + ERKEN_CIKTI → her ikisi HAZIR');

// 12. GELMEDI + IZINLI çelişkili
$dayRows = [
    baseBildirim('GELMEDI', ['id' => 1]),
    baseBildirim('IZINLI', ['id' => 2]),
];
$compat = S::evaluateDayCompatibility($dayRows);
if ($compat[1] !== 'COKLU_BILDIRIM_CELISKISI' || $compat[2] !== 'COKLU_BILDIRIM_CELISKISI') {
    failScenario(12, 'GELMEDI+IZINLI COKLU_BILDIRIM_CELISKISI bekleniyordu');
}
passScenario(12, 'GELMEDI + IZINLI → COKLU_BILDIRIM_CELISKISI');

// 13. GEC_GELDI + DIGER (DIGER diger adayi bozmaz)
$dayRows = [
    baseBildirim('GEC_GELDI', ['id' => 1, 'dakika' => 12]),
    baseBildirim('DIGER', ['id' => 2]),
];
$compat = S::evaluateDayCompatibility($dayRows);
$gec = S::projectCandidate($dayRows[0], baseContext([
    'multi_conflict_code' => $compat[1] ?? '',
    'day_bildirim_turleri' => ['GEC_GELDI', 'DIGER'],
]));
$diger = S::projectCandidate($dayRows[1], baseContext([
    'multi_conflict_code' => $compat[2] ?? '',
    'day_bildirim_turleri' => ['GEC_GELDI', 'DIGER'],
]));
if ($gec['state'] !== 'HAZIR' || $diger['conflict_code'] !== 'DIGER_MANUEL_INCELEME') {
    failScenario(13, 'GEC+ DIGER: GEC HAZIR, DIGER inceleme bekleniyordu');
}
passScenario(13, 'GEC_GELDI + DIGER → GEC HAZIR, DIGER inceleme');

// 14. mevcut puantaj satırı
$result = S::projectCandidate(baseBildirim('GELMEDI'), baseContext(['has_puantaj_row' => true]));
if ($result['conflict_code'] !== 'MEVCUT_PUANTAJ_VAR') {
    failScenario(14, 'MEVCUT_PUANTAJ_VAR bekleniyordu');
}
passScenario(14, 'Mevcut puantaj → INCELEME_GEREKLI / MEVCUT_PUANTAJ_VAR');

// 15. birden fazla resmî süreç
$result = S::projectCandidate(baseBildirim('IZINLI'), baseContext([
    'resmi_surecler' => [izinSurec(5), izinSurec(6)],
]));
if ($result['conflict_code'] !== 'COKLU_RESMI_SUREC') {
    failScenario(15, 'COKLU_RESMI_SUREC bekleniyordu');
}
$raporMulti = S::projectCandidate(baseBildirim('RAPORLU'), baseContext([
    'resmi_surecler' => [raporSurec(7), raporSurec(8)],
]));
if ($raporMulti['conflict_code'] !== 'COKLU_RESMI_SUREC') {
    failScenario(15, 'RAPORLU COKLU_RESMI_SUREC bekleniyordu');
}
passScenario(15, 'Birden fazla resmi surec → COKLU_RESMI_SUREC');

// 16. conflict-code priority (MEVCUT_PUANTAJ_VAR > COKLU_BILDIRIM_CELISKISI)
$result = S::projectCandidate(baseBildirim('GELMEDI'), baseContext([
    'has_puantaj_row' => true,
    'multi_conflict_code' => 'COKLU_BILDIRIM_CELISKISI',
]));
if ($result['conflict_code'] !== 'MEVCUT_PUANTAJ_VAR') {
    failScenario(16, 'MEVCUT_PUANTAJ_VAR oncelikli bekleniyordu');
}
passScenario(16, 'Conflict priority → MEVCUT_PUANTAJ_VAR once gelir');

// Regression: bos multi_conflict_code false-positive degil
$regression = S::projectCandidate(baseBildirim('GELMEDI'), baseContext(['multi_conflict_code' => '']));
if ($regression['state'] !== 'HAZIR') {
    failScenario('R1', 'Bos multi_conflict_code regression HAZIR bekleniyordu');
}
passScenario('R1', 'Regression: bos multi_conflict_code → HAZIR');

// 17. IZINLI + ucretsiz resmi izin sureci
function ucretsizIzinSurec($id = 9)
{
    return [
        'id' => $id,
        'surec_turu' => 'IZIN',
        'alt_tur' => null,
        'baslangic_tarihi' => '2026-06-15',
        'bitis_tarihi' => '2026-06-15',
        'ucretli_mi' => 0,
        'state' => 'AKTIF',
    ];
}

$result = S::projectCandidate(baseBildirim('IZINLI'), baseContext(['resmi_surecler' => [ucretsizIzinSurec()]]));
if ($result['state'] !== 'INCELEME_GEREKLI' || $result['etki_turu'] !== 'IZIN_GUNU' || $result['conflict_code'] !== 'UCRETSIZ_IZIN_MANUEL_INCELEME') {
    failScenario(17, 'Ucretsiz izin UCRETSIZ_IZIN_MANUEL_INCELEME bekleniyordu');
}
passScenario(17, 'IZINLI + ucretsiz izin → INCELEME_GEREKLI / IZIN_GUNU / UCRETSIZ_IZIN_MANUEL_INCELEME');

echo "OK\n";
