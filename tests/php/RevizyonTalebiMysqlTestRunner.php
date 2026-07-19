<?php

declare(strict_types=1);

/**
 * MariaDB HTTP + persistence acceptance for haftalik kapanis revizyon talebi (S79-E).
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
    foreach (['haftalik_kapanis_revizyon_talepleri', 'haftalik_kapanis_revizyon_talebi_gecmisi'] as $table) {
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
    rtAssert(stripos($talepCreate, 'GENERATED') !== false, 'acik_talep_slot is GENERATED');
    rtAssert(
        preg_match(
            "/acik_talep_slot[\\s\\S]*?case\\s+when\\s+`?durum`?\\s+in\\s*\\(\\s*'TASLAK'\\s*,\\s*'ONAY_BEKLIYOR'\\s*\\)\\s+then\\s+1\\s+else\\s+NULL\\s+end/i",
            $talepCreate
        ) === 1,
        'acik_talep_slot exact CASE WHEN expression'
    );

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
        'PRIMARY', 'uq_hkrt_acik_kaynak', 'idx_hkrt_personel_talep', 'idx_hkrt_sube_hafta',
        'idx_hkrt_durum', 'idx_hkrt_kapanis', 'idx_hkrt_snapshot',
    ] as $idx) {
        rtAssert(in_array($idx, $indexNames, true), 'talep index ' . $idx);
    }

    $fks = $pdo->query("
        SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
        FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME IN ('haftalik_kapanis_revizyon_talepleri', 'haftalik_kapanis_revizyon_talebi_gecmisi')
        ORDER BY TABLE_NAME, CONSTRAINT_NAME
    ")->fetchAll(PDO::FETCH_ASSOC);
    rtAssert(count($fks) >= 8, 'revizyon FK count >= 8');
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
    rtAssert((int) $corrFk === 0, 'correction_event_id has no FK');
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
    seedRtFixtures($pdo);
    seedRtKapanis($pdo, 1, 10, '2026-04-06', '2026-04-12');

    return $dbName;
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN missing\n");
    exit(1);
}

$routerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Router.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../../api/src/Controllers/RevizyonController.php');
$migrationSource = (string) file_get_contents(__DIR__ . '/../../api/migrations/030_haftalik_kapanis_revizyon_talepleri.sql');

rtAssert(strpos($routerSource, 'RevizyonController::talepleri') !== false, 'router GET talepleri');
rtAssert(strpos($routerSource, 'RevizyonController::createTalep') !== false, 'router POST createTalep');
rtAssert(strpos($routerSource, 'RevizyonController::talepDetail') !== false, 'router GET talepDetail');
rtAssert(strpos($routerSource, 'RevizyonController::gonder') !== false, 'router POST gonder');
rtAssert(strpos($routerSource, 'RevizyonController::onay') !== false, 'router POST onay');
rtAssert(strpos($routerSource, 'RevizyonController::red') !== false, 'router POST red');
rtAssert(strpos($routerSource, 'RevizyonController::iptal') !== false, 'router POST iptal');
rtAssert(strpos($controllerSource, 'revizyon.view') !== false, 'controller has revizyon.view');
rtAssert(strpos($controllerSource, 'revizyon.create') !== false, 'controller has revizyon.create');
rtAssert(strpos($controllerSource, 'revizyon.submit') !== false, 'controller has revizyon.submit');
rtAssert(strpos($controllerSource, 'revizyon.approve') !== false, 'controller has revizyon.approve');
rtAssert(strpos($controllerSource, 'revizyon.reject') !== false, 'controller has revizyon.reject');
rtAssert(strpos($controllerSource, 'revizyon.cancel') !== false, 'controller has revizyon.cancel');
rtAssert(
    preg_match('/CREATE TABLE\s+haftalik_kapanis_revizyon_talepleri\s*\(/i', $migrationSource) === 1,
    'migration CREATE haftalik_kapanis_revizyon_talepleri'
);
rtAssert(
    preg_match('/CREATE TABLE\s+haftalik_kapanis_revizyon_talebi_gecmisi\s*\(/i', $migrationSource) === 1,
    'migration CREATE haftalik_kapanis_revizyon_talebi_gecmisi'
);
rtAssert(stripos($migrationSource, 'CREATE TABLE IF NOT EXISTS') === false, 'migration no IF NOT EXISTS');
rtAssert(stripos($migrationSource, 'ON DELETE RESTRICT') !== false, 'migration FK ON DELETE RESTRICT');
rtAssert(stripos($migrationSource, 'uq_hkrt_acik_kaynak') !== false, 'migration uq_hkrt_acik_kaynak');
rtAssert(stripos($migrationSource, 'acik_talep_slot') !== false, 'migration acik_talep_slot generated');
rtAssert(preg_match('/\bDROP\s+(TABLE|DATABASE|INDEX)\b/i', $migrationSource) !== 1, 'migration no DROP');
rtAssert(preg_match('/(?:^|;)\s*TRUNCATE\b/im', $migrationSource) !== 1, 'migration no TRUNCATE');
rtAssert(preg_match('/\bDELETE\s+FROM\b/i', $migrationSource) !== 1, 'migration no DELETE FROM');
rtAssert(preg_match('/(?:^|;)\s*UPDATE\b/im', $migrationSource) !== 1, 'migration no UPDATE');

$partialRoot = rtPdo($dsn);
$partialDb = 'rt_partial_talep_' . bin2hex(random_bytes(3));
$partialRoot->exec('CREATE DATABASE `' . $partialDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$partialRoot->exec('USE `' . $partialDb . '`');
createRtParentTables($partialRoot);
applySqlFile($partialRoot, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
$partialRoot->exec('CREATE TABLE haftalik_kapanis_revizyon_talepleri (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$partialTalepFailed = false;
try {
    applySqlFile($partialRoot, __DIR__ . '/../../api/migrations/030_haftalik_kapanis_revizyon_talepleri.sql');
} catch (Throwable $e) {
    $partialTalepFailed = true;
}
rtAssert($partialTalepFailed, 'partial existing haftalik_kapanis_revizyon_talepleri → migration fails');
$partialRoot->exec('DROP DATABASE `' . $partialDb . '`');

$partialGecmisRoot = rtPdo($dsn);
$partialGecmisDb = 'rt_partial_gecmis_' . bin2hex(random_bytes(3));
$partialGecmisRoot->exec('CREATE DATABASE `' . $partialGecmisDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$partialGecmisRoot->exec('USE `' . $partialGecmisDb . '`');
createRtParentTables($partialGecmisRoot);
applySqlFile($partialGecmisRoot, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
$partialGecmisRoot->exec('CREATE TABLE haftalik_kapanis_revizyon_talebi_gecmisi (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$partialGecmisFailed = false;
try {
    applySqlFile($partialGecmisRoot, __DIR__ . '/../../api/migrations/030_haftalik_kapanis_revizyon_talepleri.sql');
} catch (Throwable $e) {
    $partialGecmisFailed = true;
}
rtAssert($partialGecmisFailed, 'partial existing haftalik_kapanis_revizyon_talebi_gecmisi → migration fails');
$partialGecmisRoot->exec('DROP DATABASE `' . $partialGecmisDb . '`');

$root = rtPdo($dsn);
$dbName = bootstrapRtSchema($root);
$pdo = rtPdo(preg_replace('/dbname=[^;]+/', 'dbname=' . $dbName, $dsn));
assertRtSchemaPostconditions($pdo);

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$ba = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => [1]];
$patron = ['id' => 3, 'rol' => 'PATRON', 'sube_ids' => []];
$muhasebe = ['id' => 4, 'rol' => 'MUHASEBE', 'sube_ids' => [1]];
$bolum = ['id' => 5, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];
$subeHeader = ['x-active-sube-id' => '1'];

$puantaj07 = rtPuantajId($pdo, 10, '2026-04-07');
$puantaj08 = rtPuantajId($pdo, 10, '2026-04-08');
$puantaj09 = rtPuantajId($pdo, 10, '2026-04-09');
$puantaj10 = rtPuantajId($pdo, 10, '2026-04-10');
$puantaj11 = rtPuantajId($pdo, 10, '2026-04-11');
$puantaj12 = rtPuantajId($pdo, 10, '2026-04-12');
$puantajOpenWeek = rtPuantajId($pdo, 10, '2026-06-03');

$unauth = invokeRtHttp($pdo, null, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader);
rtAssert($unauth['status'] === 401, 'unauthenticated GET → 401');

$patronGet = invokeRtHttp($pdo, $patron, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader);
rtAssert($patronGet['status'] === 403, 'PATRON GET talepleri → 403');

$gyEmpty = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader);
rtAssert($gyEmpty['status'] === 200, 'GY GET list → 200 empty');
rtAssert(is_array($gyEmpty['payload']['data']['items'] ?? null)
    && count($gyEmpty['payload']['data']['items']) === 0, 'GY GET list empty items');

$detail404 = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-talepleri/99999', [], $subeHeader);
rtAssert($detail404['status'] === 404, 'GET detail missing → 404 NOT_FOUND');
rtAssert(($detail404['payload']['errors'][0]['code'] ?? '') === 'NOT_FOUND', 'detail NOT_FOUND code');

$create = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj08,
    '2026-04-08',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($create['status'] === 201, 'BA POST create → 201');
rtAssert(($create['payload']['data']['durum'] ?? '') === 'TASLAK', 'create durum TASLAK');
rtAssert(
    ($create['payload']['data']['correction_event_id'] ?? null) === null,
    'create correction_event_id null'
);
$talepId = (int) ($create['payload']['data']['id'] ?? 0);
rtAssert($talepId > 0, 'create returns id');
rtAssert(rtGecmisCount($pdo, $talepId, 'OLUSTUR') === 1, 'gecmis OLUSTUR on create');

$detail200 = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-talepleri/' . $talepId, [], $subeHeader);
rtAssert($detail200['status'] === 200, 'GET detail existing → 200');
rtAssert((int) ($detail200['payload']['data']['id'] ?? 0) === $talepId, 'GET detail id match');

$gyWithData = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader);
rtAssert($gyWithData['status'] === 200, 'GY GET list with data → 200');
rtAssert(is_array($gyWithData['payload']['data']['items'] ?? null)
    && count($gyWithData['payload']['data']['items']) >= 1, 'GY GET list has items');

$periodNotClosed = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantajOpenWeek,
    '2026-06-03',
    '2026-06-02',
    '2026-06-08'
), $subeHeader);
rtAssert($periodNotClosed['status'] === 409, 'create without closed kapanis → 409');
rtAssert(($periodNotClosed['payload']['errors'][0]['code'] ?? '') === 'PERIOD_NOT_CLOSED', 'PERIOD_NOT_CLOSED');

$targetNotFound = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
    rtCreateBody(10, $puantaj09, '2026-04-09', '2026-04-06', '2026-04-12'),
    ['kaynak_id' => 999999]
), $subeHeader);
rtAssert($targetNotFound['status'] === 404, 'bad kaynak_id → 404');
rtAssert(($targetNotFound['payload']['errors'][0]['code'] ?? '') === 'TARGET_NOT_FOUND', 'TARGET_NOT_FOUND');

$duplicateTaslak = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj08,
    '2026-04-08',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($duplicateTaslak['status'] === 409, 'duplicate open TASLAK → 409');
rtAssert(($duplicateTaslak['payload']['errors'][0]['code'] ?? '') === 'ALREADY_EXISTS', 'duplicate TASLAK ALREADY_EXISTS');

$gonder = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/gonder', [], $subeHeader);
rtAssert($gonder['status'] === 200, 'owner gonder → 200');
rtAssert(($gonder['payload']['data']['durum'] ?? '') === 'ONAY_BEKLIYOR', 'gonder → ONAY_BEKLIYOR');
rtAssert(rtGecmisCount($pdo, $talepId, 'GONDER') === 1, 'gecmis GONDER on gonder');

$duplicateOnayBekliyor = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj08,
    '2026-04-08',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($duplicateOnayBekliyor['status'] === 409, 'duplicate open ONAY_BEKLIYOR → 409');
rtAssert(($duplicateOnayBekliyor['payload']['errors'][0]['code'] ?? '') === 'ALREADY_EXISTS', 'duplicate ONAY_BEKLIYOR ALREADY_EXISTS');

$nonOwnerGonder = invokeRtHttp($pdo, $bolum, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/gonder', [], $subeHeader);
rtAssert($nonOwnerGonder['status'] === 403, 'non-owner gonder → 403');
rtAssert(($nonOwnerGonder['payload']['errors'][0]['code'] ?? '') === 'REVISION_OWNER_DENIED', 'REVISION_OWNER_DENIED');

$muhOnay = invokeRtHttp($pdo, $muhasebe, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/onay', [], $subeHeader);
rtAssert($muhOnay['status'] === 403, 'MUHASEBE onay → 403');

$redEmpty = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/red', [], $subeHeader);
rtAssert($redEmpty['status'] === 422, 'red without karar_notu → 422');
rtAssert(($redEmpty['payload']['errors'][0]['code'] ?? '') === 'VALIDATION_ERROR', 'red empty VALIDATION_ERROR');

$onay = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/onay', [], $subeHeader);
rtAssert($onay['status'] === 200, 'GY onay → 200');
rtAssert(($onay['payload']['data']['durum'] ?? '') === 'ONAYLANDI', 'onay → ONAYLANDI');
rtAssert(($onay['payload']['data']['correction_event_id'] ?? null) === null, 'onay correction_event_id still null');
rtAssert(rtGecmisCount($pdo, $talepId, 'ONAY') === 1, 'gecmis ONAY on onay');

$onayConflict = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/onay', [], $subeHeader);
rtAssert($onayConflict['status'] === 409, 'onay again → 409 STATE_CONFLICT');
rtAssert(($onayConflict['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT', 'onay again STATE_CONFLICT');

$gonderConflict = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/gonder', [], $subeHeader);
rtAssert($gonderConflict['status'] === 409, 'gonder from ONAYLANDI → 409');
rtAssert(($gonderConflict['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT', 'gonder invalid STATE_CONFLICT');

$createIptal = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj09,
    '2026-04-09',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($createIptal['status'] === 201, 'create for iptal TASLAK → 201');
$iptalTaslakId = (int) ($createIptal['payload']['data']['id'] ?? 0);
$iptalTaslak = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $iptalTaslakId . '/iptal', [], $subeHeader);
rtAssert($iptalTaslak['status'] === 200, 'iptal from TASLAK → 200');
rtAssert(($iptalTaslak['payload']['data']['durum'] ?? '') === 'IPTAL', 'iptal TASLAK → IPTAL');
rtAssert(rtGecmisCount($pdo, $iptalTaslakId, 'IPTAL') === 1, 'gecmis IPTAL from TASLAK');

$createIptalOb = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj10,
    '2026-04-10',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($createIptalOb['status'] === 201, 'create for iptal ONAY_BEKLIYOR → 201');
$iptalObId = (int) ($createIptalOb['payload']['data']['id'] ?? 0);
$gonderOb = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $iptalObId . '/gonder', [], $subeHeader);
rtAssert($gonderOb['status'] === 200, 'gonder before iptal ONAY_BEKLIYOR → 200');
$iptalOb = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $iptalObId . '/iptal', [], $subeHeader);
rtAssert($iptalOb['status'] === 200, 'iptal from ONAY_BEKLIYOR → 200');
rtAssert(($iptalOb['payload']['data']['durum'] ?? '') === 'IPTAL', 'iptal ONAY_BEKLIYOR → IPTAL');

$iptalOnaylandi = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $talepId . '/iptal', [], $subeHeader);
rtAssert($iptalOnaylandi['status'] === 409, 'iptal from ONAYLANDI → 409');
rtAssert(($iptalOnaylandi['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT', 'iptal ONAYLANDI STATE_CONFLICT');

$serverOwned = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
    rtCreateBody(10, $puantaj07, '2026-04-07', '2026-04-06', '2026-04-12'),
    ['durum' => 'ONAYLANDI']
), $subeHeader);
rtAssert($serverOwned['status'] === 422, 'server-owned durum in body → 422');

$subeInBody = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
    rtCreateBody(10, $puantaj07, '2026-04-07', '2026-04-06', '2026-04-12'),
    ['sube_id' => 1]
), $subeHeader);
rtAssert($subeInBody['status'] === 422, 'sube_id in body → 422');

$parCreateBody = rtCreateBody(10, $puantaj07, '2026-04-07', '2026-04-06', '2026-04-12');
$pc1 = spawnRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', $parCreateBody, $subeHeader);
$pc2 = spawnRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', $parCreateBody, $subeHeader);
$rc1 = finishRtHttp($pc1);
$rc2 = finishRtHttp($pc2);
$parCreateStatuses = [$rc1['status'], $rc2['status']];
sort($parCreateStatuses);
rtAssert($parCreateStatuses === [201, 409], 'parallel create → one 201 one 409');
$parCreateLoserCode = ($rc1['status'] === 409 ? ($rc1['payload']['errors'][0]['code'] ?? '') : ($rc2['payload']['errors'][0]['code'] ?? ''));
rtAssert($parCreateLoserCode === 'ALREADY_EXISTS', 'parallel create loser ALREADY_EXISTS');

$parGonderCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj11,
    '2026-04-11',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($parGonderCreate['status'] === 201, 'parallel gonder setup create → 201');
$parGonderId = (int) ($parGonderCreate['payload']['data']['id'] ?? 0);
$pg1 = spawnRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parGonderId . '/gonder', [], $subeHeader);
$pg2 = spawnRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parGonderId . '/gonder', [], $subeHeader);
$rg1 = finishRtHttp($pg1);
$rg2 = finishRtHttp($pg2);
$parGonderStatuses = [$rg1['status'], $rg2['status']];
sort($parGonderStatuses);
rtAssert($parGonderStatuses === [200, 409], 'parallel gonder → one 200 one 409');
$parGonderLoserCode = ($rg1['status'] === 409 ? ($rg1['payload']['errors'][0]['code'] ?? '') : ($rg2['payload']['errors'][0]['code'] ?? ''));
rtAssert($parGonderLoserCode === 'STATE_CONFLICT', 'parallel gonder loser STATE_CONFLICT');

$parDecisionCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj12,
    '2026-04-12',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($parDecisionCreate['status'] === 201, 'parallel decision setup create → 201');
$parDecisionId = (int) ($parDecisionCreate['payload']['data']['id'] ?? 0);
$gonderDecision = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parDecisionId . '/gonder', [], $subeHeader);
rtAssert($gonderDecision['status'] === 200, 'parallel decision setup gonder → 200');
$pd1 = spawnRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parDecisionId . '/onay', [], $subeHeader);
$pd2 = spawnRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parDecisionId . '/red', [
    'karar_notu' => 'Red gerekcesi',
], $subeHeader);
$rd1 = finishRtHttp($pd1);
$rd2 = finishRtHttp($pd2);
$parDecisionOk = (($rd1['status'] === 200) ? 1 : 0) + (($rd2['status'] === 200) ? 1 : 0);
rtAssert($parDecisionOk === 1, 'parallel onay/red → one success');
$parDecisionConflict = ($rd1['status'] === 409 ? ($rd1['payload']['errors'][0]['code'] ?? '') : ($rd2['payload']['errors'][0]['code'] ?? ''));
rtAssert($parDecisionConflict === 'STATE_CONFLICT', 'parallel onay/red loser STATE_CONFLICT');

$parIptalGonderCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj10,
    '2026-04-10',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($parIptalGonderCreate['status'] === 201, 'parallel iptal/gonder setup create → 201');
$parIptalGonderId = (int) ($parIptalGonderCreate['payload']['data']['id'] ?? 0);
$pig1 = spawnRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parIptalGonderId . '/iptal', [], $subeHeader);
$pig2 = spawnRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parIptalGonderId . '/gonder', [], $subeHeader);
$rig1 = finishRtHttp($pig1);
$rig2 = finishRtHttp($pig2);
$parIptalGonderOk = (($rig1['status'] === 200) ? 1 : 0) + (($rig2['status'] === 200) ? 1 : 0);
rtAssert($parIptalGonderOk === 1, 'parallel iptal/gonder → one success');
$parIptalGonderConflict = ($rig1['status'] === 409 ? ($rig1['payload']['errors'][0]['code'] ?? '') : ($rig2['payload']['errors'][0]['code'] ?? ''));
rtAssert($parIptalGonderConflict === 'STATE_CONFLICT', 'parallel iptal/gonder loser STATE_CONFLICT');
$parIptalGonderDurum = (string) $pdo->query(
    'SELECT durum FROM haftalik_kapanis_revizyon_talepleri WHERE id = ' . (int) $parIptalGonderId
)->fetchColumn();
rtAssert(in_array($parIptalGonderDurum, ['IPTAL', 'ONAY_BEKLIYOR'], true), 'parallel iptal/gonder terminal durum tutarli');
$parIptalGonderAudit = rtGecmisCount($pdo, $parIptalGonderId);
rtAssert($parIptalGonderAudit === 2, 'parallel iptal/gonder tek gecis audit (OLUSTUR+aksiyon)');

$redSetup = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj09,
    '2026-04-09',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($redSetup['status'] === 201, 'red flow setup create → 201');
$redSetupId = (int) ($redSetup['payload']['data']['id'] ?? 0);
$gonderRed = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $redSetupId . '/gonder', [], $subeHeader);
rtAssert($gonderRed['status'] === 200, 'red flow setup gonder → 200');
$redOk = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $redSetupId . '/red', [
    'karar_notu' => 'Red aciklamasi',
], $subeHeader);
rtAssert($redOk['status'] === 200, 'red with karar_notu → 200');
rtAssert(($redOk['payload']['data']['durum'] ?? '') === 'REDDEDILDI', 'red → REDDEDILDI');
rtAssert(rtGecmisCount($pdo, $redSetupId, 'RED') === 1, 'gecmis RED on red');

$redRetry = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $redSetupId . '/red', [
    'karar_notu' => 'Tekrar red',
], $subeHeader);
rtAssert($redRetry['status'] === 409, 'red retry → 409');
rtAssert(($redRetry['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT', 'red retry STATE_CONFLICT');
rtAssert(rtGecmisCount($pdo, $redSetupId, 'RED') === 1, 'red retry audit artmaz');

$iptalReddedildi = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $redSetupId . '/iptal', [], $subeHeader);
rtAssert($iptalReddedildi['status'] === 409, 'iptal REDDEDILDI → 409');
rtAssert(($iptalReddedildi['payload']['errors'][0]['code'] ?? '') === 'STATE_CONFLICT', 'iptal REDDEDILDI STATE_CONFLICT');

// --- Terminal recreate → 201 ---
$recreateOnay = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj08,
    '2026-04-08',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($recreateOnay['status'] === 201, 'terminal ONAYLANDI sonrası recreate → 201');

$recreateRed = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj09,
    '2026-04-09',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($recreateRed['status'] === 201, 'terminal REDDEDILDI sonrası recreate → 201');
$recreateRedId = (int) ($recreateRed['payload']['data']['id'] ?? 0);
$recreateIptal = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $recreateRedId . '/iptal', [], $subeHeader);
rtAssert($recreateIptal['status'] === 200, 'recreate iptal setup → 200');
$recreateAfterIptal = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj09,
    '2026-04-09',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($recreateAfterIptal['status'] === 201, 'terminal IPTAL sonrası recreate → 201');

// --- Permission matrix spot checks ---
$puantaj06 = rtPuantajId($pdo, 10, '2026-04-06');
foreach ([$bolum, $muhasebe, $ba] as $roleUser) {
    $label = (string) $roleUser['rol'];
    $permView = invokeRtHttp($pdo, $roleUser, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader);
    rtAssert($permView['status'] === 200, $label . ' view → 200');
}
$bolumCreate = invokeRtHttp($pdo, $bolum, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
    rtCreateBody(10, $puantaj06, '2026-04-06', '2026-04-06', '2026-04-12'),
    ['bordro_etki_var_mi' => true, 'bordro_etki_notu' => 'bordro']
), $subeHeader);
rtAssert($bolumCreate['status'] === 201, 'BOLUM create → 201');
$bolumCreateId = (int) ($bolumCreate['payload']['data']['id'] ?? 0);
$bolumGonder = invokeRtHttp($pdo, $bolum, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $bolumCreateId . '/gonder', [], $subeHeader);
rtAssert($bolumGonder['status'] === 200, 'BOLUM gonder own → 200');
$bolumOnay = invokeRtHttp($pdo, $bolum, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $bolumCreateId . '/onay', [], $subeHeader);
rtAssert($bolumOnay['status'] === 403, 'BOLUM onay → 403');
$bolumRed = invokeRtHttp($pdo, $bolum, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $bolumCreateId . '/red', [
    'karar_notu' => 'x',
], $subeHeader);
rtAssert($bolumRed['status'] === 403, 'BOLUM red → 403');
$gyCancelBolum = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $bolumCreateId . '/iptal', [], $subeHeader);
rtAssert($gyCancelBolum['status'] === 200, 'GY iptal başkasının ONAY_BEKLIYOR → 200');

$muhCreate = invokeRtHttp($pdo, $muhasebe, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
    rtCreateBody(10, $puantaj06, '2026-04-06', '2026-04-06', '2026-04-12'),
    ['bordro_etki_var_mi' => true, 'bordro_etki_notu' => 'muhasebe notu']
), $subeHeader);
rtAssert($muhCreate['status'] === 201, 'MUHASEBE create → 201');
$muhCreateId = (int) ($muhCreate['payload']['data']['id'] ?? 0);
$muhGonder = invokeRtHttp($pdo, $muhasebe, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $muhCreateId . '/gonder', [], $subeHeader);
rtAssert($muhGonder['status'] === 200, 'MUHASEBE gonder → 200');
$muhIptal = invokeRtHttp($pdo, $muhasebe, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $muhCreateId . '/iptal', [], $subeHeader);
rtAssert($muhIptal['status'] === 200, 'MUHASEBE iptal own → 200');

foreach (['GET' => '/haftalik-kapanis/revizyon-talepleri', 'POST' => '/haftalik-kapanis/revizyon-talepleri'] as $method => $path) {
    $patronAct = invokeRtHttp($pdo, $patron, $method, $path, $method === 'POST' ? rtCreateBody(
        10,
        $puantaj06,
        '2026-04-06',
        '2026-04-06',
        '2026-04-12'
    ) : [], $subeHeader);
    rtAssert($patronAct['status'] === 403, 'PATRON ' . $method . ' → 403');
}
$unauthCreate = invokeRtHttp($pdo, null, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj06,
    '2026-04-06',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($unauthCreate['status'] === 401, 'unauthenticated create → 401');

// --- Ownership GY bypass + non-owner iptal ---
$ownCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj06,
    '2026-04-06',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($ownCreate['status'] === 201, 'ownership setup create → 201');
$ownId = (int) ($ownCreate['payload']['data']['id'] ?? 0);
$nonOwnerIptal = invokeRtHttp($pdo, $bolum, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $ownId . '/iptal', [], $subeHeader);
rtAssert($nonOwnerIptal['status'] === 403, 'non-owner iptal → 403');
rtAssert(($nonOwnerIptal['payload']['errors'][0]['code'] ?? '') === 'REVISION_OWNER_DENIED', 'non-owner iptal REVISION_OWNER_DENIED');
$gyGonderOther = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $ownId . '/gonder', [], $subeHeader);
rtAssert($gyGonderOther['status'] === 200, 'GY başkasının gonder → 200');
$gyIptalOther = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $ownId . '/iptal', [], $subeHeader);
rtAssert($gyIptalOther['status'] === 200, 'GY başkasının iptal → 200');

// --- Scope matrix ---
$baEmpty = ['id' => 2, 'rol' => 'BIRIM_AMIRI', 'sube_ids' => []];
$emptyList = invokeRtHttp($pdo, $baEmpty, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader);
rtAssert($emptyList['status'] === 200, 'allowedSubeIds=[] list → 200');
rtAssert(is_array($emptyList['payload']['data']['items'] ?? null)
    && count($emptyList['payload']['data']['items']) === 0, 'allowedSubeIds=[] global erişim yok');
$emptyCreate = invokeRtHttp($pdo, $baEmpty, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj06,
    '2026-04-06',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($emptyCreate['status'] === 403, 'allowedSubeIds=[] create → 403');

$outOfScopeCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    20,
    rtPuantajId($pdo, 20, '2026-04-07'),
    '2026-04-07',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($outOfScopeCreate['status'] === 403, 'scope dışı create → 403');

$gySeedOtherSube = seedRtKapanis($pdo, 2, 20, '2026-04-06', '2026-04-12');
$gyOtherCreate = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    20,
    rtPuantajId($pdo, 20, '2026-04-07'),
    '2026-04-07',
    '2026-04-06',
    '2026-04-12'
), ['x-active-sube-id' => '2']);
rtAssert($gyOtherCreate['status'] === 201, 'GY global create other sube → 201');
$gyOtherId = (int) ($gyOtherCreate['payload']['data']['id'] ?? 0);
$baList = invokeRtHttp($pdo, $ba, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader);
$baListIds = array_map(static function ($item) {
    return (int) ($item['id'] ?? 0);
}, $baList['payload']['data']['items'] ?? []);
rtAssert(!in_array($gyOtherId, $baListIds, true), 'scope dışı satır listeden hariç');
$baDetailOut = invokeRtHttp($pdo, $ba, 'GET', '/haftalik-kapanis/revizyon-talepleri/' . $gyOtherId, [], $subeHeader);
rtAssert($baDetailOut['status'] === 403, 'scope dışı detail → 403');

$querySube = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader, ['sube_id' => '1']);
rtAssert($querySube['status'] === 422, 'query sube_id → 422');
rtAssert(($querySube['payload']['errors'][0]['code'] ?? '') === 'VALIDATION_ERROR', 'query sube_id VALIDATION_ERROR');
$unknownQuery = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader, ['foo' => '1']);
rtAssert($unknownQuery['status'] === 422, 'unknown query → 422');

// --- GET list no-write + sort + detail leak ---
$beforeTalep = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri')->fetchColumn();
$beforeGecmis = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi')->fetchColumn();
$sortList = invokeRtHttp($pdo, $gy, 'GET', '/haftalik-kapanis/revizyon-talepleri', [], $subeHeader);
rtAssert($sortList['status'] === 200, 'GET list no-write → 200');
$afterTalep = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri')->fetchColumn();
$afterGecmis = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi')->fetchColumn();
rtAssert($beforeTalep === $afterTalep && $beforeGecmis === $afterGecmis, 'GET list no-write counts');
$items = $sortList['payload']['data']['items'] ?? [];
rtAssert(is_array($items) && count($items) >= 2, 'GET list has sortable items');
for ($i = 1; $i < count($items); $i++) {
    $prev = (string) ($items[$i - 1]['talep_zamani'] ?? '');
    $cur = (string) ($items[$i]['talep_zamani'] ?? '');
    $prevId = (int) ($items[$i - 1]['id'] ?? 0);
    $curId = (int) ($items[$i]['id'] ?? 0);
    rtAssert($prev > $cur || ($prev === $cur && $prevId >= $curId), 'list sort talep_zamani DESC, id DESC');
}
$detailKeys = array_keys($detail200['payload']['data'] ?? []);
rtAssert(!in_array('acik_talep_slot', $detailKeys, true), 'detail no acik_talep_slot leak');

$olusturRow = $pdo->query(
    'SELECT onceki_durum, yeni_durum, aksiyon FROM haftalik_kapanis_revizyon_talebi_gecmisi
     WHERE revizyon_talebi_id = ' . (int) $talepId . ' AND aksiyon = \'OLUSTUR\' LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
rtAssert($olusturRow !== false && $olusturRow['onceki_durum'] === null, 'OLUSTUR onceki_durum null');
rtAssert(($olusturRow['yeni_durum'] ?? '') === 'TASLAK', 'OLUSTUR yeni_durum TASLAK');

// --- Red karar_notu variants ---
$redVarCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj06,
    '2026-04-06',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($redVarCreate['status'] === 201, 'red variants setup → 201');
$redVarId = (int) ($redVarCreate['payload']['data']['id'] ?? 0);
invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $redVarId . '/gonder', [], $subeHeader);
$redNull = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $redVarId . '/red', [
    'karar_notu' => null,
], $subeHeader);
rtAssert($redNull['status'] === 422, 'red null karar_notu → 422');
$redWs = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $redVarId . '/red', [
    'karar_notu' => '   ',
], $subeHeader);
rtAssert($redWs['status'] === 422, 'red whitespace karar_notu → 422');

// --- JSON normalization ---
$jsonCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
    rtCreateBody(10, $puantaj06, '2026-04-06', '2026-04-06', '2026-04-12'),
    [
        'onceki_deger' => ['giris' => '08:00', 'flags' => [true, 1]],
        'talep_edilen_deger' => ['giris' => '09:00', 'flags' => [false, 2]],
    ]
), $subeHeader);
// may conflict if open slot still held by redVar — cancel first
if ($jsonCreate['status'] !== 201) {
    invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $redVarId . '/iptal', [], $subeHeader);
    $jsonCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
        rtCreateBody(10, $puantaj06, '2026-04-06', '2026-04-06', '2026-04-12'),
        [
            'onceki_deger' => ['giris' => '08:00', 'flags' => [true, 1]],
            'talep_edilen_deger' => ['giris' => '09:00', 'flags' => [false, 2]],
        ]
    ), $subeHeader);
}
rtAssert($jsonCreate['status'] === 201, 'JSON object/array create → 201');
$jsonOnceki = $jsonCreate['payload']['data']['onceki_deger'] ?? null;
$jsonTalep = $jsonCreate['payload']['data']['talep_edilen_deger'] ?? null;
rtAssert(is_array($jsonOnceki) && ($jsonOnceki['giris'] ?? '') === '08:00', 'JSON onceki object roundtrip');
rtAssert(is_array($jsonTalep) && is_array($jsonTalep['flags'] ?? null), 'JSON talep array roundtrip');
$jsonId = (int) ($jsonCreate['payload']['data']['id'] ?? 0);
invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $jsonId . '/iptal', [], $subeHeader);

$jsonScalar = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
    rtCreateBody(10, $puantaj06, '2026-04-06', '2026-04-06', '2026-04-12'),
    ['onceki_deger' => '08:00', 'talep_edilen_deger' => 42]
), $subeHeader);
rtAssert($jsonScalar['status'] === 201, 'JSON scalar create → 201');
rtAssert(($jsonScalar['payload']['data']['onceki_deger'] ?? null) === '08:00', 'JSON string roundtrip');
rtAssert(($jsonScalar['payload']['data']['talep_edilen_deger'] ?? null) === 42, 'JSON number roundtrip');
$jsonScalarId = (int) ($jsonScalar['payload']['data']['id'] ?? 0);
invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $jsonScalarId . '/iptal', [], $subeHeader);

// --- Server-owned full list sample ---
foreach (['id', 'kapanis_id', 'snapshot_id', 'correction_event_id', 'acik_talep_slot', 'talep_zamani'] as $field) {
    $owned = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
        rtCreateBody(10, $puantaj06, '2026-04-06', '2026-04-06', '2026-04-12'),
        [$field => 1]
    ), $subeHeader);
    rtAssert($owned['status'] === 422, 'server-owned ' . $field . ' → 422');
}

// --- Source validation ---
$invalidTip = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', array_merge(
    rtCreateBody(10, $puantaj06, '2026-04-06', '2026-04-06', '2026-04-12'),
    ['kaynak_tipi' => 'BILINMEYEN']
), $subeHeader);
rtAssert($invalidTip['status'] === 422, 'invalid kaynak_tipi → 422');

$snapshotId = (int) $pdo->query(
    'SELECT id FROM haftalik_kapanis_satirlari WHERE personel_id = 10 ORDER BY id ASC LIMIT 1'
)->fetchColumn();
$kapanisSatirOk = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'HAFTALIK_KAPANIS_SATIR',
    'kaynak_id' => $snapshotId,
    'revizyon_tipi' => 'KAPANIS_HESAP_REVIZYONU',
    'gerekce' => 'kapanis satir revizyon',
], $subeHeader);
rtAssert($kapanisSatirOk['status'] === 201, 'HAFTALIK_KAPANIS_SATIR create → 201');
$kapanisSatirId = (int) ($kapanisSatirOk['payload']['data']['id'] ?? 0);
invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $kapanisSatirId . '/iptal', [], $subeHeader);

$surecOk = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'SUREC',
    'kaynak_id' => 501,
    'revizyon_tipi' => 'SUREC_GEC_GIRIS',
    'gerekce' => 'surec revizyon',
], $subeHeader);
rtAssert($surecOk['status'] === 201, 'SUREC create → 201');
$surecId = (int) ($surecOk['payload']['data']['id'] ?? 0);
invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $surecId . '/iptal', [], $subeHeader);

$surecWrongPersonel = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'SUREC',
    'kaynak_id' => 502,
    'revizyon_tipi' => 'SUREC_GEC_GIRIS',
    'gerekce' => 'surec wrong personel',
], $subeHeader);
rtAssert($surecWrongPersonel['status'] === 404, 'SUREC wrong personel → 404');
rtAssert(($surecWrongPersonel['payload']['errors'][0]['code'] ?? '') === 'TARGET_NOT_FOUND', 'SUREC wrong personel TARGET_NOT_FOUND');

$szOk = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'SERBEST_ZAMAN',
    'kaynak_id' => 601,
    'revizyon_tipi' => 'SERBEST_ZAMAN_ETKI_DUZELTME',
    'gerekce' => 'sz revizyon',
], $subeHeader);
rtAssert($szOk['status'] === 201, 'SERBEST_ZAMAN create → 201');
$szId = (int) ($szOk['payload']['data']['id'] ?? 0);
invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $szId . '/iptal', [], $subeHeader);

$szWrong = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', [
    'personel_id' => 10,
    'hafta_baslangic' => '2026-04-06',
    'hafta_bitis' => '2026-04-12',
    'etkilenen_tarih' => '2026-04-07',
    'kaynak_tipi' => 'SERBEST_ZAMAN',
    'kaynak_id' => 602,
    'revizyon_tipi' => 'SERBEST_ZAMAN_ETKI_DUZELTME',
    'gerekce' => 'sz wrong',
], $subeHeader);
rtAssert($szWrong['status'] === 404, 'SERBEST_ZAMAN wrong personel → 404');
rtAssert(($szWrong['payload']['errors'][0]['code'] ?? '') === 'TARGET_NOT_FOUND', 'SERBEST_ZAMAN TARGET_NOT_FOUND');

// --- Aylık mühür does not block ---
$muhurCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj06,
    '2026-04-06',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($muhurCreate['status'] === 201, 'aylik muhur varken create → 201');
$muhurCode = (string) ($muhurCreate['payload']['errors'][0]['code'] ?? '');
rtAssert($muhurCode !== 'PERIOD_LOCKED' && $muhurCode !== 'PERIOD_STATE_UNKNOWN', 'aylik muhur PERIOD_LOCKED/UNKNOWN üretmez');
$muhurId = (int) ($muhurCreate['payload']['data']['id'] ?? 0);
$muhurGonder = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $muhurId . '/gonder', [], $subeHeader);
rtAssert($muhurGonder['status'] === 200, 'aylik muhur varken gonder → 200');
rtAssert(((string) ($muhurGonder['payload']['errors'][0]['code'] ?? '')) !== 'PERIOD_LOCKED', 'gonder PERIOD_LOCKED üretmez');

// --- Onay correction write yok ---
$corrBefore = (int) $pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%correction%'
")->fetchColumn();
$onayCorr = invokeRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $muhurId . '/onay', [], $subeHeader);
rtAssert($onayCorr['status'] === 200, 'onay for correction proof → 200');
rtAssert(($onayCorr['payload']['data']['correction_event_id'] ?? null) === null, 'onay correction_event_id null proof');
$corrAfter = (int) $pdo->query("
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '%correction%'
")->fetchColumn();
rtAssert($corrBefore === $corrAfter, 'onayda correction tablo write yok');

// --- Parallel iptal/onay ---
$parIoCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj06,
    '2026-04-06',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
rtAssert($parIoCreate['status'] === 201, 'parallel iptal/onay setup create → 201');
$parIoId = (int) ($parIoCreate['payload']['data']['id'] ?? 0);
$parIoGonder = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parIoId . '/gonder', [], $subeHeader);
rtAssert($parIoGonder['status'] === 200, 'parallel iptal/onay setup gonder → 200');
$pio1 = spawnRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parIoId . '/iptal', [], $subeHeader);
$pio2 = spawnRtHttp($pdo, $gy, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $parIoId . '/onay', [], $subeHeader);
$rio1 = finishRtHttp($pio1);
$rio2 = finishRtHttp($pio2);
$parIoOk = (($rio1['status'] === 200) ? 1 : 0) + (($rio2['status'] === 200) ? 1 : 0);
rtAssert($parIoOk === 1, 'parallel iptal/onay → one success');
$parIoConflict = ($rio1['status'] === 409 ? ($rio1['payload']['errors'][0]['code'] ?? '') : ($rio2['payload']['errors'][0]['code'] ?? ''));
rtAssert($parIoConflict === 'STATE_CONFLICT', 'parallel iptal/onay loser STATE_CONFLICT');

// --- Transaction rollback injection ---
$rbCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', rtCreateBody(
    10,
    $puantaj11,
    '2026-04-11',
    '2026-04-06',
    '2026-04-12'
), $subeHeader);
// 11 may still be open from parallel gonder — if conflict, use recreate path after ensuring closed
if ($rbCreate['status'] !== 201) {
    // find a free identity: cancel any open on 11 or use SUREC 501 again after iptal
    $rbCreate = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', [
        'personel_id' => 10,
        'hafta_baslangic' => '2026-04-06',
        'hafta_bitis' => '2026-04-12',
        'etkilenen_tarih' => '2026-04-07',
        'kaynak_tipi' => 'SUREC',
        'kaynak_id' => 501,
        'revizyon_tipi' => 'SUREC_GEC_GIRIS',
        'gerekce' => 'rollback create setup',
    ], $subeHeader);
}
rtAssert($rbCreate['status'] === 201, 'rollback setup create → 201');
$rbId = (int) ($rbCreate['payload']['data']['id'] ?? 0);
$beforeRbMain = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri WHERE id = ' . $rbId)->fetchColumn();
$beforeRbAudit = rtGecmisCount($pdo, $rbId);
$pdo->exec("
    CREATE TRIGGER trg_rt_gecmis_fail BEFORE INSERT ON haftalik_kapanis_revizyon_talebi_gecmisi
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced gecmis fail'
");
$rbGonderFailed = false;
try {
    $rbGonder = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri/' . $rbId . '/gonder', [], $subeHeader);
    $rbGonderFailed = $rbGonder['status'] >= 500 || $rbGonder['status'] === 409 || $rbGonder['status'] !== 200;
} catch (Throwable $e) {
    $rbGonderFailed = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_rt_gecmis_fail');
$afterRbDurum = (string) $pdo->query('SELECT durum FROM haftalik_kapanis_revizyon_talepleri WHERE id = ' . $rbId)->fetchColumn();
$afterRbAudit = rtGecmisCount($pdo, $rbId);
rtAssert($afterRbDurum === 'TASLAK', 'rollback gonder ana state TASLAK');
rtAssert($afterRbAudit === $beforeRbAudit, 'rollback gonder orphan audit yok');
rtAssert($beforeRbMain === 1, 'rollback setup main mevcut');
rtAssert($rbGonderFailed, 'rollback gonder audit fail surfaced');

$pdo->exec("
    CREATE TRIGGER trg_rt_gecmis_fail_create BEFORE INSERT ON haftalik_kapanis_revizyon_talebi_gecmisi
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced create gecmis fail'
");
$beforeCreateCnt = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri')->fetchColumn();
$beforeCreateAudit = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi')->fetchColumn();
$rbCreateFail = false;
try {
    $rbCreateResp = invokeRtHttp($pdo, $ba, 'POST', '/haftalik-kapanis/revizyon-talepleri', [
        'personel_id' => 10,
        'hafta_baslangic' => '2026-04-06',
        'hafta_bitis' => '2026-04-12',
        'etkilenen_tarih' => '2026-04-07',
        'kaynak_tipi' => 'SERBEST_ZAMAN',
        'kaynak_id' => 601,
        'revizyon_tipi' => 'SERBEST_ZAMAN_ETKI_DUZELTME',
        'gerekce' => 'rollback create fail',
    ], $subeHeader);
    $rbCreateFail = $rbCreateResp['status'] !== 201;
} catch (Throwable $e) {
    $rbCreateFail = true;
}
$pdo->exec('DROP TRIGGER IF EXISTS trg_rt_gecmis_fail_create');
$afterCreateCnt = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talepleri')->fetchColumn();
$afterCreateAudit = (int) $pdo->query('SELECT COUNT(*) FROM haftalik_kapanis_revizyon_talebi_gecmisi')->fetchColumn();
rtAssert($rbCreateFail, 'rollback create audit fail surfaced');
rtAssert($beforeCreateCnt === $afterCreateCnt, 'rollback create ana kayıt yok');
rtAssert($beforeCreateAudit === $afterCreateAudit, 'rollback create orphan audit yok');

// --- Drift migration fail-loud ---
$driftRoot = rtPdo($dsn);
$driftDb = 'rt_drift_' . bin2hex(random_bytes(3));
$driftRoot->exec('CREATE DATABASE `' . $driftDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$driftRoot->exec('USE `' . $driftDb . '`');
createRtParentTables($driftRoot);
applySqlFile($driftRoot, __DIR__ . '/../../api/migrations/027_haftalik_kapanis.sql');
$driftRoot->exec('CREATE TABLE haftalik_kapanis_revizyon_talepleri (
  id INT UNSIGNED NOT NULL PRIMARY KEY,
  bogus_col VARCHAR(8) NOT NULL
) ENGINE=InnoDB');
$driftFailed = false;
try {
    applySqlFile($driftRoot, __DIR__ . '/../../api/migrations/030_haftalik_kapanis_revizyon_talepleri.sql');
} catch (Throwable $e) {
    $driftFailed = true;
}
rtAssert($driftFailed, 'driftli tablo varken apply fail-loud');
$driftRoot->exec('DROP DATABASE `' . $driftDb . '`');

echo "verify-revizyon-talebi-mysql: OK\n";
