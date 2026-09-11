<?php

declare(strict_types=1);

/**
 * Focused final-close personnel invariant-hash contract — no DB access, no mutation.
 * php tests/php/FinalClosePersonnelInvariantHashTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Controllers\PersonellerController;

function finalClosePersonnelHashAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$inputMethod = new ReflectionMethod(PersonellerController::class, 'finalClosePersonnelHashInput');
$inputMethod->setAccessible(true);
$hashMethod = new ReflectionMethod(PersonellerController::class, 'finalClosePersonnelInvariantHash');
$hashMethod->setAccessible(true);

/** @return array<string, mixed> */
$hashInputOf = static function (array $row) use ($inputMethod): array {
    $input = $inputMethod->invoke(null, $row);

    return is_array($input) ? $input : [];
};
$hashOf = static function (array $row) use ($hashMethod): string {
    return (string) $hashMethod->invoke(null, $row);
};

// 0) The bounded query itself: the runtime hash can only stay wide while the
//    statement still projects the canonical p.* row. A narrowed column list here
//    would silently shrink the invariant at runtime, so it is asserted too.
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/PersonellerController.php');
$readStart = strpos($controllerSource, 'public static function finalCloseRead(');
$detailStart = strpos($controllerSource, 'public static function detail');
finalClosePersonnelHashAssert(
    $readStart !== false && $detailStart !== false && $detailStart > $readStart,
    'controller exposes the bounded final-close personnel reader'
);
$readBody = substr($controllerSource, (int) $readStart, (int) $detailStart - (int) $readStart);
finalClosePersonnelHashAssert(
    strpos($readBody, "'SELECT p.*, s.sirket_id AS final_close_sirket_id'") !== false,
    'bounded query selects the canonical p.* row with only the aliased branch company column'
);
finalClosePersonnelHashAssert(
    preg_match('/SELECT p\.[a-z_]+/', $readBody) !== 1,
    'bounded query never narrows to an explicit personnel column list'
);
finalClosePersonnelHashAssert(
    strpos($readBody, "' LEFT JOIN subeler s ON s.id = p.sube_id'") !== false
        && preg_match_all('/JOIN\s+[a-z_]+/', $readBody) === 1,
    'the branch company join is the only relation in the bounded query'
);

/**
 * Canonical personeller row as the owner reads it: p.* plus the branch company
 * alias. Columns that only ever arrived through the old UI join chain
 * (sube_adi, departman_adi, gorev_adi, personel_tipi_adi, calisma_lokasyonu_adi)
 * are deliberately absent: they are not canonical personnel columns.
 *
 * @var array<string, mixed>
 */
$canonicalRow = [
    'id' => 203,
    'tc_kimlik_no' => '19000000203',
    'ad' => 'MUHAMMED IRAKLI',
    // The live canonical row keeps a NULL surname; the invariant drops it either way.
    'soyad' => null,
    'dogum_tarihi' => '1990-01-01',
    'telefon' => '05550000203',
    'acil_durum_kisi' => 'Aile',
    'acil_durum_telefon' => '05550000204',
    'sicil_no' => '203',
    'ise_giris_tarihi' => '2020-01-01',
    'sube_id' => 1,
    'departman_id' => 4,
    'gorev_id' => 7,
    'personel_tipi_id' => 2,
    'bagli_amir_id' => 17,
    'aktif_durum' => 'AKTIF',
    'dogum_yeri' => 'Kayseri',
    'kan_grubu' => 'A Rh+',
    'ucret_tipi_id' => 1,
    'maas_tutari' => '42500.00',
    'prim_kurali_id' => 2,
    'bolum_id' => 3,
    'birim_id' => 6,
    'sgk_isveren_id' => 4,
    'calisma_lokasyonu_id' => null,
    'created_at' => '2024-05-01 08:00:00',
    'updated_at' => '2026-08-01 09:00:00',
    'final_close_sirket_id' => 1,
];

$hashInput = $hashInputOf($canonicalRow);
$canonicalHash = $hashOf($canonicalRow);

// 1) The hash input is the canonical row itself, never a narrow preimage projection.
finalClosePersonnelHashAssert(
    array_keys($hashInput) === [
        'acil_durum_kisi', 'acil_durum_telefon', 'aktif_durum', 'bagli_amir_id', 'birim_id', 'bolum_id',
        'created_at', 'departman_id', 'dogum_tarihi', 'dogum_yeri', 'gorev_id', 'id', 'ise_giris_tarihi',
        'kan_grubu', 'maas_tutari', 'personel_tipi_id', 'prim_kurali_id', 'sgk_isveren_id', 'sicil_no',
        'sube_id', 'tc_kimlik_no', 'telefon', 'ucret_tipi_id',
    ],
    'hash input keeps every canonical personeller column (sorted; join and mutable fields dropped)'
);
finalClosePersonnelHashAssert(
    count($hashInput) > 7 && array_key_exists('tc_kimlik_no', $hashInput) && array_key_exists('maas_tutari', $hashInput),
    'hash input is strictly wider than the seven approved preimage fields'
);

// 2) The join-derived company alias never enters the personnel-row invariant.
finalClosePersonnelHashAssert(
    !array_key_exists('final_close_sirket_id', $hashInput),
    'join-derived company alias is not part of the hash input'
);
foreach ([9, null] as $aliasValue) {
    $aliasDrift = $canonicalRow;
    $aliasDrift['final_close_sirket_id'] = $aliasValue;
    finalClosePersonnelHashAssert(
        $hashOf($aliasDrift) === $canonicalHash,
        'joined company does not change the invariant: ' . var_export($aliasValue, true)
    );
}
$withoutAlias = $canonicalRow;
unset($withoutAlias['final_close_sirket_id']);
finalClosePersonnelHashAssert(
    $hashOf($withoutAlias) === $canonicalHash,
    'a p.* read without the branch join reproduces the same invariant'
);

// 3) Unrelated canonical columns still change the invariant. This is the
//    regression guard: a narrowed seven-field projection would leave every one of
//    these mutations invisible to the final-close postcheck.
foreach ($hashInput as $field => $value) {
    $drift = $canonicalRow;
    $drift[$field] = is_int($value) ? $value + 1 : (string) $value . '-drift';
    finalClosePersonnelHashAssert(
        $hashOf($drift) !== $canonicalHash,
        'canonical column changes the invariant: ' . $field
    );
}

// 4) The mutable fields this package writes stay excluded, including the legacy
//    display column that used to arrive through the UI join chain.
$mutableDrift = [
    'ad' => 'Muhammed',
    'soyad' => 'Mahmud',
    'calisma_lokasyonu_id' => 5,
    'calisma_lokasyonu_adi' => 'Merkez',
    'updated_at' => '2026-09-10 10:00:00',
];
foreach ($mutableDrift as $field => $value) {
    $drift = $canonicalRow;
    $drift[$field] = $value;
    finalClosePersonnelHashAssert(
        $hashOf($drift) === $canonicalHash,
        'mutable field stays out of the invariant: ' . $field
    );
}

// 5) A seven-field preimage projection can never reproduce the canonical invariant.
$narrowProjection = [
    'id' => 203,
    'ad' => 'MUHAMMED IRAKLI',
    'soyad' => null,
    'aktif_durum' => 'AKTIF',
    'sube_id' => 1,
    'sirket_id' => 1,
    'calisma_lokasyonu_id' => null,
];
finalClosePersonnelHashAssert(
    $hashOf($narrowProjection) !== $canonicalHash,
    'the narrow preimage projection cannot stand in for the canonical row invariant'
);

// 6) Key order and digest contract.
$reordered = array_reverse($canonicalRow, true);
finalClosePersonnelHashAssert($hashOf($reordered) === $canonicalHash, 'key order is normalised (ksort)');
finalClosePersonnelHashAssert(
    strlen($canonicalHash) === 64 && ctype_xdigit($canonicalHash),
    'sha256 hex digest over the JSON-encoded input'
);

echo 'verify-final-close-personnel-invariant-hash: OK' . PHP_EOL;
