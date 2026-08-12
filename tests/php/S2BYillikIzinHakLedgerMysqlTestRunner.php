<?php

declare(strict_types=1);

/**
 * S2B ledger acceptance: permission matrix + migration tip + ledger CRUD (SQLite or disposable MariaDB).
 * php tests/php/S2BYillikIzinHakLedgerMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Services\Izin\YillikIzinHakDuzeltmeException;
use Medisa\Api\Services\Izin\YillikIzinHakDuzeltmeLedgerService;

function s2bLedgerAssert(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$name}\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] {$name}\n");
}

/** @return list<string> */
function s2bLedgerMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

function s2bLedgerHasSqlite(): bool
{
    return extension_loaded('pdo_sqlite') || in_array('sqlite', PDO::getAvailableDrivers(), true);
}

function s2bLedgerRootPdo(): ?PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    if ($dsn === '' || $user === '') {
        return null;
    }

    return new PDO($dsn, $user, getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function s2bLedgerCreateSqliteSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE personeller (
            id INTEGER PRIMARY KEY,
            sube_id INTEGER NOT NULL DEFAULT 1
        )'
    );
    $pdo->exec(
        'CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            ad_soyad TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE yillik_izin_hak_duzeltmeleri (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            personel_id INTEGER NOT NULL,
            gun_delta INTEGER NOT NULL CHECK (gun_delta <> 0),
            kategori TEXT NOT NULL,
            aciklama TEXT NOT NULL,
            effective_date TEXT NOT NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            reverses_id INTEGER NULL UNIQUE,
            FOREIGN KEY (personel_id) REFERENCES personeller(id),
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (reverses_id) REFERENCES yillik_izin_hak_duzeltmeleri(id)
        )'
    );
    $pdo->exec('INSERT INTO personeller (id, sube_id) VALUES (1, 1)');
    $pdo->exec("INSERT INTO users (id, ad_soyad) VALUES (1, 'IK User')");
}

// --- migration tip ---
$files = s2bLedgerMigrationFiles();
s2bLedgerAssert(in_array('052_puantaj_tolerans_ve_disiplin.sql', $files, true), '052 present');
s2bLedgerAssert(in_array('053_retention_legal_hold_arsiv.sql', $files, true), '053 present');
s2bLedgerAssert(in_array('054_canonical_role_consolidation.sql', $files, true), '054 present');
s2bLedgerAssert(end($files) === '058_qr_puantaj_candidate_decision_ledger.sql', 'tip is 055');

$migration055 = (string) file_get_contents(__DIR__ . '/../../api/migrations/055_yillik_izin_hak_duzeltmeleri.sql');
s2bLedgerAssert(strpos($migration055, 'CREATE TABLE IF NOT EXISTS yillik_izin_hak_duzeltmeleri') !== false, '055 ledger table');
s2bLedgerAssert(strpos($migration055, 'INSERT INTO') === false, '055 additive only');

// --- permission matrix (RolePermissions reflection) ---
s2bLedgerAssert(RolePermissions::has(['rol' => 'GENEL_YONETICI'], 'yillik_izin_hak_duzeltme.manage'), 'GY manage');
s2bLedgerAssert(RolePermissions::has(['rol' => 'IK_SORUMLUSU'], 'yillik_izin_hak_duzeltme.manage'), 'IK manage');
foreach (['BOLUM_YONETICISI', 'MUHASEBE', 'BIRIM_AMIRI', 'SISTEM_YONETICISI', 'PERSONEL'] as $role) {
    s2bLedgerAssert(
        !RolePermissions::has(['rol' => $role], 'yillik_izin_hak_duzeltme.manage'),
        "{$role} denied manage"
    );
}

// --- source invariants ---
$ctrl = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/YillikIzinHakDuzeltmeController.php');
s2bLedgerAssert(strpos($ctrl, 'yillik_izin_hak_duzeltme.manage') !== false, 'controller manage guard');
s2bLedgerAssert(strpos($ctrl, 'DELETE FROM yillik_izin_hak_duzeltmeleri') === false, 'no hard delete');

$router = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
s2bLedgerAssert(strpos($router, 'yillik-izin-bakiye') !== false, 'router bakiye route');
s2bLedgerAssert(strpos($router, 'yillik-izin-hak-duzeltmeleri') !== false, 'router list/create');
s2bLedgerAssert(strpos($router, 'ters-kayit') !== false, 'router ters-kayit');
s2bLedgerAssert(strpos($router, 'kalan_izin') === false, 'no kalan_izin overwrite route');

$surecler = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/SureclerController.php');
s2bLedgerAssert(strpos($surecler, "RolePermissions::assert(\$user, 'surecler.create')") !== false, 'surecler.create matrix');

// --- ledger CRUD on SQLite (create + netSum; reverse skipped — FOR UPDATE) ---
if (s2bLedgerHasSqlite()) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    s2bLedgerCreateSqliteSchema($pdo);

    $row = YillikIzinHakDuzeltmeLedgerService::create($pdo, 1, [
        'gun_delta' => 8,
        'kategori' => 'DEVIR',
        'aciklama' => 'Onceki sistem devir bakiyesi',
        'effective_date' => '2026-01-01',
    ], ['id' => 1, 'rol' => 'IK_SORUMLUSU']);
    s2bLedgerAssert((int) $row['gun_delta'] === 8 && $row['kategori'] === 'DEVIR', 'create DEVIR +8');

    $row2 = YillikIzinHakDuzeltmeLedgerService::create($pdo, 1, [
        'gun_delta' => -2,
        'kategori' => 'DUZELTME',
        'aciklama' => 'Idari duzeltme',
        'effective_date' => '2026-02-01',
    ], ['id' => 1]);
    s2bLedgerAssert((int) $row2['gun_delta'] === -2, 'create signed DUZELTME');

    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSum($pdo, 1) === 6, 'netSum = 6');
    s2bLedgerAssert(count(YillikIzinHakDuzeltmeLedgerService::listByPersonel($pdo, 1)) === 2, 'listByPersonel count');

    // AS-OF ledger: future effective_date excluded until as-of
    $future = YillikIzinHakDuzeltmeLedgerService::create($pdo, 1, [
        'gun_delta' => 5,
        'kategori' => 'EK_HAK',
        'aciklama' => 'Ileri tarihli ek hak',
        'effective_date' => '2026-09-01',
    ], ['id' => 1]);
    s2bLedgerAssert((int) $future['gun_delta'] === 5, 'create future EK_HAK +5');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSum($pdo, 1) === 11, 'netSum full history includes future');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdo, 1, '2026-08-11') === 6, 'netSumAsOf 2026-08-11 excludes future');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdo, 1, '2026-09-02') === 11, 'netSumAsOf 2026-09-02 includes future');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::countByPersonelAsOf($pdo, 1, '2026-08-11') === 2, 'countAsOf excludes future');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::countByPersonelAsOf($pdo, 1, '2026-09-02') === 3, 'countAsOf includes future');

    // Dedicated FUTURE_DATED_LEDGER fixture: +4 then +5
    $pdo2 = new PDO('sqlite::memory:');
    $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo2->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    s2bLedgerCreateSqliteSchema($pdo2);
    $a4 = YillikIzinHakDuzeltmeLedgerService::create($pdo2, 1, [
        'gun_delta' => 4,
        'kategori' => 'DEVIR',
        'aciklama' => 'Past +4',
        'effective_date' => '2026-08-01',
    ], ['id' => 1]);
    YillikIzinHakDuzeltmeLedgerService::create($pdo2, 1, [
        'gun_delta' => 5,
        'kategori' => 'EK_HAK',
        'aciklama' => 'Future +5',
        'effective_date' => '2026-09-01',
    ], ['id' => 1]);
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdo2, 1, '2026-08-11') === 4, 'FUTURE_DATED_LEDGER as-of Aug11 = +4');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdo2, 1, '2026-09-02') === 9, 'FUTURE_DATED_LEDGER as-of Sep02 = +9');
    $revA4 = YillikIzinHakDuzeltmeLedgerService::reverse($pdo2, 1, (int) $a4['id'], ['id' => 1], 'Telafi past');
    s2bLedgerAssert($revA4['effective_date'] === '2026-08-01', 'reversal copies original effective_date');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdo2, 1, '2026-08-11') === 0, 'as-of after reverse restates to 0 (+4-4)');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdo2, 1, '2026-09-02') === 5, 'as-of Sep02 after reverse = +5 future only');

    try {
        YillikIzinHakDuzeltmeLedgerService::create($pdo, 1, [
            'gun_delta' => 0,
            'kategori' => 'DEVIR',
            'aciklama' => 'zero forbidden',
            'effective_date' => '2026-03-01',
        ], ['id' => 1]);
        s2bLedgerAssert(false, 'zero delta should throw');
    } catch (YillikIzinHakDuzeltmeException $e) {
        s2bLedgerAssert($e->getErrorCode() === 'VALIDATION_ERROR', 'zero delta rejected');
    }

    $ek = YillikIzinHakDuzeltmeLedgerService::create($pdo, 1, [
        'gun_delta' => 3,
        'kategori' => 'EK_HAK',
        'aciklama' => 'Sirket ekstra hak',
        'effective_date' => '2026-03-15',
    ], ['id' => 1]);
    s2bLedgerAssert((int) $ek['gun_delta'] === 3 && $ek['kategori'] === 'EK_HAK', 'create EK_HAK +3');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSum($pdo, 1) === 14, 'netSum after EK_HAK = 14 (11+3)');

    $rev = YillikIzinHakDuzeltmeLedgerService::reverse($pdo, 1, (int) $row['id'], ['id' => 1], 'Telafi');
    s2bLedgerAssert($rev['kategori'] === 'TERS_KAYIT' && (int) $rev['gun_delta'] === -8, 'reverse creates TERS_KAYIT -8');
    s2bLedgerAssert((int) $rev['reverses_id'] === (int) $row['id'], 'reverse link');
    s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSum($pdo, 1) === 6, 'netSum after reverse = 6');

    try {
        YillikIzinHakDuzeltmeLedgerService::reverse($pdo, 1, (int) $row['id'], ['id' => 1], 'again');
        s2bLedgerAssert(false, 'double reverse should throw');
    } catch (YillikIzinHakDuzeltmeException $e) {
        s2bLedgerAssert($e->getErrorCode() === 'ALREADY_REVERSED', 'double reverse rejected');
    }

    try {
        YillikIzinHakDuzeltmeLedgerService::reverse($pdo, 1, (int) $rev['id'], ['id' => 1], 'rev-of-rev');
        s2bLedgerAssert(false, 'reverse-of-reversal should throw');
    } catch (YillikIzinHakDuzeltmeException $e) {
        s2bLedgerAssert($e->getErrorCode() === 'INVALID_REVERSAL_TARGET', 'reversal-of-TERS rejected');
    }

    $pdo->exec('INSERT INTO personeller (id, sube_id) VALUES (2, 1)');
    try {
        YillikIzinHakDuzeltmeLedgerService::reverse($pdo, 2, (int) $row2['id'], ['id' => 1], 'cross');
        s2bLedgerAssert(false, 'cross-personel reverse should throw');
    } catch (YillikIzinHakDuzeltmeException $e) {
        s2bLedgerAssert($e->getErrorCode() === 'NOT_FOUND', 'cross-personel reverse rejected');
    }

    try {
        YillikIzinHakDuzeltmeLedgerService::create($pdo, 1, [
            'gun_delta' => 1,
            'kategori' => 'XYZ',
            'aciklama' => 'bad cat',
            'effective_date' => '2026-04-01',
        ], ['id' => 1]);
        s2bLedgerAssert(false, 'invalid category should throw');
    } catch (YillikIzinHakDuzeltmeException $e) {
        s2bLedgerAssert($e->getErrorCode() === 'VALIDATION_ERROR', 'invalid category rejected');
    }

    try {
        YillikIzinHakDuzeltmeLedgerService::create($pdo, 1, [
            'gun_delta' => 1,
            'kategori' => 'DEVIR',
            'aciklama' => '',
            'effective_date' => '2026-04-01',
        ], ['id' => 1]);
        s2bLedgerAssert(false, 'empty reason should throw');
    } catch (YillikIzinHakDuzeltmeException $e) {
        s2bLedgerAssert($e->getErrorCode() === 'VALIDATION_ERROR', 'empty reason rejected');
    }
} else {
    fwrite(STDOUT, "[SKIP] sqlite driver missing — ledger CRUD checks skipped\n");
}

// --- MariaDB acceptance when CI/local credentials present ---
$mysql = s2bLedgerRootPdo();
if ($mysql instanceof PDO) {
    $dbName = 'medisa_s2c_yihd_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $mysql->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $dsnBase = (string) (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $dsnParts = [];
    foreach (explode(';', $dsnBase) as $part) {
        if (stripos($part, 'dbname=') === 0) {
            continue;
        }
        $dsnParts[] = $part;
    }
    $dsnParts[] = 'dbname=' . $dbName;
    $pdoM = new PDO(
        implode(';', $dsnParts),
        (string) (getenv('MEDISA_TEST_MYSQL_USER') ?: ''),
        (string) (getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    try {
        $pdoM->exec(
            'CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                ad_soyad VARCHAR(120) NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdoM->exec(
            'CREATE TABLE personeller (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                sube_id INT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdoM->exec("INSERT INTO users (id, ad_soyad) VALUES (1, 'IK User')");
        $pdoM->exec('INSERT INTO personeller (id, sube_id) VALUES (1, 1)');
        $pdoM->exec($migration055);

        $idx = $pdoM->query("SHOW INDEX FROM yillik_izin_hak_duzeltmeleri WHERE Key_name = 'idx_yihd_personel_effective'")->fetchAll();
        s2bLedgerAssert(count($idx) >= 1, 'MariaDB idx_yihd_personel_effective present');

        $past = YillikIzinHakDuzeltmeLedgerService::create($pdoM, 1, [
            'gun_delta' => 4,
            'kategori' => 'DEVIR',
            'aciklama' => 'MariaDB past +4',
            'effective_date' => '2026-08-01',
        ], ['id' => 1]);
        YillikIzinHakDuzeltmeLedgerService::create($pdoM, 1, [
            'gun_delta' => 5,
            'kategori' => 'EK_HAK',
            'aciklama' => 'MariaDB future +5',
            'effective_date' => '2026-09-01',
        ], ['id' => 1]);
        s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdoM, 1, '2026-08-11') === 4, 'MariaDB as-of indexed sum Aug11=+4');
        s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdoM, 1, '2026-09-02') === 9, 'MariaDB as-of indexed sum Sep02=+9');

        $revM = YillikIzinHakDuzeltmeLedgerService::reverse($pdoM, 1, (int) $past['id'], ['id' => 1], 'MariaDB telafi');
        s2bLedgerAssert($revM['kategori'] === 'TERS_KAYIT' && $revM['effective_date'] === '2026-08-01', 'MariaDB FOR UPDATE reverse path');
        s2bLedgerAssert(YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdoM, 1, '2026-08-11') === 0, 'MariaDB as-of after reverse = 0');

        try {
            YillikIzinHakDuzeltmeLedgerService::reverse($pdoM, 1, (int) $past['id'], ['id' => 1], 'again');
            s2bLedgerAssert(false, 'MariaDB double reverse should throw');
        } catch (YillikIzinHakDuzeltmeException $e) {
            s2bLedgerAssert($e->getErrorCode() === 'ALREADY_REVERSED', 'MariaDB double reversal race protection');
        }

        $zeroRejected = false;
        try {
            $pdoM->exec(
                "INSERT INTO yillik_izin_hak_duzeltmeleri
                 (personel_id, gun_delta, kategori, aciklama, effective_date, created_by, reverses_id)
                 VALUES (1, 0, 'DEVIR', 'zero', '2026-08-01', 1, NULL)"
            );
        } catch (PDOException $e) {
            $zeroRejected = true;
        }
        s2bLedgerAssert($zeroRejected, 'MariaDB CHECK gun_delta nonzero');

        $selfFkRejected = false;
        try {
            $pdoM->exec(
                "INSERT INTO yillik_izin_hak_duzeltmeleri
                 (personel_id, gun_delta, kategori, aciklama, effective_date, created_by, reverses_id)
                 VALUES (1, -1, 'TERS_KAYIT', 'no target', '2026-08-01', 1, NULL)"
            );
        } catch (PDOException $e) {
            $selfFkRejected = true;
        }
        s2bLedgerAssert($selfFkRejected, 'MariaDB CHECK TERS requires reverses_id / self FK');

        fwrite(STDOUT, "[PASS] MariaDB 055 runtime acceptance\n");
    } finally {
        $mysql->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
    }
} else {
    fwrite(STDOUT, "[SKIP] MariaDB credentials absent — matrix/source/SQLite path sufficient\n");
}

fwrite(STDOUT, "S2B ledger mysql runner OK\n");
