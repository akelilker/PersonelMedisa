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

        $cols = $pdo->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            echo '[SCHEMA] COL ' . $table . '.' . $col['Field']
                . ' type=' . $col['Type']
                . ' null=' . $col['Null']
                . ' default=' . var_export($col['Default'], true)
                . ' key=' . $col['Key']
                . PHP_EOL;
        }
        $indexes = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $idx) {
            echo '[SCHEMA] IDX ' . $table . '.' . $idx['Key_name']
                . ' col=' . $idx['Column_name']
                . ' unique=' . (((int) $idx['Non_unique'] === 0) ? '1' : '0')
                . PHP_EOL;
        }
    }

    $eventsCreate = (string) $pdo->query('SHOW CREATE TABLE serbest_zaman_events')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    szAssert(stripos($eventsCreate, 'uq_sz_personel_islem_anahtari') !== false, 'uq_sz_personel_islem_anahtari present');
    szAssert(stripos($eventsCreate, 'uq_sz_iptal_hedef') !== false, 'uq_sz_iptal_hedef present');
    szAssert(stripos($eventsCreate, 'PRIMARY KEY') !== false, 'events PK present');

    $guardCreate = (string) $pdo->query('SHOW CREATE TABLE serbest_zaman_aktif_olusumlar')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    szAssert(stripos($guardCreate, 'PRIMARY KEY') !== false, 'aktif olusum PK present');
    szAssert(stripos($guardCreate, 'uq_sz_aktif_olusum_event') !== false, 'aktif olusum event unique present');

    $requiredEventCols = [
        'id', 'personel_id', 'event_tipi', 'dakika', 'yeni_dakika', 'event_tarihi',
        'son_kullanim_tarihi', 'kaynak_snapshot_id', 'kaynak_odeme_tercihi_id',
        'hedef_event_id', 'hedef_event_tipi', 'islem_anahtari', 'aciklama',
        'donem_yil', 'donem_ay', 'donem_kilitli_miydi', 'created_by', 'created_at',
    ];
    $eventColNames = array_column(
        $pdo->query('SHOW FULL COLUMNS FROM serbest_zaman_events')->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    foreach ($requiredEventCols as $col) {
        szAssert(in_array($col, $eventColNames, true), 'events column ' . $col);
    }
    $guardColNames = array_column(
        $pdo->query('SHOW FULL COLUMNS FROM serbest_zaman_aktif_olusumlar')->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    foreach (['odeme_tercihi_id', 'olusum_event_id', 'created_at'] as $col) {
        szAssert(in_array($col, $guardColNames, true), 'aktif olusum column ' . $col);
    }

    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('serbest_zaman_events', 'serbest_zaman_aktif_olusumlar')
        ORDER BY TABLE_NAME, CONSTRAINT_NAME
    ")->fetchAll(PDO::FETCH_ASSOC);
    szAssert(count($fks) >= 7, 'SZ FK count >= 7');
    foreach ($fks as $fk) {
        szAssert(
            in_array((string) $fk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true),
            'FK ' . $fk['CONSTRAINT_NAME'] . ' DELETE_RULE RESTRICT/NO ACTION'
        );
        echo '[SCHEMA] FK ' . $fk['CONSTRAINT_NAME'] . ' → ' . $fk['REFERENCED_TABLE_NAME']
            . ' DELETE=' . $fk['DELETE_RULE'] . ' UPDATE=' . $fk['UPDATE_RULE'] . PHP_EOL;
    }
}

/**
 * @return int tercih id
 */
function seedSzTercih(
    PDO $pdo,
    int $snapshotId,
    int $kapanisId,
    int $personelId,
    string $haftaBaslangic,
    string $haftaBitis,
    int $fazla,
    string $odemeTipi = 'SERBEST_ZAMAN'
): int {
    $ins = $pdo->prepare('
        INSERT INTO fazla_calisma_odeme_tercihleri (
          snapshot_id, kapanis_id, personel_id, hafta_baslangic, hafta_bitis,
          fazla_calisma_dakika, odeme_tipi, secim_zamani, secen_kullanici_id, onceki_odeme_tipi
        ) VALUES (
          :snapshot_id, :kapanis_id, :personel_id, :hafta_baslangic, :hafta_bitis,
          :fazla, :odeme_tipi, :secim_zamani, 1, \'KARAR_BEKLIYOR\'
        )
    ');
    $ins->execute([
        'snapshot_id' => $snapshotId,
        'kapanis_id' => $kapanisId,
        'personel_id' => $personelId,
        'hafta_baslangic' => $haftaBaslangic,
        'hafta_bitis' => $haftaBitis,
        'fazla' => $fazla,
        'odeme_tipi' => $odemeTipi,
        'secim_zamani' => $haftaBitis . ' 12:00:00',
    ]);

    return (int) $pdo->lastInsertId();
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
szAssert(preg_match('/\bDROP\s+(TABLE|DATABASE|INDEX)\b/i', $migrationSource) !== 1, 'migration no DROP');
szAssert(preg_match('/\bTRUNCATE\b/i', $migrationSource) !== 1, 'migration no TRUNCATE');
szAssert(preg_match('/\bDELETE\s+FROM\b/i', $migrationSource) !== 1, 'migration no DELETE FROM');
szAssert(preg_match('/(?:^|;)\s*UPDATE\b/im', $migrationSource) !== 1, 'migration no UPDATE');

// Partial existing events table must fail loudly (no IF NOT EXISTS silent success).
$partialRoot = szPdo($dsn);
$partialDb = 'sz_partial_ev_' . bin2hex(random_bytes(3));
$partialRoot->exec('CREATE DATABASE `' . $partialDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$partialRoot->exec('USE `' . $partialDb . '`');
createSzParentTables($partialRoot);
applySqlFile($partialRoot, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
applySqlFile($partialRoot, __DIR__ . '/../../api/migrations/028_fazla_calisma_odeme_tercihleri.sql');
$partialRoot->exec('CREATE TABLE serbest_zaman_events (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$partialEventsFailed = false;
try {
    applySqlFile($partialRoot, __DIR__ . '/../../api/migrations/029_serbest_zaman_events.sql');
} catch (Throwable $e) {
    $partialEventsFailed = true;
}
szAssert($partialEventsFailed, 'partial existing serbest_zaman_events → migration fails');
$partialRoot->exec('DROP DATABASE `' . $partialDb . '`');

// Partial existing guard table must fail loudly.
$partialGuardRoot = szPdo($dsn);
$partialGuardDb = 'sz_partial_g_' . bin2hex(random_bytes(3));
$partialGuardRoot->exec('CREATE DATABASE `' . $partialGuardDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$partialGuardRoot->exec('USE `' . $partialGuardDb . '`');
createSzParentTables($partialGuardRoot);
applySqlFile($partialGuardRoot, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
applySqlFile($partialGuardRoot, __DIR__ . '/../../api/migrations/028_fazla_calisma_odeme_tercihleri.sql');
$partialGuardRoot->exec('CREATE TABLE serbest_zaman_aktif_olusumlar (odeme_tercihi_id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$partialGuardFailed = false;
try {
    applySqlFile($partialGuardRoot, __DIR__ . '/../../api/migrations/029_serbest_zaman_events.sql');
} catch (Throwable $e) {
    $partialGuardFailed = true;
}
szAssert($partialGuardFailed, 'partial existing serbest_zaman_aktif_olusumlar → migration fails');
$partialGuardRoot->exec('DROP DATABASE `' . $partialGuardDb . '`');

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

// --- S79-D-R1 acceptance hardening matrix ---
$bolum = ['id' => 5, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];

$baWrite = invokeSzHttp($pdo, $ba, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 1,
    'event_tarihi' => '2026-04-24',
    'islem_anahtari' => 'sz-ba-write-forbidden',
], $subeHeader);
szAssert($baWrite['status'] === 403, 'BIRIM_AMIRI POST kullanim → 403');

$patronWrite = invokeSzHttp($pdo, $patron, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => 1,
], $subeHeader);
szAssert($patronWrite['status'] === 403, 'PATRON POST olusum → 403');

$unauthWrite = invokeSzHttp($pdo, null, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => 1,
], $subeHeader);
szAssert($unauthWrite['status'] === 401, 'unauthenticated POST → 401');

$seedBolum = seedSnapshot($pdo, 1, 10, '2026-05-04', '2026-05-10', 40);
$tidBolum = seedSzTercih(
    $pdo,
    $seedBolum['snapshot_id'],
    $seedBolum['kapanis_id'],
    10,
    '2026-05-04',
    '2026-05-10',
    40
);
$bolumOlusum = invokeSzHttp($pdo, $bolum, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidBolum,
], $subeHeader);
szAssert($bolumOlusum['status'] === 200, 'BOLUM_YONETICISI scope içi POST olusum → 200');
$bolumOlusumId = (int) ($bolumOlusum['payload']['data']['id'] ?? 0);

$bolumOut = invokeSzHttp($pdo, $bolum, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '20',
]);
szAssert($bolumOut['status'] === 403, 'BOLUM_YONETICISI scope dışı GET → 403');

$bolumOutWrite = invokeSzHttp($pdo, $bolum, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 20,
    'dakika' => 1,
    'event_tarihi' => '2026-05-11',
    'islem_anahtari' => 'sz-bolum-out-write',
], $subeHeader);
szAssert($bolumOutWrite['status'] === 403, 'BOLUM_YONETICISI scope dışı POST → 403');

$missingPersonel = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '99999',
]);
szAssert($missingPersonel['status'] === 404, 'GET events olmayan personel → 404');

$beforeGetCount = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
$getNoWrite = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/events', [], $subeHeader, [
    'personel_id' => '10',
]);
$bakiyeNoWrite = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
    'personel_id' => '10',
]);
$afterGetCount = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
szAssert($getNoWrite['status'] === 200 && $bakiyeNoWrite['status'] === 200, 'GET events/bakiye → 200');
szAssert($beforeGetCount === $afterGetCount, 'GET no-write (event count unchanged)');
$leak = false;
foreach (($getNoWrite['payload']['data']['items'] ?? []) as $item) {
    if (array_key_exists('iptal_hedef_key', $item) || array_key_exists('created_by', $item)) {
        $leak = true;
        break;
    }
}
szAssert(!$leak, 'GET events no internal field leak');

$notPersisted = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => 999999,
], $subeHeader);
szAssert($notPersisted['status'] === 409, 'olusum missing FCOT → 409');
szAssert(($notPersisted['payload']['errors'][0]['code'] ?? '') === 'NOT_PERSISTED', 'NOT_PERSISTED');

$seedKb = seedSnapshot($pdo, 1, 10, '2026-05-11', '2026-05-17', 50);
$tidKb = seedSzTercih(
    $pdo,
    $seedKb['snapshot_id'],
    $seedKb['kapanis_id'],
    10,
    '2026-05-11',
    '2026-05-17',
    50,
    'KARAR_BEKLIYOR'
);
$kbOlusum = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidKb,
], $subeHeader);
szAssert($kbOlusum['status'] === 409, 'olusum KARAR_BEKLIYOR → 409');
szAssert(($kbOlusum['payload']['errors'][0]['code'] ?? '') === 'NOT_ELIGIBLE', 'KARAR_BEKLIYOR NOT_ELIGIBLE');

$seedUcret = seedSnapshot($pdo, 1, 10, '2026-05-18', '2026-05-24', 50);
$tidUcret = seedSzTercih(
    $pdo,
    $seedUcret['snapshot_id'],
    $seedUcret['kapanis_id'],
    10,
    '2026-05-18',
    '2026-05-24',
    50,
    'UCRET'
);
$ucretOlusum = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidUcret,
], $subeHeader);
szAssert($ucretOlusum['status'] === 409, 'olusum UCRET → 409');
szAssert(($ucretOlusum['payload']['errors'][0]['code'] ?? '') === 'NOT_ELIGIBLE', 'UCRET NOT_ELIGIBLE');

$seedZero = seedSnapshot($pdo, 1, 10, '2026-05-25', '2026-05-31', 0);
$tidZero = seedSzTercih(
    $pdo,
    $seedZero['snapshot_id'],
    $seedZero['kapanis_id'],
    10,
    '2026-05-25',
    '2026-05-31',
    0
);
$zeroOlusum = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidZero,
], $subeHeader);
szAssert($zeroOlusum['status'] === 422, 'olusum ZERO_DAKIKA → 422');
szAssert(($zeroOlusum['payload']['errors'][0]['code'] ?? '') === 'ZERO_DAKIKA', 'ZERO_DAKIKA');

$olusumOverride = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidBolum,
    'dakika' => 999,
], $subeHeader);
szAssert($olusumOverride['status'] === 422, 'olusum client dakika override → 422 VALIDATION_ERROR');

// KULLANIM iptal restores balance
$kullanimForIptal = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 10,
    'event_tarihi' => '2026-05-12',
    'islem_anahtari' => 'sz-kullanim-for-iptal',
], $subeHeader);
szAssert($kullanimForIptal['status'] === 200, 'kullanim for iptal → 200');
$kullanimForIptalId = (int) ($kullanimForIptal['payload']['data']['id'] ?? 0);
$bakiyeBeforeIptalKul = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
    'personel_id' => '10',
    'referans_tarih' => '2026-05-12',
]);
$kalanBefore = (int) ($bakiyeBeforeIptalKul['payload']['data']['kalan_dakika'] ?? -1);
$iptalKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
    'personel_id' => 10,
    'hedef_event_id' => $kullanimForIptalId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
    'event_tarihi' => '2026-05-13',
    'islem_anahtari' => 'sz-iptal-kullanim-1',
], $subeHeader);
szAssert($iptalKul['status'] === 200, 'POST iptal KULLANIM → 200');
$bakiyeAfterIptalKul = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
    'personel_id' => '10',
    'referans_tarih' => '2026-05-13',
]);
szAssert(
    (int) ($bakiyeAfterIptalKul['payload']['data']['kalan_dakika'] ?? -1) === $kalanBefore + 10,
    'kullanim iptal bakiyeyi geri getirir'
);

$duzeltmeIptalli = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
    'personel_id' => 10,
    'hedef_event_id' => $olusumId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'yeni_dakika' => 50,
    'event_tarihi' => '2026-05-14',
    'islem_anahtari' => 'sz-duzeltme-iptalli',
    'aciklama' => 'iptalli hedef',
], $subeHeader);
szAssert($duzeltmeIptalli['status'] === 409, 'duzeltme iptalli hedef → 409');
szAssert(
    ($duzeltmeIptalli['payload']['errors'][0]['code'] ?? '') === 'TARGET_ALREADY_CANCELLED',
    'TARGET_ALREADY_CANCELLED'
);

$mismatch = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
    'personel_id' => 20,
    'hedef_event_id' => $bolumOlusumId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'event_tarihi' => '2026-05-15',
    'islem_anahtari' => 'sz-mismatch-personel',
], ['x-active-sube-id' => '2']);
szAssert(
    in_array($mismatch['status'], [409, 422], true),
    'hedef event / body personel mismatch reddedilir'
);

$noBalanceSeed = seedSnapshot($pdo, 2, 20, '2026-06-01', '2026-06-07', 20);
$tidNoBal = seedSzTercih(
    $pdo,
    $noBalanceSeed['snapshot_id'],
    $noBalanceSeed['kapanis_id'],
    20,
    '2026-06-01',
    '2026-06-07',
    20
);
// personel 20 has no aktif olusum → NO_ELIGIBLE_BALANCE
$noBal = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 20,
    'dakika' => 5,
    'event_tarihi' => '2026-06-08',
    'islem_anahtari' => 'sz-no-eligible',
], ['x-active-sube-id' => '2']);
szAssert($noBal['status'] === 409, 'kullanim bakiye sifir → 409');
szAssert(($noBal['payload']['errors'][0]['code'] ?? '') === 'NO_ELIGIBLE_BALANCE', 'NO_ELIGIBLE_BALANCE');

// Expired olusum: force son_kullanim_tarihi in past via direct update is not allowed;
// create olusum then query bakiye with far-future referans.
$seedExp = seedSnapshot($pdo, 1, 10, '2026-06-08', '2026-06-14', 20);
$tidExp = seedSzTercih(
    $pdo,
    $seedExp['snapshot_id'],
    $seedExp['kapanis_id'],
    10,
    '2026-06-08',
    '2026-06-14',
    20
);
$expOlusum = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidExp,
], $subeHeader);
szAssert($expOlusum['status'] === 200, 'expired-scenario olusum → 200');
$expId = (int) ($expOlusum['payload']['data']['id'] ?? 0);
$pdo->exec("UPDATE serbest_zaman_events SET son_kullanim_tarihi = '2026-01-01' WHERE id = {$expId}");
$bakiyeExp = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
    'personel_id' => '10',
    'referans_tarih' => '2026-06-15',
]);
szAssert($bakiyeExp['status'] === 200, 'GET bakiye expired referans → 200');
szAssert((int) ($bakiyeExp['payload']['data']['suresi_dolan_dakika'] ?? 0) >= 30, 'suresi_dolan_dakika includes expired olusum');
szAssert((int) ($bakiyeExp['payload']['data']['kalan_dakika'] ?? -1) >= 0, 'negatif bakiye yok');

// Period without lock → write continues, metadata unset/false
$seedOpen = seedSnapshot($pdo, 1, 10, '2026-07-06', '2026-07-12', 30);
$tidOpen = seedSzTercih(
    $pdo,
    $seedOpen['snapshot_id'],
    $seedOpen['kapanis_id'],
    10,
    '2026-07-06',
    '2026-07-12',
    30
);
$openOlusum = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidOpen,
], $subeHeader);
szAssert($openOlusum['status'] === 200, 'donem kaydi yok POST olusum → 200');
szAssert(($openOlusum['payload']['data']['donem_kilitli_miydi'] ?? true) === false, 'donem_kilitli_miydi false when unlocked');
$openOlusumId = (int) ($openOlusum['payload']['data']['id'] ?? 0);

// --- Rollback / failure injection ---
$seedRbGuard = seedSnapshot($pdo, 1, 10, '2026-07-13', '2026-07-19', 30);
$tidRbGuard = seedSzTercih(
    $pdo,
    $seedRbGuard['snapshot_id'],
    $seedRbGuard['kapanis_id'],
    10,
    '2026-07-13',
    '2026-07-19',
    30
);
$beforeRbEvents = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
$beforeRbGuards = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar')->fetchColumn();
$pdo->exec("
    CREATE TRIGGER trg_sz_guard_fail BEFORE INSERT ON serbest_zaman_aktif_olusumlar
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced guard fail'
");
$rbGuardFailed = false;
try {
    invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
        'odeme_tercihi_id' => $tidRbGuard,
    ], $subeHeader);
} catch (Throwable $e) {
    $rbGuardFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_sz_guard_fail');
$afterRbEvents = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
$afterRbGuards = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar')->fetchColumn();
szAssert($rbGuardFailed || $beforeRbEvents === $afterRbEvents, 'guard fail after event insert rolls back');
szAssert($beforeRbEvents === $afterRbEvents, 'rollback: orphan event yok');
szAssert($beforeRbGuards === $afterRbGuards, 'rollback: orphan guard yok');

$pdo->exec("
    CREATE TRIGGER trg_sz_kullanim_fail BEFORE INSERT ON serbest_zaman_events
    FOR EACH ROW
    BEGIN
      IF NEW.event_tipi = 'SERBEST_ZAMAN_KULLANIM' AND NEW.islem_anahtari = 'sz-rb-kullanim-fail' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced kullanim fail';
      END IF;
    END
");
$beforeKul = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
$rbKulFailed = false;
try {
    invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 10,
        'dakika' => 1,
        'event_tarihi' => '2026-07-14',
        'islem_anahtari' => 'sz-rb-kullanim-fail',
    ], $subeHeader);
} catch (Throwable $e) {
    $rbKulFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_sz_kullanim_fail');
$afterKul = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
szAssert($rbKulFailed || $beforeKul === $afterKul, 'kullanim insert fail rolls back');
szAssert($beforeKul === $afterKul, 'rollback: partial kullanim event yok');

$pdo->exec("
    CREATE TRIGGER trg_sz_duzeltme_fail BEFORE INSERT ON serbest_zaman_events
    FOR EACH ROW
    BEGIN
      IF NEW.event_tipi = 'SERBEST_ZAMAN_DUZELTME' AND NEW.islem_anahtari = 'sz-rb-duzeltme-fail' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced duzeltme fail';
      END IF;
    END
");
$beforeDuz = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
$rbDuzFailed = false;
try {
    invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 10,
        'hedef_event_id' => $openOlusumId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'yeni_dakika' => 40,
        'event_tarihi' => '2026-07-15',
        'islem_anahtari' => 'sz-rb-duzeltme-fail',
        'aciklama' => 'rollback test',
    ], $subeHeader);
} catch (Throwable $e) {
    $rbDuzFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_sz_duzeltme_fail');
$afterDuz = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
szAssert($rbDuzFailed || $beforeDuz === $afterDuz, 'duzeltme insert fail rolls back');
szAssert($beforeDuz === $afterDuz, 'rollback: partial duzeltme event yok');

$pdo->exec("
    CREATE TRIGGER trg_sz_guard_del_fail BEFORE DELETE ON serbest_zaman_aktif_olusumlar
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced guard delete fail'
");
$beforeIptalEv = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
$beforeIptalGuard = (int) $pdo->query(
    'SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE olusum_event_id = ' . $openOlusumId
)->fetchColumn();
$rbIptalFailed = false;
try {
    invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 10,
        'hedef_event_id' => $openOlusumId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'event_tarihi' => '2026-07-16',
        'islem_anahtari' => 'sz-rb-iptal-guard-fail',
    ], $subeHeader);
} catch (Throwable $e) {
    $rbIptalFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_sz_guard_del_fail');
$afterIptalEv = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
$afterIptalGuard = (int) $pdo->query(
    'SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE olusum_event_id = ' . $openOlusumId
)->fetchColumn();
szAssert($rbIptalFailed || $beforeIptalEv === $afterIptalEv, 'iptal guard delete fail rolls back');
szAssert($beforeIptalEv === $afterIptalEv, 'rollback: partial iptal event yok');
szAssert($beforeIptalGuard === $afterIptalGuard && $afterIptalGuard === 1, 'rollback: guard silinmedi');

// --- Real parallel concurrency ---
$seedParOl = seedSnapshot($pdo, 1, 10, '2026-08-03', '2026-08-09', 40);
$tidParOl = seedSzTercih(
    $pdo,
    $seedParOl['snapshot_id'],
    $seedParOl['kapanis_id'],
    10,
    '2026-08-03',
    '2026-08-09',
    40
);
$po1 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidParOl,
], $subeHeader);
$po2 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidParOl,
], $subeHeader);
$ro1 = finishSzHttp($po1);
$ro2 = finishSzHttp($po2);
$parOlStatuses = [$ro1['status'], $ro2['status']];
sort($parOlStatuses);
szAssert($parOlStatuses === [200, 409], 'parallel olusum → one 200 one 409');
$parGuard = (int) $pdo->query(
    'SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id = ' . $tidParOl
)->fetchColumn();
szAssert($parGuard === 1, 'parallel olusum tek aktif guard');
$parOlCode = ($ro1['status'] === 409 ? ($ro1['payload']['errors'][0]['code'] ?? '') : ($ro2['payload']['errors'][0]['code'] ?? ''));
szAssert($parOlCode === 'ALREADY_EXISTS', 'parallel olusum loser ALREADY_EXISTS');
$parOlusumId = (int) (($ro1['status'] === 200 ? $ro1 : $ro2)['payload']['data']['id'] ?? 0);

// Parallel KULLANIM exceeding balance
$seedParKul = seedSnapshot($pdo, 1, 10, '2026-08-10', '2026-08-16', 40);
$tidParKul = seedSzTercih(
    $pdo,
    $seedParKul['snapshot_id'],
    $seedParKul['kapanis_id'],
    10,
    '2026-08-10',
    '2026-08-16',
    40
);
$parKulOlusum = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidParKul,
], $subeHeader);
szAssert($parKulOlusum['status'] === 200, 'parallel-kullanim setup olusum → 200');
// Fresh personel balance for isolated race: use personel 20 with dedicated olusum
$seedParKul20 = seedSnapshot($pdo, 2, 20, '2026-08-10', '2026-08-16', 40);
$tidParKul20 = seedSzTercih(
    $pdo,
    $seedParKul20['snapshot_id'],
    $seedParKul20['kapanis_id'],
    20,
    '2026-08-10',
    '2026-08-16',
    40
);
$olusum20 = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidParKul20,
], ['x-active-sube-id' => '2']);
szAssert($olusum20['status'] === 200, 'personel20 olusum → 200');
// 40*1.5=60; two parallel 50 → at most one can succeed
$pk1 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 20,
    'dakika' => 50,
    'event_tarihi' => '2026-08-17',
    'islem_anahtari' => 'sz-par-kul-a',
], ['x-active-sube-id' => '2']);
$pk2 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 20,
    'dakika' => 50,
    'event_tarihi' => '2026-08-17',
    'islem_anahtari' => 'sz-par-kul-b',
], ['x-active-sube-id' => '2']);
$rk1 = finishSzHttp($pk1);
$rk2 = finishSzHttp($pk2);
$okKul = (($rk1['status'] === 200) ? 1 : 0) + (($rk2['status'] === 200) ? 1 : 0);
szAssert($okKul >= 1 && $okKul <= 1, 'parallel kullanim only one success when sum exceeds');
$bakiyeParKul = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], ['x-active-sube-id' => '2'], [
    'personel_id' => '20',
    'referans_tarih' => '2026-08-17',
]);
szAssert((int) ($bakiyeParKul['payload']['data']['kalan_dakika'] ?? -1) >= 0, 'parallel kullanim negatif bakiye yok');

// Parallel IPTAL same target
$pi1 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
    'personel_id' => 10,
    'hedef_event_id' => $parOlusumId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'event_tarihi' => '2026-08-18',
    'islem_anahtari' => 'sz-par-iptal-a',
], $subeHeader);
$pi2 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
    'personel_id' => 10,
    'hedef_event_id' => $parOlusumId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'event_tarihi' => '2026-08-18',
    'islem_anahtari' => 'sz-par-iptal-b',
], $subeHeader);
$ri1 = finishSzHttp($pi1);
$ri2 = finishSzHttp($pi2);
$parIptalStatuses = [$ri1['status'], $ri2['status']];
sort($parIptalStatuses);
szAssert($parIptalStatuses === [200, 409], 'parallel iptal → one 200 one 409');
$iptalLoserCode = ($ri1['status'] === 409 ? ($ri1['payload']['errors'][0]['code'] ?? '') : ($ri2['payload']['errors'][0]['code'] ?? ''));
szAssert($iptalLoserCode === 'ALREADY_CANCELLED', 'parallel iptal loser ALREADY_CANCELLED');
$iptalCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM serbest_zaman_events
     WHERE event_tipi = 'SERBEST_ZAMAN_IPTAL' AND hedef_event_id = {$parOlusumId}"
)->fetchColumn();
szAssert($iptalCount === 1, 'parallel iptal tek iptal event');

// Parallel DUZELTME — both may append; last id wins; bakiye invariant
$seedParDuz = seedSnapshot($pdo, 1, 10, '2026-08-17', '2026-08-23', 40);
$tidParDuz = seedSzTercih(
    $pdo,
    $seedParDuz['snapshot_id'],
    $seedParDuz['kapanis_id'],
    10,
    '2026-08-17',
    '2026-08-23',
    40
);
$duzBase = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/olusum', [
    'odeme_tercihi_id' => $tidParDuz,
], $subeHeader);
szAssert($duzBase['status'] === 200, 'parallel-duzeltme setup olusum → 200');
$duzBaseId = (int) ($duzBase['payload']['data']['id'] ?? 0);
$pd1 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
    'personel_id' => 10,
    'hedef_event_id' => $duzBaseId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'yeni_dakika' => 55,
    'event_tarihi' => '2026-08-24',
    'islem_anahtari' => 'sz-par-duz-a',
    'aciklama' => 'parallel A',
], $subeHeader);
$pd2 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
    'personel_id' => 10,
    'hedef_event_id' => $duzBaseId,
    'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
    'yeni_dakika' => 70,
    'event_tarihi' => '2026-08-24',
    'islem_anahtari' => 'sz-par-duz-b',
    'aciklama' => 'parallel B',
], $subeHeader);
$rd1 = finishSzHttp($pd1);
$rd2 = finishSzHttp($pd2);
szAssert($rd1['status'] === 200 && $rd2['status'] === 200, 'parallel duzeltme both append 200');
$duzCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM serbest_zaman_events
     WHERE event_tipi = 'SERBEST_ZAMAN_DUZELTME' AND hedef_event_id = {$duzBaseId}"
)->fetchColumn();
szAssert($duzCount === 2, 'parallel duzeltme iki event append');
$lastOverride = (int) $pdo->query(
    "SELECT yeni_dakika FROM serbest_zaman_events
     WHERE event_tipi = 'SERBEST_ZAMAN_DUZELTME' AND hedef_event_id = {$duzBaseId}
     ORDER BY id DESC LIMIT 1"
)->fetchColumn();
$bakiyeParDuz = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
    'personel_id' => '10',
    'referans_tarih' => '2026-08-24',
]);
szAssert($bakiyeParDuz['status'] === 200, 'parallel duzeltme bakiye → 200');
szAssert((int) ($bakiyeParDuz['payload']['data']['kalan_dakika'] ?? -1) >= 0, 'parallel duzeltme bakiye invariant');
szAssert(in_array($lastOverride, [55, 70], true), 'parallel duzeltme son id override geçerli');

// Idempotency: aciklama farkı conflict
$idemAcik = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 2,
    'event_tarihi' => '2026-08-25',
    'islem_anahtari' => 'sz-idem-aciklama',
    'aciklama' => 'bir',
], $subeHeader);
szAssert($idemAcik['status'] === 200, 'idempotency base kullanim → 200');
$idemAcikConflict = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
    'personel_id' => 10,
    'dakika' => 2,
    'event_tarihi' => '2026-08-25',
    'islem_anahtari' => 'sz-idem-aciklama',
    'aciklama' => 'iki',
], $subeHeader);
szAssert($idemAcikConflict['status'] === 409, 'idempotency aciklama farkı → 409');
szAssert(
    ($idemAcikConflict['payload']['errors'][0]['code'] ?? '') === 'IDEMPOTENCY_CONFLICT',
    'aciklama farkı IDEMPOTENCY_CONFLICT'
);

echo "verify-serbest-zaman-mysql: OK\n";
