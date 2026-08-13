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
            'S080', '2010-01-01', 1, 'AKTIF'),
         (90, '99999999990', 'Partial', 'Dokuz', '1990-01-01', '05000000018', 'Acil', '05000000019',
            'S090', '2010-01-01', 1, 'AKTIF'),
         (91, '10101010100', 'Olusum', 'Gate', '1990-01-01', '05000000020', 'Acil', '05000000021',
            'S091', '2010-01-01', 1, 'AKTIF'),
         (92, '12121212120', 'Olusum', 'Corr', '1990-01-01', '05000000022', 'Acil', '05000000023',
            'S092', '2010-01-01', 1, 'AKTIF'),
         (93, '13131313130', 'Olusum', 'After', '1990-01-01', '05000000024', 'Acil', '05000000025',
            'S093', '2010-01-01', 1, 'AKTIF'),
         (94, '14141414140', 'Inv', 'Broken', '1990-01-01', '05000000026', 'Acil', '05000000027',
            'S094', '2010-01-01', 1, 'AKTIF'),
         (95, '15151515150', 'Legacy', 'Mut', '1990-01-01', '05000000028', 'Acil', '05000000029',
            'S095', '2010-01-01', 1, 'AKTIF'),
         (96, '16161616160', 'Legacy', 'Cancel', '1990-01-01', '05000000030', 'Acil', '05000000031',
            'S096', '2010-01-01', 1, 'AKTIF'),
         (97, '17171717170', 'Inv', 'Mut', '1990-01-01', '05000000032', 'Acil', '05000000033',
            'S097', '2010-01-01', 1, 'AKTIF'),
         (98, '18181818180', 'Legacy', 'Multi', '1990-01-01', '05000000034', 'Acil', '05000000035',
            'S098', '2010-01-01', 1, 'AKTIF'),
         (99, '19191919190', 'Alloc', 'Valid', '1990-01-01', '05000000036', 'Acil', '05000000037',
            'S099', '2010-01-01', 1, 'AKTIF')"
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

function p4aInsertAllocationDelta(
    PDO $pdo,
    int $personelId,
    int $kullanimEventId,
    int $olusumEventId,
    int $kaynakEventId,
    int $delta
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO serbest_zaman_kullanim_tahsisleri
            (personel_id, kullanim_event_id, olusum_event_id, kaynak_event_id,
             tahsis_delta_dakika, politika_kodu)
         VALUES
            (:pid, :kid, :oid, :sid, :delta, :politika)'
    );
    $stmt->execute([
        'pid' => $personelId,
        'kid' => $kullanimEventId,
        'oid' => $olusumEventId,
        'sid' => $kaynakEventId,
        'delta' => $delta,
        'politika' => SerbestZamanAllocationService::POLICY_CONSUME,
    ]);
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
    p4aAssert(end($files) === '062_serbest_zaman_retention_destroy_gate.sql', 'tip ends with 062');
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

    // 6-month partially-consumed lots via real allocation ledger (not handcrafted summarize)
    // While active: A300+B300 early, C300 later. usage400 → A300 B100; after early expiry usage50 → C50.
    $earlySon = '2026-06-10';
    $lateSon = '2026-12-31';
    $activeRef = '2026-06-01';
    $afterEarlyRef = '2026-06-11';
    $pA = p4aSeedOlusum($pdo, 90, 300, $earlySon, '2026-01-01');
    $pB = p4aSeedOlusum($pdo, 90, 300, $earlySon, '2026-01-02');
    $pC = p4aSeedOlusum($pdo, 90, 300, $lateSon, '2026-01-03');
    $partialKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 90,
        'dakika' => 400,
        'event_tarihi' => $activeRef,
        'islem_anahtari' => 'p4a-partial-400',
    ], $subeHeader);
    p4aAssert($partialKul['status'] === 200, 'partial 6m usage400 → 200');
    $partialKid = (int) ($partialKul['payload']['data']['id'] ?? 0);
    $partialNet = p4aNetByLotForUsage($pdo, $partialKid);
    p4aAssert(($partialNet[(int) $pA['olusum_id']] ?? 0) === 300, 'partial A allocated 300');
    p4aAssert(($partialNet[(int) $pB['olusum_id']] ?? 0) === 100, 'partial B allocated 100');
    $partialKul2 = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 90,
        'dakika' => 50,
        'event_tarihi' => $afterEarlyRef,
        'islem_anahtari' => 'p4a-partial-50',
    ], $subeHeader);
    p4aAssert($partialKul2['status'] === 200, 'partial 6m usage50 after early expiry → 200');
    $partialKid2 = (int) ($partialKul2['payload']['data']['id'] ?? 0);
    $partialNet2 = p4aNetByLotForUsage($pdo, $partialKid2);
    p4aAssert(($partialNet2[(int) $pC['olusum_id']] ?? 0) === 50, 'partial C allocated 50');
    p4aAssert(($partialNet2[(int) $pB['olusum_id']] ?? 0) === 0, 'partial second usage skips expired B');
    $bakiyePartial = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
        'personel_id' => '90',
        'referans_tarih' => $afterEarlyRef,
    ]);
    p4aAssert($bakiyePartial['status'] === 200, 'partial 6m bakiye → 200');
    p4aAssert(
        (int) ($bakiyePartial['payload']['data']['lot_based_expired_unused'] ?? -1) === 200,
        'partial expired_unused=200 (B remaining)'
    );
    p4aAssert(
        (int) ($bakiyePartial['payload']['data']['lot_based_balance_available'] ?? -1) === 250,
        'partial usable=250 (C remaining)'
    );
    $events90 = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 90 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $lots90 = SerbestZamanAllocationService::projectLots($pdo, $events90, 90, $afterEarlyRef);
    $sum90 = SerbestZamanAllocationService::summarizeLotBalance($lots90);
    p4aAssert((int) $sum90['expired_unused_dakika'] === 200, 'partial summarize expired_unused=200');
    p4aAssert((int) $sum90['usable_dakika'] === 250, 'partial summarize usable=250');

    // Merge-blocker A: OLUSUM IPTAL blocked while net allocation > 0
    $gateA = p4aSeedOlusum($pdo, 91, 300, '2026-12-31');
    $gateKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 91,
        'dakika' => 300,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-gate-a-kul',
    ], $subeHeader);
    p4aAssert($gateKul['status'] === 200, 'gate A usage → 200');
    $eventsBeforeGateIptal = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 91'
    )->fetchColumn();
    $aktifBefore = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE olusum_event_id = ' . (int) $gateA['olusum_id']
    )->fetchColumn();
    $allocBeforeGate = (int) $pdo->query(
        'SELECT COALESCE(SUM(tahsis_delta_dakika),0) FROM serbest_zaman_kullanim_tahsisleri
         WHERE olusum_event_id = ' . (int) $gateA['olusum_id']
    )->fetchColumn();
    $gateIptal = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 91,
        'hedef_event_id' => (int) $gateA['olusum_id'],
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-gate-a-iptal',
    ], $subeHeader);
    p4aAssert($gateIptal['status'] === 409, 'A OLUSUM IPTAL with allocation → 409');
    p4aAssert(
        ($gateIptal['payload']['errors'][0]['code'] ?? '') === SerbestZamanAllocationService::CODE_OLUSUM_HAS_ALLOCATIONS,
        'A SERBEST_ZAMAN_OLUSUM_HAS_ALLOCATIONS'
    );
    $eventsAfterGateIptal = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 91'
    )->fetchColumn();
    p4aAssert($eventsAfterGateIptal === $eventsBeforeGateIptal, 'A no IPTAL event persisted');
    $aktifAfter = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE olusum_event_id = ' . (int) $gateA['olusum_id']
    )->fetchColumn();
    p4aAssert($aktifAfter === $aktifBefore && $aktifAfter === 1, 'A aktif_olusum remains');
    $allocAfterGate = (int) $pdo->query(
        'SELECT COALESCE(SUM(tahsis_delta_dakika),0) FROM serbest_zaman_kullanim_tahsisleri
         WHERE olusum_event_id = ' . (int) $gateA['olusum_id']
    )->fetchColumn();
    p4aAssert($allocAfterGate === $allocBeforeGate && $allocAfterGate === 300, 'A allocation unchanged');
    $events91 = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 91 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    p4aAssert(
        SerbestZamanAllocationService::effectiveEventDakika($events91, (int) $gateA['olusum_id'], 91) === 300,
        'A OLUSUM effective remains 300'
    );

    // Merge-blocker B/C/D/E: OLUSUM DUZELTME vs net allocation
    $corr = p4aSeedOlusum($pdo, 92, 300, '2026-12-31');
    $corrKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 92,
        'dakika' => 200,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-corr-kul',
    ], $subeHeader);
    p4aAssert($corrKul['status'] === 200, 'corr usage200 → 200');
    $corrDown250 = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 92,
        'hedef_event_id' => (int) $corr['olusum_id'],
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'yeni_dakika' => 250,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-corr-250',
        'aciklama' => 'olusum 300 to 250 allowed',
    ], $subeHeader);
    p4aAssert($corrDown250['status'] === 200, 'B OLUSUM correction 300→250 PASS');
    $events92 = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 92 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    p4aAssert(
        SerbestZamanAllocationService::effectiveEventDakika($events92, (int) $corr['olusum_id'], 92) === 250,
        'B effective 250'
    );
    p4aAssert(
        SerbestZamanAllocationService::netAllocatedToLot($pdo, (int) $corr['olusum_id']) === 200,
        'B allocation still 200'
    );
    $corrDown200 = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 92,
        'hedef_event_id' => (int) $corr['olusum_id'],
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'yeni_dakika' => 200,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-corr-200',
        'aciklama' => 'olusum 250 to 200 equal allocation allowed',
    ], $subeHeader);
    p4aAssert($corrDown200['status'] === 200, 'C OLUSUM correction →200 PASS');
    $eventsBefore199 = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 92')->fetchColumn();
    $corrDown199 = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 92,
        'hedef_event_id' => (int) $corr['olusum_id'],
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'yeni_dakika' => 199,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-corr-199',
        'aciklama' => 'olusum below allocation blocked',
    ], $subeHeader);
    p4aAssert($corrDown199['status'] === 409, 'D OLUSUM correction →199 BLOCK');
    p4aAssert(
        ($corrDown199['payload']['errors'][0]['code'] ?? '') === SerbestZamanAllocationService::CODE_OLUSUM_HAS_ALLOCATIONS,
        'D OLUSUM_HAS_ALLOCATIONS'
    );
    p4aAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 92')->fetchColumn()
            === $eventsBefore199,
        'D no correction event'
    );
    $events92b = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 92 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    p4aAssert(
        SerbestZamanAllocationService::effectiveEventDakika($events92b, (int) $corr['olusum_id'], 92) === 200,
        'D effective remains 200'
    );
    p4aAssert(
        SerbestZamanAllocationService::netAllocatedToLot($pdo, (int) $corr['olusum_id']) === 200,
        'D allocation remains 200'
    );
    $eventsBefore0 = $eventsBefore199; // same after blocked 199
    $corrDown0 = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 92,
        'hedef_event_id' => (int) $corr['olusum_id'],
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'yeni_dakika' => 0,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-corr-0',
        'aciklama' => 'olusum to zero blocked',
    ], $subeHeader);
    // yeni_dakika 0 may be validation (422) or allocation conflict — either must not persist
    p4aAssert($corrDown0['status'] !== 200, 'E OLUSUM correction →0 BLOCK');
    p4aAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 92')->fetchColumn()
            === $eventsBefore0,
        'E no correction event for →0'
    );

    // Merge-blocker F: after usage IPTAL releases allocation, OLUSUM IPTAL PASS
    $after = p4aSeedOlusum($pdo, 93, 300, '2026-12-31');
    $afterKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 93,
        'dakika' => 200,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-after-kul',
    ], $subeHeader);
    p4aAssert($afterKul['status'] === 200, 'F usage → 200');
    $afterKid = (int) ($afterKul['payload']['data']['id'] ?? 0);
    $afterKulIptal = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 93,
        'hedef_event_id' => $afterKid,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-after-kul-iptal',
    ], $subeHeader);
    p4aAssert($afterKulIptal['status'] === 200, 'F usage IPTAL → 200');
    p4aAssert(
        SerbestZamanAllocationService::netAllocatedToLot($pdo, (int) $after['olusum_id']) === 0,
        'F lot net allocation 0 after usage cancel'
    );
    $afterOlusumIptal = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 93,
        'hedef_event_id' => (int) $after['olusum_id'],
        'hedef_event_tipi' => 'SERBEST_ZAMAN_OLUSUM',
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-after-olusum-iptal',
    ], $subeHeader);
    p4aAssert($afterOlusumIptal['status'] === 200, 'F OLUSUM IPTAL after release → PASS');
    $aktif93 = (int) $pdo->query(
        'SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE olusum_event_id = ' . (int) $after['olusum_id']
    )->fetchColumn();
    p4aAssert($aktif93 === 0, 'F aktif_olusum removed');

    // Merge-blocker G: effective usage 0 + stranded net allocation → INVARIANT_BROKEN
    $inv = p4aSeedOlusum($pdo, 94, 300, '2026-12-31');
    $invKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 94,
        'dakika' => 100,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-inv-kul',
    ], $subeHeader);
    p4aAssert($invKul['status'] === 200, 'G usage → 200');
    $invKid = (int) ($invKul['payload']['data']['id'] ?? 0);
    // Synthetic IPTAL without release deltas (bypass controller) → stranded allocation
    $pdo->exec(
        "INSERT INTO serbest_zaman_events
          (personel_id, event_tipi, event_tarihi, hedef_event_id, hedef_event_tipi,
           islem_anahtari, aciklama, donem_yil, donem_ay, donem_kilitli_miydi, created_by)
         VALUES
          (94, 'SERBEST_ZAMAN_IPTAL', '{$referans}', {$invKid}, 'SERBEST_ZAMAN_KULLANIM',
           'p4a-inv-sql-iptal', 'synthetic stranded', 2026, 5, 0, 1)"
    );
    $bakiyeInv = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
        'personel_id' => '94',
        'referans_tarih' => $referans,
    ]);
    p4aAssert($bakiyeInv['status'] === 200, 'G bakiye → 200');
    p4aAssert(
        ($bakiyeInv['payload']['data']['allocation_state'] ?? '') === SerbestZamanAllocationService::STATE_INVARIANT_BROKEN,
        'G allocation_state INVARIANT_BROKEN'
    );

    // Merge-blocker H: assertLotInvariants — effective OLUSUM 0 + allocation > 0
    $pdo->exec(
        "INSERT INTO serbest_zaman_events
          (personel_id, event_tipi, event_tarihi, hedef_event_id, hedef_event_tipi,
           islem_anahtari, aciklama, donem_yil, donem_ay, donem_kilitli_miydi, created_by)
         VALUES
          (94, 'SERBEST_ZAMAN_IPTAL', '{$referans}', " . (int) $inv['olusum_id'] . ", 'SERBEST_ZAMAN_OLUSUM',
           'p4a-inv-sql-olusum-iptal', 'synthetic lot cancel', 2026, 5, 0, 1)"
    );
    $events94 = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 94 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $lotBroken = false;
    try {
        SerbestZamanAllocationService::assertLotInvariants($pdo, $events94, 94);
    } catch (RuntimeException $e) {
        $lotBroken = $e->getMessage() === SerbestZamanAllocationService::CODE_ALLOCATION_INVARIANT_BROKEN;
    }
    p4aAssert($lotBroken, 'H assertLotInvariants stranded lot → INVARIANT_BROKEN');

    // --- Legacy KULLANIM mutation hardening (A–H + multi-legacy) ---

    // A) legacy usage300 correction→200 → 409 LEGACY_ALLOCATION_REQUIRED, no event, no allocation
    p4aSeedOlusum($pdo, 95, 600, '2026-12-31');
    $legA = p4aInsertLegacyKullanim($pdo, 95, 300, $referans, 'p4a-leg-a-300');
    $eventsBeforeA = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 95')->fetchColumn();
    $allocBeforeA = p4aAllocRowCount($pdo, $legA);
    $corrA = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 95,
        'hedef_event_id' => $legA,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'yeni_dakika' => 200,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-leg-a-corr-200',
        'aciklama' => 'legacy correction blocked',
    ], $subeHeader);
    p4aAssert($corrA['status'] === 409, 'A legacy correction 300→200 → 409');
    p4aAssert(
        ($corrA['payload']['errors'][0]['code'] ?? '') === SerbestZamanAllocationService::CODE_LEGACY_ALLOCATION_REQUIRED,
        'A SERBEST_ZAMAN_LEGACY_ALLOCATION_REQUIRED'
    );
    p4aAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 95')->fetchColumn()
            === $eventsBeforeA,
        'A no correction event'
    );
    p4aAssert(p4aAllocRowCount($pdo, $legA) === $allocBeforeA && $allocBeforeA === 0, 'A no allocation invented');
    $events95a = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 95 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $usageA = SerbestZamanAllocationService::usageAllocationState($pdo, $events95a, 95, $legA);
    p4aAssert($usageA['state'] === SerbestZamanAllocationService::STATE_LEGACY_UNALLOCATED, 'A still LEGACY_UNALLOCATED');
    p4aAssert((int) $usageA['effective'] === 300, 'A effective remains 300');

    // B) legacy usage300 correction→300 (same value) → same BLOCK
    $eventsBeforeB = $eventsBeforeA;
    $corrB = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 95,
        'hedef_event_id' => $legA,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'yeni_dakika' => 300,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-leg-b-corr-300',
        'aciklama' => 'legacy same-value correction blocked',
    ], $subeHeader);
    p4aAssert($corrB['status'] === 409, 'B legacy correction 300→300 → 409');
    p4aAssert(
        ($corrB['payload']['errors'][0]['code'] ?? '') === SerbestZamanAllocationService::CODE_LEGACY_ALLOCATION_REQUIRED,
        'B LEGACY_ALLOCATION_REQUIRED'
    );
    p4aAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 95')->fetchColumn()
            === $eventsBeforeB,
        'B no correction event'
    );
    p4aAssert(p4aAllocRowCount($pdo, $legA) === 0, 'B no allocation invented');

    // C) legacy usage300 IPTAL → PASS, effective0, allocation rows0, no provenance
    p4aSeedOlusum($pdo, 96, 600, '2026-12-31');
    $legC = p4aInsertLegacyKullanim($pdo, 96, 300, $referans, 'p4a-leg-c-300');
    $cancelC = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 96,
        'hedef_event_id' => $legC,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-leg-c-iptal',
    ], $subeHeader);
    p4aAssert($cancelC['status'] === 200, 'C legacy IPTAL → PASS');
    $events96 = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 96 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    p4aAssert(
        SerbestZamanAllocationService::effectiveEventDakika($events96, $legC, 96) === 0,
        'C effective usage 0'
    );
    p4aAssert(p4aAllocRowCount($pdo, $legC) === 0, 'C allocation rows 0 (no provenance invented)');
    $usageC = SerbestZamanAllocationService::usageAllocationState($pdo, $events96, 96, $legC);
    p4aAssert($usageC['state'] === SerbestZamanAllocationService::STATE_ZERO, 'C usage state ZERO');
    $bakiyeC = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
        'personel_id' => '96',
        'referans_tarih' => $referans,
    ]);
    p4aAssert(
        ($bakiyeC['payload']['data']['allocation_state'] ?? '') === SerbestZamanAllocationService::STATE_NO_USAGE,
        'C personel NO_USAGE after sole legacy cancel'
    );

    // D) after C: new usage against current active lots → PASS with explicit allocation
    $newD = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 96,
        'dakika' => 100,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-leg-d-new',
    ], $subeHeader);
    p4aAssert($newD['status'] === 200, 'D new usage after legacy cancel → PASS');
    $newDKid = (int) ($newD['payload']['data']['id'] ?? 0);
    p4aAssert($newDKid > 0, 'D new usage id');
    p4aAssert(p4aAllocRowCount($pdo, $newDKid) > 0, 'D new usage has explicit allocation');
    p4aAssert(
        SerbestZamanAllocationService::netAllocatedForUsage($pdo, $newDKid) === 100,
        'D new usage net allocation 100'
    );

    // E/F) synthetic effective300 / net200 → DUZELTME and IPTAL blocked as INVARIANT_BROKEN
    $invLot = p4aSeedOlusum($pdo, 97, 600, '2026-12-31');
    $invKid = p4aInsertLegacyKullanim($pdo, 97, 300, $referans, 'p4a-inv-mut-300');
    p4aInsertAllocationDelta($pdo, 97, $invKid, (int) $invLot['olusum_id'], $invKid, 200);
    $events97 = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 97 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $usageInv = SerbestZamanAllocationService::usageAllocationState($pdo, $events97, 97, $invKid);
    p4aAssert($usageInv['state'] === SerbestZamanAllocationService::STATE_INVARIANT_BROKEN, 'E/F synthetic INVARIANT_BROKEN');
    p4aAssert((int) $usageInv['effective'] === 300 && (int) $usageInv['net'] === 200, 'E/F effective300 net200');
    $eventsBeforeE = (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 97')->fetchColumn();
    $allocBeforeE = p4aAllocRowCount($pdo, $invKid);
    $corrE = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 97,
        'hedef_event_id' => $invKid,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'yeni_dakika' => 250,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-inv-mut-corr',
        'aciklama' => 'invariant correction blocked',
    ], $subeHeader);
    p4aAssert($corrE['status'] === 409, 'E invariant DUZELTME → 409');
    p4aAssert(
        ($corrE['payload']['errors'][0]['code'] ?? '') === SerbestZamanAllocationService::CODE_ALLOCATION_INVARIANT_BROKEN,
        'E SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN'
    );
    p4aAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 97')->fetchColumn()
            === $eventsBeforeE,
        'E no correction mutation'
    );
    p4aAssert(p4aAllocRowCount($pdo, $invKid) === $allocBeforeE, 'E allocation unchanged');
    $cancelF = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 97,
        'hedef_event_id' => $invKid,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-inv-mut-iptal',
    ], $subeHeader);
    p4aAssert($cancelF['status'] === 409, 'F invariant IPTAL → 409');
    p4aAssert(
        ($cancelF['payload']['errors'][0]['code'] ?? '') === SerbestZamanAllocationService::CODE_ALLOCATION_INVARIANT_BROKEN,
        'F INVARIANT_BROKEN on cancel'
    );
    p4aAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM serbest_zaman_events WHERE personel_id = 97')->fetchColumn()
            === $eventsBeforeE,
        'F no cancel mutation'
    );
    p4aAssert(p4aAllocRowCount($pdo, $invKid) === $allocBeforeE, 'F allocation unchanged');

    // G) allocated valid usage: DUZELTME / IPTAL remain PASS
    p4aSeedOlusum($pdo, 99, 600, '2026-12-31');
    $allocKul = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 99,
        'dakika' => 300,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-alloc-g-kul',
    ], $subeHeader);
    p4aAssert($allocKul['status'] === 200, 'G allocated usage → 200');
    $allocKid = (int) ($allocKul['payload']['data']['id'] ?? 0);
    $corrG = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/duzeltme', [
        'personel_id' => 99,
        'hedef_event_id' => $allocKid,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'yeni_dakika' => 200,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-alloc-g-corr',
        'aciklama' => 'allocated correction allowed',
    ], $subeHeader);
    p4aAssert($corrG['status'] === 200, 'G allocated DUZELTME → PASS');
    p4aAssert(
        SerbestZamanAllocationService::netAllocatedForUsage($pdo, $allocKid) === 200,
        'G net follows correction to 200'
    );
    $cancelG = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 99,
        'hedef_event_id' => $allocKid,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-alloc-g-iptal',
    ], $subeHeader);
    p4aAssert($cancelG['status'] === 200, 'G allocated IPTAL → PASS');
    p4aAssert(
        SerbestZamanAllocationService::netAllocatedForUsage($pdo, $allocKid) === 0,
        'G net 0 after cancel'
    );

    // H / multi-legacy: cancel one unresolved legacy, another remains → still LEGACY_UNALLOCATED
    p4aSeedOlusum($pdo, 98, 900, '2026-12-31');
    $legM1 = p4aInsertLegacyKullanim($pdo, 98, 100, $referans, 'p4a-multi-leg-1');
    $legM2 = p4aInsertLegacyKullanim($pdo, 98, 150, $referans, 'p4a-multi-leg-2');
    $cancelM1 = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/iptal', [
        'personel_id' => 98,
        'hedef_event_id' => $legM1,
        'hedef_event_tipi' => 'SERBEST_ZAMAN_KULLANIM',
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-multi-leg-1-iptal',
    ], $subeHeader);
    p4aAssert($cancelM1['status'] === 200, 'multi cancel one legacy → PASS');
    $bakiyeM = invokeSzHttp($pdo, $gy, 'GET', '/serbest-zaman/bakiye', [], $subeHeader, [
        'personel_id' => '98',
        'referans_tarih' => $referans,
    ]);
    p4aAssert(
        ($bakiyeM['payload']['data']['allocation_state'] ?? '') === SerbestZamanAllocationService::STATE_LEGACY_UNALLOCATED,
        'multi personel still LEGACY_UNALLOCATED'
    );
    p4aAssert(
        (int) ($bakiyeM['payload']['data']['legacy_unallocated_usage_count'] ?? 0) === 1,
        'multi remaining legacy count 1'
    );
    $newBlockedM = invokeSzHttp($pdo, $gy, 'POST', '/serbest-zaman/kullanim', [
        'personel_id' => 98,
        'dakika' => 50,
        'event_tarihi' => $referans,
        'islem_anahtari' => 'p4a-multi-new-blocked',
    ], $subeHeader);
    p4aAssert($newBlockedM['status'] === 409, 'multi new KULLANIM still BLOCKED');
    p4aAssert(
        ($newBlockedM['payload']['errors'][0]['code'] ?? '') === SerbestZamanAllocationService::CODE_LEGACY_ALLOCATION_REQUIRED,
        'multi LEGACY_ALLOCATION_REQUIRED'
    );
    $events98 = $pdo->query('SELECT * FROM serbest_zaman_events WHERE personel_id = 98 ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $usageM2 = SerbestZamanAllocationService::usageAllocationState($pdo, $events98, 98, $legM2);
    p4aAssert($usageM2['state'] === SerbestZamanAllocationService::STATE_LEGACY_UNALLOCATED, 'multi remaining usage LEGACY');

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
