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
    'puantaj.donem_reopen.request',
    'bildirimler.create',
    'bildirimler.update',
    'revizyon.create',
    'revizyon.submit',
    'haftalik_mutabakat.view',
    'aylik_bildirim_onayi.view',
    // Branch-scoped payroll input split out of the overloaded puantaj.muhurle key.
    'fazla_calisma_odeme_tercihi.manage',
    'serbest_zaman.manage',
], true, 'SUBE_BRANCH_OPERATIONAL_DATA_ENTRY_AND_SUBMIT');

mgAssertHas('SUBE_YONETICISI', [
    // Corrective removals: period closing, bulk import, department approval, bordro effect.
    'puantaj.donem_muhurle',
    'puantaj.haftalik_kapanis.manage',
    'personeller.import.apply',
    'aylik_bolum_onayi.approve',
    'aylik-ozet.review',
    'revizyon.view_finance_effect',
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

// The split must not hand IK a closing authority it never had under puantaj.muhurle.
mgAssertHas('IK_SORUMLUSU', [
    'puantaj.donem_muhurle',
    'puantaj.haftalik_kapanis.manage',
], false, 'IK_SORUMLUSU_GAINS_NO_CLOSURE_FROM_SPLIT');

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

// Reviewed and deliberately kept: branch-scoped operational decisions that assert
// SubeScope::assertPersonelAccess on a DB-loaded row and record their acting user.
mgAssertHas('SUBE_YONETICISI', [
    'disiplin.final_decision',
    'puantaj.olay_karar.decide',
    'surecler.cancel',
    'bildirimler.cancel',
    'revizyon.cancel',
    'finans.view',
], true, 'SUBE_KEEPS_BRANCH_SCOPED_OPERATIONAL_DECISIONS');

// --- Real endpoint gates, read from the owning source ---------------------------------

/**
 * Reads the permission list an endpoint gate actually consults, so the assertion below
 * is bound to the real gate instead of a hand-copied list that can drift.
 *
 * @return array<int, string>
 */
function mgGatePermissions($file, $anchor, $window = 400)
{
    $source = file_get_contents($file);
    if ($source === false) {
        mgFail("gate source unreadable: {$file}");
    }
    $at = strpos($source, $anchor);
    if ($at === false) {
        mgFail("gate anchor not found in {$file}: {$anchor}");
    }
    $slice = substr($source, $at, $window);
    if (!preg_match_all("/'([a-z0-9_.\\-]+\\.[a-z0-9_.\\-]+)'/i", $slice, $m)) {
        mgFail("no permission literals near anchor in {$file}: {$anchor}");
    }

    return array_values(array_unique($m[1]));
}

$apiRoot = dirname(__DIR__, 2) . '/api/src';
$subeYonetici = mgUser('SUBE_YONETICISI', [3]);

$gates = [
    'AYLIK_BOLUM_ONAY_WRITE' => mgGatePermissions(
        $apiRoot . '/Controllers/YonetimController.php',
        'private static function assertBolumOnayPermission'
    ),
    'PERSONEL_IMPORT_APPLY' => mgGatePermissions(
        $apiRoot . '/Controllers/PersonellerController.php',
        'public static function importApply'
    ),
    'PUANTAJ_AYLIK_MUHURLE' => mgGatePermissions(
        $apiRoot . '/Controllers/PuantajController.php',
        'public static function muhurleAylik'
    ),
    'HAFTALIK_KAPANIS_CREATE' => mgGatePermissions(
        $apiRoot . '/Controllers/HaftalikKapanisController.php',
        'public static function create'
    ),
];

// Branch payroll input gates must ADMIT the branch manager after the split.
$operationalGates = [
    'FAZLA_CALISMA_ODEME_TERCIHI_PUT' => mgGatePermissions(
        $apiRoot . '/Controllers/FazlaCalismaOdemeTercihiController.php',
        'public static function put'
    ),
    'SERBEST_ZAMAN_OLUSUM' => mgGatePermissions(
        $apiRoot . '/Controllers/SerbestZamanController.php',
        'public static function olusum'
    ),
    'SERBEST_ZAMAN_KULLANIM' => mgGatePermissions(
        $apiRoot . '/Controllers/SerbestZamanController.php',
        'public static function kullanim'
    ),
    'SERBEST_ZAMAN_IPTAL' => mgGatePermissions(
        $apiRoot . '/Controllers/SerbestZamanController.php',
        'public static function iptal'
    ),
    'SERBEST_ZAMAN_DUZELTME' => mgGatePermissions(
        $apiRoot . '/Controllers/SerbestZamanController.php',
        'public static function duzeltme'
    ),
];

foreach ($gates as $label => $permissions) {
    $granted = [];
    foreach ($permissions as $permission) {
        if (RolePermissions::has($subeYonetici, $permission)) {
            $granted[] = $permission;
        }
    }
    if ($granted !== []) {
        mgFail("{$label}: SUBE_YONETICISI must be denied, but holds " . implode(', ', $granted));
    }
    mgOk("GATE_DENIES_SUBE_YONETICISI: {$label}");
}

$closurePermissions = ['puantaj.donem_muhurle', 'puantaj.haftalik_kapanis.manage'];
foreach ($operationalGates as $label => $permissions) {
    if ($permissions === []) {
        mgFail("{$label}: no permission literal found at gate");
    }
    foreach ($permissions as $permission) {
        if (in_array($permission, $closurePermissions, true)) {
            mgFail("{$label}: operational write must not be gated by a closure permission");
        }
        if (!RolePermissions::has($subeYonetici, $permission)) {
            mgFail("{$label}: SUBE_YONETICISI must hold {$permission}");
        }
        if (!RolePermissions::has(mgUser('GENEL_YONETICI'), $permission)
            || !RolePermissions::has(mgUser('BOLUM_YONETICISI'), $permission)
        ) {
            mgFail("{$label}: {$permission} must stay granted to GENEL/BOLUM_YONETICISI");
        }
    }
    mgOk("GATE_ADMITS_SUBE_YONETICISI: {$label}");
}

// Splitting must not leave an alternative gate: no write path may accept both a closure
// permission and an operational one, and no closure endpoint may accept an operational key.
foreach ($gates as $label => $permissions) {
    foreach ($permissions as $permission) {
        if (in_array($permission, ['fazla_calisma_odeme_tercihi.manage', 'serbest_zaman.manage'], true)) {
            mgFail("{$label}: closure gate must not accept a branch operational permission");
        }
    }
}
mgOk('NO_ALTERNATIVE_GATE_BETWEEN_CLOSURE_AND_OPERATIONAL');

// Every operational write path authorizes the DB-loaded target, never a client sube_id.
$scopeOwners = [
    '/Controllers/FazlaCalismaOdemeTercihiController.php' => [
        'anchors' => ['public static function put'],
        'scope' => 'self::assertSnapshotScope($user, $request,',
        'load' => 'self::loadSnapshotSatir(',
    ],
    '/Controllers/SerbestZamanController.php' => [
        'anchors' => [
            'public static function olusum',
            'public static function kullanim',
            'public static function iptal',
            'public static function duzeltme',
        ],
        'scope' => 'self::assertPersonelScope($user, $request, $personel)',
        'load' => 'self::loadPersonel($pdo,',
    ],
];
foreach ($scopeOwners as $relative => $spec) {
    $source = file_get_contents($apiRoot . $relative);
    if ($source === false) {
        mgFail("scope owner source unreadable: {$relative}");
    }
    if (strpos($source, 'SubeScope::assertPersonelAccess') === false) {
        mgFail("{$relative} must delegate to SubeScope::assertPersonelAccess");
    }
    // Empty user_subeler must be denied before any personel row is trusted.
    if (strpos($source, "count(\$allowed) === 0 && !RolePermissions::has(\$user, 'personeller.view')") === false) {
        mgFail("{$relative} must fail closed on empty user_subeler");
    }
    foreach ($spec['anchors'] as $anchor) {
        $at = strpos($source, $anchor);
        if ($at === false) {
            mgFail("write path missing in {$relative}: {$anchor}");
        }
        $end = strpos($source, "\n    public static function", $at + strlen($anchor));
        $body = $end === false ? substr($source, $at) : substr($source, $at, $end - $at);
        if (strpos($body, $spec['scope']) === false) {
            mgFail("{$relative}::{$anchor} must assert branch scope on the loaded row");
        }
        if (strpos($body, $spec['load']) === false) {
            mgFail("{$relative}::{$anchor} must load the target from the database");
        }
        if (preg_match("/\\\$body\\['sube_id'\\]/", $body)) {
            mgFail("{$relative}::{$anchor} must not trust a client-supplied sube_id");
        }
        if (strpos($body, "'created_by' => \$userId > 0 ? \$userId : null") === false
            && strpos($body, '$userId > 0 ? $userId : null') === false
        ) {
            mgFail("{$relative}::{$anchor} must record the acting user");
        }
    }
    mgOk('SCOPE_ENFORCED_ON_DB_LOADED_TARGET: ' . $relative);
}

// The same gates must still admit the roles that own them (no unrelated regression).
if (!RolePermissions::has(mgUser('BOLUM_YONETICISI'), 'aylik_bolum_onayi.approve')) {
    mgFail('BOLUM_YONETICISI must keep aylik_bolum_onayi.approve');
}
foreach ([
    'puantaj.donem_muhurle',
    'puantaj.haftalik_kapanis.manage',
    'fazla_calisma_odeme_tercihi.manage',
    'serbest_zaman.manage',
] as $granular) {
    if (!RolePermissions::has(mgUser('GENEL_YONETICI'), $granular)) {
        mgFail("GENEL_YONETICI must keep {$granular}");
    }
    if (!RolePermissions::has(mgUser('BOLUM_YONETICISI'), $granular)) {
        mgFail("BOLUM_YONETICISI must keep {$granular}");
    }
}
// The overloaded key must be gone from the matrix entirely (no compatibility alias).
$permissionsSource = file_get_contents($apiRoot . '/Auth/RolePermissions.php');
if ($permissionsSource === false) {
    mgFail('RolePermissions source unreadable');
}
if (strpos($permissionsSource, 'puantaj.muhurle') !== false) {
    mgFail('puantaj.muhurle must not remain in the backend permission matrix');
}
foreach (['GENEL_YONETICI', 'BOLUM_YONETICISI', 'SUBE_YONETICISI', 'IK_SORUMLUSU', 'MUHASEBE', 'BIRIM_AMIRI', 'SISTEM_YONETICISI', 'PERSONEL'] as $rol) {
    if (RolePermissions::has(mgUser($rol), 'puantaj.muhurle')) {
        mgFail("{$rol} must not resolve the removed puantaj.muhurle key");
    }
}
mgOk('OLD_OVERLOADED_PERMISSION_FULLY_REMOVED');

// --- Central payroll control chain ----------------------------------------------------
// Branch input → central İK control → a different GENEL_YONETICI finalizes.
if (!RolePermissions::has(mgUser('IK_SORUMLUSU'), 'bordro_on_izleme.view')
    || !RolePermissions::has(mgUser('IK_SORUMLUSU'), 'maas_hesaplama.manage')
) {
    mgFail('IK_SORUMLUSU must be able to see and control bordro hazirlik data');
}
if (RolePermissions::has(mgUser('IK_SORUMLUSU'), 'bordro_kesinlestirme.approve')) {
    mgFail('IK_SORUMLUSU must not finalize bordro');
}
if (!RolePermissions::has(mgUser('GENEL_YONETICI'), 'bordro_kesinlestirme.approve')) {
    mgFail('GENEL_YONETICI must own bordro finalization');
}
if (RolePermissions::has($subeYonetici, 'bordro_on_izleme.view')
    || RolePermissions::has($subeYonetici, 'bordro_kesinlestirme.approve')
) {
    mgFail('SUBE_YONETICISI must stay out of the central bordro control chain');
}
$bordroSource = file_get_contents($apiRoot . '/Services/BordroOnIzlemeService.php');
if ($bordroSource === false) {
    mgFail('BordroOnIzlemeService source unreadable');
}
if (strpos($bordroSource, "'muhasebe_kontrol_by'") === false) {
    mgFail('kesinlestir must separate duties against the muhasebe control actor');
}
mgOk('CENTRAL_CONTROL_CHAIN_SEPARATED');
mgOk('GATE_OWNERS_UNCHANGED');

// --- DualControl is the canonical owner; SGK delegates to it --------------------------

if (DualControl::isSameActorUser($approver, 7) !== true) {
    mgFail('isSameActorUser must match identical user ids');
}
foreach ([null, '', 0, '0', -3, 8] as $notSame) {
    if (DualControl::isSameActorUser($approver, $notSame)) {
        mgFail('isSameActorUser must not match ' . var_export($notSame, true));
    }
}
mgOk('SAME_ACTOR_USER_PREDICATE');

// SgkKararPaketiAuthz keeps its public contract while delegating the decision.
$self = \Medisa\Api\Services\Payroll\SgkKararPaketiAuthz::denySelfApproval($approver, 7);
if (!empty($self['ok']) || $self['code'] !== 'SGK_SELF_APPROVAL_FORBIDDEN') {
    mgFail('SGK denySelfApproval must still reject the preparer with its own code');
}
$other = \Medisa\Api\Services\Payroll\SgkKararPaketiAuthz::denySelfApproval($approver, 8);
if (empty($other['ok'])) {
    mgFail('SGK denySelfApproval must accept a distinct preparer');
}
$unknown = \Medisa\Api\Services\Payroll\SgkKararPaketiAuthz::denySelfApproval($approver, 0);
if (empty($unknown['ok'])) {
    mgFail('SGK denySelfApproval must leave unknown preparer to denySamePerson (contract)');
}
mgOk('SGK_AUTHZ_DELEGATES_WITHOUT_CONTRACT_BREAK');

// Dependency direction: generic auth owner must not depend on the payroll service.
$dualControlSource = file_get_contents($apiRoot . '/Auth/DualControl.php');
if ($dualControlSource === false) {
    mgFail('DualControl source unreadable');
}
if (strpos($dualControlSource, 'SgkKararPaketiAuthz') !== false) {
    mgFail('DualControl must not depend on payroll SgkKararPaketiAuthz');
}
if (strpos($dualControlSource, 'function resolveActorIdentityId') === false
    || strpos($dualControlSource, 'function actorIdentitySchemaSupported') === false
) {
    mgFail('DualControl must own actor identity resolution');
}
mgOk('DEPENDENCY_DIRECTION_DOMAIN_TO_DUALCONTROL');

// Every former self-approval owner routes through the canonical decision.
foreach ([
    '/Services/PuantajDonemReopenService.php',
    '/Services/Payroll/SgkKararPaketiAuthz.php',
    '/Services/Payroll/SgkKatalogOnayService.php',
    '/Services/SirketCalismaPolitikasiService.php',
    '/Services/Qr/QrAttendanceCorrectionService.php',
    '/Services/PersonelBordroKapsamService.php',
    '/Services/BordroOnIzlemeService.php',
    '/Controllers/GenelYoneticiBildirimOnaylariController.php',
] as $relative) {
    $source = file_get_contents($apiRoot . $relative);
    if ($source === false || strpos($source, 'DualControl::') === false) {
        mgFail("self-approval owner must delegate to DualControl: {$relative}");
    }
}
mgOk('ALL_SELF_APPROVAL_OWNERS_USE_CANONICAL_DECISION');

// direkt_onayla can no longer create an already-approved record.
$kapsamSource = file_get_contents($apiRoot . '/Services/PersonelBordroKapsamService.php');
if ($kapsamSource === false) {
    mgFail('PersonelBordroKapsamService source unreadable');
}
if (preg_match("/direkt_onayla.*\n.*initialState = 'ONAYLANDI'/", $kapsamSource)) {
    mgFail('direkt_onayla must not short-circuit into ONAYLANDI');
}
if (strpos($kapsamSource, "\$initialState = 'TASLAK';") === false) {
    mgFail('bordro kapsam create must always start as TASLAK');
}
mgOk('BORDRO_KAPSAM_DIRECT_APPROVE_REMOVED');

echo "MG_SUBE_YONETICI_PERMISSION_MODEL=PASS\n";
exit(0);
