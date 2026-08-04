<?php

declare(strict_types=1);

/**
 * S97-B MariaDB concurrency/idempotency acceptance for personel import apply.
 * Requires MEDISA_TEST_MYSQL_DSN and MEDISA_TEST_MYSQL_USER.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelImportApplyService;
use Medisa\Api\Services\Personel\PersonelImportDryRunService;
use Medisa\Api\Services\Personel\PersonelImportException;

function s97bConcAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s97bConcPdo(string $dsn): PDO
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

/** @return list<string> */
function s97bConcSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function s97bConcApplyMigration(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s97bConcSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s97bConcCountPersonel(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
}

function s97bConcValidRow(array $overrides = []): string
{
    $row = [
        'tc_kimlik_no' => '10000000146',
        'sicil_no' => 'CONC-001',
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
    ];
    foreach ($overrides as $key => $value) {
        $row[$key] = $value;
    }
    $ordered = [];
    foreach (PersonelImportDryRunService::TEMPLATE_COLUMNS as $col) {
        $ordered[] = (string) ($row[$col] ?? '');
    }

    return implode(';', $ordered);
}

function s97bConcTwoRowCsv(): string
{
    return implode(';', PersonelImportDryRunService::TEMPLATE_COLUMNS) . "\r\n"
        . s97bConcValidRow(['tc_kimlik_no' => '10000000146', 'sicil_no' => 'CONC-001']) . "\r\n"
        . s97bConcValidRow(['tc_kimlik_no' => '10000000154', 'sicil_no' => 'CONC-002', 'ad' => 'Mehmet', 'soyad' => 'Demir']) . "\r\n";
}

/** @return array<string, mixed> */
function s97bConcApplyInput(string $idempotencyKey, string $manifestHash): array
{
    return [
        'confirmation' => PersonelImportApplyService::CONFIRMATION_TOKEN,
        'idempotency_key' => $idempotencyKey,
        'manifest_hash' => $manifestHash,
    ];
}

function s97bConcNowMs(): string
{
    $micro = microtime(true);
    $seconds = (int) floor($micro);
    $millis = (int) round(($micro - $seconds) * 1000);
    if ($millis >= 1000) {
        $seconds++;
        $millis = 0;
    }

    return gmdate('Y-m-d H:i:s', $seconds) . sprintf('.%03d', $millis);
}

$adminDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$userName = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
if ($adminDsn === '' || $userName === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN/USER required for S97-B personel import apply concurrency MariaDB acceptance\n");
    exit(1);
}

if (!extension_loaded('pdo_mysql') && !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "pdo_mysql driver missing\n");
    exit(1);
}

$admin = s97bConcPdo($adminDsn);
$database = 'medisa_s97b_personel_import_apply_conc_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $adminDsn);
    $pdo = s97bConcPdo((string) $dsn);

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
          tc_kimlik_no CHAR(11) NOT NULL,
          ad VARCHAR(80) NOT NULL,
          soyad VARCHAR(80) NOT NULL,
          dogum_tarihi DATE NOT NULL,
          telefon VARCHAR(32) NOT NULL,
          acil_durum_kisi VARCHAR(120) NULL,
          acil_durum_telefon VARCHAR(32) NULL,
          sicil_no VARCHAR(32) NOT NULL,
          ise_giris_tarihi DATE NOT NULL,
          sube_id INT UNSIGNED NOT NULL,
          departman_id INT UNSIGNED NULL,
          gorev_id INT UNSIGNED NULL,
          personel_tipi_id INT UNSIGNED NULL,
          bagli_amir_id INT UNSIGNED NULL,
          aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          dogum_yeri VARCHAR(80) NULL,
          kan_grubu VARCHAR(8) NULL,
          ucret_tipi_id INT UNSIGNED NULL,
          maas_tutari DECIMAL(12,2) NULL,
          prim_kurali_id INT UNSIGNED NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_personeller_tc (tc_kimlik_no)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    s97bConcApplyMigration($pdo, '046_personel_import_apply_owner.sql');

    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'İdari İşler')");
    $pdo->exec("INSERT INTO gorevler (id, ad) VALUES (2, 'Asistan')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad) VALUES (1, 'Tam Zamanli')");
    $pdo->exec('INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1, 1)');

    $gyUser = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $baselinePersonel = s97bConcCountPersonel($pdo);

    // 1) Same idempotency key: first apply +2; second idempotent_replay; total +2 once
    $twoRowCsv = s97bConcTwoRowCsv();
    $dry = PersonelImportDryRunService::dryRun($pdo, $twoRowCsv, $gyUser, null);
    $manifest = (string) $dry['manifest_hash'];
    $idemKey = 's97b.conc.idem01';

    $first = PersonelImportApplyService::apply(
        $pdo,
        $twoRowCsv,
        $gyUser,
        s97bConcApplyInput($idemKey, $manifest),
        null
    );
    s97bConcAssert(($first['idempotent_replay'] ?? true) === false, 'first apply not replay');
    s97bConcAssert(s97bConcCountPersonel($pdo) - $baselinePersonel === 2, 'first apply personel +2');

    $second = PersonelImportApplyService::apply(
        $pdo,
        $twoRowCsv,
        $gyUser,
        s97bConcApplyInput($idemKey, $manifest),
        null
    );
    s97bConcAssert(($second['idempotent_replay'] ?? false) === true, 'second apply idempotent_replay');
    s97bConcAssert(s97bConcCountPersonel($pdo) - $baselinePersonel === 2, 'idempotency personel +2 total only once');

    // 2) In-progress CLAIMED row blocks concurrent apply with same key
    $claimedKey = 's97b.conc.claimed01';
    $claimedCsv = implode(';', PersonelImportDryRunService::TEMPLATE_COLUMNS) . "\r\n"
        . s97bConcValidRow(['tc_kimlik_no' => '10000000162', 'sicil_no' => 'CONC-C01']) . "\r\n";
    $claimedDry = PersonelImportDryRunService::dryRun($pdo, $claimedCsv, $gyUser, null);
    $claimedManifest = (string) $claimedDry['manifest_hash'];
    $claimedSource = (string) $claimedDry['source_sha256'];
    $beforeClaimedPersonel = s97bConcCountPersonel($pdo);

    $pdo->prepare(
        'INSERT INTO personel_import_runs (
            idempotency_key, source_sha256, manifest_hash, schema_version,
            actor_id, actor_rol, active_sube_id, status,
            toplam_satir, gecerli_satir, created_count, started_at
        ) VALUES (
            :idempotency_key, :source_sha256, :manifest_hash, :schema_version,
            :actor_id, :actor_rol, :active_sube_id, \'CLAIMED\',
            :toplam_satir, :gecerli_satir, 0, :started_at
        )'
    )->execute([
        'idempotency_key' => $claimedKey,
        'source_sha256' => $claimedSource,
        'manifest_hash' => $claimedManifest,
        'schema_version' => PersonelImportDryRunService::SCHEMA_VERSION,
        'actor_id' => 1,
        'actor_rol' => 'GENEL_YONETICI',
        'active_sube_id' => null,
        'toplam_satir' => 1,
        'gecerli_satir' => 1,
        'started_at' => s97bConcNowMs(),
    ]);

    try {
        PersonelImportApplyService::apply(
            $pdo,
            $claimedCsv,
            $gyUser,
            s97bConcApplyInput($claimedKey, $claimedManifest),
            null
        );
        s97bConcAssert(false, 'claimed key apply should fail');
    } catch (PersonelImportException $e) {
        s97bConcAssert(
            $e->getCodeString() === 'PERSONEL_IMPORT_TRANSACTION_FAILED',
            'claimed key PERSONEL_IMPORT_TRANSACTION_FAILED'
        );
    }
    s97bConcAssert(s97bConcCountPersonel($pdo) === $beforeClaimedPersonel, 'claimed key personel unchanged');

    // 3) Different keys, same TC CSV: first +1; second ALREADY_EXISTS; no duplicate personel
    $dupTc = '10000000170';
    $dupCsv = implode(';', PersonelImportDryRunService::TEMPLATE_COLUMNS) . "\r\n"
        . s97bConcValidRow(['tc_kimlik_no' => $dupTc, 'sicil_no' => 'CONC-D01']) . "\r\n";
    $dupDry = PersonelImportDryRunService::dryRun($pdo, $dupCsv, $gyUser, null);
    $dupManifest = (string) $dupDry['manifest_hash'];
    $beforeDupPersonel = s97bConcCountPersonel($pdo);

    PersonelImportApplyService::apply(
        $pdo,
        $dupCsv,
        $gyUser,
        s97bConcApplyInput('s97b.conc.dupkey01', $dupManifest),
        null
    );
    s97bConcAssert(s97bConcCountPersonel($pdo) - $beforeDupPersonel === 1, 'dup TC first key personel +1');

    try {
        PersonelImportApplyService::apply(
            $pdo,
            $dupCsv,
            $gyUser,
            s97bConcApplyInput('s97b.conc.dupkey02', $dupManifest),
            null
        );
        s97bConcAssert(false, 'dup TC second key should fail');
    } catch (PersonelImportException $e) {
        s97bConcAssert(
            in_array(
                $e->getCodeString(),
                [
                    'PERSONEL_IMPORT_ALREADY_EXISTS',
                    'PERSONEL_IMPORT_NOT_APPLICABLE',
                    'PERSONEL_IMPORT_MANIFEST_CHANGED',
                ],
                true
            ),
            'dup TC second key duplicate rejection'
        );
    }

    $tcCountStmt = $pdo->prepare('SELECT COUNT(*) FROM personeller WHERE tc_kimlik_no = :tc');
    $tcCountStmt->execute(['tc' => $dupTc]);
    s97bConcAssert((int) $tcCountStmt->fetchColumn() === 1, 'no duplicate personel for same TC');

    echo 'verify-s97b-personel-import-apply-concurrency-mysql: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
