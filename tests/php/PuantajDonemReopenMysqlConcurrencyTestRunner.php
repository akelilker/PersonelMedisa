<?php

declare(strict_types=1);

/**
 * S87 reopen/reseal MariaDB concurrency (disposable).
 * php tests/php/PuantajDonemReopenMysqlConcurrencyTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\PuantajDonemKilidiService;
use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\PuantajDonemReopenException;
use Medisa\Api\Services\PuantajDonemReopenService;

function s87cAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s87cRootPdo(): PDO
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

function s87cDbPdo(string $db): PDO
{
    $root = s87cRootPdo();
    $dsn = (string) getenv('MEDISA_TEST_MYSQL_DSN');
    $user = (string) getenv('MEDISA_TEST_MYSQL_USER');
    $password = (string) (getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '');
    if (stripos($dsn, 'dbname=') !== false) {
        $dsn = preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn;
    } else {
        $dsn .= (substr($dsn, -1) === ';' ? '' : ';') . 'dbname=' . $db;
    }
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');

    return $pdo;
}

function s87cSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED PRIMARY KEY)');
    $pdo->exec('CREATE TABLE personeller (
        id INT UNSIGNED PRIMARY KEY, sube_id INT UNSIGNED NOT NULL,
        ise_giris_tarihi DATE NULL, cikis_tarihi DATE NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE puantaj_donem_kilitleri (
        sube_id INT UNSIGNED NOT NULL, yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
        PRIMARY KEY (sube_id, yil, ay)
    ) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE puantaj_aylik_muhurleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sube_id INT UNSIGNED NOT NULL, yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
        revision_no INT UNSIGNED NOT NULL DEFAULT 1,
        donem CHAR(7) NOT NULL, durum VARCHAR(32) NOT NULL DEFAULT 'MUHURLENDI',
        muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL, parent_muhur_id INT UNSIGNED NULL,
        superseded_by_id INT UNSIGNED NULL, source_hash CHAR(64) NULL, reopen_talep_id INT UNSIGNED NULL,
        aktif_muhur TINYINT(1) GENERATED ALWAYS AS (CASE WHEN durum = 'MUHURLENDI' THEN 1 ELSE NULL END) STORED,
        UNIQUE KEY uq_pam_sube_donem_revision (sube_id, yil, ay, revision_no),
        UNIQUE KEY uq_pam_aktif_muhur (sube_id, yil, ay, aktif_muhur)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE puantaj_donem_reopen_talepleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sube_id INT UNSIGNED NOT NULL, yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
        kaynak_muhur_id INT UNSIGNED NOT NULL, talep_durumu VARCHAR(32) NOT NULL, gerekce VARCHAR(1000) NOT NULL,
        requested_by INT UNSIGNED NOT NULL, requested_at DATETIME NOT NULL,
        approved_by INT UNSIGNED NULL, approved_at DATETIME NULL,
        rejected_by INT UNSIGNED NULL, rejected_at DATETIME NULL, rejection_reason VARCHAR(1000) NULL,
        applied_at DATETIME NULL, reseal_muhur_id INT UNSIGNED NULL, request_hash CHAR(64) NOT NULL,
        acik_talep_slot TINYINT UNSIGNED GENERATED ALWAYS AS (
          CASE WHEN talep_durumu IN ('ONAY_BEKLIYOR','ONAYLANDI') THEN 1 ELSE NULL END
        ) STORED,
        UNIQUE KEY uq_pdrt_acik_donem (sube_id, yil, ay, acik_talep_slot),
        UNIQUE KEY uq_pdrt_request_hash (sube_id, yil, ay, request_hash)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE puantaj_donem_reopen_auditleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sube_id INT UNSIGNED NOT NULL, yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
        aksiyon VARCHAR(64) NOT NULL, sonuc VARCHAR(32) NOT NULL, reopen_talep_id INT UNSIGNED NULL,
        source_muhur_id INT UNSIGNED NULL, source_revision INT UNSIGNED NULL,
        target_muhur_id INT UNSIGNED NULL, target_revision INT UNSIGNED NULL,
        request_hash CHAR(64) NOT NULL, previous_source_hash CHAR(64) NULL, new_source_hash CHAR(64) NULL,
        failure_code VARCHAR(64) NULL, payload_json TEXT NULL, actor_id INT UNSIGNED NOT NULL,
        UNIQUE KEY uq_pdra_idempotency (sube_id, yil, ay, aksiyon, request_hash)
    ) ENGINE=InnoDB");
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        personel_id INT UNSIGNED NOT NULL, tarih DATE NOT NULL, state VARCHAR(32) NOT NULL,
        gun_tipi VARCHAR(64) NULL, muhur_id INT UNSIGNED NULL,
        UNIQUE KEY uq_gp (personel_id, tarih)
    ) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO users (id) VALUES (1),(2)');
    $pdo->exec('INSERT INTO subeler (id) VALUES (1)');
    $pdo->exec("INSERT INTO personeller (id, sube_id, ise_giris_tarihi) VALUES (10, 1, '2026-04-01')");
    $pdo->exec("INSERT INTO puantaj_aylik_muhurleri
        (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, source_hash)
        VALUES (1, 2026, 4, 1, '2026-04', 'MUHURLENDI', 1, 1, 'hash-old')");
}

try {
    $root = s87cRootPdo();
} catch (Throwable $e) {
    if (strpos($e->getMessage(), 'SKIP:') === 0) {
        echo $e->getMessage() . PHP_EOL;
        exit(0);
    }
    throw $e;
}

$db = 'medisa_s87_reopen_conc_' . getmypid();
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$a = s87cDbPdo($db);
$b = s87cDbPdo($db);
s87cSchema($a);

// 1) Concurrent reopen request: holder keeps lock; waiter gets ALREADY_EXISTS after commit
$a->beginTransaction();
PuantajDonemKilidiService::acquire($a, 1, 2026, 4);
$t1 = PuantajDonemReopenService::createReopenRequest($a, ['id' => 1], 1, 2026, 4, 'concurrent gerekce');

$blockedCode = null;
$b->beginTransaction();
try {
    PuantajDonemKilidiService::acquire($b, 1, 2026, 4);
    PuantajDonemReopenService::createReopenRequest($b, ['id' => 1], 1, 2026, 4, 'concurrent gerekce B');
} catch (Throwable $e) {
    $blockedCode = $e instanceof PuantajDonemReopenException
        ? $e->getErrorCode()
        : (stripos($e->getMessage(), 'Lock wait') !== false ? 'LOCK_WAIT' : get_class($e));
    if ($b->inTransaction()) {
        $b->rollBack();
    }
}
s87cAssert($blockedCode === 'LOCK_WAIT' || $blockedCode === 'REOPEN_REQUEST_ALREADY_EXISTS', 'second request blocked while first holds lock');
$a->commit();

$b->beginTransaction();
try {
    PuantajDonemReopenService::createReopenRequest($b, ['id' => 1], 1, 2026, 4, 'concurrent gerekce B');
    s87cAssert(false, 'second request should conflict after first commit');
} catch (PuantajDonemReopenException $e) {
    $b->rollBack();
    s87cAssert($e->getErrorCode() === 'REOPEN_REQUEST_ALREADY_EXISTS', 'single open request enforced');
}

$openCount = (int) $a->query(
    "SELECT COUNT(*) FROM puantaj_donem_reopen_talepleri
     WHERE sube_id=1 AND yil=2026 AND ay=4 AND talep_durumu IN ('ONAY_BEKLIYOR','ONAYLANDI')"
)->fetchColumn();
s87cAssert($openCount === 1, 'exactly one open talep');

// 2) Approve vs reject race: one wins
$a->beginTransaction();
PuantajDonemKilidiService::acquire($a, 1, 2026, 4);
PuantajDonemReopenService::approveReopenRequest($a, ['id' => 2], 1, 2026, 4, (int) $t1['id'], 'ok');

$rejectCode = null;
$b->beginTransaction();
try {
    PuantajDonemKilidiService::acquire($b, 1, 2026, 4);
    PuantajDonemReopenService::rejectReopenRequest($b, ['id' => 2], 1, 2026, 4, (int) $t1['id'], 'too late');
} catch (Throwable $e) {
    $rejectCode = $e instanceof PuantajDonemReopenException
        ? $e->getErrorCode()
        : (stripos($e->getMessage(), 'Lock wait') !== false ? 'LOCK_WAIT' : 'ERR');
    if ($b->inTransaction()) {
        $b->rollBack();
    }
}
$a->commit();
s87cAssert($rejectCode === 'LOCK_WAIT' || $rejectCode === 'REOPEN_REQUEST_NOT_PENDING', 'reject loses to approve race');

$durum = (string) $a->query('SELECT talep_durumu FROM puantaj_donem_reopen_talepleri WHERE id=' . (int) $t1['id'])->fetchColumn();
s87cAssert($durum === 'ONAYLANDI', 'approve is terminal winner');

// 3) Double reseal race → tek revision
$a->exec("UPDATE personeller SET ise_giris_tarihi='2026-04-01' WHERE id=10");
for ($d = 1; $d <= 30; $d++) {
    $t = sprintf('2026-04-%02d', $d);
    $a->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, state, gun_tipi) VALUES (10, '{$t}', 'ACIK', 'Normal_Is_Gunu')");
}

$copy = static function (PDO $pdo, $subeId, $yil, $ay, $revisionNo, $parentId, $talepId) {
    $stmt = $pdo->prepare(
        "INSERT INTO puantaj_aylik_muhurleri
            (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, parent_muhur_id, reopen_talep_id, source_hash)
         VALUES (1, 2026, 4, :rev, '2026-04', 'MUHURLENDI', 30, 1, :parent, :talep, CONCAT('hash-', :rev2))"
    );
    $stmt->execute(['rev' => $revisionNo, 'parent' => $parentId, 'talep' => $talepId, 'rev2' => $revisionNo]);
    $newId = (int) $pdo->lastInsertId();

    return ['rows' => array_fill(0, 30, ['x' => 1]), 'source_hash' => 'hash-' . $revisionNo, 'muhur_id' => $newId];
};

$a->beginTransaction();
PuantajDonemKilidiService::acquire($a, 1, 2026, 4);
$r1 = PuantajDonemReopenService::reseal($a, ['id' => 1], 1, 2026, 4, 'reseal-a', 1, $copy);

$resealCode = null;
$b->beginTransaction();
try {
    PuantajDonemKilidiService::acquire($b, 1, 2026, 4);
    PuantajDonemReopenService::reseal($b, ['id' => 1], 1, 2026, 4, 'reseal-b', 1, $copy);
} catch (Throwable $e) {
    $resealCode = $e instanceof PuantajDonemReopenException
        ? $e->getErrorCode()
        : (stripos($e->getMessage(), 'Lock wait') !== false ? 'LOCK_WAIT' : 'ERR');
    if ($b->inTransaction()) {
        $b->rollBack();
    }
}
$a->commit();
s87cAssert(
    $resealCode === 'LOCK_WAIT'
    || $resealCode === 'PERIOD_REOPEN_NOT_APPROVED'
    || $resealCode === 'RESEAL_CONFLICT'
    || $resealCode === 'REOPEN_SOURCE_SEAL_CHANGED',
    'second reseal blocked'
);

$effCount = (int) $a->query("SELECT COUNT(*) FROM puantaj_aylik_muhurleri WHERE durum='MUHURLENDI'")->fetchColumn();
$maxRev = (int) $a->query('SELECT MAX(revision_no) FROM puantaj_aylik_muhurleri')->fetchColumn();
$applied = (int) $a->query("SELECT COUNT(*) FROM puantaj_donem_reopen_talepleri WHERE talep_durumu='UYGULANDI'")->fetchColumn();
s87cAssert($effCount === 1, 'exactly one effective seal after reseal race');
s87cAssert($maxRev === 2 && (int) $r1['revision_no'] === 2, 'single revision 2 produced');
s87cAssert($applied === 1, 'talep UYGULANDI once');
s87cAssert(PuantajDonemPeriodService::resolvePeriodState($a, 1, 2026, 4) === 'SEALED', 'period SEALED after reseal');

$root->exec('DROP DATABASE `' . $db . '`');
echo 'PuantajDonemReopenMysqlConcurrencyTestRunner: ALL PASS' . PHP_EOL;
