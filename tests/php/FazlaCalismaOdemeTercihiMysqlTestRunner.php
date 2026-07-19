<?php

declare(strict_types=1);

/**
 * MariaDB HTTP + persistence acceptance for fazla calisma odeme tercihi (S79-C).
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\FazlaCalismaOdemeTercihiController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function fcotAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function fcotPdo(string $dsn): PDO
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
function spawnFcotHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    setConnectionPdo($pdo);
    resetAuthUser($user);

    $statusFile = tempnam(sys_get_temp_dir(), 'fcot_http_');
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
function finishFcotHttp(array $child): array
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
function invokeFcotHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    return finishFcotHttp(spawnFcotHttp($pdo, $user, $method, $path, $body, $headers, $query));
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

    if ($method === 'GET' && $path === '/fazla-calisma-odeme-tercihi') {
        FazlaCalismaOdemeTercihiController::get($request);
    }
    if ($method === 'PUT' && $path === '/fazla-calisma-odeme-tercihi') {
        FazlaCalismaOdemeTercihiController::put($request);
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

function applyHkMigration(PDO $pdo): void
{
    applySqlFile($pdo, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
}

function applyFcotMigration(PDO $pdo): void
{
    applySqlFile($pdo, __DIR__ . '/../../api/migrations/028_fazla_calisma_odeme_tercihleri.sql');
}

function createFcotParentTables(PDO $pdo): void
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
    // Minimal SZ events table for guard tests (product migration not yet present).
    $pdo->exec("
        CREATE TABLE serbest_zaman_events (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          event_tipi VARCHAR(64) NOT NULL,
          kaynak_odeme_tercihi_id INT UNSIGNED NULL,
          hedef_event_id INT UNSIGNED NULL,
          personel_id INT UNSIGNED NOT NULL DEFAULT 0,
          dakika INT UNSIGNED NOT NULL DEFAULT 0,
          event_tarihi DATE NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function seedFcotFixtures(PDO $pdo): void
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
    int $fazla = 120
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

function assertFcotSchemaPostconditions(PDO $pdo): void
{
    foreach (['fazla_calisma_odeme_tercihleri', 'fazla_calisma_odeme_tercihi_audit'] as $table) {
        $create = (string) $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC)['Create Table'];
        fcotAssert(stripos($create, 'CREATE TABLE `' . $table . '`') !== false, 'SHOW CREATE TABLE ' . $table);
        fcotAssert(stripos($create, 'utf8mb4') !== false, $table . ' charset utf8mb4');
        fcotAssert(stripos($create, 'utf8mb4_unicode_ci') !== false, $table . ' collation unicode_ci');
        echo '[SCHEMA] ' . $table . ' CREATE: ' . preg_replace('/\s+/', ' ', $create) . PHP_EOL;

        $cols = $pdo->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        fcotAssert(count($cols) > 0, 'SHOW FULL COLUMNS ' . $table);
        echo '[SCHEMA] ' . $table . ' COLUMNS: ' . count($cols) . PHP_EOL;

        $indexes = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        fcotAssert(count($indexes) > 0, 'SHOW INDEX ' . $table);
    }

    $mainCreate = (string) $pdo->query('SHOW CREATE TABLE fazla_calisma_odeme_tercihleri')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    fcotAssert(stripos($mainCreate, 'uq_fcot_snapshot') !== false, 'unique(snapshot_id) present');
    fcotAssert(stripos($mainCreate, 'PRIMARY KEY') !== false, 'main PK present');

    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('fazla_calisma_odeme_tercihleri', 'fazla_calisma_odeme_tercihi_audit')
        ORDER BY TABLE_NAME, CONSTRAINT_NAME
    ")->fetchAll(PDO::FETCH_ASSOC);
    fcotAssert(count($fks) >= 6, 'FK count >= 6');
    foreach ($fks as $fk) {
        fcotAssert(
            in_array((string) $fk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true),
            'FK ' . $fk['CONSTRAINT_NAME'] . ' DELETE_RULE RESTRICT/NO ACTION'
        );
        echo '[SCHEMA] FK ' . $fk['CONSTRAINT_NAME'] . ' → ' . $fk['REFERENCED_TABLE_NAME']
            . ' DELETE=' . $fk['DELETE_RULE'] . PHP_EOL;
    }
}

function bootstrapFcotSchema(PDO $pdo): string
{
    $suffix = bin2hex(random_bytes(4));
    $dbName = 'fcot_s79c_' . $suffix;
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $dbName . '`');
    createFcotParentTables($pdo);
    applyHkMigration($pdo);
    applyFcotMigration($pdo);
    seedFcotFixtures($pdo);

    return $dbName;
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/FazlaCalismaOdemeTercihiController.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/028_fazla_calisma_odeme_tercihleri.sql');
$permissionsSource = (string) file_get_contents(__DIR__ . '/../../api/src/Auth/RolePermissions.php');

fcotAssert(strpos($routerSource, 'FazlaCalismaOdemeTercihiController::get') !== false, 'router GET fcot');
fcotAssert(strpos($routerSource, 'FazlaCalismaOdemeTercihiController::put') !== false, 'router PUT fcot');
fcotAssert(strpos($controllerSource, "puantaj.view") !== false, 'GET permission puantaj.view');
fcotAssert(strpos($controllerSource, "puantaj.muhurle") !== false, 'PUT permission puantaj.muhurle');
fcotAssert(strpos($permissionsSource, "'puantaj.view'") !== false, 'RolePermissions has puantaj.view');
fcotAssert(strpos($permissionsSource, "'puantaj.muhurle'") !== false, 'RolePermissions has puantaj.muhurle');
fcotAssert(preg_match('/CREATE TABLE\s+fazla_calisma_odeme_tercihleri\s*\(/i', $migrationSource) === 1, 'migration main table');
fcotAssert(preg_match('/CREATE TABLE\s+fazla_calisma_odeme_tercihi_audit\s*\(/i', $migrationSource) === 1, 'migration audit table');
fcotAssert(stripos($migrationSource, 'CREATE TABLE IF NOT EXISTS') === false, 'migration no IF NOT EXISTS');
fcotAssert(stripos($migrationSource, 'DROP ') === false, 'migration no DROP');
fcotAssert(preg_match('/\bDELETE\s+FROM\b/i', $migrationSource) !== 1, 'migration no DELETE FROM');
fcotAssert(preg_match('/(?:^|;)\s*UPDATE\b/im', $migrationSource) !== 1, 'migration no UPDATE statement');
fcotAssert(stripos($migrationSource, 'ON DELETE RESTRICT') !== false, 'migration FK ON DELETE RESTRICT');
fcotAssert(stripos($migrationSource, 'uq_fcot_snapshot') !== false, 'migration unique snapshot');

// Partial existing table must fail loudly.
$partialRoot = fcotPdo($dsn);
$partialDb = 'fcot_partial_' . bin2hex(random_bytes(3));
$partialRoot->exec('CREATE DATABASE `' . $partialDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$partialRoot->exec('USE `' . $partialDb . '`');
createFcotParentTables($partialRoot);
applyHkMigration($partialRoot);
$partialRoot->exec('CREATE TABLE fazla_calisma_odeme_tercihleri (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$partialFailed = false;
try {
    applyFcotMigration($partialRoot);
} catch (Throwable $e) {
    $partialFailed = true;
}
fcotAssert($partialFailed, 'partial existing fazla_calisma_odeme_tercihleri → migration fails');
$partialRoot->exec('DROP DATABASE `' . $partialDb . '`');

$root = fcotPdo($dsn);
$dbName = bootstrapFcotSchema($root);
$pdo = fcotPdo(preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn));
assertFcotSchemaPostconditions($pdo);

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$ba = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
$patron = ['id' => 3, 'rol' => 'PATRON', 'sube_ids' => []];
$muhasebe = ['id' => 4, 'rol' => 'MUHASEBE', 'sube_ids' => [1]];
$bolum = ['id' => 5, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];
$subeHeader = ['x-active-sube-id' => '1'];

$seed = seedSnapshot($pdo, 1, 10, '2026-04-06', '2026-04-12', 180);
$snapshotId = $seed['snapshot_id'];
$seedOut = seedSnapshot($pdo, 2, 20, '2026-04-06', '2026-04-12', 90);
$snapshotOut = $seedOut['snapshot_id'];

$countBeforeGet = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri')->fetchColumn();
$auditBeforeGet = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit')->fetchColumn();
$getDefault = invokeFcotHttp($pdo, $gy, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => (string) $snapshotId,
]);
fcotAssert($getDefault['status'] === 200, 'GET default no-write → 200');
$dataDefault = $getDefault['payload']['data'] ?? [];
fcotAssert(($dataDefault['odeme_tipi'] ?? '') === 'KARAR_BEKLIYOR', 'GET default KARAR_BEKLIYOR');
fcotAssert(array_key_exists('id', $dataDefault) && $dataDefault['id'] === null, 'GET synthetic id=null');
fcotAssert(array_key_exists('onceki_odeme_tipi', $dataDefault) && $dataDefault['onceki_odeme_tipi'] === null, 'GET synthetic onceki=null');
fcotAssert(array_key_exists('secen_kullanici_id', $dataDefault) && $dataDefault['secen_kullanici_id'] === null, 'GET synthetic secen=null');
fcotAssert(array_key_exists('secim_zamani', $dataDefault) && $dataDefault['secim_zamani'] === null, 'GET synthetic secim_zamani=null');
fcotAssert(array_key_exists('gerekce', $dataDefault) && $dataDefault['gerekce'] === null, 'GET synthetic gerekce=null');
$countAfterGet = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri')->fetchColumn();
$auditAfterGet = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit')->fetchColumn();
fcotAssert($countBeforeGet === $countAfterGet, 'GET default no-write');
fcotAssert($auditBeforeGet === $auditAfterGet, 'GET default no-write audit');

$missing = invokeFcotHttp($pdo, $gy, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => '999999',
]);
fcotAssert($missing['status'] === 404, 'snapshot 404');

$unauthGet = invokeFcotHttp($pdo, null, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => (string) $snapshotId,
]);
fcotAssert($unauthGet['status'] === 401, 'unauthenticated GET → 401');
$unauthPut = invokeFcotHttp($pdo, null, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapshotId,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($unauthPut['status'] === 401, 'unauthenticated PUT → 401');

$patronGet = invokeFcotHttp($pdo, $patron, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => (string) $snapshotId,
]);
fcotAssert($patronGet['status'] === 403, 'PATRON GET → 403');
$patronPut = invokeFcotHttp($pdo, $patron, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapshotId,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($patronPut['status'] === 403, 'PATRON PUT → 403');

$gyGet = invokeFcotHttp($pdo, $gy, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => (string) $snapshotId,
]);
fcotAssert($gyGet['status'] === 200, 'GENEL_YONETICI GET → 200');

$muhGet = invokeFcotHttp($pdo, $muhasebe, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => (string) $snapshotId,
]);
fcotAssert($muhGet['status'] === 200, 'MUHASEBE GET scope içi → 200');
$muhPut = invokeFcotHttp($pdo, $muhasebe, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapshotId,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($muhPut['status'] === 403, 'MUHASEBE PUT → 403');

$baGet = invokeFcotHttp($pdo, $ba, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => (string) $snapshotId,
]);
fcotAssert($baGet['status'] === 200, 'BIRIM_AMIRI GET scope içi → 200');
$baPut = invokeFcotHttp($pdo, $ba, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapshotId,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($baPut['status'] === 403, 'BIRIM_AMIRI PUT → 403');

$baEmpty = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => []];
$baEmptyGet = invokeFcotHttp($pdo, $baEmpty, 'GET', '/fazla-calisma-odeme-tercihi', [], [], [
    'snapshot_id' => (string) $snapshotId,
]);
fcotAssert($baEmptyGet['status'] === 403, 'BA empty allowedSubeIds global GET → 403');

$baOut = invokeFcotHttp($pdo, $ba, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => (string) $snapshotOut,
]);
fcotAssert($baOut['status'] === 403, 'scope dışı 403');
$baOutPut = invokeFcotHttp($pdo, $ba, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapshotOut,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($baOutPut['status'] === 403, 'scope dışı PUT → 403');

$bolumPut = invokeFcotHttp($pdo, $bolum, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapshotId,
    'odeme_tipi' => 'UCRET',
    'gerekce' => 'Bolum karari',
], $subeHeader);
fcotAssert($bolumPut['status'] === 200, 'BOLUM_YONETICISI PUT scope içi → 200');

// KAPANDI weekly close alone is not a PUT blocker (seed snapshots are KAPANDI).
fcotAssert(
    (string) $pdo->query('SELECT state FROM haftalik_kapanislar WHERE id = ' . (int) $seed['kapanis_id'])->fetchColumn() === 'KAPANDI',
    'haftalik kapanis KAPANDI alone not blocker (precondition)'
);

// Reset for GY insert path on a fresh snapshot.
$seed2 = seedSnapshot($pdo, 1, 10, '2026-04-13', '2026-04-19', 200);
$snap2 = $seed2['snapshot_id'];

$putInsert = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'SERBEST_ZAMAN',
    'gerekce' => 'Ilk secim',
], $subeHeader);
fcotAssert($putInsert['status'] === 200, 'PUT insert');
fcotAssert(($putInsert['payload']['data']['odeme_tipi'] ?? '') === 'SERBEST_ZAMAN', 'PUT insert odeme_tipi');
$tercihId = (int) ($putInsert['payload']['data']['id'] ?? 0);
fcotAssert($tercihId > 0, 'PUT insert returns id');
fcotAssert((int) ($putInsert['payload']['data']['secen_kullanici_id'] ?? 0) === 1, 'secen_kullanici_id from auth');

$getPersisted = invokeFcotHttp($pdo, $gy, 'GET', '/fazla-calisma-odeme-tercihi', [], $subeHeader, [
    'snapshot_id' => (string) $snap2,
]);
fcotAssert($getPersisted['status'] === 200, 'GET persisted');
fcotAssert((int) ($getPersisted['payload']['data']['id'] ?? 0) === $tercihId, 'GET persisted id');

$rowBefore = $pdo->query('SELECT updated_at, odeme_tipi, gerekce FROM fazla_calisma_odeme_tercihleri WHERE id = ' . $tercihId)->fetch(PDO::FETCH_ASSOC);
$auditBefore = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit WHERE tercih_id = ' . $tercihId)->fetchColumn();
fcotAssert($auditBefore === 1, 'audit append on insert');
$firstAudit = $pdo->query('SELECT onceki_odeme_tipi, yeni_odeme_tipi, secen_kullanici_id, gerekce FROM fazla_calisma_odeme_tercihi_audit WHERE tercih_id = ' . $tercihId)->fetch(PDO::FETCH_ASSOC);
fcotAssert((string) ($firstAudit['onceki_odeme_tipi'] ?? '') === 'KARAR_BEKLIYOR', 'audit zinciri tutarlı (ilk onceki=KARAR_BEKLIYOR)');
fcotAssert((string) ($firstAudit['yeni_odeme_tipi'] ?? '') === 'SERBEST_ZAMAN', 'audit ilk yeni=SERBEST_ZAMAN');
fcotAssert((int) ($firstAudit['secen_kullanici_id'] ?? 0) === 1, 'audit secen_kullanici_id (=degistiren)');

$putIdem = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'SERBEST_ZAMAN',
], $subeHeader);
fcotAssert($putIdem['status'] === 200, 'aynı payload idempotent');
$rowAfterIdem = $pdo->query('SELECT updated_at, odeme_tipi FROM fazla_calisma_odeme_tercihleri WHERE id = ' . $tercihId)->fetch(PDO::FETCH_ASSOC);
$auditAfterIdem = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit WHERE tercih_id = ' . $tercihId)->fetchColumn();
fcotAssert((string) $rowBefore['updated_at'] === (string) $rowAfterIdem['updated_at'], 'idempotent updated_at unchanged');
fcotAssert($auditAfterIdem === $auditBefore, 'audit no-op üretmiyor');

// Same odeme_tipi + different gerekce is NOT a real change (locked contract).
$putGerekceOnly = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'SERBEST_ZAMAN',
    'gerekce' => 'Farkli gerekce ama ayni tercih',
], $subeHeader);
fcotAssert($putGerekceOnly['status'] === 200, 'gerekce-only idempotent → 200');
$rowAfterGerekce = $pdo->query('SELECT updated_at, gerekce FROM fazla_calisma_odeme_tercihleri WHERE id = ' . $tercihId)->fetch(PDO::FETCH_ASSOC);
$auditAfterGerekce = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit WHERE tercih_id = ' . $tercihId)->fetchColumn();
fcotAssert((string) $rowBefore['updated_at'] === (string) $rowAfterGerekce['updated_at'], 'gerekce-only updated_at unchanged');
fcotAssert((string) ($rowAfterGerekce['gerekce'] ?? '') === (string) ($rowBefore['gerekce'] ?? ''), 'gerekce-only row gerekce unchanged');
fcotAssert($auditAfterGerekce === $auditBefore, 'gerekce-only audit +0');

// Period lock wins over SZ guard when both would apply.
$pdo->exec("INSERT INTO serbest_zaman_events (event_tipi, kaynak_odeme_tercihi_id, personel_id, dakika)
            VALUES ('SERBEST_ZAMAN_OLUSUM', {$tercihId}, 10, 200)");
$pdo->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, created_by)
            VALUES (1, 2026, 4, '2026-04', 'MUHURLENDI', 1)");
$periodBeforeSz = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($periodBeforeSz['status'] === 409, 'period before SZ → 409');
fcotAssert(($periodBeforeSz['payload']['errors'][0]['code'] ?? '') === 'PERIOD_LOCKED', 'period before SZ → PERIOD_LOCKED');
// Unseal April for remaining tests on snap2 week.
$pdo->exec('DELETE FROM puantaj_aylik_muhurleri WHERE sube_id = 1 AND yil = 2026 AND ay = 4');

// SZ guard: active OLUSUM blocks leaving SERBEST_ZAMAN
$szGuard = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($szGuard['status'] === 409, 'aktif SZ oluşum guard 409');
fcotAssert(($szGuard['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT', 'SZ guard STATE_CONFLICT');

$szGuardKb = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'KARAR_BEKLIYOR',
], $subeHeader);
fcotAssert($szGuardKb['status'] === 409, 'SZ → KARAR_BEKLIYOR guard 409');

// Cancel SZ then allow update
$olusumId = (int) $pdo->query('SELECT id FROM serbest_zaman_events WHERE kaynak_odeme_tercihi_id = ' . $tercihId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
$pdo->exec("INSERT INTO serbest_zaman_events (event_tipi, hedef_event_id, personel_id)
            VALUES ('SERBEST_ZAMAN_IPTAL', {$olusumId}, 10)");

$putUpdate = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'UCRET',
    'gerekce' => 'SZ iptal sonrasi',
], $subeHeader);
fcotAssert($putUpdate['status'] === 200, 'PUT gerçek update');
fcotAssert(($putUpdate['payload']['data']['odeme_tipi'] ?? '') === 'UCRET', 'PUT update odeme_tipi');
fcotAssert(($putUpdate['payload']['data']['onceki_odeme_tipi'] ?? '') === 'SERBEST_ZAMAN', 'PUT update onceki');
$auditAfterUpdate = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit WHERE tercih_id = ' . $tercihId)->fetchColumn();
fcotAssert($auditAfterUpdate === 2, 'audit append on real update');

// UCRET → SERBEST_ZAMAN allowed when no active olusum
$putBackSz = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'SERBEST_ZAMAN',
], $subeHeader);
fcotAssert($putBackSz['status'] === 200, 'UCRET → SERBEST_ZAMAN allowed');

$mainCount = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri WHERE snapshot_id = ' . $snap2)->fetchColumn();
fcotAssert($mainCount === 1, 'tek ana kayıt');

$serverOwned = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'KARAR_BEKLIYOR',
    'secen_kullanici_id' => 99,
], $subeHeader);
fcotAssert($serverOwned['status'] === 422, 'server-owned override 422');
fcotAssert(($serverOwned['payload']['errors'][0]['code'] ?? '') === 'VALIDATION_ERROR', 'server-owned VALIDATION_ERROR');

$badEnum = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snap2,
    'odeme_tipi' => 'INVALID',
], $subeHeader);
fcotAssert($badEnum['status'] === 422, 'gecersiz enum 422');

// Period locked (May)
$seedLocked = seedSnapshot($pdo, 1, 10, '2026-05-04', '2026-05-10', 50);
$snapLocked = $seedLocked['snapshot_id'];
$pdo->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, created_by)
            VALUES (1, 2026, 5, '2026-05', 'MUHURLENDI', 1)");
$periodLocked = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapLocked,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($periodLocked['status'] === 409, 'period locked 409');
fcotAssert(($periodLocked['payload']['errors'][0]['code'] ?? '') === 'PERIOD_LOCKED', 'PERIOD_LOCKED code');

// Cross-month week Apr 27–May 3: April open, May locked → PERIOD_LOCKED
$seedCrossMay = seedSnapshot($pdo, 1, 10, '2026-04-27', '2026-05-03', 40);
$crossMay = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $seedCrossMay['snapshot_id'],
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($crossMay['status'] === 409, 'cross-month first open second locked → PERIOD_LOCKED');

// Cross-month: June sealed, July open on 2026-06-29..2026-07-05
$pdo->exec('DELETE FROM puantaj_aylik_muhurleri WHERE sube_id = 1 AND yil = 2026 AND ay = 5');
$pdo->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, created_by)
            VALUES (1, 2026, 6, '2026-06', 'MUHURLENDI', 1)");
$seedCrossJunJul = seedSnapshot($pdo, 1, 10, '2026-06-29', '2026-07-05', 43);
$crossJunJul = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $seedCrossJunJul['snapshot_id'],
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($crossJunJul['status'] === 409, 'cross-month first locked second open → PERIOD_LOCKED');

// Both months open → PUT continues
$pdo->exec('DELETE FROM puantaj_aylik_muhurleri WHERE sube_id = 1 AND yil = 2026 AND ay = 6');
$seedBothOpen = seedSnapshot($pdo, 1, 10, '2026-07-27', '2026-08-02', 44);
$bothOpen = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $seedBothOpen['snapshot_id'],
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($bothOpen['status'] === 200, 'cross-month both open → PUT 200');

// Period unknown: drop period tables on isolated DB
$unknownRoot = fcotPdo($dsn);
$unknownDb = 'fcot_unknown_' . bin2hex(random_bytes(3));
$unknownRoot->exec('CREATE DATABASE `' . $unknownDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$unknownRoot->exec('USE `' . $unknownDb . '`');
createFcotParentTables($unknownRoot);
applyHkMigration($unknownRoot);
applyFcotMigration($unknownRoot);
seedFcotFixtures($unknownRoot);
$unknownSeed = seedSnapshot($unknownRoot, 1, 10, '2026-04-06', '2026-04-12', 10);
$unknownRoot->exec('DROP TABLE puantaj_aylik_muhurleri');
$unknownRoot->exec('DROP TABLE puantaj_donem_kilitleri');
$unknownPdo = fcotPdo(preg_replace('/dbname=[^;]+/', 'dbname=' . $unknownDb, $dsn));
$periodUnknown = invokeFcotHttp($unknownPdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $unknownSeed['snapshot_id'],
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($periodUnknown['status'] === 409, 'period unknown 409');
fcotAssert(($periodUnknown['payload']['errors'][0]['code'] ?? '') === 'PERIOD_STATE_UNKNOWN', 'PERIOD_STATE_UNKNOWN code');
$unknownRoot->exec('DROP DATABASE `' . $unknownDb . '`');

// Parallel PUT competing tipileri → tek ana satır
$seedPar = seedSnapshot($pdo, 1, 10, '2026-08-03', '2026-08-09', 70);
$snapPar = $seedPar['snapshot_id'];
$p1 = spawnFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapPar,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
$p2 = spawnFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapPar,
    'odeme_tipi' => 'SERBEST_ZAMAN',
], $subeHeader);
$r1 = finishFcotHttp($p1);
$r2 = finishFcotHttp($p2);
fcotAssert(
    ($r1['status'] === 200 || $r1['status'] === 409) && ($r2['status'] === 200 || $r2['status'] === 409),
    'parallel PUT completes'
);
$parCount = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri WHERE snapshot_id = ' . $snapPar)->fetchColumn();
fcotAssert($parCount === 1, 'parallel PUT tek ana kayıt');
$okCount = (($r1['status'] === 200) ? 1 : 0) + (($r2['status'] === 200) ? 1 : 0);
fcotAssert($okCount >= 1, 'parallel PUT at least one 200');
$parAudit = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit a
    INNER JOIN fazla_calisma_odeme_tercihleri t ON t.id = a.tercih_id
    WHERE t.snapshot_id = ' . $snapPar)->fetchColumn();
fcotAssert($parAudit >= 1, 'parallel PUT audit zinciri mevcut');
$orphanAudit = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit a
    LEFT JOIN fazla_calisma_odeme_tercihleri t ON t.id = a.tercih_id
    WHERE t.id IS NULL')->fetchColumn();
fcotAssert($orphanAudit === 0, 'parallel PUT orphan audit yok');

// Parallel same payload
$seedParSame = seedSnapshot($pdo, 1, 10, '2026-08-10', '2026-08-16', 71);
$snapParSame = $seedParSame['snapshot_id'];
$ps1 = spawnFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapParSame,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
$ps2 = spawnFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $snapParSame,
    'odeme_tipi' => 'UCRET',
], $subeHeader);
$rs1 = finishFcotHttp($ps1);
$rs2 = finishFcotHttp($ps2);
fcotAssert($rs1['status'] === 200 && $rs2['status'] === 200, 'parallel same payload both 200');
fcotAssert(
    (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri WHERE snapshot_id = ' . $snapParSame)->fetchColumn() === 1,
    'parallel same payload tek ana kayıt'
);
$sameAudit = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit a
    INNER JOIN fazla_calisma_odeme_tercihleri t ON t.id = a.tercih_id
    WHERE t.snapshot_id = ' . $snapParSame)->fetchColumn();
fcotAssert($sameAudit === 1, 'parallel same payload audit=1');

// Audit fail after insert → full rollback (trigger injection)
$seedAuditFail = seedSnapshot($pdo, 1, 10, '2026-08-17', '2026-08-23', 72);
$beforeAf = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri')->fetchColumn();
$beforeAfAudit = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit')->fetchColumn();
$pdo->exec("
    CREATE TRIGGER trg_fcot_audit_fail BEFORE INSERT ON fazla_calisma_odeme_tercihi_audit
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced audit fail'
");
$afFailed = false;
try {
    invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
        'snapshot_id' => $seedAuditFail['snapshot_id'],
        'odeme_tipi' => 'UCRET',
    ], $subeHeader);
} catch (Throwable $e) {
    $afFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_fcot_audit_fail');
$afterAf = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri')->fetchColumn();
$afterAfAudit = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit')->fetchColumn();
fcotAssert($afFailed || $beforeAf === $afterAf, 'audit fail rollback (no partial main)');
fcotAssert($beforeAf === $afterAf, 'transaction rollback audit-fail main');
fcotAssert($beforeAfAudit === $afterAfAudit, 'transaction rollback audit-fail audit');

// Period locked rollback path (no partial write)
$beforeRollback = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri')->fetchColumn();
$beforeAudit = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit')->fetchColumn();
$seedRb = seedSnapshot($pdo, 1, 10, '2026-09-07', '2026-09-13', 33);
$pdo->exec("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, created_by)
            VALUES (1, 2026, 9, '2026-09', 'MUHURLENDI', 1)");
$rb = invokeFcotHttp($pdo, $gy, 'PUT', '/fazla-calisma-odeme-tercihi', [
    'snapshot_id' => $seedRb['snapshot_id'],
    'odeme_tipi' => 'UCRET',
], $subeHeader);
fcotAssert($rb['status'] === 409, 'transaction rollback path → 409');
$afterRollback = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihleri')->fetchColumn();
$afterAudit = (int) $pdo->query('SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit')->fetchColumn();
fcotAssert($beforeRollback === $afterRollback, 'transaction rollback');
fcotAssert($beforeAudit === $afterAudit, 'transaction rollback no audit');

$fk = $pdo->query("
    SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'fazla_calisma_odeme_tercihleri'
      AND CONSTRAINT_NAME = 'fk_fcot_snapshot'
")->fetch(PDO::FETCH_ASSOC);
fcotAssert(is_array($fk) && in_array((string) $fk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true), 'FK DELETE_RULE RESTRICT');

echo "verify-fazla-calisma-odeme-tercihi-mysql: OK\n";
