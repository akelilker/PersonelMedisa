<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/PuantajDonemKilidiService.php';

use Medisa\Api\Services\PuantajDonemKilidiService;

function mysqlPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Isolated MySQL test credentials are required.');
    }
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

    return $pdo;
}

function assertMysql(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function signalReady(string $path): void
{
    file_put_contents($path, 'ready');
}

function childMode(array $argv): void
{
    $action = $argv[2] ?? '';
    $pdo = mysqlPdo();
    if ($action === 'hold') {
        [$sube, $yil, $ay, $milliseconds, $finish, $signal] = array_slice($argv, 3, 6);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquire($pdo, (int) $sube, (int) $yil, (int) $ay);
        signalReady($signal);
        usleep((int) $milliseconds * 1000);
        $finish === 'rollback' ? $pdo->rollBack() : $pdo->commit();
        echo strtoupper($finish) . PHP_EOL;
        return;
    }
    if ($action === 'seal-hold') {
        [$sube, $yil, $ay, $milliseconds, $signal] = array_slice($argv, 3, 5);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquire($pdo, (int) $sube, (int) $yil, (int) $ay);
        $stmt = $pdo->prepare('INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay) VALUES (?, ?, ?)');
        $stmt->execute([(int) $sube, (int) $yil, (int) $ay]);
        signalReady($signal);
        usleep((int) $milliseconds * 1000);
        $pdo->commit();
        echo 'SEALED' . PHP_EOL;
        return;
    }
    if ($action === 'candidate-apply') {
        [$candidateId, $subeId, $tarih, $signal, $holdMilliseconds] = array_slice($argv, 3, 5);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquireForDate($pdo, (int) $subeId, (string) $tarih);
        $stmt = $pdo->prepare('SELECT * FROM test_adaylar WHERE id = ? FOR UPDATE');
        $stmt->execute([(int) $candidateId]);
        $candidate = $stmt->fetch();
        signalReady($signal);
        if (!$candidate) {
            $pdo->rollBack();
            echo 'MISSING' . PHP_EOL;
            return;
        }
        if ($candidate['state'] === 'UYGULANDI') {
            $pdo->commit();
            echo 'IDEMPOTENT' . PHP_EOL;
            return;
        }
        $seal = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = ? AND yil = ? AND ay = ?');
        $seal->execute([(int) $candidate['sube_id'], (int) substr((string) $candidate['tarih'], 0, 4), (int) substr((string) $candidate['tarih'], 5, 2)]);
        if ($seal->fetch()) {
            $pdo->rollBack();
            echo 'PERIOD_LOCKED' . PHP_EOL;
            return;
        }
        $insert = $pdo->prepare('INSERT INTO gunluk_puantaj (personel_id, sube_id, tarih, state) VALUES (?, ?, ?, ?)');
        $insert->execute([(int) $candidate['personel_id'], (int) $candidate['sube_id'], $candidate['tarih'], 'ACIK']);
        $update = $pdo->prepare('UPDATE test_adaylar SET state = ?, uygulanan_puantaj_id = ? WHERE id = ?');
        $update->execute(['UYGULANDI', (int) $pdo->lastInsertId(), (int) $candidateId]);
        usleep((int) $holdMilliseconds * 1000);
        $pdo->commit();
        echo 'APPLIED' . PHP_EOL;
        return;
    }
    throw new RuntimeException('Unknown child action: ' . $action);
}

/** @return array{process: resource, pipes: array<int, resource>, signal: string} */
function spawnChild(array $args): array
{
    $signal = tempnam(sys_get_temp_dir(), 'medisa-mysql-signal-');
    if ($signal === false) {
        throw new RuntimeException('Signal file could not be created.');
    }
    unlink($signal);
    foreach ($args as &$arg) {
        if ($arg === '{SIGNAL}') {
            $arg = $signal;
        }
    }
    unset($arg);
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        if (!extension_loaded('pdo_mysql')) {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension=php_pdo_mysql.dll';
        }
    }
    $command = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--child'], $args);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Child process could not be started.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes, 'signal' => $signal];
}

function childStdoutToken(string $stdout): string
{
    $lines = preg_split('/\R/', trim($stdout)) ?: [];
    $token = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'Warning:') === 0) {
            continue;
        }
        $token = $line;
    }

    return $token;
}

function waitReady(array $child): void
{
    $deadline = microtime(true) + 5;
    while (!is_file($child['signal'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Child did not acquire the lock in time.');
        }
        usleep(20000);
    }
}

function finishChild(array $child): string
{
    $stdout = stream_get_contents($child['pipes'][1]);
    $stderr = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $code = proc_close($child['process']);
    @unlink($child['signal']);
    if ($code !== 0) {
        throw new RuntimeException('Child failed: ' . trim($stderr));
    }

    return childStdoutToken((string) $stdout);
}

function resetMysql(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['gunluk_puantaj', 'test_adaylar', 'puantaj_aylik_muhurleri', 'puantaj_donem_kilitleri'] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function seedLock(PDO $pdo, int $sube, int $yil, int $ay): void
{
    $pdo->beginTransaction();
    PuantajDonemKilidiService::acquire($pdo, $sube, $yil, $ay);
    $pdo->commit();
}

function measureAcquire(PDO $pdo, int $sube, int $yil, int $ay): float
{
    $started = microtime(true);
    $pdo->beginTransaction();
    PuantajDonemKilidiService::acquire($pdo, $sube, $yil, $ay);
    $elapsed = microtime(true) - $started;
    $pdo->commit();

    return $elapsed;
}

function attemptWriteAfterSeal(PDO $pdo, string $owner): bool
{
    $pdo->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($pdo, 1, '2026-05-15');
    $seal = $pdo->query('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = 1 AND yil = 2026 AND ay = 5')->fetch();
    if ($seal) {
        $pdo->rollBack();
        return false;
    }
    $stmt = $pdo->prepare('INSERT INTO gunluk_puantaj (personel_id, sube_id, tarih, state) VALUES (?, 1, ?, ?)');
    $stmt->execute([$owner === 'manual' ? 2 : ($owner === 'upsert' ? 3 : 1), '2026-05-15', 'ACIK']);
    $pdo->commit();

    return true;
}

if (($argv[1] ?? '') === '--child') {
    childMode($argv);
    exit(0);
}

$admin = mysqlPdo();
$database = 'medisa_s74_period_lock_test';
$admin->exec('DROP DATABASE IF EXISTS ' . $database);
$admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dsn = getenv('MEDISA_TEST_MYSQL_DSN');
putenv('MEDISA_TEST_MYSQL_DSN=' . preg_replace('/dbname=[^;]*/', 'dbname=' . $database, $dsn));
$pdo = mysqlPdo();

try {
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO subeler (id) VALUES (1), (2)');
    $migration = file_get_contents(__DIR__ . '/../../api/migrations/014_puantaj_donem_kilitleri.sql');
    $migration = preg_replace('/^\s*--.*$/m', '', (string) $migration);
    foreach (array_filter(array_map('trim', explode(';', (string) $migration))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sube_id INT UNSIGNED NOT NULL,
        yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL,
        UNIQUE KEY uniq_test_seal (sube_id, yil, ay)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, personel_id INT UNSIGNED NOT NULL,
        sube_id INT UNSIGNED NOT NULL, tarih DATE NOT NULL, state VARCHAR(32) NOT NULL,
        UNIQUE KEY uniq_test_personel_tarih (personel_id, tarih)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE test_adaylar (
        id INT UNSIGNED NOT NULL PRIMARY KEY, personel_id INT UNSIGNED NOT NULL, sube_id INT UNSIGNED NOT NULL,
        tarih DATE NOT NULL, state VARCHAR(32) NOT NULL, uygulanan_puantaj_id INT UNSIGNED NULL
    ) ENGINE=InnoDB');

    foreach (['automatic apply', 'manual apply', 'direct upsert'] as $owner) {
        resetMysql($pdo);
        seedLock($pdo, 1, 2026, 5);
        $child = spawnChild(['hold', '1', '2026', '5', '650', 'commit', '{SIGNAL}']);
        waitReady($child);
        $elapsed = measureAcquire(mysqlPdo(), 1, 2026, 5);
        finishChild($child);
        assertMysql($elapsed >= 0.45, $owner . ' lock makes seal wait');
    }

    foreach (['automatic', 'manual', 'upsert'] as $owner) {
        resetMysql($pdo);
        seedLock($pdo, 1, 2026, 5);
        $child = spawnChild(['seal-hold', '1', '2026', '5', '650', '{SIGNAL}']);
        waitReady($child);
        $wrote = attemptWriteAfterSeal(mysqlPdo(), $owner);
        finishChild($child);
        assertMysql(!$wrote && (int) $pdo->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn() === 0,
            'seal lock blocks ' . $owner . ' puantaj write');
    }

    resetMysql($pdo);
    seedLock($pdo, 1, 2026, 5);
    $child = spawnChild(['hold', '1', '2026', '5', '500', 'rollback', '{SIGNAL}']);
    waitReady($child);
    $elapsed = measureAcquire(mysqlPdo(), 1, 2026, 5);
    assertMysql(str_contains(finishChild($child), 'ROLLBACK') && $elapsed >= 0.3,
        'rollback releases period lock for another transaction');

    resetMysql($pdo);
    seedLock($pdo, 1, 2026, 5);
    $pdo->exec("INSERT INTO test_adaylar VALUES (1, 7, 1, '2026-05-15', 'HAZIR', NULL)");
    $first = spawnChild(['candidate-apply', '1', '1', '2026-05-15', '{SIGNAL}', '400']);
    waitReady($first);
    $second = spawnChild(['candidate-apply', '1', '1', '2026-05-15', '{SIGNAL}', '0']);
    $firstResult = finishChild($first);
    $secondResult = finishChild($second);
    assertMysql((int) $pdo->query('SELECT COUNT(*) FROM gunluk_puantaj')->fetchColumn() === 1
        && in_array('APPLIED', [$firstResult, $secondResult], true)
        && in_array('IDEMPOTENT', [$firstResult, $secondResult], true),
        'two apply transactions produce one puantaj and one idempotent result');

    resetMysql($pdo);
    seedLock($pdo, 1, 2026, 5);
    $pdo->exec("INSERT INTO test_adaylar VALUES (1, 7, 1, '2026-05-15', 'HAZIR', NULL)");
    $apply = spawnChild(['candidate-apply', '1', '1', '2026-05-15', '{SIGNAL}', '500']);
    waitReady($apply);
    $sealPdo = mysqlPdo();
    $sealPdo->beginTransaction();
    PuantajDonemKilidiService::acquire($sealPdo, 1, 2026, 5);
    $snapshotCount = (int) $sealPdo->query("SELECT COUNT(*) FROM gunluk_puantaj WHERE tarih BETWEEN '2026-05-01' AND '2026-05-31'")->fetchColumn();
    $sealPdo->exec('INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay) VALUES (1, 2026, 5)');
    $sealPdo->commit();
    finishChild($apply);
    assertMysql($snapshotCount === 1, 'apply completes before seal and its puantaj is inside seal snapshot set');

    resetMysql($pdo);
    foreach ([[1, 2026, 5], [2, 2026, 5], [1, 2026, 6]] as $key) {
        seedLock($pdo, ...$key);
    }
    $child = spawnChild(['hold', '1', '2026', '5', '900', 'commit', '{SIGNAL}']);
    waitReady($child);
    $otherSube = measureAcquire(mysqlPdo(), 2, 2026, 5);
    $otherMonth = measureAcquire(mysqlPdo(), 1, 2026, 6);
    finishChild($child);
    assertMysql($otherSube < 0.3 && $otherMonth < 0.3, 'different sube and month locks proceed independently');

    resetMysql($pdo);
    $first = spawnChild(['hold', '1', '2026', '5', '500', 'commit', '{SIGNAL}']);
    waitReady($first);
    $second = spawnChild(['hold', '1', '2026', '5', '0', 'commit', '{SIGNAL}']);
    finishChild($first);
    finishChild($second);
    assertMysql((int) $pdo->query('SELECT COUNT(*) FROM puantaj_donem_kilitleri WHERE sube_id = 1 AND yil = 2026 AND ay = 5')->fetchColumn() === 1,
        'concurrent first lock-row creation leaves exactly one guarded row');

    $pdo->exec('DROP TABLE puantaj_donem_kilitleri');
    $failClosed = false;
    try {
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquire($pdo, 1, 2026, 5);
    } catch (Throwable $e) {
        $failClosed = true;
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    assertMysql($failClosed, 'missing lock table fails closed without puantaj write');

    echo 'verify-puantaj-donem-kilidi-mysql-concurrency: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS ' . $database);
}
