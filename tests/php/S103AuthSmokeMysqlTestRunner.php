<?php

declare(strict_types=1);

/**
 * S103: migration 041 + AUTH_SMOKE_READONLY + personeller read authz MySQL acceptance.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\AuthSmokeController;
use Medisa\Api\Auth\LoginController;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Controllers\PersonellerController;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function s103Assert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s103Pdo(string $dsn): PDO
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

function s103SplitSql(string $sql): array
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

function s103ApplyFile(PDO $pdo, string $relative): void
{
    $path = __DIR__ . '/../../api/migrations/' . $relative;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $relative);
    }
    foreach (s103SplitSql($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function s103SetPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function s103ResetAuth($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function s103Request(string $method, string $path, array $body = []): Request
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
function s103Http(PDO $pdo, $auth, string $action, array $extra = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 's103_');
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
        'jwt_secret' => 's103-test-secret',
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--http-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = [];
    foreach ($_ENV as $k => $v) {
        if (is_string($k) && (is_string($v) || is_numeric($v))) {
            $env[$k] = (string) $v;
        }
    }
    // Windows proc_open: incomplete env breaks TCP/MariaDB client libs.
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
    $config['db_user'] = 's103';
    $config['db_password'] = 's103';
    $config['jwt_secret'] = str_repeat('s', 32);
    $config['jwt_ttl_seconds'] = 3600;

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $cfg['database'], (string) $cfg['dsn']);
    $pdo = new PDO(
        (string) $dsn,
        (string) $cfg['user'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    s103SetPdo($pdo);
    s103ResetAuth(array_key_exists('auth', $cfg) ? $cfg['auth'] : null);

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

    if ($action === 'personeller_list') {
        $_GET = ['page' => '1', 'limit' => '5'];
        PersonellerController::list(s103Request('GET', '/personeller'));
    } elseif ($action === 'personeller_detail') {
        $_GET = [];
        PersonellerController::detail(
            s103Request('GET', '/personeller/' . (int) ($extra['id'] ?? 0)),
            (int) ($extra['id'] ?? 0)
        );
    } elseif ($action === 'smoke_read') {
        $_GET = [];
        AuthSmokeController::smokeRead(s103Request('GET', '/auth/smoke-read'));
    } elseif ($action === 'login') {
        $_GET = [];
        s103ResetAuth(null);
        LoginController::login(s103Request('POST', '/auth/login', [
            'username' => (string) ($extra['username'] ?? ''),
            'password' => (string) ($extra['password'] ?? ''),
        ]));
    } elseif ($action === 'user_create') {
        $_GET = [];
        YonetimController::kullaniciOlustur(s103Request('POST', '/yonetim/kullanicilar', $extra));
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

$root = s103Pdo($rootDsn);
$db = 's103_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $db, $rootDsn);
    $pdo = s103Pdo((string) $dsn);

    s103ApplyFile($pdo, '001_initial_schema.sql');
    $usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $subelerBefore = (int) $pdo->query('SELECT COUNT(*) FROM user_subeler')->fetchColumn();

    s103ApplyFile($pdo, '041_auth_smoke_readonly_role.sql');
    s103Assert(true, '041 ilk apply');
    s103ApplyFile($pdo, '041_auth_smoke_readonly_role.sql');
    s103Assert(true, '041 ikinci apply idempotent');

    s103ApplyFile($pdo, '051_users_varsayilan_sube_id.sql');
    s103Assert(true, '051 ilk apply');
    s103ApplyFile($pdo, '051_users_varsayilan_sube_id.sql');
    s103Assert(true, '051 ikinci apply idempotent');
    s103Assert(
        (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'varsayilan_sube_id'"
        )->fetchColumn() === 1,
        '051 varsayilan_sube_id column'
    );

    $colType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rol'"
    )->fetchColumn();
    s103Assert(strpos($colType, 'PATRON') !== false, 'enum contains PATRON');
    s103Assert(strpos($colType, 'AUTH_SMOKE_READONLY') !== false, 'enum contains AUTH_SMOKE_READONLY');
    s103Assert(strpos($colType, 'GENEL_YONETICI') !== false, 'enum keeps GENEL_YONETICI');
    s103Assert((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $usersBefore, 'user row delta 0');
    s103Assert((int) $pdo->query('SELECT COUNT(*) FROM user_subeler')->fetchColumn() === $subelerBefore, 'user_subeler row delta 0');

    // Permission matrix (in-process)
    s103Assert(RolePermissions::has(['rol' => 'AUTH_SMOKE_READONLY'], 'ops.auth_smoke.read'), 'smoke permission true');
    s103Assert(!RolePermissions::has(['rol' => 'AUTH_SMOKE_READONLY'], 'personeller.view'), 'smoke no personeller.view');
    s103Assert(!RolePermissions::has(['rol' => 'AUTH_SMOKE_READONLY'], 'personeller.view.sube'), 'smoke no personeller.view.sube');
    s103Assert(!RolePermissions::has(['rol' => 'AUTH_SMOKE_READONLY'], 'personeller.detail.view'), 'smoke no detail.view');
    s103Assert(!RolePermissions::has(['rol' => 'AUTH_SMOKE_READONLY'], 'personeller.create'), 'smoke no create');
    s103Assert(RolePermissions::has(['rol' => 'PATRON'], 'personeller.view'), 'legacy PATRON aliases to GENEL_YONETICI personeller.view');
    s103Assert(RolePermissions::normalizeRole('PATRON') === 'GENEL_YONETICI', 'PATRON normalizes to GENEL_YONETICI');
    s103Assert(RolePermissions::normalizeRole('PERSONEL') === 'PERSONEL', 'PERSONEL canonical');
    s103Assert(!RolePermissions::has(['rol' => 'PERSONEL'], 'personeller.view'), 'PERSONEL no personeller.view');

    $phpMatrix = (new ReflectionClass(RolePermissions::class))->getStaticPropertyValue('matrix');
    s103Assert(isset($phpMatrix['AUTH_SMOKE_READONLY']) && count($phpMatrix['AUTH_SMOKE_READONLY']) === 1, 'smoke permission count = 1');

    // Seed users/subeler/personeller
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF'), (2, 'B', 'Sube B', 'AKTIF')");
    $hash = password_hash('SmokeTestPass-24chars!!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
      (1, 'gy', '$hash', 'GY', 'GENEL_YONETICI', 'AKTIF'),
      (2, 'patron', '$hash', 'Patron', 'PATRON', 'AKTIF'),
      (3, 'pm_smoke_ro_test', '$hash', 'Otomatik Smoke Test', 'AUTH_SMOKE_READONLY', 'AKTIF'),
      (4, 'ba', '$hash', 'BA', 'BIRIM_AMIRI', 'AKTIF'),
      (5, 'pm_smoke_ro_bad0', '$hash', 'Bad0', 'AUTH_SMOKE_READONLY', 'AKTIF'),
      (6, 'pm_smoke_ro_bad2', '$hash', 'Bad2', 'AUTH_SMOKE_READONLY', 'AKTIF')
    ");
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (3, 1), (4, 1), (6, 1), (6, 2)');
    // patron: empty sube_ids (unrestricted risk before authz fix)
    $pdo->exec("INSERT INTO personeller (
        id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
        sicil_no, ise_giris_tarihi, sube_id, aktif_durum
      ) VALUES
      (10, '11111111110', 'A', 'Bir', '1990-01-01', '555', 'X', '556', 'S10', '2020-01-01', 1, 'AKTIF'),
      (11, '22222222220', 'B', 'Iki', '1991-01-01', '555', 'X', '556', 'S11', '2020-01-01', 2, 'AKTIF')
    ");

    $gy = ['id' => 1, 'username' => 'gy', 'ad_soyad' => 'GY', 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $patron = ['id' => 2, 'username' => 'patron', 'ad_soyad' => 'Patron', 'rol' => 'PATRON', 'sube_ids' => []];
    $smoke = ['id' => 3, 'username' => 'pm_smoke_ro_test', 'ad_soyad' => 'Smoke', 'rol' => 'AUTH_SMOKE_READONLY', 'sube_ids' => [1]];
    $ba = ['id' => 4, 'username' => 'ba', 'ad_soyad' => 'BA', 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
    $smoke0 = ['id' => 5, 'username' => 'pm_smoke_ro_bad0', 'ad_soyad' => 'Bad0', 'rol' => 'AUTH_SMOKE_READONLY', 'sube_ids' => []];
    $smoke2 = ['id' => 6, 'username' => 'pm_smoke_ro_bad2', 'ad_soyad' => 'Bad2', 'rol' => 'AUTH_SMOKE_READONLY', 'sube_ids' => [1, 2]];
    $personel = ['id' => 7, 'username' => 'personel', 'ad_soyad' => 'Personel', 'rol' => 'PERSONEL', 'sube_ids' => []];

    $r = s103Http($pdo, $patron, 'personeller_list');
    s103Assert($r['status'] === 200, 'legacy PATRON aliases to GENEL_YONETICI list 200');
    $r = s103Http($pdo, $personel, 'personeller_list');
    s103Assert($r['status'] === 403, 'PERSONEL list 403');
    $r = s103Http($pdo, $smoke, 'personeller_list');
    s103Assert($r['status'] === 403, 'AUTH_SMOKE_READONLY list 403');
    $r = s103Http($pdo, $gy, 'personeller_list');
    s103Assert($r['status'] === 200, 'GENEL_YONETICI list 200');
    $r = s103Http($pdo, $ba, 'personeller_list');
    s103Assert($r['status'] === 200, 'BIRIM_AMIRI list 200');
    $items = $r['payload']['data']['items'] ?? [];
    s103Assert(is_array($items) && count($items) === 1 && (int) $items[0]['id'] === 10, 'BIRIM_AMIRI yalnız kendi şubesi');

    $r = s103Http($pdo, $personel, 'personeller_detail', ['id' => 10]);
    s103Assert($r['status'] === 403, 'PERSONEL detail 403');
    $r = s103Http($pdo, $smoke, 'personeller_detail', ['id' => 10]);
    s103Assert($r['status'] === 403, 'AUTH_SMOKE_READONLY detail 403');
    $r = s103Http($pdo, $gy, 'personeller_detail', ['id' => 10]);
    s103Assert($r['status'] === 200, 'GENEL_YONETICI detail 200');
    $r = s103Http($pdo, $ba, 'personeller_detail', ['id' => 11]);
    s103Assert($r['status'] === 403, 'BIRIM_AMIRI other sube detail 403');

    $r = s103Http($pdo, $smoke, 'smoke_read');
    s103Assert($r['status'] === 200, 'smoke-read 200');
    $data = $r['payload']['data'] ?? [];
    s103Assert(($data['authenticated'] ?? null) === true, 'smoke authenticated');
    s103Assert(($data['read_only'] ?? null) === true, 'smoke read_only');
    s103Assert(($data['role'] ?? null) === 'AUTH_SMOKE_READONLY', 'smoke role');
    s103Assert(($data['scope_type'] ?? null) === 'SINGLE_BRANCH', 'smoke scope_type');
    s103Assert(($data['scope_count'] ?? null) === 1, 'smoke scope_count');
    $encoded = json_encode($data);
    s103Assert(strpos((string) $encoded, 'pm_smoke') === false, 'smoke response no username');
    s103Assert(strpos((string) $encoded, 'Sube') === false, 'smoke response no sube name');
    s103Assert(!isset($data['token']), 'smoke response no token');

    $r = s103Http($pdo, $gy, 'smoke_read');
    s103Assert($r['status'] === 403, 'GY smoke-read 403');
    $r = s103Http($pdo, $ba, 'smoke_read');
    s103Assert($r['status'] === 403, 'BA smoke-read 403');
    $r = s103Http($pdo, $patron, 'smoke_read');
    s103Assert($r['status'] === 403, 'PATRON smoke-read 403');
    $r = s103Http($pdo, $smoke0, 'smoke_read');
    s103Assert($r['status'] === 403, 'smoke 0 sube 403');
    $code = $r['payload']['errors'][0]['code'] ?? '';
    s103Assert($code === 'AUTH_SMOKE_SCOPE_INVALID', 'smoke 0 sube code');
    $r = s103Http($pdo, $smoke2, 'smoke_read');
    s103Assert($r['status'] === 403, 'smoke 2 sube 403');

    $r = s103Http($pdo, null, 'login', ['username' => 'pm_smoke_ro_test', 'password' => 'SmokeTestPass-24chars!!']);
    s103Assert($r['status'] === 200, 'smoke login 200');
    s103Assert(isset($r['payload']['data']['token']), 'smoke login token');
    $r = s103Http($pdo, null, 'login', ['username' => 'pm_smoke_ro_bad0', 'password' => 'SmokeTestPass-24chars!!']);
    s103Assert($r['status'] === 403, 'smoke login 0 sube 403');
    $r = s103Http($pdo, null, 'login', ['username' => 'pm_smoke_ro_bad2', 'password' => 'SmokeTestPass-24chars!!']);
    s103Assert($r['status'] === 403, 'smoke login 2 sube 403');

    $r = s103Http($pdo, $gy, 'user_create', [
        'username' => 'bad_prefix',
        'password' => 'AnotherStrongPass-24!!',
        'ad_soyad' => 'X',
        'rol' => 'AUTH_SMOKE_READONLY',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
        'varsayilan_sube_id' => 1,
    ]);
    s103Assert($r['status'] === 400, 'bad username prefix 400');
    s103Assert(($r['payload']['errors'][0]['code'] ?? '') === 'AUTH_SMOKE_USERNAME_INVALID', 'username invalid code');

    $r = s103Http($pdo, $gy, 'user_create', [
        'username' => 'pm_smoke_ro_ok2',
        'password' => 'AnotherStrongPass-24!!',
        'ad_soyad' => 'Otomatik Smoke Test',
        'rol' => 'AUTH_SMOKE_READONLY',
        'durum' => 'AKTIF',
        'sube_ids' => [1, 2],
        'varsayilan_sube_id' => 1,
    ]);
    s103Assert($r['status'] === 400, 'two sube create 400');
    s103Assert(($r['payload']['errors'][0]['code'] ?? '') === 'AUTH_SMOKE_SCOPE_INVALID', 'scope invalid code');

    $r = s103Http($pdo, $gy, 'user_create', [
        'username' => 'pm_smoke_ro_ok1',
        'password' => 'AnotherStrongPass-24!!',
        'ad_soyad' => 'Otomatik Smoke Test',
        'rol' => 'AUTH_SMOKE_READONLY',
        'durum' => 'AKTIF',
        'sube_ids' => [2],
        'varsayilan_sube_id' => 2,
    ]);
    s103Assert($r['status'] === 200, 'valid smoke user create 200');

    $r = s103Http($pdo, $gy, 'user_create', [
        'username' => 'normal_ba_new',
        'password' => 'AnotherStrongPass-24!!',
        'ad_soyad' => 'Normal BA',
        'rol' => 'BIRIM_AMIRI',
        'durum' => 'AKTIF',
        'sube_ids' => [1],
        'varsayilan_sube_id' => 1,
    ]);
    s103Assert($r['status'] === 200, 'normal BIRIM_AMIRI create regression 200');

    echo "verify-s103-auth-smoke-mysql: OK\n";
} finally {
    try {
        $root->exec("DROP DATABASE IF EXISTS `$db`");
    } catch (Throwable $e) {
        // ignore
    }
}
