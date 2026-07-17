<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/PersonelUcretException.php';
require_once __DIR__ . '/../../api/src/Services/PersonelUcretService.php';

use Medisa\Api\Services\PersonelUcretException;
use Medisa\Api\Services\PersonelUcretService;

function concurrencyPdo(): PDO
{
    return new PDO(
        getenv('MEDISA_TEST_MYSQL_DSN') ?: '',
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

function concurrencyAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

if (($argv[1] ?? '') === '--child') {
    $pdo = concurrencyPdo();
    $action = $argv[2] ?? '';
    try {
        if ($action === 'create') {
            PersonelUcretService::createSalaryRecord($pdo, (int) $argv[3], [
                'ucret_tutari' => (string) $argv[4],
                'ucret_turu' => 'NET',
                'gecerlilik_baslangic' => (string) $argv[5],
            ]);
        } elseif ($action === 'cancel') {
            PersonelUcretService::cancelSalaryRecord($pdo, (int) $argv[3]);
        } else {
            throw new RuntimeException('Unknown child action.');
        }
        echo 'OK' . PHP_EOL;
    } catch (PersonelUcretException $e) {
        echo $e->getCodeString() . ':' . $e->getHttpStatus() . PHP_EOL;
    }
    exit(0);
}

/** @return array{process: resource, pipes: array<int, resource>} */
function spawnSalaryChild(array $args, string $dsn): array
{
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=pdo_mysql';
    }
    $command = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--child'], $args);
    $pipes = [];
    $env = array_merge(getenv(), ['MEDISA_TEST_MYSQL_DSN' => $dsn]);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Child process could not start.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

function finishSalaryChild(array $child): string
{
    $stdout = trim((string) stream_get_contents($child['pipes'][1]));
    $stderr = trim((string) stream_get_contents($child['pipes'][2]));
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $status = proc_close($child['process']);
    if ($status !== 0) {
        throw new RuntimeException('Child failed: ' . $stderr);
    }

    return $stdout;
}

$adminDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
$password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
$admin = new PDO($adminDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'medisa_s77_concurrency_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $adminDsn);
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE personeller (
        id INT UNSIGNED PRIMARY KEY, sube_id INT UNSIGNED NOT NULL,
        maas_tutari DECIMAL(12,2) NULL, ise_giris_tarihi DATE NULL
    ) ENGINE=InnoDB');
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/018_personel_ucret_gecmisi.sql'));
    $pdo->exec('INSERT INTO subeler VALUES (1)');
    $pdo->exec("INSERT INTO personeller VALUES
        (1, 1, NULL, '2020-01-01'), (2, 1, NULL, '2020-01-01'), (3, 1, NULL, '2020-01-01')");

    $raceA = spawnSalaryChild(['create', '1', '10000', '2027-01-01'], $dsn);
    $raceB = spawnSalaryChild(['create', '1', '11000', '2027-01-01'], $dsn);
    $results = [finishSalaryChild($raceA), finishSalaryChild($raceB)];
    sort($results);
    concurrencyAssert(
        $results === ['OK', 'SALARY_DATE_OVERLAP:409'],
        'parallel overlapping creates allow one winner and one 409'
    );
    concurrencyAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM personel_ucret_gecmisi WHERE personel_id = 1 AND state = 'AKTIF' AND gecerlilik_bitis IS NULL")->fetchColumn() === 1,
        'parallel race leaves one open-ended active salary'
    );

    $existing = PersonelUcretService::createSalaryRecord($pdo, 2, [
        'ucret_tutari' => 9000, 'ucret_turu' => 'NET', 'gecerlilik_baslangic' => '2026-01-01',
    ]);
    $existingId = (int) $existing['id'];
    $cancel = spawnSalaryChild(['cancel', (string) $existingId], $dsn);
    $create = spawnSalaryChild(['create', '2', '12000', '2027-01-01'], $dsn);
    $cancelResult = finishSalaryChild($cancel);
    $createResult = finishSalaryChild($create);
    concurrencyAssert(
        $cancelResult === 'OK' && $createResult === 'OK',
        'cancel versus create race serializes safely [' . $cancelResult . ' / ' . $createResult . ']'
    );
    concurrencyAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM personel_ucret_gecmisi WHERE personel_id = 2 AND state = 'AKTIF' AND gecerlilik_bitis IS NULL")->fetchColumn() <= 1,
        'cancel versus create leaves at most one open-ended active salary'
    );

    echo 'verify-personel-ucret-mysql-concurrency: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
