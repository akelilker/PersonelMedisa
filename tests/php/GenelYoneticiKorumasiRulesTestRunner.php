<?php

declare(strict_types=1);

/**
 * GenelYoneticiKorumasi kural matrisi (DB'siz; Fast CI).
 * MariaDB senaryoları ve eşzamanlılık: GenelYoneticiKorumasiMysqlTestRunner.php
 * php tests/php/GenelYoneticiKorumasiRulesTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Auth\GenelYoneticiKorumasi;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;

function gykrCase(string $name, callable $fn, ?string $expectedCode, ?int $expectedStatus = null): void
{
    $code = null;
    $status = null;
    try {
        $fn();
    } catch (OrganizasyonException $e) {
        $code = $e->errorCode;
        $status = $e->httpStatus;
    }
    $ok = $code === $expectedCode && ($expectedStatus === null || $status === $expectedStatus);
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name . ' (kod=' . var_export($code, true) . ')');
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI'];
$sy = ['id' => 3, 'rol' => 'SISTEM_YONETICISI'];
$ik = ['id' => 7, 'rol' => 'IK_SORUMLUSU'];
$k = GenelYoneticiKorumasi::class;

gykrCase('1 SY yeni GY olusturamaz', static function () use ($k, $sy) {
    $k::assertActorMayAssign($sy, null, null, null, 'GENEL_YONETICI', 'AKTIF');
}, 'ROLE_ESCALATION_FORBIDDEN', 403);
gykrCase('2 IK yeni GY olusturamaz', static function () use ($k, $ik) {
    $k::assertActorMayAssign($ik, null, null, null, 'genel_yonetici', 'AKTIF');
}, 'ROLE_ESCALATION_FORBIDDEN', 403);
gykrCase('3 GY yeni GY olusturabilir', static function () use ($k, $gy) {
    $k::assertActorMayAssign($gy, null, null, null, 'GENEL_YONETICI', 'AKTIF');
}, null);
gykrCase('4 SY baskasini GY yapamaz', static function () use ($k, $sy) {
    $k::assertActorMayAssign($sy, 4, 'MUHASEBE', 'AKTIF', 'GENEL_YONETICI', 'AKTIF');
}, 'ROLE_ESCALATION_FORBIDDEN', 403);
gykrCase('5 SY GY rolunu geri alamaz', static function () use ($k, $sy) {
    $k::assertActorMayAssign($sy, 2, 'GENEL_YONETICI', 'AKTIF', 'MUHASEBE', 'AKTIF');
}, 'ROLE_ESCALATION_FORBIDDEN', 403);
gykrCase('6 SY GY hesabini pasife alamaz', static function () use ($k, $sy) {
    $k::assertActorMayAssign($sy, 2, 'GENEL_YONETICI', 'AKTIF', 'GENEL_YONETICI', 'PASIF');
}, 'ROLE_ESCALATION_FORBIDDEN', 403);
gykrCase('7 SY GY hesabinin rol/durum disi alanina dokunabilir (yetki yukseltme yok)', static function () use ($k, $sy) {
    $k::assertActorMayAssign($sy, 2, 'GENEL_YONETICI', 'AKTIF', 'GENEL_YONETICI', 'AKTIF');
}, null);
gykrCase('8 SY GY olmayan rolleri atayabilir', static function () use ($k, $sy) {
    $k::assertActorMayAssign($sy, 4, 'MUHASEBE', 'AKTIF', 'IK_SORUMLUSU', 'PASIF');
}, null);
gykrCase('9 kendi rolunu degistiremez (SY → GY)', static function () use ($k, $sy) {
    $k::assertActorMayAssign($sy, 3, 'SISTEM_YONETICISI', 'AKTIF', 'GENEL_YONETICI', 'AKTIF');
}, 'SELF_ROLE_CHANGE_FORBIDDEN', 409);
gykrCase('10 GY kendi rolunu dusuremez', static function () use ($k, $gy) {
    $k::assertActorMayAssign($gy, 1, 'GENEL_YONETICI', 'AKTIF', 'MUHASEBE', 'AKTIF');
}, 'SELF_ROLE_CHANGE_FORBIDDEN', 409);
gykrCase('11 kendini pasife alamaz', static function () use ($k, $gy) {
    $k::assertActorMayAssign($gy, 1, 'GENEL_YONETICI', 'AKTIF', 'GENEL_YONETICI', 'PASIF');
}, 'SELF_STATUS_CHANGE_FORBIDDEN', 409);
gykrCase('12 kendi rol/durumu ayni kalirsa duzenleme serbest', static function () use ($k, $gy) {
    $k::assertActorMayAssign($gy, 1, 'GENEL_YONETICI', 'AKTIF', 'genel_yonetici', 'aktif');
}, null);
gykrCase('13 SY GY erisimini kaldiramaz', static function () use ($k, $sy) {
    $k::assertActorMayRevoke($sy, 'GENEL_YONETICI');
}, 'ROLE_ESCALATION_FORBIDDEN', 403);
gykrCase('14 SY GY olmayan erisimi kaldirabilir', static function () use ($k, $sy) {
    $k::assertActorMayRevoke($sy, 'MUHASEBE');
}, null);
gykrCase('15 GY GY erisimini kaldirabilir (son-yonetici kontrolu ayrica)', static function () use ($k, $gy) {
    $k::assertActorMayRevoke($gy, 'GENEL_YONETICI');
}, null);

$sqlite = new PDO('sqlite::memory:');
$threw = false;
try {
    GenelYoneticiKorumasi::assertNotLastActiveAdminLocked($sqlite, 1, 'MUHASEBE', 'AKTIF');
} catch (LogicException $e) {
    $threw = true;
}
if (!$threw) {
    throw new RuntimeException('[FAIL] 16 son yonetici kontrolu transaction disinda calismaz');
}
echo '[PASS] 16 son yonetici kontrolu transaction disinda calismaz' . PHP_EOL;

echo "verify-genel-yonetici-korumasi-rules: OK\n";
