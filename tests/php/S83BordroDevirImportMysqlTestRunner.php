<?php

declare(strict_types=1);

/**
 * S83 MariaDB acceptance: PersonelBordroDevirService::processImport classification + commit/rollback.
 * Service-level only (no HTTP child). Requires MEDISA_TEST_MYSQL_DSN.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\MaasHesaplamaException;
use Medisa\Api\Services\PersonelBordroDevirService;

function s83DevirAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s83DevirPdo(string $dsn): PDO
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

function s83DevirApplySqlFile(PDO $pdo, string $path): void
{
    $sql = (string) file_get_contents($path);
    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $chunk) {
        $statement = trim((string) $chunk);
        if ($statement === '' || str_starts_with($statement, '--') || str_starts_with(strtoupper($statement), 'SET ')) {
            continue;
        }
        $pdo->exec($statement);
    }
}

function s83AktifDevirCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM personel_bordro_devirleri WHERE state = 'AKTIF'")->fetchColumn();
}

$adminDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$userName = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
if ($adminDsn === '' || $userName === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN/USER required for S83 bordro devir import MariaDB acceptance\n");
    exit(1);
}

if (!extension_loaded('pdo_mysql') && !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "pdo_mysql driver missing\n");
    exit(1);
}

$admin = s83DevirPdo($adminDsn);
$database = 'medisa_s83_devir_import_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $adminDsn);
    putenv('MEDISA_TEST_MYSQL_DSN=' . $dsn);
    $_ENV['MEDISA_TEST_MYSQL_DSN'] = $dsn;

    $pdo = s83DevirPdo($dsn);

    $pdo->exec("
        CREATE TABLE users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad_soyad VARCHAR(120) NOT NULL DEFAULT 'Test User',
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
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
        CREATE TABLE personeller (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(100) NOT NULL,
          soyad VARCHAR(100) NOT NULL,
          sicil_no VARCHAR(40) NOT NULL,
          sube_id INT UNSIGNED NOT NULL,
          departman_id INT UNSIGNED NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id),
          UNIQUE KEY uq_personeller_sicil (sicil_no),
          KEY idx_personeller_sube (sube_id),
          CONSTRAINT fk_personeller_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    s83DevirApplySqlFile($pdo, __DIR__ . '/../../api/migrations/022_personel_bordro_devirleri.sql');

    // Import audit table from 034 (skip unrelated ALTER chunks).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS personel_bordro_devir_importlari (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          sube_id INT UNSIGNED NOT NULL,
          yil SMALLINT UNSIGNED NOT NULL,
          ay TINYINT UNSIGNED NOT NULL,
          dry_run TINYINT(1) NOT NULL DEFAULT 0,
          toplam_satir INT UNSIGNED NOT NULL DEFAULT 0,
          basarili_satir INT UNSIGNED NOT NULL DEFAULT 0,
          hatali_satir INT UNSIGNED NOT NULL DEFAULT 0,
          hata_ozeti JSON NULL,
          actor_id INT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_pbdi_sube_donem (sube_id, yil, ay),
          CONSTRAINT fk_pbdi_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
          CONSTRAINT fk_pbdi_actor FOREIGN KEY (actor_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("INSERT INTO users (id, ad_soyad) VALUES (1, 'Muhasebe Test')");
    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'S1', 'Sube 1'), (2, 'S2', 'Sube 2')");
    $pdo->exec("
        INSERT INTO personeller (id, ad, soyad, sicil_no, sube_id, departman_id, durum) VALUES
          (1, 'Ali', 'Yilmaz', 'P-001', 1, NULL, 'AKTIF'),
          (2, 'Ayse', 'Demir', 'P-002', 2, NULL, 'AKTIF')
    ");

    $actor = ['id' => 1, 'rol' => 'MUHASEBE'];
    $yil = 2026;
    $ay = 3;
    $subeId = 1;

    $validRow = [
        'sicil' => 'P-001',
        'onceki_kumulatif_gelir_vergisi_matrahi' => '12345.67',
        'onceki_kumulatif_gelir_vergisi' => '987.65',
        'onceki_kumulatif_sgk_matrahi' => '100.00',
    ];

    $dryEkle = PersonelBordroDevirService::processImport($pdo, $subeId, $yil, $ay, [$validRow], true, $actor);
    s83DevirAssert((int) $dryEkle['eklenecek'] === 1, 'dry_run: eklenecek for valid sicil');
    s83DevirAssert(s83AktifDevirCount($pdo) === 0, 'dry_run leaves zero AKTIF rows');

    $dryEslesmeyen = PersonelBordroDevirService::processImport(
        $pdo,
        $subeId,
        $yil,
        $ay,
        [['sicil' => 'UNKNOWN', 'onceki_kumulatif_gelir_vergisi_matrahi' => '1', 'onceki_kumulatif_gelir_vergisi' => '1']],
        true,
        $actor
    );
    s83DevirAssert((int) $dryEslesmeyen['eslesmeyen'] === 1, 'dry_run: eslesmeyen for unknown');

    $dryScope = PersonelBordroDevirService::processImport(
        $pdo,
        $subeId,
        $yil,
        $ay,
        [['sicil' => 'P-002', 'onceki_kumulatif_gelir_vergisi_matrahi' => '1', 'onceki_kumulatif_gelir_vergisi' => '1']],
        true,
        $actor
    );
    s83DevirAssert((int) $dryScope['scope_disi'] === 1, 'dry_run: scope_disi for other-sube sicil');

    $dryDup = PersonelBordroDevirService::processImport(
        $pdo,
        $subeId,
        $yil,
        $ay,
        [$validRow, $validRow],
        true,
        $actor
    );
    s83DevirAssert((int) $dryDup['eklenecek'] === 1 && (int) $dryDup['duplicate'] === 1, 'dry_run: duplicate for same sicil twice');

    $dryHatali = PersonelBordroDevirService::processImport(
        $pdo,
        $subeId,
        $yil,
        $ay,
        [[
            'sicil' => 'P-001',
            'onceki_kumulatif_gelir_vergisi_matrahi' => '-10.00',
            'onceki_kumulatif_gelir_vergisi' => '1.00',
        ]],
        true,
        $actor
    );
    s83DevirAssert((int) $dryHatali['hatali'] === 1, 'dry_run: hatali for negative money');

    $threw = false;
    try {
        PersonelBordroDevirService::processImport(
            $pdo,
            $subeId,
            $yil,
            $ay,
            [
                $validRow,
                ['sicil' => 'UNKNOWN', 'onceki_kumulatif_gelir_vergisi_matrahi' => '1', 'onceki_kumulatif_gelir_vergisi' => '1'],
            ],
            false,
            $actor
        );
    } catch (MaasHesaplamaException $e) {
        $threw = $e->getCodeString() === 'DEVIR_IMPORT_VALIDATION_FAILED';
    }
    s83DevirAssert($threw, 'commit with any invalid row throws DEVIR_IMPORT_VALIDATION_FAILED');
    s83DevirAssert(s83AktifDevirCount($pdo) === 0, 'commit with invalid leaves zero AKTIF rows (no partial)');

    $commit = PersonelBordroDevirService::processImport($pdo, $subeId, $yil, $ay, [$validRow], false, $actor);
    s83DevirAssert((int) $commit['eklenecek'] === 1, 'commit dry_run=false classifies eklenecek');
    s83DevirAssert(s83AktifDevirCount($pdo) === 1, 'commit dry_run=false with all-valid rows inserts AKTIF row');

    $aktif = $pdo->query(
        "SELECT onceki_kumulatif_gelir_vergisi_matrahi, onceki_kumulatif_gelir_vergisi, onceki_kumulatif_sgk_matrahi
         FROM personel_bordro_devirleri WHERE state = 'AKTIF' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    s83DevirAssert(
        is_array($aktif)
            && (string) $aktif['onceki_kumulatif_gelir_vergisi_matrahi'] === '12345.67'
            && (string) $aktif['onceki_kumulatif_gelir_vergisi'] === '987.65',
        'AKTIF row stores decimal money without float drift'
    );

    $second = PersonelBordroDevirService::processImport($pdo, $subeId, $yil, $ay, [$validRow], false, $actor);
    s83DevirAssert((int) $second['degismeyecek'] === 1, 'second commit same values → degismeyecek');
    s83DevirAssert(s83AktifDevirCount($pdo) === 1, 'second commit same values → no duplicate AKTIF');

    echo 'verify-s83-devir-import-mysql: OK' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    if (isset($admin, $database)) {
        try {
            $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        } catch (Throwable $ignore) {
        }
    }
    exit(1);
}

try {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
} catch (Throwable $ignore) {
}
