<?php

declare(strict_types=1);

/**
 * Dinamik yetki P2 — kişiye özel istisna kuralları (DB'siz).
 * php tests/php/EffectivePermissionResolverIstisnaTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\EffectivePermissionResolver as R;
use Medisa\Api\Auth\RolePermissions;

function epiAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** @return array<string, mixed> */
function epiRow(string $perm, string $etki, ?int $sube = null, string $from = '2026-01-01 00:00:00', ?string $until = null): array
{
    return ['permission' => $perm, 'etki' => $etki, 'sube_id' => $sube, 'gecerlilik_baslangic' => $from, 'gecerlilik_bitis' => $until];
}

R::setNowForTests('2026-10-11 12:00:00');

// Şube kapsamlı istisnalar P2'de KAPALI: satır olsa bile hiçbir kararda kullanılmaz.
epiAssert(R::SUBE_ISTISNALARI_ETKIN === false && R::subeIstisnalariEtkin() === false, 'K0 şube kapsamlı istisnalar varsayılan KAPALI');
$kapali = ['id' => 1, 'rol' => 'MUHASEBE', 'sube_ids' => [1], 'yetki_istisnalari' => [
    epiRow('personeller.create', 'ALLOW', 1), epiRow('bordro_on_izleme.view', 'DENY', 1),
]];
epiAssert(!R::resolveForSube($kapali, 'personeller.create', 1) && R::resolveForSube($kapali, 'bordro_on_izleme.view', 1)
    && R::resolve($kapali, 'bordro_on_izleme.view') && !R::resolve($kapali, 'personeller.create'), 'K1 kapalıyken şube ALLOW izin vermez, şube DENY engellemez (her kararda)');
// Aşağıdaki şube semantiği testleri için geçici açma (yalnız test).
R::setSubeIstisnalariForTests(true);

$muh = ['id' => 5, 'rol' => 'MUHASEBE', 'sube_ids' => [1, 2]];
$gy = ['id' => 6, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$birim = ['id' => 7, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
$perm = 'bordro_on_izleme.view';           // MUHASEBE varsayılanı
$extra = 'personeller.create';              // MUHASEBE varsayılanı değil

epiAssert(RolePermissions::has($muh, $perm) && !RolePermissions::has($muh, $extra), '0 başlangıç: rol varsayılanı');

// DENY
epiAssert(!R::resolve($muh + ['yetki_istisnalari' => [epiRow($perm, 'DENY')]], $perm), '1 global DENY rol varsayılanını kapatır');
epiAssert(!R::resolveForSube($muh + ['yetki_istisnalari' => [epiRow($perm, 'DENY')]], $perm, 2), '2 global DENY tüm şubeleri kapatır');
$subeDeny = $muh + ['yetki_istisnalari' => [epiRow($perm, 'DENY', 1)]];
epiAssert(!R::resolveForSube($subeDeny, $perm, 1) && R::resolveForSube($subeDeny, $perm, 2), '3 şube DENY yalnız o şubeyi kapatır');
epiAssert(R::resolve($subeDeny, $perm), '4 şubesiz kontrolde şube DENY engellemez (kayıt düzeyinde uygulanır)');
// Kayseri (şube 7) senaryosu: kapsam 7 + 8
$kay = ['id' => 9, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [7, 8]];
$kayAllow = $kay + ['yetki_istisnalari' => [epiRow($extra, 'ALLOW', 7)]];
epiAssert(RolePermissions::hasForSube($kayAllow, $extra, 7) && !RolePermissions::hasForSube($kayAllow, $extra, 8) && !RolePermissions::has($kayAllow, $extra), '4a Kayseri ALLOW (düzenleme) yalnız Kayseri\'de');
$kayDenyPerm = RolePermissions::roleDefaultPermissions('BIRIM_AMIRI')[0];
$kayDeny = $kay + ['yetki_istisnalari' => [epiRow($kayDenyPerm, 'DENY', 7)]];
epiAssert(!RolePermissions::hasForSube($kayDeny, $kayDenyPerm, 7) && RolePermissions::hasForSube($kayDeny, $kayDenyPerm, 8) && RolePermissions::has($kayDeny, $kayDenyPerm), '4b Kayseri DENY diğer şubeleri engellemez');
$kayGlobalDeny = $kay + ['yetki_istisnalari' => [epiRow($kayDenyPerm, 'DENY')]];
epiAssert(!RolePermissions::hasForSube($kayGlobalDeny, $kayDenyPerm, 8) && !RolePermissions::has($kayGlobalDeny, $kayDenyPerm), '4c global DENY her şubede ve şubesiz kontrolde engeller');
epiAssert(!R::resolve($muh + ['yetki_istisnalari' => [epiRow($extra, 'ALLOW'), epiRow($extra, 'DENY')]], $extra), '5 DENY ALLOW\'u yener');
epiAssert(!R::resolve($gy + ['yetki_istisnalari' => [epiRow('bordro_kesinlestirme.approve', 'DENY')]], 'bordro_kesinlestirme.approve'), '6 GY\'nin bordro kesinleştirme kullanımı DENY ile kapatılabilir');
epiAssert(R::resolve($gy + ['yetki_istisnalari' => [epiRow('kullanici_yetkileri.manage', 'DENY'), epiRow('yonetim-paneli.manage', 'DENY'), epiRow('kullanicilar.kalici_sil', 'DENY')]], 'kullanici_yetkileri.manage')
    && R::resolve($gy + ['yetki_istisnalari' => [epiRow('yonetim-paneli.manage', 'DENY')]], 'yonetim-paneli.manage'), '7 GY sistem hakları DENY ile kapatılamaz');

// ALLOW
$allow = $muh + ['yetki_istisnalari' => [epiRow($extra, 'ALLOW')]];
epiAssert(R::resolve($allow, $extra) && R::source($allow, $extra) === R::SOURCE_USER_ALLOW, '8 global ALLOW izni verir (kaynak USER_ALLOW)');
epiAssert(R::source($muh + ['yetki_istisnalari' => [epiRow($perm, 'ALLOW')]], $perm) === R::SOURCE_ROLE_DEFAULT, '9 rol varsayılanı ALLOW\'dan önce gelir');
$subeAllow = $birim + ['yetki_istisnalari' => [epiRow($extra, 'ALLOW', 1), epiRow('finans.view', 'ALLOW', 9)]];
epiAssert(!R::resolve($subeAllow, $extra), '10 şube ALLOW şube bağlamı olmadan izin vermez');
epiAssert(R::resolveForSube($subeAllow, $extra, 1), '11 şube ALLOW kapsam içindeki şubede geçerli');
epiAssert(!R::resolveForSube($subeAllow, 'finans.view', 9), '12 ALLOW kapsamı genişletmez (kapsam dışı şube 9)');
foreach (R::NON_GRANTABLE_PERMISSIONS as $red) {
    if (R::resolve($muh + ['yetki_istisnalari' => [epiRow($red, 'ALLOW')]], $red)) {
        epiAssert(false, '13 kırmızı liste ALLOW yok sayılır: ' . $red);
    }
}
epiAssert(true, '13 kırmızı liste izinleri ALLOW ile verilemez (' . count(R::NON_GRANTABLE_PERMISSIONS) . ' izin)');

// Süre (değerlendirme anında)
epiAssert(!R::resolve($muh + ['yetki_istisnalari' => [epiRow($extra, 'ALLOW', null, '2026-01-01 00:00:00', '2026-10-11 12:00:00')]], $extra), '14 bitişi geçmiş ALLOW geçersiz (bitiş anı dahil)');
epiAssert(R::resolve($muh + ['yetki_istisnalari' => [epiRow($extra, 'ALLOW', null, '2026-01-01 00:00:00', '2026-10-11 12:00:01')]], $extra), '15 bitişi gelecekte ALLOW geçerli');
epiAssert(!R::resolve($muh + ['yetki_istisnalari' => [epiRow($extra, 'ALLOW', null, '2026-10-12 00:00:00')]], $extra), '16 başlangıcı gelecekte ALLOW henüz geçersiz');
epiAssert(R::resolve($muh + ['yetki_istisnalari' => [epiRow($perm, 'DENY', null, '2026-01-01 00:00:00', '2026-10-01 00:00:00')]], $perm), '17 süresi dolmuş DENY rol varsayılanını kapatmaz');
epiAssert(R::resolve($muh + ['yetki_istisnalari' => [epiRow($extra, 'ALLOW', null, '2026-01-01 00:00:00', null)]], $extra), '18 bitişsiz (kalıcı) ALLOW geçerli');

// Bilinmeyen rol / self-service / QR istisnadan etkilenmez
epiAssert(!R::resolve(['rol' => 'PATRON', 'yetki_istisnalari' => [epiRow($extra, 'ALLOW')]], $extra), '19 bilinmeyen rolde ALLOW uygulanmaz (fail-closed)');
$personel = ['rol' => 'PERSONEL', 'personel_id' => 3, 'personel_tipi_ad' => 'Mavi Yaka', 'yetki_istisnalari' => [epiRow('self_service.qr.scan', 'DENY')]];
epiAssert(R::resolve($personel, 'self_service.qr.scan'), '20 QR self-service istisnadan etkilenmez');

// effectivePermissions
$eff = R::effectivePermissions($muh + ['yetki_istisnalari' => [epiRow($perm, 'DENY'), epiRow($extra, 'ALLOW')]]);
epiAssert(!in_array($perm, $eff, true) && in_array($extra, $eff, true), '21 effectivePermissions DENY/ALLOW\'u yansıtır');

R::setNowForTests(null);
R::setSubeIstisnalariForTests(null);
echo "verify-effective-permission-resolver-istisna: OK\n";
