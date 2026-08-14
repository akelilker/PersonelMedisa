<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';
require_once __DIR__ . '/../../api/src/Controllers/PuantajController.php';
require_once __DIR__ . '/../../api/src/Http/JsonResponse.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Database\Connection;
use Medisa\Api\Controllers\PuantajController;
use Medisa\Api\Http\Request;

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

if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === '--upsert-child')) {
    $cfg = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($cfg)) {
        fwrite(STDERR, "bad upsert child config\n");
        exit(2);
    }
    $pdo = new PDO('sqlite:' . (string) $cfg['db']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $connRef = new ReflectionClass(Connection::class);
    $connProp = $connRef->getProperty('pdo');
    $connProp->setAccessible(true);
    $connProp->setValue(null, $pdo);
    $authRef = new ReflectionClass(AuthMiddleware::class);
    $authProp = $authRef->getProperty('user');
    $authProp->setAccessible(true);
    $authProp->setValue(null, $cfg['user']);
    register_shutdown_function(static function () use ($cfg): void {
        file_put_contents((string) $cfg['status_file'], (string) http_response_code());
    });

    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => 'PUT',
        'path' => '/puantaj/' . (int) $cfg['personel_id'] . '/2026-08-15',
        'headers' => [],
        'jsonBody' => [
            'gun_tipi' => 'Normal_Is_Gunu',
            'hareket_durumu' => 'Geldi',
            'dayanak' => 'Gorevde_Calisma',
            'hesap_etkisi' => 'Tam_Yevmiye_Ver',
            'giris_saati' => '08:00',
            'cikis_saati' => '17:00',
        ],
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }
    PuantajController::upsert($request, (int) $cfg['personel_id'], '2026-08-15');
    exit(3);
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
        'CREATE TABLE resmi_tatil_takvimi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tarih TEXT NOT NULL,
            tatil_kodu TEXT NOT NULL,
            tatil_adi TEXT NOT NULL,
            tatil_turu TEXT NOT NULL,
            gun_kapsami TEXT NOT NULL,
            tatil_interval_baslangic TEXT,
            tatil_interval_bitis TEXT,
            durum TEXT NOT NULL,
            kaynak_turu TEXT NOT NULL,
            kaynak_referansi TEXT NOT NULL,
            kaynak_tarihi TEXT,
            aciklama TEXT,
            revizyon_no INTEGER NOT NULL DEFAULT 1,
            onceki_kayit_id INTEGER,
            yapan_kullanici_id INTEGER
        )'
    );
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
            sgk_eksik_gun_neden_tipi TEXT,
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
            tatil_takvim_id INTEGER,
            tatil_turu TEXT,
            tatil_gun_kapsami TEXT,
            tatil_interval_baslangic TEXT,
            tatil_interval_bitis TEXT,
            tatil_siniflandirma_durumu TEXT,
            tatil_snapshot_hash TEXT,
            tatil_kaynak_referansi TEXT,
            tatil_donemi_brut_calisma_dakika INTEGER,
            tatil_donemi_ara_dinlenme_dakika INTEGER,
            tatil_donemi_net_calisma_dakika INTEGER,
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
            sgk_eksik_gun_neden_tipi TEXT,
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
            tatil_takvim_id INTEGER,
            tatil_turu TEXT,
            tatil_gun_kapsami TEXT,
            tatil_interval_baslangic TEXT,
            tatil_interval_bitis TEXT,
            tatil_siniflandirma_durumu TEXT,
            tatil_snapshot_hash TEXT,
            tatil_kaynak_referansi TEXT,
            tatil_donemi_brut_calisma_dakika INTEGER,
            tatil_donemi_ara_dinlenme_dakika INTEGER,
            tatil_donemi_net_calisma_dakika INTEGER
        )'
    );

    return $pdo;
}

function resetConnectionPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function createUpsertPdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE personeller (
        id INTEGER PRIMARY KEY,
        sube_id INTEGER NOT NULL,
        dogum_tarihi TEXT NULL,
        tc_kimlik_no TEXT NULL,
        soyad TEXT NULL,
        telefon TEXT NULL,
        calisan_kapsami TEXT NOT NULL DEFAULT "IC_PERSONEL"
    )');
    $pdo->exec("INSERT INTO personeller (id, sube_id, dogum_tarihi, tc_kimlik_no, soyad, telefon, calisan_kapsami) VALUES
        (10, 1, '1990-01-01', '11111111111', 'Ic', '05000000000', 'IC_PERSONEL'),
        (20, 1, NULL, NULL, NULL, NULL, 'DIS_KAYNAK'),
        (30, 2, NULL, NULL, NULL, NULL, 'DIS_KAYNAK')");
    $pdo->exec('CREATE TABLE resmi_tatil_takvimi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tarih TEXT NOT NULL,
        tatil_kodu TEXT NOT NULL,
        tatil_adi TEXT NOT NULL,
        tatil_turu TEXT NOT NULL,
        gun_kapsami TEXT NOT NULL,
        tatil_interval_baslangic TEXT,
        tatil_interval_bitis TEXT,
        durum TEXT NOT NULL,
        kaynak_turu TEXT NOT NULL,
        kaynak_referansi TEXT NOT NULL,
        kaynak_tarihi TEXT,
        aciklama TEXT,
        revizyon_no INTEGER NOT NULL DEFAULT 1,
        onceki_kayit_id INTEGER,
        yapan_kullanici_id INTEGER
    )');
    $pdo->exec('CREATE TABLE puantaj_donem_kilitleri (
        sube_id INTEGER NOT NULL,
        yil INTEGER NOT NULL,
        ay INTEGER NOT NULL,
        PRIMARY KEY (sube_id, yil, ay)
    )');
    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sube_id INTEGER NOT NULL,
        yil INTEGER NOT NULL,
        ay INTEGER NOT NULL,
        revision_no INTEGER NOT NULL DEFAULT 1,
        donem TEXT,
        durum TEXT NOT NULL,
        muhurlenen_kayit_sayisi INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER,
        parent_muhur_id INTEGER,
        reopen_talep_id INTEGER,
        source_hash TEXT
    )');
    $pdo->exec('CREATE TABLE gunluk_puantaj (
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
        sgk_eksik_gun_neden_tipi TEXT,
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
        tatil_takvim_id INTEGER,
        tatil_turu TEXT,
        tatil_gun_kapsami TEXT,
        tatil_interval_baslangic TEXT,
        tatil_interval_bitis TEXT,
        tatil_siniflandirma_durumu TEXT,
        tatil_snapshot_hash TEXT,
        tatil_kaynak_referansi TEXT,
        tatil_donemi_brut_calisma_dakika INTEGER,
        tatil_donemi_ara_dinlenme_dakika INTEGER,
        tatil_donemi_net_calisma_dakika INTEGER,
        updated_at TEXT
    )');

    return $pdo;
}

/**
 * @return array{status:int,payload:array<string,mixed>,stdout:string,stderr:string}
 */
function invokeUpsertChild(string $dbPath, array $user, int $personelId): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'puantaj_status_');
    $payload = json_encode([
        'db' => $dbPath,
        'user' => $user,
        'personel_id' => $personelId,
        'status_file' => $statusFile,
    ], JSON_UNESCAPED_UNICODE);
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=php_sqlite3';
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=php_pdo_sqlite';
    }
    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--upsert-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) {
        throw new RuntimeException('upsert child failed to start');
    }
    fwrite($pipes[0], (string) $payload);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $status = (int) trim((string) @file_get_contents((string) $statusFile));
    @unlink((string) $statusFile);
    $decoded = json_decode($stdout, true);

    return [
        'status' => $status,
        'payload' => is_array($decoded) ? $decoded : [],
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
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

// 11. Gece yarısını aşan vardiya gece bandındadır
if (invokePrivate('geceBandinaGiriyor', ['22:00', '05:00']) !== true) {
    failScenario('11', '22:00-05:00 gece bandi bekleniyordu');
}
passScenario('11', '22:00-05:00 overnight gece bandi');

// 12. Gündüz vardiyası gece bandında değildir
if (invokePrivate('geceBandinaGiriyor', ['08:00', '17:00']) !== false) {
    failScenario('12', '08:00-17:00 gece bandi disi bekleniyordu');
}
passScenario('12', '08:00-17:00 gece bandi disi');

// 13. Direct upsert keeps IC_PERSONEL write path and blocks DIS_KAYNAK before write.
$dbFile = tempnam(sys_get_temp_dir(), 'puantaj_upsert_');
if ($dbFile === false) {
    failScenario('13', 'temp db failed');
}
$upsertPdo = createUpsertPdo($dbFile);
$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$bolumScoped = ['id' => 2, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];
$internal = invokeUpsertChild($dbFile, $gy, 10);
if ($internal['status'] !== 200 || (int) $upsertPdo->query('SELECT COUNT(*) FROM gunluk_puantaj WHERE personel_id = 10')->fetchColumn() !== 1) {
    failScenario('13', 'authorized IC_PERSONEL upsert did not write status=' . $internal['status'] . ' stdout=' . $internal['stdout'] . ' stderr=' . $internal['stderr']);
}
passScenario('13', 'authorized IC_PERSONEL upsert writes');

$external = invokeUpsertChild($dbFile, $gy, 20);
if ($external['status'] !== 409 || ($external['payload']['errors'][0]['code'] ?? '') !== 'PERSONEL_OPERASYON_KAPSAM_DISI') {
    failScenario('14', 'authorized DIS_KAYNAK upsert missing scope error');
}
if ((int) $upsertPdo->query('SELECT COUNT(*) FROM gunluk_puantaj WHERE personel_id = 20')->fetchColumn() !== 0) {
    failScenario('14', 'DIS_KAYNAK upsert wrote a row');
}
passScenario('14', 'authorized DIS_KAYNAK upsert blocked without write');

$wrongBranch = invokeUpsertChild($dbFile, $bolumScoped, 30);
if ($wrongBranch['status'] !== 403 || ($wrongBranch['payload']['errors'][0]['code'] ?? '') === 'PERSONEL_OPERASYON_KAPSAM_DISI') {
    failScenario('15', 'wrong branch did not win before external guard');
}
passScenario('15', 'wrong branch wins before external guard');
@unlink($dbFile);

echo "OK\n";
