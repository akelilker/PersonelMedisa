<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/PuantajDonemKilidiService.php';

use Medisa\Api\Services\PuantajDonemKilidiService;

function conflictMysqlPdo(): PDO
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

function assertConflictMysql(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function signalConflictReady(string $path): void
{
    file_put_contents($path, 'ready');
}

function conflictChildMode(array $argv): void
{
    $action = $argv[2] ?? '';
    $pdo = conflictMysqlPdo();
    if ($action === 'revise-hold') {
        [$personelId, $tarih, $milliseconds, $signal] = array_slice($argv, 3, 4);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquireForDate($pdo, 1, (string) $tarih);
        $stmt = $pdo->prepare('SELECT * FROM gunluk_puantaj WHERE personel_id = ? AND tarih = ? FOR UPDATE');
        $stmt->execute([(int) $personelId, (string) $tarih]);
        signalConflictReady($signal);
        usleep((int) $milliseconds * 1000);
        $update = $pdo->prepare("UPDATE gunluk_puantaj SET hareket_durumu = 'Gelmedi', kaynak = 'BILDIRIM_ETKI_REVIZYON' WHERE personel_id = ? AND tarih = ?");
        $update->execute([(int) $personelId, (string) $tarih]);
        $pdo->commit();
        echo 'REVISED' . PHP_EOL;
        return;
    }
    if ($action === 'upsert-hold') {
        [$personelId, $tarih, $milliseconds, $signal] = array_slice($argv, 3, 4);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquireForDate($pdo, 1, (string) $tarih);
        $stmt = $pdo->prepare('SELECT * FROM gunluk_puantaj WHERE personel_id = ? AND tarih = ? FOR UPDATE');
        $stmt->execute([(int) $personelId, (string) $tarih]);
        signalConflictReady($signal);
        usleep((int) $milliseconds * 1000);
        $update = $pdo->prepare("UPDATE gunluk_puantaj SET aciklama = 'upsert-race' WHERE personel_id = ? AND tarih = ?");
        $update->execute([(int) $personelId, (string) $tarih]);
        $pdo->commit();
        echo 'UPSERTED' . PHP_EOL;
        return;
    }
    throw new RuntimeException('Unknown child action: ' . $action);
}

/** @return array{process: resource, pipes: array<int, resource>, signal: string} */
function spawnConflictChild(array $args): array
{
    $signal = tempnam(sys_get_temp_dir(), 'medisa-conflict-signal-');
    if ($signal === false) {
        throw new RuntimeException('Signal file could not be created.');
    }
    $command = array_merge(['php', __FILE__, '--child'], array_map(static function ($value) use ($signal) {
        return $value === '{SIGNAL}' ? $signal : (string) $value;
    }, $args));
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes, __DIR__);
    if (!is_resource($process)) {
        throw new RuntimeException('Child process could not be started.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes, 'signal' => $signal];
}

function waitConflictReady(array $child, int $timeoutMs = 10000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    while (microtime(true) < $deadline) {
        if (is_file($child['signal']) && file_get_contents($child['signal']) === 'ready') {
            return;
        }
        usleep(20000);
    }
    throw new RuntimeException('Child did not become ready.');
}

function finishConflictChild(array $child): string
{
    $stdout = stream_get_contents($child['pipes'][1]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    proc_close($child['process']);
    @unlink($child['signal']);

    return trim((string) $stdout);
}

if (($argv[1] ?? '') === '--child') {
    conflictChildMode($argv);
    exit(0);
}

$admin = conflictMysqlPdo();
$database = 'medisa_s75_conflict_resolution_test';
$admin->exec('DROP DATABASE IF EXISTS ' . $database);
$admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dsn = getenv('MEDISA_TEST_MYSQL_DSN');
putenv('MEDISA_TEST_MYSQL_DSN=' . preg_replace('/dbname=[^;]*/', 'dbname=' . $database, (string) $dsn));
$pdo = conflictMysqlPdo();

try {
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO subeler (id) VALUES (1)');
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
        hareket_durumu VARCHAR(32) NULL, kaynak VARCHAR(64) NULL, aciklama TEXT NULL,
        UNIQUE KEY uniq_test_personel_tarih (personel_id, tarih)
    ) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, sube_id, tarih, state, hareket_durumu, kaynak) VALUES (1, 1, '2026-06-04', 'ACIK', 'Geldi', 'MANUEL')");
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, sube_id, tarih, state, hareket_durumu, kaynak) VALUES (2, 1, '2026-07-04', 'ACIK', 'Geldi', 'MANUEL')");

    $child = spawnConflictChild(['revise-hold', '1', '2026-06-04', '500', '{SIGNAL}']);
    waitConflictReady($child);
    $started = microtime(true);
    $racePdo = conflictMysqlPdo();
    $racePdo->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($racePdo, 1, '2026-06-04');
    $stmt = $racePdo->prepare('SELECT * FROM gunluk_puantaj WHERE personel_id = ? AND tarih = ? FOR UPDATE');
    $stmt->execute([1, '2026-06-04']);
    $elapsed = microtime(true) - $started;
    $racePdo->commit();
    $firstResult = finishConflictChild($child);
    assertConflictMysql($elapsed >= 0.35 && $firstResult === 'REVISED', 'revise vs revise serializes on same puantaj row');

    $child = spawnConflictChild(['upsert-hold', '1', '2026-06-04', '500', '{SIGNAL}']);
    waitConflictReady($child);
    $upsertPdo = conflictMysqlPdo();
    $upsertPdo->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($upsertPdo, 1, '2026-06-04');
    $stmt = $upsertPdo->prepare('SELECT * FROM gunluk_puantaj WHERE personel_id = ? AND tarih = ? FOR UPDATE');
    $stmt->execute([1, '2026-06-04']);
    usleep(300000);
    $upsertPdo->commit();
    $secondResult = finishConflictChild($child);
    assertConflictMysql($secondResult === 'UPSERTED', 'revise vs direct upsert does not deadlock');

    $june = conflictMysqlPdo();
    $june->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($june, 1, '2026-06-04');
    $july = conflictMysqlPdo();
    $july->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($july, 1, '2026-07-04');
    $june->commit();
    $july->commit();
    assertConflictMysql(true, 'different period tuples lock independently');

    echo 'verify-bildirim-puantaj-etki-conflict-resolution-mysql-concurrency: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS ' . $database);
}
