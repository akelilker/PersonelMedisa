<?php

declare(strict_types=1);

/**
 * Ayarlar > Veri Yedekleme: Yedek Al -> izole geri yükleme gidiş-dönüşü.
 * Canlıya dokunmaz. php tests/php/VeriYedeklemeMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\FullDatabaseBackupService;

function vyAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function vyPdo(string $dsn): PDO
{
    return new PDO($dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: 'root', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    throw new RuntimeException('MEDISA_TEST_MYSQL_DSN missing');
}
$serverDsn = (string) preg_replace('/;?dbname=[^;]*/i', '', $dsn);
$root = vyPdo($serverDsn);
$suffix = bin2hex(random_bytes(4));
$liveName = 'vy_live_' . $suffix;
$restoreName = $liveName . '_r1';
$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vy-' . $suffix;
$apiDirectory = $sandbox . '/public_html/personelmedisa/api';
mkdir($apiDirectory, 0777, true);
putenv('MEDISA_MIGRATION_BACKUP_DIR');

try {
    $root->exec('CREATE DATABASE `' . $liveName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $root->exec('CREATE DATABASE `' . $restoreName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $live = vyPdo($serverDsn . ';dbname=' . $liveName);
    $live->exec('CREATE TABLE medisa_schema_migrations (version CHAR(3) NOT NULL PRIMARY KEY)');
    $live->exec("INSERT INTO medisa_schema_migrations (version) VALUES ('099')");
    $live->exec('CREATE TABLE personel (id INT NOT NULL PRIMARY KEY, ad VARCHAR(64) NOT NULL, notlar TEXT NULL, tutar DECIMAL(12,2) NULL)');
    $live->exec("INSERT INTO personel VALUES (1, 'Şükrü Öztürk', 'satır;\nikinci', 1234.56), (2, 'İlker', NULL, NULL)");
    $live->exec('CREATE TABLE bordro (id INT NOT NULL PRIMARY KEY, personel_id INT NOT NULL,
        CONSTRAINT fk_bordro_p FOREIGN KEY (personel_id) REFERENCES personel (id))');
    $live->exec('INSERT INTO bordro VALUES (1, 1), (2, 2), (3, 1)');
    $live->exec("CREATE TRIGGER trg_personel_no_delete BEFORE DELETE ON personel FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'no'");

    $meta = FullDatabaseBackupService::createAppBackup($live, $apiDirectory, 'app-test-1');
    vyAssert(FullDatabaseBackupService::isAppBackupName($meta['file']), 'dosya adı kalıba uyar');
    vyAssert(strlen($meta['sha256']) === 64 && $meta['ledger_tip'] === '099', 'sha256 ve migration defteri yazıldı');
    vyAssert($meta['table_count'] === 3 && $meta['total_rows'] === 6, 'tablo ve satır sayısı doğru');
    vyAssert(!$live->inTransaction(), 'anlık görüntü işlemi kapandı');
    vyAssert(strpos($meta['file'], '/') === false && !isset($meta['absolute_path']), 'mutlak yol dışarı verilmez');

    $path = FullDatabaseBackupService::resolveAppBackupForDownload($apiDirectory, $meta['file']);
    vyAssert(strpos(str_replace('\\', '/', $path), '/public_html/') === false, 'yedek web kökü dışında');
    vyAssert(hash_file('sha256', $path) === $meta['sha256'], 'indirilecek dosyanın sha256 değeri eşleşir');

    $restored = vyPdo($serverDsn . ';dbname=' . $restoreName);
    FullDatabaseBackupService::restoreInto($restored, $path, $liveName);
    vyAssert(FullDatabaseBackupService::mismatchCodes($live, $restored) === [], 'geri yükleme: şema, satır sayısı ve içerik checksum farkı yok');
    $row = $restored->query('SELECT ad, notlar, tutar FROM personel WHERE id = 1')->fetch();
    vyAssert($row['ad'] === 'Şükrü Öztürk' && $row['notlar'] === "satır;\nikinci" && $row['tutar'] === '1234.56', 'Türkçe karakter, çok satır ve ondalık korunur');

    $listed = FullDatabaseBackupService::listAppBackups($apiDirectory);
    vyAssert(count($listed) === 1 && $listed[0]['file'] === $meta['file'], 'durum son yedeği gösterir');

    foreach (['../x.sql', 'medisa-full-099-a-20260101-000000.sql', 'medisa-yedek-x.sql'] as $bad) {
        $refused = false;
        try {
            FullDatabaseBackupService::resolveAppBackupForDownload($apiDirectory, $bad);
        } catch (RuntimeException $e) {
            $refused = $e->getMessage() === 'APP_BACKUP_NOT_FOUND';
        }
        vyAssert($refused, 'geçersiz ad reddedilir ' . $bad);
    }

    file_put_contents($path, "\n-- değiştirildi", FILE_APPEND);
    $tampered = false;
    try {
        FullDatabaseBackupService::resolveAppBackupForDownload($apiDirectory, $meta['file']);
    } catch (RuntimeException $e) {
        $tampered = $e->getMessage() === 'APP_BACKUP_SHA_MISMATCH';
    }
    vyAssert($tampered, 'değiştirilmiş yedek indirilmez');

    for ($i = 0; $i < 8; $i++) {
        FullDatabaseBackupService::createAppBackup($live, $apiDirectory, 'app-test-loop');
        usleep(1100000);
    }
    vyAssert(count(FullDatabaseBackupService::listAppBackups($apiDirectory)) === 7, 'yalnız son 7 yedek saklanır');
    vyAssert(!is_file($apiDirectory . '/../../full-backup-evidence.json'), 'migration kanıt dosyası üretilmez');
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $liveName . '`');
    $root->exec('DROP DATABASE IF EXISTS `' . $restoreName . '`');
}

echo 'verify-veri-yedekleme-mysql: OK' . PHP_EOL;
