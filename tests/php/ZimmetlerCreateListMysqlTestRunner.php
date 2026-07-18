<?php

declare(strict_types=1);

/**
 * MariaDB HTTP + persistence acceptance for zimmetler list/create.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\ZimmetlerController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function zimmetAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function zimmetPdo(string $dsn): PDO
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

function makeRequest(string $method, string $path, array $body = [], array $headers = []): Request
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
function invokeZimmetHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    setConnectionPdo($pdo);
    resetAuthUser($user);

    $statusFile = tempnam(sys_get_temp_dir(), 'zimmet_http_');
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
        'method' => $method,
        'path' => $path,
        'body' => $body,
        'headers' => $headers,
        'query' => $query,
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

    $_GET = [];
    if (is_array($cfg['query'] ?? null)) {
        foreach ($cfg['query'] as $key => $value) {
            $_GET[(string) $key] = $value;
        }
    }

    register_shutdown_function(static function () use ($cfg): void {
        file_put_contents((string) $cfg['status_file'], (string) http_response_code());
    });

    $method = strtoupper((string) ($cfg['method'] ?? 'GET'));
    $path = (string) ($cfg['path'] ?? '');
    $body = is_array($cfg['body'] ?? null) ? $cfg['body'] : [];
    $headers = is_array($cfg['headers'] ?? null) ? $cfg['headers'] : [];
    $request = makeRequest($method, $path, $body, $headers);

    if ($method === 'GET' && $path === '/zimmetler') {
        ZimmetlerController::list($request);
    }
    if ($method === 'POST' && $path === '/zimmetler') {
        ZimmetlerController::create($request);
    }

    fwrite(STDERR, "unhandled route\n");
    exit(3);
}

function bootstrapZimmetSchema(PDO $pdo): void
{
    $suffix = bin2hex(random_bytes(4));
    $dbName = 'zimmet_s78c2_' . $suffix;
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $dbName . '`');

    $pdo->exec("
        CREATE TABLE subeler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          kod VARCHAR(32) NOT NULL,
          ad VARCHAR(120) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personeller (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          tc_kimlik_no CHAR(11) NOT NULL,
          ad VARCHAR(80) NOT NULL,
          soyad VARCHAR(80) NOT NULL,
          dogum_tarihi DATE NOT NULL,
          telefon VARCHAR(32) NOT NULL DEFAULT '',
          acil_durum_kisi VARCHAR(120) NOT NULL DEFAULT '',
          acil_durum_telefon VARCHAR(32) NOT NULL DEFAULT '',
          sicil_no VARCHAR(32) NOT NULL,
          ise_giris_tarihi DATE NOT NULL,
          sube_id INT UNSIGNED NOT NULL,
          aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          KEY idx_personel_sube (sube_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $migration = (string) file_get_contents(__DIR__ . '/../../api/migrations/026_zimmetler.sql');
    foreach (preg_split('/;\s*\n/', $migration) as $stmt) {
        $trimmed = trim((string) $stmt);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        if (preg_match('/^SET\s+/i', $trimmed) === 1) {
            continue;
        }
        $pdo->exec($trimmed);
    }

    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec("
        INSERT INTO personeller (
          id, tc_kimlik_no, ad, soyad, dogum_tarihi, sicil_no, ise_giris_tarihi, sube_id, aktif_durum
        ) VALUES
          (10, '11111111111', 'Ayse', 'Yilmaz', '1990-01-01', 'S10', '2020-01-01', 1, 'AKTIF'),
          (20, '22222222222', 'Mehmet', 'Demir', '1988-01-01', 'S20', '2020-01-01', 2, 'AKTIF'),
          (30, '33333333333', 'Pasif', 'Kullanici', '1992-01-01', 'S30', '2020-01-01', 1, 'PASIF')
    ");
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/ZimmetlerController.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/026_zimmetler.sql');
zimmetAssert(strpos($routerSource, 'ZimmetlerController::list') !== false, 'router GET list');
zimmetAssert(strpos($routerSource, 'ZimmetlerController::create') !== false, 'router POST create');
zimmetAssert(strpos($controllerSource, "personeller.detail.view") !== false, 'list permission');
zimmetAssert(strpos($controllerSource, "personeller.update") !== false, 'create permission');
zimmetAssert(strpos($migrationSource, 'CREATE TABLE IF NOT EXISTS zimmetler') !== false, 'migration creates zimmetler');
zimmetAssert(stripos($migrationSource, 'DROP ') === false, 'migration no DROP');
zimmetAssert(preg_match('/\bDELETE\s+FROM\b/i', $migrationSource) !== 1, 'migration no DELETE FROM');
zimmetAssert(preg_match('/(?:^|;)\s*UPDATE\b/im', $migrationSource) !== 1, 'migration no UPDATE statement');
zimmetAssert(stripos($migrationSource, 'ON DELETE CASCADE') !== false, 'migration FK ON DELETE CASCADE');
zimmetAssert(stripos($migrationSource, 'ON UPDATE CURRENT_TIMESTAMP') !== false, 'migration updated_at ON UPDATE');

$root = zimmetPdo($dsn);
bootstrapZimmetSchema($root);
$dbName = (string) $root->query('SELECT DATABASE()')->fetchColumn();
$pdo = zimmetPdo(preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn));

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$ba = ['id' => 3, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
$muhasebe = ['id' => 2, 'rol' => 'MUHASEBE', 'sube_ids' => [1, 2]];
$patron = ['id' => 9, 'rol' => 'PATRON', 'sube_ids' => []];

$createPayload = [
    'personel_id' => 10,
    'urun_turu' => 'KASK',
    'teslim_tarihi' => '2026-07-18',
    'teslim_eden' => 'IK Gorevlisi',
    'teslim_durumu' => 'YENI',
    'aciklama' => 'Seri No: KSK-100',
];

$created = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', $createPayload);
zimmetAssert($created['status'] === 201, 'HTTP authorized create → 201');
zimmetAssert((int) ($created['payload']['data']['id'] ?? 0) > 0, 'create returns id');
zimmetAssert(($created['payload']['data']['zimmet_durumu'] ?? '') === 'AKTIF', 'create state AKTIF');
zimmetAssert(($created['payload']['data']['urun_turu'] ?? '') === 'KASK', 'create urun_turu');
zimmetAssert(array_key_exists('iade_tarihi', $created['payload']['data'] ?? [])
    && $created['payload']['data']['iade_tarihi'] === null, 'create iade_tarihi null');
$createdId = (int) $created['payload']['data']['id'];

$dbCount = (int) $pdo->query('SELECT COUNT(*) FROM zimmetler')->fetchColumn();
zimmetAssert($dbCount === 1, 'DB single row after create');

$list = invokeZimmetHttp($pdo, $gy, 'GET', '/zimmetler', [], [], ['personel_id' => '10']);
zimmetAssert($list['status'] === 200, 'HTTP list → 200');
zimmetAssert(count($list['payload']['data']['items'] ?? []) === 1, 'list shows created row');
zimmetAssert((int) ($list['payload']['data']['items'][0]['id'] ?? 0) === $createdId, 'list id matches');

$dupAllowed = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', $createPayload);
zimmetAssert($dupAllowed['status'] === 201, 'duplicate same product allowed → 201');
zimmetAssert((int) $pdo->query('SELECT COUNT(*) FROM zimmetler WHERE personel_id = 10')->fetchColumn() === 2, 'duplicate rows persist');

$emptyAd = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', array_merge($createPayload, ['urun_turu' => '  ']));
zimmetAssert($emptyAd['status'] === 422, 'empty urun_turu → 422');

$numericUrun = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', array_merge($createPayload, ['urun_turu' => 12]));
zimmetAssert($numericUrun['status'] === 422, 'numeric urun_turu → 422');

$badDate = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', array_merge($createPayload, ['teslim_tarihi' => '18-07-2026']));
zimmetAssert($badDate['status'] === 422, 'invalid date → 422');

$clientState = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', array_merge($createPayload, [
    'zimmet_durumu' => 'IADE_EDILDI',
    'iade_tarihi' => '2026-07-19',
]));
zimmetAssert($clientState['status'] === 201, 'client state fields ignored → 201');
zimmetAssert(($clientState['payload']['data']['zimmet_durumu'] ?? '') === 'AKTIF', 'server owns zimmet_durumu');
zimmetAssert(array_key_exists('iade_tarihi', $clientState['payload']['data'] ?? [])
    && $clientState['payload']['data']['iade_tarihi'] === null, 'server owns iade_tarihi');

$subeOverride = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', array_merge($createPayload, ['sube_id' => 2]));
zimmetAssert($subeOverride['status'] === 201, 'body sube_id ignored → 201');
$lastPersonel = (int) $pdo->query('SELECT personel_id FROM zimmetler ORDER BY id DESC LIMIT 1')->fetchColumn();
zimmetAssert($lastPersonel === 10, 'sube_id body does not reassign personel');

$missingPersonel = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', array_merge($createPayload, ['personel_id' => 9999]));
zimmetAssert($missingPersonel['status'] === 422, 'missing personel → 422');

$pasif = invokeZimmetHttp($pdo, $gy, 'POST', '/zimmetler', array_merge($createPayload, ['personel_id' => 30]));
zimmetAssert($pasif['status'] === 422, 'pasif personel → 422');

$baOther = invokeZimmetHttp($pdo, $ba, 'POST', '/zimmetler', array_merge($createPayload, ['personel_id' => 20]));
zimmetAssert($baOther['status'] === 403, 'BA other sube → 403');

$baOwn = invokeZimmetHttp($pdo, $ba, 'POST', '/zimmetler', $createPayload);
zimmetAssert($baOwn['status'] === 403, 'BA create → 403 (no personeller.update)');

$baList = invokeZimmetHttp($pdo, $ba, 'GET', '/zimmetler', [], [], ['personel_id' => '10']);
zimmetAssert($baList['status'] === 200, 'BA list own sube → 200');

$baListOther = invokeZimmetHttp($pdo, $ba, 'GET', '/zimmetler', [], [], ['personel_id' => '20']);
zimmetAssert($baListOther['status'] === 403, 'BA list other sube → 403');

$muhCreate = invokeZimmetHttp($pdo, $muhasebe, 'POST', '/zimmetler', array_merge($createPayload, ['personel_id' => 20]));
zimmetAssert($muhCreate['status'] === 201, 'MUHASEBE create → 201');

$patronCreate = invokeZimmetHttp($pdo, $patron, 'POST', '/zimmetler', $createPayload);
zimmetAssert($patronCreate['status'] === 403, 'PATRON create → 403');

$unauth = invokeZimmetHttp($pdo, null, 'POST', '/zimmetler', $createPayload);
zimmetAssert($unauth['status'] === 401, 'unauthenticated → 401');

echo "verify-zimmetler-create-list-mysql: OK\n";
