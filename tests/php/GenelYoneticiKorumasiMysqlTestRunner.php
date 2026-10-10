<?php

declare(strict_types=1);

/**
 * Yönetici hesap korumaları (GenelYoneticiKorumasi) — MariaDB senaryoları:
 * yetki yükseltme (SISTEM_YONETICISI), kendi rol/durum değişikliği, son aktif
 * Genel Yönetici (rol düşürme / pasif / erişim kaldırma) ve eşzamanlılık
 * (FOR UPDATE kilidi: ikinci istek bekler ve son yöneticiyi kaldıramaz).
 * php tests/php/GenelYoneticiKorumasiMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\Auth\GenelYoneticiKorumasi;

function gykAssert(bool $condition, string $name, array $r = []): void
{
    if (!$condition) {
        if ($r !== []) {
            fwrite(STDERR, json_encode($r, JSON_UNESCAPED_UNICODE) . PHP_EOL);
        }
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function gykPdo(string $dsn): PDO
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
function gykSplitSql(string $sql): array
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

function gykApplyFile(PDO $pdo, string $relative): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $relative);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $relative);
    }
    foreach (gykSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function gykSetStatic(string $class, string $property, $value): void
{
    $ref = new ReflectionClass($class);
    $prop = $ref->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue(null, $value);
}

function gykRequest(string $method, string $path, array $body = []): Request
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
 * Starts one controller action in a child process (JsonResponse exits) without waiting.
 *
 * @param array<string, mixed> $auth
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function gykStart(string $database, array $auth, string $action, array $extra = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'gyk_');
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
        'database' => $database,
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

    return ['process' => $process, 'pipes' => $pipes, 'status_file' => $statusFile];
}

function gykRunning(array $handle): bool
{
    $status = proc_get_status($handle['process']);

    return (bool) $status['running'];
}

/** @return array{status:int, payload:array<string,mixed>} */
function gykFinish(array $handle): array
{
    $stdout = (string) stream_get_contents($handle['pipes'][1]);
    $stderr = (string) stream_get_contents($handle['pipes'][2]);
    fclose($handle['pipes'][1]);
    fclose($handle['pipes'][2]);
    proc_close($handle['process']);
    $statusFile = (string) $handle['status_file'];
    $status = (int) (is_file($statusFile) ? trim((string) file_get_contents($statusFile)) : '0');
    @unlink($statusFile);
    $jsonStart = strpos($stdout, '{');
    $decoded = json_decode((string) ($jsonStart === false ? $stdout : substr($stdout, $jsonStart)), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('http child invalid json: ' . $stdout . ' / ' . $stderr);
    }

    return ['status' => $status, 'payload' => $decoded];
}

/** @return array{status:int, payload:array<string,mixed>} */
function gykHttp(string $database, array $auth, string $action, array $extra = []): array
{
    return gykFinish(gykStart($database, $auth, $action, $extra));
}

function gykCode(array $r): string
{
    return (string) ($r['payload']['errors'][0]['code'] ?? '');
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
    gykSetStatic(Connection::class, 'pdo', $pdo);
    gykSetStatic(AuthMiddleware::class, 'user', $cfg['auth']);
    $statusFile = (string) $cfg['status_file'];
    register_shutdown_function(static function () use ($statusFile) {
        $code = http_response_code();
        file_put_contents($statusFile, (string) (is_int($code) && $code >= 100 ? $code : 200));
    });
    $action = (string) ($cfg['action'] ?? '');
    $extra = is_array($cfg['extra'] ?? null) ? $cfg['extra'] : [];
    $id = (int) ($extra['id'] ?? 0);
    $body = is_array($extra['body'] ?? null) ? $extra['body'] : [];
    if ($action === 'update') {
        YonetimController::kullaniciGuncelle(gykRequest('PUT', '/yonetim/kullanicilar/' . $id, $body), $id);
    } elseif ($action === 'create') {
        YonetimController::kullaniciOlustur(gykRequest('POST', '/yonetim/kullanicilar', $body));
    } elseif ($action === 'revoke') {
        YonetimController::kullaniciErisimKaldir(gykRequest('DELETE', '/yonetim/kullanicilar/' . $id, $body), $id);
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

$root = gykPdo($rootDsn);
$db = 'gyk_' . bin2hex(random_bytes(4));
$root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

/** @return array<string, mixed> */
function gykAuth(int $id, string $username, string $rol): array
{
    return ['id' => $id, 'username' => $username, 'ad_soyad' => $username, 'rol' => $rol, 'sube_ids' => [1]];
}

try {
    $pdo = gykPdo((string) preg_replace('/dbname=[^;]+/', 'dbname=' . $db, $rootDsn));
    foreach ([
        '001_initial_schema.sql', '048_sgk_dual_control_actor_roles.sql', '051_users_varsayilan_sube_id.sql',
        '054_canonical_role_consolidation.sql', '056_users_personel_binding.sql', '064_personel_org_location_model.sql',
        '065_personel_org_structure.sql', '068_sgk_actor_identity_lifecycle_audit.sql',
        '069_personel_credential_onboarding.sql', '071_org_hierarchy_authorization.sql',
        '077_legacy_role_enum_shrink.sql', '079_sirket_sube_hiyerarsisi.sql', '080_organizasyon_audit_owners.sql',
        '081_ik_personeli_rolu.sql', '082_user_erisim_degisiklik_auditleri.sql',
    ] as $migration) {
        gykApplyFile($pdo, $migration);
    }
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");

    $reset = static function () use ($pdo): void {
        $pdo->exec('DELETE FROM user_subeler');
        $pdo->exec('DELETE FROM users WHERE id > 5');
        $pdo->exec(
            "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
               (1, 'gy.bir', 'x', 'Gy Bir', 'GENEL_YONETICI', 'AKTIF'),
               (2, 'gy.iki', 'x', 'Gy Iki', 'GENEL_YONETICI', 'AKTIF'),
               (3, 'sistem.yon', 'x', 'Sistem Yon', 'SISTEM_YONETICISI', 'AKTIF'),
               (4, 'muhasebe.bir', 'x', 'Muhasebe Bir', 'MUHASEBE', 'AKTIF'),
               (5, 'gy.pasif', 'x', 'Gy Pasif', 'GENEL_YONETICI', 'PASIF')
             ON DUPLICATE KEY UPDATE rol = VALUES(rol), durum = VALUES(durum), username = VALUES(username),
               ad_soyad = VALUES(ad_soyad), password_hash = 'x'"
        );
        $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (3, 1), (4, 1)');
    };
    $rolOf = static function (int $id) use ($pdo): string {
        $s = $pdo->prepare('SELECT CONCAT(rol, "/", durum) FROM users WHERE id = :id');
        $s->execute(['id' => $id]);

        return (string) $s->fetchColumn();
    };
    $aktifGy = static function () use ($pdo): int {
        return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE rol = 'GENEL_YONETICI' AND durum = 'AKTIF'")->fetchColumn();
    };

    $gy1 = gykAuth(1, 'gy.bir', 'GENEL_YONETICI');
    $gy2 = gykAuth(2, 'gy.iki', 'GENEL_YONETICI');
    $sy = gykAuth(3, 'sistem.yon', 'SISTEM_YONETICISI');
    $demote = ['rol' => 'MUHASEBE', 'sube_ids' => [1]];

    // --- Yetki yükseltme (SISTEM_YONETICISI) ---
    $reset();
    $r = gykHttp($db, $sy, 'create', ['body' => ['username' => 'yeni.gy', 'ad_soyad' => 'Yeni Gy', 'rol' => 'GENEL_YONETICI', 'password' => 'Guclu-Parola-2026!']]);
    gykAssert($r['status'] === 403 && gykCode($r) === 'ROLE_ESCALATION_FORBIDDEN', '1 SISTEM_YONETICISI GENEL_YONETICI hesabi olusturamaz (403)', $r);
    gykAssert((int) $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'yeni.gy'")->fetchColumn() === 0, '1b hesap yazilmadi');

    $r = gykHttp($db, $sy, 'update', ['id' => 4, 'body' => ['rol' => 'GENEL_YONETICI', 'sube_ids' => []]]);
    gykAssert($r['status'] === 403 && gykCode($r) === 'ROLE_ESCALATION_FORBIDDEN' && $rolOf(4) === 'MUHASEBE/AKTIF', '2 SISTEM_YONETICISI baskasini GENEL_YONETICI yapamaz', $r);

    $r = gykHttp($db, $sy, 'update', ['id' => 3, 'body' => ['rol' => 'GENEL_YONETICI', 'sube_ids' => []]]);
    gykAssert($r['status'] === 409 && gykCode($r) === 'SELF_ROLE_CHANGE_FORBIDDEN' && $rolOf(3) === 'SISTEM_YONETICISI/AKTIF', '3 SISTEM_YONETICISI kendini GENEL_YONETICI yapamaz', $r);

    $r = gykHttp($db, $sy, 'update', ['id' => 2, 'body' => $demote]);
    gykAssert($r['status'] === 403 && gykCode($r) === 'ROLE_ESCALATION_FORBIDDEN' && $rolOf(2) === 'GENEL_YONETICI/AKTIF', '4 SISTEM_YONETICISI GENEL_YONETICI rolunu geri alamaz', $r);

    $r = gykHttp($db, $sy, 'update', ['id' => 2, 'body' => ['durum' => 'PASIF']]);
    gykAssert($r['status'] === 403 && gykCode($r) === 'ROLE_ESCALATION_FORBIDDEN' && $rolOf(2) === 'GENEL_YONETICI/AKTIF', '5 SISTEM_YONETICISI GENEL_YONETICI hesabini pasife alamaz', $r);

    $r = gykHttp($db, $sy, 'revoke', ['id' => 2]);
    gykAssert($r['status'] === 403 && gykCode($r) === 'ROLE_ESCALATION_FORBIDDEN' && $rolOf(2) === 'GENEL_YONETICI/AKTIF', '6 SISTEM_YONETICISI GENEL_YONETICI erisimini kaldiramaz', $r);

    $r = gykHttp($db, $sy, 'update', ['id' => 4, 'body' => ['ad_soyad' => 'Muhasebe Bir Yeni']]);
    gykAssert($r['status'] === 200, '7 SISTEM_YONETICISI GY olmayan hesapta normal duzenleme yapabilir', $r);

    // --- Kendi rolü / durumu ---
    $r = gykHttp($db, $gy1, 'update', ['id' => 1, 'body' => $demote]);
    gykAssert($r['status'] === 409 && gykCode($r) === 'SELF_ROLE_CHANGE_FORBIDDEN' && $rolOf(1) === 'GENEL_YONETICI/AKTIF', '8 kullanici kendi rolunu dusuremez', $r);
    $r = gykHttp($db, $gy1, 'update', ['id' => 1, 'body' => ['durum' => 'PASIF']]);
    gykAssert($r['status'] === 409 && gykCode($r) === 'SELF_STATUS_CHANGE_FORBIDDEN' && $rolOf(1) === 'GENEL_YONETICI/AKTIF', '9 kullanici kendini pasife alamaz', $r);
    $r = gykHttp($db, $gy1, 'update', ['id' => 1, 'body' => ['ad_soyad' => 'Gy Bir Yeni']]);
    gykAssert($r['status'] === 200, '10 kullanici kendi rol/durum disi alanlarini duzenleyebilir', $r);

    // --- GY yetkisi: iki aktif GY varken ---
    $r = gykHttp($db, $gy1, 'create', ['body' => ['username' => 'ucuncu.gy', 'ad_soyad' => 'Ucuncu Gy', 'rol' => 'GENEL_YONETICI', 'password' => 'Guclu-Parola-2026!']]);
    gykAssert($r['status'] === 200 || $r['status'] === 201, '11 GENEL_YONETICI yeni GENEL_YONETICI olusturabilir', $r);
    $pdo->exec("DELETE FROM users WHERE username = 'ucuncu.gy'");
    $r = gykHttp($db, $gy1, 'update', ['id' => 2, 'body' => $demote]);
    gykAssert($r['status'] === 200 && $rolOf(2) === 'MUHASEBE/AKTIF' && $aktifGy() === 1, '12 iki aktif GY varken digerinin rolu dusurulebilir', $r);

    // --- Son aktif GY (eski oturum / eşzamanlı istek ile ulaşılır) ---
    $r = gykHttp($db, $gy2, 'update', ['id' => 1, 'body' => $demote]);
    gykAssert($r['status'] === 409 && gykCode($r) === 'LAST_ADMIN_PROTECTED' && $rolOf(1) === 'GENEL_YONETICI/AKTIF', '13 son aktif GY rolden dusurulemez', $r);
    $r = gykHttp($db, $gy2, 'update', ['id' => 1, 'body' => ['durum' => 'PASIF']]);
    gykAssert($r['status'] === 409 && gykCode($r) === 'LAST_ADMIN_PROTECTED' && $rolOf(1) === 'GENEL_YONETICI/AKTIF', '14 son aktif GY pasife alinamaz', $r);
    $r = gykHttp($db, $gy2, 'revoke', ['id' => 1]);
    gykAssert($r['status'] === 409 && gykCode($r) === 'LAST_ADMIN_PROTECTED' && $rolOf(1) === 'GENEL_YONETICI/AKTIF', '15 son aktif GY erisimi kaldirilamaz', $r);
    gykAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM user_erisim_kaldirma_auditleri')->fetchColumn() === 0,
        '15b reddedilen erisim kaldirma audit satiri yazmadi'
    );
    gykAssert($aktifGy() === 1 && $rolOf(5) === 'GENEL_YONETICI/PASIF', '16 pasif GY son-aktif sayimina girmez');

    // --- Eşzamanlılık (deterministik): ilk transaction kilidi tutarken ikinci istek bekler ---
    $reset();
    $pdo->beginTransaction();
    GenelYoneticiKorumasi::assertNotLastActiveAdminLocked($pdo, 1, 'MUHASEBE', 'AKTIF');
    $pdo->exec("UPDATE users SET rol = 'MUHASEBE' WHERE id = 1");
    $child = gykStart($db, $gy1, 'update', ['id' => 2, 'body' => $demote]);
    usleep(1500000);
    gykAssert(gykRunning($child), '17 ikinci dusurme istegi aktif GY kilidini bekliyor (FOR UPDATE)');
    $pdo->commit();
    $r = gykFinish($child);
    gykAssert($r['status'] === 409 && gykCode($r) === 'LAST_ADMIN_PROTECTED', '18 kilit birakilinca ikinci istek guncel sayimi gorur ve reddedilir', $r);
    gykAssert($aktifGy() === 1 && $rolOf(2) === 'GENEL_YONETICI/AKTIF', '19 en az bir aktif GY kaldi');

    // --- Eşzamanlılık (yarış): iki GY ayni anda birbirini dusurur → tam biri basarili ---
    $reset();
    $a = gykStart($db, $gy1, 'update', ['id' => 2, 'body' => $demote]);
    $b = gykStart($db, $gy2, 'update', ['id' => 1, 'body' => $demote]);
    $ra = gykFinish($a);
    $rb = gykFinish($b);
    $statuses = [$ra['status'], $rb['status']];
    sort($statuses);
    gykAssert($statuses === [200, 409], '20 eszamanli karsilikli dusurmede tam bir istek basarili, digeri LAST_ADMIN_PROTECTED');
    gykAssert($aktifGy() === 1, '21 yaris sonunda tam 1 aktif GY kaldi');

    echo "verify-genel-yonetici-korumasi-mysql: OK\n";
} finally {
    try {
        $root->exec("DROP DATABASE IF EXISTS `$db`");
    } catch (Throwable $e) {
        // ignore
    }
}
