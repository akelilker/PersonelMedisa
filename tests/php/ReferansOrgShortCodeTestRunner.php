<?php

declare(strict_types=1);

/**
 * Focused contract checks for bolum/birim kisa_kod on ReferansController + migration 072.
 */

require_once __DIR__ . '/../../api/src/Http/JsonResponse.php';
require_once __DIR__ . '/../../api/src/Auth/RolePermissions.php';
require_once __DIR__ . '/../../api/src/Auth/EffectivePermissionResolver.php';
require_once __DIR__ . '/../../api/src/Services/Personel/PersonelOrgStructureSchema.php';
require_once __DIR__ . '/../../api/src/Controllers/ReferansController.php';

use Medisa\Api\Controllers\ReferansController;

function kisaAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/ReferansController.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/072_org_reference_short_codes.sql');
$schemaSource = (string) file_get_contents(__DIR__ . '/../../api/src/Services/Personel/PersonelOrgStructureSchema.php');
$personelSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/PersonellerController.php');

kisaAssert(
    strpos($migrationSource, 'ADD COLUMN kisa_kod VARCHAR(16) NULL') !== false,
    'migration adds nullable VARCHAR(16) kisa_kod'
);
kisaAssert(
    strpos($migrationSource, 'ALTER TABLE bolumler ADD COLUMN kisa_kod') !== false
        && strpos($migrationSource, 'ALTER TABLE birimler ADD COLUMN kisa_kod') !== false,
    'migration targets bolumler and birimler'
);
kisaAssert(
    strpos($migrationSource, "id = 13 AND ad = 'Mali Ve İdari İşler'") !== false
        && strpos($migrationSource, "kisa_kod = 'Mİİ'") !== false,
    'migration fail-closed maps Mali Ve İdari İşler → Mİİ'
);
kisaAssert(
    preg_match('/\bUNIQUE\s+(KEY|INDEX)\b/i', $migrationSource) !== 1
        && stripos($migrationSource, 'ADD UNIQUE') === false,
    'migration does not add unique constraint on kisa_kod'
);

kisaAssert(
    strpos($schemaSource, 'function hasKisaKodColumns') !== false,
    'PersonelOrgStructureSchema exposes hasKisaKodColumns'
);
kisaAssert(
    strpos($controllerSource, 'normalizeKisaKodOrThrow') !== false,
    'ReferansController normalizes kisa_kod'
);
kisaAssert(
    strpos($controllerSource, "'kisa_kod' =>") !== false,
    'list/create responses include kisa_kod'
);
kisaAssert(
    strpos($controllerSource, 'KISA_KOD_TYPE') !== false
        && strpos($controllerSource, 'KISA_KOD_TOO_LONG') !== false,
    'create validates kisa_kod type and length'
);
kisaAssert(
    strpos($controllerSource, 'ad, kisa_kod, durum') !== false,
    'create inserts kisa_kod when schema ready'
);
kisaAssert(
    strpos($personelSource, 'bolum_kisa_kod') !== false
        && strpos($personelSource, 'birim_kisa_kod') !== false,
    'personel select exposes bolum/birim kisa_kod'
);

$ref = new ReflectionClass(ReferansController::class);
kisaAssert($ref->hasMethod('normalizeKisaKodOrThrow'), 'normalizeKisaKodOrThrow method exists');
$method = $ref->getMethod('normalizeKisaKodOrThrow');
$method->setAccessible(true);

kisaAssert($method->invoke(null, null) === null, 'null kisa_kod stays null');
kisaAssert($method->invoke(null, '') === null, 'empty string normalizes to null');
kisaAssert($method->invoke(null, "  \t  ") === null, 'whitespace normalizes to null');
kisaAssert($method->invoke(null, '  Mİİ  ') === 'Mİİ', 'trims kisa_kod and keeps Turkish chars');

try {
    $method->invoke(null, 13);
    throw new RuntimeException('[FAIL] non-string kisa_kod should throw');
} catch (InvalidArgumentException $e) {
    kisaAssert($e->getMessage() === 'KISA_KOD_TYPE', 'non-string kisa_kod fail-closed');
}

try {
    $method->invoke(null, str_repeat('A', 17));
    throw new RuntimeException('[FAIL] overlong kisa_kod should throw');
} catch (InvalidArgumentException $e) {
    kisaAssert($e->getMessage() === 'KISA_KOD_TOO_LONG', 'overlong kisa_kod fail-closed');
}

echo "ALL_KISA_KOD_CONTRACT_CHECKS_PASSED\n";
