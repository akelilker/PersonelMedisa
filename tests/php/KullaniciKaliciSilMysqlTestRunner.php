<?php

declare(strict_types=1);

/**
 * MG-KULLANICI-KALICI-SIL-001 — the safe Kalıcı Sil (hard delete) owner,
 * driven through the real controller against a real disposable MariaDB.
 *
 * Every case here goes through GET /yonetim/kullanicilar/{id}/silinebilirlik-kontrolu
 * and POST /yonetim/kullanicilar/{id}/kalici-sil in a child process, so the
 * HTTP status, the eligibility verdict and the audit evidence are observed the
 * way production would produce them rather than by calling the service writer
 * directly.
 *
 * Nothing here touches production. The disposable database is created and
 * dropped inside this runner; no live data is mutated.
 *
 * php tests/php/KullaniciKaliciSilMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Controllers\YonetimController;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\Request;

const KSA_AUDIT_TABLE = 'user_kalici_silme_auditleri';
const KSA_REVOKE_TABLE = 'user_erisim_kaldirma_auditleri';
const KSA_MIGRATION_TIP = '099_user_kalici_silme_auditleri.sql';

function ksaAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function ksaPdo(string $dsn): PDO
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

function ksaApplyFile(PDO $pdo, string $relative): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $relative);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $relative);
    }
    $pdo->exec($sql);
}

function ksaSetPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

/** @param array<string, mixed>|null $user */
function ksaResetAuth($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

/** @param array<string, mixed> $body */
function ksaRequest(string $method, string $path, array $body = []): Request
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
 * readiness caches can never leak a stale answer between cases.
 *
 * @param array<string, mixed>|null $auth
 * @param array<string, mixed> $extra
 * @return array{status:int, payload:array<string,mixed>}
 */
function ksaHttp(PDO $pdo, $auth, string $action, array $extra = []): array
{
    $statusFile = tempnam(sys_get_temp_dir(), 'ksa_');
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
function ksaRow(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function ksaCount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

/** @return array<string, mixed>|null */
function ksaUser(PDO $pdo, int $id): ?array
{
    return ksaRow($pdo, 'SELECT id, username, ad_soyad, rol, durum, personel_id FROM users WHERE id = :id', [':id' => $id]);
}

/**
 * @return array<string, mixed>
 */
function ksaEligibility(PDO $pdo, $auth, int $id): array
{
    $r = ksaHttp($pdo, $auth, 'kullanici_silinebilirlik', ['id' => $id]);
    if ($r['status'] !== 200) {
        throw new RuntimeException('eligibility http status ' . $r['status'] . ' for user ' . $id);
    }

    return $r['payload']['data'];
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
    ksaSetPdo(new PDO(
        (string) $dsn,
        (string) $cfg['user'],
        (string) $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    ));
    ksaResetAuth(is_array($cfg['auth'] ?? null) ? $cfg['auth'] : null);

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

    if ($action === 'kullanici_silinebilirlik') {
        $_GET = [];
        YonetimController::kullaniciSilinebilirlikKontrolu(ksaRequest('GET', '/yonetim/kullanicilar/' . $id, []), $id);
    } elseif ($action === 'kullanici_kalici_sil') {
        $_GET = [];
        YonetimController::kullaniciKaliciSil(ksaRequest('POST', '/yonetim/kullanicilar/' . $id . '/kalici-sil', $extra), $id);
    } elseif ($action === 'kullanici_erisim_kaldir') {
        $_GET = [];
        YonetimController::kullaniciErisimKaldir(ksaRequest('DELETE', '/yonetim/kullanicilar/' . $id, $extra), $id);
    } else {
        fwrite(STDERR, "unknown action\n");
        exit(2);
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Parent: disposable database, full canonical chain, then the delete contract.
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

$db = 'medisa_ksa_' . bin2hex(random_bytes(5));
$baseDsn = preg_replace('/;?dbname=[^;]*/i', '', $rootDsn) ?: $rootDsn;
$root = ksaPdo($baseDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = ksaPdo($baseDsn . ';dbname=' . $db);

    // Full canonical chain through the current code tip, minus the reference-data
    // gate (067) which needs a populated catalog no schema test owns.
    $chain = array_values(array_filter(
        scandir(__DIR__ . '/../../api/migrations') ?: [],
        static fn ($name): bool => (bool) preg_match('/^\d{3}_.+\.sql$/', (string) $name)
            && $name !== '067_personel_canonical_reference_gate.sql'
    ));
    sort($chain, SORT_STRING);
    ksaAssert(end($chain) === KSA_MIGRATION_TIP, 'the canonical chain tip is migration 099');

    // 099 must never identify protected people from usernames. On a populated
    // database it refuses to apply until the operator supplies two verified,
    // distinct users.id values in the migration session; a fresh empty schema
    // may apply but remains service-fail-closed until registered.
    $preconditionDb = 'medisa_ksa_pre_' . bin2hex(random_bytes(4));
    $root->exec('CREATE DATABASE `' . $preconditionDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    try {
        $preconditionPdo = ksaPdo($baseDsn . ';dbname=' . $preconditionDb);
        foreach (array_filter($chain, static fn (string $migration): bool => $migration !== KSA_MIGRATION_TIP) as $migration) {
            ksaApplyFile($preconditionPdo, $migration);
        }
        $preconditionPdo->exec(
            "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
                (21, 'verified.one', 'x', 'Verified One', 'SISTEM_YONETICISI', 'AKTIF'),
                (22, 'verified.two', 'x', 'Verified Two', 'SISTEM_YONETICISI', 'AKTIF')"
        );
        $withoutAttestationBlocked = false;
        try {
            ksaApplyFile($preconditionPdo, KSA_MIGRATION_TIP);
        } catch (\Throwable $exception) {
            $withoutAttestationBlocked = true;
        }
        ksaAssert(
            $withoutAttestationBlocked,
            'migration 099 refuses a populated database without verified protected-account IDs'
        );
        // Missing identity must abort before the first DDL: nothing may exist.
        ksaAssert(
            ksaCount(
                $preconditionPdo,
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . KSA_AUDIT_TABLE . "'"
            ) === 0,
            'migration 099 leaves no partial audit table when the protected-account IDs are missing'
        );
        ksaAssert(
            ksaCount(
                $preconditionPdo,
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'silinmesi_korunur'"
            ) === 0,
            'migration 099 leaves no partial protected-account flag when the IDs are missing'
        );
        ksaAssert(
            ksaCount(
                $preconditionPdo,
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_kalici_silme_korunan_hesaplar'"
            ) === 0,
            'migration 099 leaves no partial registry table when the IDs are missing'
        );

        // An orphaned reference in one of the 15 FK-less columns must also abort
        // before the first DDL, not fail mid-way through the ADD CONSTRAINTs.
        $preconditionPdo->exec(
            "INSERT INTO offline_mutation_idempotency
                (actor_user_id, operation_scope, idempotency_key, payload_hash, state, created_at)
             VALUES (9999, 'orphan', 'orphan-key-1', '" . str_repeat('a', 64) . "', 'COMPLETED', NOW(3))"
        );
        $preconditionPdo->exec('SET @p099_protected_ilker_user_id = 21');
        $preconditionPdo->exec('SET @p099_protected_serhan_user_id = 22');
        $orphanBlocked = false;
        try {
            ksaApplyFile($preconditionPdo, KSA_MIGRATION_TIP);
        } catch (\Throwable $exception) {
            $orphanBlocked = true;
        }
        ksaAssert(
            $orphanBlocked,
            'migration 099 refuses a populated database with an orphaned FK-less reference'
        );
        ksaAssert(
            ksaCount(
                $preconditionPdo,
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . KSA_AUDIT_TABLE . "'"
            ) === 0,
            'migration 099 leaves no partial audit table when an orphan is present'
        );
        $preconditionPdo->exec("DELETE FROM offline_mutation_idempotency WHERE idempotency_key = 'orphan-key-1'");
        ksaApplyFile($preconditionPdo, KSA_MIGRATION_TIP);
        ksaAssert(
            ksaCount(
                $preconditionPdo,
                "SELECT COUNT(*) FROM user_kalici_silme_korunan_hesaplar
                 WHERE protection_key IN ('ILKER_A', 'SERHAN_KOSE') AND user_id IN (21, 22)"
            ) === 2,
            'migration 099 records only the operator-verified protected-account IDs'
        );
        ksaAssert(
            ksaCount($preconditionPdo, 'SELECT COUNT(*) FROM users WHERE id IN (21, 22) AND silinmesi_korunur = 1') === 2,
            'migration 099 flags the verified protected accounts without username matching'
        );
    } finally {
        $root->exec('DROP DATABASE IF EXISTS `' . $preconditionDb . '`');
    }
    foreach ($chain as $migration) {
        ksaApplyFile($pdo, (string) $migration);
    }

    ksaAssert(
        ksaCount(
            $pdo,
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . KSA_AUDIT_TABLE . "'"
        ) === 1,
        'migration 099 created the kalici silme audit table'
    );
    ksaAssert(
        ksaCount(
            $pdo,
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'silinmesi_korunur'"
        ) === 1,
        'migration 099 added the stable protected-account flag'
    );
    ksaAssert(
        ksaCount(
            $pdo,
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_kalici_silme_korunan_hesaplar'"
        ) === 1,
        'migration 099 created the explicit protected-account registry'
    );
    foreach ([
        'ek_odeme_kesinti.created_by',
        'ek_odeme_kesinti.updated_by',
        'gunluk_bildirimler.created_by',
        'gunluk_bildirimler.updated_by',
        'gunluk_bildirimler.correction_requested_by',
        'legal_holdlar.released_by',
        'legal_hold_auditleri.actor_user_id',
        'offline_mutation_idempotency.actor_user_id',
        'personel_gecici_gorevlendirmeler.olusturan_user_id',
        'personel_gecici_gorevlendirmeler.sonlandiran_user_id',
        'personel_import_runs.actor_id',
        'personel_test_fixture_archive_kayitlari.archived_by',
        'personel_test_fixture_siniflandirmalari.classified_by',
        'personel_test_fixture_siniflandirmalari.iptal_edildi_by',
        'retention_imha_auditleri.actor_user_id',
    ] as $reference) {
        [$table, $column] = explode('.', $reference, 2);
        ksaAssert(
            ksaCount(
                $pdo,
                "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table
                   AND COLUMN_NAME = :column
                   AND REFERENCED_TABLE_NAME = 'users'
                   AND REFERENCED_COLUMN_NAME = 'id'",
                [':table' => $table, ':column' => $column]
            ) === 1,
            'migration 099 protects the classified reference ' . $reference . ' with a users FK'
        );
    }

    // Structure: actor attribution stays a real FK (RESTRICT) while target fields
    // are data-only; append-only is enforced by BEFORE UPDATE / BEFORE DELETE.
    $actorFk = ksaCount(
        $pdo,
        "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . KSA_AUDIT_TABLE . "'
           AND COLUMN_NAME = 'actor_user_id' AND REFERENCED_TABLE_NAME = 'users'"
    );
    ksaAssert($actorFk === 1, 'the audit actor column carries a foreign key to users');
    foreach (['trg_ksa_no_update', 'trg_ksa_no_delete'] as $trigger) {
        ksaAssert(
            ksaCount(
                $pdo,
                "SELECT COUNT(*) FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name",
                [':name' => $trigger]
            ) === 1,
            'the append-only trigger ' . $trigger . ' is present'
        );
    }

    // Seed: actor, an unprivileged actor, and the full protected/blocked roster.
    $hash = password_hash('KsaSeedPass-24chars!!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO sirketler (id, kod, ad) VALUES (1, 'SRK-1', 'Sirket Bir')");
    $pdo->exec("INSERT INTO sgk_isverenler (id, kod, ad, sirket_id) VALUES (1, 'SGK-1', 'Medisa', 1)");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, sgk_isveren_id, sirket_id, durum) VALUES (1, 'SB-1', 'Fabrika', 1, 1, 'AKTIF')");
    $pdo->exec(
        "INSERT INTO personeller
            (id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
             sicil_no, ise_giris_tarihi, sube_id, aktif_durum)
         VALUES
            (173, '10000000146', 'Bagli', 'Personel', '1985-01-01', '05000000001', 'Acil Kisi', '05000000002',
             'SCL-173', '2015-01-01', 1, 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
            (1, 'gy', '{$hash}', 'Genel Yonetici', 'GENEL_YONETICI', 'AKTIF'),
            (2, 'sade', '{$hash}', 'Sade Personel', 'PERSONEL', 'AKTIF'),
            (3, 'standalone', '{$hash}', 'Standalone Kullanici', 'SISTEM_YONETICISI', 'AKTIF'),
            (4, 'ilkerA', '{$hash}', 'Ilker Yonetici', 'SISTEM_YONETICISI', 'AKTIF'),
            (5, 'serhan.kose', '{$hash}', 'Serhan Kose', 'SISTEM_YONETICISI', 'AKTIF'),
            (6, 'gy2', '{$hash}', 'Ikinci Genel Yonetici', 'GENEL_YONETICI', 'AKTIF'),
            (7, 'bagli', '{$hash}', 'Bagli Kullanici', 'PERSONEL', 'AKTIF'),
            (8, 'dep', '{$hash}', 'Bagimli Kullanici', 'SISTEM_YONETICISI', 'AKTIF'),
            (9, 'cascade', '{$hash}', 'Cascade Kullanici', 'SISTEM_YONETICISI', 'AKTIF'),
            (10, 'unverified', '{$hash}', 'Unverified Kullanici', 'SISTEM_YONETICISI', 'AKTIF'),
            (11, 'rollback', '{$hash}', 'Rollback Kullanici', 'SISTEM_YONETICISI', 'AKTIF')"
    );
    $pdo->exec('UPDATE users SET personel_id = 173 WHERE id = 7');
    // The canonical chain runs on an empty disposable schema. Mirror the
    // production migration's explicit, operator-verified identity registry
    // after fixture accounts exist; no username is used as identity evidence.
    $pdo->exec('UPDATE users SET silinmesi_korunur = 1 WHERE id IN (4, 5)');
    $pdo->exec(
        "INSERT INTO user_kalici_silme_korunan_hesaplar (protection_key, user_id)
         VALUES ('ILKER_A', 4), ('SERHAN_KOSE', 5)"
    );
    // Scope rows that must be cleaned on a successful delete and restored on rollback.
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (3, 1), (11, 1)');

    $gy = ['id' => 1, 'username' => 'gy', 'ad_soyad' => 'Genel Yonetici', 'rol' => 'GENEL_YONETICI', 'sube_ids' => []];
    $sade = ['id' => 2, 'username' => 'sade', 'ad_soyad' => 'Sade Personel', 'rol' => 'PERSONEL', 'sube_ids' => []];

    // =====================================================================
    // 1) Unauthorized actors never reach the eligibility or the delete path.
    // =====================================================================
    $r = ksaHttp($pdo, $sade, 'kullanici_silinebilirlik', ['id' => 3]);
    ksaAssert($r['status'] === 403, 'an unprivileged actor is refused with 403 for eligibility');
    $r = ksaHttp($pdo, $sade, 'kullanici_kalici_sil', ['id' => 3, 'confirm_username' => 'standalone', 'gerekce' => 'x']);
    ksaAssert($r['status'] === 403, 'an unprivileged actor is refused with 403 for deletion');
    ksaAssert(ksaUser($pdo, 3) !== null, 'the refused actor changed no user row');

    // =====================================================================
    // 2) Eligibility verdicts: SİLİNEBİLİR / ENGELLENDİ with the real blocker.
    // =====================================================================
    $elig = ksaEligibility($pdo, $gy, 3);
    ksaAssert($elig['verdict'] === 'SİLİNEBİLİR', 'a standalone account is reported SİLİNEBİLİR');

    $elig = ksaEligibility($pdo, $gy, 4);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'the protected manager account ilkerA is reported ENGELLENDİ');
    ksaAssert(
        ($elig['blockers'][0]['code'] ?? '') === 'PROTECTED_ACCOUNT',
        'ilkerA names the protected account blocker'
    );

    $elig = ksaEligibility($pdo, $gy, 5);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'the protected manager account serhan.kose is reported ENGELLENDİ');
    ksaAssert(
        ($elig['blockers'][0]['code'] ?? '') === 'PROTECTED_ACCOUNT',
        'serhan.kose names the protected account blocker'
    );

    $elig = ksaEligibility($pdo, $gy, 6);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'a GENEL_YONETICI account is reported ENGELLENDİ');
    ksaAssert(
        ($elig['blockers'][0]['code'] ?? '') === 'PROTECTED_ADMIN_ROLE',
        'the GENEL_YONETICI account names the admin role blocker'
    );

    $elig = ksaEligibility($pdo, $gy, 1);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'self-delete is reported ENGELLENDİ');
    ksaAssert(
        ($elig['blockers'][0]['code'] ?? '') === 'SELF_DELETE_FORBIDDEN',
        'self-delete names the self blocker'
    );

    $elig = ksaEligibility($pdo, $gy, 7);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'a personnel-bound account is reported ENGELLENDİ');
    ksaAssert(
        ($elig['blockers'][0]['code'] ?? '') === 'PERSONEL_BINDING',
        'the personnel-bound account names the binding blocker'
    );

    // =====================================================================
    // 3) The safe standalone delete: real row gone, scope cleaned, audit written.
    // =====================================================================
    $r = ksaHttp($pdo, $gy, 'kullanici_kalici_sil', [
        'id' => 3,
        'confirm_username' => 'standalone',
        'gerekce' => 'Yanlis olusturulmus bagimsiz hesap.',
    ]);
    ksaAssert($r['status'] === 200, 'the standalone account is physically deleted');
    ksaAssert(($r['payload']['data']['deleted'] ?? false) === true, 'the delete result confirms deletion');
    ksaAssert(ksaUser($pdo, 3) === null, 'the deleted user row no longer exists');
    $cleaned = $r['payload']['data']['cleaned_scope_rows'] ?? [];
    $cleanedSubeler = array_values(array_filter($cleaned, static fn (array $row): bool => $row['table'] === 'user_subeler'));
    ksaAssert(
        count($cleanedSubeler) === 1 && (int) $cleanedSubeler[0]['removed'] === 1,
        'the scope cleanup removed exactly the one user_subeler row'
    );
    ksaAssert(
        ksaCount($pdo, 'SELECT COUNT(*) FROM user_subeler WHERE user_id = 3') === 0,
        'no scope row survives the deleted user'
    );

    $auditCount = ksaCount($pdo, 'SELECT COUNT(*) FROM ' . KSA_AUDIT_TABLE);
    ksaAssert($auditCount === 1, 'the deleted user left immutable audit evidence');
    $audit = ksaRow($pdo, 'SELECT * FROM ' . KSA_AUDIT_TABLE . ' ORDER BY id DESC LIMIT 1');
    ksaAssert((int) $audit['target_user_id'] === 3, 'the audit names the deleted target id');
    ksaAssert($audit['target_username'] === 'standalone', 'the audit names the deleted username');
    ksaAssert((int) $audit['actor_user_id'] === 1, 'the audit names the real actor');
    ksaAssert($audit['gerekce'] !== '', 'the audit carries the mandatory justification');
    ksaAssert(
        preg_match('/^[0-9a-f]{64}$/', (string) $audit['request_hash']) === 1,
        'the audit carries the request fingerprint'
    );

    // Append-only enforcement at the database.
    $updateBlocked = false;
    try {
        $pdo->exec('UPDATE ' . KSA_AUDIT_TABLE . ' SET gerekce = \'x\' WHERE id = ' . (int) $audit['id']);
    } catch (\Throwable $exception) {
        $updateBlocked = true;
    }
    ksaAssert($updateBlocked, 'kalici silme audit rows cannot be updated');

    $deleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM ' . KSA_AUDIT_TABLE . ' WHERE id = ' . (int) $audit['id']);
    } catch (\Throwable $exception) {
        $deleteBlocked = true;
    }
    ksaAssert($deleteBlocked, 'kalici silme audit rows cannot be deleted');

    // Resubmitting the same delete after the row is gone must fail-closed.
    $r = ksaHttp($pdo, $gy, 'kullanici_kalici_sil', [
        'id' => 3,
        'confirm_username' => 'standalone',
        'gerekce' => 'Tekrar deneme.',
    ]);
    ksaAssert($r['status'] === 404, 'resubmitting the same delete is refused with 404');
    ksaAssert(
        ($r['payload']['errors'][0]['code'] ?? '') === 'NOT_FOUND',
        'the resubmit names NOT_FOUND'
    );

    // =====================================================================
    // 4) A non-scope dependency blocks deletion (and names the table).
    // =====================================================================
    $pdo->exec(
        "CREATE TABLE ks_test_block (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            CONSTRAINT fk_ks_block_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec('INSERT INTO ks_test_block (user_id) VALUES (8)');

    $elig = ksaEligibility($pdo, $gy, 8);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'a dependency-carrying account is reported ENGELLENDİ');
    ksaAssert(
        ($elig['blockers'][0]['code'] ?? '') === 'DEPENDENCY_EXISTS',
        'the dependency blocker names DEPENDENCY_EXISTS'
    );
    $r = ksaHttp($pdo, $gy, 'kullanici_kalici_sil', ['id' => 8, 'confirm_username' => 'dep', 'gerekce' => 'x']);
    ksaAssert($r['status'] === 409, 'the dependency-carrying deletion is refused with 409');
    ksaAssert(ksaUser($pdo, 8) !== null, 'the blocked account was not deleted');

    // =====================================================================
    // 5) CASCADE and SET NULL references are inventoried with their delete rule.
    // =====================================================================
    $pdo->exec(
        "CREATE TABLE ks_test_cascade (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            CONSTRAINT fk_ks_cascade_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE ks_test_setnull (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            CONSTRAINT fk_ks_setnull_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec('INSERT INTO ks_test_cascade (user_id) VALUES (9)');
    $pdo->exec('INSERT INTO ks_test_setnull (user_id) VALUES (9)');

    $elig = ksaEligibility($pdo, $gy, 9);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'the cascade/set-null account is reported ENGELLENDİ');
    $deps = $elig['dependencies'] ?? [];
    $cascadeDep = array_values(array_filter($deps, static fn (array $d): bool => $d['table'] === 'ks_test_cascade'));
    $setnullDep = array_values(array_filter($deps, static fn (array $d): bool => $d['table'] === 'ks_test_setnull'));
    ksaAssert(
        count($cascadeDep) === 1 && ($cascadeDep[0]['delete_rule'] ?? '') === 'CASCADE',
        'a CASCADE reference is inventoried with its CASCADE rule'
    );
    ksaAssert(
        count($setnullDep) === 1 && ($setnullDep[0]['delete_rule'] ?? '') === 'SET NULL',
        'a SET NULL reference is inventoried with its SET NULL rule'
    );
    ksaAssert(
        count(array_filter($elig['blockers'] ?? [], static fn (array $b): bool => $b['table'] === 'ks_test_cascade' || $b['table'] === 'ks_test_setnull')) === 2,
        'multiple simultaneous references are each reported as blockers'
    );

    // =====================================================================
    // 6) A failure writing audit evidence rolls the whole deletion back.
    // =====================================================================
    $pdo->exec(
        "CREATE TRIGGER trg_ksa_block_insert BEFORE INSERT ON " . KSA_AUDIT_TABLE . "
         FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'KSA_TEST_AUDIT_UNWRITABLE'"
    );
    $r = ksaHttp($pdo, $gy, 'kullanici_kalici_sil', [
        'id' => 11,
        'confirm_username' => 'rollback',
        'gerekce' => 'Bu islem geri alinmali.',
    ]);
    ksaAssert($r['status'] >= 500, 'a failure writing audit evidence fails the request');
    ksaAssert(ksaUser($pdo, 11) !== null, 'the rollback left the user row intact');
    ksaAssert(
        ksaCount($pdo, 'SELECT COUNT(*) FROM user_subeler WHERE user_id = 11') === 1,
        'the rollback restored the scope row'
    );
    ksaAssert(
        ksaCount($pdo, 'SELECT COUNT(*) FROM ' . KSA_AUDIT_TABLE) === 1,
        'the rollback left no partial audit row'
    );
    $pdo->exec('DROP TRIGGER trg_ksa_block_insert');

    // =====================================================================
    // 7) An unknown dependency resolves fail-closed to DOĞRULANAMADI.
    // =====================================================================
    $pdo->exec(
        "CREATE TABLE `ks_unsafe-table` (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sorumlu_user_id INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $elig = ksaEligibility($pdo, $gy, 10);
    ksaAssert($elig['verdict'] === 'DOĞRULANAMADI', 'an unknown dependency resolves to DOĞRULANAMADI');
    ksaAssert(
        ($elig['unverified'][0]['code'] ?? '') === 'DEPENDENCY_UNVERIFIED',
        'the unverified entry names DEPENDENCY_UNVERIFIED'
    );
    $pdo->exec('DROP TABLE `ks_unsafe-table`');

    // =====================================================================
    // 8) The Erişimi Kaldır owner keeps working and writing its own table.
    // =====================================================================
    $revokeBefore = ksaCount($pdo, 'SELECT COUNT(*) FROM ' . KSA_REVOKE_TABLE);
    $kaliciBefore = ksaCount($pdo, 'SELECT COUNT(*) FROM ' . KSA_AUDIT_TABLE);
    $r = ksaHttp($pdo, $gy, 'kullanici_erisim_kaldir', ['id' => 2, 'gerekce' => 'Test revoke']);
    ksaAssert($r['status'] === 200, 'the Erişimi Kaldır owner still succeeds');
    ksaAssert(
        ksaCount($pdo, 'SELECT COUNT(*) FROM ' . KSA_REVOKE_TABLE) === $revokeBefore + 1,
        'the revocation wrote exactly one row into its own audit table'
    );
    ksaAssert(
        ksaCount($pdo, 'SELECT COUNT(*) FROM ' . KSA_AUDIT_TABLE) === $kaliciBefore,
        'the revocation wrote nothing into the kalici silme audit table'
    );
    ksaAssert(
        (ksaUser($pdo, 2)['durum'] ?? '') === 'PASIF',
        'the revocation deactivated the account without deleting it'
    );
    ksaAssert(ksaUser($pdo, 2) !== null, 'the revoked account still exists (not deleted)');

    // =====================================================================
    // 9) Classified-reference inventory (gap 2): legal_holdlar.released_by is
    //    now a RESTRICT FK, a row blocks deletion, and a NEW unclassified
    //    user-reference column still fails closed to DOĞRULANAMADI.
    // =====================================================================
    $elig = ksaEligibility($pdo, $gy, 10);
    $releasedBy = array_values(array_filter(
        $elig['dependencies'] ?? [],
        static fn (array $d): bool => $d['table'] === 'legal_holdlar' && $d['column'] === 'released_by'
    ));
    ksaAssert(
        count($releasedBy) === 1 && (int) $releasedBy[0]['row_count'] === 0,
        'the classified legal_holdlar.released_by reference is inventoried'
    );

    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (13, 'classified', '{$hash}', 'Classified Kullanici', 'SISTEM_YONETICISI', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO legal_holdlar
            (target_domain, reason, created_by, released_by)
         VALUES ('KALICI_SIL', 'classified reference', 1, 13)"
    );
    $elig = ksaEligibility($pdo, $gy, 13);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'a classified reference blocks deletion');
    ksaAssert(
        ($elig['blockers'][0]['code'] ?? '') === 'DEPENDENCY_EXISTS',
        'the classified reference names DEPENDENCY_EXISTS'
    );

    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (14, 'uncat', '{$hash}', 'Uncat Kullanici', 'SISTEM_YONETICISI', 'AKTIF')");
    $pdo->exec(
        "CREATE TABLE ks_test_unclassified (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sorumlu_user_id INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $elig = ksaEligibility($pdo, $gy, 14);
    ksaAssert($elig['verdict'] === 'DOĞRULANAMADI', 'a new unclassified user-reference column resolves to DOĞRULANAMADI');
    ksaAssert(
        ($elig['unverified'][0]['code'] ?? '') === 'DEPENDENCY_UNVERIFIED',
        'the unclassified column names DEPENDENCY_UNVERIFIED'
    );
    $pdo->exec('DROP TABLE ks_test_unclassified');

    // =====================================================================
    // 10) Protected accounts (gap 4): the stable flag survives username edits.
    // =====================================================================
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, silinmesi_korunur) VALUES (12, 'korunan.gecici', '{$hash}', 'Korunan Gecici', 'SISTEM_YONETICISI', 'AKTIF', 1)");
    $elig = ksaEligibility($pdo, $gy, 12);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'a flagged protected account is reported ENGELLENDİ');
    ksaAssert(($elig['blockers'][0]['code'] ?? '') === 'PROTECTED_ACCOUNT', 'the flag names the protected account blocker');

    $pdo->exec("UPDATE users SET username = 'korunan.renamed' WHERE id = 12");
    $elig = ksaEligibility($pdo, $gy, 12);
    ksaAssert($elig['verdict'] === 'ENGELLENDİ', 'a renamed protected account stays ENGELLENDİ');
    ksaAssert(
        ($elig['blockers'][0]['code'] ?? '') === 'PROTECTED_ACCOUNT',
        'the renamed account still names the protected blocker via the stable flag'
    );

    // Missing verified identity registry must never make any account appear
    // deletable, even though the stable flag column itself still exists.
    $pdo->exec('DELETE FROM user_kalici_silme_korunan_hesaplar');
    $elig = ksaEligibility($pdo, $gy, 10);
    ksaAssert($elig['verdict'] === 'DOĞRULANAMADI', 'a missing protected-account registry resolves eligibility to DOĞRULANAMADI');
    $r = ksaHttp($pdo, $gy, 'kullanici_kalici_sil', ['id' => 10, 'confirm_username' => 'unverified', 'gerekce' => 'x']);
    ksaAssert($r['status'] === 409, 'the missing protected-account registry refuses deletion with 409');
    ksaAssert(ksaUser($pdo, 10) !== null, 'the missing protected-account registry refusal deleted nothing');
    $pdo->exec(
        "INSERT INTO user_kalici_silme_korunan_hesaplar (protection_key, user_id)
         VALUES ('ILKER_A', 4), ('SERHAN_KOSE', 5)"
    );

    // =====================================================================
    // 11) Concurrency (gap 3): the advisory lock serializes concurrent deletes.
    //     More importantly, the classified FK on legal_holdlar.released_by
    //     makes a new writer block on lockTarget() after the final dependency
    //     check and before DELETE; after the delete commits it cannot insert an
    //     orphan reference.
    // =====================================================================
    $pdo->exec("INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES (15, 'race', '{$hash}', 'Race Kullanici', 'SISTEM_YONETICISI', 'AKTIF')");

    $connA = ksaPdo($baseDsn . ';dbname=' . $db);
    $connB = ksaPdo($baseDsn . ';dbname=' . $db);

    $gotA = (int) $connA->query("SELECT GET_LOCK('medisa_kalici_sil_user_15', 5)")->fetchColumn();
    ksaAssert($gotA === 1, 'the first delete acquires the advisory lock');
    $gotB = (int) $connB->query("SELECT GET_LOCK('medisa_kalici_sil_user_15', 0)")->fetchColumn();
    ksaAssert($gotB === 0, 'a concurrent delete cannot acquire the held advisory lock');
    $connA->query("SELECT RELEASE_LOCK('medisa_kalici_sil_user_15')");

    $connA->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $connA->beginTransaction();
    $connA->query('SELECT id FROM users WHERE id = 15 FOR UPDATE')->fetch();
    $initial = (int) $connA->query('SELECT COUNT(*) FROM legal_holdlar WHERE released_by = 15')->fetchColumn();
    ksaAssert($initial === 0, 'the final dependency check sees no released_by reference');

    $connB->exec('SET innodb_lock_wait_timeout = 1');
    $lateWriterBlocked = false;
    try {
        $connB->exec(
            "INSERT INTO legal_holdlar
                (target_domain, reason, created_by, released_by)
             VALUES ('KALICI_SIL_RACE', 'late writer', 1, 15)"
        );
    } catch (\Throwable $exception) {
        $lateWriterBlocked = true;
    }
    ksaAssert(
        $lateWriterBlocked,
        'a classified late writer is blocked while the target row is locked after final verification'
    );
    $connA->exec('DELETE FROM users WHERE id = 15');
    $connA->commit();
    ksaAssert(ksaUser($pdo, 15) === null, 'the raced account was deleted only after the late writer was blocked');

    $lateWriterRejectedAfterDelete = false;
    try {
        $connB->exec(
            "INSERT INTO legal_holdlar
                (target_domain, reason, created_by, released_by)
             VALUES ('KALICI_SIL_RACE', 'post delete writer', 1, 15)"
        );
    } catch (\Throwable $exception) {
        $lateWriterRejectedAfterDelete = true;
    }
    ksaAssert(
        $lateWriterRejectedAfterDelete,
        'the classified late writer cannot create an orphan after the user DELETE'
    );

    // =====================================================================
    // 12) Missing audit schema (gap 1): eligibility fails closed to
    //     DOĞRULANAMADI and the delete is refused with 409.
    // =====================================================================
    $pdo->exec('DROP TRIGGER IF EXISTS trg_ksa_no_update');
    $elig = ksaEligibility($pdo, $gy, 10);
    ksaAssert($elig['verdict'] === 'DOĞRULANAMADI', 'a missing audit trigger resolves eligibility to DOĞRULANAMADI');
    ksaAssert(
        ($elig['unverified'][0]['code'] ?? '') === 'AUDIT_SCHEMA_NOT_READY',
        'the missing-trigger case names AUDIT_SCHEMA_NOT_READY'
    );
    $r = ksaHttp($pdo, $gy, 'kullanici_kalici_sil', ['id' => 10, 'confirm_username' => 'unverified', 'gerekce' => 'x']);
    ksaAssert($r['status'] === 409, 'the missing-trigger deletion is refused with 409');
    ksaAssert(ksaUser($pdo, 10) !== null, 'the missing-trigger refusal deleted nothing');

    $pdo->exec('DROP TABLE ' . KSA_AUDIT_TABLE);
    $elig = ksaEligibility($pdo, $gy, 10);
    ksaAssert($elig['verdict'] === 'DOĞRULANAMADI', 'a missing audit table resolves eligibility to DOĞRULANAMADI');
    $r = ksaHttp($pdo, $gy, 'kullanici_kalici_sil', ['id' => 10, 'confirm_username' => 'unverified', 'gerekce' => 'x']);
    ksaAssert($r['status'] === 409, 'the missing-audit-table deletion is refused with 409');
    ksaAssert(ksaUser($pdo, 10) !== null, 'the missing-audit-table refusal deleted nothing');

    echo "verify-kullanici-kalici-sil-mysql: OK\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
