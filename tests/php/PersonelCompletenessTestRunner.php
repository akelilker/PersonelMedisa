<?php

declare(strict_types=1);

/**
 * Focused completeness policy runner — no DB mutation.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelCompletenessService;

function completenessAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function baseCompleteIc(): array
{
    return [
        'calisan_kapsami' => 'IC_PERSONEL',
        'tc_kimlik_no' => '12345678901',
        'sicil_no' => 'P-001',
        'dogum_tarihi' => '1990-01-01',
        'telefon' => '05551234567',
        'ise_giris_tarihi' => '2020-01-01',
        'sube_id' => 1,
        'calisma_lokasyonu_id' => 2,
        'bagli_amir_id' => 3,
        'departman_id' => 4,
        'bolum_id' => 5,
        'birim_id' => 6,
        'gorev_id' => 7,
        'pozisyon_id' => 8,
        'personel_tipi_id' => 9,
    ];
}

$complete = PersonelCompletenessService::evaluate(baseCompleteIc(), true);
completenessAssert($complete['is_complete'] === true, 'complete personel is_complete');
completenessAssert($complete['missing_count'] === 0, 'complete personel missing_count 0');
completenessAssert($complete['missing_fields'] === [], 'complete personel missing_fields empty');

$oneNull = PersonelCompletenessService::evaluate(array_merge(baseCompleteIc(), ['telefon' => null]), true);
completenessAssert($oneNull['is_complete'] === false, 'one required NULL → missing');
completenessAssert($oneNull['missing_count'] === 1, 'one required NULL → count 1');
completenessAssert($oneNull['missing_fields'][0]['key'] === 'telefon', 'one required NULL → telefon key');

$emptyString = PersonelCompletenessService::evaluate(array_merge(baseCompleteIc(), ['sicil_no' => '']), true);
completenessAssert($emptyString['missing_count'] === 1, 'empty string → missing');
completenessAssert($emptyString['missing_fields'][0]['key'] === 'sicil_no', 'empty string → sicil_no');

$whitespace = PersonelCompletenessService::evaluate(array_merge(baseCompleteIc(), ['tc_kimlik_no' => '   ']), true);
completenessAssert($whitespace['missing_count'] === 1, 'whitespace-only → missing');
completenessAssert($whitespace['missing_fields'][0]['key'] === 'tc_kimlik_no', 'whitespace-only → tc');

$optionalNull = PersonelCompletenessService::evaluate(
    array_merge(baseCompleteIc(), ['acil_durum_kisi' => null, 'dogum_yeri' => null]),
    true
);
completenessAssert($optionalNull['is_complete'] === true, 'optional NULL → NOT missing');

$conditionalMissing = PersonelCompletenessService::evaluate(
    array_merge(baseCompleteIc(), ['tc_kimlik_no' => null]),
    true
);
completenessAssert($conditionalMissing['missing_count'] === 1, 'IC conditional required → missing');

$disComplete = PersonelCompletenessService::evaluate(
    array_merge(baseCompleteIc(), [
        'calisan_kapsami' => 'DIS_KAYNAK',
        'tc_kimlik_no' => null,
        'dogum_tarihi' => null,
        'telefon' => null,
    ]),
    true
);
completenessAssert($disComplete['is_complete'] === true, 'DIS_KAYNAK conditional not applicable → NOT missing');

$disOrgOptional = PersonelCompletenessService::evaluate(
    [
        'calisan_kapsami' => 'DIS_KAYNAK',
        'sicil_no' => 'D-1',
        'ise_giris_tarihi' => '2026-01-01',
        'bolum_id' => null,
        'birim_id' => null,
        'departman_id' => null,
        'gorev_id' => null,
        'sube_id' => null,
        'calisma_lokasyonu_id' => null,
        'bagli_amir_id' => null,
        'pozisyon_id' => null,
        'personel_tipi_id' => null,
    ],
    true
);
completenessAssert($disOrgOptional['is_complete'] === false, 'DIS_KAYNAK null org → CRITICAL missing');
completenessAssert(
    $disOrgOptional['critical_missing_labels'] === [
        'Şube', 'Çalışma Lokasyonu', 'Yönetici', 'Departman', 'Bölüm', 'Birim', 'Unvan / Görev', 'Pozisyon',
    ],
    'DIS_KAYNAK requires all non-SGK organizational fields'
);

$disCompleteWithNullSgk = PersonelCompletenessService::evaluate(
    array_merge(baseCompleteIc(), [
        'calisan_kapsami' => 'DIS_KAYNAK',
        'tc_kimlik_no' => null,
        'dogum_tarihi' => null,
        'telefon' => null,
        'sgk_isveren_id' => null,
    ]),
    true
);
completenessAssert($disCompleteWithNullSgk['is_complete'] === true, 'DIS_KAYNAK NULL SGK remains complete');

$multi = PersonelCompletenessService::evaluate(
    array_merge(baseCompleteIc(), [
        'telefon' => '',
        'bolum_id' => null,
        'birim_id' => 0,
    ]),
    true
);
completenessAssert($multi['missing_count'] === 3, 'multiple fields exact count');
completenessAssert(
    $multi['missing_fields'][0]['label'] === 'Telefon'
        && $multi['missing_fields'][0]['category'] === 'ILETISIM'
        && $multi['missing_fields'][0]['severity'] === 'CRITICAL',
    'field labels/categories/severity correct'
);

$listLight = PersonelCompletenessService::evaluate(array_merge(baseCompleteIc(), ['telefon' => null]), false);
completenessAssert(!array_key_exists('missing_fields', $listLight), 'list summary omits missing_fields');
completenessAssert($listLight['missing_count'] === 1, 'list summary missing_count');
completenessAssert($listLight['critical_missing_labels'] === ['Telefon'], 'list summary labels');

$predicate = PersonelCompletenessService::sqlHasMissingPredicate('p');
completenessAssert(strpos($predicate, 'sicil_no') !== false, 'sql predicate includes sicil_no');
completenessAssert(strpos($predicate, 'DIS_KAYNAK') !== false, 'sql predicate scopes IC-only fields');

$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/PersonellerController.php');
completenessAssert(
    strpos($controllerSource, 'PersonelCompletenessService') !== false,
    'PersonellerController wires completeness owner'
);
completenessAssert(
    strpos($controllerSource, 'eksik_bilgi') !== false,
    'PersonellerController exposes eksik_bilgi filter'
);
completenessAssert(
    strpos($controllerSource, 'missing_personel_total') !== false,
    'PersonellerController exposes missing_personel_total meta'
);
completenessAssert(
    strpos($controllerSource, 'mapPersonelRow($row, $user, false)') !== false,
    'list mapping uses light completeness (no N+1 field dump)'
);

echo 'verify-personel-completeness: OK' . PHP_EOL;
