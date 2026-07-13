<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Controllers/PuantajController.php';
require_once __DIR__ . '/../../api/src/Http/JsonResponse.php';

use Medisa\Api\Controllers\PuantajController;

if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === '--negative-probe')) {
    $ref = new ReflectionClass(PuantajController::class);
    $method = $ref->getMethod('readNullableInt');
    $method->setAccessible(true);
    ob_start();
    try {
        $method->invoke(null, ['gec_kalma_dakika' => -5], 'gec_kalma_dakika', null);
    } finally {
        $output = ob_get_clean();
    }
    if (strpos($output, 'VALIDATION_ERROR') === false) {
        fwrite(STDERR, "negative probe missing VALIDATION_ERROR\n");
        exit(2);
    }
    exit(0);
}

function invokePrivate(string $method, array $args = [])
{
    $ref = new ReflectionClass(PuantajController::class);
    $callable = $ref->getMethod($method);
    $callable->setAccessible(true);

    return $callable->invokeArgs(null, $args);
}

function failScenario(string $id, string $message): void
{
    fwrite(STDERR, "SCENARIO:$id:FAIL:$message\n");
    exit(1);
}

function passScenario(string $id, string $message): void
{
    echo "SCENARIO:$id:PASS:$message\n";
}

function createMemoryPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE gunluk_puantaj (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id INTEGER NOT NULL,
            tarih TEXT NOT NULL,
            state TEXT NOT NULL,
            gun_tipi TEXT,
            hareket_durumu TEXT,
            dayanak TEXT,
            durumu_bildirdi_mi INTEGER,
            durum_bildirim_aciklamasi TEXT,
            hesap_etkisi TEXT,
            beklenen_giris_saati TEXT,
            beklenen_cikis_saati TEXT,
            giris_saati TEXT,
            cikis_saati TEXT,
            gec_kalma_dakika INTEGER,
            erken_cikis_dakika INTEGER,
            gercek_mola_dakika INTEGER,
            hesaplanan_mola_dakika INTEGER,
            net_calisma_suresi_dakika INTEGER,
            gunluk_brut_sure_dakika INTEGER,
            hafta_tatili_hak_kazandi_mi INTEGER,
            kontrol_durumu TEXT,
            kaynak TEXT,
            aciklama TEXT,
            muhur_id INTEGER,
            updated_at TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE puantaj_aylik_muhur_satirlari (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            muhur_id INTEGER NOT NULL,
            personel_id INTEGER NOT NULL,
            tarih TEXT NOT NULL,
            gun_tipi TEXT,
            hareket_durumu TEXT,
            dayanak TEXT,
            durumu_bildirdi_mi INTEGER,
            durum_bildirim_aciklamasi TEXT,
            hesap_etkisi TEXT,
            beklenen_giris_saati TEXT,
            beklenen_cikis_saati TEXT,
            giris_saati TEXT,
            cikis_saati TEXT,
            gec_kalma_dakika INTEGER,
            erken_cikis_dakika INTEGER,
            gercek_mola_dakika INTEGER,
            hesaplanan_mola_dakika INTEGER,
            net_calisma_suresi_dakika INTEGER,
            gunluk_brut_sure_dakika INTEGER,
            hafta_tatili_hak_kazandi_mi INTEGER,
            kontrol_durumu TEXT,
            kaynak TEXT,
            aciklama TEXT
        )'
    );

    return $pdo;
}

function baseExistingRow(): array
{
    return [
        'id' => 1,
        'personel_id' => 10,
        'tarih' => '2026-06-15',
        'state' => 'ACIK',
        'gun_tipi' => 'Normal_Is_Gunu',
        'hareket_durumu' => 'Gec_Geldi',
        'dayanak' => 'Yok_Izinsiz',
        'durumu_bildirdi_mi' => 0,
        'durum_bildirim_aciklamasi' => null,
        'hesap_etkisi' => 'Tam_Yevmiye_Ver',
        'beklenen_giris_saati' => '08:00',
        'beklenen_cikis_saati' => '17:00',
        'giris_saati' => '08:15',
        'cikis_saati' => '17:00',
        'gec_kalma_dakika' => 15,
        'erken_cikis_dakika' => null,
        'gercek_mola_dakika' => null,
        'hesaplanan_mola_dakika' => null,
        'net_calisma_suresi_dakika' => null,
        'gunluk_brut_sure_dakika' => null,
        'hafta_tatili_hak_kazandi_mi' => 1,
        'kontrol_durumu' => 'BEKLIYOR',
        'kaynak' => null,
        'aciklama' => null,
        'muhur_id' => null,
    ];
}

// 1. Insert build: gec=15, erken=null
$insertValues = invokePrivate('buildUpsertValues', [
    ['gec_kalma_dakika' => 15, 'erken_cikis_dakika' => null],
    [],
    10,
    '2026-06-15',
]);
if ($insertValues['gec_kalma_dakika'] !== 15 || $insertValues['erken_cikis_dakika'] !== null) {
    failScenario('1', 'Insert buildUpsertValues gec=15 erken=null');
}
passScenario('1', 'Insert buildUpsertValues gec=15 erken=null');

// 2. Insert build reverse: gec=null, erken=20
$insertReverse = invokePrivate('buildUpsertValues', [
    ['gec_kalma_dakika' => null, 'erken_cikis_dakika' => 20],
    [],
    11,
    '2026-06-16',
]);
if ($insertReverse['gec_kalma_dakika'] !== null || $insertReverse['erken_cikis_dakika'] !== 20) {
    failScenario('2', 'Insert buildUpsertValues gec=null erken=20');
}
passScenario('2', 'Insert buildUpsertValues gec=null erken=20');

// 3. Update preserves missing field
$existing = baseExistingRow();
$partialUpdate = invokePrivate('buildUpsertValues', [
    ['erken_cikis_dakika' => 20],
    $existing,
    10,
    '2026-06-15',
]);
if ($partialUpdate['gec_kalma_dakika'] !== 15 || $partialUpdate['erken_cikis_dakika'] !== 20) {
    failScenario('3', 'Update partial erken preserves gec');
}
passScenario('3', 'Update partial erken preserves gec=15');

// 4. Numeric string normalization
$stringNorm = invokePrivate('buildUpsertValues', [
    ['gec_kalma_dakika' => '18'],
    [],
    12,
    '2026-06-17',
]);
if ($stringNorm['gec_kalma_dakika'] !== 18) {
    failScenario('4', 'Numeric string gec_kalma_dakika normalize');
}
passScenario('4', 'Numeric string gec_kalma_dakika normalize');

// 5. mapRow integer/null mapping
$mapped = invokePrivate('mapRow', [
    array_merge(baseExistingRow(), ['gec_kalma_dakika' => '22', 'erken_cikis_dakika' => null]),
]);
if ($mapped['gec_kalma_dakika'] !== 22 || $mapped['erken_cikis_dakika'] !== null) {
    failScenario('5', 'mapRow dakika mapping');
}
if (!array_key_exists('gec_kalma_dakika', $mapped) || !array_key_exists('erken_cikis_dakika', $mapped)) {
    failScenario('5', 'mapRow missing dakika keys');
}
passScenario('5', 'mapRow dakika integer/null response');

// 6. PDO insert persists dakika columns
$pdo = createMemoryPdo();
$fullInsertValues = invokePrivate('buildUpsertValues', [
    [
        'gun_tipi' => 'Normal_Is_Gunu',
        'hareket_durumu' => 'Gec_Geldi',
        'dayanak' => 'Yok_Izinsiz',
        'hesap_etkisi' => 'Tam_Yevmiye_Ver',
        'gec_kalma_dakika' => 15,
        'erken_cikis_dakika' => null,
    ],
    [],
    10,
    '2026-06-15',
]);
invokePrivate('insertPuantajRow', [$pdo, $fullInsertValues]);
$row = $pdo->query('SELECT gec_kalma_dakika, erken_cikis_dakika FROM gunluk_puantaj LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ((int) $row['gec_kalma_dakika'] !== 15 || $row['erken_cikis_dakika'] !== null) {
    failScenario('6', 'PDO insert dakika columns');
}
passScenario('6', 'PDO insert dakika columns');

// 7. PDO update preserves untouched dakika
$storedRow = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$updateValues = invokePrivate('buildUpsertValues', [
    ['erken_cikis_dakika' => 20],
    $storedRow,
    10,
    '2026-06-15',
]);
invokePrivate('updatePuantajRow', [$pdo, 1, $updateValues]);
$updated = $pdo->query('SELECT gec_kalma_dakika, erken_cikis_dakika FROM gunluk_puantaj WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
if ((int) $updated['gec_kalma_dakika'] !== 15 || (int) $updated['erken_cikis_dakika'] !== 20) {
    failScenario('7', 'PDO update preserves gec updates erken');
}
passScenario('7', 'PDO update preserves gec updates erken');

// 8. Seal snapshot copies gec=15 erken=null
$sealRow = baseExistingRow();
invokePrivate('insertSealRows', [$pdo, 99, [$sealRow]]);
$sealed = $pdo->query('SELECT gec_kalma_dakika, erken_cikis_dakika FROM puantaj_aylik_muhur_satirlari LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ((int) $sealed['gec_kalma_dakika'] !== 15 || $sealed['erken_cikis_dakika'] !== null) {
    failScenario('8', 'Seal snapshot gec=15 erken=null');
}
passScenario('8', 'Seal snapshot gec=15 erken=null');

// 9. Seal snapshot reverse gec=null erken=20
$sealReverse = baseExistingRow();
$sealReverse['gec_kalma_dakika'] = null;
$sealReverse['erken_cikis_dakika'] = 20;
invokePrivate('insertSealRows', [$pdo, 100, [$sealReverse]]);
$sealedReverse = $pdo->query('SELECT gec_kalma_dakika, erken_cikis_dakika FROM puantaj_aylik_muhur_satirlari WHERE muhur_id = 100')->fetch(PDO::FETCH_ASSOC);
if ($sealedReverse['gec_kalma_dakika'] !== null || (int) $sealedReverse['erken_cikis_dakika'] !== 20) {
    failScenario('9', 'Seal snapshot gec=null erken=20');
}
passScenario('9', 'Seal snapshot gec=null erken=20');

// 10. Negative validation via subprocess probe
$probeCmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --negative-probe';
exec($probeCmd, $probeOutput, $probeCode);
if ($probeCode !== 0) {
    failScenario('10', 'Negative gec_kalma_dakika probe exit code');
}
passScenario('10', 'Negative gec_kalma_dakika VALIDATION_ERROR');

echo "OK\n";
