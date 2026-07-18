<?php

declare(strict_types=1);

/**
 * MariaDB HTTP + persistence acceptance for surecler detail/update/cancel.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\SureclerController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function surecAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function surecPdo(string $dsn): PDO
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
function invokeSurecHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = []): array
{
    setConnectionPdo($pdo);
    resetAuthUser($user);

    $statusFile = tempnam(sys_get_temp_dir(), 'surec_http_');
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

    $method = strtoupper((string) ($cfg['method'] ?? 'GET'));
    $path = (string) ($cfg['path'] ?? '');
    $body = is_array($cfg['body'] ?? null) ? $cfg['body'] : [];
    $headers = is_array($cfg['headers'] ?? null) ? $cfg['headers'] : [];
    $request = makeRequest($method, $path, $body, $headers);

    if ($method === 'GET' && preg_match('#^/surecler/(\d+)$#', $path, $m)) {
        SureclerController::detail($request, $m[1]);
    }
    if ($method === 'PUT' && preg_match('#^/surecler/(\d+)$#', $path, $m)) {
        SureclerController::update($request, $m[1]);
    }
    if ($method === 'POST' && preg_match('#^/surecler/(\d+)/iptal$#', $path, $m)) {
        SureclerController::cancel($request, $m[1]);
    }

    fwrite(STDERR, "unhandled route\n");
    exit(3);
}

function bootstrapSurecSchema(PDO $pdo): void
{
    $suffix = bin2hex(random_bytes(4));
    $dbName = 'surec_s78c1_' . $suffix;
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
    $pdo->exec("
        CREATE TABLE surecler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          personel_id INT UNSIGNED NOT NULL,
          surec_turu VARCHAR(64) NOT NULL,
          alt_tur VARCHAR(64) NULL,
          baslangic_tarihi DATE NOT NULL,
          bitis_tarihi DATE NULL,
          ucretli_mi TINYINT(1) NOT NULL DEFAULT 0,
          ilk_iki_gun_firma_oder_mi TINYINT(1) NULL,
          aciklama TEXT NULL,
          state VARCHAR(32) NOT NULL DEFAULT 'AKTIF',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY idx_surecler_personel (personel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec("
        INSERT INTO personeller (
          id, tc_kimlik_no, ad, soyad, dogum_tarihi, sicil_no, ise_giris_tarihi, sube_id, aktif_durum
        ) VALUES
          (10, '11111111111', 'Ayse', 'Yilmaz', '1990-01-01', 'S10', '2020-01-01', 1, 'AKTIF'),
          (20, '22222222222', 'Mehmet', 'Demir', '1988-01-01', 'S20', '2020-01-01', 2, 'AKTIF')
    ");
    $pdo->exec("
        INSERT INTO surecler (id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, ucretli_mi, aciklama, state)
        VALUES
          (100, 10, 'IZIN', NULL, '2026-07-01', '2026-07-05', 1, 'Yillik izin', 'AKTIF'),
          (101, 20, 'RAPOR', 'Raporlu_Hastalik', '2026-07-02', NULL, 0, 'Rapor', 'AKTIF'),
          (102, 10, 'IZIN', NULL, '2026-06-01', '2026-06-02', 1, 'Tamam', 'TAMAMLANDI')
    ");
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/SureclerController.php');
surecAssert(strpos($routerSource, 'SureclerController::detail') !== false, 'router GET detail');
surecAssert(strpos($routerSource, 'SureclerController::update') !== false, 'router PUT update');
surecAssert(strpos($routerSource, 'SureclerController::cancel') !== false, 'router POST iptal');
surecAssert(strpos($controllerSource, "RolePermissions::assert(\$user, 'surecler.detail.view')") !== false, 'detail permission');
surecAssert(strpos($controllerSource, "RolePermissions::assert(\$user, 'surecler.update')") !== false, 'update permission');
surecAssert(strpos($controllerSource, "RolePermissions::assert(\$user, 'surecler.cancel')") !== false, 'cancel permission');
surecAssert(strpos($controllerSource, 'DELETE FROM surecler') === false, 'no hard delete');

$root = surecPdo($dsn);
bootstrapSurecSchema($root);
$dbName = (string) $root->query('SELECT DATABASE()')->fetchColumn();
$pdo = surecPdo(preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn));

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$ba = ['id' => 3, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
$muhasebe = ['id' => 2, 'rol' => 'MUHASEBE', 'sube_ids' => []];

$detail = invokeSurecHttp($pdo, $gy, 'GET', '/surecler/100');
surecAssert($detail['status'] === 200, 'HTTP detail → 200');
surecAssert((int) ($detail['payload']['data']['id'] ?? 0) === 100, 'detail id');
surecAssert(($detail['payload']['data']['surec_turu'] ?? '') === 'IZIN', 'detail turu');

$missing = invokeSurecHttp($pdo, $gy, 'GET', '/surecler/9999');
surecAssert($missing['status'] === 404, 'HTTP detail missing → 404');

$baOther = invokeSurecHttp($pdo, $ba, 'GET', '/surecler/101');
surecAssert($baOther['status'] === 403, 'HTTP BA other sube → 403');

$baOwn = invokeSurecHttp($pdo, $ba, 'GET', '/surecler/100');
surecAssert($baOwn['status'] === 200, 'HTTP BA own sube detail → 200');

$baUpdate = invokeSurecHttp($pdo, $ba, 'PUT', '/surecler/100', [
    'surec_turu' => 'IZIN',
    'baslangic_tarihi' => '2026-07-01',
    'aciklama' => 'x',
]);
surecAssert($baUpdate['status'] === 403, 'HTTP BA update → 403');

$emptyUpdate = invokeSurecHttp($pdo, $gy, 'PUT', '/surecler/100', []);
surecAssert($emptyUpdate['status'] === 422, 'HTTP empty update → 422');

$personelChange = invokeSurecHttp($pdo, $gy, 'PUT', '/surecler/100', [
    'personel_id' => 20,
    'surec_turu' => 'IZIN',
    'baslangic_tarihi' => '2026-07-01',
]);
surecAssert($personelChange['status'] === 422, 'HTTP personel_id change → 422');

$okUpdate = invokeSurecHttp($pdo, $gy, 'PUT', '/surecler/100', [
    'surec_turu' => 'IZIN',
    'baslangic_tarihi' => '2026-07-01',
    'bitis_tarihi' => '2026-07-06',
    'ucretli_mi' => true,
    'aciklama' => 'Guncellendi',
]);
surecAssert($okUpdate['status'] === 200, 'HTTP update → 200');
surecAssert(($okUpdate['payload']['data']['aciklama'] ?? '') === 'Guncellendi', 'update aciklama');
surecAssert(($okUpdate['payload']['data']['bitis_tarihi'] ?? '') === '2026-07-06', 'update bitis');

$row = $pdo->query('SELECT aciklama, bitis_tarihi, personel_id FROM surecler WHERE id = 100')->fetch();
surecAssert(($row['aciklama'] ?? '') === 'Guncellendi', 'DB update side effect');
surecAssert((int) ($row['personel_id'] ?? 0) === 10, 'DB personel unchanged');

$tamamUpdate = invokeSurecHttp($pdo, $gy, 'PUT', '/surecler/102', [
    'aciklama' => 'nope',
]);
surecAssert($tamamUpdate['status'] === 409, 'HTTP update TAMAMLANDI → 409');

$cancel = invokeSurecHttp($pdo, $gy, 'POST', '/surecler/100/iptal');
surecAssert($cancel['status'] === 200, 'HTTP cancel → 200');
surecAssert(($cancel['payload']['data']['state'] ?? '') === 'IPTAL', 'cancel state');
$dbState = (string) $pdo->query('SELECT state FROM surecler WHERE id = 100')->fetchColumn();
surecAssert($dbState === 'IPTAL', 'DB cancel state');
$count = (int) $pdo->query('SELECT COUNT(*) FROM surecler WHERE id = 100')->fetchColumn();
surecAssert($count === 1, 'cancel is soft (row remains)');

$cancelAgain = invokeSurecHttp($pdo, $gy, 'POST', '/surecler/100/iptal');
surecAssert($cancelAgain['status'] === 200, 'HTTP cancel idempotent → 200');
surecAssert(($cancelAgain['payload']['data']['state'] ?? '') === 'IPTAL', 'idempotent state');

$cancelTamam = invokeSurecHttp($pdo, $gy, 'POST', '/surecler/102/iptal');
surecAssert($cancelTamam['status'] === 409, 'HTTP cancel TAMAMLANDI → 409');

$baCancel = invokeSurecHttp($pdo, $ba, 'POST', '/surecler/101/iptal');
surecAssert($baCancel['status'] === 403, 'HTTP BA cancel other → 403');

$muhCancelOwnScope = invokeSurecHttp($pdo, $muhasebe, 'POST', '/surecler/101/iptal');
surecAssert($muhCancelOwnScope['status'] === 200, 'HTTP MUHASEBE cancel → 200');

echo "verify-surecler-detail-update-cancel-mysql: OK\n";
