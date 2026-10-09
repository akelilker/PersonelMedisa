<?php

declare(strict_types=1);

/**
 * Kullanici Yonetimi list: accounts bound to an operationally hidden personel
 * (AKTIF TEST_FIXTURE classification, e.g. tombstoned 'DESTROYED PERSONEL') are
 * excluded from the default list; the tombstone placeholder is never surfaced as
 * personel_ad_soyad; schema-absent environments stay unfiltered (MariaDB).
 * php tests/php/KullaniciYonetimiGizliTestPersonelMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function kygAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function kygPdo(string $dsn): PDO
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
function kygSplitSql(string $sql): array
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

function kygApplyFile(PDO $pdo, string $relative): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $relative);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $relative);
    }
    foreach (kygSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function kygSetStatic(string $class, string $property, $value): void
{
    $ref = new ReflectionClass($class);
    $prop = $ref->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue(null, $value);
}

function kygRequest(string $method, string $path, array $body = []): Request
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
 * Runs one controller action in a child process (JsonResponse exits).
 *
 * @param array<string, mixed> $auth
 * @param array<string, mixed> $extra
 * @return array{status:int, payload:array<string,mixed>}
 */
function kygHttp(PDO $pdo, array $auth, string $action, array $extra = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'kyg_');
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
        'auth' => $auth,
        'action' => $action,
        'extra' => $extra,
        'status_file' => $statusFile,
    ], JSON_UNESCAPED_UNICODE);

    $env = [];
    foreach (['Path', 'PATH', 'SYSTEMROOT', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $key) {
        $val = getenv($key);
        if (is_string($val) && $val !== '') {
            $env[$key] = $val;
        }
    }
    $env['MEDISA_TEST_MYSQL_DSN'] = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $env['MEDISA_TEST_MYSQL_USER'] = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $env['MEDISA_TEST_MYSQL_PASSWORD'] = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';

    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--http-child']);
    $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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

    $status = (int) (is_file($statusFile) ? trim((string) file_get_contents($statusFile)) : '0');
    @unlink($statusFile);

    $jsonStart = strpos($stdout, '{');
    $decoded = json_decode((string) ($jsonStart === false ? $stdout : substr($stdout, $jsonStart)), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('http child invalid json: ' . $stdout . ' / ' . $stderr);
    }

    return ['status' => $status, 'payload' => $decoded];
}

/** @param array<int, array<string, mixed>> $items @return array<string, mixed>|null */
function kygFind(array $items, string $username)
{
    foreach ($items as $item) {
        if (($item['username'] ?? '') === $username) {
            return $item;
        }
    }

    return null;
}

/** Mirrors src/lib/yonetim/personel-first-login-status.ts countPersonelFirstLoginStatus. */
function kygFirstLoginCounts(array $items): array
{
    $pending = 0;
    $completed = 0;
    foreach ($items as $item) {
        if (!isset($item['personel_id']) || (int) $item['personel_id'] <= 0) {
            continue;
        }
        if (($item['must_change_password'] ?? null) === true) {
            $pending++;
        } elseif (($item['must_change_password'] ?? null) === false) {
            $completed++;
        }
    }

    return ['pending' => $pending, 'completed' => $completed];
}

if (($argv[1] ?? '') === '--http-child') {
    $cfg = json_decode((string) stream_get_contents(STDIN), true);
    if (!is_array($cfg)) {
        fwrite(STDERR, "bad child config\n");
        exit(2);
    }

    global $config;
    $config['db_name'] = (string) $cfg['database'];
    $config['jwt_secret'] = str_repeat('s', 32);
    $config['jwt_ttl_seconds'] = 3600;

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $cfg['database'], (string) $cfg['dsn']);
    $pdo = new PDO((string) $dsn, (string) $cfg['user'], (string) $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
    kygSetStatic(Connection::class, 'pdo', $pdo);
    kygSetStatic(AuthMiddleware::class, 'user', $cfg['auth']);

    $statusFile = (string) $cfg['status_file'];
    register_shutdown_function(static function () use ($statusFile) {
        $code = http_response_code();
        file_put_contents($statusFile, (string) (is_int($code) && $code >= 100 ? $code : 200));
    });

    $action = (string) ($cfg['action'] ?? '');
    $extra = is_array($cfg['extra'] ?? null) ? $cfg['extra'] : [];
    $_GET = is_array($extra['query'] ?? null) ? $extra['query'] : [];

    if ($action === 'kullanicilar_list') {
        YonetimController::kullanicilar(kygRequest('GET', '/yonetim/kullanicilar'));
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

$root = kygPdo($rootDsn);
$db = 'kyg_' . bin2hex(random_bytes(4));
$legacyDb = 'kyg_legacy_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$root->exec("CREATE DATABASE `$legacyDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = kygPdo((string) preg_replace('/dbname=[^;]+/', 'dbname=' . $db, $rootDsn));
    foreach ([
        '001_initial_schema.sql',
        '051_users_varsayilan_sube_id.sql',
        '053_retention_legal_hold_arsiv.sql',
        '056_users_personel_binding.sql',
        '069_personel_credential_onboarding.sql',
        '073_test_fixture_personel_archive.sql',
    ] as $migration) {
        kygApplyFile($pdo, $migration);
    }

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES (1, 'Tip', 'AKTIF')");

    $insertPersonel = $pdo->prepare(
        "INSERT INTO personeller
            (tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
             sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id, aktif_durum)
         VALUES
            (:tc, :ad, :soyad, '1990-01-01', '05550000000', 'X', '05550000001',
             :sicil, '2020-01-01', 1, 1, 1, 1, :durum)"
    );
    $personel = static function (string $tc, string $ad, string $soyad, string $sicil, string $durum) use ($pdo, $insertPersonel): int {
        $insertPersonel->execute(['tc' => $tc, 'ad' => $ad, 'soyad' => $soyad, 'sicil' => $sicil, 'durum' => $durum]);

        return (int) $pdo->lastInsertId();
    };
    $pReal = $personel('10000000001', 'Gercek', 'Calisan', 'S-1', 'AKTIF');
    $pRealDone = $personel('10000000002', 'Tamam', 'Calisan', 'S-2', 'AKTIF');
    $pFixtureTomb = $personel('10000000003', 'DESTROYED', 'PERSONEL', 'S-3', 'PASIF');
    $pFixtureLive = $personel('10000000004', 'Demo', 'Fixture', 'S-4', 'AKTIF');
    $pRetentionTomb = $personel('10000000005', 'DESTROYED', 'PERSONEL', 'S-5', 'PASIF');
    $pFixtureIptal = $personel('10000000006', 'Iptal', 'Sinif', 'S-6', 'AKTIF');

    $classify = $pdo->prepare(
        "INSERT INTO personel_test_fixture_siniflandirmalari
            (personel_id, sinif, evidence_kodu, state, classified_by, classified_at)
         VALUES (:pid, 'TEST_FIXTURE', 'BORDRO_KAPSAM_DEMO_TEST_VERISI', :state, NULL, NOW(3))"
    );
    $classify->execute(['pid' => $pFixtureTomb, 'state' => 'AKTIF']);
    $classify->execute(['pid' => $pFixtureLive, 'state' => 'AKTIF']);
    $classify->execute(['pid' => $pFixtureIptal, 'state' => 'IPTAL']);

    $insertUser = $pdo->prepare(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, personel_id, must_change_password)
         VALUES (:id, :u, 'x', :ad, :rol, 'AKTIF', :pid, :mcp)"
    );
    foreach ([
        [1, 'gy', 'GY', 'GENEL_YONETICI', null, 0],
        [2, 'gercek.calisan', 'Gercek Calisan', 'MUHASEBE', $pReal, 1],
        [3, 'tamam.calisan', 'Tamam Calisan', 'MUHASEBE', $pRealDone, 0],
        [4, 'ayse.yilmaz', 'Ayse Yilmaz', 'MUHASEBE', $pFixtureTomb, 1],
        [5, 'demo.fixture', 'Demo Fixture', 'MUHASEBE', $pFixtureLive, 0],
        [6, 'imha.edilmis', 'Imha Edilmis', 'MUHASEBE', $pRetentionTomb, 1],
        [7, 'iptal.sinif', 'Iptal Sinif', 'MUHASEBE', $pFixtureIptal, 1],
        [8, 'pm_smoke_ro_e2e', 'Diger Smoke', 'MUHASEBE', null, 0],
        [9, 'pm_smoke_ro_production', 'Otomatik Smoke TEST', 'MUHASEBE', null, 0],
    ] as [$id, $username, $adSoyad, $rol, $pid, $mcp]) {
        $insertUser->execute(['id' => $id, 'u' => $username, 'ad' => $adSoyad, 'rol' => $rol, 'pid' => $pid, 'mcp' => $mcp]);
    }

    $gy = ['id' => 1, 'username' => 'gy', 'ad_soyad' => 'GY', 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];

    $all = kygHttp($pdo, $gy, 'kullanicilar_list', ['query' => ['include_hidden' => '1']]);
    kygAssert($all['status'] === 200, '1 include_hidden=1 diagnostics list 200');
    $allItems = $all['payload']['data']['items'] ?? [];
    kygAssert(count($allItems) === 8, '2 include_hidden=1 returns every account except pm_smoke_ro_production (8)');

    $r = kygHttp($pdo, $gy, 'kullanicilar_list');
    kygAssert($r['status'] === 200, '3 default list 200');
    $items = $r['payload']['data']['items'] ?? [];
    $usernames = array_map(static function ($item) {
        return (string) ($item['username'] ?? '');
    }, $items);
    kygAssert(
        $usernames === ['gy', 'gercek.calisan', 'tamam.calisan', 'imha.edilmis', 'iptal.sinif', 'pm_smoke_ro_e2e'],
        '4 default list drops exactly the 2 hidden-fixture-bound accounts, order id ASC kept'
    );
    $hiddenSmoke = 'pm_smoke_ro_production';
    $allUsernames = array_map(static function ($item) {
        return (string) ($item['username'] ?? '');
    }, $allItems);
    kygAssert(
        !in_array($hiddenSmoke, $usernames, true) && !in_array($hiddenSmoke, $allUsernames, true),
        '4b pm_smoke_ro_production absent from default and include_hidden lists'
    );
    kygAssert(
        in_array('pm_smoke_ro_e2e', $usernames, true) && in_array('pm_smoke_ro_e2e', $allUsernames, true),
        '4c other pm_smoke_ro account stays listed'
    );
    kygAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'pm_smoke_ro_production'")->fetchColumn() === 1,
        '4d pm_smoke_ro_production row remains in users'
    );
    kygAssert(kygFind($items, 'ayse.yilmaz') === null, '5 tombstoned TEST_FIXTURE-bound account hidden');
    kygAssert(kygFind($items, 'demo.fixture') === null, '6 live TEST_FIXTURE-bound account hidden');
    kygAssert(kygFind($items, 'iptal.sinif') !== null, '7 IPTAL classification does not hide');
    kygAssert(kygFind($items, 'gy') !== null, '8 account without personel unaffected');

    $before = kygFirstLoginCounts($allItems);
    $after = kygFirstLoginCounts($items);
    kygAssert($before === ['pending' => 4, 'completed' => 2], '9 counters before filter pending=4 completed=2');
    kygAssert($after === ['pending' => 3, 'completed' => 1], '10 counters drop by exactly the hidden accounts (3/1)');

    $real = kygFind($items, 'gercek.calisan');
    kygAssert(($real['personel_ad_soyad'] ?? null) === 'Gercek Calisan', '11 real personel name still surfaced');
    $retention = kygFind($items, 'imha.edilmis');
    kygAssert(
        is_array($retention) && array_key_exists('personel_ad_soyad', $retention) && $retention['personel_ad_soyad'] === null,
        '12 non-fixture tombstone placeholder not surfaced (personel_ad_soyad null)'
    );
    kygAssert((int) ($retention['personel_id'] ?? 0) === $pRetentionTomb, '13 personel link itself unchanged in payload');
    $noPlaceholder = true;
    foreach ($allItems as $item) {
        if (stripos((string) ($item['personel_ad_soyad'] ?? ''), 'DESTROYED') !== false) {
            $noPlaceholder = false;
        }
    }
    kygAssert($noPlaceholder, '14 DESTROYED PERSONEL never surfaced, even with include_hidden=1');

    $legacy = kygPdo((string) preg_replace('/dbname=[^;]+/', 'dbname=' . $legacyDb, $rootDsn));
    kygApplyFile($legacy, '001_initial_schema.sql');
    $legacy->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum)
         VALUES (1, 'gy', 'x', 'GY', 'GENEL_YONETICI', 'AKTIF'), (2, 'eski', 'x', 'Eski', 'MUHASEBE', 'AKTIF')"
    );
    $r = kygHttp($legacy, $gy, 'kullanicilar_list');
    kygAssert($r['status'] === 200, '15 schema-absent (no personel_id/073) list 200');
    kygAssert(count($r['payload']['data']['items'] ?? []) === 2, '16 schema-absent list unfiltered');

    echo "verify-kullanici-yonetimi-gizli-test-personel-mysql: OK\n";
} finally {
    foreach ([$db, $legacyDb] as $name) {
        try {
            $root->exec("DROP DATABASE IF EXISTS `$name`");
        } catch (Throwable $e) {
            // ignore
        }
    }
}
