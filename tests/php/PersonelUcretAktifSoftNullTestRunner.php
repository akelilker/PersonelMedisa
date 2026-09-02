<?php

declare(strict_types=1);

/**
 * MG-PERSONEL-AKTIF-UCRET-SOFT-NULL-001
 * HTTP acceptance for GET /personeller/{id}/ucretler/aktif soft-null contract.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\PersonelUcretController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\PersonelUcretService;

function softNullAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function softNullCreateDb(string $dbFile): PDO
{
    if (is_file($dbFile)) {
        @unlink($dbFile);
    }
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE personeller (
        id INTEGER PRIMARY KEY,
        sube_id INTEGER NOT NULL,
        maas_tutari REAL,
        ise_giris_tarihi TEXT,
        calisan_kapsami TEXT NOT NULL DEFAULT "IC_PERSONEL"
    )');
    $pdo->exec('CREATE TABLE personel_ucret_gecmisi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        personel_id INTEGER NOT NULL,
        ucret_tutari REAL NOT NULL,
        ucret_turu TEXT NOT NULL,
        para_birimi TEXT NOT NULL,
        gecerlilik_baslangic TEXT NOT NULL,
        gecerlilik_bitis TEXT,
        state TEXT NOT NULL,
        kaynak TEXT NOT NULL,
        aciklama TEXT,
        created_by INTEGER,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_by INTEGER,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        iptal_edildi_at TEXT,
        iptal_edildi_by INTEGER,
        revision_no INTEGER NOT NULL DEFAULT 1
    )');
    $pdo->exec('CREATE TABLE personel_ucret_auditleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        personel_id INTEGER NOT NULL,
        ucret_kaydi_id INTEGER,
        aksiyon TEXT NOT NULL,
        onceki_snapshot TEXT,
        sonraki_snapshot TEXT,
        actor_id INTEGER,
        actor_rol TEXT,
        sube_id INTEGER,
        request_hash TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec("INSERT INTO personeller (id, sube_id, maas_tutari, ise_giris_tarihi, calisan_kapsami) VALUES
        (1, 1, NULL, '2024-01-01', 'IC_PERSONEL'),
        (2, 1, NULL, '2024-01-01', 'IC_PERSONEL')");

    return $pdo;
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

function makeRequest(string $method, string $path): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => [],
        'jsonBody' => [],
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }

    return $request;
}

/**
 * @param array<string, mixed> $user
 * @param array<string, string> $query
 * @return array{status:int, payload:array<string,mixed>}
 */
function invokeAktifHttp(string $sourceDbFile, array $user, string $personelId, array $query = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'ucret_aktif_');
    if ($statusFile === false) {
        throw new RuntimeException('tempnam failed');
    }
    $dbCopy = tempnam(sys_get_temp_dir(), 'ucret_aktif_db_');
    if ($dbCopy === false) {
        throw new RuntimeException('tempnam db failed');
    }
    @unlink($dbCopy);
    $dbCopy .= '.sqlite';
    if (!copy($sourceDbFile, $dbCopy)) {
        throw new RuntimeException('failed to copy sqlite fixture');
    }

    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'ext';
        if (is_dir($extensionDir)) {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=php_pdo_sqlite.dll';
    }

    $payload = json_encode([
        'db_file' => $dbCopy,
        'auth' => $user,
        'personel_id' => $personelId,
        'query' => $query,
        'status_file' => $statusFile,
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--http-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes);
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
    @unlink($dbCopy);
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

    $_GET = is_array($cfg['query'] ?? null) ? $cfg['query'] : [];

    $pdo = new PDO('sqlite:' . (string) $cfg['db_file']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    setConnectionPdo($pdo);
    resetAuthUser($cfg['auth']);

    register_shutdown_function(static function () use ($cfg): void {
        file_put_contents((string) $cfg['status_file'], (string) http_response_code());
    });

    $personelId = (string) ($cfg['personel_id'] ?? '0');
    $request = makeRequest('GET', '/personeller/' . $personelId . '/ucretler/aktif');
    PersonelUcretController::aktif($request, $personelId);
    exit(0);
}

$gy = [
    'id' => 10,
    'rol' => 'GENEL_YONETICI',
    'sube_id' => 1,
    'sube_ids' => [1],
];
$personelRole = [
    'id' => 99,
    'rol' => 'PERSONEL',
    'sube_id' => 1,
    'sube_ids' => [1],
];

$dbFile = tempnam(sys_get_temp_dir(), 'ucret_aktif_seed_');
if ($dbFile === false) {
    throw new RuntimeException('seed tempnam failed');
}
@unlink($dbFile);
$dbFile .= '.sqlite';
$pdo = softNullCreateDb($dbFile);

$missing = invokeAktifHttp($dbFile, $gy, '1', ['tarih' => '2026-09-02']);
softNullAssert($missing['status'] === 200, 'missing active wage returns HTTP 200');
softNullAssert(
    array_key_exists('data', $missing['payload']) && $missing['payload']['data'] === null,
    'missing active wage returns data null'
);
softNullAssert(($missing['payload']['errors'] ?? ['x']) === [], 'missing active wage has empty errors');

PersonelUcretService::createSalaryRecord($pdo, 1, [
    'ucret_tutari' => 15000,
    'ucret_turu' => 'NET',
    'gecerlilik_baslangic' => '2026-01-01',
]);

$found = invokeAktifHttp($dbFile, $gy, '1', ['tarih' => '2026-09-02']);
softNullAssert($found['status'] === 200, 'active wage returns HTTP 200');
softNullAssert(is_array($found['payload']['data'] ?? null), 'active wage returns record object');
softNullAssert((int) ($found['payload']['data']['personel_id'] ?? 0) === 1, 'active wage personel_id matches');
softNullAssert((float) ($found['payload']['data']['ucret_tutari'] ?? 0) === 15000.0, 'active wage amount matches');

$notFound = invokeAktifHttp($dbFile, $gy, '999', ['tarih' => '2026-09-02']);
softNullAssert($notFound['status'] === 404, 'unknown personel returns HTTP 404');
softNullAssert(
    (($notFound['payload']['errors'][0]['code'] ?? '') === 'SALARY_RECORD_NOT_FOUND'),
    'unknown personel returns SALARY_RECORD_NOT_FOUND'
);

$forbidden = invokeAktifHttp($dbFile, $personelRole, '1', ['tarih' => '2026-09-02']);
softNullAssert($forbidden['status'] === 403, 'unauthorized role returns HTTP 403');
softNullAssert(
    (($forbidden['payload']['errors'][0]['code'] ?? '') === 'SALARY_ACCESS_FORBIDDEN'),
    'unauthorized role returns SALARY_ACCESS_FORBIDDEN'
);

@unlink($dbFile);
echo "verify-personel-ucret-aktif-soft-null: OK\n";
