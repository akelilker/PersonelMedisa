<?php

declare(strict_types=1);

/**
 * S97-B tip 046 — disposable MariaDB apply twice + schema/constraint asserts.
 */

function s97b046Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s97b046RootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('SKIP: Disposable MariaDB credentials are required.');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function s97b046SplitSql(string $sql): array
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

function s97b046Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi');
    }
    foreach (s97b046SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

try {
    $root = s97b046RootPdo();
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SKIP:') === 0) {
        echo $e->getMessage() . PHP_EOL;
        exit(0);
    }
    throw $e;
}

$database = 'medisa_s97b_046_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN'));
    $pdo = new PDO(
        (string) $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    // 1) First apply PASS
    s97b046Apply($pdo, '046_personel_import_apply_owner.sql');
    // 2) Second apply PASS (idempotent)
    s97b046Apply($pdo, '046_personel_import_apply_owner.sql');

    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ('personel_import_runs','personel_import_run_satirlari')"
    )->fetchAll(PDO::FETCH_COLUMN);
    s97b046Assert(count($tables) === 2, '046 tables exist after double apply');

    $uq = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'personel_import_runs'
           AND INDEX_NAME = 'uq_pir_idempotency_key'"
    )->fetchColumn();
    s97b046Assert((int) $uq === 1, 'idempotency unique index exists');

    $fk = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'personel_import_run_satirlari'
           AND CONSTRAINT_NAME = 'fk_pirs_import_run'
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    )->fetchColumn();
    s97b046Assert((int) $fk === 1, 'satirlari FK to import_run exists');

    $checks = $pdo->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME IN ('chk_pir_source_sha256','chk_pir_manifest_hash','chk_pirs_row_hash')"
    )->fetchAll(PDO::FETCH_COLUMN);
    s97b046Assert(count($checks) >= 2, 'SHA CHECK constraints present');

    $runCols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personel_import_runs'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $rowCols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personel_import_run_satirlari'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $forbidden = ['raw_csv', 'csv_text', 'tc_kimlik_no', 'tc_sha256', 'telefon', 'canonical_json', 'request_body'];
    foreach ($forbidden as $col) {
        s97b046Assert(!in_array($col, $runCols, true), 'runs has no sensitive column ' . $col);
        if ($col !== 'tc_kimlik_no') {
            s97b046Assert(!in_array($col, $rowCols, true), 'satirlari has no sensitive column ' . $col);
        }
    }
    s97b046Assert(!in_array('tc_kimlik_no', $rowCols, true), 'satirlari has no raw tc column');
    s97b046Assert(in_array('tc_kimlik_no_masked', $rowCols, true), 'masked tc column exists');

    $validSha = str_repeat('a', 64);
    $pdo->prepare(
        "INSERT INTO personel_import_runs (
            idempotency_key, source_sha256, manifest_hash, schema_version,
            actor_id, actor_rol, active_sube_id, status,
            toplam_satir, gecerli_satir, created_count, started_at
        ) VALUES (
            's97b046.key.1', :src, :man, 'personel-import-v1',
            1, 'GENEL_YONETICI', NULL, 'COMPLETED',
            1, 1, 1, CURRENT_TIMESTAMP(3)
        )"
    )->execute(['src' => $validSha, 'man' => $validSha]);
    s97b046Assert(true, 'valid lowercase hex SHA accepted');

    $dupRejected = false;
    try {
        $pdo->prepare(
            "INSERT INTO personel_import_runs (
                idempotency_key, source_sha256, manifest_hash, schema_version,
                actor_id, actor_rol, active_sube_id, status,
                toplam_satir, gecerli_satir, created_count, started_at
            ) VALUES (
                's97b046.key.1', :src, :man, 'personel-import-v1',
                1, 'GENEL_YONETICI', NULL, 'CLAIMED',
                1, 1, 0, CURRENT_TIMESTAMP(3)
            )"
        )->execute(['src' => $validSha, 'man' => $validSha]);
    } catch (PDOException $e) {
        $dupRejected = stripos($e->getMessage(), 'Duplicate') !== false
            || stripos($e->getMessage(), 'uq_pir_idempotency_key') !== false;
    }
    s97b046Assert($dupRejected, 'duplicate idempotency key rejected');

    $invalidRejected = false;
    try {
        $pdo->exec(
            "INSERT INTO personel_import_runs (
                idempotency_key, source_sha256, manifest_hash, schema_version,
                actor_id, actor_rol, active_sube_id, status,
                toplam_satir, gecerli_satir, created_count, started_at
            ) VALUES (
                's97b046.key.badsha', 'NOT_A_VALID_SHA', '" . $validSha . "', 'personel-import-v1',
                1, 'GENEL_YONETICI', NULL, 'CLAIMED',
                1, 1, 0, CURRENT_TIMESTAMP(3)
            )"
        );
    } catch (PDOException $e) {
        $invalidRejected = true;
    }
    s97b046Assert($invalidRejected, 'invalid SHA rejected by CHECK');

    // Personel seed yok / personeller tablosu yok
    $personelTable = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'personeller'"
    )->fetchColumn();
    s97b046Assert($personelTable === 0, '046 does not create/seed personeller');

    echo 'S97B046MigrationMysqlTestRunner: ALL PASS' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
