<?php

declare(strict_types=1);

/**
 * Tam veritabanı yedeği: döküm, izole geri yükleme, yetki şema sınıflaması.
 * Canlıya dokunmaz. php tests/php/FullDatabaseBackupMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\FullDatabaseBackupService;

function fdbAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function fdbPdo(string $dsn): PDO
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
$root = fdbPdo($serverDsn);
$suffix = bin2hex(random_bytes(4));
$liveName = 'fdb_live_' . $suffix;
$manualName = $liveName . '_rmanual';
$sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fdb-' . $suffix;
$apiDirectory = $sandbox . '/public_html/personelmedisa/api';
mkdir($apiDirectory, 0777, true);
putenv('MEDISA_MIGRATION_BACKUP_DIR');

$root->exec('CREATE DATABASE `' . $liveName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec('CREATE DATABASE `' . $manualName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$live = fdbPdo($serverDsn . ';dbname=' . $liveName);
$live->exec('CREATE TABLE medisa_schema_migrations (version CHAR(3) NOT NULL PRIMARY KEY)');
$live->exec("INSERT INTO medisa_schema_migrations (version) VALUES ('099')");
$live->exec('CREATE TABLE items (id INT NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)');
$live->exec("INSERT INTO items (id, name) VALUES (1, 'Ayşe;Yılmaz'), (2, 'Deneme')");
$live->exec('CREATE TABLE item_lines (
    id INT NOT NULL PRIMARY KEY,
    item_id INT NOT NULL,
    CONSTRAINT fk_line_item FOREIGN KEY (item_id) REFERENCES items (id)
)');
$live->exec('INSERT INTO item_lines (id, item_id) VALUES (1, 1)');
$live->exec("CREATE TRIGGER trg_items_no_delete BEFORE DELETE ON items FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'no'");
$live->exec('CREATE VIEW item_names AS SELECT id, name FROM items');

$sha = str_repeat('a', 40);
$evidence = FullDatabaseBackupService::capture(
    $live,
    $apiDirectory,
    'req-full-1',
    $sha,
    $serverDsn,
    getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
    getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: ''
);

fdbAssert(
    ($evidence['result'] ?? '') === 'PASS',
    'tam yedek ve izole geri yükleme PASS ' . (string) ($evidence['failure_reason'] ?? '')
);
fdbAssert(($evidence['yetki_schema'] ?? '') === 'ABSENT', 'üç yetki tablosu yokken sınıflama ABSENT');
fdbAssert(($evidence['restore_verified'] ?? false) === true, 'geri yükleme doğrulandı');
fdbAssert(($evidence['dump_transferred'] ?? true) === false, 'döküm aktarılmadı');
fdbAssert(($evidence['dump_channel'] ?? '') === 'NONE', 'döküm kanalı yok');
fdbAssert(($evidence['outside_webroot'] ?? false) === true, 'yedek web kökünün dışında');
fdbAssert(($evidence['table_count'] ?? 0) === ($evidence['restore_table_count'] ?? -1), 'tablo sayısı kaynakla aynı');
fdbAssert(($evidence['total_rows'] ?? 0) === ($evidence['restore_total_rows'] ?? -1), 'satır sayısı kaynakla aynı');
fdbAssert(($evidence['trigger_count'] ?? 0) === 1, 'tetikleyici yedeğe girdi');
fdbAssert(($evidence['view_count'] ?? 0) === 1, 'görünüm yedeğe girdi');
fdbAssert(!array_key_exists('absolute_path', $evidence), 'yayınlanan kanıtta sunucu yolu yok');

$encoded = (string) json_encode($evidence);
fdbAssert(strpos($encoded, 'Ayşe') === false && strpos($encoded, 'Yılmaz') === false, 'kanıt satır içeriği taşımaz');

$dumpPath = $sandbox . '/medisa-migration-backups/' . $evidence['backup_file'];
fdbAssert(is_file($dumpPath), 'döküm dosyası oluştu');
fdbAssert(strpos((string) realpath($dumpPath), (string) realpath($sandbox . '/public_html')) === false, 'döküm public_html içinde değil');
fdbAssert(hash_file('sha256', $dumpPath) === ($evidence['backup_sha256'] ?? ''), 'dosya özeti kanıtla aynı');

$manual = fdbPdo($serverDsn . ';dbname=' . $manualName);
FullDatabaseBackupService::restoreInto($manual, $dumpPath, $liveName);
$restoredName = (string) $manual->query('SELECT name FROM items WHERE id = 1')->fetchColumn();
fdbAssert($restoredName === 'Ayşe;Yılmaz', 'noktalı virgül ve Türkçe karakter geri geldi');
fdbAssert((int) $manual->query('SELECT COUNT(*) FROM item_names')->fetchColumn() === 2, 'görünüm geri yüklendi');
fdbAssert((int) $manual->query('SELECT COUNT(*) FROM item_lines')->fetchColumn() === 1, 'ilişkili satır geri yüklendi');
$triggerBlocked = false;
try {
    $manual->exec('DELETE FROM items WHERE id = 2');
} catch (Throwable $exception) {
    $triggerBlocked = true;
}
fdbAssert($triggerBlocked, 'tetikleyici geri yüklenen veritabanında silmeyi engelledi');
fdbAssert((int) $manual->query('SELECT COUNT(*) FROM items')->fetchColumn() === 2, 'engellenen silme satırı düşürmedi');

$refused = false;
try {
    FullDatabaseBackupService::restoreInto($live, $dumpPath, $liveName);
} catch (Throwable $exception) {
    $refused = $exception->getMessage() === 'RESTORE_TARGET_REFUSED';
}
fdbAssert($refused, 'canlı veritabanının üzerine geri yükleme reddedildi');

$live->exec('CREATE TABLE user_yetki_istisnalari (id INT NOT NULL PRIMARY KEY)');
$partial = FullDatabaseBackupService::capture(
    $live,
    $apiDirectory,
    'req-full-2',
    $sha,
    $serverDsn,
    getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
    getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: ''
);
fdbAssert(($partial['result'] ?? '') === 'PASS', 'kısmi şemada yedek yine tamamlanır');
fdbAssert(($partial['yetki_schema'] ?? '') === 'PARTIAL', 'tek yetki tablosu PARTIAL sayılır');

putenv('MEDISA_MIGRATION_BACKUP_DIR=' . $sandbox . '/public_html/dumps');
$inside = FullDatabaseBackupService::capture(
    $live,
    $apiDirectory,
    'req-full-3',
    $sha,
    $serverDsn,
    getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
    getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: ''
);
fdbAssert(
    ($inside['result'] ?? '') === 'FAIL' && ($inside['failure_reason'] ?? '') === 'BACKUP_LOCATION_INSIDE_WEBROOT',
    'web köküne yazma reddedilir'
);

$root->exec('DROP DATABASE IF EXISTS `' . $manualName . '`');
$root->exec('DROP DATABASE IF EXISTS `' . $liveName . '`');
echo 'verify-full-database-backup-mysql: OK' . PHP_EOL;
