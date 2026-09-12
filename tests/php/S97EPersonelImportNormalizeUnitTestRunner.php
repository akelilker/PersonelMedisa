<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelImportReferenceCatalogService;

function nAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$norm = [
    'İnsan Kaynakları Sorumlusu',
    'insan kaynakları sorumlusu',
    'INSAN KAYNAKLARI SORUMLUSU',
    'insan kaynaklari sorumlusu',
    '  İnsan   Kaynakları Sorumlusu  ',
];
$keys = array_map([PersonelImportReferenceCatalogService::class, 'normalizeMatchKey'], $norm);
nAssert(count(array_unique($keys)) === 1, 'PASS variants share one normalize key');

$index = [
    'İnsan Kaynakları Sorumlusu' => [42],
    'Medisa Ankara' => [1],
    'Karyapı Ankara' => [2],
    'Ankara' => [1, 2],
    'Şube Merkez' => [7],
    'Sube Merkez' => [8],
];

$h = [];
$auto = [];
$amb = [];
$id = PersonelImportReferenceCatalogService::resolveExactUnique('insan kaynaklari sorumlusu', $index, 'gorev', $h, $auto, $amb);
nAssert($id === 42, 'normalized unique match id');
nAssert(count($h) === 0, 'safe auto-match no hard error');
nAssert(count($auto) === 1 && $auto[0]['canonical'] === 'İnsan Kaynakları Sorumlusu', 'auto-match note');

$h = [];
$auto = [];
$amb = [];
$id = PersonelImportReferenceCatalogService::resolveExactUnique('Ankara', $index, 'sube', $h, $auto, $amb);
nAssert($id === null, 'exact multi-id Ankara null');
nAssert(in_array('PERSONEL_IMPORT_REFERANS_BELIRSIZ', $h, true), 'exact multi-id ambiguous code');
nAssert(count($amb) === 1 && count($amb[0]['candidates']) >= 2, 'exact multi-id candidates');

$h = [];
$auto = [];
$amb = [];
$id = PersonelImportReferenceCatalogService::resolveExactUnique('sube merkez', $index, 'sube', $h, $auto, $amb);
nAssert($id === null, 'normalized fold ambiguous null');
nAssert(in_array('PERSONEL_IMPORT_REFERANS_BELIRSIZ', $h, true), 'normalized fold ambiguous code');

$h = [];
$auto = [];
$amb = [];
$id = PersonelImportReferenceCatalogService::resolveExactUnique('Uzmxn', $index, 'gorev', $h, $auto, $amb);
nAssert($id === null, 'missing null');
nAssert(in_array('PERSONEL_IMPORT_REFERANS_BULUNAMADI', $h, true), 'missing code');

$h = [];
$auto = [];
$amb = [];
$id = PersonelImportReferenceCatalogService::resolveExactUnique('İnsan Kaynakları Sorumlusu', $index, 'gorev', $h, $auto, $amb);
nAssert($id === 42 && count($auto) === 0, 'exact match no auto note');

$byParent = [
    10 => ['Finans' => [100]],
    11 => ['Finans' => [101]],
];
$h = [];
$auto = [];
$amb = [];
$id = PersonelImportReferenceCatalogService::resolveExactUniqueWithinParent('finans', $byParent, null, 'bolum', $h, $auto, $amb);
nAssert($id === null && in_array('PERSONEL_IMPORT_REFERANS_BULUNAMADI', $h, true), 'null parent fail-closed');

$h = [];
$auto = [];
$amb = [];
$id = PersonelImportReferenceCatalogService::resolveExactUniqueWithinParent('finans', $byParent, 10, 'bolum', $h, $auto, $amb);
nAssert($id === 100, 'parent-scoped normalized unique');

echo "ALL_OK\n";
