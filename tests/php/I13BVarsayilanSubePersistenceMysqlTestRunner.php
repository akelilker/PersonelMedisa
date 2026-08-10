<?php

declare(strict_types=1);

/**
 * I13-B: varsayilan_sube_id persistence + login active_sube + schema-absent compat (MariaDB).
 * php tests/php/I13BVarsayilanSubePersistenceMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\LoginController;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function i13bAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function i13bPdo(string $dsn): PDO
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

/** @return list<string> */
function i13bSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function i13bApplyFile(PDO $pdo, string $relative): void
{
    $path = __DIR__ . '/../../api/migrations/' . $relative;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $relative);
    }
    foreach (i13bSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function i13bSetPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function i13bResetAuth($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function i13bRequest(string $method, string $path, array $body = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => [],
        'jsonBody' => $body,
        'rawBody' => $body === [] ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE),
        'rawBodyLoaded' => true,
        'jsonBodyParsed' => true,
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

/**
 * @param array<string, mixed>|null $auth
 * @return array{status:int, payload:array<string,mixed>}
 */
function i13bHttp(PDO $pdo, $auth, string $action, array $extra = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'i13b_');
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
        foreach (['pdo_mysql'] as $ext) {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension=' . $ext;
        }
    }

    $payload = json_encode([
        'dsn' => getenv('MEDISA_TEST_MYSQL_DSN'),
        'user' => getenv('MEDISA_TEST_MYSQL_USER'),
        'password' => getenv('MEDISA_TEST_MYSQL_PASSWORD'),
        'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'auth' => $auth,
        'action' => $action,
        'extra' => $extra,
        'status_file' => $statusFile,
        'jwt_secret' => 'i13b-test-secret-32chars-minimum!!',
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--http-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = [];
    foreach ($_ENV as $k => $v) {
        if (is_string($k) && (is_string($v) || is_numeric($v))) {
            $env[$k] = (string) $v;
        }
    }
    foreach (['Path', 'PATH', 'SYSTEMROOT', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $key) {
        $val = getenv($key);
        if (is_string($val) && $val !== '') {
            $env[$key] = $val;
        }
    }
    $env['MEDISA_TEST_MYSQL_DSN'] = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $env['MEDISA_TEST_MYSQL_USER'] = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $env['MEDISA_TEST_MYSQL_PASSWORD'] = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';

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
    $jsonSlice = $jsonStart === false ? $stdout : substr($stdout, $jsonStart);
    $decoded = json_decode((string) $jsonSlice, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('http child invalid json: ' . $stdout . ' / ' . $stderr);
    }

    return ['status' => $status, 'payload' => $decoded];
}

/** @param array<int, array<string, mixed>> $items @return array<string, mixed>|null */
function i13bFindUserByUsername(array $items, string $username)
{
    foreach ($items as $item) {
        if (($item['username'] ?? '') === $username) {
            return $item;
        }
    }

    return null;
}

/** @param array<string, mixed> $row */
function i13bAssertNullField(array $row, string $key, string $name): void
{
    i13bAssert(array_key_exists($key, $row) && $row[$key] === null, $name);
}

if (($argv[1] ?? '') === '--http-child') {
    $raw = stream_get_contents(STDIN);
    $cfg = json_decode((string) $raw, true);
    if (!is_array($cfg)) {
        fwrite(STDERR, "bad child config\n");
        exit(2);
    }

    global $config;
    $config['db_host'] = '127.0.0.1';
    $config['db_name'] = (string) $cfg['database'];
    $config['db_user'] = 'i13b';
    $config['db_password'] = 'i13b';
    $config['jwt_secret'] = str_repeat('s', 32);
    $config['jwt_ttl_seconds'] = 3600;

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $cfg['database'], (string) $cfg['dsn']);
    $pdo = new PDO(
        (string) $dsn,
        (string) $cfg['user'],
        (string) $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
    i13bSetPdo($pdo);
    i13bResetAuth(array_key_exists('auth', $cfg) ? $cfg['auth'] : null);

    $statusFile = (string) $cfg['status_file'];
    register_shutdown_function(static function () use ($statusFile) {
        $code = http_response_code();
        if (!is_int($code) || $code < 100) {
            $code = 200;
        }
        file_put_contents($statusFile, (string) $code);
    });

    $action = (string) ($cfg['action'] ?? '');
    $extra = is_array($cfg['extra'] ?? null) ? $cfg['extra'] : [];

    if ($action === 'kullanicilar_list') {
        $_GET = [];
        YonetimController::kullanicilar(i13bRequest('GET', '/yonetim/kullanicilar'));
    } elseif ($action === 'kullanici_create') {
        $_GET = [];
        YonetimController::kullaniciOlustur(i13bRequest('POST', '/yonetim/kullanicilar', $extra));
    } elseif ($action === 'kullanici_update') {
        $_GET = [];
        $id = (int) ($extra['id'] ?? 0);
        $body = $extra;
        unset($body['id']);
        YonetimController::kullaniciGuncelle(
            i13bRequest('PUT', '/yonetim/kullanicilar/' . $id, $body),
            $id
        );
    } elseif ($action === 'login') {
        $_GET = [];
        i13bResetAuth(null);
        LoginController::login(i13bRequest('POST', '/auth/login', [
            'username' => (string) ($extra['username'] ?? ''),
            'password' => (string) ($extra['password'] ?? ''),
        ]));
    } else {
        fwrite(STDERR, "unknown action\n");
        exit(2);
    }
    exit(0);
}

$rootDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($rootDsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$root = i13bPdo($rootDsn);
$db = 'i13b_' . bin2hex(random_bytes(4));
$legacyDb = 'i13b_legacy_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$root->exec("CREATE DATABASE `$legacyDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$pass = 'I13bPersistPass-24chars!!';

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $db, $rootDsn);
    $pdo = i13bPdo((string) $dsn);

    i13bApplyFile($pdo, '001_initial_schema.sql');
    i13bApplyFile($pdo, '041_auth_smoke_readonly_role.sql');
    i13bApplyFile($pdo, '051_users_varsayilan_sube_id.sql');

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF'), (2, 'B', 'Sube B', 'AKTIF')");
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'gy', " . $pdo->quote($hash) . ", 'GY', 'GENEL_YONETICI', 'AKTIF')"
    );
    // GY unrestricted: empty user_subeler

    $gy = ['id' => 1, 'username' => 'gy', 'ad_soyad' => 'GY', 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];

    // A) Schema present create
    $r = i13bHttp($pdo, $gy, 'kullanici_create', [
        'username' => 'ba_scoped',
        'password' => $pass,
        'ad_soyad' => 'BA Scoped',
        'rol' => 'BIRIM_AMIRI',
        'durum' => 'AKTIF',
        'sube_ids' => [1, 2],
        'varsayilan_sube_id' => 2,
    ]);
    i13bAssert($r['status'] === 200, 'A create 200');
    $created = $r['payload']['data'] ?? [];
    $userId = (int) ($created['id'] ?? 0);
    i13bAssert($userId > 0, 'A create returns id');
    i13bAssert((int) ($created['varsayilan_sube_id'] ?? 0) === 2, 'A response varsayilan=2');
    $subeIds = $created['sube_ids'] ?? null;
    i13bAssert(is_array($subeIds) && $subeIds === [1, 2], 'A response sube_ids ASC [1,2]');

    $r = i13bHttp($pdo, $gy, 'kullanicilar_list');
    i13bAssert($r['status'] === 200, 'A list 200');
    $items = $r['payload']['data']['items'] ?? [];
    $listed = i13bFindUserByUsername(is_array($items) ? $items : [], 'ba_scoped');
    i13bAssert($listed !== null, 'A list finds ba_scoped');
    i13bAssert((int) ($listed['varsayilan_sube_id'] ?? 0) === 2, 'A list varsayilan=2');
    i13bAssert(($listed['sube_ids'] ?? null) === [1, 2], 'A list sube_ids ASC [1,2]');

    $dbDefault = $pdo->query('SELECT varsayilan_sube_id FROM users WHERE id = ' . $userId)->fetchColumn();
    i13bAssert((int) $dbDefault === 2, 'A DB column=2');

    // B) Update default to 1
    $r = i13bHttp($pdo, $gy, 'kullanici_update', [
        'id' => $userId,
        'varsayilan_sube_id' => 1,
    ]);
    i13bAssert($r['status'] === 200, 'B update 200');
    i13bAssert((int) ($r['payload']['data']['varsayilan_sube_id'] ?? 0) === 1, 'B response varsayilan=1');
    $r = i13bHttp($pdo, $gy, 'kullanicilar_list');
    $listed = i13bFindUserByUsername($r['payload']['data']['items'] ?? [], 'ba_scoped');
    i13bAssert((int) ($listed['varsayilan_sube_id'] ?? 0) === 1, 'B fresh GET varsayilan=1');

    // C) Explicit null clear
    $r = i13bHttp($pdo, $gy, 'kullanici_update', [
        'id' => $userId,
        'varsayilan_sube_id' => null,
    ]);
    i13bAssert($r['status'] === 200, 'C clear 200');
    i13bAssertNullField(is_array($r['payload']['data'] ?? null) ? $r['payload']['data'] : [], 'varsayilan_sube_id', 'C response null');
    $r = i13bHttp($pdo, $gy, 'kullanicilar_list');
    $listed = i13bFindUserByUsername($r['payload']['data']['items'] ?? [], 'ba_scoped');
    i13bAssert($listed !== null, 'C list finds user');
    i13bAssertNullField($listed, 'varsayilan_sube_id', 'C fresh GET null');

    // D) Set default 2; then update sube_ids [1] without varsayilan key → NULL
    $r = i13bHttp($pdo, $gy, 'kullanici_update', [
        'id' => $userId,
        'varsayilan_sube_id' => 2,
        'sube_ids' => [1, 2],
    ]);
    i13bAssert($r['status'] === 200, 'D set default 2');
    i13bAssert((int) ($r['payload']['data']['varsayilan_sube_id'] ?? 0) === 2, 'D varsayilan=2 before scope shrink');
    $r = i13bHttp($pdo, $gy, 'kullanici_update', [
        'id' => $userId,
        'sube_ids' => [1],
    ]);
    i13bAssert($r['status'] === 200, 'D scope shrink 200');
    i13bAssertNullField(is_array($r['payload']['data'] ?? null) ? $r['payload']['data'] : [], 'varsayilan_sube_id', 'D default cleared to NULL');
    $r = i13bHttp($pdo, $gy, 'kullanicilar_list');
    $listed = i13bFindUserByUsername($r['payload']['data']['items'] ?? [], 'ba_scoped');
    i13bAssert($listed !== null, 'D list finds user');
    i13bAssertNullField($listed, 'varsayilan_sube_id', 'D fresh GET null after scope shrink');

    // Restore scope for later login tests
    $r = i13bHttp($pdo, $gy, 'kullanici_update', [
        'id' => $userId,
        'sube_ids' => [1, 2],
        'varsayilan_sube_id' => 2,
    ]);
    i13bAssert($r['status'] === 200, 'restore scope+default for login');

    // E) Invalid default outside scope
    $r = i13bHttp($pdo, $gy, 'kullanici_create', [
        'username' => 'ba_bad_default',
        'password' => $pass,
        'ad_soyad' => 'BA Bad',
        'rol' => 'BIRIM_AMIRI',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
        'varsayilan_sube_id' => 2,
    ]);
    i13bAssert($r['status'] === 400, 'E create outside scope 400');
    i13bAssert(($r['payload']['errors'][0]['code'] ?? '') === 'VALIDATION_ERROR', 'E VALIDATION_ERROR');

    // F) Login preferred default
    $r = i13bHttp($pdo, null, 'login', ['username' => 'ba_scoped', 'password' => $pass]);
    i13bAssert($r['status'] === 200, 'F login 200');
    i13bAssert((int) ($r['payload']['data']['active_sube_id'] ?? 0) === 2, 'F active_sube_id=2');

    // G) Login default NULL → ASC first
    $r = i13bHttp($pdo, $gy, 'kullanici_update', [
        'id' => $userId,
        'varsayilan_sube_id' => null,
    ]);
    i13bAssert($r['status'] === 200, 'G clear default');
    $r = i13bHttp($pdo, null, 'login', ['username' => 'ba_scoped', 'password' => $pass]);
    i13bAssert($r['status'] === 200, 'G login 200');
    i13bAssert((int) ($r['payload']['data']['active_sube_id'] ?? 0) === 1, 'G active_sube_id=1 ASC first');

    // H) Login: one scope + NULL → sole sube
    $r = i13bHttp($pdo, $gy, 'kullanici_update', [
        'id' => $userId,
        'sube_ids' => [2],
        'varsayilan_sube_id' => null,
    ]);
    i13bAssert($r['status'] === 200, 'H sole scope update');
    $r = i13bHttp($pdo, null, 'login', ['username' => 'ba_scoped', 'password' => $pass]);
    i13bAssert($r['status'] === 200, 'H login 200');
    i13bAssert((int) ($r['payload']['data']['active_sube_id'] ?? 0) === 2, 'H active_sube_id=sole 2');

    // I) Out-of-scope stored default defense
    $r = i13bHttp($pdo, $gy, 'kullanici_update', [
        'id' => $userId,
        'sube_ids' => [1],
        'varsayilan_sube_id' => null,
    ]);
    i13bAssert($r['status'] === 200, 'I scope to [1]');
    $pdo->exec('UPDATE users SET varsayilan_sube_id = 2 WHERE id = ' . $userId);
    $poisoned = $pdo->query('SELECT varsayilan_sube_id FROM users WHERE id = ' . $userId)->fetchColumn();
    i13bAssert((int) $poisoned === 2, 'I poisoned DB default=2');
    $r = i13bHttp($pdo, null, 'login', ['username' => 'ba_scoped', 'password' => $pass]);
    i13bAssert($r['status'] === 200, 'I login 200');
    i13bAssert((int) ($r['payload']['data']['active_sube_id'] ?? 0) === 1, 'I defense active=1 not 2');

    // J) Schema-absent compat on separate DB
    $legacyDsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $legacyDb, $rootDsn);
    $legacyPdo = i13bPdo((string) $legacyDsn);
    i13bApplyFile($legacyPdo, '001_initial_schema.sql');
    $legacyPdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF'), (2, 'B', 'Sube B', 'AKTIF')");
    $legacyPdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'gy', " . $legacyPdo->quote($hash) . ", 'GY', 'GENEL_YONETICI', 'AKTIF')"
    );
    $legacyGy = ['id' => 1, 'username' => 'gy', 'ad_soyad' => 'GY', 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];

    $r = i13bHttp($legacyPdo, $legacyGy, 'kullanicilar_list');
    i13bAssert($r['status'] === 200, 'J list without 051 is 200');
    $legacyItems = $r['payload']['data']['items'] ?? [];
    i13bAssert(is_array($legacyItems) && count($legacyItems) >= 1, 'J list has items');
    foreach ($legacyItems as $item) {
        i13bAssertNullField(is_array($item) ? $item : [], 'varsayilan_sube_id', 'J list varsayilan null (schema absent)');
    }

    $r = i13bHttp($legacyPdo, null, 'login', ['username' => 'gy', 'password' => $pass]);
    i13bAssert($r['status'] === 200, 'J login works without 051');

    $r = i13bHttp($legacyPdo, $legacyGy, 'kullanici_create', [
        'username' => 'legacy_with_default',
        'password' => $pass,
        'ad_soyad' => 'Legacy Default',
        'rol' => 'BIRIM_AMIRI',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
        'varsayilan_sube_id' => 1,
    ]);
    i13bAssert($r['status'] === 409, 'J create non-null varsayilan 409');
    i13bAssert(($r['payload']['errors'][0]['code'] ?? '') === 'SCHEMA_NOT_READY', 'J SCHEMA_NOT_READY');

    $r = i13bHttp($legacyPdo, $legacyGy, 'kullanici_create', [
        'username' => 'legacy_null_default',
        'password' => $pass,
        'ad_soyad' => 'Legacy Null',
        'rol' => 'BIRIM_AMIRI',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
        'varsayilan_sube_id' => null,
    ]);
    i13bAssert($r['status'] === 200, 'J create null varsayilan succeeds');

    echo "verify-i13b-varsayilan-sube-persistence-mysql: OK\n";
} finally {
    try {
        $root->exec("DROP DATABASE IF EXISTS `$db`");
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $root->exec("DROP DATABASE IF EXISTS `$legacyDb`");
    } catch (Throwable $e) {
        // ignore
    }
}
