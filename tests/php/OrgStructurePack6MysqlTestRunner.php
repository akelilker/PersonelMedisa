<?php

declare(strict_types=1);

/**
 * Pack6 Org Structure MariaDB acceptance (A1–A21).
 * php tests/php/OrgStructurePack6MysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\PersonellerController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;
use Medisa\Api\Scope\SubeScope;
use Medisa\Api\Services\Personel\PersonelCanonicalValidator;
use Medisa\Api\Services\Personel\PersonelCreateService;
use Medisa\Api\Services\Personel\PersonelImportApplyService;
use Medisa\Api\Services\Personel\PersonelImportDryRunService;
use Medisa\Api\Services\Personel\PersonelImportException;
use Medisa\Api\Services\Personel\PersonelImportReferenceCatalogService;
use Medisa\Api\Services\Personel\PersonelOrgLocationSchema;
use Medisa\Api\Services\Personel\PersonelOrgStructureSchema;
use Medisa\Api\Services\Personel\PersonelValidationException;

function p6Assert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function p6RootPdo(): PDO
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
function p6SplitSql(string $sql): array
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

function p6Apply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (p6SplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function p6PdoForDb(string $database): PDO
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
function p6MigrationFiles(): array
{
    $dir = __DIR__ . '/../../api/migrations';
    $files = array_values(array_filter(scandir($dir) ?: [], static function ($name) {
        return (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name)
            && $name !== '067_personel_canonical_reference_gate.sql'
            && $name !== '068_sgk_actor_identity_lifecycle_audit.sql'
            && $name !== '069_personel_credential_onboarding.sql';
    }));
    sort($files, SORT_STRING);

    return $files;
}

/** @param list<string> $files */
function p6ApplyThrough(PDO $pdo, array $files, string $maxInclusive): void
{
    foreach ($files as $file) {
        p6Apply($pdo, $file);
        if ($file === $maxInclusive) {
            return;
        }
    }
    throw new RuntimeException('Migration tip not reached: ' . $maxInclusive);
}

function p6AssertSafeTarget(string $database): void
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

function p6ColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
    );
    $stmt->execute(['t' => $table, 'c' => $column]);

    return (int) $stmt->fetchColumn() === 1;
}

function p6TableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $stmt->execute(['t' => $table]);

    return (int) $stmt->fetchColumn() === 1;
}

function p6IndexExists(PDO $pdo, string $table, string $indexName): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i'
    );
    $stmt->execute(['t' => $table, 'i' => $indexName]);

    return (int) $stmt->fetchColumn() >= 1;
}

function p6FkExists(PDO $pdo, string $table, string $constraintName): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE table_schema = DATABASE() AND table_name = :t
           AND constraint_name = :c AND constraint_type = 'FOREIGN KEY'"
    );
    $stmt->execute(['t' => $table, 'c' => $constraintName]);

    return (int) $stmt->fetchColumn() === 1;
}

function p6SeedBase(PDO $pdo): void
{
    $hash = password_hash('P6Pack6TestPass-24chars!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES
        (1, 'MRK', 'Merkez', 'AKTIF'),
        (2, 'SB2', 'Sube 2', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES
        (1, 'İdari İşler', 'AKTIF'),
        (2, 'Klinik', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES
        (1, 'Uzman', 'AKTIF'),
        (2, 'Asistan', 'AKTIF')");
    $pdo->exec("INSERT INTO personel_tipleri (id, ad, durum) VALUES
        (1, 'Tam Zamanli', 'AKTIF'),
        (2, 'Yari Zamanli', 'AKTIF')");
    $pdo->exec('INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES (1, 1), (1, 2), (2, 2)');
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
        (1, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF')"
    );
}

function p6SeedOrgStructure(PDO $pdo): void
{
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES
        (1, 'MEDISA', 'Medisa', 'AKTIF'),
        (2, 'KARYAPI', 'Karyapi', 'AKTIF')");
    $pdo->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, durum) VALUES
        (1, 'ANK', 'Ankara', 'AKTIF'),
        (2, 'IST', 'Istanbul', 'AKTIF')");
    $pdo->exec("INSERT INTO bolumler (id, departman_id, ad, durum) VALUES
        (1, 1, 'Operasyon', 'AKTIF'),
        (2, 2, 'Operasyon', 'AKTIF'),
        (3, 1, 'Muhasebe', 'AKTIF')");
    $pdo->exec("INSERT INTO birimler (id, bolum_id, ad, durum) VALUES
        (1, 1, 'Saha', 'AKTIF'),
        (2, 2, 'Saha', 'AKTIF'),
        (3, 1, 'Ofis', 'AKTIF')");
    $pdo->exec("INSERT INTO pozisyonlar (id, ad, durum) VALUES
        (1, 'Kadro', 'AKTIF'),
        (2, 'Destek', 'AKTIF')");
}

/** @return array<string, mixed> */
function p6CreatePayload(array $overrides = []): array
{
    $base = [
        'tc_kimlik_no' => '10000000146',
        'ad' => 'Ayşe',
        'soyad' => 'Yılmaz',
        'dogum_tarihi' => '1990-05-15',
        'telefon' => '05321112233',
        'acil_durum_kisi' => 'Ali Yılmaz',
        'acil_durum_telefon' => '05324445566',
        'sicil_no' => 'P6-001',
        'ise_giris_tarihi' => '2024-01-10',
        'sube_id' => 1,
        'departman_id' => 1,
        'gorev_id' => 2,
        'personel_tipi_id' => 1,
        'aktif_durum' => 'AKTIF',
    ];

    return array_merge($base, $overrides);
}

function p6LegacyCsvHeader(): string
{
    return implode(';', PersonelImportDryRunService::TEMPLATE_COLUMNS);
}

function p6LegacyCsvRow(array $overrides = []): string
{
    $row = array_merge([
        'tc_kimlik_no' => '10000000146',
        'sicil_no' => 'IMP-P6-001',
        'ad' => 'Ayşe',
        'soyad' => 'Yılmaz',
        'dogum_tarihi' => '1990-05-15',
        'dogum_yeri' => 'Ankara',
        'telefon' => '0532 111 22 33',
        'kan_grubu' => 'A Rh+',
        'acil_durum_kisi' => 'Ali Yılmaz',
        'acil_durum_telefon' => '0532 444 55 66',
        'ise_giris_tarihi' => '2024-01-10',
        'sube' => 'Merkez',
        'departman' => 'İdari İşler',
        'gorev' => 'Asistan',
        'personel_tipi' => 'Tam Zamanli',
    ], $overrides);
    $ordered = [];
    foreach (PersonelImportDryRunService::TEMPLATE_COLUMNS as $col) {
        $ordered[] = (string) ($row[$col] ?? '');
    }

    return implode(';', $ordered);
}

function p6StructCsvHeader(): string
{
    return implode(';', array_merge(
        PersonelImportDryRunService::TEMPLATE_COLUMNS,
        ['bolum', 'birim', 'pozisyon']
    ));
}

function p6StructCsvRow(array $overrides = []): string
{
    $row = array_merge([
        'tc_kimlik_no' => '10000000154',
        'sicil_no' => 'IMP-P6-ORG',
        'ad' => 'Mehmet',
        'soyad' => 'Demir',
        'dogum_tarihi' => '1991-06-20',
        'dogum_yeri' => 'İzmir',
        'telefon' => '0532 222 33 44',
        'kan_grubu' => 'B Rh+',
        'acil_durum_kisi' => 'Ayse Demir',
        'acil_durum_telefon' => '0532 555 66 77',
        'ise_giris_tarihi' => '2024-02-01',
        'sube' => 'Merkez',
        'departman' => 'İdari İşler',
        'gorev' => 'Asistan',
        'personel_tipi' => 'Tam Zamanli',
        'bolum' => 'Operasyon',
        'birim' => 'Saha',
        'pozisyon' => 'Kadro',
    ], $overrides);
    $ordered = [];
    foreach (array_merge(PersonelImportDryRunService::TEMPLATE_COLUMNS, ['bolum', 'birim', 'pozisyon']) as $col) {
        $ordered[] = (string) ($row[$col] ?? '');
    }

    return implode(';', $ordered);
}

function p6CountPersonel(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM personeller')->fetchColumn();
}

function p6SetConnectionPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

/** @param array<string, mixed>|null $user */
function p6ResetAuthUser($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

function p6MakeRequest(string $method = 'GET', array $headers = [], array $query = [], array $body = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => '/',
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'jsonBody' => $body,
    ] as $name => $value) {
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }
    $_GET = [];
    foreach ($query as $key => $value) {
        $_GET[(string) $key] = $value;
    }

    return $request;
}

/** @return list<string> */
function p6PhpMysqlArgs(): array
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
 * @param array<string, mixed> $user
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 * @param array<string, mixed> $query
 * @return array{status:int, payload:array<string,mixed>}
 */
function p6InvokeHttp(
    PDO $pdo,
    array $user,
    string $method,
    string $path,
    array $body = [],
    array $headers = [],
    array $query = []
): array {
    $statusFile = tempnam(sys_get_temp_dir(), 'p6_http_');
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

    $cmd = array_merge([PHP_BINARY], p6PhpMysqlArgs(), [__FILE__, '--p6-http-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, null, array_merge(getenv(), [
        'MEDISA_TEST_MYSQL_DSN' => getenv('MEDISA_TEST_MYSQL_DSN') ?: '',
        'MEDISA_TEST_MYSQL_USER' => getenv('MEDISA_TEST_MYSQL_USER') ?: '',
        'MEDISA_TEST_MYSQL_PASSWORD' => getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
    ]));
    if (!is_resource($process)) {
        @unlink($statusFile);
        throw new RuntimeException('p6 http child failed to start');
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
    $jsonSlice = $jsonStart === false ? $stdout : substr($stdout, $jsonStart);
    $decoded = json_decode((string) $jsonSlice, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('p6 http child invalid json: ' . $stdout . ' / ' . $stderr);
    }

    return ['status' => $status, 'payload' => $decoded];
}

if (($argv[1] ?? '') === '--p6-http-child') {
    $raw = stream_get_contents(STDIN);
    $cfg = json_decode((string) $raw, true);
    if (!is_array($cfg)) {
        fwrite(STDERR, "bad p6 child config\n");
        exit(2);
    }

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $cfg['database'], (string) $cfg['dsn']);
    $pdo = new PDO(
        (string) $dsn,
        (string) $cfg['user'],
        (string) $cfg['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    p6SetConnectionPdo($pdo);
    p6ResetAuthUser($cfg['auth']);
    $request = p6MakeRequest(
        (string) $cfg['method'],
        is_array($cfg['headers'] ?? null) ? $cfg['headers'] : [],
        is_array($cfg['query'] ?? null) ? $cfg['query'] : [],
        is_array($cfg['body'] ?? null) ? $cfg['body'] : []
    );

    register_shutdown_function(static function () use ($cfg) {
        $code = http_response_code();
        if (!is_int($code) || $code < 100) {
            $code = 200;
        }
        file_put_contents((string) $cfg['status_file'], (string) $code);
    });

    $method = strtoupper((string) $cfg['method']);
    $path = (string) $cfg['path'];
    if ($method === 'GET' && $path === '/personeller') {
        PersonellerController::list($request);
    }
    if ($method === 'POST' && $path === '/personeller') {
        PersonellerController::create($request);
    }
    if ($method === 'GET' && preg_match('#^/personeller/(\d+)$#', $path, $matches)) {
        PersonellerController::detail($request, $matches[1]);
    }
    if ($method === 'PUT' && preg_match('#^/personeller/(\d+)$#', $path, $matches)) {
        PersonellerController::update($request, $matches[1]);
    }

    fwrite(STDERR, "unhandled p6 route\n");
    exit(3);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$root = p6RootPdo();
$files = p6MigrationFiles();
p6Assert(end($files) === '066_personel_calisan_kapsami.sql', 'A0 tip ends with 066');
$gyUser = ['id' => 1, 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
$scopedUser = ['id' => 2, 'rol' => 'BOLUM_YONETICISI', 'sube_ids' => [1]];

// ========== Pre-065 (through 064) ==========
$dbPre = 'medisa_pack6_pre_' . substr(bin2hex(random_bytes(4)), 0, 8);
p6AssertSafeTarget($dbPre);
$root->exec('CREATE DATABASE `' . $dbPre . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdoPre = p6PdoForDb($dbPre);

try {
    p6ApplyThrough($pdoPre, $files, '064_personel_org_location_model.sql');
    p6Assert(!PersonelOrgStructureSchema::isReady($pdoPre), 'A4 pre-065 structure not ready');
    p6SeedBase($pdoPre);
    $pdoPre->exec("INSERT INTO sgk_isverenler (id, kod, ad, durum) VALUES (1, 'MEDISA', 'Medisa', 'AKTIF')");
    $pdoPre->exec("INSERT INTO calisma_lokasyonlari (id, kod, ad, durum) VALUES (1, 'ANK', 'Ankara', 'AKTIF')");

    // A5: create without Pack6 fields works
    $payloadA5 = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p6CreatePayload([
        'tc_kimlik_no' => '10000000146',
        'sicil_no' => 'P6-A5',
    ]));
    PersonelCreateService::validateCreateReferences($pdoPre, $payloadA5);
    $idA5 = PersonelCreateService::insertPersonel($pdoPre, $payloadA5);
    p6Assert($idA5 > 0, 'A5 pre-065 create without new fields works');

    // A4 list/detail via HTTP
    $listA4 = p6InvokeHttp($pdoPre, $gyUser, 'GET', '/personeller', [], [], ['page' => 1, 'limit' => 10]);
    p6Assert($listA4['status'] === 200, 'A4 pre-065 list 200');
    $detailA4 = p6InvokeHttp($pdoPre, $gyUser, 'GET', '/personeller/' . $idA5);
    p6Assert($detailA4['status'] === 200, 'A4 pre-065 detail 200');
    $detailData = $detailA4['payload']['data'] ?? [];
    p6Assert(($detailData['bolum_id'] ?? null) === null, 'A4 bolum_id null pre-065');

    // A6: explicit bolum_id=null → 409
    $beforeA6 = p6CountPersonel($pdoPre);
    $caughtA6 = null;
    try {
        $payloadA6 = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p6CreatePayload([
            'tc_kimlik_no' => '10000000162',
            'sicil_no' => 'P6-A6',
            'bolum_id' => null,
        ]));
        PersonelCreateService::validateCreateReferences($pdoPre, $payloadA6);
    } catch (PersonelValidationException $e) {
        $caughtA6 = $e;
    }
    p6Assert($caughtA6 !== null && $caughtA6->getCodeString() === PersonelOrgStructureSchema::ERROR_CODE, 'A6 explicit null fail-closed');
    p6Assert(p6CountPersonel($pdoPre) === $beforeA6, 'A6 no mutation');

    // A7 blank Pack6 headers
    $blankCsv = p6StructCsvHeader() . "\r\n" . p6StructCsvRow([
        'tc_kimlik_no' => '10000000170',
        'sicil_no' => 'IMP-A7',
        'bolum' => '',
        'birim' => '',
        'pozisyon' => '',
    ]) . "\r\n";
    $dryA7 = PersonelImportDryRunService::analyze($pdoPre, $blankCsv, $gyUser, null);
    p6Assert(($dryA7['can_apply'] ?? false) === true, 'A7 blank Pack6 dry-run PASS');
    $applyA7 = PersonelImportApplyService::apply(
        $pdoPre,
        $blankCsv,
        $gyUser,
        [
            'confirmation' => PersonelImportApplyService::CONFIRMATION_TOKEN,
            'idempotency_key' => 'p6.apply.blank.struct.a7.01',
            'manifest_hash' => (string) ($dryA7['manifest_hash'] ?? ''),
        ],
        null
    );
    p6Assert(($applyA7['created_count'] ?? 0) === 1, 'A7 blank Pack6 apply PASS');

    // A8 nonblank bolum pre-065
    $nonblank = p6StructCsvHeader() . "\r\n" . p6StructCsvRow([
        'tc_kimlik_no' => '10000000188',
        'sicil_no' => 'IMP-A8',
        'bolum' => 'Operasyon',
    ]) . "\r\n";
    $caughtA8 = null;
    try {
        PersonelImportDryRunService::analyze($pdoPre, $nonblank, $gyUser, null);
    } catch (PersonelImportException $e) {
        $caughtA8 = $e;
    }
    p6Assert($caughtA8 !== null && $caughtA8->getCodeString() === PersonelOrgStructureSchema::ERROR_CODE, 'A8 nonblank pre-065 409');

    // Pre-065 reference bundle remains usable (legacy refs present; Pack6 absent)
    $exportPre = PersonelImportReferenceCatalogService::buildExport($pdoPre, $gyUser, null);
    $exportPreBody = (string) ($exportPre['body'] ?? '');
    p6Assert(strpos($exportPreBody, 'DEPARTMAN') !== false, 'pre-065 reference bundle DEPARTMAN');
    p6Assert(strpos($exportPreBody, 'GOREV') !== false, 'pre-065 reference bundle GOREV/Unvan');
    p6Assert(strpos($exportPreBody, 'PERSONEL_TIPI') !== false, 'pre-065 reference bundle PERSONEL_TIPI');
    p6Assert(strpos($exportPreBody, 'BOLUM;') === false, 'pre-065 no BOLUM rows');
    p6Assert(strpos($exportPreBody, 'BIRIM;') === false, 'pre-065 no BIRIM rows');
    p6Assert(strpos($exportPreBody, 'POZISYON;') === false, 'pre-065 no POZISYON rows');
    $catalogPre = PersonelImportReferenceCatalogService::loadCatalogForDryRun($pdoPre);
    p6Assert(count($catalogPre['departman'] ?? []) > 0, 'pre-065 dry-run departman catalog');
    p6Assert(($catalogPre['bolum_by_departman'] ?? []) === [], 'pre-065 empty Pack6 bolum catalog');
    p6Assert(($catalogPre['pozisyon'] ?? []) === [], 'pre-065 empty Pack6 pozisyon catalog');

    // A21 Pack5 regression: location gate still works
    $caughtLoc = null;
    try {
        $payloadLoc = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p6CreatePayload([
            'tc_kimlik_no' => '10000000196',
            'sicil_no' => 'P6-LOC',
            'sgk_isveren_id' => null,
        ]));
        // schema IS ready for Pack5 on pre-065 tip (064 applied) — so null write is OK post-064
        PersonelCreateService::validateCreateReferences($pdoPre, $payloadLoc);
        p6Assert(true, 'A21 Pack5 null org write allowed post-064');
    } catch (PersonelValidationException $e) {
        $caughtLoc = $e;
    }
    p6Assert($caughtLoc === null, 'A21 Pack5 org location ready on 064 tip');
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $dbPre . '`');
    } catch (Throwable $e) {
    }
}

// ========== Full 065 ==========
$dbFull = 'medisa_pack6_' . substr(bin2hex(random_bytes(4)), 0, 8);
p6AssertSafeTarget($dbFull);
$root->exec('CREATE DATABASE `' . $dbFull . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = p6PdoForDb($dbFull);

try {
    foreach ($files as $file) {
        p6Apply($pdo, $file);
    }
    // A1 clean + A2 reapply
    p6Apply($pdo, '065_personel_org_structure.sql');
    p6Assert(PersonelOrgStructureSchema::isReady($pdo), 'A1/A2 schema ready after clean+reapply');
    p6Assert(p6TableExists($pdo, 'bolumler') && p6TableExists($pdo, 'birimler') && p6TableExists($pdo, 'pozisyonlar'), 'A1 tables');
    p6Assert(p6ColumnExists($pdo, 'subeler', 'sgk_isveren_id'), 'A1 subeler.sgk_isveren_id');

    p6SeedBase($pdo);
    p6SeedOrgStructure($pdo);

    // A9 create with all org attrs
    $payloadA9 = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p6CreatePayload([
        'tc_kimlik_no' => '10000000204',
        'sicil_no' => 'P6-A9',
        'departman_id' => 1,
        'bolum_id' => 1,
        'birim_id' => 1,
        'gorev_id' => 2,
        'pozisyon_id' => 1,
        'personel_tipi_id' => 1,
        'sgk_isveren_id' => 1,
        'calisma_lokasyonu_id' => 1,
    ]));
    PersonelCreateService::validateCreateReferences($pdo, $payloadA9);
    $idA9 = PersonelCreateService::insertPersonel($pdo, $payloadA9);
    $rowA9 = $pdo->query('SELECT bolum_id, birim_id, pozisyon_id, gorev_id, departman_id FROM personeller WHERE id=' . $idA9)
        ->fetch(PDO::FETCH_ASSOC);
    p6Assert((int) $rowA9['bolum_id'] === 1 && (int) $rowA9['birim_id'] === 1 && (int) $rowA9['pozisyon_id'] === 1, 'A9 IDs persist');

    // A13 names
    $detailA13 = p6InvokeHttp($pdo, $gyUser, 'GET', '/personeller/' . $idA9);
    $d13 = $detailA13['payload']['data'] ?? [];
    p6Assert(($d13['bolum_adi'] ?? '') === 'Operasyon', 'A13 bolum_adi');
    p6Assert(($d13['birim_adi'] ?? '') === 'Saha', 'A13 birim_adi');
    p6Assert(($d13['pozisyon_adi'] ?? '') === 'Kadro', 'A13 pozisyon_adi');
    p6Assert(($d13['gorev_adi'] ?? '') === 'Asistan', 'A13 gorev_adi/unvan');

    // A10 invalid departman/bolum
    $caughtA10 = null;
    try {
        $bad = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p6CreatePayload([
            'tc_kimlik_no' => '10000000212',
            'sicil_no' => 'P6-A10',
            'departman_id' => 1,
            'bolum_id' => 2, // Operasyon under Klinik
        ]));
        PersonelCreateService::validateCreateReferences($pdo, $bad);
    } catch (PersonelValidationException $e) {
        $caughtA10 = $e;
    }
    p6Assert($caughtA10 !== null && $caughtA10->getField() === 'bolum_id', 'A10 invalid departman/bolum');

    // A11 invalid bolum/birim
    $caughtA11 = null;
    try {
        $bad = PersonelCanonicalValidator::normalizeAndValidateCreatePayload(p6CreatePayload([
            'tc_kimlik_no' => '10000000220',
            'sicil_no' => 'P6-A11',
            'departman_id' => 1,
            'bolum_id' => 1,
            'birim_id' => 2, // Saha under Klinik Operasyon
        ]));
        PersonelCreateService::validateCreateReferences($pdo, $bad);
    } catch (PersonelValidationException $e) {
        $caughtA11 = $e;
    }
    p6Assert($caughtA11 !== null && $caughtA11->getField() === 'birim_id', 'A11 invalid bolum/birim');

    // A12 update parent stale child
    $upd = p6InvokeHttp($pdo, $gyUser, 'PUT', '/personeller/' . $idA9, [
        'ad' => 'Ayşe',
        'soyad' => 'Yılmaz',
        'telefon' => '05321112233',
        'departman_id' => 2,
        'effective_date' => '2024-06-01',
    ]);
    p6Assert($upd['status'] === 422, 'A12 stale child fail-closed status');

    // A14/A15 SubeScope still sube_id
    $pdo->exec("UPDATE subeler SET sgk_isveren_id = 2 WHERE id = 1");
    $allowed = SubeScope::allowedSubeIds($scopedUser);
    p6Assert($allowed === [1], 'A14 SubeScope uses sube_ids only');
    p6Assert(!in_array(2, $allowed, true), 'A15 branch sgk owner does not authorize');

    // A16–A18 hierarchical import
    $csvHier = p6StructCsvHeader() . "\r\n" . p6StructCsvRow([
        'tc_kimlik_no' => '10000000238',
        'sicil_no' => 'IMP-A16',
        'departman' => 'İdari İşler',
        'bolum' => 'Operasyon',
        'birim' => 'Saha',
        'pozisyon' => 'Destek',
    ]) . "\r\n";
    $dryA16 = PersonelImportDryRunService::analyze($pdo, $csvHier, $gyUser, null);
    p6Assert(($dryA16['ozet']['gecerli_satir'] ?? 0) === 1, 'A16 hierarchical resolve PASS');
    $cand = $dryA16['candidates'][0] ?? [];
    p6Assert((int) ($cand['bolum_id'] ?? 0) === 1, 'A17 same bolum name under parent departman');
    p6Assert((int) ($cand['birim_id'] ?? 0) === 1, 'A18 same birim name under parent bolum');
    p6Assert((int) ($cand['pozisyon_id'] ?? 0) === 2, 'A16 pozisyon resolve');

    // A19 manifest protection
    $hashA19 = (string) $dryA16['manifest_hash'];
    $tamperCsv = p6StructCsvHeader() . "\r\n" . p6StructCsvRow([
        'tc_kimlik_no' => '10000000238',
        'sicil_no' => 'IMP-A16',
        'departman' => 'Klinik',
        'bolum' => 'Operasyon',
        'birim' => 'Saha',
        'pozisyon' => 'Destek',
    ]) . "\r\n";
    $caughtA19 = null;
    try {
        PersonelImportApplyService::apply(
            $pdo,
            $tamperCsv,
            $gyUser,
            [
                'confirmation' => PersonelImportApplyService::CONFIRMATION_TOKEN,
                'idempotency_key' => 'p6.apply.tamper.a19.01',
                'manifest_hash' => $hashA19,
            ],
            null
        );
    } catch (PersonelImportException $e) {
        $caughtA19 = $e;
    }
    p6Assert($caughtA19 !== null, 'A19 manifest protects resolved IDs');

    // A20 reference export parent context
    $export = PersonelImportReferenceCatalogService::buildExport($pdo, $gyUser, null);
    p6Assert(strpos($export['body'], 'BOLUM') !== false, 'A20 BOLUM export');
    p6Assert(strpos($export['body'], 'BIRIM') !== false, 'A20 BIRIM export');
    p6Assert(strpos($export['body'], 'POZISYON') !== false, 'A20 POZISYON export');
    p6Assert(strpos($export['body'], 'departman_id=') !== false, 'A20 parent context in aciklama');
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $dbFull . '`');
    } catch (Throwable $e) {
    }
}

// ========== A3 partial-state convergence (A3-1 .. A3-8) ==========
$dbPartial = 'medisa_pack6_p_' . substr(bin2hex(random_bytes(4)), 0, 8);
p6AssertSafeTarget($dbPartial);
$root->exec('CREATE DATABASE `' . $dbPartial . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdoP = p6PdoForDb($dbPartial);
try {
    // ----- A3-1 / T3: empty bolumler missing status/index/FK — 065 converges -----
    p6ApplyThrough($pdoP, $files, '064_personel_org_location_model.sql');
    $pdoP->exec(
        "CREATE TABLE bolumler (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          departman_id INT UNSIGNED NOT NULL,
          ad VARCHAR(120) NOT NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    p6Assert(p6ColumnExists($pdoP, 'bolumler', 'departman_id'), 'A3-1 fixture has departman_id');
    p6Assert(!p6ColumnExists($pdoP, 'bolumler', 'durum'), 'A3-1 durum absent');
    p6Assert((int) $pdoP->query('SELECT COUNT(*) FROM bolumler')->fetchColumn() === 0, 'A3-1/T3 empty table');
    p6Assert(!p6FkExists($pdoP, 'bolumler', 'fk_bolumler_departman'), 'A3-1 FK absent');
    p6Assert(!PersonelOrgStructureSchema::isReady($pdoP), 'A3-1 not ready before repair');
    p6Apply($pdoP, '065_personel_org_structure.sql');
    p6Assert(p6ColumnExists($pdoP, 'bolumler', 'durum'), 'A3-1 durum converged');
    p6Assert(p6IndexExists($pdoP, 'bolumler', 'idx_bolumler_durum'), 'A3-1 durum index converged');
    p6Assert(p6IndexExists($pdoP, 'bolumler', 'uq_bolumler_departman_ad'), 'A3-1 unique converged');
    p6Assert(p6FkExists($pdoP, 'bolumler', 'fk_bolumler_departman'), 'A3-1 FK converged');
    p6Assert(PersonelOrgStructureSchema::isReady($pdoP), 'A3-1 ready after 065');

    // ----- A3-2: birimler complete columns, missing parent FK/index -----
    $dbA32 = 'medisa_pack6_a32_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbA32);
    $root->exec('CREATE DATABASE `' . $dbA32 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoA32 = p6PdoForDb($dbA32);
    try {
        p6ApplyThrough($pdoA32, $files, '064_personel_org_location_model.sql');
        $pdoA32->exec(
            "CREATE TABLE bolumler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              departman_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_bolumler_departman_ad (departman_id, ad)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoA32->exec(
            "ALTER TABLE bolumler
               ADD CONSTRAINT fk_bolumler_departman
                 FOREIGN KEY (departman_id) REFERENCES departmanlar (id) ON DELETE RESTRICT"
        );
        $pdoA32->exec(
            "CREATE TABLE birimler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              bolum_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_birimler_bolum_ad (bolum_id, ad)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        p6Assert(!p6FkExists($pdoA32, 'birimler', 'fk_birimler_bolum'), 'A3-2 birim FK absent');
        p6Assert(!p6IndexExists($pdoA32, 'birimler', 'idx_birimler_bolum'), 'A3-2 birim index absent');
        p6Apply($pdoA32, '065_personel_org_structure.sql');
        p6Assert(p6FkExists($pdoA32, 'birimler', 'fk_birimler_bolum'), 'A3-2 birim FK converged');
        p6Assert(p6IndexExists($pdoA32, 'birimler', 'idx_birimler_bolum'), 'A3-2 birim index converged');
        p6Assert(PersonelOrgStructureSchema::isReady($pdoA32), 'A3-2 ready after 065');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbA32 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- A3-3: pozisyonlar missing unique/status index -----
    $dbA33 = 'medisa_pack6_a33_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbA33);
    $root->exec('CREATE DATABASE `' . $dbA33 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoA33 = p6PdoForDb($dbA33);
    try {
        p6ApplyThrough($pdoA33, $files, '064_personel_org_location_model.sql');
        $pdoA33->exec(
            "CREATE TABLE pozisyonlar (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        p6Assert(!p6IndexExists($pdoA33, 'pozisyonlar', 'uq_pozisyonlar_ad'), 'A3-3 unique absent');
        p6Assert(!p6IndexExists($pdoA33, 'pozisyonlar', 'idx_pozisyonlar_durum'), 'A3-3 durum index absent');
        p6Apply($pdoA33, '065_personel_org_structure.sql');
        p6Assert(p6IndexExists($pdoA33, 'pozisyonlar', 'uq_pozisyonlar_ad'), 'A3-3 unique converged');
        p6Assert(p6IndexExists($pdoA33, 'pozisyonlar', 'idx_pozisyonlar_durum'), 'A3-3 durum index converged');
        p6Assert(PersonelOrgStructureSchema::isReady($pdoA33), 'A3-3 ready after 065');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbA33 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- A3-4: personeller has bolum_id only -----
    $dbA34 = 'medisa_pack6_a34_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbA34);
    $root->exec('CREATE DATABASE `' . $dbA34 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoA34 = p6PdoForDb($dbA34);
    try {
        p6ApplyThrough($pdoA34, $files, '064_personel_org_location_model.sql');
        $pdoA34->exec('ALTER TABLE personeller ADD COLUMN bolum_id INT UNSIGNED NULL AFTER departman_id');
        p6Assert(p6ColumnExists($pdoA34, 'personeller', 'bolum_id'), 'A3-4 bolum_id present');
        p6Assert(!p6ColumnExists($pdoA34, 'personeller', 'birim_id'), 'A3-4 birim_id absent');
        p6Assert(!p6ColumnExists($pdoA34, 'personeller', 'pozisyon_id'), 'A3-4 pozisyon_id absent');
        p6Apply($pdoA34, '065_personel_org_structure.sql');
        p6Assert(p6ColumnExists($pdoA34, 'personeller', 'birim_id'), 'A3-4 birim_id converged');
        p6Assert(p6ColumnExists($pdoA34, 'personeller', 'pozisyon_id'), 'A3-4 pozisyon_id converged');
        p6Assert(p6FkExists($pdoA34, 'personeller', 'fk_personeller_birim'), 'A3-4 birim FK converged');
        p6Assert(PersonelOrgStructureSchema::isReady($pdoA34), 'A3-4 ready after 065');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbA34 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- A3-5: subeler.sgk_isveren_id exists, FK absent -----
    $dbA35 = 'medisa_pack6_a35_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbA35);
    $root->exec('CREATE DATABASE `' . $dbA35 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoA35 = p6PdoForDb($dbA35);
    try {
        p6ApplyThrough($pdoA35, $files, '064_personel_org_location_model.sql');
        $pdoA35->exec('ALTER TABLE subeler ADD COLUMN sgk_isveren_id INT UNSIGNED NULL AFTER ad');
        p6Assert(p6ColumnExists($pdoA35, 'subeler', 'sgk_isveren_id'), 'A3-5 col exists');
        p6Assert(!p6FkExists($pdoA35, 'subeler', 'fk_subeler_sgk_isveren'), 'A3-5 FK absent');
        p6Apply($pdoA35, '065_personel_org_structure.sql');
        p6Assert(p6FkExists($pdoA35, 'subeler', 'fk_subeler_sgk_isveren'), 'A3-5 FK converged');
        p6Assert(p6IndexExists($pdoA35, 'subeler', 'idx_subeler_sgk_isveren'), 'A3-5 index converged');
        p6Assert(PersonelOrgStructureSchema::isReady($pdoA35), 'A3-5 ready after 065');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbA35 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- A3-6: all names/columns exist but critical constraint missing → not ready; 065 repairs -----
    $dbA36 = 'medisa_pack6_a36_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbA36);
    $root->exec('CREATE DATABASE `' . $dbA36 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoA36 = p6PdoForDb($dbA36);
    try {
        p6ApplyThrough($pdoA36, $files, '064_personel_org_location_model.sql');
        $pdoA36->exec(
            "CREATE TABLE bolumler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              departman_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_bolumler_departman_ad (departman_id, ad),
              KEY idx_bolumler_departman (departman_id),
              KEY idx_bolumler_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoA36->exec(
            "CREATE TABLE birimler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              bolum_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_birimler_bolum_ad (bolum_id, ad),
              KEY idx_birimler_bolum (bolum_id),
              KEY idx_birimler_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoA36->exec(
            "CREATE TABLE pozisyonlar (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_pozisyonlar_ad (ad),
              KEY idx_pozisyonlar_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoA36->exec('ALTER TABLE personeller ADD COLUMN bolum_id INT UNSIGNED NULL AFTER departman_id');
        $pdoA36->exec('ALTER TABLE personeller ADD COLUMN birim_id INT UNSIGNED NULL AFTER bolum_id');
        $pdoA36->exec('ALTER TABLE personeller ADD COLUMN pozisyon_id INT UNSIGNED NULL AFTER gorev_id');
        $pdoA36->exec('ALTER TABLE subeler ADD COLUMN sgk_isveren_id INT UNSIGNED NULL AFTER ad');
        // Intentionally omit all Pack6 FKs — names+columns present, constraints incomplete.
        p6Assert(p6TableExists($pdoA36, 'bolumler') && p6ColumnExists($pdoA36, 'personeller', 'bolum_id'), 'A3-6 names+cols exist');
        p6Assert(!p6FkExists($pdoA36, 'bolumler', 'fk_bolumler_departman'), 'A3-6 critical FK missing');
        p6Assert(!PersonelOrgStructureSchema::isReady($pdoA36), 'A3-6 isReady FALSE before repair');
        p6Apply($pdoA36, '065_personel_org_structure.sql');
        p6Assert(p6FkExists($pdoA36, 'bolumler', 'fk_bolumler_departman'), 'A3-6 bolum FK repaired');
        p6Assert(p6FkExists($pdoA36, 'birimler', 'fk_birimler_bolum'), 'A3-6 birim FK repaired');
        p6Assert(p6FkExists($pdoA36, 'personeller', 'fk_personeller_bolum'), 'A3-6 personel FK repaired');
        p6Assert(p6FkExists($pdoA36, 'subeler', 'fk_subeler_sgk_isveren'), 'A3-6 sube FK repaired');
        p6Assert(PersonelOrgStructureSchema::isReady($pdoA36), 'A3-6 isReady TRUE after repair');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbA36 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- A3-7: unsafe partial with rows — fail closed, no invent/backfill -----
    $dbA37 = 'medisa_pack6_a37_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbA37);
    $root->exec('CREATE DATABASE `' . $dbA37 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoA37 = p6PdoForDb($dbA37);
    try {
        p6ApplyThrough($pdoA37, $files, '064_personel_org_location_model.sql');
        $pdoA37->exec(
            "CREATE TABLE bolumler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoA37->exec('INSERT INTO bolumler () VALUES ()');
        p6Assert((int) $pdoA37->query('SELECT COUNT(*) FROM bolumler')->fetchColumn() === 1, 'A3-7 has rows');
        $caughtA37 = null;
        try {
            p6Apply($pdoA37, '065_personel_org_structure.sql');
        } catch (Throwable $e) {
            $caughtA37 = $e;
        }
        p6Assert($caughtA37 !== null, 'A3-7 migration STOP/FAIL CLOSED');
        p6Assert(
            strpos($caughtA37->getMessage(), 'PACK6_065_BLOCKER') !== false
                || strpos($caughtA37->getMessage(), 'departman_id') !== false,
            'A3-7 deterministic blocker message'
        );
        p6Assert(!p6ColumnExists($pdoA37, 'bolumler', 'departman_id'), 'A3-7 did not invent departman_id');
        p6Assert(!PersonelOrgStructureSchema::isReady($pdoA37), 'A3-7 remains not ready');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbA37 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- A3-8 / T7: full 065 reapply remains idempotent -----
    $dbA38 = 'medisa_pack6_a38_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbA38);
    $root->exec('CREATE DATABASE `' . $dbA38 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoA38 = p6PdoForDb($dbA38);
    try {
        foreach ($files as $file) {
            p6Apply($pdoA38, $file);
        }
        p6Assert(PersonelOrgStructureSchema::isReady($pdoA38), 'A3-8 ready after first 065');
        p6Apply($pdoA38, '065_personel_org_structure.sql');
        p6Apply($pdoA38, '065_personel_org_structure.sql');
        p6Assert(PersonelOrgStructureSchema::isReady($pdoA38), 'A3-8 ready after reapply');
        p6Assert(p6FkExists($pdoA38, 'bolumler', 'fk_bolumler_departman'), 'A3-8 FK stable');
        p6Assert(p6IndexExists($pdoA38, 'pozisyonlar', 'uq_pozisyonlar_ad'), 'A3-8 unique stable');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbA38 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- T1: existing bolumler rows missing durum => FAIL_CLOSED, no AKTIF fabricated -----
    $dbT1 = 'medisa_pack6_t1_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbT1);
    $root->exec('CREATE DATABASE `' . $dbT1 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoT1 = p6PdoForDb($dbT1);
    try {
        p6ApplyThrough($pdoT1, $files, '064_personel_org_location_model.sql');
        $pdoT1->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'T1 Dep', 'AKTIF')");
        $pdoT1->exec(
            "CREATE TABLE bolumler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              departman_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoT1->exec("INSERT INTO bolumler (departman_id, ad) VALUES (1, 'Partial Bolum')");
        p6Assert(!p6ColumnExists($pdoT1, 'bolumler', 'durum'), 'T1 durum absent');
        p6Assert((int) $pdoT1->query('SELECT COUNT(*) FROM bolumler')->fetchColumn() === 1, 'T1 has rows');
        $caughtT1 = null;
        try {
            p6Apply($pdoT1, '065_personel_org_structure.sql');
        } catch (Throwable $e) {
            $caughtT1 = $e;
        }
        p6Assert($caughtT1 !== null, 'T1 migration FAIL_CLOSED');
        p6Assert(strpos($caughtT1->getMessage(), 'PACK6_065_BLOCKER') !== false, 'T1 PACK6_065_BLOCKER');
        p6Assert(strpos($caughtT1->getMessage(), 'durum missing with rows') !== false, 'T1 durum blocker');
        p6Assert(!p6ColumnExists($pdoT1, 'bolumler', 'durum'), 'T1 did not fabricate durum/AKTIF');
        p6Assert(!PersonelOrgStructureSchema::isReady($pdoT1), 'T1 remains not ready');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbT1 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- T2: existing birimler rows missing created_at => FAIL_CLOSED -----
    $dbT2 = 'medisa_pack6_t2_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbT2);
    $root->exec('CREATE DATABASE `' . $dbT2 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoT2 = p6PdoForDb($dbT2);
    try {
        p6ApplyThrough($pdoT2, $files, '064_personel_org_location_model.sql');
        $pdoT2->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'T2 Dep', 'AKTIF')");
        $pdoT2->exec(
            "CREATE TABLE bolumler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              departman_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_bolumler_departman_ad (departman_id, ad)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoT2->exec(
            "ALTER TABLE bolumler
               ADD CONSTRAINT fk_bolumler_departman
                 FOREIGN KEY (departman_id) REFERENCES departmanlar (id) ON DELETE RESTRICT"
        );
        $pdoT2->exec("INSERT INTO bolumler (id, departman_id, ad, durum) VALUES (1, 1, 'Bolum', 'AKTIF')");
        $pdoT2->exec(
            "CREATE TABLE birimler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              bolum_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoT2->exec("INSERT INTO birimler (bolum_id, ad, durum) VALUES (1, 'Partial Birim', 'AKTIF')");
        p6Assert(!p6ColumnExists($pdoT2, 'birimler', 'created_at'), 'T2 created_at absent');
        p6Assert((int) $pdoT2->query('SELECT COUNT(*) FROM birimler')->fetchColumn() === 1, 'T2 has rows');
        $caughtT2 = null;
        try {
            p6Apply($pdoT2, '065_personel_org_structure.sql');
        } catch (Throwable $e) {
            $caughtT2 = $e;
        }
        p6Assert($caughtT2 !== null, 'T2 migration FAIL_CLOSED');
        p6Assert(strpos($caughtT2->getMessage(), 'PACK6_065_BLOCKER') !== false, 'T2 PACK6_065_BLOCKER');
        p6Assert(strpos($caughtT2->getMessage(), 'created_at missing with rows') !== false, 'T2 created_at blocker');
        p6Assert(!p6ColumnExists($pdoT2, 'birimler', 'created_at'), 'T2 did not fabricate created_at');
        p6Assert(!PersonelOrgStructureSchema::isReady($pdoT2), 'T2 remains not ready');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbT2 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- T4: same-named FK with wrong parent => readiness FALSE + migration FAIL_CLOSED -----
    $dbT4 = 'medisa_pack6_t4_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbT4);
    $root->exec('CREATE DATABASE `' . $dbT4 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoT4 = p6PdoForDb($dbT4);
    try {
        p6ApplyThrough($pdoT4, $files, '064_personel_org_location_model.sql');
        $pdoT4->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'T4 Dep', 'AKTIF')");
        $pdoT4->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'T4 Gorev', 'AKTIF')");
        $pdoT4->exec(
            "CREATE TABLE bolumler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              departman_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_bolumler_departman_ad (departman_id, ad),
              KEY idx_bolumler_departman (departman_id),
              KEY idx_bolumler_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        // Wrong parent: same expected name, references gorevler instead of departmanlar.
        $pdoT4->exec(
            "ALTER TABLE bolumler
               ADD CONSTRAINT fk_bolumler_departman
                 FOREIGN KEY (departman_id) REFERENCES gorevler (id) ON DELETE RESTRICT"
        );
        $pdoT4->exec(
            "CREATE TABLE birimler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              bolum_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_birimler_bolum_ad (bolum_id, ad),
              KEY idx_birimler_bolum (bolum_id),
              KEY idx_birimler_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoT4->exec(
            "CREATE TABLE pozisyonlar (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_pozisyonlar_ad (ad),
              KEY idx_pozisyonlar_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoT4->exec('ALTER TABLE personeller ADD COLUMN bolum_id INT UNSIGNED NULL AFTER departman_id');
        $pdoT4->exec('ALTER TABLE personeller ADD COLUMN birim_id INT UNSIGNED NULL AFTER bolum_id');
        $pdoT4->exec('ALTER TABLE personeller ADD COLUMN pozisyon_id INT UNSIGNED NULL AFTER gorev_id');
        $pdoT4->exec('ALTER TABLE subeler ADD COLUMN sgk_isveren_id INT UNSIGNED NULL AFTER ad');
        PersonelOrgStructureSchema::clearReadyCache();
        p6Assert(p6FkExists($pdoT4, 'bolumler', 'fk_bolumler_departman'), 'T4 wrong-named FK present');
        p6Assert(!PersonelOrgStructureSchema::isReady($pdoT4), 'T4 isReady FALSE for wrong FK semantics');
        $caughtT4 = null;
        try {
            p6Apply($pdoT4, '065_personel_org_structure.sql');
        } catch (Throwable $e) {
            $caughtT4 = $e;
        }
        p6Assert($caughtT4 !== null, 'T4 migration FAIL_CLOSED');
        p6Assert(strpos($caughtT4->getMessage(), 'PACK6_065_BLOCKER') !== false, 'T4 PACK6_065_BLOCKER');
        p6Assert(strpos($caughtT4->getMessage(), 'fk_bolumler_departman wrong semantics') !== false, 'T4 wrong FK message');
        $refT4 = $pdoT4->query(
            "SELECT referenced_table_name FROM information_schema.KEY_COLUMN_USAGE
             WHERE table_schema = DATABASE() AND table_name = 'bolumler'
               AND constraint_name = 'fk_bolumler_departman'
               AND referenced_table_name IS NOT NULL LIMIT 1"
        )->fetchColumn();
        p6Assert((string) $refT4 === 'gorevler', 'T4 did not silently replace wrong FK');
        p6Assert(!PersonelOrgStructureSchema::isReady($pdoT4), 'T4 remains not ready');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbT4 . '`');
        } catch (Throwable $e) {
        }
    }

    // ----- T5: same-named unique with wrong composition / non-unique => FAIL_CLOSED -----
    $dbT5 = 'medisa_pack6_t5_' . substr(bin2hex(random_bytes(4)), 0, 8);
    p6AssertSafeTarget($dbT5);
    $root->exec('CREATE DATABASE `' . $dbT5 . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdoT5 = p6PdoForDb($dbT5);
    try {
        p6ApplyThrough($pdoT5, $files, '064_personel_org_location_model.sql');
        $pdoT5->exec(
            "CREATE TABLE bolumler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              departman_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY uq_bolumler_departman_ad (ad),
              KEY idx_bolumler_departman (departman_id),
              KEY idx_bolumler_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoT5->exec(
            "ALTER TABLE bolumler
               ADD CONSTRAINT fk_bolumler_departman
                 FOREIGN KEY (departman_id) REFERENCES departmanlar (id) ON DELETE RESTRICT"
        );
        $pdoT5->exec(
            "CREATE TABLE birimler (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              bolum_id INT UNSIGNED NOT NULL,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_birimler_bolum_ad (bolum_id, ad),
              KEY idx_birimler_bolum (bolum_id),
              KEY idx_birimler_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoT5->exec(
            "CREATE TABLE pozisyonlar (
              id INT UNSIGNED NOT NULL AUTO_INCREMENT,
              ad VARCHAR(120) NOT NULL,
              durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              UNIQUE KEY uq_pozisyonlar_ad (ad),
              KEY idx_pozisyonlar_durum (durum)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdoT5->exec('ALTER TABLE personeller ADD COLUMN bolum_id INT UNSIGNED NULL AFTER departman_id');
        $pdoT5->exec('ALTER TABLE personeller ADD COLUMN birim_id INT UNSIGNED NULL AFTER bolum_id');
        $pdoT5->exec('ALTER TABLE personeller ADD COLUMN pozisyon_id INT UNSIGNED NULL AFTER gorev_id');
        $pdoT5->exec('ALTER TABLE subeler ADD COLUMN sgk_isveren_id INT UNSIGNED NULL AFTER ad');
        PersonelOrgStructureSchema::clearReadyCache();
        p6Assert(p6IndexExists($pdoT5, 'bolumler', 'uq_bolumler_departman_ad'), 'T5 wrong-named index present');
        p6Assert(!PersonelOrgStructureSchema::isReady($pdoT5), 'T5 isReady FALSE for wrong unique semantics');
        $caughtT5 = null;
        try {
            p6Apply($pdoT5, '065_personel_org_structure.sql');
        } catch (Throwable $e) {
            $caughtT5 = $e;
        }
        p6Assert($caughtT5 !== null, 'T5 migration FAIL_CLOSED');
        p6Assert(strpos($caughtT5->getMessage(), 'PACK6_065_BLOCKER') !== false, 'T5 PACK6_065_BLOCKER');
        p6Assert(strpos($caughtT5->getMessage(), 'uq_bolumler_departman_ad wrong semantics') !== false, 'T5 wrong unique message');
        $nonUniqueT5 = (int) $pdoT5->query(
            "SELECT NON_UNIQUE FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE() AND table_name = 'bolumler'
               AND index_name = 'uq_bolumler_departman_ad' LIMIT 1"
        )->fetchColumn();
        p6Assert($nonUniqueT5 === 1, 'T5 did not silently replace wrong index');
        p6Assert(!PersonelOrgStructureSchema::isReady($pdoT5), 'T5 remains not ready');
    } finally {
        try {
            $root->exec('DROP DATABASE IF EXISTS `' . $dbT5 . '`');
        } catch (Throwable $e) {
        }
    }
} finally {
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $dbPartial . '`');
    } catch (Throwable $e) {
    }
}

echo 'verify-org-structure-pack6-mysql: OK' . PHP_EOL;
