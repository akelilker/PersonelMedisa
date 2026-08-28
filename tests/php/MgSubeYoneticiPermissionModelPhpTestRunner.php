<?php

declare(strict_types=1);

/**
 * MG-SUBE-YONETICI-001: branch-manager capability model + approval separation of duties.
 * In-process, no MariaDB. Exit 0 = PASS.
 *
 * Branch scope enforcement itself (empty user_subeler = deny, cross-branch deny) is owned by
 * OrgHierarchyAuthMatrixPhpTestRunner; this runner covers the capability matrix and DualControl.
 */

$root = dirname(__DIR__, 2);
require $root . '/api/src/bootstrap.php';

use Medisa\Api\Auth\DualControl;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Scope\OrgScope;

function mgFail($msg)
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function mgOk($msg)
{
    echo "OK: {$msg}\n";
}

function mgUser($rol, array $sube = [], $id = 10)
{
    return ['id' => $id, 'rol' => $rol, 'sube_ids' => $sube, 'bolum_ids' => [], 'birim_ids' => []];
}

function mgAssertHas($rol, array $permissions, $expected, $label)
{
    $user = mgUser($rol);
    foreach ($permissions as $permission) {
        if (RolePermissions::has($user, $permission) !== $expected) {
            mgFail("{$label}: {$rol} / {$permission} expected " . ($expected ? 'GRANT' : 'DENY'));
        }
    }
    mgOk($label);
}

// --- SUBE_YONETICISI capability model -------------------------------------------------

mgAssertHas('SUBE_YONETICISI', [
    'personeller.view.sube',
    'personeller.detail.view',
    'personeller.create',
    'personeller.update',
    'puantaj.view',
    'puantaj.update',
    'puantaj.muhurle',
    'puantaj.donem_reopen.request',
    'bildirimler.create',
    'bildirimler.update',
    'revizyon.create',
    'revizyon.submit',
    'haftalik_mutabakat.view',
    'aylik_bildirim_onayi.view',
], true, 'SUBE_BRANCH_OPERATIONAL_DATA_ENTRY_AND_SUBMIT');

mgAssertHas('SUBE_YONETICISI', [
    // Central payroll finalization / final management approval.
    'bordro_kesinlestirme.approve',
    'bordro_on_izleme.view',
    'maas_hesaplama.manage',
    'maas_hesaplama_adaylari.manage',
    'personel_bordro_kapsam.manage',
    'personel_bordro_kapsam.approve',
    'genel_yonetici_onayi.approve',
    'genel_yonetici_bildirim_onayi.approve',
    'aylik-ozet.executive_ack',
    'puantaj.donem_reopen.approve',
    'revizyon.approve',
    'revizyon.reject',
    // Company-wide SGK / official decisions.
    'sgk_karar_paketi.prepare',
    'sgk_karar_paketi.approve',
    'sgk.manuel_kod_override',
    // Company-wide finance writes / payment.
    'finans.create',
    'finans.update',
    'finans.cancel',
    // User and critical system administration.
    'yonetim-paneli.view',
    'yonetim-paneli.manage',
    'sirket_parametreleri.manage',
    'resmi_tatil_takvimi.manage',
    'mevzuat_parametreleri.manage',
    'personeller.ucret.manage',
    // Retention / legal hold.
    'legal_hold.manage',
    'retention.destruction.approve',
    'retention.destruction.execute',
], false, 'SUBE_NO_FINALIZATION_SGK_FINANCE_ADMIN_RETENTION');

// Not a BIRIM_AMIRI + BOLUM_YONETICISI union: the BA daily-report chain stays with BIRIM_AMIRI.
mgAssertHas('SUBE_YONETICISI', [
    'gunluk_bildirim.create',
    'gunluk_bildirim.update_own_open',
    'gunluk_bildirim.submit',
    'gunluk_bildirim.complete_day',
    'haftalik_mutabakat.approve',
    'aylik_bildirim_onayi.approve',
    'puantaj.amir_kontrol',
    'attendance.correction.decide',
    'puantaj.bildirim_etki.generate',
    'puantaj.bildirim_etki.apply',
], false, 'SUBE_NOT_A_SUPERROLE_UNION');

// --- Existing roles keep their behaviour ----------------------------------------------

mgAssertHas('GENEL_YONETICI', [
    'bordro_kesinlestirme.approve',
    'genel_yonetici_onayi.approve',
    'genel_yonetici_bildirim_onayi.approve',
    'sgk_karar_paketi.approve',
    'yonetim-paneli.manage',
], true, 'GENEL_YONETICI_KEEPS_FINAL_AUTHORITY');

mgAssertHas('IK_SORUMLUSU', [
    'maas_hesaplama.manage',
    'maas_hesaplama_adaylari.manage',
    'bordro_on_izleme.view',
    'personel_bordro_kapsam.manage',
    'sgk_karar_paketi.prepare',
], true, 'IK_SORUMLUSU_IS_CENTRAL_CONTROL_OWNER');

mgAssertHas('IK_SORUMLUSU', [
    'bordro_kesinlestirme.approve',
    'personel_bordro_kapsam.approve',
    'genel_yonetici_onayi.approve',
    'genel_yonetici_bildirim_onayi.approve',
    'sgk_karar_paketi.approve',
], false, 'IK_SORUMLUSU_CANNOT_TAKE_FINAL_STEP');

mgAssertHas('BIRIM_AMIRI', [
    'gunluk_bildirim.create',
    'gunluk_bildirim.submit',
    'gunluk_bildirim.complete_day',
    'haftalik_mutabakat.approve',
    'aylik_bildirim_onayi.approve',
    'puantaj.amir_kontrol',
], true, 'BIRIM_AMIRI_NO_REGRESSION');

mgAssertHas('BOLUM_YONETICISI', [
    'aylik_bolum_onayi.approve',
    'sgk_karar_paketi.approve',
    'finans.create',
    'attendance.correction.decide',
    'personeller.update',
], true, 'BOLUM_YONETICISI_NO_REGRESSION');

// --- Branch scope role classification -------------------------------------------------

if (!in_array('SUBE_YONETICISI', OrgScope::SUBE_ASSIGNMENT_ROLES, true)) {
    mgFail('SUBE_YONETICISI must require explicit user_subeler');
}
if (in_array('SUBE_YONETICISI', OrgScope::GLOBAL_ROLES, true)) {
    mgFail('SUBE_YONETICISI must never be an unrestricted role');
}
mgOk('SUBE_REQUIRES_EXPLICIT_USER_SUBELER');

$where = [];
$params = [];
OrgScope::appendPersonelOrgFilter($where, $params, mgUser('SUBE_YONETICISI', []), null, 'p');
if (!in_array('1=0', $where, true)) {
    mgFail('empty user_subeler must fail closed in list filter');
}
mgOk('SUBE_EMPTY_SCOPE_FAILS_CLOSED');

// --- DualControl separation of duties -------------------------------------------------

$approver = mgUser('GENEL_YONETICI', [], 7);

if (DualControl::violation($approver, 7) === null) {
    mgFail('same user id must be rejected as self-approval');
}
if ((DualControl::violation($approver, 7))['code'] !== DualControl::CODE_SELF_APPROVAL) {
    mgFail('self-approval must report SELF_APPROVAL_FORBIDDEN');
}
mgOk('SELF_APPROVAL_REJECTED');

if (DualControl::violation($approver, 8) !== null) {
    mgFail('distinct enterer must be accepted');
}
mgOk('DISTINCT_ENTERER_ACCEPTED');

foreach ([null, '', 0, '0', -3] as $unknown) {
    $violation = DualControl::violation($approver, $unknown);
    if ($violation === null || $violation['code'] !== DualControl::CODE_ENTERED_BY_REQUIRED) {
        mgFail('unknown enterer must fail closed, got ' . var_export($unknown, true));
    }
}
mgOk('UNKNOWN_ENTERER_FAILS_CLOSED');

$violation = DualControl::violation(['rol' => 'GENEL_YONETICI'], 8);
if ($violation === null || $violation['code'] !== DualControl::CODE_ACTOR_REQUIRED) {
    mgFail('unresolvable actor must fail closed');
}
mgOk('UNRESOLVABLE_ACTOR_FAILS_CLOSED');

// Role name is never a bypass: every role self-approves identically (rejected).
foreach (['GENEL_YONETICI', 'IK_SORUMLUSU', 'SUBE_YONETICISI', 'BOLUM_YONETICISI', 'BIRIM_AMIRI'] as $rol) {
    if (DualControl::isSeparated(mgUser($rol, [], 42), 42)) {
        mgFail("{$rol} must not be able to approve its own record");
    }
    if (!DualControl::isSeparated(mgUser($rol, [], 42), 43)) {
        mgFail("{$rol} must be able to approve another actor's record");
    }
}
mgOk('NO_ROLE_NAME_BYPASS');

echo "MG_SUBE_YONETICI_PERMISSION_MODEL=PASS\n";
exit(0);
