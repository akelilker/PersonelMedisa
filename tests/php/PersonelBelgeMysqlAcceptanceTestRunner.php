<?php

declare(strict_types=1);

/**
 * S86 pre-merge: real PHP + disposable MariaDB belge CRUD + parallel file-replace.
 * Mock yok. Production DB yok.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\PersonelBelgelerController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\PersonelBelge\PersonelBelgeStorageService;

function pbAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function pbPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
}

function pbSetConnection(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function pbResetAuth($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function pbMakeRequest(string $method, string $path, array $body = [], array $headers = [], array $query = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'jsonBody' => $body,
        'query' => $query,
    ] as $name => $value) {
        if (!$ref->hasProperty($name)) {
            continue;
        }
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }

    return $request;
}

/** @return list<string> */
function pbSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function pbApplyFile(PDO $pdo, string $file): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (pbSplitSql($sql) as $statement) {
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}

/** @return list<string> */
function pbMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

function pbPhpArgs(): array
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

    return $phpArgs;
}

/**
 * @return array{status:int, payload:?array, raw:string, stderr:string}
 */
function pbInvokeHttp(
    string $database,
    $user,
    string $method,
    string $path,
    array $body = [],
    array $query = [],
    string $storageRoot = ''
): array {
    $statusFile = tempnam(sys_get_temp_dir(), 'pb_http_');
    if ($statusFile === false) {
        throw new RuntimeException('tempnam failed');
    }

    $payload = json_encode([
        'dsn' => getenv('MEDISA_TEST_MYSQL_DSN'),
        'user' => getenv('MEDISA_TEST_MYSQL_USER'),
        'password' => getenv('MEDISA_TEST_MYSQL_PASSWORD'),
        'database' => $database,
        'auth' => $user,
        'method' => $method,
        'path' => $path,
        'body' => $body,
        'query' => $query,
        'status_file' => $statusFile,
        'storage_root' => $storageRoot,
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], pbPhpArgs(), [__FILE__, '--http-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv(), [
        'MEDISA_TEST_MYSQL_DSN' => getenv('MEDISA_TEST_MYSQL_DSN') ?: '',
        'MEDISA_TEST_MYSQL_USER' => getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        'MEDISA_TEST_MYSQL_PASSWORD' => getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        'MEDISA_PERSONEL_BELGE_STORAGE_ROOT' => $storageRoot,
    ]);
    $process = proc_open($cmd, $descriptors, $pipes, null, $env);
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
    $decoded = null;
    if ($jsonStart !== false) {
        $decoded = json_decode(substr($stdout, $jsonStart), true);
        if (!is_array($decoded)) {
            $decoded = null;
        }
    }

    return ['status' => $status, 'payload' => $decoded, 'raw' => $stdout, 'stderr' => $stderr];
}

/**
 * @return array{process:resource,pipes:array<int,resource>,label:string}
 */
function pbSpawnReplaceChild(string $database, $user, int $kayitId, array $body, string $storageRoot, string $label): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'pb_par_');
    if ($statusFile === false) {
        throw new RuntimeException('tempnam failed');
    }
    $payload = json_encode([
        'dsn' => getenv('MEDISA_TEST_MYSQL_DSN'),
        'user' => getenv('MEDISA_TEST_MYSQL_USER'),
        'password' => getenv('MEDISA_TEST_MYSQL_PASSWORD'),
        'database' => $database,
        'auth' => $user,
        'method' => 'POST',
        'path' => '/belge-kayitlari/' . $kayitId . '/dosya-degistir',
        'body' => $body,
        'query' => [],
        'status_file' => $statusFile,
        'storage_root' => $storageRoot,
        'label' => $label,
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], pbPhpArgs(), [__FILE__, '--http-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = array_merge(getenv(), [
        'MEDISA_TEST_MYSQL_DSN' => getenv('MEDISA_TEST_MYSQL_DSN') ?: '',
        'MEDISA_TEST_MYSQL_USER' => getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        'MEDISA_TEST_MYSQL_PASSWORD' => getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        'MEDISA_PERSONEL_BELGE_STORAGE_ROOT' => $storageRoot,
    ]);
    $process = proc_open($cmd, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('parallel child failed');
    }
    fwrite($pipes[0], (string) $payload);
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes, 'label' => $label, 'status_file' => $statusFile];
}

/**
 * @param array{process:resource,pipes:array<int,resource>,label:string,status_file:string} $child
 * @return array{status:int,payload:?array,raw:string,stderr:string,label:string}
 */
function pbFinishChild(array $child): array
{
    $stdout = (string) stream_get_contents($child['pipes'][1]);
    $stderr = (string) stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    proc_close($child['process']);

    $statusRaw = is_file($child['status_file']) ? trim((string) file_get_contents($child['status_file'])) : '';
    @unlink($child['status_file']);
    $status = (int) $statusRaw;
    $jsonStart = strpos($stdout, '{');
    $decoded = null;
    if ($jsonStart !== false) {
        $decoded = json_decode(substr($stdout, $jsonStart), true);
        if (!is_array($decoded)) {
            $decoded = null;
        }
    }

    return [
        'status' => $status,
        'payload' => $decoded,
        'raw' => $stdout,
        'stderr' => $stderr,
        'label' => (string) $child['label'],
    ];
}

function pbPdfBytes(string $marker): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" . $marker . "\n";
}

function pbFileBody(string $marker, string $name = 'belge.pdf'): array
{
    $bytes = pbPdfBytes($marker);

    return [
        'dosya_icerik_base64' => base64_encode($bytes),
        'dosya_adi' => $name,
        'dosya_mime' => 'application/pdf',
    ];
}

function pbListStorageFiles(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }
    $files = [];
    foreach (scandir($root) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        if (str_ends_with($name, '.tmp') || strpos($name, '.tmp.') !== false) {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            $files[] = $name;
        }
    }
    sort($files);

    return $files;
}

if (($argv[1] ?? '') === '--http-child') {
    $raw = stream_get_contents(STDIN);
    $cfg = json_decode((string) $raw, true);
    if (!is_array($cfg)) {
        fwrite(STDERR, "bad child config\n");
        exit(2);
    }

    $storageRoot = (string) ($cfg['storage_root'] ?? '');
    if ($storageRoot !== '') {
        putenv('MEDISA_PERSONEL_BELGE_STORAGE_ROOT=' . $storageRoot);
        $_ENV['MEDISA_PERSONEL_BELGE_STORAGE_ROOT'] = $storageRoot;
    }

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $cfg['database'], (string) $cfg['dsn']);
    $pdo = new PDO(
        (string) $dsn,
        (string) $cfg['user'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 15');
    pbSetConnection($pdo);
    pbResetAuth($cfg['auth']);

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
    $query = is_array($cfg['query'] ?? null) ? $cfg['query'] : [];
    $request = pbMakeRequest($method, $path, $body, [], $query);

    if ($method === 'POST' && preg_match('#^/personeller/(\d+)/belge-kayitlari$#', $path, $m)) {
        PersonelBelgelerController::createKaydi($request, $m[1]);
    }
    if ($method === 'PUT' && preg_match('#^/belge-kayitlari/(\d+)$#', $path, $m)) {
        PersonelBelgelerController::updateKaydi($request, $m[1]);
    }
    if ($method === 'POST' && preg_match('#^/belge-kayitlari/(\d+)/dosya-degistir$#', $path, $m)) {
        PersonelBelgelerController::replaceDosya($request, $m[1]);
    }
    if ($method === 'POST' && preg_match('#^/belge-kayitlari/(\d+)/iptal$#', $path, $m)) {
        PersonelBelgelerController::cancelKaydi($request, $m[1]);
    }
    if ($method === 'GET' && preg_match('#^/belge-kayitlari/(\d+)/gecmis$#', $path, $m)) {
        PersonelBelgelerController::gecmis($request, $m[1]);
    }
    if ($method === 'GET' && preg_match('#^/belge-kayitlari/(\d+)/indir$#', $path, $m)) {
        PersonelBelgelerController::indir($request, $m[1]);
    }
    if ($method === 'GET' && preg_match('#^/belge-kayitlari/(\d+)$#', $path, $m)) {
        PersonelBelgelerController::getKaydi($request, $m[1]);
    }

    fwrite(STDERR, "unhandled route\n");
    exit(3);
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$storageRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa_s86_belge_' . bin2hex(random_bytes(4));
if (!mkdir($storageRoot, 0750, true) && !is_dir($storageRoot)) {
    throw new RuntimeException('storage root create failed');
}
putenv('MEDISA_PERSONEL_BELGE_STORAGE_ROOT=' . $storageRoot);
$_ENV['MEDISA_PERSONEL_BELGE_STORAGE_ROOT'] = $storageRoot;

$root = pbPdo($dsn);
$dbName = 'medisa_s86_acc_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $testDsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn);
    $pdo = pbPdo((string) $testDsn);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 15');

    $files = pbMigrationFiles();
    pbAssert($files !== [] && $files[0] === '001_initial_schema.sql', 'zincir 001 ile baslar');
    pbAssert(end($files) === '038_personel_belge_yonetimi.sql', 'zincir 038 ile biter');
    foreach ($files as $file) {
        pbApplyFile($pdo, $file);
    }
    pbAssert(true, '001-038 apply (acceptance DB)');

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'MRK', 'Merkez', 'AKTIF')");
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum)
        VALUES (1, 'gy', 'x', 'Genel Yonetici', 'GENEL_YONETICI', 'AKTIF')");
    $pdo->exec("INSERT INTO personeller (
        id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
        sicil_no, ise_giris_tarihi, sube_id, aktif_durum
      ) VALUES (
        10, '11111111110', 'Ayse', 'Yilmaz', '1990-01-01', '555', 'Acil', '556',
        'S10', '2020-01-01', 1, 'AKTIF'
      )");

    $gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => [], 'username' => 'gy', 'ad_soyad' => 'Genel Yonetici'];

    $createBody = array_merge([
        'kayit_tipi' => 'SERTIFIKA',
        'ad' => 'ISO 9001',
        'veren_kurum' => 'TSE',
        'belge_no' => 'BEL-S86-001',
        'baslangic_tarihi' => '2026-01-01',
        'bitis_tarihi' => '2027-12-31',
        'aciklama' => 'S86 kabul',
    ], pbFileBody('CREATE-V1', 'create.pdf'));

    $created = pbInvokeHttp($dbName, $gy, 'POST', '/personeller/10/belge-kayitlari', $createBody, [], $storageRoot);
    pbAssert($created['status'] === 201, 'belge olustur → 201');
    $kayitId = (int) ($created['payload']['data']['id'] ?? 0);
    pbAssert($kayitId > 0, 'create id > 0');
    pbAssert(!empty($created['payload']['data']['dosya']['var_mi']), 'create aktif dosya var');
    pbAssert((int) ($created['payload']['data']['dosya']['surum_no'] ?? 0) === 1, 'create surum_no=1');

    $updated = pbInvokeHttp($dbName, $gy, 'PUT', '/belge-kayitlari/' . $kayitId, [
        'ad' => 'ISO 9001 Rev',
        'veren_kurum' => 'TSE',
        'belge_no' => 'BEL-S86-001',
        'kayit_tipi' => 'SERTIFIKA',
        'baslangic_tarihi' => '2026-01-01',
        'bitis_tarihi' => '2028-01-01',
        'aciklama' => 'metadata guncellendi',
    ], [], $storageRoot);
    pbAssert($updated['status'] === 200, 'metadata guncelle → 200');
    pbAssert(($updated['payload']['data']['ad'] ?? '') === 'ISO 9001 Rev', 'metadata ad guncellendi');

    $v1Keys = $pdo->query('SELECT storage_key, surum_no FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $kayitId)->fetchAll();
    pbAssert(count($v1Keys) === 1 && (int) $v1Keys[0]['surum_no'] === 1, 'ilk surum = 1');

    $replaced = pbInvokeHttp(
        $dbName,
        $gy,
        'POST',
        '/belge-kayitlari/' . $kayitId . '/dosya-degistir',
        pbFileBody('REPLACE-V2', 'replace.pdf'),
        [],
        $storageRoot
    );
    pbAssert($replaced['status'] === 200, 'dosya degistir → 200');

    $gecmis = pbInvokeHttp($dbName, $gy, 'GET', '/belge-kayitlari/' . $kayitId . '/gecmis', [], [], $storageRoot);
    pbAssert($gecmis['status'] === 200, 'gecmis → 200');
    $surumler = $gecmis['payload']['data']['surumler'] ?? [];
    pbAssert(count($surumler) === 2, 'eski surum listelenir (2)');
    $aktifCount = 0;
    $surumNos = [];
    foreach ($surumler as $s) {
        $surumNos[] = (int) ($s['surum_no'] ?? 0);
        if (!empty($s['aktif_mi'])) {
            $aktifCount++;
        }
    }
    pbAssert($aktifCount === 1, 'gecmis tek aktif');
    pbAssert(count($surumNos) === count(array_unique($surumNos)), 'surum_no tekrarsiz (gecmis)');

    $indir = pbInvokeHttp($dbName, $gy, 'GET', '/belge-kayitlari/' . $kayitId . '/indir', [], [], $storageRoot);
    pbAssert($indir['status'] === 200, 'aktif surum indir → 200');
    pbAssert(strpos($indir['raw'], '%PDF-1.4') === 0 || strpos($indir['raw'], '%PDF-1.4') !== false, 'indir PDF icerik');
    pbAssert(strpos($indir['raw'], 'REPLACE-V2') !== false, 'indir aktif (v2) icerik');
    pbAssert(strpos($indir['stderr'] . $indir['raw'], 'Fatal') === false, 'indir fatal yok');

    $cancel = pbInvokeHttp($dbName, $gy, 'POST', '/belge-kayitlari/' . $kayitId . '/iptal', [
        'iptal_nedeni' => 'S86 kabul iptal',
    ], [], $storageRoot);
    pbAssert($cancel['status'] === 200, 'iptal → 200');
    pbAssert(strtoupper((string) ($cancel['payload']['data']['durum'] ?? '')) === 'IPTAL', 'state IPTAL');
    pbAssert(strtoupper((string) ($cancel['payload']['data']['takip_durumu'] ?? '')) === 'IPTAL', 'takip IPTAL');

    $audits = $gecmis['payload']['data']['auditler'] ?? [];
    // Refresh audits after cancel
    $gecmis2 = pbInvokeHttp($dbName, $gy, 'GET', '/belge-kayitlari/' . $kayitId . '/gecmis', [], [], $storageRoot);
    $auditTurleri = array_map(
        static fn ($a) => (string) ($a['islem_turu'] ?? ''),
        $gecmis2['payload']['data']['auditler'] ?? []
    );
    pbAssert(in_array('CREATED', $auditTurleri, true), 'audit CREATED');
    pbAssert(in_array('METADATA_UPDATED', $auditTurleri, true), 'audit METADATA_UPDATED');
    pbAssert(in_array('FILE_REPLACED', $auditTurleri, true), 'audit FILE_REPLACED');
    pbAssert(in_array('CANCELLED', $auditTurleri, true), 'audit CANCELLED');

    $rejectReplace = pbInvokeHttp(
        $dbName,
        $gy,
        'POST',
        '/belge-kayitlari/' . $kayitId . '/dosya-degistir',
        pbFileBody('AFTER-CANCEL', 'x.pdf'),
        [],
        $storageRoot
    );
    pbAssert($rejectReplace['status'] === 409, 'iptal sonrasi replace reddi → 409');
    pbAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $kayitId)->fetchColumn() === 2,
        'iptal sonrasi yeni surum yok'
    );

    $rejectUpdate = pbInvokeHttp($dbName, $gy, 'PUT', '/belge-kayitlari/' . $kayitId, [
        'ad' => 'olmamali',
        'kayit_tipi' => 'SERTIFIKA',
    ], [], $storageRoot);
    pbAssert($rejectUpdate['status'] === 409, 'iptal sonrasi metadata update reddi → 409');

    // --- Parallel file-replace ---
    $create2 = pbInvokeHttp($dbName, $gy, 'POST', '/personeller/10/belge-kayitlari', array_merge([
        'kayit_tipi' => 'KIMLIK',
        'ad' => 'Kimlik belgesi',
        'belge_no' => 'BEL-S86-CONC',
        'baslangic_tarihi' => '2026-01-01',
        'bitis_tarihi' => '2027-01-01',
    ], pbFileBody('CONC-V1', 'conc-v1.pdf')), [], $storageRoot);
    pbAssert($create2['status'] === 201, 'concurrency belge olustur → 201');
    $concId = (int) ($create2['payload']['data']['id'] ?? 0);
    pbAssert($concId > 0, 'concurrency kayit id');

    $beforeKeys = $pdo->query(
        'SELECT storage_key FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $concId
    )->fetchAll(PDO::FETCH_COLUMN);
    $beforeFiles = pbListStorageFiles($storageRoot);

    $childA = pbSpawnReplaceChild($dbName, $gy, $concId, pbFileBody('CONC-A', 'a.pdf'), $storageRoot, 'A');
    $childB = pbSpawnReplaceChild($dbName, $gy, $concId, pbFileBody('CONC-B', 'b.pdf'), $storageRoot, 'B');
    $resA = pbFinishChild($childA);
    $resB = pbFinishChild($childB);

    $okStatuses = [];
    $failStatuses = [];
    foreach ([$resA, $resB] as $res) {
        pbAssert(strpos($res['stderr'] . $res['raw'], 'Fatal error') === false, 'parallel fatal yok (' . $res['label'] . ')');
        pbAssert($res['status'] !== 500, 'parallel 500 sızıntı yok (' . $res['label'] . ' status=' . $res['status'] . ')');
        if ($res['status'] >= 200 && $res['status'] < 300) {
            $okStatuses[] = $res;
        } else {
            $failStatuses[] = $res;
        }
    }
    pbAssert(count($okStatuses) >= 1, 'en az bir parallel replace basarili');

    $dupAktif = $pdo->query("
        SELECT surec_id, COUNT(*) AS aktif_surum
        FROM personel_belge_dosya_surumleri
        WHERE aktif_mi = 1
        GROUP BY surec_id
        HAVING COUNT(*) > 1
    ")->fetchAll();
    pbAssert($dupAktif === [], 'SQL: birden fazla aktif surum yok');

    $concVersions = $pdo->query(
        'SELECT id, surum_no, aktif_mi, storage_key FROM personel_belge_dosya_surumleri WHERE surec_id = ' . $concId . ' ORDER BY surum_no'
    )->fetchAll();
    $aktifRows = array_values(array_filter($concVersions, static fn ($r) => (int) $r['aktif_mi'] === 1));
    pbAssert(count($aktifRows) === 1, 'concurrency sonunda 1 aktif surum');
    $surumNoList = array_map(static fn ($r) => (int) $r['surum_no'], $concVersions);
    pbAssert(count($surumNoList) === count(array_unique($surumNoList)), 'concurrency surum_no tekrarsiz');
    pbAssert(in_array(1, $surumNoList, true), 'eski aktif surum gecmiste korunur (surum 1)');

    $aktifKey = (string) $aktifRows[0]['storage_key'];
    $downloadOk = pbInvokeHttp($dbName, $gy, 'GET', '/belge-kayitlari/' . $concId . '/indir', [], [], $storageRoot);
    pbAssert($downloadOk['status'] === 200, 'basarili aktif surum indirilebilir');
    pbAssert(
        strpos($downloadOk['raw'], 'CONC-A') !== false || strpos($downloadOk['raw'], 'CONC-B') !== false,
        'indir parallel basarili icerik'
    );

    $dbKeys = $pdo->query('SELECT storage_key FROM personel_belge_dosya_surumleri')->fetchAll(PDO::FETCH_COLUMN);
    $diskFiles = pbListStorageFiles($storageRoot);
    sort($dbKeys);
    sort($diskFiles);
    $orphanOnDisk = array_values(array_diff($diskFiles, $dbKeys));
    pbAssert($orphanOnDisk === [], 'orphan dosya yok (disk\\db): ' . implode(',', $orphanOnDisk));

    // Failed child (if any) must not leave extra version beyond successful path
    $expectedMin = 1 + count($okStatuses); // v1 + each success
    pbAssert(count($concVersions) === $expectedMin, 'basarisiz tx ekstra surum birakmaz (count=' . count($concVersions) . ' expected=' . $expectedMin . ')');

    $concAudits = $pdo->query(
        "SELECT islem_turu FROM personel_belge_auditleri WHERE surec_id = $concId ORDER BY id"
    )->fetchAll(PDO::FETCH_COLUMN);
    $fileReplacedAudits = array_values(array_filter($concAudits, static fn ($t) => $t === 'FILE_REPLACED'));
    pbAssert(count($fileReplacedAudits) === count($okStatuses), 'audit FILE_REPLACED = basarili replace sayisi');
    pbAssert(in_array('CREATED', $concAudits, true), 'concurrency audit CREATED');

    // lock helper present
    $repoSrc = (string) file_get_contents(__DIR__ . '/../../api/src/Services/PersonelBelge/PersonelBelgeKayitRepository.php');
    $ctrlSrc = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/PersonelBelgelerController.php');
    pbAssert(strpos($repoSrc, 'FOR UPDATE') !== false, 'repo FOR UPDATE lock');
    pbAssert(strpos($ctrlSrc, 'lockSurecRowForUpdate') !== false, 'controller lock kullanir');
    pbAssert(strpos((string) file_get_contents(__DIR__ . '/../../api/migrations/038_personel_belge_yonetimi.sql'), 'uq_pbd_tek_aktif') !== false, '038 tek aktif unique');

    echo 'verify-personel-belge-mysql-acceptance: OK' . PHP_EOL;
} finally {
    try {
        $root->exec("DROP DATABASE IF EXISTS `$dbName`");
    } catch (Throwable $e) {
        // ignore
    }
    if (is_dir($storageRoot)) {
        foreach (pbListStorageFiles($storageRoot) as $f) {
            @unlink($storageRoot . DIRECTORY_SEPARATOR . $f);
        }
        @rmdir($storageRoot);
    }
}
