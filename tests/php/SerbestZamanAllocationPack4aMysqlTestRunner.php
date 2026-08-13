<?php

declare(strict_types=1);

/**
 * Pack 4A: disposable MariaDB — Serbest Zaman KULLANIM→OLUSUM allocation ledger.
 * php tests/php/SerbestZamanAllocationPack4aMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\SerbestZamanController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;
use Medisa\Api\Services\SerbestZaman\SerbestZamanAllocationService;

function p4aAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function p4aRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function p4aSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    $inSingle = false;
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if (!$inSingle && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
            continue;
        }
        if (!$inTrigger && !$inSingle && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            if ($line[$i] !== "'") {
                continue;
            }
            if ($inSingle && $i + 1 < $len && $line[$i + 1] === "'") {
                $i++;
                continue;
            }
            $inSingle = !$inSingle;
        }
        if ($inSingle) {
            continue;
        }
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function p4aApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (p4aSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function p4aPdoForDb(string $database): PDO
{
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN'));

    return new PDO(
        (string) $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

/** @return list<string> */
function p4aMigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name);
    }));
    sort($files, SORT_STRING);

    return $files;
}

function p4aAssertSafeTarget(string $database): void
{
    $dbLower = strtolower($database);
    if (strpos($dbLower, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: refused database name containing karmotor_medisa\n");
        exit(1);
    }
    $dsn = (string) (getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    if (stripos($dsn, 'karmotor_medisa') !== false) {
        fwrite(STDERR, "ABORT: DSN contains karmotor_medisa\n");
        exit(1);
    }
    if (!preg_match('/host=([^;]+)/i', $dsn, $m)) {
        return;
    }
    $host = strtolower(trim($m[1]));
    if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "ABORT: host suggests production ({$host})\n");
        exit(1);
    }
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

    $statusFile = tempnam(sys_get_temp_dir(), 'p4a_http_');
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

    if ($method === 'GET' && $path === '/serbest-zaman/bakiye') {
        SerbestZamanController::bakiye($request);
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

function p4aSeedBase(PDO $pdo): void
{
    $hash = password_hash('P4aPack4TestPass-24chars!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (1, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (10, '11111111110', 'Aktif', 'Bir', '1990-01-01', '05000000000', 'Acil', '05000000001',
            'S010', '2010-01-01', 1, 'AKTIF'),
         (20, '22222222220', 'Diger', 'Personel', '1990-01-01', '05000000004', 'Acil', '05000000005',
            'S020', '2010-01-01', 1, 'AKTIF'),
         (30, '33333333330', 'Expiry', 'Uc', '1990-01-01', '05000000006', 'Acil', '05000000007',
            'S030', '2010-01-01', 1, 'AKTIF'),
         (40, '44444444440', 'Tie', 'Dort', '1990-01-01', '05000000008', 'Acil', '05000000009',
            'S040', '2010-01-01', 1, 'AKTIF'),
         (50, '55555555550', 'Race', 'Bes', '1990-01-01', '05000000010', 'Acil', '05000000011',
            'S050', '2010-01-01', 1, 'AKTIF'),
         (60, '66666666660', 'Legacy', 'Alti', '1990-01-01', '05000000012', 'Acil', '05000000013',
            'S060', '2010-01-01', 1, 'AKTIF'),
         (70, '77777777770', 'SixM', 'Yedi', '1990-01-01', '05000000014', 'Acil', '05000000015',
            'S070', '2010-01-01', 1, 'AKTIF'),
         (80, '88888888880', 'Cancel', 'Sekiz', '1990-01-01', '05000000016', 'Acil', '05000000017',
            'S080', '2010-01-01', 1, 'AKTIF')"
    );
}

/** Unique hafta_baslangic per sube scope (uq_haftalik_kapanis_scope). */
function p4aNextWeekStart(): string
{
    static $n = 0;
    // Fixed Monday 2024-01-01 + 7*n days — never collide within runner
    $base = strtotime('2024-01-01');
    $ts = $base + ($n * 7 * 86400);
    $n++;

    return date('Y-m-d', $ts);
}

/**
 * Direct SQL OLUSUM + weekly graph (HTTP olusum skipped for controlled son_kullanim).
 *
 * @return array{kapanis_id:int,satir_id:int,tercih_id:int,olusum_id:int}
 */
function p4aSeedOlusum(
    PDO $pdo,
    int $personelId,
    int $olusumDakika,
    string $sonKullanim,
    ?string $eventTarihi = null,
    ?string $haftaBaslangic = null
): array {
    $haftaBaslangic = $haftaBaslangic ?? p4aNextWeekStart();
    $haftaBitis = date('Y-m-d', strtotime($haftaBaslangic . ' +6 days'));
    $eventTarihi = $eventTarihi ?? $haftaBaslangic;
    $pdo->exec(
        "INSERT INTO haftalik_kapanislar
            (sube_id, hafta_baslangic, hafta_bitis, state, personel_sayisi, snapshot_satir_sayisi, created_by)
         VALUES (1, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI', 1, 1, 1)"
    );
    $kapanisId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO haftalik_kapanis_satirlari (
            kapanis_id, personel_id, hafta_baslangic, hafta_bitis, state,
            toplam_net_dakika, normal_calisma_dakika, fazla_calisma_dakika, fazla_surelerle_calisma_dakika,
            tam_hafta_verisi, compliance_uyarilari_json, compliance_uyari_sayisi, kritik_uyari_var_mi,
            hesaplama_zamani, kaynak_gun_sayisi, notlar_json
         ) VALUES (
            {$kapanisId}, {$personelId}, '{$haftaBaslangic}', '{$haftaBitis}', 'KAPANDI',
            3000, 2700, {$olusumDakika}, 0,
            1, '[]', 0, 0,
            '{$haftaBaslangic} 18:00:00', 7, NULL
         )"
    );
    $satirId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO fazla_calisma_odeme_tercihleri (
            snapshot_id, kapanis_id, personel_id, hafta_baslangic, hafta_bitis,
            fazla_calisma_dakika, odeme_tipi, secim_zamani, secen_kullanici_id, gerekce
         ) VALUES (
            {$satirId}, {$kapanisId}, {$personelId}, '{$haftaBaslangic}', '{$haftaBitis}',
            {$olusumDakika}, 'SERBEST_ZAMAN', '{$haftaBaslangic} 19:00:00', 1, 'p4a-gerekce'
         )"
    );
    $tercihId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO serbest_zaman_events (
            personel_id, event_tipi, dakika, event_tarihi, son_kullanim_tarihi,
            kaynak_snapshot_id, kaynak_odeme_tercihi_id, created_by
         ) VALUES (
            {$personelId}, 'SERBEST_ZAMAN_OLUSUM', {$olusumDakika}, '{$eventTarihi}', '{$sonKullanim}',
            {$satirId}, {$tercihId}, 1
         )"
    );
    $olusumId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO serbest_zaman_aktif_olusumlar (odeme_tercihi_id, olusum_event_id)
         VALUES ({$tercihId}, {$olusumId})"
    );

    return [
        'kapanis_id' => $kapanisId,
        'satir_id' => $satirId,
        'tercih_id' => $tercihId,
        'olusum_id' => $olusumId,
    ];
}

function p4aInsertLegacyKullanim(PDO $pdo, int $personelId, int $dakika, string $eventTarihi, string $anahtar): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO serbest_zaman_events (
            personel_id, event_tipi, dakika, event_tarihi, islem_anahtari, created_by
         ) VALUES (
            :pid, 'SERBEST_ZAMAN_KULLANIM', :dakika, :tarih, :anahtar, 1
         )"
    );
    $stmt->execute([
        'pid' => $personelId,
        'dakika' => $dakika,
        'tarih' => $eventTarihi,
        'anahtar' => $anahtar,
    ]);

    return (int) $pdo->lastInsertId();
}

/** @return array<int,int> olusum_id => net */
function p4aNetByLotForUsage(PDO $pdo, int $kullanimId): array
{
    return SerbestZamanAllocationService::netAllocationByLotForUsage($pdo, $kullanimId);
}

function p4aAllocRowCount(PDO $pdo, ?int $kullanimId = null): int
{
    if ($kullanimId === null) {
        return (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri')->fetchColumn();
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri WHERE kullanim_event_id = :k');
    $stmt->execute(['k' => $kullanimId]);

    return (int) $stmt->fetchColumn();
}

function p4aNetForUsage(PDO $pdo, int $kullanimId): int
{
    return SerbestZamanAllocationService::netAllocatedForUsage($pdo, $kullanimId);
}

// --- HTTP child handled above; main runner below ---

$root = p4aRootPdo();
$database = 'medisa_sz_alloc_pack4a_' . substr(bin2hex(random_bytes(4)), 0, 8);
p4aAssertSafeTarget($database);
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = p4aPdoForDb($database);

$gy = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$subeHeader = ['x-active-sube-id' => '1'];
$referans = '2026-06-15';

try {
    $files = p4aMigrationFiles();
    p4aAssert(count($files) >= 61, 'migrations 001→061 present');
    p4aAssert(end($files) === '061_serbest_zaman_kullanim_tahsisleri.sql', 'tip ends with 061');
    foreach ($files as $file) {
        p4aApply($pdo, $file);
    }

    $eventsAfterMig = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events')->fetchColumn();
    $allocAfterMig = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri')->fetchColumn();
    p4aAssert($eventsAfterMig === 0, '061 NO backfill: events empty after migrate');
    p4aAssert($allocAfterMig === 0, '061 NO backfill: allocations empty after migrate');

    p4aSeedBase($pdo);

    // ---------- Isolation personel 20 lots (D) — seed before personel 10 usage ----------
    $otherA = p4aSeedOlusum($pdo, 20, 300, '2026-12-31');
    $otherB = p4aSeedOlusum($pdo, 20, 300, '2026-12-31');

    // ---------- Personel 10: A early expiry + B later ----------
    $lotA = p4aSeedOlusum($pdo, 10, 300, '2026-07-01', '2026-01-05');
    $lotB = p4aSeedOlusum($pdo, 10, 300, '2026-09-01', '2026-01-12');
    $oidA = (int) $lotA['olusum_id'];
    $oidB = (int) $lotB['olusum_id'];
    p4aAssert($oidA < $oidB, 'OLUSUM A id < B id');

    // C) insufficient 700 vs 600 — no event, no allocations
    $eventsBeforeC = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 10')->fetchColumn();
    $allocBeforeC = p4aAllocRowCount($pdo);
    $insuff = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 10,
        'dakika' => 700,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-insuff-700',
    ], $subeHeader);
    p4aAssert($insuff['status'] === 409, 'C usage 700 → 409');
    p4aAssert(
        ($insuff['payload']['errors'][0]['code'] ?? '') === 'INSUFFICIENT_BALANCE',
        'C INSUFFICIENT_BALANCE'
    );
    $eventsAfterC = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 10')->fetchColumn();
    p4aAssert($eventsAfterC === $eventsBeforeC, 'C no event created');
    p4aAssert(p4aAllocRowCount($pdo) === $allocBeforeC, 'C no allocations');

    // A) usage 400 → A300 + B100
    $kulA = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 10,
        'dakika' => 400,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-kul-400',
    ], $subeHeader);
    p4aAssert($kulA['status'] === 200, 'A usage 400 → 200');
    $kullanimId = (int) ($kulA['payload']['data']['id'] ?? 0);
    p4aAssert($kullanimId > 0, 'A kullanim id');
    $netA = p4aNetByLotForUsage($pdo, $kullanimId);
    p4aAssert(($netA[$oidA] ?? 0) === 300, 'A allocates A300');
    p4aAssert(($netA[$oidB] ?? 0) === 100, 'A allocates B100');
    p4aAssert(p4aNetForUsage($pdo, $kullanimId) === 400, 'A SUM(delta)=400');
    $allocCountAfterA = p4aAllocRowCount($pdo, $kullanimId);
    p4aAssert($allocCountAfterA === 2, 'A two positive allocation rows');

    // B) same islem_anahtari retry
    $retry = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 10,
        'dakika' => 400,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-kul-400',
    ], $subeHeader);
    p4aAssert($retry['status'] === 200, 'B retry → 200');
    p4aAssert((int) ($retry['payload']['data']['id'] ?? 0) === $kullanimId, 'B same event id');
    p4aAssert(p4aAllocRowCount($pdo, $kullanimId) === $allocCountAfterA, 'B allocation count unchanged');

    // D) other personel lots never allocated
    $otherNet = (int) $pdo->query(
        'SELECT COALESCE(SUM(tahsis_delta_dakika),0) FROM serbest_zaman_kullanim_tahsisleri
         WHERE olusum_event_id IN (' . (int) $otherA['olusum_id'] . ',' . (int) $otherB['olusum_id'] . ')'
    )->fetchColumn();
    p4aAssert($otherNet === 0, 'D other personel lots never allocated');
    $pid20Alloc = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri WHERE personel_id = 20'
    )->fetchColumn();
    p4aAssert($pid20Alloc === 0, 'D personel 20 allocation rows = 0');

    // Snapshot positive rows before correction (append-only)
    $positiveBefore = $pdo->query(
        'SELECT id, olusum_event_id, tahsis_delta_dakika FROM serbest_zaman_kullanim_tahsisleri
         WHERE kullanim_event_id = ' . $kullanimId . ' AND tahsis_delta_dakika > 0
         ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    // Correction: 400→250 releases B100 then A50
    $duzDown = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 10,
        'hedef_event_id' => $kullanimId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'yeni_dakika' => 250,
        'event_tarihi' => '2026-06-16',
        'islem_anahtari' => 'p4a-duz-250',
        'aciklama' => 'reduce to 250',
    ], $subeHeader);
    p4aAssert($duzDown['status'] === 200, 'correction 400→250 → 200');
    $netDown = p4aNetByLotForUsage($pdo, $kullanimId);
    p4aAssert(($netDown[$oidA] ?? 0) === 250, 'correction net A250');
    p4aAssert(($netDown[$oidB] ?? 0) === 0, 'correction net B0 (released B100)');
    p4aAssert(p4aNetForUsage($pdo, $kullanimId) === 250, 'correction SUM=250');
    $releaseRows = $pdo->query(
        'SELECT olusum_event_id, tahsis_delta_dakika FROM serbest_zaman_kullanim_tahsisleri
         WHERE kullanim_event_id = ' . $kullanimId . ' AND tahsis_delta_dakika < 0
         ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    p4aAssert(count($releaseRows) >= 2, 'correction released via negative deltas');
    p4aAssert((int) $releaseRows[0]['olusum_event_id'] === $oidB, 'release order first B (later expiry)');
    p4aAssert((int) $releaseRows[0]['tahsis_delta_dakika'] === -100, 'release B100');
    p4aAssert((int) $releaseRows[1]['olusum_event_id'] === $oidA, 'release order then A');
    p4aAssert((int) $releaseRows[1]['tahsis_delta_dakika'] === -50, 'release A50');
    foreach ($positiveBefore as $row) {
        $still = $pdo->query(
            'SELECT tahsis_delta_dakika FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . (int) $row['id']
        )->fetchColumn();
        p4aAssert((int) $still === (int) $row['tahsis_delta_dakika'], 'positive row id ' . $row['id'] . ' not UPDATEd');
    }

    // then 250→500 adds back
    $duzUp = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 10,
        'hedef_event_id' => $kullanimId,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'yeni_dakika' => 500,
        'event_tarihi' => '2026-06-17',
        'islem_anahtari' => 'p4a-duz-500',
        'aciklama' => 'increase to 500',
    ], $subeHeader);
    p4aAssert($duzUp['status'] === 200, 'correction 250→500 → 200');
    $netUp = p4aNetByLotForUsage($pdo, $kullanimId);
    p4aAssert(($netUp[$oidA] ?? 0) === 300, 'add-back net A300');
    p4aAssert(($netUp[$oidB] ?? 0) === 200, 'add-back net B200');
    p4aAssert(p4aNetForUsage($pdo, $kullanimId) === 500, 'add-back SUM=500');

    // Cancel scenario on personel 80: usage 400 then IPTAL → net A0 B0
    $cA = p4aSeedOlusum($pdo, 80, 300, '2026-07-01', '2026-02-02');
    $cB = p4aSeedOlusum($pdo, 80, 300, '2026-09-01', '2026-02-09');
    $cOidA = (int) $cA['olusum_id'];
    $cOidB = (int) $cB['olusum_id'];
    $kulCancel = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 80,
        'dakika' => 400,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-cancel-400',
    ], $subeHeader);
    p4aAssert($kulCancel['status'] === 200, 'cancel setup usage 400 → 200');
    $cancelKid = (int) ($kulCancel['payload']['data']['id'] ?? 0);
    $posCancel = $pdo->query(
        'SELECT id, tahsis_delta_dakika FROM serbest_zaman_kullanim_tahsisleri
         WHERE kullanim_event_id = ' . $cancelKid . ' AND tahsis_delta_dakika > 0 ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $iptal = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 80,
        'hedef_event_id' => $cancelKid,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'event_tarihi' => '2026-06-18',
        'islem_anahtari' => 'p4a-iptal-kul',
        'aciklama' => 'cancel usage',
    ], $subeHeader);
    p4aAssert($iptal['status'] === 200, 'cancel IPTAL → 200');
    $sumCancel = p4aNetForUsage($pdo, $cancelKid);
    p4aAssert($sumCancel === 0, 'cancel net SUM=0');
    $aNet = (int) $pdo->query(
        'SELECT COALESCE(SUM(tahsis_delta_dakika),0) FROM serbest_zaman_kullanim_tahsisleri
         WHERE kullanim_event_id = ' . $cancelKid . ' AND olusum_event_id = ' . $cOidA
    )->fetchColumn();
    $bNet = (int) $pdo->query(
        'SELECT COALESCE(SUM(tahsis_delta_dakika),0) FROM serbest_zaman_kullanim_tahsisleri
         WHERE kullanim_event_id = ' . $cancelKid . ' AND olusum_event_id = ' . $cOidB
    )->fetchColumn();
    p4aAssert($aNet === 0, 'cancel net A0');
    p4aAssert($bNet === 0, 'cancel net B0');
    $negCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri
         WHERE kullanim_event_id = ' . $cancelKid . ' AND tahsis_delta_dakika < 0'
    )->fetchColumn();
    p4aAssert($negCount >= 2, 'cancel uses negative deltas');
    foreach ($posCancel as $row) {
        $still = $pdo->query(
            'SELECT tahsis_delta_dakika FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . (int) $row['id']
        )->fetchColumn();
        p4aAssert((int) $still === (int) $row['tahsis_delta_dakika'], 'cancel did not UPDATE positive row ' . $row['id']);
    }

    // E) expired lot not used (referans > son_kullanim)
    $eExp = p4aSeedOlusum($pdo, 30, 300, '2026-01-01', '2025-01-06');
    $eAct = p4aSeedOlusum($pdo, 30, 300, '2026-12-01', '2026-02-02');
    $eKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 30,
        'dakika' => 200,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-exp-200',
    ], $subeHeader);
    p4aAssert($eKul['status'] === 200, 'E usage against active lot → 200');
    $eKid = (int) ($eKul['payload']['data']['id'] ?? 0);
    $eNet = p4aNetByLotForUsage($pdo, $eKid);
    p4aAssert(($eNet[(int) $eExp['olusum_id']] ?? 0) === 0, 'E expired lot not used');
    p4aAssert(($eNet[(int) $eAct['olusum_id']] ?? 0) === 200, 'E active lot used');

    // F/G) same expiry: older event_tarihi then lower id
    $sameSon = '2026-11-01';
    $t1 = p4aSeedOlusum($pdo, 40, 100, $sameSon, '2026-03-01'); // older event_tarihi
    $t2 = p4aSeedOlusum($pdo, 40, 100, $sameSon, '2026-03-10'); // newer event_tarihi
    // same event_tarihi, different ids — third lot shares expiry+tarih with a fourth
    $t3 = p4aSeedOlusum($pdo, 40, 100, $sameSon, '2026-03-20');
    $t4 = p4aSeedOlusum($pdo, 40, 100, $sameSon, '2026-03-20'); // same tarih, higher id
    $tieKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 40,
        'dakika' => 250,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-tie-250',
    ], $subeHeader);
    p4aAssert($tieKul['status'] === 200, 'F/G usage 250 → 200');
    $tieKid = (int) ($tieKul['payload']['data']['id'] ?? 0);
    $tieNet = p4aNetByLotForUsage($pdo, $tieKid);
    p4aAssert(($tieNet[(int) $t1['olusum_id']] ?? 0) === 100, 'F older event_tarihi first');
    p4aAssert(($tieNet[(int) $t2['olusum_id']] ?? 0) === 100, 'F then next event_tarihi');
    p4aAssert(($tieNet[(int) $t3['olusum_id']] ?? 0) === 50, 'G same tarih lower id next (partial)');
    p4aAssert(($tieNet[(int) $t4['olusum_id']] ?? 0) === 0, 'G higher id last unused');

    // Concurrency: available 300, both try 200 — only one succeeds, allocated <= 300
    p4aSeedOlusum($pdo, 50, 300, '2026-12-31');
    $race1 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 50,
        'dakika' => 200,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-race-a',
    ], $subeHeader);
    $race2 = spawnSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 50,
        'dakika' => 200,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-race-b',
    ], $subeHeader);
    $rr1 = finishSzHttp($race1);
    $rr2 = finishSzHttp($race2);
    $okRace = (($rr1['status'] === 200) ? 1 : 0) + (($rr2['status'] === 200) ? 1 : 0);
    p4aAssert($okRace === 1, 'concurrency only one succeeds');
    $raceAlloc = (int) $pdo->query(
        'SELECT COALESCE(SUM(tahsis_delta_dakika),0) FROM serbest_zaman_kullanim_tahsisleri WHERE personel_id = 50'
    )->fetchColumn();
    p4aAssert($raceAlloc <= 300, 'concurrency allocated <= 300');
    p4aAssert($raceAlloc === 200, 'concurrency allocated exactly 200');

    // Legacy: SQL KULLANIM without allocations
    p4aSeedOlusum($pdo, 60, 300, '2026-12-31');
    p4aSeedOlusum($pdo, 60, 300, '2026-12-31');
    $eventsBeforeLegacy = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 60')->fetchColumn();
    $legacyKid = p4aInsertLegacyKullanim($pdo, 60, 100, $referans, 'p4a-legacy-sql');
    $legacyAlloc = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_kullanim_tahsisleri WHERE kullanim_event_id = ' . $legacyKid
    )->fetchColumn();
    p4aAssert($legacyAlloc === 0, 'legacy KULLANIM has no allocations');
    $bakiyeLegacy = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
        'personel_id' => '60',
        'referans_tarih' => $referans,
    ]);
    p4aAssert($bakiyeLegacy['status'] === 200, 'legacy bakiye → 200');
    p4aAssert(
        ($bakiyeLegacy['payload']['data']['allocation_state'] ?? '') === SerbestZamanAllocationService::STATE_LEGACY_UNALLOCATED,
        'legacy allocation_state LEGACY_UNALLOCATED'
    );
    $eventsMidLegacy = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 60')->fetchColumn();
    $newLegacy = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 60,
        'dakika' => 50,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-legacy-blocked',
    ], $subeHeader);
    p4aAssert($newLegacy['status'] === 409, 'legacy new KULLANIM → 409');
    p4aAssert(
        ($newLegacy['payload']['errors'][0]['code'] ?? '') === SerbestZamanAllocationService::CODE_LEGACY_ALLOCATION_REQUIRED,
        'SERBEST_ZAMAN_LEGACY_ALLOCATION_REQUIRED'
    );
    $eventsAfterLegacy = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 60')->fetchColumn();
    p4aAssert($eventsAfterLegacy === $eventsMidLegacy, 'legacy no auto backfill / no new event');
    p4aAssert($eventsMidLegacy === $eventsBeforeLegacy + 1, 'legacy only SQL insert added one event');

    // 6-month lot foundation: fixed referans vs son_kullanim ($referans > $son)
    $sixSon = '2026-06-10';
    $six = p4aSeedOlusum($pdo, 70, 180, $sixSon, '2025-12-15');
    $bakiyeOnExpiryDay = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
        'personel_id' => '70',
        'referans_tarih' => $sixSon,
    ]);
    p4aAssert($bakiyeOnExpiryDay['status'] === 200, '6m bakiye on expiry day → 200');
    p4aAssert(
        ($bakiyeOnExpiryDay['payload']['data']['allocation_state'] ?? '') === SerbestZamanAllocationService::STATE_NO_USAGE,
        '6m NO_USAGE state'
    );
    p4aAssert(
        (int) ($bakiyeOnExpiryDay['payload']['data']['lot_based_balance_available'] ?? -1) === 180,
        '6m usable on expiry day (referans === son)'
    );
    p4aAssert(
        (int) ($bakiyeOnExpiryDay['payload']['data']['lot_based_expired_unused'] ?? -1) === 0,
        '6m not expired on expiry day'
    );
    $dayAfter = '2026-06-11';
    $bakiyeAfterExpiry = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
        'personel_id' => '70',
        'referans_tarih' => $dayAfter,
    ]);
    p4aAssert(
        (int) ($bakiyeAfterExpiry['payload']['data']['lot_based_balance_available'] ?? -1) === 0,
        '6m usable 0 when referans > son'
    );
    p4aAssert(
        (int) ($bakiyeAfterExpiry['payload']['data']['lot_based_expired_unused'] ?? -1) === 180,
        '6m expired_unused when referans > son'
    );
    // Direct projectLots boundary check
    $events70 = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 70 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $lotsOn = SerbestZamanAllocationService::projectLots($pdo, $events70, 70, $sixSon);
    $lotsAfter = SerbestZamanAllocationService::projectLots($pdo, $events70, 70, $dayAfter);
    p4aAssert(($lotsOn[0]['expiry_state'] ?? '') === 'ACTIVE', '6m expiry_state ACTIVE on son day');
    p4aAssert(($lotsAfter[0]['expiry_state'] ?? '') === 'EXPIRED', '6m expiry_state EXPIRED after son');

    // Trigger: UPDATE/DELETE blocked
    $anyId = (int) $pdo->query('SELECT id FROM serbest_zaman_kullanim_tahsisleri LIMIT 1')->fetchColumn();
    p4aAssert($anyId > 0, 'trigger test has allocation row');
    $updBlocked = false;
    try {
        $pdo->exec('UPDATE serbest_zaman_kullanim_tahsisleri SET tahsis_delta_dakika = 1 WHERE id = ' . $anyId);
    } catch (Throwable $e) {
        $updBlocked = stripos($e->getMessage(), 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE') !== false
            || stripos($e->getMessage(), '45000') !== false;
    }
    p4aAssert($updBlocked, 'allocation UPDATE blocked by trigger');
    $delBlocked = false;
    try {
        $pdo->exec('DELETE FROM serbest_zaman_kullanim_tahsisleri WHERE id = ' . $anyId);
    } catch (Throwable $e) {
        $delBlocked = stripos($e->getMessage(), 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE') !== false
            || stripos($e->getMessage(), '45000') !== false;
    }
    p4aAssert($delBlocked, 'allocation DELETE blocked by trigger');

    // Policy constants visible
    p4aAssert(
        SerbestZamanAllocationService::POLICY_CONSUME === 'EARLIEST_EXPIRY_FIRST_V1',
        'POLICY EARLIEST_EXPIRY_FIRST_V1'
    );

    echo 'verify-serbest-zaman-allocation-pack4a-mysql: OK' . PHP_EOL;
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // ignore cleanup errors
    }
}
