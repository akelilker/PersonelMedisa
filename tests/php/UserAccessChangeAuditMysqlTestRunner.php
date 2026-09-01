<?php

declare(strict_types=1);

/**
 * MG-USER-ACCESS-CHANGE-AUDIT-001 — migration 082 and the access-change audit
 * owner, driven through the real controller against a real MariaDB.
 *
 * Every case here goes through PUT /yonetim/kullanicilar/{id} in a child
 * process, so the HTTP status, the audit row and the business row are all
 * observed the way production would produce them rather than by calling the
 * writer directly.
 *
 * The restore fixture mirrors the pending production changeset (user 110 from
 * 042/PASIF/IK_SORUMLUSU to sinemH/AKTIF/GENEL_YONETICI bound to personnel 173)
 * so the audit contract is proven on the exact shape it will have to record.
 * Nothing here touches production.
 *
 * php tests/php/UserAccessChangeAuditMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Database\FilesystemMigrationSourceProvider;
use Medisa\Api\Database\MigrationExecutionService;
use Medisa\Api\Http\Request;

const UACA_AUDIT_TABLE = 'user_erisim_degisiklik_auditleri';
const UACA_REVOKE_TABLE = 'user_erisim_kaldirma_auditleri';
const UACA_ORG_AUDIT_TABLE = 'personel_organizasyon_degisiklik_auditleri';
const UACA_MIGRATION_082 = '082_user_erisim_degisiklik_auditleri.sql';
const UACA_MIGRATION_083 = '083_personel_organizasyon_degisiklik_auditleri.sql';

function uacaAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function uacaPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
}

function uacaApplyFile(PDO $pdo, string $relative): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $relative);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $relative);
    }
    $pdo->exec($sql);
}

function uacaSetPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

/** @param array<string, mixed>|null $user */
function uacaResetAuth($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

/** @param array<string, mixed> $body */
function uacaRequest(string $method, string $path, array $body = []): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => strtoupper($method),
        'path' => $path,
        'headers' => [],
        'jsonBody' => $body,
        'rawBody' => $body === [] ? '' : (string) json_encode($body, JSON_UNESCAPED_UNICODE),
        'rawBodyLoaded' => true,
        'jsonBodyParsed' => true,
    ] as $name => $value) {
        if (!$ref->hasProperty($name)) {
            continue;
        }
        $prop = $ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($request, $value);
    }

    return $request;
}

/**
 * One controller call per child process, so the writer's per-process schema
 * readiness cache can never leak a stale answer between cases.
 *
 * @param array<string, mixed>|null $auth
 * @param array<string, mixed> $extra
 * @return array{status:int, payload:array<string,mixed>}
 */
function uacaHttp(PDO $pdo, $auth, string $action, array $extra = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'uaca_');
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
        'database' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'auth' => $auth,
        'action' => $action,
        'extra' => $extra,
        'status_file' => $statusFile,
    ], JSON_UNESCAPED_UNICODE);

    $cmd = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--http-child']);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $env = [];
    foreach ($_ENV as $key => $value) {
        if (is_string($key) && (is_string($value) || is_numeric($value))) {
            $env[$key] = (string) $value;
        }
    }
    foreach (['Path', 'PATH', 'SYSTEMROOT', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            $env[$key] = $value;
        }
    }
    foreach (['MEDISA_TEST_MYSQL_DSN', 'MEDISA_TEST_MYSQL_USER', 'MEDISA_TEST_MYSQL_PASSWORD'] as $key) {
        $env[$key] = getenv($key) ?: '';
    }

    $process = proc_open($cmd, $descriptors, $pipes, null, $env);
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

    $jsonStart = strpos($stdout, '{');
    $decoded = json_decode($jsonStart === false ? $stdout : substr($stdout, $jsonStart), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('http child invalid json: ' . $stdout . ' / ' . $stderr);
    }

    return ['status' => (int) $statusRaw, 'payload' => $decoded];
}

/** @return array<string, mixed>|null */
function uacaRow(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function uacaCount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function uacaAuditCount(PDO $pdo): int
{
    return uacaCount($pdo, 'SELECT COUNT(*) FROM ' . UACA_AUDIT_TABLE);
}

/** @return array<string, mixed> */
function uacaUser(PDO $pdo, int $id): array
{
    $row = uacaRow(
        $pdo,
        'SELECT id, username, ad_soyad, rol, durum, personel_id, must_change_password, password_hash
         FROM users WHERE id = :id',
        [':id' => $id]
    );
    if ($row === null) {
        throw new RuntimeException('user not found: ' . $id);
    }

    return $row;
}

function uacaScopeRowCount(PDO $pdo, int $userId): int
{
    $total = 0;
    foreach (['user_subeler', 'user_bolumler', 'user_birimler', 'user_sirketler', 'user_sgk_isverenler'] as $table) {
        $total += uacaCount($pdo, 'SELECT COUNT(*) FROM `' . $table . '` WHERE user_id = :id', [':id' => $userId]);
    }

    return $total;
}

// ---------------------------------------------------------------------------
// Child process: performs exactly one controller call.
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--http-child') {
    $raw = stream_get_contents(STDIN);
    $cfg = json_decode((string) $raw, true);
    if (!is_array($cfg)) {
        fwrite(STDERR, "bad child config\n");
        exit(2);
    }

    global $config;
    $config['db_host'] = '127.0.0.1';
    $config['db_name'] = (string) $cfg['database'];
    $config['jwt_secret'] = str_repeat('s', 32);
    $config['jwt_ttl_seconds'] = 3600;

    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $cfg['database'], (string) $cfg['dsn']);
    if (strpos((string) $dsn, 'dbname=') === false) {
        $dsn = rtrim((string) $dsn, ';') . ';dbname=' . $cfg['database'];
    }
    uacaSetPdo(new PDO(
        (string) $dsn,
        (string) $cfg['user'],
        (string) $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    ));
    uacaResetAuth(is_array($cfg['auth'] ?? null) ? $cfg['auth'] : null);

    $statusFile = (string) $cfg['status_file'];
    register_shutdown_function(static function () use ($statusFile) {
        $code = http_response_code();
        if (!is_int($code) || $code < 100) {
            $code = 200;
        }
        file_put_contents($statusFile, (string) $code);
    });

    $action = (string) ($cfg['action'] ?? '');
    $extra = is_array($cfg['extra'] ?? null) ? $cfg['extra'] : [];
    $id = (int) ($extra['id'] ?? 0);
    unset($extra['id']);

    if ($action === 'kullanici_update') {
        $_GET = [];
        YonetimController::kullaniciGuncelle(uacaRequest('PUT', '/yonetim/kullanicilar/' . $id, $extra), $id);
    } elseif ($action === 'kullanici_erisim_kaldir') {
        $_GET = [];
        YonetimController::kullaniciErisimKaldir(uacaRequest('DELETE', '/yonetim/kullanicilar/' . $id, $extra), $id);
    } else {
        fwrite(STDERR, "unknown action\n");
        exit(2);
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Parent: disposable database, full canonical chain, then the audit contract.
// ---------------------------------------------------------------------------
$rootDsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($rootDsn === '' || stripos($rootDsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $rootDsn, $hostMatch)
    && !in_array(strtolower($hostMatch[1]), ['127.0.0.1', 'localhost', '::1'], true)
) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}

$db = 'medisa_uaca_' . bin2hex(random_bytes(5));
$baseDsn = preg_replace('/;?dbname=[^;]*/i', '', $rootDsn) ?: $rootDsn;
$root = uacaPdo($baseDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = uacaPdo($baseDsn . ';dbname=' . $db);

    // The canonical chain in order through tip 083, so 082 is proven to apply on the
    // production schema shape and 083 is proven not to disturb the access-change
    // audit owner. 067 is the reference-data gate and needs a populated catalog no
    // schema test has; it owns nothing this contract touches, so it is the one file skipped.
    $chain = array_values(array_filter(
        scandir(__DIR__ . '/../../api/migrations') ?: [],
        static fn ($name): bool => (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name)
            && $name !== '067_personel_canonical_reference_gate.sql'
    ));
    sort($chain, SORT_STRING);
    uacaAssert(end($chain) === UACA_MIGRATION_083, 'the canonical chain tip is migration 083');
    foreach ($chain as $migration) {
        uacaApplyFile($pdo, (string) $migration);
    }
    uacaAssert(
        uacaCount(
            $pdo,
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . UACA_ORG_AUDIT_TABLE . "'"
        ) === 1,
        'migration 083 created the personnel organisation audit table'
    );
    uacaAssert(
        uacaCount(
            $pdo,
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . UACA_AUDIT_TABLE . "'"
        ) === 1,
        'migration 082 created the access change audit table'
    );

    // Structure: attribution foreign keys, read indexes and the event allowlist.
    $fkCount = uacaCount(
        $pdo,
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . UACA_AUDIT_TABLE . "'
           AND REFERENCED_TABLE_NAME = 'users'"
    );
    uacaAssert($fkCount === 2, 'actor and target both carry a foreign key to users');
    foreach (['idx_ueda_target_created', 'idx_ueda_actor_created', 'idx_ueda_event_created', 'idx_ueda_request_hash'] as $index) {
        uacaAssert(
            uacaCount(
                $pdo,
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . UACA_AUDIT_TABLE . "'
                   AND INDEX_NAME = :name",
                [':name' => $index]
            ) > 0,
            'index ' . $index . ' present'
        );
    }

    // Seed: the actor, an unprivileged actor, the revoked account and personnel.
    $hash = password_hash('UacaSeedPass-24chars!!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO sirketler (id, kod, ad) VALUES (1, 'SRK-1', 'Sirket Bir')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, sirket_id) VALUES (1, 'SGK-1', 'Medisa', 1)");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id, sirket_id, durum) VALUES (1, 'SB-1', 'Fabrika', 1, 1, 'AKTIF')");
    $pdo->exec(
        "INSERT INTO personeller
            (id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
             sicil_no, ise_giris_tarihi, sube_id, aktif_durum)
         VALUES
            (173, '10000000146', 'Sinem', 'Hamaloğlu', '1985-01-01', '05000000001', 'Acil Kisi', '05000000002',
             'SCL-173', '2015-01-01', 1, 'AKTIF'),
            (174, '10000000154', 'Baska', 'Personel', '1990-01-01', '05000000003', 'Acil Kisi', '05000000004',
             'SCL-174', '2016-01-01', 1, 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
            (1, 'gy', '{$hash}', 'Genel Yonetici', 'GENEL_YONETICI', 'AKTIF'),
            (2, 'sade', '{$hash}', 'Sade Personel', 'PERSONEL', 'AKTIF'),
            (110, '042', '{$hash}', 'Sinem Hamaloğlu', 'IK_SORUMLUSU', 'PASIF'),
            (111, 'bagli', '{$hash}', 'Bagli Kullanici', 'SISTEM_YONETICISI', 'AKTIF')"
    );
    $pdo->exec('UPDATE users SET personel_id = 174 WHERE id = 111');

    $gy = ['id' => 1, 'username' => 'gy', 'ad_soyad' => 'Genel Yonetici', 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $sade = ['id' => 2, 'username' => 'sade', 'ad_soyad' => 'Sade Personel', 'rol' => 'PERSONEL', 'sube_ids' => []];

    $preimage = uacaUser($pdo, 110);
    uacaAssert(
        $preimage['username'] === '042'
            && $preimage['durum'] === 'PASIF'
            && $preimage['rol'] === 'IK_SORUMLUSU'
            && $preimage['personel_id'] === null,
        'the restore fixture starts from the recorded production preimage'
    );
    uacaAssert(uacaAuditCount($pdo) === 0, 'no access change audit row exists before the first change');

    // =====================================================================
    // 1) Unauthorized actor never reaches the business or audit write.
    // =====================================================================
    $r = uacaHttp($pdo, $sade, 'kullanici_update', ['id' => 110, 'durum' => 'AKTIF']);
    uacaAssert($r['status'] === 403, 'an unprivileged actor is refused with 403');
    uacaAssert(uacaUser($pdo, 110)['durum'] === 'PASIF', 'the refused actor changed no business row');
    uacaAssert(uacaAuditCount($pdo) === 0, 'the refused actor wrote no audit row');

    // =====================================================================
    // 2) A taken username is refused before the transaction opens.
    // =====================================================================
    $r = uacaHttp($pdo, $gy, 'kullanici_update', ['id' => 110, 'username' => 'bagli', 'durum' => 'AKTIF']);
    uacaAssert($r['status'] === 409, 'a taken username is refused with 409');
    uacaAssert(
        ($r['payload']['errors'][0]['code'] ?? '') === 'DUPLICATE_USERNAME',
        'the duplicate username error names its own code'
    );
    $afterDuplicate = uacaUser($pdo, 110);
    uacaAssert(
        $afterDuplicate['username'] === '042' && $afterDuplicate['durum'] === 'PASIF',
        'the duplicate username attempt left the business row untouched'
    );
    uacaAssert(uacaAuditCount($pdo) === 0, 'the duplicate username attempt wrote no audit row');

    // =====================================================================
    // 3) Fail-closed while migration 082 is not applied yet.
    // =====================================================================
    $pdo->exec('DROP TABLE ' . UACA_AUDIT_TABLE);
    $r = uacaHttp($pdo, $gy, 'kullanici_update', ['id' => 110, 'durum' => 'AKTIF']);
    uacaAssert($r['status'] === 409, 'a security change without migration 082 is refused with 409');
    uacaAssert(
        ($r['payload']['errors'][0]['code'] ?? '') === 'USER_ACCESS_AUDIT_SCHEMA_NOT_READY',
        'the refusal names USER_ACCESS_AUDIT_SCHEMA_NOT_READY'
    );
    uacaAssert(uacaUser($pdo, 110)['durum'] === 'PASIF', 'the unauditable environment changed no access field');

    // An unrelated edit must stay available while the audit owner is missing.
    $r = uacaHttp($pdo, $gy, 'kullanici_update', ['id' => 111, 'ad_soyad' => 'Bagli Kullanici Yeni']);
    uacaAssert($r['status'] === 200, 'a non-security edit still succeeds without migration 082');
    uacaAssert(
        uacaUser($pdo, 111)['ad_soyad'] === 'Bagli Kullanici Yeni',
        'the non-security edit was actually applied'
    );
    uacaApplyFile($pdo, UACA_MIGRATION_082);
    uacaAssert(uacaAuditCount($pdo) === 0, 'restoring the audit owner starts from an empty history');

    // =====================================================================
    // 4) A personnel binding conflict rolls the whole request back.
    // =====================================================================
    $r = uacaHttp($pdo, $gy, 'kullanici_update', [
        'id' => 110,
        'durum' => 'AKTIF',
        'rol' => 'GENEL_YONETICI',
        'personel_id' => 174,
    ]);
    uacaAssert($r['status'] === 409, 'binding an already bound personnel record is refused with 409');
    $afterConflict = uacaUser($pdo, 110);
    uacaAssert(
        $afterConflict['durum'] === 'PASIF'
            && $afterConflict['rol'] === 'IK_SORUMLUSU'
            && $afterConflict['personel_id'] === null,
        'the binding conflict rolled the status and role back too'
    );
    uacaAssert(uacaAuditCount($pdo) === 0, 'the binding conflict wrote no audit row');

    // =====================================================================
    // 5) An unwritable audit row aborts the entire access change.
    // =====================================================================
    $pdo->exec(
        'CREATE TRIGGER trg_uaca_block_audit BEFORE INSERT ON ' . UACA_AUDIT_TABLE . '
         FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'UACA_TEST_AUDIT_UNWRITABLE\''
    );
    $passwordBeforeFailure = uacaUser($pdo, 110)['password_hash'];
    $r = uacaHttp($pdo, $gy, 'kullanici_update', [
        'id' => 110,
        'username' => 'sinemH',
        'durum' => 'AKTIF',
        'rol' => 'GENEL_YONETICI',
        'personel_id' => 173,
        'baslangic_sifresine_sifirla' => true,
    ]);
    uacaAssert($r['status'] >= 500, 'an unwritable audit row fails the request');
    $afterAuditFailure = uacaUser($pdo, 110);
    uacaAssert(
        $afterAuditFailure['username'] === '042'
            && $afterAuditFailure['durum'] === 'PASIF'
            && $afterAuditFailure['rol'] === 'IK_SORUMLUSU'
            && $afterAuditFailure['personel_id'] === null,
        'audit failure rolled the username, status, role and binding back'
    );
    uacaAssert(
        $afterAuditFailure['password_hash'] === $passwordBeforeFailure,
        'audit failure rolled the credential reset back'
    );
    uacaAssert(uacaAuditCount($pdo) === 0, 'audit failure left no partial audit row');
    $pdo->exec('DROP TRIGGER trg_uaca_block_audit');

    // =====================================================================
    // 6) The Sinem restore: one request, one combined audit row.
    // =====================================================================
    $r = uacaHttp($pdo, $gy, 'kullanici_update', [
        'id' => 110,
        'username' => 'sinemH',
        'durum' => 'AKTIF',
        'rol' => 'GENEL_YONETICI',
        'personel_id' => 173,
        'baslangic_sifresine_sifirla' => true,
    ]);
    uacaAssert($r['status'] === 200, 'the combined access restore succeeds');

    $restored = uacaUser($pdo, 110);
    uacaAssert($restored['username'] === 'sinemH', 'username moved from 042 to sinemH');
    uacaAssert($restored['durum'] === 'AKTIF', 'status moved from PASIF to AKTIF');
    uacaAssert($restored['rol'] === 'GENEL_YONETICI', 'role moved to GENEL_YONETICI');
    uacaAssert((int) $restored['personel_id'] === 173, 'the account is bound to personnel 173');
    uacaAssert((int) $restored['must_change_password'] === 1, 'the credential reset forces a password change');
    uacaAssert(
        $restored['password_hash'] !== $passwordBeforeFailure,
        'the credential was actually reissued rather than kept'
    );
    uacaAssert(uacaScopeRowCount($pdo, 110) === 0, 'the restore added no explicit scope row');

    uacaAssert(uacaAuditCount($pdo) === 1, 'the multi-field restore produced exactly one audit row');
    $audit = uacaRow($pdo, 'SELECT * FROM ' . UACA_AUDIT_TABLE . ' ORDER BY id DESC LIMIT 1');
    uacaAssert($audit !== null, 'the audit row is readable');
    uacaAssert($audit['event_type'] === 'COMBINED_ACCESS_CHANGE', 'the event is typed COMBINED_ACCESS_CHANGE');
    uacaAssert((int) $audit['actor_user_id'] === 1, 'the audit names the real actor');
    uacaAssert((int) $audit['target_user_id'] === 110, 'the audit names the target account');
    uacaAssert(
        $audit['eski_durum'] === 'PASIF' && $audit['yeni_durum'] === 'AKTIF',
        'the audit carries the exact status before and after'
    );
    uacaAssert(
        $audit['eski_rol'] === 'IK_SORUMLUSU' && $audit['yeni_rol'] === 'GENEL_YONETICI',
        'the audit carries the exact role before and after'
    );
    uacaAssert(
        $audit['eski_username'] === '042' && $audit['yeni_username'] === 'sinemH',
        'the audit carries the exact username before and after'
    );
    uacaAssert(
        $audit['eski_personel_id'] === null && (int) $audit['yeni_personel_id'] === 173,
        'the audit carries the exact personnel binding before and after'
    );
    uacaAssert(
        preg_match('/^[0-9a-f]{64}$/', (string) $audit['request_hash']) === 1,
        'the audit carries a request fingerprint'
    );
    $auditColumns = array_keys($audit);
    foreach (['password_hash', 'password', 'token', 'token_hash', 'invitation'] as $forbidden) {
        uacaAssert(!in_array($forbidden, $auditColumns, true), 'the audit row carries no ' . $forbidden . ' column');
    }

    // =====================================================================
    // 7) Append-only enforcement at the database.
    // =====================================================================
    $updateBlocked = false;
    try {
        $pdo->exec('UPDATE ' . UACA_AUDIT_TABLE . ' SET yeni_rol = \'PERSONEL\' WHERE id = ' . (int) $audit['id']);
    } catch (\Throwable $exception) {
        $updateBlocked = true;
    }
    uacaAssert($updateBlocked, 'access change audit rows cannot be updated');

    $deleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM ' . UACA_AUDIT_TABLE . ' WHERE id = ' . (int) $audit['id']);
    } catch (\Throwable $exception) {
        $deleteBlocked = true;
    }
    uacaAssert($deleteBlocked, 'access change audit rows cannot be deleted');

    // =====================================================================
    // 8) An ordinary edit is not an access event.
    // =====================================================================
    $r = uacaHttp($pdo, $gy, 'kullanici_update', ['id' => 110, 'ad_soyad' => 'Sinem Hamaloğlu']);
    uacaAssert($r['status'] === 200, 'a display name edit succeeds');
    uacaAssert(uacaAuditCount($pdo) === 1, 'a non-security edit produced no additional access audit row');

    // =====================================================================
    // 9) Single-axis changes are typed by their own axis.
    // =====================================================================
    $singleAxisCases = [
        [['id' => 110, 'rol' => 'SISTEM_YONETICISI'], 'ROLE_CHANGE', 'a role-only change is typed ROLE_CHANGE'],
        [['id' => 110, 'username' => 'sinemHY'], 'USERNAME_CHANGE', 'a username-only change is typed USERNAME_CHANGE'],
        [['id' => 110, 'durum' => 'PASIF'], 'STATUS_CHANGE', 'a deactivation through the update owner is typed STATUS_CHANGE'],
        [['id' => 110, 'durum' => 'AKTIF'], 'ACCESS_RESTORE', 'a lone reactivation is typed ACCESS_RESTORE'],
        [['id' => 110, 'personel_id' => null], 'PERSONEL_BINDING_CHANGE', 'a binding-only change is typed PERSONEL_BINDING_CHANGE'],
    ];
    $expectedCount = 1;
    foreach ($singleAxisCases as [$body, $expectedEvent, $label]) {
        $r = uacaHttp($pdo, $gy, 'kullanici_update', $body);
        uacaAssert($r['status'] === 200, $label . ' (request succeeds)');
        $expectedCount++;
        uacaAssert(uacaAuditCount($pdo) === $expectedCount, $label . ' (exactly one new audit row)');
        $latest = uacaRow($pdo, 'SELECT event_type FROM ' . UACA_AUDIT_TABLE . ' ORDER BY id DESC LIMIT 1');
        uacaAssert(($latest['event_type'] ?? '') === $expectedEvent, $label);
    }

    // A resent identical value is not an event at all.
    $r = uacaHttp($pdo, $gy, 'kullanici_update', ['id' => 110, 'rol' => 'SISTEM_YONETICISI', 'durum' => 'AKTIF']);
    uacaAssert($r['status'] === 200, 'resending the current access values succeeds');
    uacaAssert(uacaAuditCount($pdo) === $expectedCount, 'resending unchanged access values produced no audit row');

    // =====================================================================
    // 10) The revocation owner keeps writing to its own table.
    // =====================================================================
    $revokeBefore = uacaCount($pdo, 'SELECT COUNT(*) FROM ' . UACA_REVOKE_TABLE);
    $accessBefore = uacaAuditCount($pdo);
    $r = uacaHttp($pdo, $gy, 'kullanici_erisim_kaldir', ['id' => 110, 'gerekce' => 'Test revoke']);
    uacaAssert($r['status'] === 200, 'the revocation owner still succeeds');
    uacaAssert(
        uacaCount($pdo, 'SELECT COUNT(*) FROM ' . UACA_REVOKE_TABLE) === $revokeBefore + 1,
        'the revocation wrote exactly one row into its own audit table'
    );
    uacaAssert(
        uacaAuditCount($pdo) === $accessBefore,
        'the revocation owner wrote nothing into the access change table'
    );
    uacaAssert(uacaUser($pdo, 110)['durum'] === 'PASIF', 'the revocation deactivated the account');

    echo "verify-user-access-change-audit-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
