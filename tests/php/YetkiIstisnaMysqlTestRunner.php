<?php

declare(strict_types=1);

/**
 * Dinamik yetki P2 — migration 100 + okuma uçları, gerçek tek kullanımlık MariaDB.
 *
 *  - 100 tabloları/tetikleyicileri: istisna silinemez, yalnız bir kez iptal edilir;
 *    audit satırı güncellenemez/silinemez.
 *  - AuthMiddleware gerçek JWT ile kullanıcıyı yükler; istisnalar tek sorguyla gelir,
 *    süresi dolmuş/iptal edilmiş satırlar yüklenmez.
 *  - Boş tablolarla /auth/login ve /auth/yetkiler etkin izinleri rol matrisiyle aynı.
 *  - DENY/ALLOW gerçek istekte etkili (GET /auth/yetkiler, korunan uç 403).
 *  - Okuma uçları yalnız GENEL_YONETICI (kullanici_yetkileri.view / .audit.view).
 *  - 100 uygulanmamış şemada okuma boş döner (geri uyumluluk).
 *
 * Canlıya dokunmaz. php tests/php/YetkiIstisnaMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\EffectivePermissionResolver;
use Medisa\Api\Auth\Jwt;
use Medisa\Api\Auth\LoginController;
use Medisa\Api\Auth\RolePermissions;
use Medisa\Api\Controllers\KullaniciYetkiController;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Database\UserYetkiIstisnaSchema;
use Medisa\Api\Http\Request;

function yiAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function yiPdo(string $dsn): PDO
{
    return new PDO($dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: 'root', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

function yiConfigure(string $database): void
{
    global $config;
    $config['db_host'] = '127.0.0.1';
    $config['db_name'] = $database;
    $config['db_user'] = 'test-yalniz-yapilandirma';     // bağlantı test PDO'su ile enjekte edilir
    $config['db_password'] = 'test-yalniz-yapilandirma';
    $config['jwt_secret'] = str_repeat('s', 32);
    $config['jwt_ttl_seconds'] = 3600;
}

/** @param array<string, mixed> $body */
function yiRequest(string $method, string $path, array $body = [], array $headers = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => $headers,
        'jsonBody' => $body,
        'rawBody' => $body === [] ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE),
        'rawBodyLoaded' => true,
        'jsonBodyParsed' => true,
    ] as $name => $value) {
        if ($ref->hasProperty($name)) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue($request, $value);
        }
    }

    return $request;
}

/**
 * Tek istek = tek alt süreç (gerçek AuthMiddleware yüklemesi, statik önbellek sızmaz).
 *
 * @param array<string, mixed> $extra
 * @return array{status:int, payload:array<string,mixed>}
 */
function yiHttp(string $database, string $action, array $extra = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'yi_');
    $payload = json_encode([
        'dsn' => getenv('MEDISA_TEST_MYSQL_DSN'),
        'user' => getenv('MEDISA_TEST_MYSQL_USER'),
        'password' => getenv('MEDISA_TEST_MYSQL_PASSWORD'),
        'database' => $database,
        'action' => $action,
        'extra' => $extra,
        'status_file' => $statusFile,
    ], JSON_UNESCAPED_UNICODE);
    $env = [];
    foreach (['PATH', 'Path', 'SYSTEMROOT', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'MEDISA_TEST_MYSQL_DSN', 'MEDISA_TEST_MYSQL_USER', 'MEDISA_TEST_MYSQL_PASSWORD'] as $key) {
        $value = getenv($key);
        if (is_string($value)) {
            $env[$key] = $value;
        }
    }
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs = ['-d', 'extension_dir=' . $extensionDir];
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=pdo_mysql';
    }
    $process = proc_open(array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--http-child']), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
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
    $status = (int) trim((string) @file_get_contents((string) $statusFile));
    @unlink((string) $statusFile);
    $start = strpos($stdout, '{');
    $decoded = json_decode($start === false ? $stdout : substr($stdout, $start), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('http child invalid json: ' . $stdout . ' / ' . $stderr);
    }

    return ['status' => $status, 'payload' => $decoded];
}

if (($argv[1] ?? '') === '--http-child') {
    $cfg = json_decode((string) stream_get_contents(STDIN), true);
    yiConfigure((string) $cfg['database']);
    $dsn = preg_replace('/;?dbname=[^;]*/i', '', (string) $cfg['dsn']) . ';dbname=' . $cfg['database'];
    $ref = new ReflectionProperty(Connection::class, 'pdo');
    $ref->setAccessible(true);
    $ref->setValue(null, new PDO($dsn, (string) $cfg['user'], (string) $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]));
    $statusFile = (string) $cfg['status_file'];
    register_shutdown_function(static function () use ($statusFile) {
        $code = http_response_code();
        file_put_contents($statusFile, (string) (is_int($code) && $code >= 100 ? $code : 200));
    });
    $extra = is_array($cfg['extra'] ?? null) ? $cfg['extra'] : [];
    $headers = [];
    if (isset($extra['token'])) {
        $headers['authorization'] = 'Bearer ' . $extra['token'];
    }
    $_GET = is_array($extra['query'] ?? null) ? $extra['query'] : [];
    switch ((string) $cfg['action']) {
        case 'login':
            LoginController::login(yiRequest('POST', '/auth/login', ['username' => $extra['username'], 'password' => $extra['password']]));
            break;
        case 'benim':
            KullaniciYetkiController::benimYetkilerim(yiRequest('GET', '/auth/yetkiler', [], $headers));
            break;
        case 'kullanici':
            KullaniciYetkiController::kullaniciYetkileri(yiRequest('GET', '/yonetim/kullanicilar/' . $extra['id'] . '/yetkiler', [], $headers), (int) $extra['id']);
            break;
        case 'audit':
            KullaniciYetkiController::yetkiAuditleri(yiRequest('GET', '/yonetim/yetki-auditleri', [], $headers));
            break;
        case 'yonetim_kullanicilar':
            YonetimController::kullanicilar(yiRequest('GET', '/yonetim/kullanicilar', [], $headers));
            break;
        default:
            fwrite(STDERR, "unknown action\n");
            exit(2);
    }
    exit(0);
}

$rootDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($rootDsn === '' || stripos($rootDsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $rootDsn, $hostMatch)
    && !in_array(strtolower($hostMatch[1]), ['127.0.0.1', 'localhost', '::1'], true)
) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}
$baseDsn = preg_replace('/;?dbname=[^;]*/i', '', $rootDsn) ?: $rootDsn;
$root = yiPdo($baseDsn);
$db = 'medisa_yi_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$legacyDb = 'medisa_yi_legacy_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $legacyDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

function yiToken(int $userId, string $rol): string
{
    return Jwt::encode(['sub' => $userId, 'rol' => $rol, 'iat' => time(), 'exp' => time() + 600]);
}

/** @param array<string, mixed> $p */
function yiSorted(array $p): array
{
    sort($p, SORT_STRING);

    return $p;
}

try {
    yiConfigure($db);
    $pdo = yiPdo($baseDsn . ';dbname=' . $db);
    $chain = array_values(array_filter(scandir(__DIR__ . '/../../api/migrations') ?: [], static fn ($n) => (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $n)));
    sort($chain, SORT_STRING);
    yiAssert(end($chain) === '100_user_yetki_istisnalari.sql', 'kanonik zincirin ucu migration 100');
    foreach ($chain as $migration) {
        if ($migration === '067_personel_canonical_reference_gate.sql') {
            continue; // referans-veri kapısı; boş test DB'sinde anlamsız (diğer MariaDB runner'larıyla aynı)
        }
        $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/' . $migration));
    }
    // 100'ün iki kez çalışması güvenli (IF NOT EXISTS / DROP TRIGGER IF EXISTS).
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/100_user_yetki_istisnalari.sql'));
    yiAssert(true, 'migration 100 tekrar çalıştırılabilir (idempotent)');

    $count = static fn (string $sql, array $p = []): int => (int) (function () use ($pdo, $sql, $p) {
        $s = $pdo->prepare($sql);
        $s->execute($p);

        return $s->fetchColumn();
    })();
    foreach (['trg_uyi_no_delete', 'trg_uyi_only_revoke', 'trg_uya_no_update', 'trg_uya_no_delete'] as $trigger) {
        yiAssert($count('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?', [$trigger]) === 1, 'tetikleyici var: ' . $trigger);
    }
    yiAssert($count("SELECT COUNT(*) FROM user_yetki_istisnalari") === 0 && $count("SELECT COUNT(*) FROM user_yetki_auditleri") === 0, 'migration 100 veri yazmaz (iki tablo boş)');

    $hash = password_hash('YiSeedPass-24chars!!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO sirketler (id, kod, ad) VALUES (1, 'SRK-1', 'Sirket Bir')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, sirket_id) VALUES (1, 'SGK-1', 'Medisa', 1)");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id, sirket_id, durum) VALUES (1, 'SB-1', 'Fabrika', 1, 1, 'AKTIF')");
    $roles = ['GENEL_YONETICI' => 1, 'SISTEM_YONETICISI' => 2, 'IK_SORUMLUSU' => 3, 'MUHASEBE' => 4, 'IK_PERSONELI' => 5];
    $ins = $pdo->prepare('INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (?, ?, ?, ?, ?, \'AKTIF\')');
    foreach ($roles as $rol => $id) {
        $ins->execute([$id, 'u' . $id, $hash, 'Kullanici ' . $id, $rol]);
    }
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (4, 1), (5, 1)');

    // --- Boş tablolar: login + /auth/yetkiler etkin izinleri == rol matrisi -------
    foreach ($roles as $rol => $id) {
        $login = yiHttp($db, 'login', ['username' => 'u' . $id, 'password' => 'YiSeedPass-24chars!!']);
        yiAssert($login['status'] === 200, 'login 200: ' . $rol . ' ' . json_encode($login['payload']));
        $loginPerms = $login['payload']['data']['user']['effective_permissions'] ?? null;
        $expected = [];
        foreach (RolePermissions::permissionCatalog() as $permission) {
            if (RolePermissions::has(['id' => $id, 'rol' => $rol], $permission)) {
                $expected[] = $permission;
            }
        }
        yiAssert(is_array($loginPerms) && yiSorted($loginPerms) === yiSorted($expected), 'boş tablo: login etkin izinleri rol matrisiyle aynı (' . $rol . ', ' . count($expected) . ')');
        $me = yiHttp($db, 'benim', ['token' => yiToken($id, $rol)]);
        yiAssert($me['status'] === 200 && yiSorted($me['payload']['data']['effective_permissions']) === yiSorted($expected)
            && $me['payload']['data']['istisnalar'] === [], 'boş tablo: /auth/yetkiler aynı ve istisna yok (' . $rol . ')');
    }
    $gyCount = count(RolePermissions::roleDefaultPermissions('GENEL_YONETICI'));
    yiAssert($gyCount === 111, 'GENEL_YONETICI rol varsayılanı 111 (108 + 3 yetki yönetimi)');

    // --- Okuma uçları yetki kapısı ------------------------------------------------
    $gyToken = yiToken(1, 'GENEL_YONETICI');
    foreach (['SISTEM_YONETICISI' => 2, 'IK_SORUMLUSU' => 3, 'MUHASEBE' => 4] as $rol => $id) {
        yiAssert(yiHttp($db, 'kullanici', ['token' => yiToken($id, $rol), 'id' => 4])['status'] === 403, 'kullanıcı yetkileri okuma 403: ' . $rol);
        yiAssert(yiHttp($db, 'audit', ['token' => yiToken($id, $rol)])['status'] === 403, 'yetki audit okuma 403: ' . $rol);
    }
    $read = yiHttp($db, 'kullanici', ['token' => $gyToken, 'id' => 4]);
    yiAssert($read['status'] === 200 && $read['payload']['data']['rol'] === 'MUHASEBE' && $read['payload']['data']['schema_ready'] === true
        && $read['payload']['data']['istisnalar'] === [] && count($read['payload']['data']['rol_varsayilanlari']) === count(RolePermissions::roleDefaultPermissions('MUHASEBE')), 'GY kullanıcı yetkilerini okur (boş istisna)');
    yiAssert(yiHttp($db, 'kullanici', ['token' => $gyToken, 'id' => 999])['status'] === 404, 'olmayan kullanıcı 404');
    $audit = yiHttp($db, 'audit', ['token' => $gyToken]);
    yiAssert($audit['status'] === 200 && $audit['payload']['data']['items'] === [] && $audit['payload']['data']['schema_ready'] === true, 'GY yetki audit listesini okur (boş)');

    // --- İstisnalar gerçek istekte ---------------------------------------------------
    $istisna = $pdo->prepare('INSERT INTO user_yetki_istisnalari (user_id, permission, etki, sube_id, gecerlilik_baslangic, gecerlilik_bitis, veren_user_id, hedef_rol_snapshot, gerekce) VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)');
    // MUHASEBE (4): bordro_on_izleme.view DENY, personeller.create ALLOW, süresi dolmuş ALLOW, gelecekte ALLOW, iptal edilmiş DENY
    $istisna->execute([4, 'bordro_on_izleme.view', 'DENY', null, '2026-01-01 00:00:00', null, 'MUHASEBE', 'test deny']);
    $istisna->execute([4, 'personeller.create', 'ALLOW', null, '2026-01-01 00:00:00', null, 'MUHASEBE', 'test allow']);
    $istisna->execute([4, 'personeller.update', 'ALLOW', null, '2026-01-01 00:00:00', '2026-01-02 00:00:00', 'MUHASEBE', 'expired']);
    $istisna->execute([4, 'personeller.delete', 'ALLOW', null, '2099-01-01 00:00:00', null, 'MUHASEBE', 'future']);
    $istisna->execute([4, 'finans.view', 'DENY', null, '2026-01-01 00:00:00', null, 'MUHASEBE', 'iptal edilecek']);
    $revokedId = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE user_yetki_istisnalari SET iptal_edildi_at = UTC_TIMESTAMP(), iptal_eden_user_id = 1, iptal_nedeni = 'MANUEL' WHERE id = ?")->execute([$revokedId]);
    yiAssert(true, 'istisna yalnız bir kez iptal edilebilir (ilk iptal kabul)');

    $loaded = UserYetkiIstisnaSchema::loadActive($pdo, 4);
    yiAssert(count($loaded) === 3 && array_column($loaded, 'permission') === ['bordro_on_izleme.view', 'personeller.create', 'personeller.delete'],
        'tek sorgu yüklemesi: iptal edilen ve süresi dolan satır gelmez');

    $muhToken = yiToken(4, 'MUHASEBE');
    $me = yiHttp($db, 'benim', ['token' => $muhToken]);
    $eff = $me['payload']['data']['effective_permissions'];
    yiAssert(!in_array('bordro_on_izleme.view', $eff, true), 'gerçek istek: DENY rol varsayılanını kapatır');
    yiAssert(in_array('personeller.create', $eff, true) && ($me['payload']['data']['kaynaklar']['personeller.create'] ?? '') === EffectivePermissionResolver::SOURCE_USER_ALLOW, 'gerçek istek: ALLOW izin verir (USER_ALLOW)');
    yiAssert(!in_array('personeller.update', $eff, true) && !in_array('personeller.delete', $eff, true), 'gerçek istek: süresi dolmuş / başlamamış ALLOW yok');
    yiAssert(in_array('finans.view', $eff, true) === RolePermissions::has(['rol' => 'MUHASEBE'], 'finans.view'), 'gerçek istek: iptal edilmiş DENY etkisiz');
    $login = yiHttp($db, 'login', ['username' => 'u4', 'password' => 'YiSeedPass-24chars!!']);
    yiAssert(yiSorted($login['payload']['data']['user']['effective_permissions']) === yiSorted($eff), 'login yanıtı /auth/yetkiler ile aynı etkin izinleri taşır');

    // Korunan uç: GY'ye yonetim-paneli.manage DENY yok sayılır (sistem hakkı); SISTEM_YONETICISI'ne DENY 403.
    $istisna->execute([2, 'yonetim-paneli.manage', 'DENY', null, '2026-01-01 00:00:00', null, 'SISTEM_YONETICISI', 'kapat']);
    yiAssert(yiHttp($db, 'yonetim_kullanicilar', ['token' => yiToken(2, 'SISTEM_YONETICISI')])['status'] === 403, 'gerçek uç: DENY edilen yonetim-paneli.manage 403');
    $istisna->execute([1, 'yonetim-paneli.manage', 'DENY', null, '2026-01-01 00:00:00', null, 'GENEL_YONETICI', 'denenen']);
    yiAssert(yiHttp($db, 'yonetim_kullanicilar', ['token' => $gyToken])['status'] === 200, 'gerçek uç: GY sistem hakkı DENY ile kapanmaz');

    $read = yiHttp($db, 'kullanici', ['token' => $gyToken, 'id' => 4]);
    yiAssert(count($read['payload']['data']['istisnalar']) === 5 && $read['payload']['data']['istisnalar'][0]['iptal_nedeni'] === 'MANUEL', 'GY okuması iptal edilenler dahil geçmişi gösterir');

    // --- Tetikleyiciler: silinmez, çekirdek alan değişmez, ikinci iptal yok ---------
    $blocked = static function (string $sql, array $p = []) use ($pdo): bool {
        try {
            $pdo->prepare($sql)->execute($p);
        } catch (PDOException $e) {
            return strpos($e->getMessage(), 'IMMUTABLE') !== false;
        }

        return false;
    };
    yiAssert($blocked('DELETE FROM user_yetki_istisnalari WHERE id = ?', [$revokedId]), 'istisna DELETE engellenir');
    yiAssert($blocked("UPDATE user_yetki_istisnalari SET iptal_edildi_at = UTC_TIMESTAMP(), iptal_eden_user_id = 1, iptal_nedeni = 'SURE_DOLDU' WHERE id = ?", [$revokedId]), 'iptal edilmiş istisna ikinci kez güncellenemez');
    yiAssert($blocked("UPDATE user_yetki_istisnalari SET etki = 'ALLOW' WHERE permission = 'bordro_on_izleme.view'"), 'istisna çekirdek alanı (etki) değişmez');
    yiAssert($blocked("UPDATE user_yetki_istisnalari SET gecerlilik_bitis = '2099-01-01 00:00:00', iptal_edildi_at = UTC_TIMESTAMP(), iptal_nedeni = 'MANUEL' WHERE permission = 'personeller.create'"), 'iptal sırasında süre değiştirilemez');
    $chk = false;
    try {
        $istisna->execute([4, 'x.y', 'ALLOW', null, '2026-02-01 00:00:00', '2026-01-01 00:00:00', 'MUHASEBE', 'ters']);
    } catch (PDOException $e) {
        $chk = true;
    }
    yiAssert($chk, 'bitiş başlangıçtan önce olamaz (CHECK)');
    $pdo->exec("INSERT INTO user_yetki_auditleri (aksiyon, aktor_user_id, hedef_user_id, hedef_rol, permission, etki, gerekce) VALUES ('VER', 1, 4, 'MUHASEBE', 'personeller.create', 'ALLOW', 'test')");
    yiAssert($blocked('UPDATE user_yetki_auditleri SET gerekce = ?', ['x']), 'audit UPDATE engellenir');
    yiAssert($blocked('DELETE FROM user_yetki_auditleri'), 'audit DELETE engellenir');
    $audit = yiHttp($db, 'audit', ['token' => $gyToken, 'query' => ['kullanici_id' => '4']]);
    yiAssert(count($audit['payload']['data']['items']) === 1 && $audit['payload']['data']['items'][0]['hedef_user_id'] === 4, 'GY audit kaydını hedef kullanıcıya göre okur');
    $fkBlocked = false;
    try {
        $pdo->exec('DELETE FROM users WHERE id = 4');
    } catch (PDOException $e) {
        $fkBlocked = true;
    }
    yiAssert($fkBlocked, 'istisna/audit geçmişi olan kullanıcı satırı FK ile korunur');

    // --- Geri uyumluluk: 100 uygulanmamış şema ---------------------------------------
    $legacy = yiPdo($baseDsn . ';dbname=' . $legacyDb);
    $legacy->exec('CREATE TABLE users (id INT UNSIGNED PRIMARY KEY)');
    yiAssert(UserYetkiIstisnaSchema::loadActive($legacy, 4) === [], '100 öncesi şema: loadActive boş döner');
    yiAssert(UserYetkiIstisnaSchema::loadHistory($legacy, 4)['schema_ready'] === false && UserYetkiIstisnaSchema::loadAudit($legacy, null, 10)['schema_ready'] === false, '100 öncesi şema: okuma uçları schema_ready=false');

    echo "verify-yetki-istisna-mysql: OK\n";
} finally {
    $pdo = null;
    $legacy = null;
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
    $root->exec('DROP DATABASE IF EXISTS `' . $legacyDb . '`');
}
