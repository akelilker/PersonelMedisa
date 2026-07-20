<?php

declare(strict_types=1);

/**
 * S83 readiness projection + action-link + import classification acceptance runner.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\BordroHazirlikPreflightService;
use Medisa\Api\Services\PersonelBordroDevirService;

function s83Assert(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$name}\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] {$name}\n");
}

s83Assert(
    BordroHazirlikPreflightService::CONTRACT_VERSION === 'S83_BORDRO_BUSINESS_DATA_READINESS_V1',
    'contract version S83'
);

$classified = BordroHazirlikPreflightService::classifyNetMaasDurumu([
    'maas_tutari' => null,
    'ucret_kayit_sayisi' => 0,
]);
s83Assert($classified['net_maas_durumu'] === 'NULL', 'net maas NULL class');

$legacyOnly = BordroHazirlikPreflightService::classifyNetMaasDurumu([
    'maas_tutari' => '15000.00',
    'ucret_kayit_sayisi' => 0,
]);
s83Assert($legacyOnly['net_maas_durumu'] === 'LEGACY_ONLY', 'legacy only class');

$ok = BordroHazirlikPreflightService::classifyNetMaasDurumu([
    'maas_tutari' => '15000.00',
    'ucret_kayit_sayisi' => 1,
]);
s83Assert($ok['net_maas_durumu'] === null, 'history present not eksik');

s83Assert(PersonelBordroDevirService::canonicalizeSicil(' p-001 ') === 'P-001', 'sicil canonicalize');

$ref = new ReflectionClass(BordroHazirlikPreflightService::class);
$linkMethod = $ref->getMethod('actionLinkForCode');
$linkMethod->setAccessible(true);
$salaryLink = $linkMethod->invoke(null, 'SALARY_MISSING', ['personel_id' => 42]);
s83Assert(strpos($salaryLink, '/personeller/42') === 0, 'salary action link personel');
s83Assert(strpos($salaryLink, 'tab=ucret') === false, 'salary link no fake ucret tab');
$etkiLink = $linkMethod->invoke(null, 'UNRESOLVED_IMPACT_CANDIDATE', []);
s83Assert($etkiLink === '/raporlar?panel=etki-adayi', 'etki aday action link');
$s81Link = $linkMethod->invoke(null, 'S81_GENEL_YONETICI_FINAL_ONAY_EKSIK', []);
s83Assert($s81Link === '/bildirimler', 's81 action link');

fwrite(STDOUT, "S83 PHP runner OK\n");
