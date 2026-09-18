<?php

declare(strict_types=1);

/**
 * Personnel-linked self-service permission matrix (no DB).
 * Exit 0 = PASS; non-zero = FAIL.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Auth\RolePermissions;

function plFail($msg)
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function plOk($msg)
{
    echo "OK: {$msg}\n";
}

function plUser($rol, $personelId = null, $collarAd = null)
{
    $u = ['id' => 1, 'rol' => $rol];
    if ($personelId !== null) {
        $u['personel_id'] = $personelId;
    } else {
        $u['personel_id'] = null;
    }
    // Canonical collar read model carried by AuthMiddleware/LoginController.
    $u['personel_tipi_id'] = $collarAd === null ? null : 1;
    $u['personel_tipi_ad'] = $collarAd;

    return $u;
}

function plHas(array $user, $permission)
{
    return RolePermissions::has($user, $permission);
}

$baseline = RolePermissions::selfServiceBaselinePermissions();
$expectedBaseline = [
    'self_service.view',
    'self_service.puantaj.view',
    'self_service.yillik_izin.view',
    'self_service.fazla_calisma.view',
    'self_service.qr.scan',
    'self_service.qr.events.view',
    'self_service.attendance.correct',
];
if ($baseline !== $expectedBaseline) {
    plFail('SELF_SERVICE_BASELINE mismatch: ' . json_encode($baseline));
}
plOk('SELF_SERVICE_BASELINE=PERSONEL_MATRIX');

// PERSONEL + Mavi Yaka + personel_id → full self baseline, management NO
$u = plUser('PERSONEL', 158, RolePermissions::QR_SELF_SERVICE_COLLAR);
foreach ($expectedBaseline as $p) {
    if (!plHas($u, $p)) {
        plFail("PERSONEL+Mavi Yaka missing {$p}");
    }
}
if (plHas($u, 'personeller.view') || plHas($u, 'puantaj.update')) {
    plFail('PERSONEL must not gain management');
}
plOk('PERSONEL+personel_id self=YES management=NO');

// A) PERSONEL + Mavi Yaka → QR allowed
if (!plHas($u, 'self_service.qr.scan') || !plHas($u, 'self_service.qr.events.view')) {
    plFail('A: PERSONEL + Mavi Yaka QR must be allowed');
}
plOk('A: PERSONEL+MAVI_YAKA qr=ALLOWED');

// B) PERSONEL + Beyaz Yaka → QR denied, non-QR self-service preserved
$u = plUser('PERSONEL', 158, 'Beyaz Yaka');
if (plHas($u, 'self_service.qr.scan') || plHas($u, 'self_service.qr.events.view')) {
    plFail('B: PERSONEL + Beyaz Yaka QR must be denied');
}
foreach (['self_service.view', 'self_service.puantaj.view', 'self_service.yillik_izin.view', 'self_service.fazla_calisma.view', 'self_service.attendance.correct'] as $p) {
    if (!plHas($u, $p)) {
        plFail("B: PERSONEL + Beyaz Yaka missing non-QR self {$p}");
    }
}
plOk('B: PERSONEL+BEYAZ_YAKA qr=DENIED non_qr_self=PRESERVED');

// C) PERSONEL + Diğer → QR denied, non-QR self-service preserved
$u = plUser('PERSONEL', 158, 'Diğer');
if (plHas($u, 'self_service.qr.scan') || plHas($u, 'self_service.qr.events.view')) {
    plFail('C: PERSONEL + Diğer QR must be denied');
}
foreach (['self_service.view', 'self_service.puantaj.view', 'self_service.attendance.correct'] as $p) {
    if (!plHas($u, $p)) {
        plFail("C: PERSONEL + Diğer missing non-QR self {$p}");
    }
}
plOk('C: PERSONEL+DIGER qr=DENIED');

// D) PERSONEL + null / unknown collar → QR denied (fail-closed).
// 'MAVİ YAKA' is included on purpose: canonical values come from the DB and the
// comparison is invariant-case on both sides (PHP mb_strtolower / JS toLowerCase),
// so a Turkish-dotted uppercase variant is NOT "Mavi Yaka".
foreach ([null, '', 'Bilinmeyen Statu', 'MAVİ YAKA'] as $badCollar) {
    $u = plUser('PERSONEL', 158, $badCollar);
    if (plHas($u, 'self_service.qr.scan') || plHas($u, 'self_service.qr.events.view')) {
        plFail('D: PERSONEL + unknown collar QR must be denied: ' . json_encode($badCollar));
    }
}
$legacyShape = ['id' => 1, 'rol' => 'PERSONEL', 'personel_id' => 158];
if (plHas($legacyShape, 'self_service.qr.scan')) {
    plFail('D: PERSONEL without collar field must be denied');
}
plOk('D: PERSONEL+NULL/UNKNOWN_COLLAR qr=DENIED');

// D-parity) invariant-case comparison: ASCII "MAVI YAKA" collapses to the
// canonical value on both sides (PHP mb_strtolower == JS toLowerCase).
$u = plUser('PERSONEL', 158, 'MAVI YAKA');
if (!plHas($u, 'self_service.qr.scan')) {
    plFail('D-parity: PERSONEL + MAVI YAKA must resolve to Mavi Yaka');
}
plOk('D-parity: ASCII_CASE_FOLD matches canonical collar');

// H) Management role + personel_id keeps the existing contract, collar-independent
foreach (['GENEL_YONETICI', 'IK_SORUMLUSU', 'SUBE_YONETICISI', 'BOLUM_YONETICISI', 'BIRIM_AMIRI'] as $rol) {
    $u = ['id' => 1, 'rol' => $rol, 'personel_id' => 173, 'personel_tipi_ad' => 'Beyaz Yaka'];
    if (!plHas($u, 'self_service.qr.scan') || !plHas($u, 'self_service.qr.events.view')) {
        plFail("H: {$rol} + personel_id QR must stay allowed (collar-independent)");
    }
    if (!plHas($u, 'self_service.view')) {
        plFail("H: {$rol} + personel_id must keep self service");
    }
}
plOk('H: MANAGEMENT_SELF_CONTRACT collar_independent=YES');

// PERSONEL without binding → role matrix still grants self (existing behavior)
$u = plUser('PERSONEL', null);
if (!plHas($u, 'self_service.view')) {
    plFail('PERSONEL without binding should still have role self_service');
}
plOk('PERSONEL_NO_BINDING role_self_preserved');

// BOLUM_YONETICISI + personel_id → management + self
$u = plUser('BOLUM_YONETICISI', 173); // Sinem-shape
if (!plHas($u, 'personeller.view') || !plHas($u, 'aylik_bolum_onayi.approve')) {
    plFail('BOLUM+binding management missing');
}
foreach ($expectedBaseline as $p) {
    if (!plHas($u, $p)) {
        plFail("BOLUM+binding missing self {$p}");
    }
}
if (!plHas($u, 'self_service.qr.scan') || !plHas($u, 'self_service.qr.events.view')) {
    plFail('BOLUM+binding QR self missing');
}
plOk('BOLUM_YONETICISI+personel_id management+self=YES (Sinem-shape)');

$u = plUser('BOLUM_YONETICISI', 120); // İsmail-shape
if (!plHas($u, 'personeller.view') || !plHas($u, 'self_service.qr.scan')) {
    plFail('Ismail-shape management+self failed');
}
plOk('BOLUM_YONETICISI+personel_id Ismail-shape=PASS');

// BOLUM_YONETICISI + null → management YES, self NO
$u = plUser('BOLUM_YONETICISI', null);
if (!plHas($u, 'personeller.view')) {
    plFail('BOLUM unbound management missing');
}
if (plHas($u, 'self_service.view') || plHas($u, 'self_service.qr.scan')) {
    plFail('BOLUM unbound must not get self_service');
}
plOk('BOLUM_YONETICISI+null self=NO management=YES');

// GENEL_YONETICI + personel_id
$u = plUser('GENEL_YONETICI', 99);
if (!plHas($u, 'yonetim-paneli.manage') || !plHas($u, 'self_service.view')) {
    plFail('GENEL+binding management+self failed');
}
plOk('GENEL_YONETICI+personel_id management+self=YES');

// GENEL_YONETICI + null (ilkerA-shape)
$u = plUser('GENEL_YONETICI', null);
if (!plHas($u, 'yonetim-paneli.manage')) {
    plFail('GENEL unbound management missing');
}
if (plHas($u, 'self_service.view')) {
    plFail('GENEL unbound must not get self_service');
}
plOk('GENEL_YONETICI+null self=NO');

// Salih post-fix simulation: PERSONEL today; if promoted BOLUM + bolum4 binding preserved via personel_id
$salihAsManager = plUser('BOLUM_YONETICISI', 158);
if (!plHas($salihAsManager, 'aylik_bolum_onayi.approve')) {
    plFail('Salih simulation management missing');
}
if (!plHas($salihAsManager, 'self_service.qr.scan')) {
    plFail('Salih simulation QR self missing');
}
plOk('SALIH_POST_FIX_SIMULATION management+self=YES');

// invalid / zero binding
foreach ([0, -1, '', false] as $bad) {
    $u = ['rol' => 'BOLUM_YONETICISI', 'personel_id' => $bad];
    if (RolePermissions::hasPersonnelLinkedSelfServiceEligibility($u)) {
        plFail('bad binding should be ineligible: ' . json_encode($bad));
    }
    if (plHas($u, 'self_service.view')) {
        plFail('bad binding must not grant self: ' . json_encode($bad));
    }
}
plOk('INVALID_BINDING self=NO');

// Missing personel_id key
$u = ['rol' => 'BOLUM_YONETICISI'];
if (plHas($u, 'self_service.view')) {
    plFail('missing personel_id key must not grant self');
}
plOk('MISSING_BINDING_KEY self=NO');

// Legacy unknown role: self via binding only; no management fail-open
$u = plUser('SGK_KARAR_ONAY_YETKILISI', 50);
if (plHas($u, 'personeller.view') || plHas($u, 'sgk_karar_paketi.approve')) {
    plFail('legacy role must not gain management via binding');
}
if (!plHas($u, 'self_service.view') || !plHas($u, 'self_service.qr.scan')) {
    plFail('legacy role + binding should get self baseline only');
}
$u = plUser('SGK_KARAR_ONAY_YETKILISI', null);
if (plHas($u, 'self_service.view')) {
    plFail('legacy unbound must get nothing');
}
plOk('LEGACY_ROLE binding=self_only unbound=none');

// Other management roles + binding
foreach (['SUBE_YONETICISI', 'BIRIM_AMIRI', 'IK_SORUMLUSU', 'MUHASEBE', 'SISTEM_YONETICISI'] as $rol) {
    $u = plUser($rol, 42);
    if (!plHas($u, 'self_service.view')) {
        plFail("{$rol}+binding missing self");
    }
    $u = plUser($rol, null);
    if (plHas($u, 'self_service.view')) {
        plFail("{$rol}+null must not get self");
    }
}
plOk('ALL_CANONICAL_ROLES binding_gate=PASS');

echo "ALL_PASS personnel-linked-self-service\n";
exit(0);
