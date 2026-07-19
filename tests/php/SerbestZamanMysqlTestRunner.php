<?php

declare(strict_types=1);

/**
 * MariaDB HTTP + persistence acceptance for serbest zaman events (S79-D).
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\SerbestZamanController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function szAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function szPdo(string $dsn): PDO
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
 * @return array{process:resource, pipes:array, status_file:string}
 */
function spawnSzHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    setConnectionPdo($pdo);
    resetAuthUser($user);

    $statusFile = tempnam(sys_get_temp_dir(), 'sz_http_');
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
function finishSzHttp(array $child): array
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

/**
 * @return array{status:int, payload:array<string,mixed>}
 */
function invokeSzHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    return finishSzHttp(spawnSzHttp($pdo, $user, $method, $path, $body, $headers, $query));
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

    if ($method === 'GET' && $path === '/serbest-zaman/events') {
        SerbestZamanController::listEvents($request);
    }
    if ($method === 'GET' && $path === '/serbest-zaman/bakiye') {
        SerbestZamanController::bakiye($request);
    }
    if ($method === 'POST' && $path === '/serbest-zaman/olusum') {
        SerbestZamanController::olusum($request);
    }
    if ($method === 'POST' && $path === '/serbest-zaman/kullanim') {
        SerbestZamanController::kullanim($request);
    }
    if ($method === 'POST' && $path === '/serbest-zaman/iptal') {
        SerbestZamanController::iptal($request);
    }
    if ($method === 'POST' && $path === '/serbest-zaman/duzeltme') {
        SerbestZamanController::duzeltme($request);
    }

    fwrite(STDERR, "unhandled route\n");
    exit(3);
}

function applySqlFile(PDO $pdo, string $path): void
{
    $migration = (string) file_get_contents($path);
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

function createSzParentTables(PDO $pdo): void
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
        CREATE TABLE puantaj_donem_kilitleri (
          sube_id INT UNSIGNED NOT NULL,
          yil SMALLINT UNSIGNED NOT NULL,
          ay TINYINT UNSIGNED NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (sube_id, yil, ay),
          CONSTRAINT fk_pdk_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE puantaj_aylik_muhurleri (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          sube_id INT UNSIGNED NOT NULL,
          yil SMALLINT UNSIGNED NOT NULL,
          ay TINYINT UNSIGNED NOT NULL,
          donem CHAR(7) NOT NULL,
          durum VARCHAR(32) NOT NULL DEFAULT 'MUHURLENDI',
          muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
          created_by INT UNSIGNED NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_puantaj_aylik_muhur_sube_donem (sube_id, yil, ay),
          CONSTRAINT fk_puantaj_aylik_muhur_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
          CONSTRAINT fk_puantaj_aylik_muhur_created_by FOREIGN KEY (created_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function seedSzFixtures(PDO $pdo): void
{
    $pdo->exec("INSERT INTO subeler (id, kod, ad) VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (3, 'Operasyon')");
    $pdo->exec("
        INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
          (1, 'gy', 'x', 'Genel Yonetici', 'GENEL_YONETICI', 'AKTIF'),
          (2, 'ba', 'x', 'Birim Amiri', 'BIRIM_AMIRI', 'AKTIF'),
          (3, 'patron', 'x', 'Patron', 'PATRON', 'AKTIF'),
          (4, 'muh', 'x', 'Muhasebe', 'MUHASEBE', 'AKTIF'),
          (5, 'bolum', 'x', 'Bolum Yoneticisi', 'BOLUM_YONETICISI', 'AKTIF')
    ");
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (2, 1), (4, 1), (5, 1)');
    $pdo->exec("
        INSERT INTO personeller (
          id, tc_kimlik_no, ad, soyad, dogum_tarihi, sicil_no, ise_giris_tarihi, sube_id, departman_id, aktif_durum
        ) VALUES
          (10, '11111111111', 'Ayse', 'Yilmaz', '1990-01-01', 'S10', '2020-01-01', 1, 3, 'AKTIF'),
          (20, '22222222222', 'Mehmet', 'Demir', '1988-01-01', 'S20', '2020-01-01', 2, NULL, 'AKTIF')
    ");
}

/**
 * @return array{kapanis_id:int, snapshot_id:int}
 */
function seedSnapshot(
    PDO $pdo,
    int $subeId,
    int $personelId,
    string $haftaBaslangic,
    string $haftaBitis,
    int $fazla = 60
): array {
    $ins = $pdo->prepare('
        INSERT INTO haftalik_kapanislar (
          sube_id, hafta_baslangic, hafta_bitis, departman_id,
          state, personel_sayisi, snapshot_satir_sayisi, kaynak_versiyon, created_by
        ) VALUES (
          :sube_id, :hafta_baslangic, :hafta_bitis, NULL,
          \'KAPANDI\', 1, 1, \'A2_MOTOR_V1\', 1
        )
    ');
    $ins->execute([
        'sube_id' => $subeId,
        'hafta_baslangic' => $haftaBaslangic,
        'hafta_bitis' => $haftaBitis,
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
          :kapanis_id, :personel_id, NULL,
          :hafta_baslangic, :hafta_bitis, :yil, 1,
          \'KAPANDI\', \'A2_MOTOR_V1\',
          :toplam, 2700, :fazla,
          0, 1,
          \'[]\', 0, 0,
          \'2026-01-01 00:00:00\', 7, NULL
        )
    ');
    $satir->execute([
        'kapanis_id' => $kapanisId,
        'personel_id' => $personelId,
        'hafta_baslangic' => $haftaBaslangic,
        'hafta_bitis' => $haftaBitis,
        'yil' => (int) substr($haftaBaslangic, 0, 4),
        'toplam' => 2700 + $fazla,
        'fazla' => $fazla,
    ]);

    return ['kapanis_id' => $kapanisId, 'snapshot_id' => (int) $pdo->lastInsertId()];
}

function assertSzSchemaPostconditions(PDO $pdo): void
{
    foreach (['serbest_zaman_events', 'serbest_zaman_aktif_olusumlar'] as $table) {
        $create = (string) $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC)['Create Table'];
        szAssert(stripos($create, 'CREATE TABLE `' . $table . '`') !== false, 'SHOW CREATE TABLE ' . $table);
        szAssert(stripos($create, 'utf8mb4') !== false, $table . ' charset utf8mb4');
        szAssert(stripos($create, 'utf8mb4_unicode_ci') !== false, $table . ' collation unicode_ci');
        echo '[SCHEMA] ' . $table . ' CREATE: ' . preg_replace('/\s+/', ' ', $create) . PHP_EOL;
    }

    $eventsCreate = (string) $pdo->query('SHOW CREATE TABLE serbest_zaman_events')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    szAssert(stripos($eventsCreate, 'uq_sz_personel_islem_anahtari') !== false, 'uq_sz_personel_islem_anahtari present');
    szAssert(stripos($eventsCreate, 'uq_sz_iptal_hedef') !== false, 'uq_sz_iptal_hedef present');

    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('serbest_zaman_events', 'serbest_zaman_aktif_olusumlar')
        ORDER BY TABLE_NAME, CONSTRAINT_NAME
    ")->fetchAll(PDO::FETCH_ASSOC);
    szAssert(count($fks) >= 5, 'SZ FK count >= 5');
    foreach ($fks as $fk) {
        szAssert(
            in_array((string) $fk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true),
            'FK ' . $fk['CONSTRAINT_NAME'] . ' DELETE_RULE RESTRICT/NO ACTION'
        );
        echo '[SCHEMA] FK ' . $fk['CONSTRAINT_NAME'] . ' → ' . $fk['REFERENCED_TABLE_NAME']
            . ' DELETE=' . $fk['DELETE_RULE'] . PHP_EOL;
    }
}

function bootstrapSzSchema(PDO $pdo): string
{
    $suffix = bin2hex(random_bytes(4));
    $dbName = 'sz_s79d_' . $suffix;
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $dbName . '`');
    createSzParentTables($pdo);
    applySqlFile($pdo, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
    applySqlFile($pdo, __DIR__ . '/../../api/migrations/028_fazla_calisma_odeme_tercihleri.sql');
    applySqlFile($pdo, __DIR__ . '/../../api/migrations/029_serbest_zaman_events.sql');
    seedSzFixtures($pdo);

    return $dbName;
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/SerbestZamanController.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/029_serbest_zaman_events.sql');

szAssert(strpos($routerSource, 'SerbestZamanController::listEvents') !== false, 'router GET events');
szAssert(strpos($routerSource, 'SerbestZamanController::bakiye') !== false, 'router GET bakiye');
szAssert(strpos($routerSource, 'SerbestZamanController::olusum') !== false, 'router POST olusum');
szAssert(strpos($routerSource, 'SerbestZamanController::kullanim') !== false, 'router POST kullanim');
szAssert(strpos($routerSource, 'SerbestZamanController::iptal') !== false, 'router POST iptal');
szAssert(strpos($routerSource, 'SerbestZamanController::duzeltme') !== false, 'router POST duzeltme');
szAssert(strpos($controllerSource, 'puantaj.view') !== false, 'controller has puantaj.view');
szAssert(strpos($controllerSource, 'puantaj.muhurle') !== false, 'controller has puantaj.muhurle');
szAssert(strpos($controllerSource, 'PERIOD_LOCKED') === false, 'controller NO PERIOD_LOCKED');
szAssert(strpos($controllerSource, 'PERIOD_STATE_UNKNOWN') === false, 'controller NO PERIOD_STATE_UNKNOWN');
szAssert(preg_match('/CREATE TABLE\s+serbest_zaman_events\s*\(/i', $migrationSource) === 1, 'migration CREATE serbest_zaman_events');
szAssert(preg_match('/CREATE TABLE\s+serbest_zaman_aktif_olusumlar\s*\(/i', $migrationSource) === 1, 'migration CREATE serbest_zaman_aktif_olusumlar');
szAssert(stripos($migrationSource, 'CREATE TABLE IF NOT EXISTS') === false, 'migration no IF NOT EXISTS');
szAssert(stripos($migrationSource, 'ON DELETE RESTRICT') !== false, 'migration FK ON DELETE RESTRICT');
szAssert(stripos($migrationSource, 'uq_sz_personel_islem_anahtari') !== false, 'migration uq_sz_personel_islem_anahtari');
szAssert(stripos($migrationSource, 'uq_sz_iptal_hedef') !== false, 'migration uq_sz_iptal_hedef');

$root = szPdo($dsn);
$dbName = bootstrapSzSchema($root);
$pdo = szPdo(preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn));
assertSzSchemaPostconditions($pdo);

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$ba = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
$patron = ['id' => 3, 'rol' => 'PATRON', 'sube_ids' => []];
$muhasebe = ['id' => 4, 'rol' => 'MUHASEBE', 'sube_ids' => [1]];
$subeHeader = ['x-active-sube-id' => '1'];

$seed = seedSnapshot($pdo, 1, 10, '2026-04-06', '2026-04-12', 60);
$snapshotId = $seed['snapshot_id'];
$kapanisId = $seed['kapanis_id'];

$pdo->exec("
    INSERT INTO fazla_calisma_odeme_tercihleri (
      id, snapshot_id, kapanis_id, personel_id, hafta_baslangic, hafta_bitis,
      fazla_calisma_dakika, odeme_tipi, secim_zamani, secen_kullanici_id, onceki_odeme_tipi
    ) VALUES (
      1, {$snapshotId}, {$kapanisId}, 10, '2026-04-06', '2026-04-12',
      60, 'SERBEST_ZAMAN', '2026-04-10 12:00:00', 1, 'KARAR_BEKLIYOR'
    )
");

$unauth = invokeSzHttp($pdo, null, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '10',
]);
szAssert($unauth['status'] === 401, 'unauthenticated → 401');

$patronGet = invokeSzHttp($pdo, $patron, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '10',
]);
szAssert($patronGet['status'] === 403, 'PATRON GET events → 403');

$gyEmpty = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '10',
]);
szAssert($gyEmpty['status'] === 200, 'GY GET events personel 10 → 200 empty items');
szAssert(is_array($gyEmpty['payload']['data']['items'] ?? null)
    && count($gyEmpty['payload']['data']['items']) === 0, 'GY GET events empty items');

$muhGet = invokeSzHttp($pdo, $muhasebe, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '10',
]);
szAssert($muhGet['status'] === 200, 'MUHASEBE GET → 200');

$muhOlusum = invokeSzHttp($pdo, $muhasebe, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => 1,
], $subeHeader);
szAssert($muhOlusum['status'] === 403, 'MUHASEBE POST olusum → 403');

$baOut = invokeSzHttp($pdo, $ba, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '20',
]);
szAssert($baOut['status'] === 403, 'BA scope dışı personel 20 → 403');

$baEmpty = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => []];
$baEmptyGet = invokeSzHttp($pdo, $baEmpty, 'GET', '/serbest-zaman/events', [], [], [
    'personel_id' => '10',
]);
szAssert($baEmptyGet['status'] === 403, 'BA empty allowedSubeIds → 403');

$olusum = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => 1,
], $subeHeader);
szAssert($olusum['status'] === 200, 'GY POST olusum → 200');
szAssert((int) ($olusum['payload']['data']['dakika'] ?? 0) === 90, 'olusum dakika=90 (60*1.5)');
$olusumId = (int) ($olusum['payload']['data']['id'] ?? 0);
szAssert($olusumId > 0, 'olusum returns id');
$guardCount = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id = 1')->fetchColumn();
szAssert($guardCount === 1, 'guard row exists');

$olusumAgain = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => 1,
], $subeHeader);
szAssert($olusumAgain['status'] === 409, 'GY POST olusum again → 409');
szAssert(($olusumAgain['payload']['errors'][0]['code'] ?? '') === 'ALREADY_EXISTS', 'olusum again ALREADY_EXISTS');

$pdo->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, created_by)
            VALUES (1, 2026, 4, '2026-04', 'MUHURLENDI', 1)");

$islemAnahtari = 'sz-kullanim-uuid-001';
$kullanim = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 30,
    'event_tarihi' => '2026-04-15',
    'islem_anahtari' => $islemAnahtari,
], $subeHeader);
szAssert($kullanim['status'] === 200, 'sealed period POST kullanim → 200');
szAssert(($kullanim['payload']['data']['donem_kilitli_miydi'] ?? false) === true, 'kullanim donem_kilitli_miydi true');
$kullanimId = (int) ($kullanim['payload']['data']['id'] ?? 0);
szAssert($kullanimId > 0, 'kullanim returns id');

$bakiyeAfter = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
    'personel_id' => '10',
    'referans_tarih' => '2026-04-15',
]);
szAssert($bakiyeAfter['status'] === 200, 'GET bakiye after kullanim → 200');
szAssert((int) ($bakiyeAfter['payload']['data']['kalan_dakika'] ?? -1) === 60, 'bakiye kalan 60');

$kullanimRetry = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 30,
    'event_tarihi' => '2026-04-15',
    'islem_anahtari' => $islemAnahtari,
], $subeHeader);
szAssert($kullanimRetry['status'] === 200, 'same islem_anahtari retry → 200');
szAssert((int) ($kullanimRetry['payload']['data']['id'] ?? 0) === $kullanimId, 'same islem_anahtari same id');

$kullanimConflict = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 45,
    'event_tarihi' => '2026-04-15',
    'islem_anahtari' => $islemAnahtari,
], $subeHeader);
szAssert($kullanimConflict['status'] === 409, 'same islem_anahtari different dakika → 409');
szAssert(
    ($kullanimConflict['payload']['errors'][0]['code'] ?? '') === 'IDEMPOTENCY_CONFLICT',
    'IDEMPOTENCY_CONFLICT'
);

$insufficient = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 100,
    'event_tarihi' => '2026-04-16',
    'islem_anahtari' => 'sz-kullanim-too-much',
], $subeHeader);
szAssert($insufficient['status'] === 409, 'kullanim dakika > bakiye → 409');
szAssert(
    ($insufficient['payload']['errors'][0]['code'] ?? '') === 'INSUFFICIENT_BALANCE',
    'INSUFFICIENT_BALANCE'
);

$iptal = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
    'personel_id' => 10,
    'hedef_event_id' => $olusumId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'event_tarihi' => '2026-04-20',
    'islem_anahtari' => 'sz-iptal-olusum-1',
], $subeHeader);
szAssert($iptal['status'] === 200, 'POST iptal OLUSUM → 200');
$guardAfterIptal = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id = 1')->fetchColumn();
szAssert($guardAfterIptal === 0, 'guard deleted after iptal');

$iptalAgain = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
    'personel_id' => 10,
    'hedef_event_id' => $olusumId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'event_tarihi' => '2026-04-21',
    'islem_anahtari' => 'sz-iptal-olusum-2',
], $subeHeader);
szAssert($iptalAgain['status'] === 409, 'second iptal → 409');
szAssert(
    ($iptalAgain['payload']['errors'][0]['code'] ?? '') === 'ALREADY_CANCELLED',
    'ALREADY_CANCELLED'
);

$reOlusum = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => 1,
], $subeHeader);
szAssert($reOlusum['status'] === 200, 'POST olusum again after iptal → 200');
$reOlusumId = (int) ($reOlusum['payload']['data']['id'] ?? 0);
szAssert($reOlusumId > 0 && $reOlusumId !== $olusumId, 're-olusum new id');
$guardRe = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id = 1')->fetchColumn();
szAssert($guardRe === 1, 'unique guard prevents second active olusum (re-olusum guard=1)');

$duzeltmeMissing = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
    'personel_id' => 10,
    'hedef_event_id' => $reOlusumId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'yeni_dakika' => 80,
    'event_tarihi' => '2026-04-22',
    'islem_anahtari' => 'sz-duzeltme-missing-aciklama',
], $subeHeader);
szAssert($duzeltmeMissing['status'] === 422, 'duzeltme missing aciklama → 422');

$duzeltmeOk = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
    'personel_id' => 10,
    'hedef_event_id' => $reOlusumId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'yeni_dakika' => 80,
    'event_tarihi' => '2026-04-22',
    'islem_anahtari' => 'sz-duzeltme-ok',
    'aciklama' => 'Duzeltme gerekcesi',
], $subeHeader);
szAssert($duzeltmeOk['status'] === 200, 'POST duzeltme with aciklama → 200');

$serverOwned = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 5,
    'event_tarihi' => '2026-04-23',
    'islem_anahtari' => 'sz-server-owned-sube',
    'sube_id' => 1,
], $subeHeader);
szAssert($serverOwned['status'] === 422, 'server-owned sube_id in body → 422');

$eventsSorted = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '10',
]);
szAssert($eventsSorted['status'] === 200, 'GET events for sort check → 200');
$items = $eventsSorted['payload']['data']['items'] ?? [];
szAssert(is_array($items) && count($items) >= 2, 'GET events has items');
$sortedOk = true;
for ($i = 1, $n = count($items); $i < $n; $i++) {
    $prevT = (string) ($items[$i - 1]['event_tarihi'] ?? '');
    $currT = (string) ($items[$i]['event_tarihi'] ?? '');
    $prevId = (int) ($items[$i - 1]['id'] ?? 0);
    $currId = (int) ($items[$i]['id'] ?? 0);
    if ($prevT > $currT || ($prevT === $currT && $prevId > $currId)) {
        $sortedOk = false;
        break;
    }
}
szAssert($sortedOk, 'GET events sort order event_tarihi ASC, id ASC');

$bakiyeFinal = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
    'personel_id' => '10',
    'referans_tarih' => '2026-04-22',
]);
szAssert($bakiyeFinal['status'] === 200, 'GET bakiye final → 200');
$aktifOlusum = (int) $pdo->query("
    SELECT COUNT(*) FROM serbest_zaman_events e
    WHERE e.personel_id = 10 AND e.event_tipi = 'SERBEST_ZAMAN_OLUSUM'
      AND NOT EXISTS (
        SELECT 1 FROM serbest_zaman_events i
        WHERE i.event_tipi = 'SERBEST_ZAMAN_IPTAL' AND i.hedef_event_id = e.id
      )
")->fetchColumn();
szAssert(
    (int) ($bakiyeFinal['payload']['data']['event_sayisi'] ?? -1) === $aktifOlusum,
    'GET bakiye event_sayisi = active olusum count'
);

echo "verify-serbest-zaman-mysql: OK\n";
