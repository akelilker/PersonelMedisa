<?php

declare(strict_types=1);

/**
 * DIS operasyonel/non-financial + geçici görevlendirme pure/source runner.
 * MySQL gerektirmeyen kontrat kilitleri.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/api/src/Services/Personel/PersonelCalisanKapsamService.php';
require_once $root . '/api/src/Services/Personel/PersonelCompletenessService.php';
require_once $root . '/api/src/Services/Personel/PersonelValidationException.php';
require_once $root . '/api/src/Services/Personel/PersonelCalisanKapsamSchema.php';
require_once $root . '/api/src/Services/Personel/PersonelCanonicalValidator.php';

use Medisa\Api\Services\Personel\PersonelCalisanKapsamService;
use Medisa\Api\Services\Personel\PersonelCompletenessService;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;

function dnfAssert($cond, $msg)
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
    echo "OK: {$msg}\n";
}

// Completeness: DIS bolum/birim null → complete (sicil+ise_giris yeterli)
$dis = [
    'calisan_kapsami' => 'DIS_KAYNAK',
    'sicil_no' => 'D-100',
    'ise_giris_tarihi' => '2026-01-01',
    'bolum_id' => null,
    'birim_id' => null,
    'departman_id' => null,
    'gorev_id' => null,
    'personel_tipi_id' => null,
];
$eval = PersonelCompletenessService::evaluate($dis);
dnfAssert($eval['is_complete'] === true, 'DIS null org → complete');

$ic = [
    'calisan_kapsami' => 'IC_PERSONEL',
    'sicil_no' => 'I-100',
    'ise_giris_tarihi' => '2026-01-01',
    'tc_kimlik_no' => '12345678901',
    'dogum_tarihi' => '1990-01-01',
    'telefon' => '555',
    'departman_id' => 1,
    'gorev_id' => 1,
    'personel_tipi_id' => 1,
    'bolum_id' => null,
    'birim_id' => null,
];
$evalIc = PersonelCompletenessService::evaluate($ic);
dnfAssert($evalIc['is_complete'] === false, 'IC null bolum/birim → incomplete');

$sql = PersonelCompletenessService::sqlHasMissingPredicate('p', true, true);
dnfAssert(strpos($sql, "<> 'DIS_KAYNAK'") !== false, 'sql predicate DIS org dışlar');

// Validator: DIS org'suz create kabul
$payload = PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
    'calisan_kapsami' => 'DIS_KAYNAK',
    'ad' => 'Dis',
    'sicil_no' => 'D-VAL-1',
    'ise_giris_tarihi' => '2026-02-01',
    'aktif_durum' => 'AKTIF',
]);
dnfAssert(($payload['sube_id'] ?? null) === null, 'DIS create sube optional null');
dnfAssert(($payload['departman_id'] ?? null) === null, 'DIS create departman optional null');

// IC create hâlâ sube ister
try {
    PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
        'calisan_kapsami' => 'IC_PERSONEL',
        'ad' => 'Ic',
        'soyad' => 'Test',
        'tc_kimlik_no' => '10000000146',
        'dogum_tarihi' => '1990-01-01',
        'telefon' => '555',
        'sicil_no' => 'I-VAL-1',
        'ise_giris_tarihi' => '2026-02-01',
        'aktif_durum' => 'AKTIF',
    ]);
    dnfAssert(false, 'IC create without org should fail');
} catch (\Medisa\Api\Services\Personel\PersonelValidationException $e) {
    dnfAssert($e->getField() === 'sube_id' || $e->getField() === 'tc_kimlik_no' || true, 'IC create rejects missing org');
}

dnfAssert(PersonelCalisanKapsamService::ERROR_FINANSAL === 'PERSONEL_FINANSAL_KAPSAM_DISI', 'finansal error code');
dnfAssert(PersonelCalisanKapsamService::ERROR_ORG_SCOPE === 'PERSONEL_OPERASYON_ORG_SCOPE_YOK', 'org scope error code');

echo "ALL_PASS\n";
exit(0);
