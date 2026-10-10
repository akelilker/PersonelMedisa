<?php

declare(strict_types=1);

/**
 * PR1 — etkin izin çözücüsü: SIFIR davranış değişikliği kanıtı (DB'siz).
 *
 * 1) PR öncesi RolePermissions::has gövdesinin dondurulmuş kopyası (legacyHas,
 *    matrisi private matrix() üzerinden okur) ile yeni EffectivePermissionResolver
 *    her rol × her katalog izni × her bağlam (personel bağlı/bağsız, mavi yaka/
 *    beyaz yaka/bilinmeyen, eksik/bozuk alanlar, rol yazım varyantları) için
 *    birebir aynı karar verir.
 * 2) Her rolün izin kümesi PR öncesi anlık görüntüyle (sayı + sha256) aynıdır.
 *
 * php tests/php/EffectivePermissionResolverEquivalenceTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\EffectivePermissionResolver;
use Medisa\Api\Auth\RolePermissions;

/**
 * Rol matrisi anlık görüntüsü: [izin sayısı, sha256(sıralı, "\n")].
 * GENEL_YONETICI: 1f0513f1'de 108 (36607fa7…); dinamik yetki P2 ile BİLİNÇLİ olarak
 * +3 (kullanici_yetkileri.view/.manage/.audit.view) = 111. Diğer roller değişmedi.
 */
const EPR_GY_P2_EKLENEN = ['kullanici_yetkileri.audit.view', 'kullanici_yetkileri.manage', 'kullanici_yetkileri.view'];
const EPR_GY_1F0513F1_HASH = '36607fa7c55a01136a5c48e2882b5e28b247d13c9e9cf67d2e7f889688d20bb5';
const EPR_SNAPSHOT = [
    'GENEL_YONETICI' => [111, '63afe1e96011603dd2e7a13eac563202e4b611e65645b03efedd006e6d30e028'],
    'SUBE_YONETICISI' => [43, '27b96fb8fd46994424b9f2f1d963bdaf7684a8f4e3025a4730da00450d089319'],
    'BOLUM_YONETICISI' => [53, '19acbe2b53a077fe380f6f014e597c5cdfd2ac3a1a13013c23ed5424a46ffa17'],
    'MUHASEBE' => [26, '2cc71406aa8b0afa58c8a418d59daaa4198bbdef37f2cb6ac7b92dad0883154e'],
    'BIRIM_AMIRI' => [29, 'c598f41c12a881be91654f4b3e2ee9602f07b2012cb1d97481ad2857992c34e8'],
    'IK_SORUMLUSU' => [60, '1d6ad6ac0ea106e5d60a8003faaa39281c87dc56121a8b22bedbd8745328f078'],
    'SISTEM_YONETICISI' => [47, '18aebf35c95ffc4eced969e012424371f84b208bda9ce93ef7bd0ec34bf60d67'],
    'PERSONEL' => [7, 'dc6e0e4f19160817baa980bf99b3ec6a19b5a88241fed13dde8e28608bf50920'],
    'AUTH_SMOKE_READONLY' => [1, '821b05b94957f3c21385b72d6d00ffe520534952b4fbac9f8d5a57fd57d9dcf0'],
    'IK_PERSONELI' => [53, 'b3c7de77b2adc7366e3c703a2219b7ef659f043cb545ac99d7559a55805d9686'],
];

function eprFail(string $message): void
{
    throw new RuntimeException('[FAIL] ' . $message);
}

/** @return array<string, array<int, string>> */
function eprLegacyMatrix(): array
{
    $method = new ReflectionMethod(RolePermissions::class, 'matrix');
    $method->setAccessible(true);

    return $method->invoke(null);
}

/**
 * PR öncesi RolePermissions::has gövdesi (1f0513f1), değiştirilmeden.
 *
 * @param array<string, mixed> $user
 * @param mixed $permission
 */
function legacyHas(array $user, $permission): bool
{
    $permission = trim((string) $permission);
    if ($permission === '') {
        return false;
    }
    if (RolePermissions::isQrSelfServicePermission($permission)) {
        return RolePermissions::hasQrSelfServiceEntitlement($user);
    }
    if (
        RolePermissions::hasPersonnelLinkedSelfServiceEligibility($user)
        && in_array($permission, RolePermissions::selfServiceBaselinePermissions(), true)
    ) {
        return true;
    }
    $role = RolePermissions::normalizeRole(isset($user['rol']) ? (string) $user['rol'] : '');
    if ($role === '') {
        return false;
    }
    $matrix = eprLegacyMatrix();
    if (!isset($matrix[$role])) {
        return false;
    }

    return in_array($permission, $matrix[$role], true);
}

$matrix = eprLegacyMatrix();

// --- 2) Rol izin kümeleri anlık görüntüyle aynı -------------------------------
if (array_keys(EPR_SNAPSHOT) != array_keys($matrix) && count(array_diff(array_keys($matrix), array_keys(EPR_SNAPSHOT))) > 0) {
    eprFail('rol listesi değişti: ' . implode(',', array_keys($matrix)));
}
foreach (EPR_SNAPSHOT as $role => [$count, $hash]) {
    $list = array_values(array_unique($matrix[$role] ?? []));
    sort($list, SORT_STRING);
    if (count($list) !== $count || hash('sha256', implode("\n", $list)) !== $hash) {
        eprFail("rol izin kümesi değişti: {$role} (" . count($list) . ')');
    }
    $viaAccessor = RolePermissions::roleDefaultPermissions($role);
    sort($viaAccessor, SORT_STRING);
    if ($viaAccessor !== $list) {
        eprFail("roleDefaultPermissions farklı: {$role}");
    }
    echo "[PASS] snapshot {$role} = {$count}\n";
}

// GY: P2 öncesi 108 izin aynen korunur, yalnız 3 yetki-yönetimi izni eklenir.
$gyList = array_values(array_unique($matrix['GENEL_YONETICI']));
$gyEski = array_values(array_diff($gyList, EPR_GY_P2_EKLENEN));
sort($gyEski, SORT_STRING);
if (count($gyEski) !== 108 || hash('sha256', implode("\n", $gyEski)) !== EPR_GY_1F0513F1_HASH
    || count(array_intersect($gyList, EPR_GY_P2_EKLENEN)) !== 3
) {
    eprFail('GENEL_YONETICI P2 öncesi 108 izin korunmadı');
}
foreach ($matrix as $role => $list) {
    if ($role !== 'GENEL_YONETICI' && array_intersect($list, EPR_GY_P2_EKLENEN) !== []) {
        eprFail('yetki yönetimi izni GY dışı rolde: ' . $role);
    }
}
echo "[PASS] GENEL_YONETICI 1f0513f1 108 izni korunur + 3 yetki yönetimi izni (111)\n";

// --- 1) Eski karar == yeni karar ----------------------------------------------
// İstisna listesi boş/etkisiz bağlamları: yok, boş, yalnız etkisiz satırlar
// (gelecekte başlayan, süresi dolmuş, başka izin, bozuk etki). Hepsinde karar
// PR #528 ile birebir aynı olmalı.
\Medisa\Api\Auth\EffectivePermissionResolver::setNowForTests('2026-10-11 12:00:00');
$emptyExceptionVariants = [
    '__missing__',
    [],
    [
        ['permission' => 'personeller.view', 'etki' => 'DENY', 'sube_id' => null, 'gecerlilik_baslangic' => '2026-11-01 00:00:00', 'gecerlilik_bitis' => null],
        ['permission' => 'personeller.view', 'etki' => 'DENY', 'sube_id' => null, 'gecerlilik_baslangic' => '2025-01-01 00:00:00', 'gecerlilik_bitis' => '2026-10-11 12:00:00'],
        ['permission' => 'bilinmeyen.izin.x', 'etki' => 'DENY', 'sube_id' => null, 'gecerlilik_baslangic' => '2025-01-01 00:00:00', 'gecerlilik_bitis' => null],
        ['permission' => 'personeller.view', 'etki' => 'BELKI', 'sube_id' => null, 'gecerlilik_baslangic' => '2025-01-01 00:00:00', 'gecerlilik_bitis' => null],
        ['permission' => 'personeller.view', 'etki' => 'DENY', 'sube_id' => null, 'gecerlilik_baslangic' => '', 'gecerlilik_bitis' => null],
    ],
];
$catalog = RolePermissions::permissionCatalog();
$permissions = array_merge($catalog, [
    '', '   ', 'bilinmeyen.izin', 'bildirimler.cancel', ' personeller.view ', "\tself_service.qr.scan\n",
    'PERSONELLER.VIEW', 'yonetim-paneli.manage ',
]);

$roleVariants = array_merge(RolePermissions::roles(), [
    '', 'PATRON', 'ADMIN', 'genel_yonetici', ' GENEL_YONETICI ', 'Ik_Sorumlusu', 'personel', null, 123,
]);

$personelVariants = [
    '__missing__', null, '', 0, '0', -3, 5, '5', '12abc', 7.0,
];
$collarVariants = [
    '__missing__', null, '', 'Mavi Yaka', '  mavi   YAKA ', 'MAVİ YAKA', 'Beyaz Yaka', 'Diğer', 42, ['Mavi Yaka'],
];

$cases = 0;
$sources = [];
foreach ($roleVariants as $rol) {
    foreach ($personelVariants as $personelId) {
        foreach ($collarVariants as $collar) {
            foreach ($emptyExceptionVariants as $exceptionVariant) {
            $user = ['id' => 1];
            if ($exceptionVariant !== '__missing__') {
                $user['yetki_istisnalari'] = $exceptionVariant;
            }
            if ($rol !== null) {
                $user['rol'] = $rol;
            }
            if ($personelId !== '__missing__') {
                $user['personel_id'] = $personelId;
            }
            if ($collar !== '__missing__') {
                $user['personel_tipi_ad'] = $collar;
            }
            $effective = [];
            foreach ($permissions as $permission) {
                $old = legacyHas($user, $permission);
                $new = EffectivePermissionResolver::resolve($user, $permission);
                $viaHas = RolePermissions::has($user, $permission);
                if ($old !== $new || $old !== $viaHas) {
                    eprFail('karar farkı: ' . json_encode([$user, $permission, $old, $new, $viaHas], JSON_UNESCAPED_UNICODE));
                }
                $source = EffectivePermissionResolver::source($user, $permission);
                if (($source !== null) !== $new) {
                    eprFail('kaynak/karar tutarsız: ' . json_encode([$user, $permission]));
                }
                if ($source !== null) {
                    $sources[$source] = true;
                }
                if ($new && in_array($permission, $catalog, true)) {
                    $effective[] = $permission;
                }
                $cases++;
            }
            sort($effective, SORT_STRING);
            if (EffectivePermissionResolver::effectivePermissions($user) !== $effective) {
                eprFail('effectivePermissions farklı: ' . json_encode($user, JSON_UNESCAPED_UNICODE));
            }
            }
        }
    }
}
foreach ([EffectivePermissionResolver::SOURCE_QR_SELF_SERVICE, EffectivePermissionResolver::SOURCE_SELF_SERVICE_BASELINE, EffectivePermissionResolver::SOURCE_ROLE_DEFAULT] as $s) {
    if (!isset($sources[$s])) {
        eprFail('kaynak hiç üretilmedi: ' . $s);
    }
}
echo '[PASS] eski RolePermissions::has == EffectivePermissionResolver: ' . $cases . ' karar, katalog ' . count($catalog) . " izin\n";

// Bağlam örnekleri (sözleşme): mavi yaka bağlı GY QR alır; beyaz yaka almaz;
// bağsız hesap self-service temeli almaz; QR izni rolle genişlemez.
$gyMavi = ['rol' => 'GENEL_YONETICI', 'personel_id' => 9, 'personel_tipi_ad' => 'Mavi Yaka'];
$gyBeyaz = ['rol' => 'GENEL_YONETICI', 'personel_id' => 9, 'personel_tipi_ad' => 'Beyaz Yaka'];
$gyBagsiz = ['rol' => 'GENEL_YONETICI'];
if (!EffectivePermissionResolver::resolve($gyMavi, 'self_service.qr.scan')
    || EffectivePermissionResolver::resolve($gyBeyaz, 'self_service.qr.scan')
    || EffectivePermissionResolver::resolve($gyBagsiz, 'self_service.qr.scan')
) {
    eprFail('QR bağlam sözleşmesi');
}
echo '[PASS] boş/etkisiz istisna listesiyle karar değişmez (' . count($emptyExceptionVariants) . " varyant)\n";
echo '[PASS] GY etkin: bağsız ' . count(EffectivePermissionResolver::effectivePermissions($gyBagsiz))
    . ', bağlı beyaz yaka ' . count(EffectivePermissionResolver::effectivePermissions($gyBeyaz))
    . ', bağlı mavi yaka ' . count(EffectivePermissionResolver::effectivePermissions($gyMavi)) . "\n";

echo "verify-effective-permission-resolver-equivalence: OK\n";
