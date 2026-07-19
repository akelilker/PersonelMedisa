<?php

declare(strict_types=1);

/**
 * MariaDB HTTP + persistence acceptance for haftalik kapanis (S79-B).
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\HaftalikKapanisController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function hkAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function hkPdo(string $dsn): PDO
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

function phpMysqlArgs(): array
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
 * @return array{status:int, payload:array<string,mixed>}
 */
function invokeHkHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    $child = spawnHkHttp($pdo, $user, $method, $path, $body, $headers, $query);

    return finishHkHttp($child);
}

/**
 * @return array{process:resource, pipes:array, status_file:string}
 */
function spawnHkHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    setConnectionPdo($pdo);
    resetAuthUser($user);

    $statusFile = tempnam(sys_get_temp_dir(), 'hk_http_');
    if ($statusFile === false) {
        throw new RuntimeException('tempnam failed');
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

    $cmd = array_merge([PHP_BINARY], phpMysqlArgs(), [__FILE__, '--http-child']);
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

    return ['process' => $process, 'pipes' => $pipes, 'status_file' => $statusFile];
}

/**
 * @param array{process:resource, pipes:array, status_file:string} $child
 * @return array{status:int, payload:array<string,mixed>}
 */
function finishHkHttp(array $child): array
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

    if ($method === 'POST' && $path === '/haftalik-kapanis') {
        HaftalikKapanisController::create($request);
    }
    if ($method === 'GET' && $path === '/haftalik-kapanis/yillik-fazla-calisma') {
        HaftalikKapanisController::yillikFazlaCalisma($request);
    }
    if ($method === 'GET' && preg_match('#^/haftalik-kapanis/(\d+)$#', $path, $matches)) {
        HaftalikKapanisController::detail($request, $matches[1]);
    }

    fwrite(STDERR, "unhandled route\n");
    exit(3);
}

function applyHkMigration(PDO $pdo): void
{
    $migration = (string) file_get_contents(__DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
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
}

function createHkParentTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE subeler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          kod VARCHAR(32) NOT NULL,
          ad VARCHAR(120) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE departmanlar (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          ad VARCHAR(120) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(64) NOT NULL,
          password_hash VARCHAR(255) NOT NULL DEFAULT '',
          ad_soyad VARCHAR(160) NOT NULL,
          rol VARCHAR(64) NOT NULL,
          durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE user_subeler (
          user_id INT UNSIGNED NOT NULL,
          sube_id INT UNSIGNED NOT NULL,
          PRIMARY KEY (user_id, sube_id),
          CONSTRAINT fk_user_subeler_user FOREIGN KEY (user_id) REFERENCES users (id),
          CONSTRAINT fk_user_subeler_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
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
          departman_id INT UNSIGNED NULL,
          aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          KEY idx_personel_sube (sube_id),
          CONSTRAINT fk_personeller_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
          CONSTRAINT fk_personeller_departman FOREIGN KEY (departman_id) REFERENCES departmanlar (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE gunluk_puantaj (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          personel_id INT UNSIGNED NOT NULL,
          tarih DATE NOT NULL,
          net_calisma_suresi_dakika INT UNSIGNED NULL,
          UNIQUE KEY uq_gp_personel_tarih (personel_id, tarih),
          CONSTRAINT fk_gp_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE gunluk_bildirimler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          sube_id INT UNSIGNED NOT NULL,
          departman_id INT UNSIGNED NULL,
          tarih DATE NOT NULL,
          state VARCHAR(32) NOT NULL DEFAULT 'TASLAK',
          haftalik_mutabakat_id INT UNSIGNED NULL,
          created_by INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE haftalik_bildirim_mutabakatlari (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          sube_id INT UNSIGNED NOT NULL,
          birim_amiri_user_id INT UNSIGNED NOT NULL,
          hafta_baslangic DATE NOT NULL,
          hafta_bitis DATE NOT NULL,
          state VARCHAR(32) NOT NULL DEFAULT 'TAMAMLANDI',
          onaylayan_user_id INT UNSIGNED NOT NULL,
          onaylandi_at TIMESTAMP NULL DEFAULT NULL,
          CONSTRAINT fk_hbm_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
          CONSTRAINT fk_hbm_amir FOREIGN KEY (birim_amiri_user_id) REFERENCES users (id),
          CONSTRAINT fk_hbm_onaylayan FOREIGN KEY (onaylayan_user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function seedHkFixtures(PDO $pdo): void
{
    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (3, 'Operasyon'), (4, 'Depo')");
    $pdo->exec("
        INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
          (1, 'gy', 'x', 'Genel Yonetici', 'GENEL_YONETICI', 'AKTIF'),
          (2, 'ba', 'x', 'Birim Amiri', 'BIRIM_AMIRI', 'AKTIF'),
          (3, 'patron', 'x', 'Patron', 'PATRON', 'AKTIF'),
          (4, 'muh', 'x', 'Muhasebe', 'MUHASEBE', 'AKTIF')
    ");
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (2, 1), (4, 1)');
    $pdo->exec("
        INSERT INTO personeller (
          id, tc_kimlik_no, ad, soyad, dogum_tarihi, sicil_no, ise_giris_tarihi, sube_id, departman_id, aktif_durum
        ) VALUES
          (10, '11111111111', 'Ayse', 'Yilmaz', '1990-01-01', 'S10', '2020-01-01', 1, 3, 'AKTIF'),
          (20, '22222222222', 'Mehmet', 'Demir', '1988-01-01', 'S20', '2020-01-01', 2, NULL, 'AKTIF')
    ");

    // Week 2026-04-06..12 mutabakat TAMAMLANDI for sube 1
    $pdo->exec("
        INSERT INTO haftalik_bildirim_mutabakatlari
          (id, sube_id, birim_amiri_user_id, hafta_baslangic, hafta_bitis, state, onaylayan_user_id, onaylandi_at)
        VALUES
          (1, 1, 2, '2026-04-06', '2026-04-12', 'TAMAMLANDI', 2, '2026-04-12 18:00:00')
    ");

    // Optional 7-day puantaj totaling 3000 (fazla=300)
    $days = [
        '2026-04-06' => 428,
        '2026-04-07' => 428,
        '2026-04-08' => 428,
        '2026-04-09' => 428,
        '2026-04-10' => 428,
        '2026-04-11' => 428,
        '2026-04-12' => 432,
    ];
    $stmt = $pdo->prepare('
        INSERT INTO gunluk_puantaj (personel_id, tarih, net_calisma_suresi_dakika)
        VALUES (10, :tarih, :net)
    ');
    foreach ($days as $tarih => $net) {
        $stmt->execute(['tarih' => $tarih, 'net' => $net]);
    }

    // Mutabakat for concurrency week + sube 2 scope week
    $pdo->exec("
        INSERT INTO haftalik_bildirim_mutabakatlari
          (id, sube_id, birim_amiri_user_id, hafta_baslangic, hafta_bitis, state, onaylayan_user_id, onaylandi_at)
        VALUES
          (2, 1, 2, '2026-04-20', '2026-04-26', 'TAMAMLANDI', 2, '2026-04-26 18:00:00'),
          (3, 2, 2, '2026-04-06', '2026-04-12', 'TAMAMLANDI', 2, '2026-04-12 18:00:00'),
          (4, 1, 2, '2025-12-29', '2026-01-04', 'TAMAMLANDI', 2, '2026-01-04 18:00:00'),
          (5, 1, 2, '2026-05-04', '2026-05-10', 'TAMAMLANDI', 2, '2026-05-10 18:00:00')
    ");
}

function bootstrapHkSchema(PDO $pdo): void
{
    $suffix = bin2hex(random_bytes(4));
    $dbName = 'hk_s79b_' . $suffix;
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $dbName . '`');

    createHkParentTables($pdo);
    applyHkMigration($pdo);
    seedHkFixtures($pdo);
}

function assertHkSchemaPostconditions(PDO $pdo): void
{
    foreach (['haftalik_kapanislar', 'haftalik_kapanis_satirlari'] as $table) {
        $create = (string) $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC)['Create Table'];
        hkAssert(stripos($create, 'CREATE TABLE `' . $table . '`') !== false, 'SHOW CREATE TABLE ' . $table);
        hkAssert(stripos($create, 'utf8mb4') !== false, $table . ' charset utf8mb4');
    }

    $create = (string) $pdo->query('SHOW CREATE TABLE haftalik_kapanislar')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    hkAssert(stripos($create, 'departman_scope_key') !== false, 'generated departman_scope_key');
    hkAssert(
        stripos($create, 'IFNULL') !== false || stripos($create, 'ifnull') !== false || stripos($create, 'STORED') !== false,
        'departman_scope_key generated expression'
    );
    hkAssert(stripos($create, 'uq_haftalik_kapanis_scope') !== false, 'unique key uq_haftalik_kapanis_scope');

    $fk = $pdo->query("
        SELECT CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'haftalik_kapanislar'
          AND CONSTRAINT_NAME = 'fk_haftalik_kapanis_sube'
    ")->fetch(PDO::FETCH_ASSOC);
    hkAssert(is_array($fk), 'FK fk_haftalik_kapanis_sube present');
    hkAssert(in_array((string) $fk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true), 'FK DELETE_RULE RESTRICT/NO ACTION');

    $satirFk = $pdo->query("
        SELECT CONSTRAINT_NAME, DELETE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'haftalik_kapanis_satirlari'
          AND CONSTRAINT_NAME = 'fk_hks_personel'
    ")->fetch(PDO::FETCH_ASSOC);
    hkAssert(is_array($satirFk), 'FK fk_hks_personel present');
    hkAssert(in_array((string) $satirFk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true), 'satir FK DELETE_RULE RESTRICT/NO ACTION');
}

/**
 * Seed a kapanis header + one satir for aggregate tests.
 */
function seedAggregateSatir(
    PDO $pdo,
    int $subeId,
    int $personelId,
    string $haftaBaslangic,
    string $haftaBitis,
    int $yil,
    int $fazla,
    int $tamHafta = 1,
    ?int $departmanId = null
): int {
    $scopeKey = $departmanId === null ? 0 : $departmanId;
    // Avoid unique collisions by using distinct weeks; departman_scope_key isolates null vs set.
    $ins = $pdo->prepare('
        INSERT INTO haftalik_kapanislar (
          sube_id, hafta_baslangic, hafta_bitis, departman_id,
          state, personel_sayisi, snapshot_satir_sayisi, kaynak_versiyon, created_by
        ) VALUES (
          :sube_id, :hafta_baslangic, :hafta_bitis, :departman_id,
          \'KAPANDI\', 1, 1, \'A2_MOTOR_V1\', 1
        )
    ');
    $ins->execute([
        'sube_id' => $subeId,
        'hafta_baslangic' => $haftaBaslangic,
        'hafta_bitis' => $haftaBitis,
        'departman_id' => $departmanId,
    ]);
    $kapanisId = (int) $pdo->lastInsertId();

    $satir = $pdo->prepare('
        INSERT INTO haftalik_kapanis_satirlari (
          kapanis_id, personel_id, departman_id,
          hafta_baslangic, hafta_bitis, yil, hafta_no,
          state, kaynak_versiyon,
          toplam_net_dakika, normal_calisma_dakika, fazla_calisma_dakika,
          fazla_surelerle_calisma_dakika, tam_hafta_verisi,
          compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi,
          hesaplama_zamani, kaynak_gun_sayisi, notlar_json
        ) VALUES (
          :kapanis_id, :personel_id, :departman_id,
          :hafta_baslangic, :hafta_bitis, :yil, 1,
          \'KAPANDI\', \'A2_MOTOR_V1\',
          :toplam, :normal, :fazla,
          0, :tam_hafta,
          \'[]\', 0, 0,
          \'2026-01-01 00:00:00\', :kaynak_gun, NULL
        )
    ');
    $satir->execute([
        'kapanis_id' => $kapanisId,
        'personel_id' => $personelId,
        'departman_id' => $departmanId,
        'hafta_baslangic' => $haftaBaslangic,
        'hafta_bitis' => $haftaBitis,
        'yil' => $yil,
        'toplam' => 2700 + $fazla,
        'normal' => 2700,
        'fazla' => $fazla,
        'tam_hafta' => $tamHafta,
        'kaynak_gun' => $tamHafta === 1 ? 7 : 3,
    ]);

    // Silence unused $scopeKey (documents unique identity).
    unset($scopeKey);

    return $kapanisId;
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/HaftalikKapanisController.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
$permissionsSource = (string) file_get_contents(__DIR__ . '/../../api/src/Auth/RolePermissions.php');

hkAssert(strpos($routerSource, 'HaftalikKapanisController::create') !== false, 'router POST create');
hkAssert(strpos($routerSource, 'HaftalikKapanisController::detail') !== false, 'router GET detail');
hkAssert(strpos($routerSource, 'HaftalikKapanisController::yillikFazlaCalisma') !== false, 'router GET yillik');
$yillikPos = strpos($routerSource, "/haftalik-kapanis/yillik-fazla-calisma");
$idPos = strpos($routerSource, '#^/haftalik-kapanis/(\\d+)$#');
hkAssert($yillikPos !== false && $idPos !== false && $yillikPos < $idPos, 'router yillik before :id regex');
hkAssert(strpos($controllerSource, "puantaj.muhurle") !== false, 'create permission puantaj.muhurle');
hkAssert(strpos($controllerSource, "puantaj.view") !== false, 'detail/yillik permission puantaj.view');
hkAssert(strpos($permissionsSource, "'puantaj.muhurle'") !== false, 'RolePermissions has puantaj.muhurle');
hkAssert(strpos($permissionsSource, "'puantaj.view'") !== false, 'RolePermissions has puantaj.view');
hkAssert(preg_match('/CREATE TABLE\s+haftalik_kapanislar\s*\(/i', $migrationSource) === 1, 'migration CREATE TABLE haftalik_kapanislar');
hkAssert(preg_match('/CREATE TABLE\s+haftalik_kapanis_satirlari\s*\(/i', $migrationSource) === 1, 'migration CREATE TABLE satirlar');
hkAssert(stripos($migrationSource, 'CREATE TABLE IF NOT EXISTS') === false, 'migration no IF NOT EXISTS');
hkAssert(stripos($migrationSource, 'DROP ') === false, 'migration no DROP');
hkAssert(preg_match('/\bDELETE\s+FROM\b/i', $migrationSource) !== 1, 'migration no DELETE FROM');
hkAssert(preg_match('/(?:^|;)\s*UPDATE\b/im', $migrationSource) !== 1, 'migration no UPDATE statement');
hkAssert(stripos($migrationSource, 'ON DELETE RESTRICT') !== false, 'migration FK ON DELETE RESTRICT');
hkAssert(stripos($migrationSource, 'ON DELETE CASCADE') === false, 'migration no ON DELETE CASCADE');
hkAssert(stripos($migrationSource, 'departman_scope_key') !== false, 'migration departman_scope_key');
hkAssert(stripos($migrationSource, 'uq_haftalik_kapanis_scope') !== false, 'migration unique scope key');

// Incomplete/partial table must fail loudly (no IF NOT EXISTS silent success).
$partialRoot = hkPdo($dsn);
$partialDb = 'hk_partial_' . bin2hex(random_bytes(3));
$partialRoot->exec('CREATE DATABASE `' . $partialDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$partialRoot->exec('USE `' . $partialDb . '`');
createHkParentTables($partialRoot);
$partialRoot->exec('CREATE TABLE haftalik_kapanislar (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$partialFailed = false;
try {
    applyHkMigration($partialRoot);
} catch (Throwable $e) {
    $partialFailed = true;
}
hkAssert($partialFailed, 'partial existing haftalik_kapanislar → migration fails');
$colsPartial = $partialRoot->query('SHOW FULL COLUMNS FROM haftalik_kapanislar')->fetchAll(PDO::FETCH_ASSOC);
hkAssert(count($colsPartial) === 1, 'partial schema unchanged after failed migration');
$partialRoot->exec('DROP DATABASE `' . $partialDb . '`');

$root = hkPdo($dsn);
bootstrapHkSchema($root);
$dbName = (string) $root->query('SELECT DATABASE()')->fetchColumn();
$pdo = hkPdo(preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn));
assertHkSchemaPostconditions($pdo);

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$ba = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
$patron = ['id' => 3, 'rol' => 'PATRON', 'sube_ids' => []];
$muhasebe = ['id' => 4, 'rol' => 'MUHASEBE', 'sube_ids' => [1]];

$subeHeader = ['x-active-sube-id' => '1'];
$weekPayload = [
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
];

$unauth = invokeHkHttp($pdo, null, 'POST', '/haftalik-kapanis', $weekPayload, $subeHeader);
hkAssert($unauth['status'] === 401, 'unauthenticated POST → 401');

$patronPost = invokeHkHttp($pdo, $patron, 'POST', '/haftalik-kapanis', $weekPayload, $subeHeader);
hkAssert($patronPost['status'] === 403, 'PATRON (no puantaj.muhurle) POST → 403');

$muhPost = invokeHkHttp($pdo, $muhasebe, 'POST', '/haftalik-kapanis', $weekPayload, $subeHeader);
hkAssert($muhPost['status'] === 403, 'MUHASEBE (has view, no muhurle) POST → 403');

$gyNoScope = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', $weekPayload);
hkAssert($gyNoScope['status'] === 422, 'GY without active sube header → 422');
hkAssert(($gyNoScope['payload']['errors'][0]['field'] ?? '') === 'sube_id', 'GY without header field sube_id');

$noMutabakat = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', [
    'hafta_baslangic' => '2026-04-13',
    'hafta_bitis' => '2026-04-19',
], $subeHeader);
hkAssert($noMutabakat['status'] === 409, 'GY POST without mutabakat → 409 STATE_CONFLICT');
hkAssert(($noMutabakat['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT', 'no mutabakat code STATE_CONFLICT');

// ISO year boundary week before main creates pollute 2026 aggregate.
seedAggregateSatir($pdo, 1, 10, '2025-12-29', '2026-01-04', 2026, 111, 1, null);
$isoAgg = invokeHkHttp($pdo, $gy, 'GET', '/haftalik-kapanis/yillik-fazla-calisma', [], $subeHeader, [
    'personel_id' => '10',
    'yil' => '2026',
]);
hkAssert($isoAgg['status'] === 200, 'ISO year boundary aggregate → 200');
hkAssert((int) ($isoAgg['payload']['data']['kullanilan_dakika'] ?? -1) === 111, 'ISO year boundary week counted in 2026');
hkAssert((int) ($isoAgg['payload']['data']['kapanan_hafta_sayisi'] ?? 0) === 1, 'ISO year boundary kapanan_hafta_sayisi');

$created = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', $weekPayload, $subeHeader);
hkAssert($created['status'] === 201, 'GY POST with mutabakat week → 201');
hkAssert((int) ($created['payload']['data']['id'] ?? 0) > 0, 'create returns id');
hkAssert(($created['payload']['data']['state'] ?? '') === 'KAPANDI', 'create state KAPANDI');
$createdId = (int) $created['payload']['data']['id'];
$dbCount = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanislar WHERE id = ' . $createdId)->fetchColumn();
hkAssert($dbCount === 1, 'DB row after create');
$satirCount = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_satirlari WHERE kapanis_id = ' . $createdId)->fetchColumn();
hkAssert($satirCount >= 1, 'DB satir after create');
$fazla = (int) $pdo->query('SELECT fazla_calisma_dakika FROM haftalik_kapanis_satirlari WHERE kapanis_id = ' . $createdId . ' AND personel_id = 10')->fetchColumn();
hkAssert($fazla === 300, 'create fazla_calisma_dakika=300 from 3000 net');

$detail = invokeHkHttp($pdo, $gy, 'GET', '/haftalik-kapanis/' . $createdId, [], $subeHeader);
hkAssert($detail['status'] === 200, 'detail GET → 200');
hkAssert((int) ($detail['payload']['data']['id'] ?? 0) === $createdId, 'detail id matches');

$dup = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', $weekPayload, $subeHeader);
hkAssert($dup['status'] === 409, 'duplicate POST same scope → 409 STATE_CONFLICT');
hkAssert(($dup['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT', 'duplicate code STATE_CONFLICT');

$deptCreate = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', array_merge($weekPayload, [
    'departman_id' => 3,
]), $subeHeader);
hkAssert($deptCreate['status'] === 201, 'different departman_id same week → 201');
$deptId = (int) ($deptCreate['payload']['data']['id'] ?? 0);
hkAssert($deptId > 0 && $deptId !== $createdId, 'departman create separate identity');

$nullVsDept = (int) $pdo->query("
    SELECT COUNT(*) FROM haftalik_kapanislar
    WHERE sube_id = 1 AND hafta_baslangic = '2026-04-06'
")->fetchColumn();
hkAssert($nullVsDept === 2, 'null departman vs departman 3 are different (two creates)');

$missing = invokeHkHttp($pdo, $gy, 'GET', '/haftalik-kapanis/999999', [], $subeHeader);
hkAssert($missing['status'] === 404, 'GET missing id → 404');

$sube2Create = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', $weekPayload, ['x-active-sube-id' => '2']);
hkAssert($sube2Create['status'] === 201, 'GY sube 2 create → 201');
$sube2Id = (int) ($sube2Create['payload']['data']['id'] ?? 0);
$baOther = invokeHkHttp($pdo, $ba, 'GET', '/haftalik-kapanis/' . $sube2Id, [], $subeHeader);
hkAssert($baOther['status'] === 403, 'GET scope dışı (BA sube1 viewing sube2) → 403');

$nonMonday = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', [
    'hafta_baslangic' => '2026-04-07',
    'hafta_bitis' => '2026-04-13',
], $subeHeader);
hkAssert($nonMonday['status'] === 422, 'non-Monday hafta_baslangic → 422');

$wrongBitis = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', [
    'hafta_baslangic' => '2026-04-20',
    'hafta_bitis' => '2026-04-25',
], $subeHeader);
hkAssert($wrongBitis['status'] === 422, 'wrong hafta_bitis → 422');

$serverOwned = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', [
    'hafta_baslangic' => '2026-04-20',
    'hafta_bitis' => '2026-04-26',
    'id' => 999,
    'state' => 'ACIK',
    'sube_id' => 2,
], $subeHeader);
hkAssert($serverOwned['status'] === 422, 'server-owned id/state in body → 422');

// Aggregate boundaries via seeded satirlar (yil=2027 isolated from 2026 creates).
// 0: no rows for personel with only empty → use personel that has no 2027 rows first.
$agg0 = invokeHkHttp($pdo, $gy, 'GET', '/haftalik-kapanis/yillik-fazla-calisma', [], $subeHeader, [
    'personel_id' => '10',
    'yil' => '2027',
]);
hkAssert($agg0['status'] === 200, 'yillik aggregate empty → 200');
hkAssert((int) ($agg0['payload']['data']['kullanilan_dakika'] ?? -1) === 0, 'yillik aggregate 0 boundary');
hkAssert(($agg0['payload']['data']['limit_asildi_mi'] ?? true) === false, 'yillik aggregate 0 not exceeded');

seedAggregateSatir($pdo, 1, 10, '2027-01-04', '2027-01-10', 2027, 16199, 1, null);
$agg16199 = invokeHkHttp($pdo, $gy, 'GET', '/haftalik-kapanis/yillik-fazla-calisma', [], $subeHeader, [
    'personel_id' => '10',
    'yil' => '2027',
]);
hkAssert((int) ($agg16199['payload']['data']['kullanilan_dakika'] ?? -1) === 16199, 'yillik aggregate 16199 boundary');
hkAssert(($agg16199['payload']['data']['limit_asildi_mi'] ?? true) === false, 'yillik aggregate 16199 not exceeded');
hkAssert((int) ($agg16199['payload']['data']['kalan_dakika'] ?? -1) === 1, 'yillik aggregate 16199 kalan=1');

// Replace with exact 16200: delete prior 2027 rows and reseed.
$pdo->exec('DELETE FROM haftalik_kapanis_satirlari WHERE yil = 2027');
$pdo->exec("DELETE FROM haftalik_kapanislar WHERE hafta_baslangic >= '2027-01-01'");
seedAggregateSatir($pdo, 1, 10, '2027-01-04', '2027-01-10', 2027, 16200, 1, null);
$agg16200 = invokeHkHttp($pdo, $gy, 'GET', '/haftalik-kapanis/yillik-fazla-calisma', [], $subeHeader, [
    'personel_id' => '10',
    'yil' => '2027',
]);
hkAssert((int) ($agg16200['payload']['data']['kullanilan_dakika'] ?? -1) === 16200, 'yillik aggregate 16200 boundary');
hkAssert(($agg16200['payload']['data']['limit_asildi_mi'] ?? true) === false, 'yillik aggregate 16200 not exceeded (strict >)');
hkAssert((int) ($agg16200['payload']['data']['kalan_dakika'] ?? -1) === 0, 'yillik aggregate 16200 kalan=0');

$pdo->exec('DELETE FROM haftalik_kapanis_satirlari WHERE yil = 2027');
$pdo->exec("DELETE FROM haftalik_kapanislar WHERE hafta_baslangic >= '2027-01-01'");
seedAggregateSatir($pdo, 1, 10, '2027-01-04', '2027-01-10', 2027, 16201, 1, null);
$agg16201 = invokeHkHttp($pdo, $gy, 'GET', '/haftalik-kapanis/yillik-fazla-calisma', [], $subeHeader, [
    'personel_id' => '10',
    'yil' => '2027',
]);
hkAssert((int) ($agg16201['payload']['data']['kullanilan_dakika'] ?? -1) === 16201, 'yillik aggregate 16201 boundary');
hkAssert(($agg16201['payload']['data']['limit_asildi_mi'] ?? false) === true, 'yillik aggregate 16201 exceeded');
hkAssert((int) ($agg16201['payload']['data']['kalan_dakika'] ?? -1) === 0, 'yillik aggregate 16201 kalan=0');

// tam_hafta_verisi=false excluded
$pdo->exec('DELETE FROM haftalik_kapanis_satirlari WHERE yil = 2027');
$pdo->exec("DELETE FROM haftalik_kapanislar WHERE hafta_baslangic >= '2027-01-01'");
seedAggregateSatir($pdo, 1, 10, '2027-01-04', '2027-01-10', 2027, 500, 0, null);
seedAggregateSatir($pdo, 1, 10, '2027-01-11', '2027-01-17', 2027, 200, 1, null);
$aggEksik = invokeHkHttp($pdo, $gy, 'GET', '/haftalik-kapanis/yillik-fazla-calisma', [], $subeHeader, [
    'personel_id' => '10',
    'yil' => '2027',
]);
hkAssert((int) ($aggEksik['payload']['data']['kullanilan_dakika'] ?? -1) === 200, 'tam_hafta_verisi=false excluded from aggregate');
hkAssert((int) ($aggEksik['payload']['data']['atlanan_eksik_hafta_sayisi'] ?? -1) === 1, 'tam_hafta_verisi=false increments atlanan_eksik');

// Concurrency: two parallel POST same identity → one 201 one 409, DB count=1
$racePayload = [
    'hafta_baslangic' => '2026-04-20',
    'hafta_bitis' => '2026-04-26',
];
$beforeRace = (int) $pdo->query("
    SELECT COUNT(*) FROM haftalik_kapanislar
    WHERE sube_id = 1 AND hafta_baslangic = '2026-04-20' AND departman_scope_key = 0
")->fetchColumn();
hkAssert($beforeRace === 0, 'concurrency week clean before race');

$a = spawnHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', $racePayload, $subeHeader);
$b = spawnHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', $racePayload, $subeHeader);
$ra = finishHkHttp($a);
$rb = finishHkHttp($b);
$statuses = [$ra['status'], $rb['status']];
sort($statuses);
hkAssert($statuses === [201, 409], 'concurrency: two parallel POST same identity → one 201 one 409');
$afterRace = (int) $pdo->query("
    SELECT COUNT(*) FROM haftalik_kapanislar
    WHERE sube_id = 1 AND hafta_baslangic = '2026-04-20' AND departman_scope_key = 0
")->fetchColumn();
hkAssert($afterRace === 1, 'concurrency DB count=1');

// FK personel/sube DELETE RESTRICT
$personelDeleteFailed = false;
try {
    $pdo->exec('DELETE FROM personeller WHERE id = 10');
} catch (Throwable $e) {
    $personelDeleteFailed = true;
}
hkAssert($personelDeleteFailed, 'FK personel DELETE RESTRICT');
hkAssert((int) $pdo->query('SELECT COUNT(*) FROM personeller WHERE id = 10')->fetchColumn() === 1, 'personel row preserved');

$subeDeleteFailed = false;
try {
    $pdo->exec('DELETE FROM subeler WHERE id = 1');
} catch (Throwable $e) {
    $subeDeleteFailed = true;
}
hkAssert($subeDeleteFailed, 'FK sube DELETE RESTRICT');
hkAssert((int) $pdo->query('SELECT COUNT(*) FROM subeler WHERE id = 1')->fetchColumn() === 1, 'sube row preserved');

// Optional transaction: if satir insert fails after mutabakat check, no partial header.
$pdo->exec("
    CREATE TRIGGER trg_hk_fail_satir
    BEFORE INSERT ON haftalik_kapanis_satirlari
    FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced satir fail'
");
$txWeek = [
    'hafta_baslangic' => '2026-05-04',
    'hafta_bitis' => '2026-05-10',
];
$beforeTx = (int) $pdo->query("
    SELECT COUNT(*) FROM haftalik_kapanislar
    WHERE sube_id = 1 AND hafta_baslangic = '2026-05-04' AND departman_scope_key = 0
")->fetchColumn();
$txResult = invokeHkHttp($pdo, $gy, 'POST', '/haftalik-kapanis', $txWeek, $subeHeader);
$pdo->exec('DROP TRIGGER IF EXISTS trg_hk_fail_satir');
$afterTx = (int) $pdo->query("
    SELECT COUNT(*) FROM haftalik_kapanislar
    WHERE sube_id = 1 AND hafta_baslangic = '2026-05-04' AND departman_scope_key = 0
")->fetchColumn();
hkAssert($txResult['status'] >= 400, 'transaction: insert fail after mutabakat → error status');
hkAssert($beforeTx === $afterTx && $afterTx === 0, 'transaction: no partial kapanis row');

echo "verify-haftalik-kapanis-mysql: OK\n";
