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
fdbAssert(
    ($evidence['restore_privilege'] ?? '') === 'UNPROVEN',
    'grant tablosu kapalı kök hesabı hosting yetkisi sayılmaz'
);
fdbAssert(
    FullDatabaseBackupService::classifyGrantLines([
        "GRANT USAGE ON *.* TO 'cpanel'@'localhost'",
        "GRANT ALL PRIVILEGES ON `karmotor_medisa`.* TO 'cpanel'@'localhost'",
    ]) === 'ABSENT',
    'yalnız canlı veritabanındaki yetki kardeş veritabanı açamaz'
);
fdbAssert(
    FullDatabaseBackupService::classifyGrantLines([
        "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON *.* TO 'ops'@'localhost'",
    ]) === 'PRESENT',
    'genel CREATE ve DROP yetkisi ayrılır'
);
fdbAssert(($evidence['schema_version'] ?? '') === '2', 'kanıt şema sürümü 2');
fdbAssert(($evidence['content_mismatch_count'] ?? 1) === 0, 'içerik farkı yok');
fdbAssert(($evidence['definition_mismatch_count'] ?? 1) === 0, 'tanım farkı yok');
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
fdbAssert(FullDatabaseBackupService::mismatchCodes($live, $manual) === [], 'içerik ve tanım kaynakla aynı');
$manual->exec("UPDATE items SET name = 'bozuk' WHERE id = 1");
fdbAssert(
    in_array('CONTENT_HASH', FullDatabaseBackupService::mismatchCodes($live, $manual), true),
    'değişen satır içerik bütünlüğünde yakalanır'
);
FullDatabaseBackupService::restoreInto($manual, $dumpPath, $liveName);
$manual->exec('DROP TRIGGER trg_items_no_delete');
$manual->exec("CREATE TRIGGER trg_items_no_delete BEFORE DELETE ON items FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'farkli'");
fdbAssert(
    in_array('TRIGGER_BODY', FullDatabaseBackupService::mismatchCodes($live, $manual), true),
    'değişen tetikleyici gövdesi yakalanır'
);

$control = $sandbox . '/control';
mkdir($control);
$evidencePath = $control . '/full-backup-evidence.json';
file_put_contents($evidencePath, (string) json_encode($evidence));
$unprovenBlocked = false;
try {
    FullDatabaseBackupService::assertReadyForApply($live, $apiDirectory, $control, $sha);
} catch (Throwable $exception) {
    $unprovenBlocked = $exception->getMessage() === 'RESTORE_PRIVILEGE_ABSENT';
}
fdbAssert($unprovenBlocked, 'yetkisi kanıtlanmamış yedek APPLY kapısını kapatır');

$proven = $evidence;
$proven['restore_privilege'] = 'VERIFIED';
file_put_contents($evidencePath, (string) json_encode($proven));
$gateOk = false;
try {
    FullDatabaseBackupService::assertReadyForApply($live, $apiDirectory, $control, $sha);
    $gateOk = true;
} catch (Throwable $exception) {
    $gateOk = false;
}
fdbAssert($gateOk, 'dosyası ve özeti tutan yedek kapıdan geçer');
$evidence = $proven;

$tampered = $evidence;
$tampered['backup_sha256'] = str_repeat('b', 64);
file_put_contents($evidencePath, (string) json_encode($tampered));
$shaBlocked = false;
try {
    FullDatabaseBackupService::assertReadyForApply($live, $apiDirectory, $control, $sha);
} catch (Throwable $exception) {
    $shaBlocked = $exception->getMessage() === 'FULL_BACKUP_SHA_MISMATCH';
}
fdbAssert($shaBlocked, 'bozuk SHA-256 APPLY kapısını kapatır');

$stale = $evidence;
$stale['generated_at'] = '2020-01-01T00:00:00Z';
file_put_contents($evidencePath, (string) json_encode($stale));
$staleBlocked = false;
try {
    FullDatabaseBackupService::assertReadyForApply($live, $apiDirectory, $control, $sha);
} catch (Throwable $exception) {
    $staleBlocked = $exception->getMessage() === 'FULL_BACKUP_STALE';
}
fdbAssert($staleBlocked, 'süresi geçmiş yedek APPLY kapısını kapatır');

file_put_contents($evidencePath, (string) json_encode($evidence));
$hiddenDump = $dumpPath . '.aside';
rename($dumpPath, $hiddenDump);
$fileBlocked = false;
try {
    FullDatabaseBackupService::assertReadyForApply($live, $apiDirectory, $control, $sha);
} catch (Throwable $exception) {
    $fileBlocked = $exception->getMessage() === 'FULL_BACKUP_FILE_MISSING';
}
rename($hiddenDump, $dumpPath);
fdbAssert($fileBlocked, 'olmayan yedek dosyası APPLY kapısını kapatır');

$deployBlocked = false;
try {
    FullDatabaseBackupService::assertReadyForApply($live, $apiDirectory, $control, str_repeat('c', 40));
} catch (Throwable $exception) {
    $deployBlocked = $exception->getMessage() === 'FULL_BACKUP_DEPLOY_SHA_MISMATCH';
}
fdbAssert($deployBlocked, 'uygulama sürümü uymayan yedek APPLY kapısını kapatır');

$live->exec("INSERT INTO medisa_schema_migrations (version) VALUES ('100')");
$ledgerBlocked = false;
try {
    FullDatabaseBackupService::assertReadyForApply($live, $apiDirectory, $control, $sha);
} catch (Throwable $exception) {
    $ledgerBlocked = $exception->getMessage() === 'FULL_BACKUP_LEDGER_MISMATCH';
}
$live->exec("DELETE FROM medisa_schema_migrations WHERE version = '100'");
fdbAssert($ledgerBlocked, 'migration seviyesi uymayan yedek APPLY kapısını kapatır');

$refused = false;
try {
    FullDatabaseBackupService::restoreInto($live, $dumpPath, $liveName);
} catch (Throwable $exception) {
    $refused = $exception->getMessage() === 'RESTORE_TARGET_REFUSED';
}
fdbAssert($refused, 'canlı veritabanının üzerine geri yükleme reddedildi');

$live->exec('CREATE TABLE user_yetki_istisnalari (id INT NOT NULL PRIMARY KEY)');
file_put_contents($evidencePath, (string) json_encode($evidence));
$yetkiBlocked = false;
try {
    FullDatabaseBackupService::assertReadyForApply($live, $apiDirectory, $control, $sha);
} catch (Throwable $exception) {
    $yetkiBlocked = $exception->getMessage() === 'YETKI_SCHEMA_NOT_ABSENT';
}
fdbAssert($yetkiBlocked, 'işlem öncesi yetki tablosu APPLY kapısını kapatır');
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

$brokenPath = $dumpPath . '.broken';
file_put_contents($brokenPath, "S20\nNOT_A_COMPLETE");
$brokenBlocked = false;
try {
    FullDatabaseBackupService::restoreInto($manual, $brokenPath, $liveName);
} catch (Throwable $exception) {
    $brokenBlocked = $exception->getMessage() === 'BACKUP_READBACK_INCOMPLETE';
}
fdbAssert($brokenBlocked, 'bozuk yedek geri yüklenmez');

$classifier = new ReflectionClass(Medisa\Api\Database\MigrationExecutionService::class);
$classify = $classifier->getMethod('classify');
foreach ([
    'RESTORE_PRIVILEGE_ABSENT',
    'RESTORE_TARGET_PRIVILEGE_ABSENT',
    'RESTORE_TARGET_OCCUPIED',
    'RESTORE_DATABASE_CLEANUP_FAILED',
    'FULL_BACKUP_RESTORE_MISMATCH',
    'FULL_DATABASE_BACKUP_FAILED',
] as $reasonCode) {
    fdbAssert(
        $classify->invoke(null, new RuntimeException($reasonCode)) === $reasonCode,
        'hata kodu korunur ' . $reasonCode
    );
}

putenv('MEDISA_MIGRATION_BACKUP_DIR');
fdbDedicatedScenarios($serverDsn);

$root->exec('DROP DATABASE IF EXISTS `' . $manualName . '`');
$root->exec('DROP DATABASE IF EXISTS `' . $liveName . '`');
echo 'verify-full-database-backup-mysql: OK' . PHP_EOL;

function fdbDedicatedScenarios(string $ignoredDsn): void
{
    unset($ignoredDsn);
    $mysqld = fdbMysqldBinary();
    $install = dirname($mysqld) . DIRECTORY_SEPARATOR . 'mariadb-install-db.exe';
    if (!is_file($install)) {
        throw new RuntimeException('GRANT_SERVER_INSTALL_MISSING');
    }
    $suffix = bin2hex(random_bytes(4));
    $liveName = 'fdb_live_' . $suffix;
    $restoreName = 'fdb_restore_' . $suffix;
    $missingName = 'fdb_missing_' . $suffix;
    $rootUser = 'fdbroot' . $suffix;
    $appUser = 'fdbapp' . $suffix;
    $weakUser = 'fdbweak' . $suffix;
    $rootPassword = bin2hex(random_bytes(12));
    $appPassword = bin2hex(random_bytes(12));
    $weakPassword = bin2hex(random_bytes(12));
    $port = fdbFreePort();
    $dataDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fdb-grants-' . $suffix;
    $initFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fdb-grants-' . $suffix . '.sql';
    $logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fdb-grants-' . $suffix . '.log';
    mkdir($dataDir, 0777, true);
    $installRun = proc_open(
        [$install, '--datadir=' . $dataDir, '--password='],
        [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'a']],
        $unused,
        null,
        null
    );
    if (!is_resource($installRun) || proc_close($installRun) !== 0) {
        throw new RuntimeException('GRANT_SERVER_BOOTSTRAP_FAILED');
    }
    file_put_contents($initFile, implode(";\n", [
        'CREATE DATABASE ' . fdbIdent($liveName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE DATABASE ' . fdbIdent($restoreName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE USER ' . fdbAccount($rootUser) . ' IDENTIFIED BY ' . fdbSqlString($rootPassword),
        'GRANT ALL PRIVILEGES ON *.* TO ' . fdbAccount($rootUser) . ' WITH GRANT OPTION',
        'CREATE USER ' . fdbAccount($appUser) . ' IDENTIFIED BY ' . fdbSqlString($appPassword),
        'GRANT ALL PRIVILEGES ON ' . fdbIdent($liveName) . '.* TO ' . fdbAccount($appUser),
        'GRANT ALL PRIVILEGES ON ' . fdbIdent($restoreName) . '.* TO ' . fdbAccount($appUser),
        'CREATE USER ' . fdbAccount($weakUser) . ' IDENTIFIED BY ' . fdbSqlString($weakPassword),
        'GRANT SELECT ON ' . fdbIdent($liveName) . '.* TO ' . fdbAccount($weakUser),
        'GRANT SELECT ON ' . fdbIdent($restoreName) . '.* TO ' . fdbAccount($weakUser),
        'FLUSH PRIVILEGES',
    ]) . ";\n");

    $process = proc_open(
        [
            $mysqld,
            '--datadir=' . $dataDir,
            '--port=' . (string) $port,
            '--bind-address=127.0.0.1',
            '--init-file=' . $initFile,
            '--console',
        ],
        [1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']],
        $pipes,
        null,
        null
    );
    if (!is_resource($process)) {
        throw new RuntimeException('GRANT_SERVER_START_FAILED');
    }
    $GLOBALS['fdbGrantProcess'] = $process;
    register_shutdown_function(static function () use ($initFile): void {
        $running = $GLOBALS['fdbGrantProcess'] ?? null;
        if (is_resource($running)) {
            proc_terminate($running);
        }
        @unlink($initFile);
    });

    $dsn = 'mysql:host=127.0.0.1;port=' . $port . ';charset=utf8mb4';
    $admin = fdbWaitPdo($dsn, $rootUser, $rootPassword);
    $app = fdbPdoOn($dsn . ';dbname=' . $liveName, $appUser, $appPassword);
    $current = (string) $app->query('SELECT CURRENT_USER()')->fetchColumn();
    fdbAssert(strpos($current, $appUser . '@') === 0, 'deneme hesabı kök hesabı değil');
    $grantLines = $app->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN);
    $grantLines = array_values(array_filter($grantLines, 'is_string'));
    fdbAssert(
        FullDatabaseBackupService::classifyGrantLines($grantLines) === 'ABSENT',
        'genel CREATE ve DROP yetkisi olmayan hesap ayrılır'
    );

    global $config;
    $previousRestore = $config['db_restore_name'] ?? '';
    $sandbox = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fdb-dedicated-' . $suffix;
    $apiDirectory = $sandbox . '/public_html/personelmedisa/api';
    mkdir($apiDirectory, 0777, true);
    $sha = str_repeat('d', 40);
    try {
        $config['db_restore_name'] = $missingName;
        $missing = FullDatabaseBackupService::capture($app, $apiDirectory, 'req-missing', $sha, $dsn, $appUser, $appPassword);
        fdbAssert(
            ($missing['result'] ?? '') === 'FAIL'
            && ($missing['failure_reason'] ?? '') === 'RESTORE_DATABASE_UNAVAILABLE',
            'olmayan hazır geri yükleme veritabanı reddedilir'
        );

        $config['db_restore_name'] = $liveName;
        $same = FullDatabaseBackupService::capture($app, $apiDirectory, 'req-same', $sha, $dsn, $appUser, $appPassword);
        fdbAssert(
            ($same['result'] ?? '') === 'FAIL' && ($same['failure_reason'] ?? '') === 'RESTORE_TARGET_REFUSED',
            'hedef canlı veritabanıyla aynıysa reddedilir'
        );

        $weak = fdbPdoOn($dsn . ';dbname=' . $liveName, $weakUser, $weakPassword);
        $config['db_restore_name'] = $restoreName;
        $weakResult = FullDatabaseBackupService::capture($weak, $apiDirectory, 'req-weak', $sha, $dsn, $weakUser, $weakPassword);
        fdbAssert(
            ($weakResult['result'] ?? '') === 'FAIL'
            && ($weakResult['failure_reason'] ?? '') === 'RESTORE_TARGET_PRIVILEGE_ABSENT',
            'hazır veritabanında yetersiz yetki reddedilir'
        );

        $admin->exec('CREATE TABLE ' . fdbIdent($restoreName) . '.app_marker (id INT NOT NULL PRIMARY KEY)');
        $occupied = FullDatabaseBackupService::capture($app, $apiDirectory, 'req-occupied', $sha, $dsn, $appUser, $appPassword);
        $markerRemains = (int) $admin->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . fdbSqlString($restoreName) . " AND TABLE_NAME = 'app_marker'"
        )->fetchColumn();
        fdbAssert(
            ($occupied['result'] ?? '') === 'FAIL'
            && ($occupied['failure_reason'] ?? '') === 'RESTORE_TARGET_OCCUPIED'
            && $markerRemains === 1,
            'başka uygulama verisi silinmeden işlem durur'
        );
        $admin->exec('DROP TABLE ' . fdbIdent($restoreName) . '.app_marker');

        fdbSeedLive($app);
        $dedicated = FullDatabaseBackupService::capture($app, $apiDirectory, 'req-dedicated', $sha, $dsn, $appUser, $appPassword);
        fdbAssert(
            ($dedicated['result'] ?? '') === 'PASS'
            && ($dedicated['restore_target'] ?? '') === 'DEDICATED'
            && ($dedicated['restore_privilege'] ?? '') === 'VERIFIED'
            && ($dedicated['restore_verified'] ?? false) === true
            && ($dedicated['content_mismatch_count'] ?? 1) === 0
            && ($dedicated['definition_mismatch_count'] ?? 1) === 0
            && ($dedicated['schema_mismatch_count'] ?? 1) === 0
            && ($dedicated['yetki_schema'] ?? '') === 'ABSENT',
            'genel yetki olmadan hazır veritabanına tam yedek PASS'
        );
        $databaseRemains = (int) $admin->query(
            'SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ' . fdbSqlString($restoreName)
        )->fetchColumn();
        $objectsRemain = (int) $admin->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . fdbSqlString($restoreName)
        )->fetchColumn();
        fdbAssert($databaseRemains === 1 && $objectsRemain === 0, 'hazır veritabanı kalır ve test nesneleri temizlenir');

        $control = $sandbox . '/control';
        mkdir($control);
        file_put_contents($control . '/full-backup-evidence.json', (string) json_encode($dedicated));
        $gateOk = false;
        try {
            FullDatabaseBackupService::assertReadyForApply($app, $apiDirectory, $control, $sha);
            $gateOk = true;
        } catch (Throwable $exception) {
            $gateOk = false;
        }
        fdbAssert($gateOk, 'doğrulanmış hazır yedek migration kapısından geçer');
        $app->exec('CREATE TABLE user_yetki_istisnalari (id INT NOT NULL PRIMARY KEY)');
        $yetkiBlocked = false;
        try {
            FullDatabaseBackupService::assertReadyForApply($app, $apiDirectory, $control, $sha);
        } catch (Throwable $exception) {
            $yetkiBlocked = $exception->getMessage() === 'YETKI_SCHEMA_NOT_ABSENT';
        }
        $app->exec('DROP TABLE user_yetki_istisnalari');
        fdbAssert($yetkiBlocked, 'migration 100 kapısı hazır yedekte de yetki tablosunu durdurur');

        $admin->exec('CREATE TABLE ' . fdbIdent($restoreName) . '.sticky (id INT NOT NULL PRIMARY KEY)');
        $admin->exec('REVOKE DROP ON ' . fdbIdent($restoreName) . '.* FROM ' . fdbAccount($appUser));
        $restoreApp = fdbPdoOn($dsn . ';dbname=' . $restoreName, $appUser, $appPassword);
        $clear = new ReflectionMethod(FullDatabaseBackupService::class, 'clearDedicatedDatabase');
        $cleared = $clear->invoke(null, $restoreApp, $liveName);
        $stickyRemains = (int) $admin->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . fdbSqlString($restoreName) . " AND TABLE_NAME = 'sticky'"
        )->fetchColumn();
        fdbAssert($cleared === false && $stickyRemains === 1, 'temizlik başarısızsa test verisi kalır ve başarı sayılmaz');
    } finally {
        $config['db_restore_name'] = $previousRestore;
        if (isset($admin) && $admin instanceof PDO) {
            try {
                $admin->exec('SHUTDOWN');
            } catch (Throwable $exception) {
                proc_terminate($process);
            }
        } else {
            proc_terminate($process);
        }
        @unlink($initFile);
    }
}

function fdbSeedLive(PDO $live): void
{
    $live->exec('CREATE TABLE medisa_schema_migrations (version CHAR(3) NOT NULL PRIMARY KEY)');
    $live->exec("INSERT INTO medisa_schema_migrations (version) VALUES ('099')");
    $live->exec('CREATE TABLE items (id INT NOT NULL PRIMARY KEY, name VARCHAR(64) NOT NULL)');
    $live->exec("INSERT INTO items (id, name) VALUES (1, 'Ayşe;Yılmaz')");
    $live->exec('CREATE TABLE item_lines (
        id INT NOT NULL PRIMARY KEY,
        item_id INT NOT NULL,
        CONSTRAINT fk_line_item FOREIGN KEY (item_id) REFERENCES items (id)
    )');
    $live->exec('CREATE INDEX idx_item_lines_item ON item_lines (item_id)');
    $live->exec('INSERT INTO item_lines (id, item_id) VALUES (1, 1)');
    $live->exec("CREATE TRIGGER trg_items_no_delete BEFORE DELETE ON items FOR EACH ROW
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'no'");
    $live->exec('CREATE VIEW item_names AS SELECT id, name FROM items');
    $live->exec('CREATE PROCEDURE item_touch() BEGIN END');
    $live->exec('CREATE FUNCTION item_one() RETURNS INT DETERMINISTIC RETURN 1');
    $live->exec('CREATE EVENT item_tick ON SCHEDULE EVERY 1 HOUR DO BEGIN END');
}

function fdbMysqldBinary(): string
{
    $candidates = array_filter([
        getenv('MEDISA_TEST_MYSQLD_PATH') ?: '',
        'C:\\Program Files\\MariaDB 13.0\\bin\\mysqld.exe',
        'C:\\Program Files\\MariaDB 12.3\\bin\\mysqld.exe',
        'C:\\Program Files\\MariaDB 11.4\\bin\\mysqld.exe',
        'C:\\Program Files\\MariaDB 10.11\\bin\\mysqld.exe',
    ]);
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('GRANT_SERVER_BINARY_MISSING');
}

function fdbFreePort(): int
{
    for ($port = 3317; $port < 3340; $port++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.15);
        if ($socket === false) {
            return $port;
        }
        fclose($socket);
    }

    throw new RuntimeException('GRANT_SERVER_PORT_BUSY');
}

function fdbIdent(string $name): string
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
        throw new RuntimeException('TEST_IDENTIFIER_REJECTED');
    }

    return '`' . $name . '`';
}

function fdbAccount(string $user): string
{
    return fdbIdent($user) . "@'127.0.0.1'";
}

function fdbSqlString(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function fdbPdoOn(string $dsn, string $user, string $password): PDO
{
    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function fdbWaitPdo(string $dsn, string $user, string $password): PDO
{
    $started = time();
    $last = 'GRANT_SERVER_UNREACHABLE';
    while (time() - $started < 40) {
        try {
            return fdbPdoOn($dsn, $user, $password);
        } catch (Throwable $exception) {
            $last = $exception->getMessage();
            usleep(250000);
        }
    }

    throw new RuntimeException('GRANT_SERVER_UNREACHABLE');
}
