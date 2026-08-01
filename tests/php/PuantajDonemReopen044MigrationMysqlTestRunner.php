<?php

declare(strict_types=1);

/**
 * S87 tip 044 — disposable MariaDB apply twice + schema asserts.
 * Requires MEDISA_TEST_MYSQL_* env. Skips cleanly if unset.
 * php tests/php/PuantajDonemReopen044MigrationMysqlTestRunner.php
 */

function s87m044Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s87m044RootPdo(): PDO
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
function s87m044SplitSql(string $sql): array
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

function s87m044Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi');
    }
    foreach (s87m044SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

try {
    $root = s87m044RootPdo();
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SKIP:') === 0) {
        echo $e->getMessage() . PHP_EOL;
        exit(0);
    }
    throw $e;
}

$dbName = 'medisa_s87_044_' . getmypid();
$root->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec('USE `' . $dbName . '`');

// Minimal deps for 044
$root->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT)');
$root->exec('CREATE TABLE subeler (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT)');
$root->exec('CREATE TABLE puantaj_aylik_muhurleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  donem CHAR(7) NOT NULL,
  durum VARCHAR(32) NOT NULL DEFAULT \'MUHURLENDI\',
  muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_puantaj_aylik_muhur_sube_donem (sube_id, yil, ay)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$root->exec('INSERT INTO subeler (id) VALUES (1)');
$root->exec('INSERT INTO users (id) VALUES (1)');
$root->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by)
             VALUES (1, 2026, 3, '2026-03', 'MUHURLENDI', 2, 1)");

s87m044Apply($root, '044_puantaj_aylik_muhur_revision_reopen.sql');
s87m044Apply($root, '044_puantaj_aylik_muhur_revision_reopen.sql'); // idempotent

$rev = $root->query('SELECT revision_no, source_hash, durum FROM puantaj_aylik_muhurleri WHERE id = 1')->fetch();
s87m044Assert((int) $rev['revision_no'] === 1, 'backfill revision_no=1');
s87m044Assert($rev['durum'] === 'MUHURLENDI', 'durum preserved');

$idxRev = (int) $root->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri'
       AND INDEX_NAME = 'uq_pam_sube_donem_revision'"
)->fetchColumn();
s87m044Assert($idxRev === 4, 'uq_pam_sube_donem_revision has 4 columns');

$idxAktif = (int) $root->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri'
       AND INDEX_NAME = 'uq_pam_aktif_muhur'"
)->fetchColumn();
s87m044Assert($idxAktif >= 1, 'uq_pam_aktif_muhur exists');

$colAktif = (int) $root->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri'
       AND COLUMN_NAME = 'aktif_muhur'"
)->fetchColumn();
s87m044Assert($colAktif === 1, 'aktif_muhur generated column');

$oldUq = (int) $root->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri'
       AND INDEX_NAME = 'uq_puantaj_aylik_muhur_sube_donem'"
)->fetchColumn();
s87m044Assert($oldUq === 0, 'legacy sube_donem unique dropped');

$tbl = $root->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_donem_reopen_talepleri'"
)->fetchColumn();
s87m044Assert((int) $tbl === 1, 'reopen talepleri table');

$auditTbl = $root->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_donem_reopen_auditleri'"
)->fetchColumn();
s87m044Assert((int) $auditTbl === 1, 'reopen auditleri table');

// Snapshot FK baglantisi: muhur id korunur
$root->exec('CREATE TABLE maas_hesaplama_donem_snapshotlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  muhur_id INT UNSIGNED NOT NULL,
  CONSTRAINT fk_test_snap_muhur FOREIGN KEY (muhur_id) REFERENCES puantaj_aylik_muhurleri (id)
) ENGINE=InnoDB');
$root->exec('INSERT INTO maas_hesaplama_donem_snapshotlari (muhur_id) VALUES (1)');
$snapMuhur = (int) $root->query('SELECT muhur_id FROM maas_hesaplama_donem_snapshotlari WHERE id = 1')->fetchColumn();
s87m044Assert($snapMuhur === 1, 'snapshot muhur_id FK preserved after 044');

// Unique: ayni revision duplicate engellenir
$dupRevBlocked = false;
try {
    $root->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, revision_no)
                 VALUES (1, 2026, 3, '2026-03', 'SUPERSEDED', 1)");
} catch (Throwable $e) {
    $dupRevBlocked = true;
}
s87m044Assert($dupRevBlocked, 'duplicate revision_no blocked');

// Unique: iki effective engellenir
$root->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, revision_no)
             VALUES (1, 2026, 3, '2026-03', 'SUPERSEDED', 2)");
$dupEffBlocked = false;
try {
    $root->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, revision_no)
                 VALUES (1, 2026, 3, '2026-03', 'MUHURLENDI', 3)");
} catch (Throwable $e) {
    $dupEffBlocked = true;
}
s87m044Assert($dupEffBlocked, 'second effective seal blocked by aktif_muhur unique');

// Ikinci apply sonrasi index sayisi artmadi
$idxRev2 = (int) $root->query(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri'
       AND INDEX_NAME = 'uq_pam_sube_donem_revision'"
)->fetchColumn();
s87m044Assert($idxRev2 === $idxRev, 'second apply did not duplicate revision index');

$root->exec('DROP DATABASE `' . $dbName . '`');
echo 'PuantajDonemReopen044MigrationMysqlTestRunner: ALL PASS' . PHP_EOL;
