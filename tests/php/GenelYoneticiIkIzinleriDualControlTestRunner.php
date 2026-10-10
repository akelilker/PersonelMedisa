<?php

declare(strict_types=1);

/**
 * GENEL_YONETICI'ye eklenen IK operasyon izinleri (talep + onay ayni rolde) dort goz
 * kuralini gevsetmez: GY kendi talebini onaylayamaz; onay farkli aktor ister. (DB'siz)
 * php tests/php/GenelYoneticiIkIzinleriDualControlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\DualControl;
use Medisa\Api\Auth\RolePermissions;

function gyikAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$gyA = ['id' => 21, 'rol' => 'GENEL_YONETICI'];
$gyB = ['id' => 22, 'rol' => 'GENEL_YONETICI'];

foreach ([
    'puantaj.donem_reopen.request',
    'puantaj.donem_reopen.approve',
    'puantaj.donem_reseal',
    'puantaj.bildirim_etki.generate',
    'puantaj.bildirim_etki.apply',
    'puantaj.bildirim_etki.dismiss',
    'puantaj.bildirim_etki.resolve_conflict',
] as $permission) {
    gyikAssert(RolePermissions::has($gyA, $permission), '1 GENEL_YONETICI ' . $permission);
    gyikAssert(
        RolePermissions::has(['id' => 30, 'rol' => 'IK_SORUMLUSU'], $permission) || $permission === 'puantaj.donem_reopen.approve',
        '2 IK_SORUMLUSU izni korunur: ' . $permission
    );
}

$self = DualControl::violation($gyA, 21);
gyikAssert(($self['code'] ?? null) === 'SELF_APPROVAL_FORBIDDEN', '3 GY kendi talebini onaylayamaz (SELF_APPROVAL_FORBIDDEN)');
gyikAssert(DualControl::isSeparated($gyA, 21) === false, '4 GY icin ayni aktor ayrismis sayilmaz');
$unknown = DualControl::violation($gyA, null);
gyikAssert(($unknown['code'] ?? null) === 'DUAL_CONTROL_ENTERED_BY_REQUIRED', '5 talep sahibi bilinmeden onay yok (fail-closed)');
gyikAssert(DualControl::violation($gyB, 21) === null, '6 farkli GY aktor onaylayabilir (dort goz)');
gyikAssert(DualControl::violation(['id' => 30, 'rol' => 'IK_SORUMLUSU'], 21) === null, '7 GY talebini farkli rol/aktor de onaylayabilir; ayni kisi kurali role bagli degil');

echo "verify-genel-yonetici-ik-izinleri-dual-control: OK\n";
