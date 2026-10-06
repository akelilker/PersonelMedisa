<?php

declare(strict_types=1);

/**
 * Focused sicil allocation policy runner — no DB access, no mutation.
 * php tests/php/PersonelAutoSicilTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelSicilAllocator;

function autoSicilPureAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** @return array<string, mixed> */
function autoSicilPureBaseBody(): array
{
    return [
        'tc_kimlik_no' => '19000000001',
        'ad' => 'Zeynep',
        'soyad' => 'Ornek',
        'dogum_tarihi' => '2002-01-01',
        'telefon' => '05550000001',
        'ise_giris_tarihi' => '2026-08-12',
        'sube_id' => 1,
        'departman_id' => 1,
        'gorev_id' => 1,
        'personel_tipi_id' => 1,
        'sgk_isveren_id' => 1,
        'aktif_durum' => 'AKTIF',
    ];
}

autoSicilPureAssert(PersonelSicilAllocator::format(1) === '001', 'format 1 → 001');
autoSicilPureAssert(PersonelSicilAllocator::format(40) === '040', 'format 40 → 040');
autoSicilPureAssert(PersonelSicilAllocator::format(382) === '382', 'format 382 → 382');
autoSicilPureAssert(PersonelSicilAllocator::format(1000) === '1000', 'format 1000 → 1000');

autoSicilPureAssert(PersonelSicilAllocator::numericValue('007') === 7, 'numericValue leading-zero');
autoSicilPureAssert(PersonelSicilAllocator::numericValue('MED-001') === null, 'numericValue legacy → null');
autoSicilPureAssert(PersonelSicilAllocator::numericValue('  ') === null, 'numericValue blank → null');

autoSicilPureAssert(PersonelSicilAllocator::floorFromExisting([]) === 1, 'bos seri → 1');
autoSicilPureAssert(
    PersonelSicilAllocator::floorFromExisting(['001', '002', 'MED-LEGACY']) === 3,
    'non-numeric siciller sayaca girmiyor'
);
autoSicilPureAssert(
    PersonelSicilAllocator::floorFromExisting(['001', '382']) === 383,
    'yuksek numeric sicil zemini tasiyor'
);

autoSicilPureAssert(PersonelSicilAllocator::isAutoRequest(null) === true, 'null → AUTO');
autoSicilPureAssert(PersonelSicilAllocator::isAutoRequest('   ') === true, 'bosluk → AUTO');
autoSicilPureAssert(PersonelSicilAllocator::isAutoRequest('040') === false, 'explicit sicil AUTO degil');

$autoPayload = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(autoSicilPureBaseBody());
autoSicilPureAssert($autoPayload['sicil_no'] === null, 'create: sicilsiz payload AUTO demek');

$blankPayload = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(
    array_merge(autoSicilPureBaseBody(), ['sicil_no' => '  '])
);
autoSicilPureAssert($blankPayload['sicil_no'] === null, 'create: bos sicil AUTO demek');

$explicitPayload = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(
    array_merge(autoSicilPureBaseBody(), ['sicil_no' => '  040  '])
);
autoSicilPureAssert($explicitPayload['sicil_no'] === '040', 'create: explicit sicil trim edilerek korunuyor');

$importMissing = PersonelCanonicalValidator::validateImportAnaVeriRow(array_merge(
    autoSicilPureBaseBody(),
    ['aktif_durum' => null]
));
$missingFields = array_column($importMissing['errors'], 'field');
autoSicilPureAssert($importMissing['payload'] === null, 'import: sicilsiz satir payload uretmiyor');
autoSicilPureAssert(in_array('sicil_no', $missingFields, true), 'import: sicil zorunlu kaliyor');
autoSicilPureAssert(
    in_array('PERSONEL_IMPORT_EKSIK_ALAN', array_column($importMissing['errors'], 'code'), true),
    'import: mevcut eksik alan hata kodu korunuyor'
);

$importWithSicil = PersonelCanonicalValidator::validateImportAnaVeriRow(array_merge(
    autoSicilPureBaseBody(),
    ['sicil_no' => 'MED-042']
));
autoSicilPureAssert($importWithSicil['errors'] === [], 'import: sicilli satir gecerli');
autoSicilPureAssert(
    is_array($importWithSicil['payload']) && $importWithSicil['payload']['sicil_no'] === 'MED-042',
    'import: mevcut sicil tasinmaya devam ediyor'
);

echo 'verify-personel-auto-sicil: OK' . PHP_EOL;
