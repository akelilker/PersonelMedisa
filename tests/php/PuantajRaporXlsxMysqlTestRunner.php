<?php

declare(strict_types=1);

/**
 * Focused MariaDB acceptance for GET /raporlar/puantaj/export.xlsx.
 * Same puantaj fetch as the JSON report. Does not run the full DB suite.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\RaporlarController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function prxAssert(bool $condition, string $name): void
{
    if (!$condition) {
        fwrite(STDERR, '[FAIL] ' . $name . PHP_EOL);
        exit(1);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function prxPdo(string $dsn): PDO
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

function prxSetConnection(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function prxResetAuth($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function prxPhpArgs(): array
{
    return ['-d', 'display_errors=stderr', '-d', 'log_errors=0'];
}

if (PHP_SAPI === 'cli-server') {
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $pdo = new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    prxSetConnection($pdo);
    $authRaw = $_SERVER['HTTP_X_TEST_AUTH'] ?? 'null';
    $auth = json_decode((string) $authRaw, true);
    prxResetAuth($auth);
    $request = new Request();
    $path = $request->getPath();
    if ($path === '/raporlar/puantaj/export.xlsx') {
        RaporlarController::exportPuantajXlsx($request);
    }
    if ($path === '/raporlar/puantaj') {
        RaporlarController::show($request, 'puantaj');
    }
    http_response_code(404);
    echo 'unhandled route';
    return;
}

/**
 * @return array{process:resource, base:string}
 */
function prxStartServer(string $dsn): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException('port bind failed: ' . $errstr);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr((string) strrchr((string) $name, ':'), 1);
    $cmd = array_merge([PHP_BINARY], prxPhpArgs(), ['-S', '127.0.0.1:' . $port, __FILE__]);
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), [
        'MEDISA_TEST_MYSQL_DSN' => $dsn,
        'MEDISA_TEST_MYSQL_USER' => getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        'MEDISA_TEST_MYSQL_PASSWORD' => getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
    ]));
    if (!is_resource($process)) {
        throw new RuntimeException('php server failed to start');
    }
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $e, $s, 0.2);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        proc_terminate($process);
        throw new RuntimeException('php server did not accept connections');
    }

    return ['process' => $process, 'base' => 'http://127.0.0.1:' . $port];
}

/**
 * @param array{process:resource, base:string} $server
 * @return array{status:int, headers:list<string>, body:string}
 */
function prxInvoke(array $server, $user, string $path, array $headers = [], array $query = []): array
{
    $url = $server['base'] . $path;
    if (count($query) > 0) {
        $url .= '?' . http_build_query($query);
    }
    $bodyFile = tempnam(sys_get_temp_dir(), 'prx_body_');
    $headerFile = tempnam(sys_get_temp_dir(), 'prx_hdr_');
    if ($bodyFile === false || $headerFile === false) {
        throw new RuntimeException('tempnam failed');
    }
    $cmd = ['curl.exe', '-sS', '-D', $headerFile, '-o', $bodyFile, '-H', 'X-Test-Auth: ' . json_encode($user)];
    foreach ($headers as $name => $value) {
        $cmd[] = '-H';
        $cmd[] = $name . ': ' . $value;
    }
    $cmd[] = $url;
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('curl failed to start');
    }
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $headerText = (string) file_get_contents($headerFile);
    $body = (string) file_get_contents($bodyFile);
    @unlink($headerFile);
    @unlink($bodyFile);
    if ($exit !== 0) {
        throw new RuntimeException('curl failed: ' . $stderr);
    }
    $headerLines = preg_split("/\r\n|\n|\r/", trim($headerText)) ?: [];
    $status = 0;
    if (isset($headerLines[0]) && preg_match('/\s(\d{3})\s/', $headerLines[0], $match) === 1) {
        $status = (int) $match[1];
    }

    return ['status' => $status, 'headers' => $headerLines, 'body' => $body];
}

function prxHeaderValue(array $headers, string $name): string
{
    $prefix = strtolower($name) . ':';
    foreach ($headers as $header) {
        if (stripos((string) $header, $prefix) === 0) {
            return trim(substr((string) $header, strlen($prefix)));
        }
    }

    return '';
}

function prxSheetXml(string $binary): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'prx_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('xlsx temp failed');
    }
    file_put_contents($tmp, $binary);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new RuntimeException('xlsx zip open failed');
    }
    $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    @unlink($tmp);

    return $xml;
}

function prxBootstrap(PDO $pdo): string
{
    $dbName = 'prx_' . bin2hex(random_bytes(4));
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $dbName . '`');
    $pdo->exec("
        CREATE TABLE subeler (
          id INT UNSIGNED NOT NULL PRIMARY KEY,
          kod VARCHAR(32) NOT NULL,
          ad VARCHAR(120) NOT NULL
        ) ENGINE=InnoDB
    ");
    $pdo->exec("
        CREATE TABLE departmanlar (
          id INT UNSIGNED NOT NULL PRIMARY KEY,
          ad VARCHAR(120) NOT NULL
        ) ENGINE=InnoDB
    ");
    $pdo->exec("
        CREATE TABLE personeller (
          id INT UNSIGNED NOT NULL PRIMARY KEY,
          ad VARCHAR(80) NOT NULL,
          soyad VARCHAR(80) NOT NULL,
          sicil_no VARCHAR(32) NOT NULL,
          sube_id INT UNSIGNED NOT NULL,
          departman_id INT UNSIGNED NULL,
          bolum_id INT UNSIGNED NULL,
          birim_id INT UNSIGNED NULL,
          aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF'
        ) ENGINE=InnoDB
    ");
    $pdo->exec("
        CREATE TABLE gunluk_puantaj (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          personel_id INT UNSIGNED NOT NULL,
          tarih DATE NOT NULL,
          giris_saati VARCHAR(8) NULL,
          cikis_saati VARCHAR(8) NULL,
          net_calisma_suresi_dakika INT UNSIGNED NULL,
          gec_kalma_dakika INT UNSIGNED NULL,
          erken_cikis_dakika INT UNSIGNED NULL,
          hareket_durumu VARCHAR(32) NULL,
          dayanak VARCHAR(64) NULL
        ) ENGINE=InnoDB
    ");
    $pdo->exec("
        CREATE TABLE puantaj_aylik_muhurleri (
          id INT UNSIGNED NOT NULL PRIMARY KEY,
          donem CHAR(7) NOT NULL,
          sube_id INT UNSIGNED NOT NULL
        ) ENGINE=InnoDB
    ");
    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (3, 'Operasyon')");
    $pdo->exec("
        INSERT INTO personeller (id, ad, soyad, sicil_no, sube_id, departman_id, bolum_id, birim_id, aktif_durum)
        VALUES
          (10, 'Ayse', 'Yilmaz', 'S10', 1, 3, 31, 41, 'AKTIF'),
          (30, 'Can', 'Kara', 'S30', 1, 3, 31, 42, 'AKTIF'),
          (20, 'Mehmet', 'Demir', 'S20', 2, NULL, NULL, NULL, 'AKTIF')
    ");
    $stmt = $pdo->prepare("
        INSERT INTO gunluk_puantaj (
          personel_id, tarih, giris_saati, cikis_saati, net_calisma_suresi_dakika,
          gec_kalma_dakika, erken_cikis_dakika, hareket_durumu, dayanak
        ) VALUES (
          :personel_id, :tarih, :giris, :cikis, :net, :gec, :erken, :hareket, :dayanak
        )
    ");
    $rows = [
        [10, '2026-04-06', '08:00', '17:00', 480, 5, 0, 'Geldi', null],
        [10, '2026-05-02', '08:10', '17:00', 111, 10, 0, 'Geldi', null],
        [30, '2026-04-06', '09:00', '18:00', 777, 0, 0, 'Geldi', null],
        [20, '2026-04-06', '08:00', '16:00', 888, 0, 15, 'Erken_Cikti', null],
    ];
    foreach ($rows as $row) {
        $stmt->execute([
            'personel_id' => $row[0],
            'tarih' => $row[1],
            'giris' => $row[2],
            'cikis' => $row[3],
            'net' => $row[4],
            'gec' => $row[5],
            'erken' => $row[6],
            'hareket' => $row[7],
            'dayanak' => $row[8],
        ]);
    }

    return $dbName;
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$router = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controller = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/RaporlarController.php');
prxAssert(strpos($router, "RaporlarController::exportPuantajXlsx") !== false, 'router export.xlsx owner');
prxAssert(strpos($controller, 'new SimpleXlsxWriter()') !== false, 'xlsx uses SimpleXlsxWriter');
prxAssert(strpos($controller, 'fetchPuantajLive') !== false && strpos($controller, 'fetchPuantajSnapshot') !== false, 'xlsx reuses puantaj fetch');

$root = prxPdo($dsn);
$dbName = '';
$server = null;
try {
    $dbName = prxBootstrap($root);
    $scoped = preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn);
    $server = prxStartServer((string) $scoped);
    $april = [
        'baslangic_tarihi' => '2026-04-01',
        'bitis_tarihi' => '2026-04-30',
        'aktiflik' => 'tum',
    ];
    $ba = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1], 'birim_ids' => [41]];
    $gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $personel = ['id' => 3, 'rol' => 'PERSONEL', 'sube_ids' => []];
    $subeHeader = ['x-active-sube-id' => '1'];

    $anon = prxInvoke($server, null, '/raporlar/puantaj/export.xlsx', [], $april);
    prxAssert($anon['status'] === 401, 'unauthenticated xlsx → 401');

    $denied = prxInvoke($server, $personel, '/raporlar/puantaj/export.xlsx', $subeHeader, $april);
    prxAssert($denied['status'] === 403, 'PERSONEL xlsx → 403');

    $baFile = prxInvoke($server, $ba, '/raporlar/puantaj/export.xlsx', $subeHeader, $april);
    prxAssert($baFile['status'] === 200, 'BA xlsx → 200');
    prxAssert(str_starts_with($baFile['body'], "PK\x03\x04"), 'xlsx zip signature');
    prxAssert(
        strpos(prxHeaderValue($baFile['headers'], 'Content-Type'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') !== false,
        'xlsx content-type'
    );
    prxAssert(
        strpos(prxHeaderValue($baFile['headers'], 'Content-Disposition'), 'filename="puantaj-raporu-2026-04.xlsx"') !== false,
        'xlsx month filename'
    );
    $baSheet = prxSheetXml($baFile['body']);
    prxAssert(strpos($baSheet, 'Tarih') !== false && strpos($baSheet, 'İzin / Devamsızlık') !== false, 'xlsx headers match puantaj contract');
    prxAssert(strpos($baSheet, 'Ayse Yilmaz') !== false && strpos($baSheet, '480') !== false, 'BA xlsx includes own birim row');
    prxAssert(strpos($baSheet, 'Can Kara') === false && strpos($baSheet, '777') === false, 'BA xlsx excludes other birim personel');
    prxAssert(strpos($baSheet, 'Mehmet Demir') === false && strpos($baSheet, '888') === false, 'BA xlsx excludes other sube personel');
    prxAssert(strpos($baSheet, '111') === false, 'BA xlsx excludes date outside filter');

    $json = prxInvoke($server, $ba, '/raporlar/puantaj', $subeHeader, $april);
    prxAssert($json['status'] === 200, 'BA json report → 200');
    $decoded = json_decode($json['body'], true);
    $jsonItems = $decoded['data']['items'] ?? [];
    prxAssert(is_array($jsonItems) && count($jsonItems) === 1, 'BA json row count matches scope');
    prxAssert((int) ($jsonItems[0]['personel_id'] ?? 0) === 10, 'BA json personel matches xlsx scope');
    prxAssert((int) ($jsonItems[0]['net_calisma_dakika'] ?? 0) === 480, 'BA json minutes match stored row');

    $gyFile = prxInvoke($server, $gy, '/raporlar/puantaj/export.xlsx', $subeHeader, $april);
    prxAssert($gyFile['status'] === 200, 'GY xlsx → 200');
    $gySheet = prxSheetXml($gyFile['body']);
    prxAssert(strpos($gySheet, 'Ayse Yilmaz') !== false && strpos($gySheet, 'Can Kara') !== false, 'GY xlsx includes both birim rows in sube');
    prxAssert(strpos($gySheet, 'Mehmet Demir') === false, 'GY active sube excludes other sube personel');

    $range = prxInvoke($server, $ba, '/raporlar/puantaj/export.xlsx', $subeHeader, [
        'baslangic_tarihi' => '2026-04-06',
        'bitis_tarihi' => '2026-04-12',
        'aktiflik' => 'tum',
    ]);
    prxAssert(
        strpos(prxHeaderValue($range['headers'], 'Content-Disposition'), 'filename="puantaj-raporu-2026-04-06_2026-04-12.xlsx"') !== false,
        'xlsx range filename'
    );

    echo "verify-puantaj-raporu-xlsx-mysql: OK\n";
} finally {
    if (is_array($server) && is_resource($server['process'])) {
        proc_terminate($server['process']);
        proc_close($server['process']);
    }
    if ($dbName !== '') {
        $root->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '', $dbName) . '`');
    }
}
