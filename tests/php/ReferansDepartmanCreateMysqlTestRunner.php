<?php

declare(strict_types=1);

/**
 * MariaDB persistence + HTTP owner + concurrency acceptance for POST /referans/departmanlar.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Controllers\ReferansController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;
use Medisa\Api\Router;

function mysqlAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function mysqlPdo(string $dsn): PDO
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

function setConnectionPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function resetAuthUser($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function makeRequest(string $method, string $path, array $body, array $headers = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'jsonBody' => $body,
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }

    return $request;
}

/**
 * @return array{status:int, payload:array<string,mixed>}
 */
function invokeCreateDepartmanHttp(PDO $pdo, $user, array $body): array
{
    setConnectionPdo($pdo);
    resetAuthUser($user);

    $statusFile = tempnam(sys_get_temp_dir(), 'dep_http_');
    if ($statusFile === false) {
        throw new RuntimeException('tempnam failed');
    }

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

    $payload = json_encode([
        'dsn' => getenv('MEDISA_TEST_MYSQL_DSN'),
        'user' => getenv('MEDISA_TEST_MYSQL_USER'),
        'password' => getenv('MEDISA_TEST_MYSQL_PASSWORD'),
        'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'auth' => $user,
        'body' => $body,
        'status_file' => $statusFile,
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--http-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, null, array_merge(getenv(), [
        'MEDISA_TEST_MYSQL_DSN' => getenv('MEDISA_TEST_MYSQL_DSN') ?: '',
        'MEDISA_TEST_MYSQL_USER' => getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        'MEDISA_TEST_MYSQL_PASSWORD' => getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
    ]));
    if (!is_resource($process)) {
        throw new RuntimeException('http child failed to start');
    }
    fwrite($pipes[0], (string) $payload);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $statusRaw = is_file($statusFile) ? trim((string) file_get_contents($statusFile)) : '';
    @unlink($statusFile);
    $status = (int) $statusRaw;

    $jsonStart = strpos($stdout, '{');
    $jsonSlice = $jsonStart === false ? $stdout : substr($stdout, $jsonStart);
    $decoded = json_decode((string) $jsonSlice, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('http child invalid json: ' . $stdout . ' / ' . $stderr);
    }

    return ['status' => $status, 'payload' => $decoded];
}

if (($argv[1] ?? '') === '--http-child') {
    $raw = stream_get_contents(STDIN);
    $cfg = json_decode((string) $raw, true);
    if (!is_array($cfg)) {
        fwrite(STDERR, "bad child config\n");
        exit(2);
    }

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $cfg['database'], (string) $cfg['dsn']);
    $pdo = new PDO(
        $dsn,
        (string) $cfg['user'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    setConnectionPdo($pdo);
    resetAuthUser($cfg['auth']);

    register_shutdown_function(static function () use ($cfg): void {
        file_put_contents((string) $cfg['status_file'], (string) http_response_code());
    });

    $request = makeRequest('POST', '/referans/departmanlar', is_array($cfg['body']) ? $cfg['body'] : []);
    // Router path ownership: ensure dispatch would route here, then invoke owner.
    $routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
    if (strpos($routerSource, "path === '/referans/departmanlar' && \$method === 'POST'") === false) {
        fwrite(STDERR, "router POST owner missing\n");
        exit(3);
    }

    ReferansController::createDepartman($request);
    exit(0);
}

if (($argv[1] ?? '') === '--race-child') {
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $pdo = mysqlPdo($dsn);
    $ad = $argv[2] ?? 'RaceDept';
    try {
        $created = ReferansController::createDepartmanRecord($pdo, ['ad' => $ad]);
        echo 'OK:' . $created['id'] . PHP_EOL;
    } catch (DomainException $e) {
        echo $e->getMessage() . PHP_EOL;
    } catch (Throwable $e) {
        echo 'ERR:' . $e->getMessage() . PHP_EOL;
        exit(1);
    }
    exit(0);
}

$adminDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
$password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
if ($adminDsn === '' || $user === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN/USER required\n");
    exit(1);
}

$admin = mysqlPdo($adminDsn);
$database = 'medisa_s78_departman_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $adminDsn);
    putenv('MEDISA_TEST_MYSQL_DSN=' . $dsn);
    $_ENV['MEDISA_TEST_MYSQL_DSN'] = $dsn;

    $pdo = mysqlPdo($dsn);
    $pdo->exec(
        "CREATE TABLE departmanlar (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(120) NOT NULL,
            durum ENUM('AKTIF', 'PASIF') NOT NULL DEFAULT 'AKTIF',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/025_departmanlar_ad_unique.sql'));

    $createSql = $pdo->query('SHOW CREATE TABLE departmanlar')->fetch();
    $createText = is_array($createSql) ? (string) ($createSql['Create Table'] ?? '') : '';
    mysqlAssert(strpos($createText, 'uq_departmanlar_ad') !== false, 'SHOW CREATE has uq_departmanlar_ad');
    mysqlAssert(stripos($createText, 'utf8mb4_unicode_ci') !== false, 'ad uses utf8mb4_unicode_ci');

    $col = $pdo->query("SHOW FULL COLUMNS FROM departmanlar LIKE 'ad'")->fetch();
    mysqlAssert(is_array($col) && stripos((string) $col['Type'], 'varchar(120)') !== false, 'ad VARCHAR(120)');
    mysqlAssert(is_array($col) && (string) $col['Collation'] === 'utf8mb4_unicode_ci', 'ad collation unicode_ci');

    // Persistence create + trim
    $created = ReferansController::createDepartmanRecord($pdo, ['ad' => '  Kalite Kontrol  ']);
    mysqlAssert($created['ad'] === 'Kalite Kontrol', 'trim persisted');
    mysqlAssert((int) $created['id'] > 0, 'lastInsertId used');
    $row = $pdo->query('SELECT ad, durum FROM departmanlar WHERE id = ' . (int) $created['id'])->fetch();
    mysqlAssert(is_array($row) && $row['ad'] === 'Kalite Kontrol' && $row['durum'] === 'AKTIF', 'DB row matches');

    // Type rejections
    foreach ([123, 12.5, true, null, [], (object) ['x' => 1]] as $bad) {
        $code = null;
        try {
            ReferansController::createDepartmanRecord($pdo, ['ad' => $bad]);
        } catch (InvalidArgumentException $e) {
            $code = $e->getMessage();
        }
        mysqlAssert($code === 'DEPARTMAN_NAME_TYPE', 'non-string rejected: ' . json_encode($bad));
    }

    // Collation-aware duplicates
    foreach (['kalite kontrol', 'KALITE KONTROL', 'Kalite Kontrol'] as $dup) {
        $code = null;
        try {
            ReferansController::createDepartmanRecord($pdo, ['ad' => $dup]);
        } catch (DomainException $e) {
            $code = $e->getMessage();
        }
        mysqlAssert($code === 'DEPARTMAN_ZATEN_VAR', 'duplicate via collation: ' . $dup);
    }

    // Turkish I/İ notes under unicode_ci (document actual DB behavior)
    ReferansController::createDepartmanRecord($pdo, ['ad' => 'İzin']);
    $iCode = null;
    try {
        ReferansController::createDepartmanRecord($pdo, ['ad' => 'izin']);
    } catch (DomainException $e) {
        $iCode = $e->getMessage();
    }
    mysqlAssert($iCode === 'DEPARTMAN_ZATEN_VAR', 'unicode_ci treats İzin ~ izin as duplicate');

    // Unexpected fields allowlist
    ReferansController::createDepartmanRecord($pdo, [
        'ad' => 'Arge',
        'sube_id' => 77,
        'durum' => 'PASIF',
        'id' => 999,
    ]);
    $cols = $pdo->query('SHOW COLUMNS FROM departmanlar')->fetchAll();
    $colNames = array_map(static function ($c) {
        return $c['Field'];
    }, $cols);
    mysqlAssert(!in_array('sube_id', $colNames, true), 'no sube_id column (global model)');
    $arge = $pdo->query("SELECT id, durum FROM departmanlar WHERE ad = 'Arge'")->fetch();
    mysqlAssert(is_array($arge) && (int) $arge['id'] !== 999 && $arge['durum'] === 'AKTIF', 'sube_id/durum/id ignored');

    // HTTP owner matrix
    $authOk = ['id' => 1, 'username' => 'gy', 'ad_soyad' => 'GY', 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $http = invokeCreateDepartmanHttp($pdo, $authOk, ['ad' => '  HTTP Dept  ']);
    mysqlAssert($http['status'] === 201, 'HTTP authorized create → 201');
    mysqlAssert(($http['payload']['data']['ad'] ?? null) === 'HTTP Dept', 'HTTP response ad trimmed');
    mysqlAssert((int) ($http['payload']['data']['id'] ?? 0) > 0, 'HTTP response id present');

    $httpDup = invokeCreateDepartmanHttp($pdo, $authOk, ['ad' => 'http dept']);
    mysqlAssert($httpDup['status'] === 409, 'HTTP duplicate → 409');
    mysqlAssert(($httpDup['payload']['errors'][0]['code'] ?? '') === 'DEPARTMAN_ZATEN_VAR', 'HTTP duplicate code');

    $httpNum = invokeCreateDepartmanHttp($pdo, $authOk, ['ad' => 123]);
    mysqlAssert($httpNum['status'] === 400, 'HTTP numeric → 400');
    mysqlAssert(($httpNum['payload']['errors'][0]['code'] ?? '') === 'VALIDATION_ERROR', 'HTTP numeric code');

    foreach (['BIRIM_AMIRI', 'MUHASEBE', 'IK', 'PATRON'] as $rol) {
        $forbiddenUser = ['id' => 2, 'username' => 'x', 'ad_soyad' => 'x', 'rol' => $rol, 'sube_ids' => []];
        $httpForbidden = invokeCreateDepartmanHttp($pdo, $forbiddenUser, ['ad' => 'Yetkisiz ' . $rol]);
        mysqlAssert($httpForbidden['status'] === 403, 'HTTP ' . $rol . ' → 403');
        mysqlAssert(($httpForbidden['payload']['errors'][0]['code'] ?? '') === 'FORBIDDEN', 'HTTP ' . $rol . ' FORBIDDEN');
    }

    $httpUnauth = invokeCreateDepartmanHttp($pdo, null, ['ad' => 'NoAuth']);
    mysqlAssert($httpUnauth['status'] === 401, 'HTTP unauthenticated → 401');

    mysqlAssert(RolePermissions::has($authOk, 'yonetim-paneli.manage'), 'permission matrix GY');

    // Concurrency: two parallel creates, one winner
    $raceName = 'RaceDept-' . bin2hex(random_bytes(3));
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

    $spawn = static function (string $ad) use ($phpArgs, $dsn): array {
        $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--race-child', $ad]);
        $pipes = [];
        $env = array_merge(getenv(), [
            'MEDISA_TEST_MYSQL_DSN' => $dsn,
            'MEDISA_TEST_MYSQL_USER' => getenv('MEDISA_TEST_MYSQL_USER') ?: '',
            'MEDISA_TEST_MYSQL_PASSWORD' => getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        ]);
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('race child start failed');
        }
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    };
    $finish = static function (array $child): string {
        $out = (string) stream_get_contents($child['pipes'][1]);
        $err = trim((string) stream_get_contents($child['pipes'][2]));
        fclose($child['pipes'][1]);
        fclose($child['pipes'][2]);
        $code = proc_close($child['process']);
        if ($code !== 0) {
            throw new RuntimeException('race child failed: ' . $err . ' / ' . $out);
        }

        if (preg_match('/OK:\\d+/', $out, $m)) {
            return $m[0];
        }
        if (strpos($out, 'DEPARTMAN_ZATEN_VAR') !== false) {
            return 'DEPARTMAN_ZATEN_VAR';
        }

        throw new RuntimeException('race child unexpected output: ' . $out . ' / ' . $err);
    };

    $a = $spawn($raceName);
    $b = $spawn($raceName);
    $results = [$finish($a), $finish($b)];
    sort($results);
    $okCount = 0;
    $dupCount = 0;
    foreach ($results as $result) {
        if (strpos($result, 'OK:') === 0) {
            $okCount++;
        }
        if ($result === 'DEPARTMAN_ZATEN_VAR') {
            $dupCount++;
        }
    }
    mysqlAssert($okCount === 1 && $dupCount === 1, 'parallel create: one OK one DEPARTMAN_ZATEN_VAR [' . implode(',', $results) . ']');
    mysqlAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM departmanlar WHERE ad = ' . $pdo->quote($raceName))->fetchColumn() === 1,
        'parallel create leaves single DB row'
    );

    // Silence unused import (Router referenced for ownership documentation)
    mysqlAssert(class_exists(Router::class), 'Router class available');

    echo 'verify-referans-departman-create-mysql: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
