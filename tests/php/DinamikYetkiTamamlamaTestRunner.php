<?php

declare(strict_types=1);

/**
 * Personel yazma kapısı ve hassas başlangıç kilidi (DB'siz).
 * php tests/php/DinamikYetkiTamamlamaTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\EffectivePermissionResolver as R;
use Medisa\Api\Controllers\PersonellerController;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\ResponseCaptured;
use Medisa\Api\Services\Auth\KullaniciYetkiYazmaService as Y;

function dyAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** @param array<string, mixed> $user */
function dyGate(array $user, string $permission): string
{
    $method = new ReflectionMethod(PersonellerController::class, 'assertWriteRole');
    $method->setAccessible(true);
    JsonResponse::beginCapture();
    try {
        $method->invoke(null, $user, $permission);
        $captured = JsonResponse::capturedResponse();
        JsonResponse::endCapture();
        if ($captured !== null) {
            return (string) ($captured['errors'][0]['code'] ?? 'CAPTURED');
        }

        return 'ALLOW';
    } catch (ResponseCaptured $e) {
        $captured = JsonResponse::capturedResponse();
        JsonResponse::endCapture();

        return (string) ($captured['errors'][0]['code'] ?? 'FORBIDDEN');
    } catch (Throwable $e) {
        JsonResponse::endCapture();
        throw $e;
    }
}

/** @return array<string, mixed> */
function dyDeny(string $permission, string $from = '2026-01-01 00:00:00', ?string $until = null): array
{
    return [
        'permission' => $permission,
        'etki' => 'DENY',
        'sube_id' => null,
        'gecerlilik_baslangic' => $from,
        'gecerlilik_bitis' => $until,
    ];
}

R::setNowForTests('2026-10-11 12:00:00');

$bolum = ['id' => 3, 'rol' => 'BOLUM_YONETICISI'];
$muhasebe = ['id' => 4, 'rol' => 'MUHASEBE'];
$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI'];
$ik = ['id' => 8, 'rol' => 'IK_SORUMLUSU'];
$birim = ['id' => 9, 'rol' => 'BIRIM_AMIRI'];

foreach (['personeller.create', 'personeller.update'] as $permission) {
    dyAssert(dyGate($bolum, $permission) === 'ALLOW', 'Bölüm Yöneticisi legacy/rol erişimi durur: ' . $permission);
    dyAssert(dyGate($muhasebe, $permission) === 'ALLOW', 'Muhasebe legacy erişimi durur, matrise izin eklenmez: ' . $permission);
    dyAssert(
        dyGate($bolum + ['yetki_istisnalari' => [dyDeny($permission)]], $permission) === 'FORBIDDEN',
        'Bölüm Yöneticisi geçerli DENY yazmayı kapatır: ' . $permission
    );
    dyAssert(
        dyGate($muhasebe + ['yetki_istisnalari' => [dyDeny($permission)]], $permission) === 'FORBIDDEN',
        'Muhasebe geçerli DENY legacy köprüyü kapatır: ' . $permission
    );
    dyAssert(
        dyGate($bolum + ['yetki_istisnalari' => [dyDeny($permission, '2026-01-01 00:00:00', '2026-10-01 00:00:00')]], $permission) === 'ALLOW',
        'Süresi dolmuş DENY yazmayı geri açar: ' . $permission
    );
    dyAssert(
        dyGate($muhasebe + ['yetki_istisnalari' => [dyDeny($permission, '2027-01-01 00:00:00')]], $permission) === 'ALLOW',
        'Henüz başlamamış DENY legacy erişimi kapatmaz: ' . $permission
    );
    dyAssert(dyGate($bolum, $permission) === 'ALLOW', 'İptal edilmiş kayıt listede yoksa erişim durur: ' . $permission);
}

dyAssert(dyGate($gy, 'personeller.update') === 'ALLOW', 'Genel Yönetici etkin izinle yazar');
dyAssert(
    dyGate($gy + ['yetki_istisnalari' => [dyDeny('personeller.update')]], 'personeller.update') === 'FORBIDDEN',
    'Genel Yönetici DENY legacy listesinde değildir'
);
dyAssert(dyGate($ik, 'personeller.update') === 'ALLOW', 'İK rol izniyle yazar');
dyAssert(
    dyGate($ik + ['yetki_istisnalari' => [dyDeny('personeller.update')]], 'personeller.update') === 'FORBIDDEN',
    'İK kişisel DENY yazmayı kapatır'
);
dyAssert(dyGate($birim, 'personeller.update') === 'FORBIDDEN', 'Köprüde olmayan ve izni olmayan rol yazamaz');
dyAssert(
    !R::hasActiveDeny($muhasebe, 'personeller.update') && R::hasActiveDeny($muhasebe + ['yetki_istisnalari' => [dyDeny('personeller.update')]], 'personeller.update'),
    'İzin yokluğu ile açık DENY ayrıdır'
);

$baslangic = ['kaynak' => Y::KAYNAK_BASLANGIC, 'etki' => 'DENY'];
dyAssert(
    Y::kilitKaydiMi('sgk_karar_paketi.approve', 'DENY', $baslangic),
    'Hassas başlangıç DENY kilitlidir'
);
dyAssert(
    !Y::kilitKaydiMi('sgk_karar_paketi.approve', 'DENY', ['etki' => 'DENY', 'kapsam' => 'GLOBAL']),
    'Sonradan konan hassas DENY kilitli değildir'
);
dyAssert(
    !Y::kilitKaydiMi('personeller.update', 'DENY', $baslangic),
    'Operasyonel başlangıç DENY hassas kilit sayılmaz'
);
dyAssert(!Y::kilitKaydiMi('sgk_karar_paketi.approve', 'ALLOW', $baslangic), 'ALLOW kayıt kilitlenmez');

R::setNowForTests(null);

echo 'verify-dinamik-yetki-tamamlama: OK' . PHP_EOL;
