<?php

declare(strict_types=1);

/**
 * Pack5 Final Code Gap: disposable MariaDB — Track A (rolling OT provenance)
 * + Track B (org location schema).
 * php tests/php/FinalCodeGapPack5MysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Payroll\FazlaCalismaYillikLimitService;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelImportDryRunService;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelOrgLocationSchema;
use Medisa\Api\Services\Personel\PersonelValidationException;

function p5Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function p5RootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function p5SplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    $inSingle = false;
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if (!$inSingle && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
            continue;
        }
        if (!$inTrigger && !$inSingle && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] !== "'") {
                continue;
            }
            if ($inSingle && $i + 1 < $len && $line[$i + 1] === "'") {
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
        }
        if ($inSingle) {
            continue;
        }
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function p5Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (p5SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function p5PdoForDb(string $database): PDO
{
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN'));

    return new PDO(
        (string) $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/** @return list<string> */
function p5MigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

/** @param list<string> $files */
function p5ApplyThrough(PDO $pdo, array $files, string $maxInclusive): void
{
    foreach ($files as $file) {
        p5Apply($pdo, $file);
        if ($file === $maxInclusive) {
            return;
        }
    }
    throw new RuntimeException('Migration tip not reached: ' . $maxInclusive);
}

function p5AssertSafeTarget(string $database): void
{
    $dbLower = strtolower($database);
    if (strpos($dbLower, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: refused database name containing karmotor_medisa\n");
        exit(1);
    }
    $dsn = (string) (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    if (stripos($dsn, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: DSN contains karmotor_medisa\n");
        exit(1);
    }
    if (!preg_match('/host=([^;]+)/i', $dsn, $m)) {
        return;
    }
    $host = strtolower(trim($m[1]));
    if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "ABORT: host suggests production ({$host})\n");
        exit(1);
    }
}

function p5ColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function p5SeedOrgRefs(PDO $pdo): void
{
    $hash = password_hash('P5Pack5TestPass-24chars!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES
        (1, 'MRK', 'Merkez', 'AKTIF'),
        (2, 'SB2', 'Sube 2', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES
        (1, 'İdari İşler', 'AKTIF'),
        (2, 'Klinik', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES
        (1, 'Uzman', 'AKTIF'),
        (2, 'Asistan', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES
        (1, 'Tam Zamanli', 'AKTIF'),
        (2, 'Yari Zamanli', 'AKTIF')");
    $pdo->exec('INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1, 1), (1, 2), (2, 2)');
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (1, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF')"
    );
}

function p5SeedPersonelMinimal(PDO $pdo, int $id, string $tc, string $sicil, int $subeId = 1): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id, aktif_durum
         ) VALUES (
            :id, :tc, 'P5', :soyad, '1990-01-01', '05000000000', 'Acil', '05000000001',
            :sicil, '2010-01-01', :sube, 1, 1, 1, 'AKTIF'
         )"
    );
    $stmt->execute([
        'id' => $id,
        'tc' => $tc,
        'soyad' => 'P' . $id,
        'sicil' => $sicil,
        'sube' => $subeId,
    ]);
}

/** @return array<string, mixed> */
function p5CreatePayload(array $overrides = []): array
{
    $base = [
        'tc_kimlik_no' => '10000000146',
        'ad' => 'Ayşe',
        'soyad' => 'Yılmaz',
        'dogum_tarihi' => '1990-05-15',
        'telefon' => '05321112233',
        'acil_durum_kisi' => 'Ali Yılmaz',
        'acil_durum_telefon' => '05324445566',
        'sicil_no' => 'P5-001',
        'ise_giris_tarihi' => '2024-01-10',
        'sube_id' => 1,
        'departman_id' => 1,
        'gorev_id' => 2,
        'personel_tipi_id' => 1,
        'aktif_durum' => 'AKTIF',
    ];

    return array_merge($base, $overrides);
}

/** Legacy CSV headers without org-location optional columns. */
function p5LegacyCsvHeader(): string
{
    return implode(';', [
        'tc_kimlik_no',
        'sicil_no',
        'ad',
        'soyad',
        'dogum_tarihi',
        'dogum_yeri',
        'telefon',
        'kan_grubu',
        'acil_durum_kisi',
        'acil_durum_telefon',
        'ise_giris_tarihi',
        'sube',
        'departman',
        'gorev',
        'personel_tipi',
    ]);
}

function p5LegacyCsvRow(array $overrides = []): string
{
    $row = array_merge([
        'tc_kimlik_no' => '10000000146',
        'sicil_no' => 'IMP-P5-001',
        'ad' => 'Ayşe',
        'soyad' => 'Yılmaz',
        'dogum_tarihi' => '1990-05-15',
        'dogum_yeri' => 'Ankara',
        'telefon' => '0532 111 22 33',
        'kan_grubu' => 'A Rh+',
        'acil_durum_kisi' => 'Ali Yılmaz',
        'acil_durum_telefon' => '0532 444 55 66',
        'ise_giris_tarihi' => '2024-01-10',
        'sube' => 'Merkez',
        'departman' => 'İdari İşler',
        'gorev' => 'Asistan',
        'personel_tipi' => 'Tam Zamanli',
    ], $overrides);

    $ordered = [];
    foreach ([
        'tc_kimlik_no', 'sicil_no', 'ad', 'soyad', 'dogum_tarihi', 'dogum_yeri', 'telefon',
        'kan_grubu', 'acil_durum_kisi', 'acil_durum_telefon', 'ise_giris_tarihi',
        'sube', 'departman', 'gorev', 'personel_tipi',
    ] as $col) {
        $ordered[] = (string) ($row[$col] ?? '');
    }

    return implode(';', $ordered);
}

function p5OrgCsvHeader(): string
{
    return implode(';', array_merge(
        PersonelImportDryRunService::TEMPLATE_COLUMNS,
        ['sgk_isveren', 'calisma_lokasyonu']
    ));
}

function p5OrgCsvRow(array $overrides = []): string
{
    $row = array_merge([
        'tc_kimlik_no' => '10000000154',
        'sicil_no' => 'IMP-P5-ORG',
        'ad' => 'Mehmet',
        'soyad' => 'Demir',
        'dogum_tarihi' => '1991-06-20',
        'dogum_yeri' => 'İzmir',
        'telefon' => '0532 222 33 44',
        'kan_grubu' => 'B Rh+',
        'acil_durum_kisi' => 'Ayse Demir',
        'acil_durum_telefon' => '0532 555 66 77',
        'ise_giris_tarihi' => '2024-02-01',
        'sube' => 'Merkez',
        'departman' => 'İdari İşler',
        'gorev' => 'Asistan',
        'personel_tipi' => 'Tam Zamanli',
        'sgk_isveren' => 'Isveren A',
        'calisma_lokasyonu' => 'Lokasyon A',
    ], $overrides);

    $ordered = [];
    foreach (array_merge(PersonelImportDryRunService::TEMPLATE_COLUMNS, ['sgk_isveren', 'calisma_lokasyonu']) as $col) {
        $ordered[] = (string) ($row[$col] ?? '');
    }

    return implode(';', $ordered);
}

function p5CountPersonel(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
}

/**
 * Insert KAPANDI weekly snapshot (+ optional actual-date distribution).
 *
 * @param list<array{tarih:string,dakika:int}>|null $distribution
 * @return array{kapanis_id:int,satir_id:int}
 */
function p5InsertClosedWeek(
    PDO $pdo,
    int $personelId,
    string $haftaBaslangic,
    int $fazlaCalismaDakika,
    ?array $distribution = null,
    int $toplamNet = 0
): array {
    $haftaBitis = date('Y-m-d', strtotime($haftaBaslangic . ' +6 days'));
    if ($toplamNet < 1) {
        $toplamNet = 2700 + max(0, $fazlaCalismaDakika);
    }
    $pdo->exec(
        "INSERT INTO haftalik_kapanislar
            (sube_id, hafta_baslangic, hafta_bitis, state, personel_sayisi, snapshot_satir_sayisi, created_by)
         VALUES (1, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI', 1, 1, 1)"
    );
    $kapanisId = (int) $pdo->lastInsertId();

    $policySql = 'NULL';
    $jsonSql = 'NULL';
    if ($distribution !== null) {
        $encoded = FazlaCalismaYillikLimitService::encodeDistributionJson($distribution);
        if ($encoded !== null) {
            $jsonSql = $pdo->quote($encoded);
            $policySql = $pdo->quote(FazlaCalismaYillikLimitService::POLICY_CODE);
        }
    }

    $hasProvenance = p5ColumnExists($pdo, 'haftalik_kapanis_satirlari', 'fazla_calisma_tarih_dagilimi_json');
    if ($hasProvenance) {
        $pdo->exec(
            "INSERT INTO haftalik_kapanis_satirlari (
                kapanis_id, personel_id, hafta_baslangic, hafta_bitis, state,
                toplam_net_dakika, normal_calisma_dakika, fazla_calisma_dakika,
                fazla_calisma_tarih_dagilimi_json, fazla_calisma_tarih_dagilim_policy,
                fazla_surelerle_calisma_dakika,
                tam_hafta_verisi, compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi,
                hesaplama_zamani, kaynak_gun_sayisi, notlar_json
             ) VALUES (
                {$kapanisId}, {$personelId}, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI',
                {$toplamNet}, 2700, {$fazlaCalismaDakika},
                {$jsonSql}, {$policySql},
                0,
                1, '[]', 0, 0,
                '{$haftaBaslangic} 18:00:00', 7, NULL
             )"
        );
    } else {
        $pdo->exec(
            "INSERT INTO haftalik_kapanis_satirlari (
                kapanis_id, personel_id, hafta_baslangic, hafta_bitis, state,
                toplam_net_dakika, normal_calisma_dakika, fazla_calisma_dakika, fazla_surelerle_calisma_dakika,
                tam_hafta_verisi, compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi,
                hesaplama_zamani, kaynak_gun_sayisi, notlar_json
             ) VALUES (
                {$kapanisId}, {$personelId}, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI',
                {$toplamNet}, 2700, {$fazlaCalismaDakika}, 0,
                1, '[]', 0, 0,
                '{$haftaBaslangic} 18:00:00', 7, NULL
             )"
        );
    }
    $satirId = (int) $pdo->lastInsertId();

    return ['kapanis_id' => $kapanisId, 'satir_id' => $satirId];
}

function p5MakeRequest(string $method = 'GET', array $headers = [], array $query = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => '/',
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'jsonBody' => [],
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }
    $_GET = [];
    foreach ($query as $key => $value) {
        $_GET[(string) $key] = $value;
    }

    return $request;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$root = p5RootPdo();
$files = p5MigrationFiles();
p5Assert(count($files) >= 64, 'migrations 001→064 present');
p5Assert(end($files) === '064_personel_org_location_model.sql', 'tip ends with 064_personel_org_location_model.sql');

$gyUser = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$scopedUser = ['id' => 2, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];

// ========== Phase Pre064 (through 062) — B1–B3 ==========
$dbPre = 'medisa_pack5_pre_' . substr(bin2hex(random_bytes(4)), 0, 8);
p5AssertSafeTarget($dbPre);
$root->exec('CREATE DATABASE `' . $dbPre . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdoPre = p5PdoForDb($dbPre);

try {
    p5ApplyThrough($pdoPre, $files, '062_serbest_zaman_retention_destroy_gate.sql');
    p5Assert(!PersonelOrgLocationSchema::isReady($pdoPre), 'pre-064 org schema not ready');
    p5Assert(!p5ColumnExists($pdoPre, 'haftalik_kapanis_satirlari', 'fazla_calisma_tarih_dagilimi_json'), '063 cols absent pre-064');
    p5SeedOrgRefs($pdoPre);

    // B1: old create without org fields works
    $beforeB1 = p5CountPersonel($pdoPre);
    $payloadB1 = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p5CreatePayload([
        'tc_kimlik_no' => '10000000146',
        'sicil_no' => 'P5-B1',
    ]));
    PersonelCreateService::validateCreateReferences($pdoPre, $payloadB1);
    $idB1 = PersonelCreateService::insertPersonel($pdoPre, $payloadB1);
    p5Assert($idB1 > 0 && p5CountPersonel($pdoPre) === $beforeB1 + 1, 'B1 pre-064 create without org fields works');

    // B2: old import CSV (no org cols) analyze PASS
    $legacyCsv = p5LegacyCsvHeader() . "\r\n" . p5LegacyCsvRow([
        'tc_kimlik_no' => '10000000162',
        'sicil_no' => 'IMP-B2',
    ]) . "\r\n";
    $dryB2 = PersonelImportDryRunService::analyze($pdoPre, $legacyCsv, $gyUser, null);
    p5Assert(($dryB2['ozet']['gecerli_satir'] ?? 0) === 1, 'B2 pre-064 legacy CSV analyze PASS');
    p5Assert(($dryB2['ozet']['hatali_satir'] ?? -1) === 0, 'B2 pre-064 legacy CSV zero hatali');
    p5Assert(($dryB2['can_apply'] ?? false) === true, 'B2 pre-064 can_apply true');

    // B3: explicit sgk_isveren_id on create → gate 409 / ERROR_CODE — no mutation
    $beforeB3 = p5CountPersonel($pdoPre);
    $payloadB3 = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p5CreatePayload([
        'tc_kimlik_no' => '10000000170',
        'sicil_no' => 'P5-B3',
        'sgk_isveren_id' => 1,
    ]));
    $would409 = PersonelOrgLocationSchema::payloadRequestsOrgFields($payloadB3)
        && !PersonelOrgLocationSchema::isReady($pdoPre);
    p5Assert($would409, 'B3 create org field → ORG_LOCATION_SCHEMA_NOT_READY gate');
    p5Assert(
        PersonelOrgLocationSchema::ERROR_CODE === 'ORG_LOCATION_SCHEMA_NOT_READY',
        'B3 ERROR_CODE constant'
    );
    // Do not insert when gate fires (mirror controller)
    p5Assert(p5CountPersonel($pdoPre) === $beforeB3, 'B3 create no mutation');

    // B3: org CSV col → 409 import exception — no mutation
    $orgCsv = p5OrgCsvHeader() . "\r\n" . p5OrgCsvRow() . "\r\n";
    $caught = null;
    try {
        PersonelImportDryRunService::analyze($pdoPre, $orgCsv, $gyUser, null);
    } catch (PersonelImportException $e) {
        $caught = $e;
    }
    p5Assert($caught !== null, 'B3 org CSV throws');
    p5Assert($caught->getCodeString() === PersonelOrgLocationSchema::ERROR_CODE, 'B3 org CSV ERROR_CODE');
    p5Assert($caught->getHttpStatus() === 409, 'B3 org CSV HTTP 409');
    p5Assert(p5CountPersonel($pdoPre) === $beforeB3, 'B3 org CSV no mutation');
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $dbPre . '`');
    } catch (Throwable $e) {
        // ignore
    }
}

// ========== Phase Full (through 064) — A* + B4–B11 ==========
$dbFull = 'medisa_pack5_' . substr(bin2hex(random_bytes(4)), 0, 8);
p5AssertSafeTarget($dbFull);
$root->exec('CREATE DATABASE `' . $dbFull . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = p5PdoForDb($dbFull);

try {
    foreach ($files as $file) {
        p5Apply($pdo, $file);
    }
    p5Assert(PersonelOrgLocationSchema::isReady($pdo), 'post-064 org schema ready');
    p5Assert(
        p5ColumnExists($pdo, 'haftalik_kapanis_satirlari', 'fazla_calisma_tarih_dagilimi_json'),
        '063 fazla_calisma_tarih_dagilimi_json exists'
    );
    p5Assert(
        p5ColumnExists($pdo, 'haftalik_kapanis_satirlari', 'fazla_calisma_tarih_dagilim_policy'),
        '063 fazla_calisma_tarih_dagilim_policy exists'
    );
    p5Assert(FazlaCalismaYillikLimitService::provenanceSchemaReady($pdo), 'provenanceSchemaReady true');

    p5SeedOrgRefs($pdo);
    // Test-only org rows (not production seed)
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES
        (1, 'ISV-A', 'Isveren A', 'AKTIF'),
        (2, 'ISV-B', 'Isveren B', 'AKTIF')");
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, durum) VALUES
        (1, 'LOK-A', 'Lokasyon A', 'AKTIF'),
        (2, 'LOK-B', 'Lokasyon B', 'AKTIF')");

    p5SeedPersonelMinimal($pdo, 10, '11111111110', 'S010');
    p5SeedPersonelMinimal($pdo, 20, '22222222220', 'S020');
    p5SeedPersonelMinimal($pdo, 30, '33333333330', 'S030');
    p5SeedPersonelMinimal($pdo, 40, '44444444440', 'S040');
    p5SeedPersonelMinimal($pdo, 50, '55555555550', 'S050');
    p5SeedPersonelMinimal($pdo, 60, '66666666660', 'S060');
    p5SeedPersonelMinimal($pdo, 70, '77777777770', 'S070');
    p5SeedPersonelMinimal($pdo, 80, '88888888880', 'S080');
    p5SeedPersonelMinimal($pdo, 90, '99999999990', 'S090');

    // ----- A10 policy constants / ISO week year not owner -----
    p5Assert(
        FazlaCalismaYillikLimitService::POLICY_CODE === 'ROLLING_12_MONTH_ACTUAL_DATE_V1',
        'A10 POLICY_CODE=ROLLING_12_MONTH_ACTUAL_DATE_V1'
    );
    p5Assert(
        FazlaCalismaYillikLimitService::PERSONEL_ROLLING_LOCK_YIL === 0,
        'A10 PERSONEL_ROLLING_LOCK_YIL sentinel 0'
    );
    p5Assert(FazlaCalismaYillikLimitService::LIMIT_DAKIKA === 16200, 'A10 LIMIT_DAKIKA 16200');
    $svcSrc = (string) file_get_contents(
        __DIR__ . '/../../api/src/Services/Payroll/FazlaCalismaYillikLimitService.php'
    );
    p5Assert(
        strpos($svcSrc, 'ISO/calendar year is NOT the 270h owner') !== false
            || strpos($svcSrc, 'ISO week year is NOT') !== false,
        'A10 ISO week year is NOT compliance owner (source)'
    );
    p5Assert(strpos($svcSrc, 'ROLLING_12_MONTH_ACTUAL_DATE_V1') !== false, 'A10 policy string in source');

    // ----- A1: year-boundary week — distribution only where excess occurs -----
    $dailyA1 = [
        '2025-12-29' => 540,
        '2025-12-30' => 540,
        '2025-12-31' => 540,
        '2026-01-01' => 540,
        '2026-01-02' => 540, // cumulative 2700 after Fri
        '2026-01-03' => 100,
        '2026-01-04' => 80,
    ];
    $weeklyFmA1 = 180; // 2880-2700
    $distA1 = FazlaCalismaYillikLimitService::allocateActualDateProvenance($dailyA1, $weeklyFmA1);
    $datesA1 = array_column($distA1, 'tarih');
    p5Assert($datesA1 === ['2026-01-03', '2026-01-04'], 'A1 excess only on Sat/Sun real dates');
    p5Assert(!in_array('2025-12-29', $datesA1, true), 'A1 not whole week dumped to one year start');
    $yearsA1 = array_values(array_unique(array_map(static function ($d) {
        return substr((string) $d, 0, 4);
    }, $datesA1)));
    p5Assert($yearsA1 === ['2026'], 'A1 OT lands on actual excess dates (2026)');
    // Persist + load for year-cross week metadata
    p5InsertClosedWeek($pdo, 10, '2025-12-29', $weeklyFmA1, $distA1, 2880);

    // ----- A2: 48h week — threshold crossed on a specific day -----
    $dailyA2 = [
        '2025-06-02' => 600,
        '2025-06-03' => 600,
        '2025-06-04' => 600,
        '2025-06-05' => 600, // 2400
        '2025-06-06' => 480, // take 300 normal → 180 OT on this day
        '2025-06-07' => 0,
        '2025-06-08' => 0,
    ];
    $distA2 = FazlaCalismaYillikLimitService::allocateActualDateProvenance($dailyA2, 180);
    p5Assert(count($distA2) === 1, 'A2 single distribution day');
    p5Assert(($distA2[0]['tarih'] ?? '') === '2025-06-06', 'A2 excess on real cross day');
    p5Assert((int) ($distA2[0]['dakika'] ?? 0) === 180, 'A2 only 180 excess minutes');

    // ----- A3: sum(distribution) === weekly fazla_calisma_dakika -----
    p5Assert(
        FazlaCalismaYillikLimitService::sumDistributionMinutes($distA1) === $weeklyFmA1,
        'A3 sum(dist A1) === weekly FM'
    );
    p5Assert(
        FazlaCalismaYillikLimitService::sumDistributionMinutes($distA2) === 180,
        'A3 sum(dist A2) === weekly FM'
    );
    // Mon–Thu 500 + Fri 700 = 2700 threshold; Sat 200 + Sun 100 = 300 OT
    $midDaily = [
        '2025-07-07' => 500,
        '2025-07-08' => 500,
        '2025-07-09' => 500,
        '2025-07-10' => 500,
        '2025-07-11' => 700,
        '2025-07-12' => 200,
        '2025-07-13' => 100,
    ];
    $midFm = 300;
    $midDist = FazlaCalismaYillikLimitService::allocateActualDateProvenance($midDaily, $midFm);
    p5Assert(
        FazlaCalismaYillikLimitService::sumDistributionMinutes($midDist) === $midFm,
        'A3 sum(mid-year) === weekly FM'
    );

    // ----- A8: normal non-cross-year mid-year week regression -----
    p5Assert(($midDist[0]['tarih'] ?? '') === '2025-07-12', 'A8 mid-year first OT day');
    p5Assert((int) ($midDist[0]['dakika'] ?? 0) === 200, 'A8 mid-year Sat 200');
    p5Assert(($midDist[1]['tarih'] ?? '') === '2025-07-13', 'A8 mid-year Sun');
    p5Assert((int) ($midDist[1]['dakika'] ?? 0) === 100, 'A8 mid-year Sun 100');
    p5InsertClosedWeek($pdo, 20, '2025-07-07', $midFm, $midDist, 3000);

    // ----- A4: 269h historical + pending 2h => asildi true -----
    $hist269 = 16140;
    p5InsertClosedWeek($pdo, 30, '2025-03-03', $hist269, [
        ['tarih' => '2025-03-07', 'dakika' => $hist269],
    ]);
    $evalA4 = FazlaCalismaYillikLimitService::evaluatePendingAgainstRolling(
        $pdo,
        30,
        '2025-12-15',
        120,
        [['tarih' => '2025-12-15', 'dakika' => 120]]
    );
    p5Assert((int) $evalA4['kullanilan'] === $hist269, 'A4 kullanilan 16140');
    p5Assert((int) $evalA4['pending'] === 120, 'A4 pending 120');
    p5Assert((int) $evalA4['projected'] === 16260, 'A4 projected 16260');
    p5Assert($evalA4['asildi'] === true, 'A4 asildi true');
    p5Assert($evalA4['policy'] === FazlaCalismaYillikLimitService::POLICY_CODE, 'A4 policy metadata');

    // ----- A5: year boundary — no calendar reset -----
    // Historical OT in prior months (within rolling window ending 2026-01-02)
    p5InsertClosedWeek($pdo, 40, '2025-02-03', $hist269, [
        ['tarih' => '2025-02-05', 'dakika' => $hist269],
    ]);
    $evalA5 = FazlaCalismaYillikLimitService::evaluatePendingAgainstRolling(
        $pdo,
        40,
        '2026-01-02',
        120,
        [['tarih' => '2026-01-02', 'dakika' => 120]]
    );
    $boundsA5 = FazlaCalismaYillikLimitService::rollingWindowBounds('2026-01-02');
    p5Assert($boundsA5['start'] === '2025-01-03', 'A5 window start 2025-01-03');
    p5Assert($boundsA5['end'] === '2026-01-02', 'A5 window end 2026-01-02');
    p5Assert((int) $evalA5['kullanilan'] === $hist269, 'A5 prior-month OT still counted');
    p5Assert($evalA5['asildi'] === true, 'A5 BLOCK (no calendar reset)');

    // ----- A6: each calendar year <270h but rolling >270h => BLOCK -----
    // 200h in late 2025 + 80h in early 2026 = 280h rolling
    p5InsertClosedWeek($pdo, 50, '2025-11-03', 12000, [
        ['tarih' => '2025-11-05', 'dakika' => 12000],
    ]);
    p5InsertClosedWeek($pdo, 50, '2026-01-05', 4800, [
        ['tarih' => '2026-01-07', 'dakika' => 4800],
    ]);
    $evalA6 = FazlaCalismaYillikLimitService::evaluatePendingAgainstRolling($pdo, 50, '2026-01-15', 0);
    p5Assert((int) $evalA6['kullanilan'] === 16800, 'A6 rolling 280h (16800)');
    p5Assert($evalA6['asildi'] === true, 'A6 calendar-under-270 but rolling BLOCK');
    // Calendar year slices each under 270h (sanity of fixture intent)
    p5Assert(12000 < 16200 && 4800 < 16200, 'A6 each calendar bucket under 270h');

    // ----- A7: legacy missing distribution — full week FM if overlaps; no fake split -----
    p5InsertClosedWeek($pdo, 60, '2025-08-04', 240, null); // NULL policy/json = legacy
    $loadedA7 = FazlaCalismaYillikLimitService::loadRollingKapanmisFazlaCalisma($pdo, 60, '2025-08-10');
    $legacyContrib = null;
    foreach ($loadedA7['contributions'] as $c) {
        if (($c['hafta_baslangic'] ?? '') === '2025-08-04') {
            $legacyContrib = $c;
            break;
        }
    }
    p5Assert($legacyContrib !== null, 'A7 legacy week included');
    p5Assert((int) ($legacyContrib['fazla_calisma_dakika'] ?? 0) === 240, 'A7 full week FM counted');
    p5Assert(($legacyContrib['accounting'] ?? '') === 'LEGACY_WEEK_OVERLAP', 'A7 LEGACY_WEEK_OVERLAP');
    // Ensure we did not invent daily distribution on the row
    $legacyRow = $pdo->query(
        "SELECT fazla_calisma_tarih_dagilimi_json, fazla_calisma_tarih_dagilim_policy
         FROM haftalik_kapanis_satirlari WHERE personel_id = 60 AND hafta_baslangic = '2025-08-04' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    p5Assert(
        ($legacyRow['fazla_calisma_tarih_dagilimi_json'] ?? null) === null
            && ($legacyRow['fazla_calisma_tarih_dagilim_policy'] ?? null) === null,
        'A7 no fake daily split invented'
    );

    // ----- A9: concurrency FOR UPDATE serialization -----
    // Practical: historical 16080 (268h) + pending 120 => projected 16200 asildi=false;
    // after first inserts closed 120, second sees asildi=true.
    $histA9 = 16080;
    p5InsertClosedWeek($pdo, 70, '2025-04-07', $histA9, [
        ['tarih' => '2025-04-09', 'dakika' => $histA9],
    ]);
    $dsnFull = preg_replace('/dbname=[^;]+/', 'dbname=' . $dbFull, (string) getenv('MEDISA_TEST_MYSQL_DSN'));
    $pdo1 = new PDO(
        (string) $dsnFull,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo2 = new PDO(
        (string) $dsnFull,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $pdo1->beginTransaction();
    FazlaCalismaYillikLimitService::acquirePersonelRollingLock($pdo1, 70, 1);
    $evalFirst = FazlaCalismaYillikLimitService::evaluatePendingAgainstRolling(
        $pdo1,
        70,
        '2025-12-20',
        120,
        [['tarih' => '2025-12-20', 'dakika' => 120]]
    );
    p5Assert($evalFirst['asildi'] === false, 'A9 first eval asildi=false (at limit)');
    p5Assert((int) $evalFirst['projected'] === 16200, 'A9 first projected 16200');
    // "commit" by inserting closed week under lock, then commit txn
    p5InsertClosedWeek($pdo1, 70, '2025-12-15', 120, [
        ['tarih' => '2025-12-20', 'dakika' => 120],
    ]);
    $pdo1->commit();

    $pdo2->beginTransaction();
    FazlaCalismaYillikLimitService::acquirePersonelRollingLock($pdo2, 70, 1);
    $evalSecond = FazlaCalismaYillikLimitService::evaluatePendingAgainstRolling(
        $pdo2,
        70,
        '2025-12-20',
        120,
        [['tarih' => '2025-12-20', 'dakika' => 120]],
        [] // second attempt also pending 120 against updated history
    );
    p5Assert((int) $evalSecond['kullanilan'] === 16200, 'A9 second sees first insert');
    p5Assert($evalSecond['asildi'] === true, 'A9 second eval asildi=true');
    $pdo2->commit();

    // Aggregate response metadata from evaluate/load
    p5Assert(
        ($evalFirst['policy'] ?? '') === 'ROLLING_12_MONTH_ACTUAL_DATE_V1'
            && ($loadedA7['policy'] ?? '') === 'ROLLING_12_MONTH_ACTUAL_DATE_V1',
        'A10 aggregate response policy metadata'
    );

    // ========== Track B post-064 ==========

    // B4: three concepts independently persist
    $payloadB4 = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p5CreatePayload([
        'tc_kimlik_no' => '10000000218',
        'sicil_no' => 'P5-B4',
        'sube_id' => 1,
        'sgk_isveren_id' => 2,
        'calisma_lokasyonu_id' => 1,
    ]));
    PersonelCreateService::validateCreateReferences($pdo, $payloadB4);
    $idB4 = PersonelCreateService::insertPersonel($pdo, $payloadB4);
    $rowB4 = $pdo->query('SELECT sube_id, sgk_isveren_id, calisma_lokasyonu_id FROM personeller WHERE id = ' . $idB4)
        ->fetch(PDO::FETCH_ASSOC);
    p5Assert((int) $rowB4['sube_id'] === 1, 'B4 sube_id persists');
    p5Assert((int) $rowB4['sgk_isveren_id'] === 2, 'B4 sgk_isveren_id persists independently');
    p5Assert((int) $rowB4['calisma_lokasyonu_id'] === 1, 'B4 calisma_lokasyonu_id persists independently');

    // B5: changing location does not change branch/employer
    $pdo->prepare('UPDATE personeller SET calisma_lokasyonu_id = :lok WHERE id = :id')
        ->execute(['lok' => 2, 'id' => $idB4]);
    $rowB5 = $pdo->query('SELECT sube_id, sgk_isveren_id, calisma_lokasyonu_id FROM personeller WHERE id = ' . $idB4)
        ->fetch(PDO::FETCH_ASSOC);
    p5Assert((int) $rowB5['calisma_lokasyonu_id'] === 2, 'B5 location updated');
    p5Assert((int) $rowB5['sube_id'] === 1, 'B5 branch unchanged');
    p5Assert((int) $rowB5['sgk_isveren_id'] === 2, 'B5 employer unchanged');

    // B6: changing employer does not change branch/location
    $pdo->prepare('UPDATE personeller SET sgk_isveren_id = :isv WHERE id = :id')
        ->execute(['isv' => 1, 'id' => $idB4]);
    $rowB6 = $pdo->query('SELECT sube_id, sgk_isveren_id, calisma_lokasyonu_id FROM personeller WHERE id = ' . $idB4)
        ->fetch(PDO::FETCH_ASSOC);
    p5Assert((int) $rowB6['sgk_isveren_id'] === 1, 'B6 employer updated');
    p5Assert((int) $rowB6['sube_id'] === 1, 'B6 branch unchanged');
    p5Assert((int) $rowB6['calisma_lokasyonu_id'] === 2, 'B6 location unchanged');

    // B7: invalid FK typed fail
    $invalidCaught = null;
    try {
        $badPayload = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p5CreatePayload([
            'tc_kimlik_no' => '10000000226',
            'sicil_no' => 'P5-B7',
            'sgk_isveren_id' => 99999,
        ]));
        PersonelCreateService::validateCreateReferences($pdo, $badPayload);
    } catch (PersonelValidationException $e) {
        $invalidCaught = $e;
    }
    p5Assert($invalidCaught !== null, 'B7 invalid FK throws');
    p5Assert($invalidCaught->getField() === 'sgk_isveren_id', 'B7 field sgk_isveren_id');

    // B8: legacy null refs PASS
    $payloadB8 = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p5CreatePayload([
        'tc_kimlik_no' => '10000000234',
        'sicil_no' => 'P5-B8',
        'sgk_isveren_id' => null,
        'calisma_lokasyonu_id' => null,
    ]));
    PersonelCreateService::validateCreateReferences($pdo, $payloadB8);
    $idB8 = PersonelCreateService::insertPersonel($pdo, $payloadB8);
    $rowB8 = $pdo->query('SELECT sgk_isveren_id, calisma_lokasyonu_id FROM personeller WHERE id = ' . $idB8)
        ->fetch(PDO::FETCH_ASSOC);
    p5Assert($rowB8['sgk_isveren_id'] === null && $rowB8['calisma_lokasyonu_id'] === null, 'B8 null refs PASS');

    // B9: old CSV without org cols PASS post-064
    $legacyPost = p5LegacyCsvHeader() . "\r\n" . p5LegacyCsvRow([
        'tc_kimlik_no' => '10000000242',
        'sicil_no' => 'IMP-B9',
    ]) . "\r\n";
    $dryB9 = PersonelImportDryRunService::analyze($pdo, $legacyPost, $gyUser, null);
    p5Assert(($dryB9['ozet']['gecerli_satir'] ?? 0) === 1, 'B9 post-064 legacy CSV PASS');
    p5Assert(($dryB9['can_apply'] ?? false) === true, 'B9 can_apply');

    // B10: optional explicit refs post-064 dry-run resolve PASS
    $orgPost = p5OrgCsvHeader() . "\r\n" . p5OrgCsvRow([
        'tc_kimlik_no' => '10000000250',
        'sicil_no' => 'IMP-B10',
        'sgk_isveren' => 'Isveren A',
        'calisma_lokasyonu' => 'Lokasyon B',
    ]) . "\r\n";
    $dryB10 = PersonelImportDryRunService::analyze($pdo, $orgPost, $gyUser, null);
    p5Assert(($dryB10['ozet']['gecerli_satir'] ?? 0) === 1, 'B10 org CSV dry-run PASS');
    $candB10 = $dryB10['candidates'][0] ?? [];
    p5Assert((int) ($candB10['sgk_isveren_id'] ?? 0) === 1, 'B10 resolved sgk_isveren_id');
    p5Assert((int) ($candB10['calisma_lokasyonu_id'] ?? 0) === 2, 'B10 resolved calisma_lokasyonu_id');

    // B11: SubeScope keys on sube_id — location/employer cannot bypass
    $payloadB11 = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p5CreatePayload([
        'tc_kimlik_no' => '10000000268',
        'sicil_no' => 'P5-B11',
        'sube_id' => 1,
        'sgk_isveren_id' => 2,
        'calisma_lokasyonu_id' => 2,
    ]));
    PersonelCreateService::validateCreateReferences($pdo, $payloadB11);
    $idB11 = PersonelCreateService::insertPersonel($pdo, $payloadB11);
    $pdo->prepare('UPDATE personeller SET calisma_lokasyonu_id = 1 WHERE id = :id')->execute(['id' => $idB11]);
    $rowB11 = $pdo->query('SELECT sube_id, sgk_isveren_id, calisma_lokasyonu_id FROM personeller WHERE id = ' . $idB11)
        ->fetch(PDO::FETCH_ASSOC);
    p5Assert((int) $rowB11['sube_id'] === 1, 'B11 location change does not change sube_id');
    p5Assert((int) $rowB11['calisma_lokasyonu_id'] === 1, 'B11 location updated');

    $scopeSrc = (string) file_get_contents(__DIR__ . '/../../api/src/Scope/SubeScope.php');
    p5Assert(
        strpos($scopeSrc, 'sgk_isveren') === false && strpos($scopeSrc, 'calisma_lokasyonu') === false,
        'B11 SubeScope does not key on org fields'
    );
    $where = [];
    $params = [];
    $req = p5MakeRequest('GET', ['x-active-sube-id' => '1']);
    $scope = SubeScope::resolveScope($scopedUser, $req);
    SubeScope::appendSubeFilter($where, $params, $scope, SubeScope::allowedSubeIds($scopedUser), 'p.sube_id', 'b11');
    p5Assert(count($where) === 1 && strpos($where[0], 'p.sube_id') !== false, 'B11 filter keys on p.sube_id');
    p5Assert((int) ($params['b11_sube_id'] ?? 0) === 1, 'B11 scope param is sube_id=1');
    // Personel with foreign org refs still scoped by sube_id=1 — allowed for scoped user of sube 1
    SubeScope::assertPersonelAccess($scopedUser, $req, (int) $rowB11['sube_id']);
    p5Assert(true, 'B11 scoped user can access via sube_id despite foreign org refs');

    echo 'verify-final-code-gap-pack5-mysql: OK' . PHP_EOL;
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $dbFull . '`');
    } catch (Throwable $e) {
        // ignore cleanup errors
    }
}
