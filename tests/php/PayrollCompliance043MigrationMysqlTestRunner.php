<?php

declare(strict_types=1);

/**
 * S87: disposable MariaDB — apply tip 043 additively + idempotency + schema asserts.
 * php tests/php/PayrollCompliance043MigrationMysqlTestRunner.php
 */

function s87mAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s87mRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Disposable MariaDB credentials are required.');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** @return list<string> */
function s87mSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
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

function s87mApplyFile(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (s87mSplitSql($sql) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

/** @return list<string> */
function s87mMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name)
            && $name !== '067_personel_canonical_reference_gate.sql'
            && $name !== '068_sgk_actor_identity_lifecycle_audit.sql'
            && $name !== '069_personel_credential_onboarding.sql'
            && $name !== '070_offline_mutation_idempotency.sql';
    }));
    sort($files, SORT_STRING);

    return $files;
}

$root = s87mRootPdo();
$dbName = 'medisa_s87_mig_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $pdo = new PDO((string) $dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);

    $files = s87mMigrationFiles();
    s87mAssert($files !== [] && $files[0] === '001_initial_schema.sql', 'zincir 001 ile baslar');
    s87mAssert(end($files) === '066_personel_calisan_kapsami.sql', 'zincir 065 ile biter');
    s87mAssert(in_array('042_sgk_resmi_kaynakli_kisitli_katalog.sql', $files, true), '042 SGK korunur');

    foreach ($files as $file) {
        s87mApplyFile($pdo, $file);
    }
    s87mAssert(true, '001-044 ilk apply tamam');

    $secondOk = true;
    $secondError = '';
    try {
        s87mApplyFile($pdo, '043_payroll_compliance_critical_gaps.sql');
    } catch (Throwable $e) {
        $secondOk = false;
        $secondError = $e->getMessage();
    }
    s87mAssert($secondOk, '043 ikinci apply idempotent' . ($secondOk ? '' : (': ' . $secondError)));

    $cols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fazla_calisma_odeme_tercihleri'
          AND COLUMN_NAME IN ('talep_tarihi','imzali_talep_belge_id','sisteme_giren_kullanici_id','sisteme_giris_zamani')
        ORDER BY COLUMN_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);
    s87mAssert(
        $cols === ['imzali_talep_belge_id', 'sisteme_giren_kullanici_id', 'sisteme_giris_zamani', 'talep_tarihi'],
        '043 tercih kanit kolonlari'
    );

    $auditCols = $pdo->query("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fazla_calisma_odeme_tercihi_audit'
          AND COLUMN_NAME IN ('talep_tarihi','imzali_talep_belge_id')
        ORDER BY COLUMN_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);
    s87mAssert($auditCols === ['imzali_talep_belge_id', 'talep_tarihi'], '043 audit kanit kolonlari');

    $fk = $pdo->query("
        SELECT CONSTRAINT_NAME, DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fazla_calisma_odeme_tercihleri'
          AND CONSTRAINT_NAME = 'fk_fcot_imzali_belge'
    ")->fetch(PDO::FETCH_ASSOC);
    s87mAssert(is_array($fk) && ($fk['DELETE_RULE'] ?? '') === 'RESTRICT', 'fk_fcot_imzali_belge RESTRICT');

    $ref = $pdo->query("
        SELECT REFERENCED_TABLE_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'fazla_calisma_odeme_tercihleri'
          AND CONSTRAINT_NAME = 'fk_fcot_imzali_belge'
          AND REFERENCED_TABLE_NAME IS NOT NULL
        LIMIT 1
    ")->fetchColumn();
    s87mAssert((string) $ref === 'surecler', 'imzali belge FK → surecler');

    $kilit = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'yillik_fazla_calisma_kilitleri'
    ")->fetchColumn();
    s87mAssert($kilit === 1, 'yillik_fazla_calisma_kilitleri mevcut');

    $uq = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'yillik_fazla_calisma_kilitleri'
          AND INDEX_NAME = 'uq_yfck_personel_yil'
    ")->fetchColumn();
    s87mAssert($uq >= 1, 'uq_yfck_personel_yil mevcut');

    // 042 SGK kolonlari hala yerinde (043 dokunmaz)
    $sgkCol = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'sgk_eksik_gun_kodlari'
          AND COLUMN_NAME = 'gecerlilik_tarih_durumu'
    ")->fetchColumn();
    s87mAssert($sgkCol === 1, '042 SGK gecerlilik_tarih_durumu korunur');

    echo 'verify-payroll-compliance-043-migration-mysql: OK' . PHP_EOL;
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
}
