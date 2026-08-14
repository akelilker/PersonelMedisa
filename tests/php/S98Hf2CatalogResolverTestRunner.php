<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkPrimGunuEngine.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkManuelKodOverrideService.php';
require_once __DIR__ . '/../../api/src/Services/SgkPrimGunuService.php';

use Medisa\Api\Services\SgkPrimGunuService;

function hf2Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$parent = [
    'id' => 1,
    'surum_kodu' => 'SGK-EKSIK-GUN-RESMI-2026-07',
    'gecerlilik_baslangic' => '2018-03-01',
];
$successor = [
    'id' => 2,
    'surum_kodu' => 'SGK-EKSIK-GUN-RESMI-2026-07-ESLEME-S98-R1',
    'gecerlilik_baslangic' => '2018-03-01',
    'aciklama' => 'S98 successor esleme import parent=SGK-EKSIK-GUN-RESMI-2026-07 esleme=abc',
];
$unrelated = [
    'id' => 3,
    'surum_kodu' => 'OTHER-CATALOG-2026',
    'gecerlilik_baslangic' => '2018-03-01',
];

hf2Assert(SgkPrimGunuService::selectEffectiveCatalogVersion([]) === null, 'empty → null');
$single = SgkPrimGunuService::selectEffectiveCatalogVersion([$successor]);
hf2Assert(is_array($single) && (int) $single['id'] === 2, 'single successor');
$lineage = SgkPrimGunuService::selectEffectiveCatalogVersion([$parent, $successor]);
hf2Assert(is_array($lineage) && (int) $lineage['id'] === 2, 'parent+successor → successor');
hf2Assert(
    SgkPrimGunuService::isApprovedCatalogAncestor($parent, $successor) === true,
    'ancestor by -ESLEME- kodu'
);
hf2Assert(
    SgkPrimGunuService::selectEffectiveCatalogVersion([$parent, $unrelated]) === null,
    'unrelated overlap → fail-closed'
);
hf2Assert(
    SgkPrimGunuService::isApprovedCatalogAncestor($unrelated, $successor) === false,
    'unrelated is not ancestor'
);

echo "HF2 catalog resolver unit OK\n";
