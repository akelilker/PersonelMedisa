<?php

declare(strict_types=1);

/**
 * S97-D MariaDB acceptance: personel import reference pack (read-only).
 * Requires MEDISA_TEST_MYSQL_DSN and MEDISA_TEST_MYSQL_USER.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Http\CsvResponse;
use Medisa\Api\Services\Personel\PersonelImportDryRunService;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelImportReferenceCatalogService;

function s97dAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s97dPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function s97dCount(PDO $pdo, string $table): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}

/** @return array{header: list<string>, rows: list<array<string,string>>, body: string} */
function s97dParseCsvBody(string $csvWithBom): array
{
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $csvWithBom) ?? $csvWithBom;
    $lines = preg_split("/\r\n|\n|\r/", rtrim($body, "\r\n")) ?: [];
    $header = str_getcsv((string) array_shift($lines), ';');
    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, ';');
        $row = [];
        foreach ($header as $i => $col) {
            $row[(string) $col] = (string) ($cells[$i] ?? '');
        }
        $rows[] = $row;
    }

    return ['header' => $header, 'rows' => $rows, 'body' => $body];
}

$adminDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$userName = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
if ($adminDsn === '' || $userName === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN/USER required for S97-D reference pack MariaDB acceptance\n");
    exit(1);
}

if (!extension_loaded('pdo_mysql') && !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "pdo_mysql driver missing\n");
    exit(1);
}

$admin = s97dPdo($adminDsn);
$database = 'medisa_s97d_ref_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $adminDsn);
    putenv('MEDISA_TEST_MYSQL_DSN=' . $dsn);
    $_ENV['MEDISA_TEST_MYSQL_DSN'] = $dsn;
    $pdo = s97dPdo((string) $dsn);

    $pdo->exec("
        CREATE TABLE subeler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          kod VARCHAR(32) NOT NULL,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id),
          UNIQUE KEY uq_subeler_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE departmanlar (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE gorevler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_tipleri (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE sube_departmanlar (
          sube_id INT UNSIGNED NOT NULL,
          departman_id INT UNSIGNED NOT NULL,
          PRIMARY KEY (sube_id, departman_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personeller (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          tc_kimlik_no CHAR(11) NULL,
          ad VARCHAR(80) NOT NULL,
          soyad VARCHAR(80) NULL,
          dogum_tarihi DATE NULL,
          telefon VARCHAR(32) NULL,
          sicil_no VARCHAR(64) NOT NULL,
          calisan_kapsami ENUM('IC_PERSONEL','DIS_KAYNAK') NOT NULL DEFAULT 'IC_PERSONEL',
          PRIMARY KEY (id),
          UNIQUE KEY uq_personeller_tc (tc_kimlik_no),
          UNIQUE KEY uq_personeller_sicil (sicil_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_ucret_gecmisi (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          personel_id INT UNSIGNED NOT NULL,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_bordro_kapsamlari (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_bordro_devirleri (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE sgk_personel_sigortalilik_surumleri (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_import_runs (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personel_import_run_satirlari (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES
      (2, 'SB2', 'Sube 2', 'AKTIF'),
      (1, 'MRK', 'Merkez', 'AKTIF'),
      (3, 'PAS', 'Pasif Sube', 'PASIF'),
      (4, 'DUP1', 'Cift Sube', 'AKTIF'),
      (5, 'DUP2', 'Cift Sube', 'AKTIF')
    ");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES
      (2, 'Klinik', 'AKTIF'),
      (1, 'Idari', 'AKTIF'),
      (3, 'Pasif Dep', 'PASIF'),
      (4, '=FormulaDept', 'AKTIF'),
      (5, 'Pazarlama', 'AKTIF')
    ");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES
      (2, 'Asistan', 'AKTIF'),
      (1, 'Uzman', 'AKTIF'),
      (3, 'Uzman', 'AKTIF')
    ");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES
      (1, 'Tam Zamanli', 'AKTIF'),
      (2, 'Yari Zamanli', 'AKTIF')
    ");
    $pdo->exec('INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1, 1), (1, 2), (2, 2), (1, 4)');
    $pdo->exec("INSERT INTO personeller (tc_kimlik_no, ad, soyad, sicil_no) VALUES ('11111111110', 'Gizli', 'Personel', 'EXIST-001')");

    $gyUser = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $scopedUser = ['id' => 2, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];

    $watchTables = [
        'personel_import_runs',
        'personel_import_run_satirlari',
        'personeller',
        'personel_ucret_gecmisi',
        'personel_bordro_kapsamlari',
        'personel_bordro_devirleri',
        'sgk_personel_sigortalilik_surumleri',
        'subeler',
        'departmanlar',
        'gorevler',
        'personel_tipleri',
        'sube_departmanlar',
    ];
    $before = [];
    foreach ($watchTables as $table) {
        $before[$table] = s97dCount($pdo, $table);
    }

    s97dAssert(PersonelImportReferenceCatalogService::schemaReady($pdo) === true, 'schemaReady true');
    s97dAssert(CsvResponse::cell('=CMD') === "'=CMD", 'CsvResponse formula guard shared');
    s97dAssert(CsvResponse::cell(' =1+1') === "' =1+1", 'CsvResponse leading-space formula guard');
    s97dAssert(CsvResponse::cell("\tCMD") === "'\tCMD", 'CsvResponse tab formula guard');
    s97dAssert(CsvResponse::cell('hello;world', ',') === 'hello;world', 'comma CSV does not quote bare semicolon');
    s97dAssert(CsvResponse::cell('hello;world', ';') === '"hello;world"', 'semicolon CSV quotes delimiter');

    // Historical parent fixture stays frozen; current validation and template compatibility are checked below.
    $goldenPath = __DIR__ . '/../fixtures/s97d/personel-import-dry-run-parent-f9fd2af.golden.json';
    s97dAssert(is_file($goldenPath), 'frozen golden fixture present');
    $goldenRaw = file_get_contents($goldenPath);
    s97dAssert(is_string($goldenRaw) && $goldenRaw !== '', 'frozen golden fixture readable');
    $golden = json_decode($goldenRaw, true);
    s97dAssert(is_array($golden), 'frozen golden fixture JSON parse');
    s97dAssert(($golden['parent_sha'] ?? '') === 'f9fd2af1390550a18ad4b8c89cd397c9724614d8', 'golden parent_sha exact');
    s97dAssert(($golden['fixture_version'] ?? 0) === 1, 'golden fixture_version');
    s97dAssert(($golden['input_fixture_id'] ?? '') === 's97d-parity-valid-row-v1', 'golden input_fixture_id');

    $parityCsv = implode(';', PersonelImportDryRunService::TEMPLATE_COLUMNS) . "\r\n"
        . implode(';', [
            '10000000146',
            'IMP-PARITY-001',
            'Ayşe',
            'Yılmaz',
            '1990-05-15',
            'Ankara',
            '0532 111 22 33',
            'A Rh+',
            'Ali Yılmaz',
            '0532 444 55 66',
            '2024-01-10',
            'Merkez',
            'Idari',
            'Asistan',
            'Tam Zamanli',
        ]) . "\r\n";
    $currentDry = PersonelImportDryRunService::analyze($pdo, $parityCsv, $gyUser, null);
    // The historical fixture predates mandatory SGK for active internal personnel.
    // Preserve its bytes; prove the current contract rejects it without writing.
    s97dAssert(($currentDry['source_sha256'] ?? '') === (string) ($golden['source_sha256'] ?? ''), 'LEGACY_INPUT source sha unchanged');
    s97dAssert(($currentDry['headers'] ?? null) === ($golden['headers'] ?? null), 'LEGACY_INPUT headers unchanged');
    s97dAssert(($currentDry['schema_version'] ?? '') === ($golden['schema_version'] ?? ''), 'LEGACY_INPUT schema version unchanged');
    s97dAssert(($currentDry['can_apply'] ?? true) === false, 'LEGACY_INPUT cannot apply without SGK');
    s97dAssert(
        in_array('PERSONEL_SGK_ISVEREN_REQUIRED', $currentDry['satirlar'][0]['hata_kodlari'] ?? [], true),
        'LEGACY_INPUT explicit SGK required'
    );
    s97dAssert(($currentDry['candidates'] ?? []) === [], 'LEGACY_INPUT has no write candidates');
    $repeatDry = PersonelImportDryRunService::analyze($pdo, $parityCsv, $gyUser, null);
    s97dAssert($currentDry['manifest_hash'] === $repeatDry['manifest_hash'], 'LEGACY_INPUT rejection manifest deterministic');
    $frozenPayloadJson = json_encode($golden['candidate_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    s97dAssert(hash('sha256', (string) $frozenPayloadJson) === $golden['candidate_payload_sha256'], 'frozen parent payload checksum preserved');
    $currentTemplate = PersonelImportDryRunService::buildTemplateCsv();
    s97dAssert(
        hash('sha256', $currentTemplate) === (string) ($golden['template_sha256'] ?? ''),
        'MANIFEST_PARITY template sha'
    );
    s97dAssert(strlen($currentTemplate) === (int) ($golden['template_byte_length'] ?? -1), 'MANIFEST_PARITY template length');
    $goldenTemplate = base64_decode((string) ($golden['template_bytes_base64'] ?? ''), true);
    s97dAssert(is_string($goldenTemplate) && $currentTemplate === $goldenTemplate, 'MANIFEST_PARITY template bytes');
    echo '[PASS] LEGACY_INPUT_CURRENT_SGK_GUARD = PASS' . PHP_EOL;
    echo '[PASS] LEGACY_FIXTURE_RUNTIME = HERMETIC' . PHP_EOL;

    // Prove PERSONELLER_TABLE_READ=NO from catalog SQL inventory (export never queries personeller).
    $catalogSrc = file_get_contents(__DIR__ . '/../../api/src/Services/Personel/PersonelImportReferenceCatalogService.php');
    s97dAssert(is_string($catalogSrc) && !preg_match('/\bFROM\s+personeller\b|\bJOIN\s+personeller\b/i', $catalogSrc), 'PERSONELLER_TABLE_READ = NO');

    $export1 = PersonelImportReferenceCatalogService::buildExport($pdo, $gyUser, null);
    s97dAssert($export1['filename'] === 'yukleme-kilavuzu.csv', 'filename exact');
    s97dAssert(strncmp($export1['csv'], "\xEF\xBB\xBF", 3) === 0, 'UTF-8 BOM present');
    s97dAssert(strpos($export1['body'], 'bolum;baslik;deger;aciklama') === 0, 'semicolon header');
    s97dAssert(strlen($export1['sha256']) === 64 && ctype_xdigit($export1['sha256']), 'sha256 64 hex');
    s97dAssert($export1['sha256'] === hash('sha256', $export1['body']), 'sha256 is body hash without BOM');

    $parsed = s97dParseCsvBody($export1['csv']);
    s97dAssert($parsed['header'] === PersonelImportReferenceCatalogService::CSV_COLUMNS, 'exact columns');

    $types = [];
    foreach ($parsed['rows'] as $row) {
        $tur = $row['baslik'];
        if (in_array($tur, ['SUBE', 'DEPARTMAN', 'GOREV', 'PERSONEL_TIPI', 'CALISAN_KAPSAMI'], true) && !in_array($tur, $types, true)) {
            $types[] = $tur;
        }
    }
    s97dAssert(
        $types === ['SUBE', 'DEPARTMAN', 'GOREV', 'PERSONEL_TIPI', 'CALISAN_KAPSAMI'],
        'tur order SUBE/DEPARTMAN/GOREV/PERSONEL_TIPI/CALISAN_KAPSAMI'
    );

    $usableSubeler = [];
    foreach ($parsed['rows'] as $row) {
        if ($row['baslik'] === 'SUBE') {
            $usableSubeler[] = $row['deger'];
        }
    }
    s97dAssert(in_array('Merkez', $usableSubeler, true), 'active sube Merkez present');
    s97dAssert(in_array('Sube 2', $usableSubeler, true), 'active sube Sube 2 present');
    s97dAssert(!in_array('Pasif Sube', $usableSubeler, true), 'passive sube excluded');

    $cift = null;
    foreach ($parsed['rows'] as $row) {
        if ($row['baslik'] === 'SUBE' && $row['deger'] === 'Cift Sube') {
            $cift = $row;
            break;
        }
    }
    s97dAssert($cift === null, 'ambiguous branch omitted from usable guide');

    $formula = null;
    foreach ($parsed['rows'] as $row) {
        if ($row['baslik'] === 'DEPARTMAN' && strpos($row['deger'], 'FormulaDept') !== false) {
            $formula = $row;
            break;
        }
    }
    s97dAssert($formula !== null, 'formula dept present');
    s97dAssert(strpos($formula['deger'], "'=") === 0, 'formula injection guarded');

    $idariDept = null;
    $pazarlamaDept = null;
    $klinikDept = null;
    foreach ($parsed['rows'] as $row) {
        if ($row['baslik'] !== 'DEPARTMAN') {
            continue;
        }
        if ($row['deger'] === 'Idari') {
            $idariDept = $row;
        }
        if ($row['deger'] === 'Pazarlama') {
            $pazarlamaDept = $row;
        }
        if ($row['deger'] === 'Klinik') {
            $klinikDept = $row;
        }
    }
    s97dAssert($idariDept !== null, 'sparse matrix Idari usable');
    s97dAssert($idariDept['aciklama'] === '', 'sparse matrix Idari has no branch restriction');
    s97dAssert($pazarlamaDept !== null, 'unmapped Pazarlama still usable');
    s97dAssert($pazarlamaDept['aciklama'] === '', 'unmapped Pazarlama has no branch restriction');
    s97dAssert($klinikDept !== null && $klinikDept['aciklama'] === '', 'Klinik open not pair-scoped');
    echo '[PASS] REFERENCE_EXPORT_OPEN_MODEL = PASS' . PHP_EOL;

    $pazarlamaCsv = implode(';', array_merge(PersonelImportDryRunService::TEMPLATE_COLUMNS, ['calisan_kapsami'])) . "\r\n"
        . implode(';', [
            '10000000250',
            'IMP-PAZ-001',
            'Ayse',
            'Yilmaz',
            '1990-05-15',
            'Ankara',
            '05321112233',
            'A Rh+',
            'Ali',
            '05324445566',
            '2024-01-10',
            'Merkez',
            'Pazarlama',
            'Asistan',
            'Tam Zamanli',
            'DIS_KAYNAK',
        ]) . "\r\n";
    $pazDry = PersonelImportDryRunService::dryRun($pdo, $pazarlamaCsv, $gyUser, null);
    s97dAssert(($pazDry['satirlar'][0]['durum'] ?? '') === 'GECERLI', 'dry-run accepts unmapped Pazarlama');
    s97dAssert(
        !in_array('PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI', $pazDry['satirlar'][0]['hata_kodlari'] ?? [], true),
        'no ILISKISI for unmapped Pazarlama'
    );
    echo '[PASS] IMPORT_REFERENCE_PARITY = PASS' . PHP_EOL;

    s97dAssert(strpos($export1['csv'], 'tc_kimlik_no') === false, 'no tc column');
    s97dAssert(strpos($export1['csv'], '11111111110') === false, 'no raw TC digits');
    s97dAssert(strpos($export1['csv'], 'Gizli') === false, 'no personel names');
    s97dAssert(stripos($export1['csv'], 'idempotency') === false, 'no idempotency');

    $export2 = PersonelImportReferenceCatalogService::buildExport($pdo, $gyUser, null);
    s97dAssert($export1['csv'] === $export2['csv'], 'deterministic same bytes');
    s97dAssert($export1['sha256'] === $export2['sha256'], 'deterministic same sha');

    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (99, 'Beta Gorev', 'AKTIF')");
    $export3 = PersonelImportReferenceCatalogService::buildExport($pdo, $gyUser, null);
    $gorevOrder = [];
    foreach (s97dParseCsvBody($export3['csv'])['rows'] as $row) {
        if ($row['baslik'] === 'GOREV') {
            $gorevOrder[] = $row['deger'];
        }
    }
    $sorted = $gorevOrder;
    sort($sorted, SORT_STRING);
    s97dAssert($gorevOrder === $sorted, 'gorev rows sorted by deger independent of insert id');

    $scoped = PersonelImportReferenceCatalogService::buildExport($pdo, $scopedUser, null);
    s97dAssert($scoped['sha256'] !== $export1['sha256'], 'scope change changes hash');
    $scopedSubeler = [];
    foreach (s97dParseCsvBody($scoped['csv'])['rows'] as $row) {
        if ($row['baslik'] === 'SUBE') {
            $scopedSubeler[] = $row['deger'];
        }
    }
    s97dAssert($scopedSubeler === ['Merkez'], 'scoped user only Merkez usable sube');
    s97dAssert(strpos($scoped['csv'], 'Sube 2') === false, 'scoped csv has no Sube 2');

    $activeOnly = PersonelImportReferenceCatalogService::buildExport($pdo, $gyUser, '1');
    $activeSubeler = [];
    foreach (s97dParseCsvBody($activeOnly['csv'])['rows'] as $row) {
        if ($row['baslik'] === 'SUBE') {
            $activeSubeler[] = $row['deger'];
        }
    }
    s97dAssert($activeSubeler === ['Merkez'], 'active branch exports only Merkez');

    // Cross-scope duplicate: same exact name in-scope + out-of-scope.
    // Export usability must match global dry-run resolution (not scoped-id count).
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (10, 'MRK2', 'Merkez', 'AKTIF')");
    $crossExport = PersonelImportReferenceCatalogService::buildExport($pdo, $scopedUser, null);
    $crossMerkez = null;
    foreach (s97dParseCsvBody($crossExport['csv'])['rows'] as $row) {
        if ($row['baslik'] === 'SUBE' && $row['deger'] === 'Merkez') {
            $crossMerkez = $row;
            break;
        }
    }
    s97dAssert($crossMerkez === null, 'cross-scope ambiguous branch omitted from usable guide');
    s97dAssert(strpos($crossExport['csv'], 'MRK2') === false, 'cross-scope does not leak out-of-scope kod');
    s97dAssert(!preg_match('/(^|;)10(;|$)/', $crossExport['csv']), 'cross-scope does not leak raw id');
    $crossCsv = implode(';', PersonelImportDryRunService::TEMPLATE_COLUMNS) . "\r\n"
        . implode(';', [
            '10000000154',
            'IMP-XSCOPE-001',
            'Fatma',
            'Demir',
            '1991-06-20',
            'Izmir',
            '0532 111 22 44',
            'B Rh+',
            'Veli Demir',
            '0532 444 55 77',
            '2024-02-01',
            'Merkez',
            'Idari',
            'Asistan',
            'Tam Zamanli',
        ]) . "\r\n";
    $crossDry = PersonelImportDryRunService::dryRun($pdo, $crossCsv, $scopedUser, null);
    s97dAssert(
        in_array('PERSONEL_IMPORT_REFERANS_BELIRSIZ', $crossDry['satirlar'][0]['hata_kodlari'], true),
        'cross-scope dry-run BELIRSIZ matches export HAYIR'
    );
    echo '[PASS] EXPORT_USABILITY = DRY_RUN_RESOLUTION_RESULT' . PHP_EOL;
    $pdo->exec('DELETE FROM subeler WHERE id = 10');

    try {
        PersonelImportReferenceCatalogService::buildExport($pdo, $scopedUser, '2');
        s97dAssert(false, 'out-of-scope active branch should throw');
    } catch (PersonelImportException $e) {
        s97dAssert($e->getHttpStatus() === 403, 'out-of-scope active branch 403');
        s97dAssert($e->getCodeString() === 'PERSONEL_IMPORT_SUBE_SCOPE_IHLALI', 'out-of-scope code');
    }

    $pdo->exec('DELETE FROM sube_departmanlar');
    $openExport = PersonelImportReferenceCatalogService::buildExport($pdo, $gyUser, null);
    $openDept = null;
    foreach (s97dParseCsvBody($openExport['csv'])['rows'] as $row) {
        if ($row['baslik'] === 'DEPARTMAN' && $row['deger'] === 'Idari') {
            $openDept = $row;
            break;
        }
    }
    s97dAssert($openDept !== null, 'open model Idari row');
    s97dAssert($openDept['aciklama'] === '', 'open model has no branch restriction');

    // Import catalog must not depend on sube_departmanlar (sparse, empty, or missing table).
    $pdo->exec('RENAME TABLE sube_departmanlar TO sube_departmanlar_hidden');
    $hiddenExport = PersonelImportReferenceCatalogService::buildExport($pdo, $gyUser, null);
    $hiddenPazarlama = null;
    foreach (s97dParseCsvBody($hiddenExport['csv'])['rows'] as $row) {
        if ($row['baslik'] === 'DEPARTMAN' && $row['deger'] === 'Pazarlama') {
            $hiddenPazarlama = $row;
            break;
        }
    }
    s97dAssert($hiddenPazarlama !== null, 'missing mapping table still exports Pazarlama');
    s97dAssert($hiddenPazarlama['aciklama'] === '', 'missing mapping table still has no branch restriction');
    $hiddenDry = PersonelImportDryRunService::dryRun($pdo, $pazarlamaCsv, $gyUser, null);
    s97dAssert(($hiddenDry['satirlar'][0]['durum'] ?? '') === 'GECERLI', 'missing mapping table dry-run still GECERLI');
    echo '[PASS] IMPORT_INDEPENDENT_OF_SUBE_DEPARTMANLAR = VERIFIED' . PHP_EOL;
    $pdo->exec('RENAME TABLE sube_departmanlar_hidden TO sube_departmanlar');
    $pdo->exec('INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1, 1), (1, 2), (2, 2)');
    $csv = implode(';', PersonelImportDryRunService::TEMPLATE_COLUMNS) . "\r\n"
        . implode(';', [
            '10000000146',
            'IMP-001',
            'Ayse',
            'Yilmaz',
            '1990-05-15',
            'Ankara',
            '05321112233',
            'A Rh+',
            'Ali',
            '05324445566',
            '2024-01-10',
            'Merkez',
            'Idari',
            'Uzman',
            'Tam Zamanli',
        ]) . "\r\n";
    $dry = PersonelImportDryRunService::dryRun($pdo, $csv, $gyUser, null);
    s97dAssert(in_array('PERSONEL_IMPORT_REFERANS_BELIRSIZ', $dry['satirlar'][0]['hata_kodlari'], true), 'dry-run ambiguous gorev preserved');
    s97dAssert(($dry['can_apply'] ?? true) === false, 'dry-run can_apply false for ambiguous');
    s97dAssert(!array_key_exists('yazma', $dry) || ($dry['yazma']['personel_write'] ?? true) === false, 'dry-run personel_write false');

    $afterBusiness = [
        'personeller' => s97dCount($pdo, 'personeller'),
        'personel_import_runs' => s97dCount($pdo, 'personel_import_runs'),
        'personel_import_run_satirlari' => s97dCount($pdo, 'personel_import_run_satirlari'),
        'personel_ucret_gecmisi' => s97dCount($pdo, 'personel_ucret_gecmisi'),
        'personel_bordro_kapsamlari' => s97dCount($pdo, 'personel_bordro_kapsamlari'),
        'personel_bordro_devirleri' => s97dCount($pdo, 'personel_bordro_devirleri'),
        'sgk_personel_sigortalilik_surumleri' => s97dCount($pdo, 'sgk_personel_sigortalilik_surumleri'),
        'subeler' => s97dCount($pdo, 'subeler'),
        'departmanlar' => s97dCount($pdo, 'departmanlar'),
        'gorevler' => s97dCount($pdo, 'gorevler'),
        'personel_tipleri' => s97dCount($pdo, 'personel_tipleri'),
        'sube_departmanlar' => s97dCount($pdo, 'sube_departmanlar'),
    ];
    foreach ($afterBusiness as $table => $count) {
        // gorevler may include Beta Gorev insert earlier; compare against live before snapshot for writes.
        if (in_array($table, ['gorevler', 'subeler', 'sube_departmanlar'], true)) {
            continue;
        }
        s97dAssert($count === $before[$table], $table . ' delta 0');
    }
    // Explicit business write tables must stay at seed counts.
    s97dAssert($afterBusiness['personeller'] === $before['personeller'], 'personeller delta 0');
    s97dAssert($afterBusiness['personel_import_runs'] === $before['personel_import_runs'], 'personel_import_runs delta 0');
    s97dAssert($afterBusiness['sube_departmanlar'] === 3, 'sube_departmanlar restored mapped rows');

    $pdo->exec('DROP TABLE gorevler');
    s97dAssert(PersonelImportReferenceCatalogService::schemaReady($pdo) === false, 'schemaReady false missing gorevler');
    try {
        PersonelImportReferenceCatalogService::buildExport($pdo, $gyUser, null);
        s97dAssert(false, 'missing schema should throw');
    } catch (PersonelImportException $e) {
        s97dAssert($e->getCodeString() === 'SCHEMA_NOT_READY', 'SCHEMA_NOT_READY');
        s97dAssert($e->getHttpStatus() === 409, 'SCHEMA_NOT_READY http 409');
    }

    echo 'verify-s97d-personel-import-reference-mysql: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
