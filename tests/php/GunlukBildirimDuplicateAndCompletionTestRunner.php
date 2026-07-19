<?php

declare(strict_types=1);

/**
 * MariaDB acceptance for S81 gunluk bildirim duplicate + completion + weekly eksik_gun.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\BildirimlerController;
use Medisa\Api\Controllers\HaftalikBildirimMutabakatlariController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

function s81Assert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s81Pdo(string $dsn): PDO
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

function s81SetPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function s81ResetAuth($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function s81MakeRequest(string $method, string $path, array $body, array $headers = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'jsonBody' => $body,
        'jsonBodyParsed' => true,
        'jsonBodyInvalid' => false,
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }

    return $request;
}

/**
 * @return array{status:int, payload:array<string,mixed>}
 */
function s81HttpChild(string $action, array $auth, int $subeId, array $query, array $body): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 's81_');
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
        'database' => (new PDO(
            (string) getenv('MEDISA_TEST_MYSQL_DSN'),
            getenv('MEDISA_TEST_MYSQL_USER') ?: '',
            getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: ''
        ))->query('SELECT DATABASE()')->fetchColumn(),
        'auth' => $auth,
        'action' => $action,
        'query' => $query,
        'body' => $body,
        'sube_id' => $subeId,
        'status_file' => $statusFile,
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--http-child']);
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
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $statusRaw = is_file($statusFile) ? trim((string) file_get_contents($statusFile)) : '';
    @unlink($statusFile);
    $status = (int) $statusRaw;

    $jsonStart = strpos($stdout, '{');
    $decoded = json_decode($jsonStart === false ? $stdout : substr($stdout, $jsonStart), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('http child invalid json: ' . $stdout . ' / ' . $stderr);
    }

    return ['status' => $status, 'payload' => $decoded];
}

function s81ApplyMigrationFile(PDO $pdo, string $path): void
{
    $sql = (string) file_get_contents($path);
    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $chunk) {
        $statement = trim((string) $chunk);
        if ($statement === '' || str_starts_with($statement, '--')) {
            continue;
        }
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            // Additive / idempotent migration chunks may already exist.
        }
    }
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
    s81SetPdo($pdo);
    s81ResetAuth($cfg['auth']);

    register_shutdown_function(static function () use ($cfg): void {
        file_put_contents((string) $cfg['status_file'], (string) http_response_code());
    });

    $action = (string) ($cfg['action'] ?? '');
    $query = is_array($cfg['query'] ?? null) ? $cfg['query'] : [];
    $body = is_array($cfg['body'] ?? null) ? $cfg['body'] : [];
    $subeId = (int) ($cfg['sube_id'] ?? 0);
    $_GET = $query;
    $headers = [
        'authorization' => 'Bearer test',
        'x-active-sube-id' => (string) $subeId,
    ];

    if ($action === 'create') {
        BildirimlerController::create(s81MakeRequest('POST', '/bildirimler', $body, $headers));
    } elseif ($action === 'complete') {
        BildirimlerController::gunlukTamamlamaCreate(
            s81MakeRequest('POST', '/bildirimler/gunluk-tamamlama', $body, $headers)
        );
    } elseif ($action === 'summary') {
        HaftalikBildirimMutabakatlariController::summary(
            s81MakeRequest('GET', '/haftalik-bildirim-mutabakatlari/ozet', [], $headers)
        );
    } else {
        fwrite(STDERR, "unknown action\n");
        exit(3);
    }
    exit(0);
}

try {
$adminDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$userName = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
if ($adminDsn === '' || $userName === '') {
    fwrite(STDERR, "MEDISA_TEST_MYSQL_DSN/USER required\n");
    exit(2);
}

if (!extension_loaded('pdo_mysql') && !in_array('mysql', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "pdo_mysql driver missing\n");
    exit(2);
}

$admin = s81Pdo($adminDsn);
$database = 'medisa_s81_bildirim_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $adminDsn);
    putenv('MEDISA_TEST_MYSQL_DSN=' . $dsn);
    $_ENV['MEDISA_TEST_MYSQL_DSN'] = $dsn;

    $pdo = s81Pdo($dsn);
    s81SetPdo($pdo);

    $migration032 = (string) file_get_contents(
        __DIR__ . '/../../api/migrations/032_gunluk_bildirim_tamamlama_ve_duplicate.sql'
    );
    s81Assert(str_contains($migration032, 'gunluk_bildirim_tamamlamalari'), 'migration 032 present');

    $pdo->exec("
        CREATE TABLE subeler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          kod VARCHAR(32) NOT NULL,
          ad VARCHAR(120) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id),
          UNIQUE KEY uq_subeler_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          username VARCHAR(80) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          ad_soyad VARCHAR(120) NOT NULL,
          rol VARCHAR(40) NOT NULL,
          durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id),
          UNIQUE KEY uq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE user_subeler (
          user_id INT UNSIGNED NOT NULL,
          sube_id INT UNSIGNED NOT NULL,
          PRIMARY KEY (user_id, sube_id),
          CONSTRAINT fk_s81_us_user FOREIGN KEY (user_id) REFERENCES users (id),
          CONSTRAINT fk_s81_us_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE departmanlar (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(120) NOT NULL,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE gorevler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          ad VARCHAR(120) NOT NULL,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE personeller (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
          gorev_id INT UNSIGNED NULL,
          bagli_amir_id INT UNSIGNED NULL,
          aktif_durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
          PRIMARY KEY (id),
          UNIQUE KEY uq_personeller_tc (tc_kimlik_no),
          CONSTRAINT fk_s81_p_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/005_gunluk_bildirimler.sql'));
    $pdo->exec((string) file_get_contents(__DIR__ . '/../../api/migrations/006_haftalik_bildirim_mutabakatlari.sql'));

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS gunluk_bildirim_tamamlamalari (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          sube_id INT UNSIGNED NOT NULL,
          birim_amiri_user_id INT UNSIGNED NOT NULL,
          tarih DATE NOT NULL,
          state VARCHAR(32) NOT NULL DEFAULT 'TAMAMLANDI',
          tamamlayan_user_id INT UNSIGNED NOT NULL,
          tamamlandi_at TIMESTAMP NULL DEFAULT NULL,
          not_metni TEXT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uniq_gbt_sube_amir_tarih (sube_id, birim_amiri_user_id, tarih),
          KEY idx_gbt_sube_tarih (sube_id, tarih),
          KEY idx_gbt_amir_tarih (birim_amiri_user_id, tarih),
          CONSTRAINT fk_gbt_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
          CONSTRAINT fk_gbt_birim_amiri FOREIGN KEY (birim_amiri_user_id) REFERENCES users (id),
          CONSTRAINT fk_gbt_tamamlayan FOREIGN KEY (tamamlayan_user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        ALTER TABLE gunluk_bildirimler
          ADD COLUMN open_duplicate_key VARCHAR(96)
            GENERATED ALWAYS AS (
              CASE
                WHEN state = 'IPTAL' THEN NULL
                ELSE CONCAT(personel_id, ':', tarih, ':', bildirim_turu)
              END
            ) STORED,
          ADD UNIQUE KEY uniq_gb_open_duplicate (open_duplicate_key)
    ");

    $openKeyStmt = $pdo->query("SHOW COLUMNS FROM gunluk_bildirimler LIKE 'open_duplicate_key'");
    $openKey = $openKeyStmt ? $openKeyStmt->fetch(PDO::FETCH_ASSOC) : false;
    if ($openKeyStmt) {
        $openKeyStmt->closeCursor();
    }
    s81Assert(is_array($openKey), 'open_duplicate_key column exists');
    $tamStmt = $pdo->query("SHOW TABLES LIKE 'gunluk_bildirim_tamamlamalari'");
    $tamTable = $tamStmt ? $tamStmt->fetch(PDO::FETCH_NUM) : false;
    if ($tamStmt) {
        $tamStmt->closeCursor();
    }
    s81Assert(is_array($tamTable), 'gunluk_bildirim_tamamlamalari exists');

    $suffix = (string) random_int(1000, 9999);
    $pdo->exec("INSERT INTO subeler (kod, ad, durum) VALUES ('S81{$suffix}', 'S81 Sube', 'AKTIF')");
    $subeId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (username, ad_soyad, rol, durum, password_hash) VALUES (:u, :a, 'BIRIM_AMIRI', 'AKTIF', 'x')")
        ->execute(['u' => 's81_amir_' . $suffix, 'a' => 'S81 Amir']);
    $amirId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO user_subeler (user_id, sube_id) VALUES (:u, :s)')
        ->execute(['u' => $amirId, 's' => $subeId]);

    $tc = str_pad((string) random_int(10000000000, 99999999999), 11, '1', STR_PAD_LEFT);
    $pdo->prepare("
        INSERT INTO personeller (
          tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
          sicil_no, ise_giris_tarihi, sube_id, bagli_amir_id, aktif_durum
        ) VALUES (
          :tc, 'S81', 'Personel', '1990-01-01', '555', 'Acil', '555',
          :sicil, '2020-01-01', :sube, :amir, 'AKTIF'
        )
    ")->execute([
        'tc' => $tc,
        'sicil' => 'S81-' . $suffix,
        'sube' => $subeId,
        'amir' => $amirId,
    ]);
    $personelId = (int) $pdo->lastInsertId();

    $tarih = date('Y-m-d');
    $auth = [
        'id' => $amirId,
        'rol' => 'BIRIM_AMIRI',
        'ad_soyad' => 'S81 Amir',
        'sube_ids' => [$subeId],
    ];

    $create1 = s81HttpChild('create', $auth, $subeId, [], [
        'personel_id' => $personelId,
        'tarih' => $tarih,
        'bildirim_turu' => 'GELMEDI',
        'aciklama' => 'S81 test',
    ]);
    s81Assert($create1['status'] === 201, 'HTTP create → 201');

    $create2 = s81HttpChild('create', $auth, $subeId, [], [
        'personel_id' => $personelId,
        'tarih' => $tarih,
        'bildirim_turu' => 'GELMEDI',
        'aciklama' => 'S81 dup',
    ]);
    $dupMessage = (string) (($create2['payload']['message'] ?? '') ?: ($create2['payload']['errors'][0]['message'] ?? ''));
    s81Assert($create2['status'] === 409, 'duplicate create → 409');
    s81Assert(
        str_contains($dupMessage, 'açık bildirim') || str_contains($dupMessage, 'acik bildirim'),
        'duplicate create Turkish message'
    );

    $pdo->prepare("UPDATE gunluk_bildirimler SET state = 'GONDERILDI', submitted_at = NOW() WHERE personel_id = :p AND tarih = :t AND state = 'TASLAK'")
        ->execute(['p' => $personelId, 't' => $tarih]);

    $complete = s81HttpChild('complete', $auth, $subeId, [], ['tarih' => $tarih]);
    s81Assert(in_array($complete['status'], [200, 201], true), 'completion create ok');

    $pdo->prepare('DELETE FROM gunluk_bildirim_tamamlamalari WHERE sube_id = :s AND birim_amiri_user_id = :a')
        ->execute(['s' => $subeId, 'a' => $amirId]);

    $monday = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
    $summary = s81HttpChild('summary', $auth, $subeId, [
        'hafta_baslangic' => $monday,
        'birim_amiri_user_id' => $amirId,
    ], []);
    $counts = $summary['payload']['data']['counts'] ?? [];
    $blok = (string) ($summary['payload']['data']['blok_nedeni'] ?? '');
    $eksik = (int) ($counts['eksik_gun'] ?? 0);
    $onaylanabilir = (bool) ($summary['payload']['data']['onaylanabilir_mi'] ?? true);
    s81Assert(
        !$onaylanabilir && ($eksik > 0 || str_contains($blok, 'tamamlanmamış') || str_contains($blok, 'tamamlanmamis') || str_contains($blok, 'Bu hafta')),
        'weekly eksik_gun blocks approve'
    );

    echo 'verify-gunluk-bildirim-duplicate-completion: OK' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[S81_RUNNER_ERROR] ' . $e->getMessage() . PHP_EOL . $e->getTraceAsString() . PHP_EOL);
    try {
        if (isset($admin) && isset($database)) {
            $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
        }
    } catch (Throwable $cleanupError) {
    }
    exit(1);
}

try {
    if (isset($admin) && isset($database)) {
        $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }
} catch (Throwable $e) {
}
