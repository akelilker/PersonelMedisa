<?php

declare(strict_types=1);

/**
 * S86 pre-merge: disposable MariaDB 001-038 apply + idempotency + schema asserts.
 */

function pbmAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function pbmRootPdo(): PDO
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
function pbmSplitSql(string $sql): array
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

function pbmApplyFile(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (pbmSplitSql($sql) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

/** @return list<string> */
function pbmMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

$root = pbmRootPdo();
$dbName = 'medisa_s86_mig_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $pdo = new PDO((string) $dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);

    $files = pbmMigrationFiles();
    pbmAssert($files !== [] && $files[0] === '001_initial_schema.sql', 'zincir 001 ile baslar');
    pbmAssert(end($files) === '038_personel_belge_yonetimi.sql', 'zincir 038 ile biter');
    pbmAssert(!in_array('039_personel_belge_yonetimi.sql', $files, true), '039 yok');

    foreach ($files as $file) {
        pbmApplyFile($pdo, $file);
    }
    pbmAssert(true, '001-038 ilk apply tamam');

    // Second apply of 038 (idempotency)
    $secondOk = true;
    $secondError = '';
    try {
        pbmApplyFile($pdo, '038_personel_belge_yonetimi.sql');
    } catch (Throwable $e) {
        $secondOk = false;
        $secondError = $e->getMessage();
    }
    pbmAssert($secondOk, '038 ikinci apply idempotent' . ($secondOk ? '' : (': ' . $secondError)));

    $tables = $pdo->query("
        SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('personel_belge_dosya_surumleri', 'personel_belge_auditleri')
        ORDER BY TABLE_NAME
    ")->fetchAll(PDO::FETCH_COLUMN);
    pbmAssert($tables === ['personel_belge_auditleri', 'personel_belge_dosya_surumleri'], '038 tablolari mevcut');

    $fkSurum = $pdo->query("
        SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'personel_belge_dosya_surumleri'
          AND CONSTRAINT_NAME IN ('fk_pbds_surec', 'fk_pbds_personel')
    ")->fetchAll(PDO::FETCH_ASSOC);
    pbmAssert(count($fkSurum) === 2, 'surum FK sayisi 2');
    foreach ($fkSurum as $fk) {
        pbmAssert(in_array(strtoupper((string) $fk['DELETE_RULE']), ['RESTRICT', 'NO ACTION'], true), 'surum FK ON DELETE RESTRICT (' . $fk['CONSTRAINT_NAME'] . ')');
    }

    $fkAudit = $pdo->query("
        SELECT CONSTRAINT_NAME, DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'personel_belge_auditleri'
          AND CONSTRAINT_NAME IN ('fk_pbaud_surec', 'fk_pbaud_personel', 'fk_pbaud_surum')
    ")->fetchAll(PDO::FETCH_ASSOC);
    pbmAssert(count($fkAudit) === 3, 'audit FK sayisi 3');
    foreach ($fkAudit as $fk) {
        pbmAssert(in_array(strtoupper((string) $fk['DELETE_RULE']), ['RESTRICT', 'NO ACTION'], true), 'audit FK ON DELETE RESTRICT (' . $fk['CONSTRAINT_NAME'] . ')');
    }

    $checks = $pdo->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'personel_belge_dosya_surumleri'
          AND CONSTRAINT_TYPE = 'CHECK'
    ")->fetchAll(PDO::FETCH_COLUMN);
    pbmAssert(count($checks) >= 3, 'surum CHECK constraint aktif (>=3)');

    $auditChecks = $pdo->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'personel_belge_auditleri'
          AND CONSTRAINT_TYPE = 'CHECK'
    ")->fetchAll(PDO::FETCH_COLUMN);
    pbmAssert(count($auditChecks) >= 1, 'audit CHECK constraint aktif');

    $uniq = $pdo->query("
        SELECT INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'personel_belge_dosya_surumleri'
          AND INDEX_NAME = 'uq_pbds_tek_aktif'
    ")->fetchColumn();
    pbmAssert($uniq === 'uq_pbds_tek_aktif', 'tek aktif unique index mevcut');

    pbmAssert((int) $pdo->query('SELECT COUNT(*) FROM personel_belge_dosya_surumleri')->fetchColumn() === 0, 'surum baslangic satiri 0');
    pbmAssert((int) $pdo->query('SELECT COUNT(*) FROM personel_belge_auditleri')->fetchColumn() === 0, 'audit baslangic satiri 0');

    $has039 = (bool) array_filter(pbmMigrationFiles(), static fn ($f) => str_starts_with($f, '039_'));
    pbmAssert(!$has039, 'repo 039 migration yok');

    echo 'verify-personel-belge-migration-mysql: OK' . PHP_EOL;
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$dbName`");
}
