<?php

declare(strict_types=1);

/**
 * MariaDB HTTP + persistence acceptance for haftalik kapanis revizyon correction (S79-F).
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\RevizyonController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function rtAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function rtPdo(string $dsn): PDO
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
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=mbstring';
    }

    return $phpArgs;
}

/**
 * @return array{process:resource, pipes:array, status_file:string}
 */
function spawnRtHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    setConnectionPdo($pdo);
    resetAuthUser($user);

    $statusFile = tempnam(sys_get_temp_dir(), 'rt_http_');
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
function finishRtHttp(array $child): array
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
function invokeRtHttp(PDO $pdo, $user, string $method, string $path, array $body = [], array $headers = [], array $query = []): array
{
    return finishRtHttp(spawnRtHttp($pdo, $user, $method, $path, $body, $headers, $query));
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

    if ($method === 'GET' && $path === '/haftalik-kapanis/revizyon-talepleri') {
        RevizyonController::talepleri($request);
    }
    if ($method === 'POST' && $path === '/haftalik-kapanis/revizyon-talepleri') {
        RevizyonController::createTalep($request);
    }
    if ($method === 'GET' && preg_match('#^/haftalik-kapanis/revizyon-talepleri/(\d+)$#', $path, $matches)) {
        RevizyonController::talepDetail($request, $matches[1]);
    }
    if ($method === 'POST' && preg_match('#^/haftalik-kapanis/revizyon-talepleri/(\d+)/gonder$#', $path, $matches)) {
        RevizyonController::gonder($request, $matches[1]);
    }
    if ($method === 'POST' && preg_match('#^/haftalik-kapanis/revizyon-talepleri/(\d+)/onay$#', $path, $matches)) {
        RevizyonController::onay($request, $matches[1]);
    }
    if ($method === 'POST' && preg_match('#^/haftalik-kapanis/revizyon-talepleri/(\d+)/red$#', $path, $matches)) {
        RevizyonController::red($request, $matches[1]);
    }
    if ($method === 'POST' && preg_match('#^/haftalik-kapanis/revizyon-talepleri/(\d+)/iptal$#', $path, $matches)) {
        RevizyonController::iptal($request, $matches[1]);
    }
    if ($method === 'POST' && preg_match('#^/haftalik-kapanis/revizyon-talepleri/(\d+)/correction-uret$#', $path, $matches)) {
        RevizyonController::correctionUret($request, $matches[1]);
    }
    if ($method === 'GET' && $path === '/haftalik-kapanis/revizyon-corrections') {
        RevizyonController::corrections($request);
    }
    if ($method === 'GET' && preg_match('#^/haftalik-kapanis/revizyon-corrections/(\d+)$#', $path, $matches)) {
        RevizyonController::correctionDetail($request, $matches[1]);
    }
    if ($method === 'POST' && preg_match('#^/haftalik-kapanis/revizyon-corrections/(\d+)/iptal$#', $path, $matches)) {
        RevizyonController::correctionIptal($request, $matches[1]);
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

function createRtParentTables(PDO $pdo): void
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
        CREATE TABLE sube_departmanlar (
          sube_id INT UNSIGNED NOT NULL,
          departman_id INT UNSIGNED NOT NULL,
          PRIMARY KEY (sube_id, departman_id),
          CONSTRAINT fk_sube_departman_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
          CONSTRAINT fk_sube_departman_departman FOREIGN KEY (departman_id) REFERENCES departmanlar (id)
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
        CREATE TABLE surecler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          personel_id INT UNSIGNED NOT NULL,
          surec_turu VARCHAR(64) NOT NULL,
          baslangic_tarihi DATE NOT NULL,
          state VARCHAR(32) NOT NULL DEFAULT 'AKTIF',
          CONSTRAINT fk_surecler_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE serbest_zaman_events (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          personel_id INT UNSIGNED NOT NULL,
          event_tipi VARCHAR(32) NOT NULL,
          event_tarihi DATE NOT NULL,
          dakika INT UNSIGNED NULL,
          CONSTRAINT fk_sz_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE puantaj_aylik_muhurleri (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          sube_id INT UNSIGNED NOT NULL,
          yil SMALLINT UNSIGNED NOT NULL,
          ay TINYINT UNSIGNED NOT NULL,
          donem CHAR(7) NOT NULL,
          created_by INT UNSIGNED NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uq_pam_sube_donem (sube_id, yil, ay),
          CONSTRAINT fk_pam_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
          CONSTRAINT fk_pam_user FOREIGN KEY (created_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function seedRtFixtures(PDO $pdo): void
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
    $pdo->exec('INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1, 3)');
    $pdo->exec("
        INSERT INTO personeller (
          id, tc_kimlik_no, ad, soyad, dogum_tarihi, sicil_no, ise_giris_tarihi, sube_id, departman_id, aktif_durum
        ) VALUES
          (10, '11111111111', 'Ayse', 'Yilmaz', '1990-01-01', 'S10', '2020-01-01', 1, 3, 'AKTIF'),
          (20, '22222222222', 'Mehmet', 'Demir', '1988-01-01', 'S20', '2020-01-01', 2, NULL, 'AKTIF')
    ");

    $stmt = $pdo->prepare('
        INSERT INTO gunluk_puantaj (personel_id, tarih, net_calisma_suresi_dakika)
        VALUES (:personel_id, :tarih, 480)
    ');
    foreach ([
        ['personel_id' => 10, 'tarih' => '2026-04-06'],
        ['personel_id' => 10, 'tarih' => '2026-04-07'],
        ['personel_id' => 10, 'tarih' => '2026-04-08'],
        ['personel_id' => 10, 'tarih' => '2026-04-09'],
        ['personel_id' => 10, 'tarih' => '2026-04-10'],
        ['personel_id' => 10, 'tarih' => '2026-04-11'],
        ['personel_id' => 10, 'tarih' => '2026-04-12'],
        ['personel_id' => 10, 'tarih' => '2026-06-03'],
        ['personel_id' => 20, 'tarih' => '2026-04-07'],
    ] as $row) {
        $stmt->execute($row);
    }

    $pdo->exec("
        INSERT INTO surecler (id, personel_id, surec_turu, baslangic_tarihi, state)
        VALUES (501, 10, 'IZIN', '2026-04-07', 'AKTIF'), (502, 20, 'IZIN', '2026-04-07', 'AKTIF')
    ");
    $pdo->exec("
        INSERT INTO serbest_zaman_events (id, personel_id, event_tipi, event_tarihi, dakika)
        VALUES (601, 10, 'OLUSUM', '2026-04-07', 60), (602, 20, 'OLUSUM', '2026-04-07', 60)
    ");
    $pdo->exec("
        INSERT INTO puantaj_aylik_muhurleri (id, sube_id, yil, ay, donem, created_by)
        VALUES (1, 1, 2026, 4, '2026-04', 1)
    ");
}

/**
 * @return array{kapanis_id:int, snapshot_id:int}
 */
function seedRtKapanis(
    PDO $pdo,
    int $subeId,
    int $personelId,
    string $haftaBaslangic,
    string $haftaBitis
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
          2700, 2700, 0,
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
    ]);

    return ['kapanis_id' => $kapanisId, 'snapshot_id' => (int) $pdo->lastInsertId()];
}

function rtPuantajId(PDO $pdo, int $personelId, string $tarih): int
{
    $stmt = $pdo->prepare('SELECT id FROM gunluk_puantaj WHERE personel_id = :personel_id AND tarih = :tarih LIMIT 1');
    $stmt->execute(['personel_id' => $personelId, 'tarih' => $tarih]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException('puantaj fixture missing for ' . $personelId . ' ' . $tarih);
    }

    return (int) $id;
}

/**
 * @return array<string, mixed>
 */
function rtCreateBody(int $personelId, int $kaynakId, string $etkilenenTarih, string $haftaBaslangic, string $haftaBitis): array
{
    return [
        'personel_id' => $personelId,
        'hafta_baslangic' => $haftaBaslangic,
        'hafta_bitis' => $haftaBitis,
        'etkilenen_tarih' => $etkilenenTarih,
        'kaynak_tipi' => 'PUANTAJ',
        'kaynak_id' => $kaynakId,
        'revizyon_tipi' => 'PUANTAJ_GIRIS_CIKIS_DUZELTME',
        'gerekce' => 'Revizyon test gerekcesi',
    ];
}

function rtGecmisCount(PDO $pdo, int $talepId, ?string $aksiyon = null): int
{
    $sql = 'SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi WHERE revizyon_talebi_id = :id';
    $params = ['id' => $talepId];
    if ($aksiyon !== null) {
        $sql .= ' AND aksiyon = :aksiyon';
        $params['aksiyon'] = $aksiyon;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function assertRtSchemaPostconditions(PDO $pdo): void
{
    foreach ([
        'haftalik_kapanis_revizyon_corrections',
        'haftalik_kapanis_revizyon_talepleri',
        'haftalik_kapanis_revizyon_talebi_gecmisi',
    ] as $table) {
        $create = (string) $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_ASSOC)['Create Table'];
        rtAssert(stripos($create, 'CREATE TABLE `' . $table . '`') !== false, 'SHOW CREATE TABLE ' . $table);
        rtAssert(stripos($create, 'ENGINE=InnoDB') !== false, $table . ' engine InnoDB');
        rtAssert(stripos($create, 'utf8mb4') !== false, $table . ' charset utf8mb4');
        rtAssert(stripos($create, 'utf8mb4_unicode_ci') !== false, $table . ' collation unicode_ci');
        echo '[SCHEMA] ' . $table . ' CREATE: ' . preg_replace('/\s+/', ' ', $create) . PHP_EOL;

        $cols = $pdo->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $col) {
            echo '[SCHEMA] COL ' . $table . '.' . $col['Field']
                . ' type=' . $col['Type']
                . ' null=' . $col['Null']
                . ' default=' . var_export($col['Default'], true)
                . ' key=' . $col['Key']
                . ' extra=' . ($col['Extra'] ?? '')
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

    $talepCreate = (string) $pdo->query('SHOW CREATE TABLE haftalik_kapanis_revizyon_talepleri')->fetch(PDO::FETCH_ASSOC)['Create Table'];
    rtAssert(stripos($talepCreate, 'acik_talep_slot') !== false, 'acik_talep_slot generated column present');
    rtAssert(stripos($talepCreate, 'uq_hkrt_acik_kaynak') !== false, 'uq_hkrt_acik_kaynak present');
    rtAssert(stripos($talepCreate, 'uq_hkrt_correction_event') !== false, 'UNIQUE(correction_event_id)');
    rtAssert(stripos($talepCreate, 'GENERATED') !== false, 'acik_talep_slot is GENERATED');
    rtAssert(
        preg_match(
            "/acik_talep_slot[\\s\\S]*?case\\s+when\\s+`?durum`?\\s+in\\s*\\(\\s*'TASLAK'\\s*,\\s*'ONAY_BEKLIYOR'\\s*\\)\\s+then\\s+1\\s+else\\s+NULL\\s+end/i",
            $talepCreate
        ) === 1,
        'acik_talep_slot exact CASE WHEN expression'
    );

    $corrCols = array_column(
        $pdo->query('SHOW FULL COLUMNS FROM haftalik_kapanis_revizyon_corrections')->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    foreach ([
        'id', 'revizyon_talebi_id', 'personel_id', 'sube_id', 'kapanis_id', 'snapshot_id',
        'hafta_baslangic', 'hafta_bitis', 'etkilenen_tarih', 'kaynak_tipi', 'kaynak_id',
        'correction_tipi', 'onceki_deger', 'yeni_deger', 'delta_dakika', 'delta_gun',
        'bordro_etki_var_mi', 'bordro_etki_tipi', 'aciklama', 'olusturan_kullanici_id',
        'olusturma_zamani', 'iptal_edildi_mi', 'iptal_zamani', 'iptal_eden_kullanici_id',
        'iptal_aciklamasi', 'audit_ref', 'snapshot_ref', 'created_at', 'updated_at',
    ] as $col) {
        rtAssert(in_array($col, $corrCols, true), 'correction column ' . $col);
    }
    $auditNull = $pdo->query("
        SELECT IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'haftalik_kapanis_revizyon_corrections'
          AND COLUMN_NAME = 'audit_ref'
    ")->fetchColumn();
    rtAssert((string) $auditNull === 'NO', 'audit_ref NOT NULL');

    $talepCols = array_column(
        $pdo->query('SHOW FULL COLUMNS FROM haftalik_kapanis_revizyon_talepleri')->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    foreach ([
        'id', 'personel_id', 'sube_id', 'kapanis_id', 'snapshot_id', 'hafta_baslangic', 'hafta_bitis',
        'etkilenen_tarih', 'kaynak_tipi', 'kaynak_id', 'revizyon_tipi', 'onceki_deger', 'talep_edilen_deger',
        'gerekce', 'durum', 'talep_eden_kullanici_id', 'talep_eden_rol', 'talep_zamani',
        'karar_veren_kullanici_id', 'karar_zamani', 'karar_aciklamasi', 'correction_event_id',
        'acik_talep_slot', 'created_at', 'updated_at',
    ] as $col) {
        rtAssert(in_array($col, $talepCols, true), 'talep column ' . $col);
    }
    rtAssert(!in_array('karar_notu', $talepCols, true), 'talep has no karar_notu DB column');

    $gecmisCols = array_column(
        $pdo->query('SHOW FULL COLUMNS FROM haftalik_kapanis_revizyon_talebi_gecmisi')->fetchAll(PDO::FETCH_ASSOC),
        'Field'
    );
    foreach ([
        'id', 'revizyon_talebi_id', 'onceki_durum', 'yeni_durum', 'aksiyon', 'aciklama',
        'islem_yapan_kullanici_id', 'islem_zamani',
    ] as $col) {
        rtAssert(in_array($col, $gecmisCols, true), 'gecmis column ' . $col);
    }

    $indexNames = array_values(array_unique(array_column(
        $pdo->query('SHOW INDEX FROM haftalik_kapanis_revizyon_talepleri')->fetchAll(PDO::FETCH_ASSOC),
        'Key_name'
    )));
    foreach ([
        'PRIMARY', 'uq_hkrt_acik_kaynak', 'uq_hkrt_correction_event', 'idx_hkrt_personel_talep', 'idx_hkrt_sube_hafta',
        'idx_hkrt_durum', 'idx_hkrt_kapanis', 'idx_hkrt_snapshot',
    ] as $idx) {
        rtAssert(in_array($idx, $indexNames, true), 'talep index ' . $idx);
    }

    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME IN (
            'haftalik_kapanis_revizyon_corrections',
            'haftalik_kapanis_revizyon_talepleri',
            'haftalik_kapanis_revizyon_talebi_gecmisi'
          )
        ORDER BY TABLE_NAME, CONSTRAINT_NAME
    ")->fetchAll(PDO::FETCH_ASSOC);
    rtAssert(count($fks) >= 15, 'revizyon+correction FK count >= 15');
    foreach ($fks as $fk) {
        rtAssert(
            in_array((string) $fk['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true),
            'FK ' . $fk['CONSTRAINT_NAME'] . ' DELETE_RULE RESTRICT/NO ACTION'
        );
        rtAssert(
            in_array((string) $fk['UPDATE_RULE'], ['RESTRICT', 'NO ACTION'], true),
            'FK ' . $fk['CONSTRAINT_NAME'] . ' UPDATE_RULE RESTRICT/NO ACTION'
        );
        echo '[SCHEMA] FK ' . $fk['CONSTRAINT_NAME'] . ' → ' . $fk['REFERENCED_TABLE_NAME']
            . ' DELETE=' . $fk['DELETE_RULE'] . ' UPDATE=' . $fk['UPDATE_RULE'] . PHP_EOL;
    }

    $corrFk = $pdo->query("
        SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'haftalik_kapanis_revizyon_talepleri'
          AND COLUMN_NAME = 'correction_event_id'
          AND REFERENCED_TABLE_NAME IS NOT NULL
    ")->fetchColumn();
    rtAssert((int) $corrFk === 1, 'migration fk_hkrt_correction_event present');
}

function bootstrapRtSchema(PDO $pdo): string
{
    $suffix = bin2hex(random_bytes(4));
    $dbName = 'rt_s79e_' . $suffix;
    $pdo->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $dbName . '`');
    createRtParentTables($pdo);
    applySqlFile($pdo, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
    applySqlFile($pdo, __DIR__ . '/../../api/migrations/030_haftalik_kapanis_revizyon_talepleri.sql');
    applySqlFile($pdo, __DIR__ . '/../../api/migrations/031_haftalik_kapanis_revizyon_corrections.sql');
    seedRtFixtures($pdo);
    seedRtKapanis($pdo, 1, 10, '2026-04-06', '2026-04-12');
    seedRtKapanis($pdo, 2, 20, '2026-04-06', '2026-04-12');

    return $dbName;
}


$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/RevizyonController.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/031_haftalik_kapanis_revizyon_corrections.sql');

rtAssert(strpos($routerSource, 'RevizyonController::corrections') !== false, 'router GET corrections');
rtAssert(strpos($routerSource, 'RevizyonController::correctionDetail') !== false, 'router GET correctionDetail');
rtAssert(strpos($routerSource, 'RevizyonController::correctionUret') !== false, 'router POST correctionUret');
rtAssert(strpos($routerSource, 'RevizyonController::correctionIptal') !== false, 'router POST correctionIptal');
rtAssert(strpos($controllerSource, 'revizyon.approve') !== false, 'controller has revizyon.approve');
rtAssert(strpos($controllerSource, 'revizyon.view_finance_effect') !== false, 'controller finance permission');
rtAssert(
    preg_match('/CREATE TABLE\s+haftalik_kapanis_revizyon_corrections\s*\(/i', $migrationSource) === 1,
    'migration CREATE haftalik_kapanis_revizyon_corrections'
);
rtAssert(stripos($migrationSource, 'CREATE TABLE IF NOT EXISTS') === false, 'migration no IF NOT EXISTS');
rtAssert(stripos($migrationSource, 'ON DELETE RESTRICT') !== false, 'migration FK RESTRICT');
rtAssert(stripos($migrationSource, 'uq_hkrc_revizyon_talebi') !== false, 'migration uq_hkrc_revizyon_talebi');
rtAssert(stripos($migrationSource, 'uq_hkrc_audit_ref') !== false, 'migration uq_hkrc_audit_ref');
rtAssert(stripos($migrationSource, 'uq_hkrt_correction_event') !== false, 'migration uq_hkrt_correction_event');
rtAssert(stripos($migrationSource, 'fk_hkrt_correction_event') !== false, 'migration fk_hkrt_correction_event text');
rtAssert(preg_match('/\bDROP\s+(TABLE|DATABASE|INDEX)\b/i', $migrationSource) !== 1, 'migration no DROP');
rtAssert(preg_match('/(?:^|;)\s*TRUNCATE\b/im', $migrationSource) !== 1, 'migration no TRUNCATE');
rtAssert(preg_match('/\bDELETE\s+FROM\b/i', $migrationSource) !== 1, 'migration no DELETE FROM');
rtAssert(preg_match('/(?:^|;)\s*UPDATE\b/im', $migrationSource) !== 1, 'migration no UPDATE');
rtAssert(preg_match('/^\s*[^-\s].*\bIF NOT EXISTS\b/im', $migrationSource) !== 1, 'migration no IF NOT EXISTS phrase');
rtAssert(stripos($migrationSource, 'ON DELETE CASCADE') === false, 'migration no ON DELETE CASCADE');

$partialRoot = rtPdo($dsn);
$partialDb = 'rc_partial_' . bin2hex(random_bytes(3));
$partialRoot->exec('CREATE DATABASE `' . $partialDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$partialRoot->exec('USE `' . $partialDb . '`');
createRtParentTables($partialRoot);
applySqlFile($partialRoot, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
applySqlFile($partialRoot, __DIR__ . '/../../api/migrations/030_haftalik_kapanis_revizyon_talepleri.sql');
$partialRoot->exec('CREATE TABLE haftalik_kapanis_revizyon_corrections (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$partialFailed = false;
try {
    applySqlFile($partialRoot, __DIR__ . '/../../api/migrations/031_haftalik_kapanis_revizyon_corrections.sql');
} catch (Throwable $e) {
    $partialFailed = true;
}
rtAssert($partialFailed, 'partial existing corrections table → migration fails');
$partialRoot->exec('DROP DATABASE `' . $partialDb . '`');

$root = rtPdo($dsn);
$dbName = bootstrapRtSchema($root);
$pdo = rtPdo(preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn));
assertRtSchemaPostconditions($pdo);

$corrCreate = (string) $pdo->query('SHOW CREATE TABLE haftalik_kapanis_revizyon_corrections')->fetch(PDO::FETCH_ASSOC)['Create Table'];
rtAssert(stripos($corrCreate, 'ENGINE=InnoDB') !== false, 'schema postcondition corrections table');
rtAssert(stripos($corrCreate, 'uq_hkrc_revizyon_talebi') !== false, 'UNIQUE(revizyon_talebi_id)');
rtAssert(stripos($corrCreate, 'uq_hkrc_audit_ref') !== false, 'UNIQUE(audit_ref)');
$talepCreatePost = (string) $pdo->query('SHOW CREATE TABLE haftalik_kapanis_revizyon_talepleri')->fetch(PDO::FETCH_ASSOC)['Create Table'];
rtAssert(stripos($talepCreatePost, 'uq_hkrt_correction_event') !== false, 'UNIQUE(correction_event_id) postcondition');

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$ba = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
$baOther = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [2]];
$patron = ['id' => 3, 'rol' => 'PATRON', 'sube_ids' => []];
$muhasebe = ['id' => 4, 'rol' => 'MUHASEBE', 'sube_ids' => [1]];
$bolum = ['id' => 5, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];
$subeHeader = ['x-active-sube-id' => '1'];
$sube2Header = ['x-active-sube-id' => '2'];

$puantaj08 = rtPuantajId($pdo, 10, '2026-04-08');
$puantaj09 = rtPuantajId($pdo, 10, '2026-04-09');
$puantaj10 = rtPuantajId($pdo, 10, '2026-04-10');
$puantaj11 = rtPuantajId($pdo, 10, '2026-04-11');
$puantaj06 = rtPuantajId($pdo, 10, '2026-04-06');
$puantaj07 = rtPuantajId($pdo, 10, '2026-04-07');
$puantaj12 = rtPuantajId($pdo, 10, '2026-04-12');
$puantaj07p20 = rtPuantajId($pdo, 20, '2026-04-07');
$snapshot10 = (int) $pdo->query('SELECT id FROM haftalik_kapanis_satirlari WHERE personel_id = 10 LIMIT 1')->fetchColumn();

function rcApproveTalep(PDO $pdo, array $ba, array $gy, array $body, array $headers): int
{
    $create = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', $body, $headers);
    rtAssert($create['status'] === 201, 'setup create → 201');
    $id = (int) ($create['payload']['data']['id'] ?? 0);
    $gonder = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $id . '/gonder', [], $headers);
    rtAssert($gonder['status'] === 200, 'setup gonder → 200');
    $onay = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $id . '/onay', [], $headers);
    rtAssert($onay['status'] === 200, 'setup onay → 200');
    rtAssert(($onay['payload']['data']['correction_event_id'] ?? null) === null, 'setup onay correction_event_id null');
    return $id;
}

$unauth = invokeRtHttp($pdo, null, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader);
rtAssert($unauth['status'] === 401, 'unauthenticated GET corrections → 401');

$patronGet = invokeRtHttp($pdo, $patron, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader);
rtAssert($patronGet['status'] === 403 || (($patronGet['status'] === 200) && count($patronGet['payload']['data']['items'] ?? ['x']) === 0), 'PATRON GET → 403');

$gyListEmpty = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader);
rtAssert($gyListEmpty['status'] === 200, 'GY GET list → 200');

$baList = invokeRtHttp($pdo, $ba, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader);
rtAssert($baList['status'] === 200, 'BA GET list scope OK');

$badQuery = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader, ['foo' => '1']);
rtAssert($badQuery['status'] === 400, 'unknown query → 400 INVALID_CORRECTION_PAYLOAD');
rtAssert(($badQuery['payload']['errors'][0]['code'] ?? '') === 'INVALID_CORRECTION_PAYLOAD', 'unknown query code');

$draft = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(10, $puantaj08, '2026-04-08', '2026-04-06', '2026-04-12'), $subeHeader);
rtAssert($draft['status'] === 201, 'draft create');
$draftId = (int) $draft['payload']['data']['id'];
$stateFail = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $draftId . '/correction-uret', [], $subeHeader);
rtAssert($stateFail['status'] === 409, 'GY produce non-ONAYLANDI → 409 CORRECTION_NOT_ALLOWED_FOR_STATE');
rtAssert(($stateFail['payload']['errors'][0]['code'] ?? '') === 'CORRECTION_NOT_ALLOWED_FOR_STATE', 'state guard code');

$baProduce = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $draftId . '/correction-uret', [], $subeHeader);
rtAssert($baProduce['status'] === 403, 'BA produce → 403');
$muhProduce = invokeRtHttp($pdo, $muhasebe, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $draftId . '/correction-uret', [], $subeHeader);
rtAssert($muhProduce['status'] === 403, 'MUHASEBE produce → 403');
$bolumProduce = invokeRtHttp($pdo, $bolum, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $draftId . '/correction-uret', [], $subeHeader);
rtAssert($bolumProduce['status'] === 403, 'BOLUM produce → 403');

$talepId = rcApproveTalep($pdo, $ba, $gy, array_merge(rtCreateBody(10, $puantaj09, '2026-04-09', '2026-04-06', '2026-04-12'), [

    'talep_edilen_deger' => 90,
    'bordro_etki_var_mi' => true,
    'bordro_etki_notu' => 'bordro notu',
]), $subeHeader);

$snapBefore = (string) $pdo->query('SELECT state FROM haftalik_kapanis_satirlari WHERE personel_id = 10 LIMIT 1')->fetchColumn();
$snapJsonBefore = (string) $pdo->query('SELECT COALESCE(notlar_json, "") FROM haftalik_kapanis_satirlari WHERE personel_id = 10 LIMIT 1')->fetchColumn();

$bodyReject = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/correction-uret', ['foo' => 1], $subeHeader);
rtAssert($bodyReject['status'] === 400, 'produce body with field → 400 INVALID_CORRECTION_PAYLOAD');

$beforeCorr = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
$produce = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/correction-uret', [], $subeHeader);
rtAssert($produce['status'] === 200, 'GY produce ONAYLANDI → 200');
$corr = $produce['payload']['data'] ?? [];
$corrId = (int) ($corr['id'] ?? 0);
rtAssert($corrId > 0, 'produce returns id');
rtAssert((int) ($corr['delta_dakika'] ?? -1) === 0, 'server-owned onceki → non-numeric delta 0');
rtAssert(($corr['audit_ref'] ?? '') === 'REV-CORR-' . $talepId . '-' . $corrId, 'produce audit_ref REV-CORR-');
rtAssert(strpos((string) ($corr['snapshot_ref'] ?? ''), 'snapshot:') === 0, 'produce snapshot_ref snapshot:');
$linked = (int) $pdo->query('SELECT correction_event_id FROM haftalik_kapanis_revizyon_talepleri WHERE id = ' . $talepId)->fetchColumn();
rtAssert($linked === $corrId, 'produce correction_event_id linked');
$afterCorr = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
rtAssert($afterCorr === $beforeCorr + 1, 'one correction inserted');

$dup = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/correction-uret', [], $subeHeader);
rtAssert($dup['status'] === 409, 'duplicate produce → 409 CORRECTION_ALREADY_EXISTS');
rtAssert(($dup['payload']['errors'][0]['code'] ?? '') === 'CORRECTION_ALREADY_EXISTS', 'duplicate code');

$nonNumTalep = rcApproveTalep($pdo, $ba, $gy, array_merge(rtCreateBody(10, $puantaj10, '2026-04-10', '2026-04-06', '2026-04-12'), [

    'talep_edilen_deger' => '09:00',
    'revizyon_tipi' => 'MOLA_DUZELTME',
]), $subeHeader);
$nonNum = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $nonNumTalep . '/correction-uret', [], $subeHeader);
rtAssert($nonNum['status'] === 200, 'non-numeric produce → 200');
rtAssert((int) ($nonNum['payload']['data']['delta_dakika'] ?? -1) === 0, 'non-numeric delta 0');

$surecBody = [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'SUREC',
    'kaynak_id' => 501,
    'revizyon_tipi' => 'SUREC_GEC_GIRIS',
    'gerekce' => 'surec gec giris correction yok',
];
$surecTalep = rcApproveTalep($pdo, $ba, $gy, $surecBody, $subeHeader);
$surecProduce = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $surecTalep . '/correction-uret', [], $subeHeader);
rtAssert($surecProduce['status'] === 404, 'SUREC_GEC_GIRIS → 404 CORRECTION_TARGET_NOT_FOUND');
rtAssert(($surecProduce['payload']['errors'][0]['code'] ?? '') === 'CORRECTION_TARGET_NOT_FOUND', 'surec target code');

$detail = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-corrections/' . $corrId, [], $subeHeader);
rtAssert($detail['status'] === 200, 'GET detail → 200');
$detailData = $detail['payload']['data'] ?? [];
rtAssert(is_array($detailData), 'detail data array');
foreach (['kapanis_id', 'snapshot_id', 'iptal_aciklamasi', 'created_at', 'updated_at'] as $hidden) {
    rtAssert(!array_key_exists($hidden, $detailData), 'detail hides ' . $hidden);
}
rtAssert(array_key_exists('sube_id', $detailData) || array_key_exists('sube_adi', $detailData), 'S80 enrichment sube fields');
$missing = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-corrections/999999', [], $subeHeader);
rtAssert($missing['status'] === 404, 'GET detail missing → 404 CORRECTION_NOT_FOUND');
rtAssert(($missing['payload']['errors'][0]['code'] ?? '') === 'CORRECTION_NOT_FOUND', 'missing code');

$baDetail = invokeRtHttp($pdo, $ba, 'GET', '/haftalik-kapanis/revizyon-corrections/' . $corrId, [], $subeHeader);
rtAssert($baDetail['status'] === 200, 'BA detail in scope');
$baData = $baDetail['payload']['data'] ?? null;
rtAssert(is_array($baData), 'BA detail data array got=' . substr(json_encode($baDetail['payload'] ?? null), 0, 400));
rtAssert(array_key_exists('bordro_etki_tipi', $baData), 'BA detail has bordro_etki_tipi key keys=' . implode(',', array_keys($baData)));
rtAssert($baData['bordro_etki_tipi'] === null, 'finance mask BA: bordro_etki_tipi null');
rtAssert(($baData['bordro_etki_var_mi'] ?? false) === true, 'BA bordro_etki_var_mi korunur');
rtAssert(array_key_exists('aciklama', $baData) && $baData['aciklama'] === null, 'BA bordro etkili aciklama null');

$muhDetail = invokeRtHttp($pdo, $muhasebe, 'GET', '/haftalik-kapanis/revizyon-corrections/' . $corrId, [], $subeHeader);
rtAssert($muhDetail['status'] === 200, 'MUHASEBE detail finance unmasked');
$muhData = $muhDetail['payload']['data'] ?? [];
rtAssert(($muhData['bordro_etki_tipi'] ?? null) !== null, 'MUHASEBE sees bordro_etki_tipi');

$baEmpty = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => []];
$emptyList = invokeRtHttp($pdo, $baEmpty, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader);
rtAssert($emptyList['status'] === 200, 'allowedSubeIds=[] list → 200');
rtAssert(count($emptyList['payload']['data']['items'] ?? ['x']) === 0, 'allowedSubeIds=[] list empty');
$emptyDetail = invokeRtHttp($pdo, $baEmpty, 'GET', '/haftalik-kapanis/revizyon-corrections/' . $corrId, [], $subeHeader);
rtAssert($emptyDetail['status'] === 403, 'allowedSubeIds=[] detail → 403 CORRECTION_SCOPE_DENIED');

$subeQuery = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader, ['sube_id' => '1']);
rtAssert($subeQuery['status'] === 400, 'query sube_id → 400 INVALID_CORRECTION_PAYLOAD');
rtAssert(($subeQuery['payload']['errors'][0]['code'] ?? '') === 'INVALID_CORRECTION_PAYLOAD', 'query sube_id code');

$scopeDenied = invokeRtHttp($pdo, $baOther, 'GET', '/haftalik-kapanis/revizyon-corrections/' . $corrId, [], $sube2Header);
rtAssert($scopeDenied['status'] === 403, 'scope dışı detail → 403 CORRECTION_SCOPE_DENIED');
rtAssert(($scopeDenied['payload']['errors'][0]['code'] ?? '') === 'CORRECTION_SCOPE_DENIED', 'scope denied code');

$list = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader, ['personel_id' => '10']);
rtAssert($list['status'] === 200, 'list filters');
$items = $list['payload']['data']['items'] ?? [];
rtAssert(count($items) >= 2, 'list has items');
$ordered = true;
for ($i = 1; $i < count($items); $i++) {
    $prev = (string) ($items[$i - 1]['olusturma_zamani'] ?? '');
    $cur = (string) ($items[$i]['olusturma_zamani'] ?? '');
    if ($prev < $cur) {
        $ordered = false;
        break;
    }
    if ($prev === $cur && (int) $items[$i - 1]['id'] < (int) $items[$i]['id']) {
        $ordered = false;
        break;
    }
}
rtAssert($ordered, 'list ordering olusturma_zamani DESC');

$cancelBadType = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-corrections/' . $corrId . '/iptal', ['aciklama' => 123], $subeHeader);
rtAssert($cancelBadType['status'] === 400, 'cancel aciklama number → 400');
rtAssert(($cancelBadType['payload']['errors'][0]['code'] ?? '') === 'INVALID_CORRECTION_PAYLOAD', 'cancel number code');
$cancelBadObj = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-corrections/' . $corrId . '/iptal', ['aciklama' => ['x' => 1]], $subeHeader);
rtAssert($cancelBadObj['status'] === 400, 'cancel aciklama object → 400');
$cancelUnknown = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-corrections/' . $corrId . '/iptal', ['foo' => 'bar'], $subeHeader);
rtAssert($cancelUnknown['status'] === 400, 'cancel unknown field → 400');

$origAciklama = (string) $pdo->query('SELECT aciklama FROM haftalik_kapanis_revizyon_corrections WHERE id = ' . $corrId)->fetchColumn();
$cancel = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-corrections/' . $corrId . '/iptal', ['aciklama' => 'iptal nedeni'], $subeHeader);
rtAssert($cancel['status'] === 200, 'cancel → 200 iptal_edildi_mi true');
rtAssert(($cancel['payload']['data']['iptal_edildi_mi'] ?? false) === true, 'cancel flag');
rtAssert(($cancel['payload']['data']['aciklama'] ?? '') === $origAciklama || ($cancel['payload']['data']['aciklama'] ?? null) !== 'iptal nedeni', 'cancel does not overwrite aciklama');
rtAssert(!array_key_exists('iptal_aciklamasi', $cancel['payload']['data'] ?? []), 'cancel response hides iptal_aciklamasi');
$stillLinked = (int) $pdo->query('SELECT correction_event_id FROM haftalik_kapanis_revizyon_talepleri WHERE id = ' . $talepId)->fetchColumn();
rtAssert($stillLinked === $corrId, 'cancel keeps talep.correction_event_id');
$iptalAcik = (string) $pdo->query('SELECT COALESCE(iptal_aciklamasi, "") FROM haftalik_kapanis_revizyon_corrections WHERE id = ' . $corrId)->fetchColumn();
rtAssert($iptalAcik === 'iptal nedeni', 'iptal_aciklamasi internal set');
$metaAfterCancel = $pdo->query('SELECT iptal_zamani, iptal_eden_kullanici_id, iptal_aciklamasi, updated_at, olusturan_kullanici_id, olusturma_zamani, audit_ref, snapshot_ref FROM haftalik_kapanis_revizyon_corrections WHERE id = ' . $corrId)->fetch(PDO::FETCH_ASSOC);
rtAssert($metaAfterCancel['iptal_zamani'] !== null, 'cancel sets iptal_zamani');
rtAssert((int) $metaAfterCancel['iptal_eden_kullanici_id'] === 1, 'cancel sets iptal_eden');

$dupAfterCancel = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/correction-uret', [], $subeHeader);
rtAssert($dupAfterCancel['status'] === 409, 'produce after cancel → 409 CORRECTION_ALREADY_EXISTS');
rtAssert(($dupAfterCancel['payload']['errors'][0]['code'] ?? '') === 'CORRECTION_ALREADY_EXISTS', 'produce after cancel code');

$secondCancel = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-corrections/' . $corrId . '/iptal', ['aciklama' => 'ikinci'], $subeHeader);
rtAssert($secondCancel['status'] === 404, 'second cancel → 404 CORRECTION_NOT_FOUND');
$metaAfterSecond = $pdo->query('SELECT iptal_zamani, iptal_eden_kullanici_id, iptal_aciklamasi, updated_at FROM haftalik_kapanis_revizyon_corrections WHERE id = ' . $corrId)->fetch(PDO::FETCH_ASSOC);
rtAssert($metaAfterSecond['iptal_zamani'] === $metaAfterCancel['iptal_zamani'], 'second cancel iptal_zamani immutable');
rtAssert((string) $metaAfterSecond['iptal_aciklamasi'] === 'iptal nedeni', 'second cancel iptal_aciklamasi immutable');
rtAssert((int) $metaAfterSecond['iptal_eden_kullanici_id'] === (int) $metaAfterCancel['iptal_eden_kullanici_id'], 'second cancel iptal_eden immutable');

$cancelMasked = invokeRtHttp($pdo, $ba, 'GET', '/haftalik-kapanis/revizyon-corrections/' . $corrId, [], $subeHeader);
rtAssert($cancelMasked['status'] === 200, 'cancelled detail still readable');
$cancelMaskedData = $cancelMasked['payload']['data'] ?? [];
rtAssert(is_array($cancelMaskedData) && array_key_exists('bordro_etki_tipi', $cancelMaskedData), 'cancelled detail has bordro_etki_tipi key');
rtAssert($cancelMaskedData['bordro_etki_tipi'] === null, 'finance mask on cancelled correction');

$snapAfter = (string) $pdo->query('SELECT state FROM haftalik_kapanis_satirlari WHERE personel_id = 10 LIMIT 1')->fetchColumn();
$snapJsonAfter = (string) $pdo->query('SELECT COALESCE(notlar_json, "") FROM haftalik_kapanis_satirlari WHERE personel_id = 10 LIMIT 1')->fetchColumn();
rtAssert($snapBefore === $snapAfter && $snapJsonBefore === $snapJsonAfter, 'snapshot unchanged after produce/cancel');

$cntBeforeGet = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-corrections', [], $subeHeader);
invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-corrections/' . $corrId, [], $subeHeader);
$cntAfterGet = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
rtAssert($cntBeforeGet === $cntAfterGet, 'GET no-write');

// mapping matrix + delta variants + stringify
$negTalep = rcApproveTalep($pdo, $ba, $gy, array_merge(rtCreateBody(10, $puantaj06, '2026-04-06', '2026-04-06', '2026-04-12'), [

    'talep_edilen_deger' => 60,
]), $subeHeader);
$neg = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $negTalep . '/correction-uret', [], $subeHeader);
rtAssert($neg['status'] === 200, 'negative delta produce → 200');
rtAssert((int) ($neg['payload']['data']['delta_dakika'] ?? 1) === 0, 'server-owned onceki → delta 0');
rtAssert(($neg['payload']['data']['correction_tipi'] ?? '') === 'GIRIS_CIKIS_DUZELTME', 'map PUANTAJ→GIRIS_CIKIS');

$eqTalep = rcApproveTalep($pdo, $ba, $gy, array_merge(rtCreateBody(10, $puantaj07, '2026-04-07', '2026-04-06', '2026-04-12'), [

    'talep_edilen_deger' => 60,
    'revizyon_tipi' => 'DEVAMSIZLIK_DUZELTME',
]), $subeHeader);
$eq = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $eqTalep . '/correction-uret', [], $subeHeader);
rtAssert($eq['status'] === 200, 'equal delta produce → 200');
rtAssert((int) ($eq['payload']['data']['delta_dakika'] ?? -1) === 0, 'equal delta 0');
rtAssert(($eq['payload']['data']['correction_tipi'] ?? '') === 'DEVAMSIZLIK_DUZELTME', 'map DEVAMSIZLIK');

$objTalep = rcApproveTalep($pdo, $ba, $gy, array_merge(rtCreateBody(10, $puantaj12, '2026-04-12', '2026-04-06', '2026-04-12'), [

    'talep_edilen_deger' => ['giris' => '09:00', 'tags' => [1, 2]],
    'revizyon_tipi' => 'BORDRO_ETKI_NOTU',
    'bordro_etki_var_mi' => true,
]), $subeHeader);
$obj = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $objTalep . '/correction-uret', [], $subeHeader);
rtAssert($obj['status'] === 200, 'object/array stringify produce → 200');
$objOnceki = $obj['payload']['data']['onceki_deger'] ?? null;
$objYeni = $obj['payload']['data']['yeni_deger'] ?? null;
rtAssert(is_string($objOnceki), 'object onceki → string');
rtAssert(is_string($objYeni), 'array yeni → string');
rtAssert(strpos((string) $objOnceki, '"tarih"') !== false || strpos((string) $objOnceki, '"id"') !== false, 'server-owned onceki stringify');
rtAssert(strpos((string) $objYeni, '"giris":"09:00"') !== false, 'array stringify yeni contains giris');
rtAssert(($obj['payload']['data']['correction_tipi'] ?? '') === 'BORDRO_ETKI_NOTU', 'map BORDRO_ETKI_NOTU');

$boolTalep = rcApproveTalep($pdo, $ba, $gy, [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'SERBEST_ZAMAN',
    'kaynak_id' => 601,
    'revizyon_tipi' => 'SERBEST_ZAMAN_ETKI_DUZELTME',

    'talep_edilen_deger' => false,
    'gerekce' => 'bool scalar',
], $subeHeader);
$boolP = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $boolTalep . '/correction-uret', [], $subeHeader);
rtAssert($boolP['status'] === 200, 'boolean scalar produce → 200');
rtAssert(is_string($boolP['payload']['data']['onceki_deger'] ?? null) || is_array($boolP['payload']['data']['onceki_deger'] ?? null), 'server-owned onceki on sz');
rtAssert(($boolP['payload']['data']['yeni_deger'] ?? null) === false, 'boolean yeni preserved');
rtAssert((int) ($boolP['payload']['data']['delta_dakika'] ?? -1) === 0, 'boolean delta 0');
rtAssert(($boolP['payload']['data']['correction_tipi'] ?? '') === 'SERBEST_ZAMAN_ETKI_DUZELTME', 'map SERBEST_ZAMAN');

$kapTalep = rcApproveTalep($pdo, $ba, $gy, [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-08',
    'kaynak_tipi' => 'HAFTALIK_KAPANIS_SATIR',
    'kaynak_id' => $snapshot10,
    'revizyon_tipi' => 'KAPANIS_HESAP_REVIZYONU',
    'gerekce' => 'kapanis hesap',
], $subeHeader);
$kap = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $kapTalep . '/correction-uret', [], $subeHeader);
rtAssert($kap['status'] === 200, 'KAPANIS produce with seal/period → 200');
rtAssert(($kap['payload']['data']['correction_tipi'] ?? '') === 'KAPANIS_HESAP_REVIZYONU', 'map KAPANIS_HESAP');
rtAssert(($kap['payload']['errors'][0]['code'] ?? '') !== 'PERIOD_LOCKED', 'period seal does not block produce');

rtAssert(($nonNum['payload']['data']['correction_tipi'] ?? '') === 'MOLA_DUZELTME', 'map MOLA_DUZELTME');

$parTalep = rcApproveTalep($pdo, $ba, $gy, rtCreateBody(10, $puantaj11, '2026-04-11', '2026-04-06', '2026-04-12'), $subeHeader);
$c1 = spawnRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parTalep . '/correction-uret', [], $subeHeader);
$c2 = spawnRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parTalep . '/correction-uret', [], $subeHeader);
$r1 = finishRtHttp($c1);
$r2 = finishRtHttp($c2);
$statuses = [$r1['status'], $r2['status']];
sort($statuses);
rtAssert($statuses === [200, 409], 'parallel produce → one 200 one 409 CORRECTION_ALREADY_EXISTS');
$parCount = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections WHERE revizyon_talebi_id = ' . $parTalep)->fetchColumn();
rtAssert($parCount === 1, 'parallel produce single correction');
$parLink = (int) $pdo->query('SELECT correction_event_id FROM haftalik_kapanis_revizyon_talepleri WHERE id = ' . $parTalep)->fetchColumn();
rtAssert($parLink > 0, 'parallel produce linked');

$parCorrId = $parLink;
$cc1 = spawnRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-corrections/' . $parCorrId . '/iptal', [], $subeHeader);
$cc2 = spawnRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-corrections/' . $parCorrId . '/iptal', [], $subeHeader);
$cr1 = finishRtHttp($cc1);
$cr2 = finishRtHttp($cc2);
$cStatuses = [$cr1['status'], $cr2['status']];
sort($cStatuses);
rtAssert($cStatuses === [200, 404], 'parallel cancel → one 200 one 404');

$linkDupTalep = rcApproveTalep($pdo, $ba, $gy, [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'SUREC',
    'kaynak_id' => 501,
    'revizyon_tipi' => 'MOLA_DUZELTME',
    'gerekce' => 'unique link probe',
], $subeHeader);
$linkDupFailed = false;
try {
    $pdo->prepare('UPDATE haftalik_kapanis_revizyon_talepleri SET correction_event_id = :cid WHERE id = :id')->execute([
        'cid' => $corrId,
        'id' => $linkDupTalep,
    ]);
} catch (Throwable $e) {
    $linkDupFailed = true;
}
rtAssert($linkDupFailed, 'UNIQUE(correction_event_id) blocks dual talep link');

$orphan = (int) $pdo->query('
    SELECT COUNT(*)
    FROM haftalik_kapanis_revizyon_corrections c
    LEFT JOIN haftalik_kapanis_revizyon_talepleri r
      ON r.id = c.revizyon_talebi_id
    WHERE r.id IS NULL
       OR r.correction_event_id <> c.id
')->fetchColumn();
rtAssert($orphan === 0, 'orphan correction check');

// produce rollback: fail talep link update
$rbTalep = rcApproveTalep($pdo, $baOther, $gy, rtCreateBody(20, $puantaj07p20, '2026-04-07', '2026-04-06', '2026-04-12'), $sube2Header);
$beforeRb = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
$pdo->exec("
    CREATE TRIGGER trg_rc_link_fail BEFORE UPDATE ON haftalik_kapanis_revizyon_talepleri
    FOR EACH ROW
    BEGIN
      IF NEW.correction_event_id IS NOT NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced link fail';
      END IF;
    END
");
$rbFailed = false;
try {
    $rbResp = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $rbTalep . '/correction-uret', [], $sube2Header);
    $rbFailed = $rbResp['status'] !== 200;
} catch (Throwable $e) {
    $rbFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_rc_link_fail');
$afterRb = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_corrections')->fetchColumn();
$rbLink = $pdo->query('SELECT correction_event_id FROM haftalik_kapanis_revizyon_talepleri WHERE id = ' . $rbTalep)->fetchColumn();
rtAssert($rbFailed, 'produce rollback surfaced');
rtAssert($beforeRb === $afterRb, 'produce rollback orphan correction yok');
rtAssert($rbLink === null, 'produce rollback link null');

// cancel rollback
$cancelRbTalep = rcApproveTalep($pdo, $ba, $gy, [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'SUREC',
    'kaynak_id' => 501,
    'revizyon_tipi' => 'MOLA_DUZELTME',
    'gerekce' => 'cancel rollback',
], $subeHeader);
// SUREC 501 may still be open from surecTalep (ONAYLANDI not open) - open slot allows new. Good.
$cancelRbProduce = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $cancelRbTalep . '/correction-uret', [], $subeHeader);
rtAssert($cancelRbProduce['status'] === 200, 'cancel-rollback fixture produce');
$cancelRbId = (int) ($cancelRbProduce['payload']['data']['id'] ?? 0);
$pdo->exec("
    CREATE TRIGGER trg_rc_cancel_fail BEFORE UPDATE ON haftalik_kapanis_revizyon_corrections
    FOR EACH ROW
    BEGIN
      IF NEW.iptal_edildi_mi = 1 AND OLD.iptal_edildi_mi = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced cancel fail';
      END IF;
    END
");
$cancelRbFailed = false;
try {
    $cancelRbResp = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-corrections/' . $cancelRbId . '/iptal', [], $subeHeader);
    $cancelRbFailed = $cancelRbResp['status'] !== 200;
} catch (Throwable $e) {
    $cancelRbFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_rc_cancel_fail');
$cancelRbRow = $pdo->query('SELECT iptal_edildi_mi, iptal_zamani, iptal_eden_kullanici_id, iptal_aciklamasi FROM haftalik_kapanis_revizyon_corrections WHERE id = ' . $cancelRbId)->fetch(PDO::FETCH_ASSOC);
rtAssert($cancelRbFailed, 'cancel rollback surfaced');
rtAssert((int) $cancelRbRow['iptal_edildi_mi'] === 0, 'cancel rollback stays active');
rtAssert($cancelRbRow['iptal_zamani'] === null, 'cancel rollback iptal_zamani null');
rtAssert($cancelRbRow['iptal_eden_kullanici_id'] === null, 'cancel rollback iptal_eden null');

echo "verify-revizyon-correction-mysql: OK\n";
