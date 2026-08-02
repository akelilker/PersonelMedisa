<?php

declare(strict_types=1);

/**
 * S97-B MariaDB acceptance: personel import apply (CREATE_ONLY_ALL_OR_NOTHING).
 * Requires MEDISA_TEST_MYSQL_DSN and MEDISA_TEST_MYSQL_USER.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelImportApplyService;
use Medisa\Api\Services\Personel\PersonelImportDryRunService;
use Medisa\Api\Services\Personel\PersonelImportException;

function s97bAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s97bPdo(string $dsn): PDO
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
function s97bSplitSql(string $sql): array
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

function s97bApplyMigration(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s97bSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function s97bCountPersonel(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
}

function s97bCountSalary(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personel_ucret_gecmisi')->fetchColumn();
}

function s97bTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function s97bCountOptional(PDO $pdo, string $table): int
{
    if (!s97bTableExists($pdo, $table)) {
        return 0;
    }

    return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
}

function s97bHeaderCsv(array $extra = []): string
{
    $cols = PersonelImportDryRunService::TEMPLATE_COLUMNS;
    if (count($extra) > 0) {
        $cols = array_merge($cols, $extra);
    }

    return implode(';', $cols);
}

function s97bValidRow(array $overrides = []): string
{
    $row = [
        'tc_kimlik_no' => '10000000146',
        'sicil_no' => 'IMP-001',
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

function s97bTwoRowCsv(array $rowOverrides = []): string
{
    $row1 = array_merge([
        'tc_kimlik_no' => '10000000146',
        'sicil_no' => 'IMP-001',
    ], $rowOverrides[0] ?? []);
    $row2 = array_merge([
        'tc_kimlik_no' => '10000000154',
        'sicil_no' => 'IMP-002',
        'ad' => 'Mehmet',
        'soyad' => 'Demir',
    ], $rowOverrides[1] ?? []);

    return s97bHeaderCsv() . "\r\n"
        . s97bValidRow($row1) . "\r\n"
        . s97bValidRow($row2) . "\r\n";
}

/** @return array<string, mixed> */
function s97bApplyInput(string $idempotencyKey, string $manifestHash, string $confirmation = PersonelImportApplyService::CONFIRMATION_TOKEN): array
{
    return [
        'confirmation' => $confirmation,
        'idempotency_key' => $idempotencyKey,
        'manifest_hash' => $manifestHash,
    ];
}

function s97bAssertNoRawTcKey($payload, string $name): void
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    s97bAssert(is_string($encoded) && strpos($encoded, '"tc_kimlik_no"') === false, $name);
}

function s97bRunStatus(PDO $pdo, string $idempotencyKey): ?string
{
    $stmt = $pdo->prepare(
        'SELECT status FROM personel_import_runs WHERE idempotency_key = :key ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute(['key' => $idempotencyKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? (string) $row['status'] : null;
}

function s97bAssertOptionalDeltas(PDO $pdo, int $beforeBordro, int $beforeDevir, int $beforeSgk, string $namePrefix): void
{
    $bordroDelta = s97bCountOptional($pdo, 'personel_bordro_kapsamlari') - $beforeBordro;
    $devirDelta = s97bCountOptional($pdo, 'personel_bordro_devirleri') - $beforeDevir;
    $sgkDelta = s97bCountOptional($pdo, 'personel_sgk_statuleri') - $beforeSgk;
    if (s97bTableExists($pdo, 'personel_bordro_kapsamlari')) {
        s97bAssert($bordroDelta === 0, $namePrefix . ' bordro delta 0');
    }
    if (s97bTableExists($pdo, 'personel_bordro_devirleri')) {
        s97bAssert($devirDelta === 0, $namePrefix . ' carryover delta 0');
    }
    if (s97bTableExists($pdo, 'personel_sgk_statuleri')) {
        s97bAssert($sgkDelta === 0, $namePrefix . ' sgk delta 0');
    }
}

$adminDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$userName = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
if ($adminDsn === '' || $userName === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN/USER required for S97-B personel import apply MariaDB acceptance\n");
    exit(1);
}

if (!extension_loaded('pdo_mysql') && !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "pdo_mysql driver missing\n");
    exit(1);
}

$admin = s97bPdo($adminDsn);
$database = 'medisa_s97b_personel_import_apply_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $adminDsn);
    putenv('MEDISA_TEST_MYSQL_DSN=' . $dsn);
    $_ENV['MEDISA_TEST_MYSQL_DSN'] = $dsn;
    $pdo = s97bPdo((string) $dsn);

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
          acil_durum_kisi VARCHAR(120) NOT NULL,
          acil_durum_telefon VARCHAR(32) NOT NULL,
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
    $pdo->exec("
        CREATE TABLE personel_ucret_gecmisi (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          personel_id INT UNSIGNED NOT NULL,
          ucret_tutari DECIMAL(12,2) NOT NULL,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS personel_bordro_kapsamlari (id INT PRIMARY KEY AUTO_INCREMENT)
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS personel_bordro_devirleri (id INT PRIMARY KEY AUTO_INCREMENT)
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS personel_sgk_statuleri (id INT PRIMARY KEY AUTO_INCREMENT)
    ');

    s97bApplyMigration($pdo, '046_personel_import_apply_owner.sql');

    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'İdari İşler'), (2, 'Klinik')");
    $pdo->exec("INSERT INTO gorevler (id, ad) VALUES (1, 'Uzman'), (2, 'Asistan'), (3, 'Uzman')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad) VALUES (1, 'Tam Zamanli'), (2, 'Yari Zamanli')");
    $pdo->exec('INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1, 1), (1, 2), (2, 2)');
    $pdo->exec("
        INSERT INTO personeller (
          tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
          sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id, aktif_durum
        ) VALUES (
          '11111111110', 'Mevcut', 'Personel', '1985-01-01', '05320000000', 'Acil', '05320000001',
          'EXIST-001', '2020-01-01', 1, 1, 1, 1, 'AKTIF'
        )
    ");
    $pdo->exec('INSERT INTO personel_ucret_gecmisi (personel_id, ucret_tutari) VALUES (1, 1000.00)');

    $gyUser = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $scopedUser = ['id' => 2, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];

    $baselinePersonel = s97bCountPersonel($pdo);
    $baselineSalary = s97bCountSalary($pdo);
    $baselineBordro = s97bCountOptional($pdo, 'personel_bordro_kapsamlari');
    $baselineDevir = s97bCountOptional($pdo, 'personel_bordro_devirleri');
    $baselineSgk = s97bCountOptional($pdo, 'personel_sgk_statuleri');

    // 1) Two valid rows dry-run → can_apply true, deterministic manifest
    $twoRowCsv = s97bTwoRowCsv();
    $dry1 = PersonelImportDryRunService::dryRun($pdo, $twoRowCsv, $gyUser, null);
    $dry2 = PersonelImportDryRunService::dryRun($pdo, $twoRowCsv, $gyUser, null);
    s97bAssert(($dry1['can_apply'] ?? false) === true, 'two-row dry-run can_apply true');
    s97bAssert(($dry1['ozet']['gecerli_satir'] ?? 0) === 2, 'two-row dry-run gecerli_satir 2');
    s97bAssert(
        ($dry1['manifest_hash'] ?? '') !== '' && $dry1['manifest_hash'] === $dry2['manifest_hash'],
        'deterministic manifest hash on repeat dry-run'
    );
    $successManifest = (string) $dry1['manifest_hash'];
    $successKey = 's97b.apply.success01';

    // 2) Apply success → personel +2, salary delta 0, optional tables delta 0
    $beforeApply = s97bCountPersonel($pdo);
    $beforeSalary = s97bCountSalary($pdo);
    $applyResult = PersonelImportApplyService::apply(
        $pdo,
        $twoRowCsv,
        $gyUser,
        s97bApplyInput($successKey, $successManifest),
        null
    );
    s97bAssert(($applyResult['status'] ?? '') === 'COMPLETED', 'apply success status COMPLETED');
    s97bAssert(($applyResult['created_count'] ?? 0) === 2, 'apply success created_count 2');
    s97bAssert(($applyResult['idempotent_replay'] ?? true) === false, 'apply success not replay');
    s97bAssert(s97bCountPersonel($pdo) - $beforeApply === 2, 'apply success personel delta +2');
    s97bAssert(s97bCountSalary($pdo) - $beforeSalary === 0, 'apply success salary delta 0');
    s97bAssertOptionalDeltas($pdo, $baselineBordro, $baselineDevir, $baselineSgk, 'apply success');

    // 3) Import audit COMPLETED, created_count=2, no raw TC in audit JSON
    $runStmt = $pdo->prepare(
        'SELECT status, created_count, created_personel_ids_json FROM personel_import_runs WHERE idempotency_key = :key LIMIT 1'
    );
    $runStmt->execute(['key' => $successKey]);
    $runRow = $runStmt->fetch(PDO::FETCH_ASSOC);
    s97bAssert(is_array($runRow) && ($runRow['status'] ?? '') === 'COMPLETED', 'import audit COMPLETED');
    s97bAssert((int) ($runRow['created_count'] ?? 0) === 2, 'import audit created_count 2');
    s97bAssertNoRawTcKey($runRow, 'import audit run row no raw tc_kimlik_no');
    $satirStmt = $pdo->prepare(
        'SELECT tc_kimlik_no_masked FROM personel_import_run_satirlari WHERE import_run_id = (
            SELECT id FROM personel_import_runs WHERE idempotency_key = :key LIMIT 1
        )'
    );
    $satirStmt->execute(['key' => $successKey]);
    $maskedRows = $satirStmt->fetchAll(PDO::FETCH_ASSOC);
    s97bAssert(count($maskedRows) === 2, 'import audit two satir rows');
    foreach ($maskedRows as $maskedRow) {
        s97bAssert(
            ($maskedRow['tc_kimlik_no_masked'] ?? '') !== '' && strpos((string) $maskedRow['tc_kimlik_no_masked'], '******') !== false,
            'import audit masked TC present'
        );
    }

    // 4) Atomicity: trigger removes gorev after first insert → all-or-nothing rollback, BASARISIZ
    $pdo->exec('DROP TRIGGER IF EXISTS s97b_atomicity_break_gorev');
    $pdo->exec("
        CREATE TRIGGER s97b_atomicity_break_gorev
        AFTER INSERT ON personeller
        FOR EACH ROW
        DELETE FROM gorevler WHERE id = 2
    ");
    $atomicCsv = s97bTwoRowCsv([
        ['tc_kimlik_no' => '10000000162', 'sicil_no' => 'IMP-A01'],
        ['tc_kimlik_no' => '10000000170', 'sicil_no' => 'IMP-A02'],
    ]);
    $atomicDry = PersonelImportDryRunService::dryRun($pdo, $atomicCsv, $gyUser, null);
    s97bAssert(($atomicDry['can_apply'] ?? false) === true, 'atomicity dry-run can_apply true');
    $atomicKey = 's97b.apply.atomic01';
    $beforeAtomicPersonel = s97bCountPersonel($pdo);
    $beforeAtomicSalary = s97bCountSalary($pdo);
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $atomicCsv,
            $gyUser,
            s97bApplyInput($atomicKey, (string) $atomicDry['manifest_hash']),
            null
        );
        s97bAssert(false, 'atomicity apply should fail');
    } catch (PersonelImportException $e) {
        s97bAssert(
            in_array($e->getCodeString(), ['PERSONEL_IMPORT_REFERENCE_CHANGED', 'PERSONEL_IMPORT_TRANSACTION_FAILED'], true),
            'atomicity apply fails inside transaction'
        );
    }
    s97bAssert(s97bCountPersonel($pdo) === $beforeAtomicPersonel, 'atomicity personel delta 0');
    s97bAssert(s97bCountSalary($pdo) === $beforeAtomicSalary, 'atomicity salary delta 0');
    s97bAssert(s97bRunStatus($pdo, $atomicKey) === 'BASARISIZ', 'atomicity run status BASARISIZ');
    $pdo->exec('DROP TRIGGER IF EXISTS s97b_atomicity_break_gorev');
    $gorevExists = (int) $pdo->query("SELECT COUNT(*) FROM gorevler WHERE id = 2")->fetchColumn();
    if ($gorevExists === 0) {
        $pdo->exec("INSERT INTO gorevler (id, ad) VALUES (2, 'Asistan')");
    } else {
        $pdo->exec("UPDATE gorevler SET ad = 'Asistan', durum = 'AKTIF' WHERE id = 2");
    }

    // 5) Drift: insert same TC after dry-run → apply rejects duplicate (pre-check or in-tx)
    $driftCsv = s97bHeaderCsv() . "\r\n" . s97bValidRow(['tc_kimlik_no' => '10000000178', 'sicil_no' => 'IMP-D01']) . "\r\n";
    $driftDry = PersonelImportDryRunService::dryRun($pdo, $driftCsv, $gyUser, null);
    s97bAssert(($driftDry['can_apply'] ?? false) === true, 'drift dry-run can_apply true');
    $pdo->exec("
        INSERT INTO personeller (
          tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
          sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id, aktif_durum
        ) VALUES (
          '10000000178', 'Drift', 'Kisi', '1990-01-01', '05320001111', 'Acil', '05320001112',
          'DRIFT-001', '2024-01-01', 1, 1, 2, 1, 'AKTIF'
        )
    ");
    $beforeDriftPersonel = s97bCountPersonel($pdo);
    $driftKey = 's97b.apply.drift01';
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $driftCsv,
            $gyUser,
            s97bApplyInput($driftKey, (string) $driftDry['manifest_hash']),
            null
        );
        s97bAssert(false, 'drift apply should fail');
    } catch (PersonelImportException $e) {
        s97bAssert(
            in_array(
                $e->getCodeString(),
                ['PERSONEL_IMPORT_ALREADY_EXISTS', 'PERSONEL_IMPORT_MANIFEST_CHANGED', 'PERSONEL_IMPORT_NOT_APPLICABLE'],
                true
            ),
            'drift apply rejects duplicate TC'
        );
    }
    s97bAssert(s97bCountPersonel($pdo) === $beforeDriftPersonel, 'drift personel delta 0');

    // 6) Manifest changed → PERSONEL_IMPORT_MANIFEST_CHANGED
    $manifestCsv = s97bHeaderCsv() . "\r\n" . s97bValidRow(['tc_kimlik_no' => '10000000186', 'sicil_no' => 'IMP-M01']) . "\r\n";
    $manifestDry = PersonelImportDryRunService::dryRun($pdo, $manifestCsv, $gyUser, null);
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $manifestCsv,
            $gyUser,
            s97bApplyInput('s97b.apply.manifest01', str_repeat('a', 64)),
            null
        );
        s97bAssert(false, 'manifest changed should fail');
    } catch (PersonelImportException $e) {
        s97bAssert($e->getCodeString() === 'PERSONEL_IMPORT_MANIFEST_CHANGED', 'manifest changed PERSONEL_IMPORT_MANIFEST_CHANGED');
    }

    // 7) Idempotency replay → same key+payload, personel unchanged
    $beforeReplayPersonel = s97bCountPersonel($pdo);
    $replayResult = PersonelImportApplyService::apply(
        $pdo,
        $twoRowCsv,
        $gyUser,
        s97bApplyInput($successKey, $successManifest),
        null
    );
    s97bAssert(($replayResult['idempotent_replay'] ?? false) === true, 'idempotency idempotent_replay true');
    s97bAssert(s97bCountPersonel($pdo) === $beforeReplayPersonel, 'idempotency personel count unchanged');

    // 8) Idempotency conflict → same key different manifest
    $conflictCsv = s97bHeaderCsv() . "\r\n" . s97bValidRow(['tc_kimlik_no' => '10000000194', 'sicil_no' => 'IMP-C01']) . "\r\n";
    $conflictDry = PersonelImportDryRunService::dryRun($pdo, $conflictCsv, $gyUser, null);
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $conflictCsv,
            $gyUser,
            s97bApplyInput($successKey, (string) $conflictDry['manifest_hash']),
            null
        );
        s97bAssert(false, 'idempotency conflict should fail');
    } catch (PersonelImportException $e) {
        s97bAssert($e->getCodeString() === 'PERSONEL_IMPORT_IDEMPOTENCY_CONFLICT', 'idempotency conflict');
    }

    // 9) Confirmation missing → PERSONEL_IMPORT_CONFIRMATION_REQUIRED
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $manifestCsv,
            $gyUser,
            s97bApplyInput('s97b.apply.confirm01', (string) $manifestDry['manifest_hash'], ''),
            null
        );
        s97bAssert(false, 'confirmation missing should fail');
    } catch (PersonelImportException $e) {
        s97bAssert($e->getCodeString() === 'PERSONEL_IMPORT_CONFIRMATION_REQUIRED', 'confirmation missing');
    }

    // 10) Wage column reject on apply path (analyze throws before manifest compare)
    $wageCsv = s97bHeaderCsv(['maas_tutari']) . "\r\n"
        . s97bValidRow(['tc_kimlik_no' => '10000000202', 'sicil_no' => 'IMP-W01']) . ";1000\r\n";
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $wageCsv,
            $gyUser,
            s97bApplyInput('s97b.apply.wage01', str_repeat('0', 64)),
            null
        );
        s97bAssert(false, 'wage column apply should fail');
    } catch (PersonelImportException $e) {
        s97bAssert($e->getCodeString() === 'PERSONEL_IMPORT_UCRET_KARARI_BEKLENIYOR', 'wage column reject on apply path');
    }

    // 11) Scope: scoped user + Sube 2 row → scope forbidden / not applicable
    $scopeCsv = s97bHeaderCsv() . "\r\n"
        . s97bValidRow(['sube' => 'Sube 2', 'departman' => 'Klinik', 'gorev' => 'Asistan', 'tc_kimlik_no' => '10000000210', 'sicil_no' => 'IMP-S01']) . "\r\n";
    $scopeDry = PersonelImportDryRunService::dryRun($pdo, $scopeCsv, $scopedUser, 1);
    s97bAssert(($scopeDry['can_apply'] ?? true) === false, 'scope dry-run can_apply false');
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $scopeCsv,
            $scopedUser,
            s97bApplyInput('s97b.apply.scope01', (string) $scopeDry['manifest_hash']),
            1
        );
        s97bAssert(false, 'scope apply should fail');
    } catch (PersonelImportException $e) {
        s97bAssert(
            in_array($e->getCodeString(), ['PERSONEL_IMPORT_SCOPE_FORBIDDEN', 'PERSONEL_IMPORT_NOT_APPLICABLE'], true),
            'scope apply forbidden or not applicable'
        );
    }

    // 12) Response has no raw tc_kimlik_no key; has masked
    s97bAssertNoRawTcKey($applyResult, 'apply response no raw tc_kimlik_no key');
    s97bAssert(
        ($applyResult['created'][0]['tc_kimlik_no_masked'] ?? '') === '100******46',
        'apply response has masked TC'
    );

    // 13) No standalone TC SHA in response / audit; row_hash != sha256(tc)
    $encodedApply = json_encode($applyResult, JSON_UNESCAPED_UNICODE);
    s97bAssert(is_string($encodedApply) && strpos($encodedApply, 'tc_sha256') === false, 'response has no tc_sha256');
    $auditCols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('personel_import_runs','personel_import_run_satirlari')"
    )->fetchAll(PDO::FETCH_COLUMN);
    s97bAssert(!in_array('tc_sha256', $auditCols, true), 'audit schema has no tc_sha256');
    $rowHashStmt = $pdo->query(
        'SELECT row_hash FROM personel_import_run_satirlari ORDER BY id ASC LIMIT 1'
    );
    $sampleRowHash = (string) $rowHashStmt->fetchColumn();
    s97bAssert($sampleRowHash !== hash('sha256', '10000000146'), 'row_hash is not standalone sha256(tc)');

    // 14) SCHEMA_NOT_READY fail-closed: no HTTP-style 500 code, personel delta 0
    $pdo->exec('DROP TABLE IF EXISTS personel_import_run_satirlari');
    $pdo->exec('DROP TABLE IF EXISTS personel_import_runs');
    s97bAssert(PersonelImportApplyService::schemaReady($pdo) === false, 'schemaReady false after drop');
    $beforeSchemaPersonel = s97bCountPersonel($pdo);
    $schemaCsv = s97bHeaderCsv() . "\r\n"
        . s97bValidRow(['tc_kimlik_no' => '10000000226', 'sicil_no' => 'IMP-SCHEMA01']) . "\r\n";
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $schemaCsv,
            $gyUser,
            s97bApplyInput('s97b.apply.schema01', str_repeat('b', 64)),
            null
        );
        s97bAssert(false, 'schema missing apply should fail');
    } catch (PersonelImportException $e) {
        s97bAssert($e->getCodeString() === 'SCHEMA_NOT_READY', 'schema missing SCHEMA_NOT_READY');
        s97bAssert($e->getHttpStatus() === 409, 'schema missing HTTP 409 not 500');
    }
    s97bAssert(s97bCountPersonel($pdo) === $beforeSchemaPersonel, 'schema missing personel delta 0');
    s97bApplyMigration($pdo, '046_personel_import_apply_owner.sql');
    s97bAssert(PersonelImportApplyService::schemaReady($pdo) === true, 'schemaReady true after re-apply 046');

    // 15) Rollback after failure leaves no durable CLAIMED (ORPHAN_CLAIM=NO); BASARISIZ reclaimable
    $retryCsv = s97bTwoRowCsv([
        ['tc_kimlik_no' => '10000000234', 'sicil_no' => 'IMP-R01'],
        ['tc_kimlik_no' => '10000000242', 'sicil_no' => 'IMP-R02'],
    ]);
    $retryDry = PersonelImportDryRunService::dryRun($pdo, $retryCsv, $gyUser, null);
    $retryKey = 's97b.apply.retry01';
    $pdo->exec('DROP TRIGGER IF EXISTS s97b_retry_break_gorev');
    $pdo->exec("
        CREATE TRIGGER s97b_retry_break_gorev
        AFTER INSERT ON personeller
        FOR EACH ROW
        DELETE FROM gorevler WHERE id = 2
    ");
    $beforeRetryPersonel = s97bCountPersonel($pdo);
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $retryCsv,
            $gyUser,
            s97bApplyInput($retryKey, (string) $retryDry['manifest_hash']),
            null
        );
        s97bAssert(false, 'retry setup apply should fail');
    } catch (PersonelImportException $e) {
        s97bAssert(true, 'retry setup apply failed as expected');
    }
    s97bAssert(s97bCountPersonel($pdo) === $beforeRetryPersonel, 'retry setup personel delta 0');
    s97bAssert(s97bRunStatus($pdo, $retryKey) !== 'CLAIMED', 'no durable CLAIMED after rollback');
    s97bAssert(s97bRunStatus($pdo, $retryKey) === 'BASARISIZ', 'failure audit BASARISIZ after rollback');
    $pdo->exec('DROP TRIGGER IF EXISTS s97b_retry_break_gorev');
    $gorevExistsRetry = (int) $pdo->query("SELECT COUNT(*) FROM gorevler WHERE id = 2")->fetchColumn();
    if ($gorevExistsRetry === 0) {
        $pdo->exec("INSERT INTO gorevler (id, ad) VALUES (2, 'Asistan')");
    } else {
        $pdo->exec("UPDATE gorevler SET ad = 'Asistan', durum = 'AKTIF' WHERE id = 2");
    }
    $retryDry2 = PersonelImportDryRunService::dryRun($pdo, $retryCsv, $gyUser, null);
    $retryOk = PersonelImportApplyService::apply(
        $pdo,
        $retryCsv,
        $gyUser,
        s97bApplyInput($retryKey, (string) $retryDry2['manifest_hash']),
        null
    );
    s97bAssert(($retryOk['status'] ?? '') === 'COMPLETED', 'same key retry after BASARISIZ completes');
    s97bAssert(($retryOk['created_count'] ?? 0) === 2, 'same key retry created_count 2');
    s97bAssert(s97bCountPersonel($pdo) - $beforeRetryPersonel === 2, 'same key retry personel +2');

    // 16) PersonelCreateService regression — single create without salary
    $beforeCreatePersonel = s97bCountPersonel($pdo);
    $beforeCreateSalary = s97bCountSalary($pdo);
    $createPayload = PersonelCanonicalValidator::normalizeAndValidateCreatePayload([
        'tc_kimlik_no' => '10000000218',
        'ad' => 'Tekil',
        'soyad' => 'Create',
        'dogum_tarihi' => '1992-03-03',
        'telefon' => '05329990000',
        'acil_durum_kisi' => 'Acil Kisi',
        'acil_durum_telefon' => '05329990001',
        'sicil_no' => 'REG-001',
        'ise_giris_tarihi' => '2025-06-01',
        'sube_id' => 1,
        'departman_id' => 1,
        'gorev_id' => 2,
        'personel_tipi_id' => 1,
        'aktif_durum' => 'AKTIF',
    ]);
    PersonelCreateService::validateCreateReferences($pdo, $createPayload);
    s97bAssert(!PersonelCreateService::tcExists($pdo, '10000000218'), 'create regression TC free');
    PersonelCreateService::insertPersonel($pdo, $createPayload);
    s97bAssert(s97bCountPersonel($pdo) - $beforeCreatePersonel === 1, 'create regression personel +1');
    s97bAssert(s97bCountSalary($pdo) === $beforeCreateSalary, 'create regression salary unchanged');

    s97bAssert(
        s97bCountPersonel($pdo) === $baselinePersonel + 6,
        'final personel count baseline+6 (apply +2, drift +1, retry +2, create +1)'
    );

    echo 'verify-s97b-personel-import-apply-mysql: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
