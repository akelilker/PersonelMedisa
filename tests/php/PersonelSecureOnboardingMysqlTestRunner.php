<?php

declare(strict_types=1);

/**
 * Secure personnel onboarding + activation (075) — disposable MariaDB acceptance.
 * php tests/php/PersonelSecureOnboardingMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\AuthMiddleware;
use Medisa\Api\Auth\ChangePasswordController;
use Medisa\Api\Auth\LoginController;
use Medisa\Api\Auth\PasswordHasher;
use Medisa\Api\Database\Connection;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\Request;
use Medisa\Api\Http\ResponseCaptured;
use Medisa\Api\Services\Auth\PersonelAccountOnboardingService;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityService;

function psoAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** Login/change-password akisi icin Connection singleton'i test DB'sine baglar. */
function psoSetPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function psoSetAuthUser($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

/** @param array<string, mixed> $body */
function psoRequest(array $body, string $path = '/auth/login'): Request
{
    $request = new Request();
    $ref = new ReflectionClass($request);
    foreach ([
        'method' => 'POST',
        'path' => $path,
        'headers' => [],
        'jsonBody' => $body,
        'rawBody' => (string) json_encode($body, JSON_UNESCAPED_UNICODE),
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
 * JsonResponse uretimini exit() olmadan yakalar.
 *
 * @return array<string, mixed>
 */
function psoCapture(callable $fn): array
{
    JsonResponse::beginCapture();
    $captured = null;
    try {
        $fn();
        $captured = JsonResponse::capturedResponse();
    } catch (ResponseCaptured $capturedSignal) {
        $captured = JsonResponse::capturedResponse();
    } finally {
        JsonResponse::endCapture();
    }

    return is_array($captured) ? $captured : [];
}

/** @return array{invitation: array<string, mixed>, issued: array<string, mixed>} */
function psoLegacyActivationFixture(PDO $pdo, int $userId, array $actor): array
{
    // Create yolu davet uretmez; legacy akis yalniz gecmis activation_required=1 hesaplar icindir.
    $pdo->exec('UPDATE users SET activation_required = 1 WHERE id = ' . $userId);
    $issued = PersonelAccountOnboardingService::reissueActivation($pdo, $userId, $actor);
    $invitation = $pdo->query(
        'SELECT * FROM personel_account_activation_invitations
          WHERE user_id = ' . $userId . ' ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($invitation)) {
        throw new RuntimeException('[FAIL] legacy activation fixture invitation missing');
    }

    return ['invitation' => $invitation, 'issued' => $issued];
}

function psoTokenFromUrl(string $url): string
{
    parse_str(parse_url($url, PHP_URL_FRAGMENT) ?: '', $fragment);

    return (string) ($fragment['token'] ?? '');
}

function psoChildPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Disposable MariaDB credentials are required (MEDISA_TEST_MYSQL_*).');
    }

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

    return $pdo;
}

/**
 * Child worker for true multi-connection concurrency / JsonResponse exit capture.
 * Exit codes: 0 = handled; stdout last meaningful line is the result token.
 */
if (($argv[1] ?? '') === '--child') {
    $action = (string) ($argv[2] ?? '');
    $GLOBALS['config']['app_public_url'] = 'https://app.example.test/personelmedisa';
    $GLOBALS['config']['personel_activation_ttl_minutes'] = 1440;
    $pdo = psoChildPdo();
    $actor = ['id' => 1, 'rol' => 'GENEL_YONETICI'];

    try {
        if ($action === 'reissue') {
            $userId = (int) ($argv[3] ?? 0);
            $result = PersonelAccountOnboardingService::reissueActivation($pdo, $userId, $actor);
            echo 'OK:' . (string) ($result['activation']['activation_url'] ?? '') . PHP_EOL;
            exit(0);
        }
        if ($action === 'redeem') {
            $token = (string) ($argv[3] ?? '');
            $password = (string) ($argv[4] ?? '');
            $result = PersonelAccountOnboardingService::completeActivation($pdo, $token, $password, $password);
            echo (($result['activated'] ?? false) === true ? 'OK' : 'FAIL') . PHP_EOL;
            exit(0);
        }
        if ($action === 'onboard') {
            $personelId = (int) ($argv[3] ?? 0);
            $result = PersonelAccountOnboardingService::onboardAndIssue($pdo, $personelId, $actor);
            echo 'OK:' . (string) ($result['user']['username'] ?? '') . PHP_EOL;
            exit(0);
        }
        if ($action === 'reject-generic') {
            PersonelAccountOnboardingService::rejectGenericPersonelBoundCreate([
                'rol' => 'PERSONEL',
                'personel_id' => 10,
                'username' => 'caller-override',
                'password' => 'CallerPass99',
            ]);
            echo 'UNEXPECTED_OK' . PHP_EOL;
            exit(0);
        }
        if ($action === 'lock-hold-user') {
            $userId = (int) ($argv[3] ?? 0);
            $holdMs = (int) ($argv[4] ?? 400);
            $signal = (string) ($argv[5] ?? '');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $userId]);
            $stmt->fetch(PDO::FETCH_ASSOC);
            if ($signal !== '') {
                file_put_contents($signal, 'ready');
            }
            usleep(max(50, $holdMs) * 1000);
            $pdo->commit();
            echo 'HELD' . PHP_EOL;
            exit(0);
        }
        fwrite(STDERR, "Unknown child action: {$action}\n");
        exit(2);
    } catch (Throwable $e) {
        // JsonResponse exits; anything else is unexpected.
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }
}

function psoRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Disposable MariaDB credentials are required (MEDISA_TEST_MYSQL_*).');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** @return array{process: resource, pipes: array<int, resource>} */
function psoSpawnChild(array $args, string $dsn): array
{
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows' && !extension_loaded('pdo_mysql')) {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=php_pdo_mysql.dll';
    }
    $command = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--child'], $args);
    $pipes = [];
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['MEDISA_TEST_MYSQL_DSN'] = $dsn;
    $env['MEDISA_TEST_MYSQL_USER'] = (string) (getenv('MEDISA_TEST_MYSQL_USER') ?: '');
    $env['MEDISA_TEST_MYSQL_PASSWORD'] = (string) (getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '');
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Child process could not start.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

/** @param array{process: resource, pipes: array<int, resource>} $child */
function psoFinishChild(array $child): string
{
    $stdout = trim((string) stream_get_contents($child['pipes'][1]));
    $stderr = trim((string) stream_get_contents($child['pipes'][2]));
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $status = proc_close($child['process']);

    $lines = preg_split("/\r\n|\n|\r/", $stdout) ?: [];
    $meaningful = array_values(array_filter($lines, static function (string $line): bool {
        $line = trim($line);
        return $line !== '' && stripos($line, 'Warning:') !== 0;
    }));
    $last = $meaningful === [] ? '' : (string) end($meaningful);

    // JsonResponse::error exits(0) after printing JSON.
    if ($last === '' && $stderr !== '') {
        throw new RuntimeException('Child failed (status=' . $status . '): ' . $stderr);
    }

    return $last;
}

function psoExtractErrorCode(string $jsonOrToken): ?string
{
    if ($jsonOrToken === '' || $jsonOrToken[0] !== '{') {
        return null;
    }
    $decoded = json_decode($jsonOrToken, true);
    if (!is_array($decoded)) {
        return null;
    }
    $code = $decoded['errors'][0]['code'] ?? null;

    return is_string($code) ? $code : null;
}

/** @return list<string> */
function psoSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function psoApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (psoSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function psoPdoForDb(string $database): PDO
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
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]
    );
}

/**
 * Capture JsonResponse exit by running in subprocess is heavy; instead call service
 * methods that return, and for error paths use a thin wrapper that catches exit via
 * output buffering is not viable. Prefer direct DB assertions + service happy paths,
 * and for expected errors use isolated try with a custom error handler that throws.
 */

function psoExpectJsonError(callable $fn, string $expectedCode): void
{
    $prev = set_error_handler(static function () {
        return false;
    });
    try {
        ob_start();
        try {
            $fn();
            $out = ob_get_clean();
            throw new RuntimeException('Expected JsonResponse exit, got return. out=' . substr((string) $out, 0, 200));
        } catch (Throwable $e) {
            $out = ob_get_clean();
            // JsonResponse exits — in CLI with exit, we never get here unless someone throws.
            throw $e;
        }
    } finally {
        if ($prev !== null) {
            set_error_handler($prev);
        } else {
            restore_error_handler();
        }
    }
}

// JsonResponse::error calls exit — wrap via register_shutdown is messy.
// Use a patched approach: invoke token/hash helpers + DB-level checks for deny paths,
// and for API-level error codes assert via direct service preconditions replicated in SQL.

$root = psoRootPdo();
$database = 'medisa_pso_075_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = psoPdoForDb($database);

    psoApply($pdo, '001_initial_schema.sql');
    psoApply($pdo, '051_users_varsayilan_sube_id.sql');
    psoApply($pdo, '054_canonical_role_consolidation.sql');
    psoApply($pdo, '056_users_personel_binding.sql');
    // calisan_kapsami
    psoApply($pdo, '066_personel_calisan_kapsami.sql');
    psoApply($pdo, '069_personel_credential_onboarding.sql');

    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");

    // Legacy active user representing production grandfathered account.
    $legacyHash = password_hash('LegacyPass-24chars!!!!!', PASSWORD_BCRYPT);
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, must_change_password) VALUES
         (1, 'admin', " . $pdo->quote($legacyHash) . ", 'Admin', 'GENEL_YONETICI', 'AKTIF', 0),
         (2, 'u2', " . $pdo->quote($legacyHash) . ", 'Legacy Bound', 'PERSONEL', 'AKTIF', 0)"
    );

    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon,
            acil_durum_kisi, acil_durum_telefon, sicil_no, ise_giris_tarihi,
            sube_id, departman_id, gorev_id, aktif_durum, calisan_kapsami
         ) VALUES
         (1, '11111111111', 'Legacy', 'One', '1990-01-01', '5550000001', 'A', '5550000011', 'LEG-001', '2020-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (10, '10101010101', 'İlker', 'Akel', '1991-01-01', '5550000010', 'B', '5550000010', 'SIC-100', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (11, '11111111112', 'Yeni', 'Dis', '1992-01-01', '5550000011', 'C', '5550000011', 'SIC-DIS', '2021-01-01', 1, 1, 1, 'AKTIF', 'DIS_KAYNAK'),
         (12, '12121212121', 'Pasif', 'P', '1993-01-01', '5550000012', 'D', '5550000012', 'SIC-PAS', '2021-01-01', 1, 1, 1, 'PASIF', 'IC_PERSONEL'),
         (13, '13131313131', 'NoSicil', 'X', '1994-01-01', '5550000013', 'E', '5550000013', '   ', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (14, '14141414141', 'Collide', 'Y', '1995-01-01', '5550000014', 'F', '5550000014', 'SIC-COL', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (15, '15151515151', 'Mgr', 'Bound', '1996-01-01', '5550000015', 'G', '5550000015', 'MGR-001', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (16, '16161616161', 'Özkan', 'Erçin', '1997-01-01', '5550000016', 'H', '5550000016', 'SIC-OZ', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (17, '17171717171', 'Kürşat', 'Kederoğlu', '1998-01-01', '5550000017', 'I', '5550000017', 'SIC-KU', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (18, '18181818181', 'Mehmet Ali', 'Yılmaz', '1999-01-01', '5550000018', 'J', '5550000018', 'SIC-MA', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (19, '19191919191', 'Serhan', 'Köse', '1990-06-01', '5550000019', 'K', '5550000019', 'SIC-SK', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (219, '21921921921', 'DOĞU', 'BERKAN ATMACA', '1992-02-02', '5550000219', 'L', '5550000219', 'SIC-219', '2021-02-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL')"
    );

    // Bind legacy u2 to personel 1 (grandfathered).
    $pdo->exec('UPDATE users SET personel_id = 1 WHERE id = 2');
    // Username collision fixture owns collideY (manual account) — pre-075 columns only.
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, must_change_password)
         VALUES ('collideY', " . $pdo->quote($legacyHash) . ", 'Taken', 'GENEL_YONETICI', 'AKTIF', 0)"
    );
    // Management-bound account for personel 15.
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, must_change_password, personel_id)
         VALUES ('birim-mgr', " . $pdo->quote($legacyHash) . ", 'Birim Yonetici', 'BIRIM_AMIRI', 'AKTIF', 0, 15)"
    );

    // --- Migration 075 apply + idempotent + legacy safety ---
    $beforeUser2 = $pdo->query('SELECT username, password_hash, rol, personel_id, must_change_password, durum FROM users WHERE id = 2')->fetch(PDO::FETCH_ASSOC);
    psoApply($pdo, '075_personel_account_activation.sql');
    psoAssert(true, '075 ilk apply');
    psoApply($pdo, '075_personel_account_activation.sql');
    psoAssert(true, '075 ikinci apply idempotent');

    $afterUser2 = $pdo->query(
        'SELECT username, password_hash, rol, personel_id, must_change_password, durum, activation_required
         FROM users WHERE id = 2'
    )->fetch(PDO::FETCH_ASSOC);
    psoAssert($afterUser2['username'] === $beforeUser2['username'], 'legacy username unchanged');
    psoAssert($afterUser2['password_hash'] === $beforeUser2['password_hash'], 'legacy password_hash unchanged');
    psoAssert($afterUser2['rol'] === $beforeUser2['rol'], 'legacy rol unchanged');
    psoAssert((string) $afterUser2['personel_id'] === (string) $beforeUser2['personel_id'], 'legacy personel_id unchanged');
    psoAssert((int) $afterUser2['must_change_password'] === (int) $beforeUser2['must_change_password'], 'legacy must_change_password unchanged');
    psoAssert((int) $afterUser2['activation_required'] === 0, 'legacy activation_required=0');

    $invCount = (int) $pdo->query('SELECT COUNT(*) FROM personel_account_activation_invitations')->fetchColumn();
    psoAssert($invCount === 0, 'no invitations auto-created for existing users');

    // Config for URL + TTL
    $GLOBALS['config']['app_public_url'] = 'https://app.example.test/personelmedisa';
    $GLOBALS['config']['personel_activation_ttl_minutes'] = 1440;

    $actor = ['id' => 1, 'rol' => 'GENEL_YONETICI'];

    psoAssert(
        PersonelAccountOnboardingService::buildPersonelUsernameFromNames('İlker', 'AKEL') === 'ilkerA',
        'builder İlker AKEL → ilkerA'
    );
    psoAssert(
        PersonelAccountOnboardingService::buildPersonelUsernameFromNames('Özkan', 'ERÇİN') === 'ozkanE',
        'builder Özkan ERÇİN → ozkanE'
    );
    psoAssert(
        PersonelAccountOnboardingService::buildPersonelUsernameFromNames('Kürşat', 'KEDEROĞLU') === 'kursatK',
        'builder Kürşat KEDEROĞLU → kursatK'
    );
    psoAssert(
        PersonelAccountOnboardingService::buildPersonelUsernameFromNames('Mehmet Ali', 'YILMAZ') === 'mehmetY',
        'builder Mehmet Ali YILMAZ → mehmetY'
    );

    // 1–3: create IC_PERSONEL account -> canonical first-login model (aktivasyon linki YOK)
    $result = PersonelAccountOnboardingService::onboardAndIssue($pdo, 10, $actor);
    psoAssert(($result['user']['username'] ?? '') === 'ilkerA', 'username exactly ilkerA from names');
    psoAssert(($result['user']['rol'] ?? '') === 'PERSONEL', 'default role PERSONEL');
    psoAssert(($result['user']['activation_required'] ?? true) === false, 'new account activation_required=false');
    psoAssert(($result['user']['must_change_password'] ?? false) === true, 'new account must_change_password=true');
    psoAssert(
        ($result['credential_model'] ?? '') === PersonelAccountOnboardingService::CREDENTIAL_MODEL_FIRST_LOGIN,
        'credential_model=FIRST_LOGIN_TEMPLATE'
    );
    psoAssert(!isset($result['activation']), 'no activation payload on new account create');
    psoAssert(!isset($result['user']['username_source']), 'username_source not returned');
    psoAssert(!isset($result['password']) && !isset($result['user']['password']), 'plaintext credential never returned');
    psoAssert(
        strpos((string) json_encode($result), 'Akel123') === false,
        'template plaintext never returned in response'
    );

    $userRow = $pdo->query('SELECT * FROM users WHERE username = \'ilkerA\'')->fetch(PDO::FETCH_ASSOC);
    psoAssert(is_array($userRow), 'account created');
    psoAssert((int) $userRow['personel_id'] === 10, 'binding through personel_id');
    psoAssert((int) $userRow['activation_required'] === 0, 'DB activation_required=0');
    psoAssert((int) $userRow['must_change_password'] === 1, 'DB must_change_password=1');
    psoAssert(
        PasswordHasher::verify('Akel123', (string) $userRow['password_hash']),
        'template baslangic sifresi Akel123 hash ile dogrulandi'
    );
    psoAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_account_activation_invitations')->fetchColumn() === 0,
        'create pathi aktivasyon daveti uretmedi'
    );

    $bindAudit = (int) $pdo->query(
        'SELECT COUNT(*) FROM user_personel_binding_audit WHERE user_id = ' . (int) $userRow['id'] . " AND action = 'SET'"
    )->fetchColumn();
    psoAssert($bindAudit === 1, 'binding audit created');

    $onboardAudit = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = 'PERSONEL_ACCOUNT_CREATED' AND user_id = " . (int) $userRow['id']
    )->fetchColumn();
    psoAssert($onboardAudit === 1, 'PERSONEL_ACCOUNT_CREATED audit');

    // Legacy aktivasyon fixture'i: create yolu davet uretmedigi icin davet yalniz legacy reissue owner'indan alinir.
    $legacyFixture = psoLegacyActivationFixture($pdo, (int) $userRow['id'], $actor);
    $inv = $legacyFixture['invitation'];
    psoAssert(
        isset($legacyFixture['issued']['activation']['activation_url']),
        'legacy reissue activation_url returns'
    );
    psoAssert(strlen((string) $inv['token_hash']) === 64, 'token_hash sha256 hex length');
    $rawToken = psoTokenFromUrl((string) $legacyFixture['issued']['activation']['activation_url']);
    psoAssert(strlen($rawToken) >= 64, 'token >=256-bit entropy (64 hex chars)');
    psoAssert(PersonelAccountOnboardingService::hashToken($rawToken) === $inv['token_hash'], 'DB stores hash only matching raw');
    $rawInDb = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_account_activation_invitations WHERE token_hash = " . $pdo->quote($rawToken)
    )->fetchColumn();
    psoAssert($rawInDb === 0, 'raw token not persisted as hash value');

    $auditBlob = (string) $pdo->query(
        'SELECT GROUP_CONCAT(COALESCE(detail_json,\'\')) FROM personel_account_onboarding_audit'
    )->fetchColumn();
    psoAssert(strpos($auditBlob, $rawToken) === false, 'token not present in audit');

    // Idempotent / already bound: exactly one user for personel 10
    $userCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id = 10')->fetchColumn();
    psoAssert($userCountBefore === 1, 'exactly one bound user before idempotent check');
    $dbDsn = (string) preg_replace('/dbname=[^;]+/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN'));
    $idempotentChild = psoFinishChild(psoSpawnChild(['onboard', '10'], $dbDsn));
    psoAssert(
        psoExtractErrorCode($idempotentChild) === PersonelAccountOnboardingService::ERR_ALREADY_PROVISIONED,
        'same personnel rerun fail-closed ALREADY_PROVISIONED'
    );
    psoAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id = 10')->fetchColumn() === 1,
        'idempotent onboard creates no duplicate user'
    );

    // Fail-closed: empty name / PASIF / username collision / management-bound
    // Empty sicil still allowed when names exist.
    $emptySicilOk = PersonelAccountOnboardingService::onboardAndIssue($pdo, 13, $actor);
    psoAssert(($emptySicilOk['user']['username'] ?? '') === 'nosicilX', 'empty sicil still onboards from names');
    psoAssert(
        (string) $pdo->query('SELECT sicil_no FROM personeller WHERE id = 13')->fetchColumn() === '   ',
        'empty sicil value preserved without becoming username'
    );

    $pasif = psoFinishChild(psoSpawnChild(['onboard', '12'], $dbDsn));
    psoAssert(
        psoExtractErrorCode($pasif) === PersonelAccountOnboardingService::ERR_PERSONEL_INACTIVE,
        'PASIF personnel denied'
    );
    $collide = psoFinishChild(psoSpawnChild(['onboard', '14'], $dbDsn));
    psoAssert(
        psoExtractErrorCode($collide) === PersonelAccountOnboardingService::ERR_USERNAME_COLLISION,
        'different-user username collision fail closed without auto suffix'
    );
    $overrideOk = PersonelAccountOnboardingService::onboardAndIssue($pdo, 14, $actor, 'collideAlt');
    psoAssert(($overrideOk['user']['username'] ?? '') === 'collideAlt', 'collision override username accepted');
    $mgrBound = psoFinishChild(psoSpawnChild(['onboard', '15'], $dbDsn));
    psoAssert(
        psoExtractErrorCode($mgrBound) === PersonelAccountOnboardingService::ERR_ALREADY_PROVISIONED,
        'management-bound user blocks second PERSONEL account'
    );
    psoAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id = 15')->fetchColumn() === 1,
        'management binding remains single account'
    );

    // Generic create bypass owner
    $generic = psoFinishChild(psoSpawnChild(['reject-generic'], $dbDsn));
    psoAssert(
        psoExtractErrorCode($generic) === PersonelAccountOnboardingService::ERR_USE_SECURE,
        'generic PERSONEL+personel_id create blocked PERSONEL_USE_SECURE_ONBOARDING'
    );
    // Legitimate non-personnel create path remains owned by YonetimController (source-locked).

    // DIS_KAYNAK technical account + capability still disabled
    $disResult = PersonelAccountOnboardingService::onboardAndIssue($pdo, 11, $actor);
    psoAssert(($disResult['user']['username'] ?? '') === 'yeniD', 'DIS_KAYNAK username from names');
    $caps = PersonelMobileCapabilityService::resolve($pdo, 11);
    psoAssert($caps['qr_scan'] === true, 'DIS_KAYNAK qr_scan enabled');
    psoAssert($caps['attendance_correct'] === true, 'DIS_KAYNAK attendance_correct enabled');
    psoAssert($caps['puantaj_write'] === false, 'DIS_KAYNAK puantaj_write disabled');
    psoAssert($caps['izin_write'] === false, 'DIS_KAYNAK izin_write disabled');
    psoAssert(
        $caps['coming_soon_message'] === null,
        'DIS_KAYNAK no blanket coming-soon'
    );

    // Failed activation (weak password) leaves hash unchanged
    $hashBeforeFail = (string) $pdo->query(
        'SELECT password_hash FROM users WHERE id = ' . (int) $userRow['id']
    )->fetchColumn();
    $weak = psoFinishChild(psoSpawnChild(['redeem', $rawToken, 'short'], $dbDsn));
    psoAssert(psoExtractErrorCode($weak) !== null, 'weak password rejected before redeem');
    $hashAfterFail = (string) $pdo->query(
        'SELECT password_hash FROM users WHERE id = ' . (int) $userRow['id']
    )->fetchColumn();
    psoAssert($hashAfterFail === $hashBeforeFail, 'failed activation leaves password unchanged');

    // Token redeem success
    $complete = PersonelAccountOnboardingService::completeActivation(
        $pdo,
        $rawToken,
        'NewSecurePass1',
        'NewSecurePass1'
    );
    psoAssert(($complete['activated'] ?? false) === true, 'valid token accepted');

    $afterActivate = $pdo->query('SELECT * FROM users WHERE id = ' . (int) $userRow['id'])->fetch(PDO::FETCH_ASSOC);
    psoAssert((int) $afterActivate['activation_required'] === 0, 'activation_required cleared');
    psoAssert((int) $afterActivate['must_change_password'] === 0, 'must_change_password cleared');
    psoAssert(!empty($afterActivate['activated_at_utc']), 'activated_at_utc set');
    psoAssert(PasswordHasher::verify('NewSecurePass1', (string) $afterActivate['password_hash']), 'password hash changed to personnel password');
    psoAssert(!PasswordHasher::verify('LegacyPass-24chars!!!!!', (string) $afterActivate['password_hash']), 'old hash not retained');

    $consumed = $pdo->query(
        'SELECT consumed_at_utc FROM personel_account_activation_invitations WHERE id = ' . (int) $inv['id']
    )->fetch(PDO::FETCH_ASSOC);
    psoAssert(!empty($consumed['consumed_at_utc']), 'token consumed');

    $completeAudit = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = 'ACTIVATION_COMPLETED' AND user_id = " . (int) $userRow['id']
    )->fetchColumn();
    psoAssert($completeAudit === 1, 'token consumption audit created');

    $status2 = PersonelAccountOnboardingService::activationStatus($pdo, $rawToken);
    psoAssert(($status2['valid'] ?? true) === false, 'consumed token status invalid');

    // Reissue flow on legacy activation-pending user (DIS_KAYNAK personeli)
    $disUserId = (int) $disResult['user']['id'];
    // Create yolu davet uretmez: legacy fixture ile activation_required=1 + davet yaratilir.
    $disFixture = psoLegacyActivationFixture($pdo, $disUserId, $actor);
    $disUrl1 = (string) ($disFixture['issued']['activation']['activation_url'] ?? '');
    $disToken1 = psoTokenFromUrl($disUrl1);

    $reissue = PersonelAccountOnboardingService::reissueActivation($pdo, $disUserId, $actor);
    $disUrl2 = $reissue['activation']['activation_url'];
    parse_str(parse_url($disUrl2, PHP_URL_FRAGMENT) ?: '', $disFrag2);
    $disToken2 = (string) ($disFrag2['token'] ?? '');
    psoAssert($disToken1 !== $disToken2, 'reissue issues new token');

    $liveCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM personel_account_activation_invitations
         WHERE user_id = ' . $disUserId . ' AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL'
    )->fetchColumn();
    psoAssert($liveCount === 1, 'reissue leaves exactly one valid token');

    $oldStatus = PersonelAccountOnboardingService::activationStatus($pdo, $disToken1);
    psoAssert(($oldStatus['valid'] ?? true) === false, 'old token denied after reissue');
    $newStatus = PersonelAccountOnboardingService::activationStatus($pdo, $disToken2);
    psoAssert(($newStatus['valid'] ?? false) === true, 'new token valid after reissue');

    // Expired token
    $pdo->exec(
        'UPDATE personel_account_activation_invitations
         SET expires_at_utc = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
         WHERE user_id = ' . $disUserId . ' AND revoked_at_utc IS NULL AND consumed_at_utc IS NULL'
    );
    $expiredStatus = PersonelAccountOnboardingService::activationStatus($pdo, $disToken2);
    psoAssert(($expiredStatus['reason'] ?? '') === 'expired' || ($expiredStatus['valid'] ?? true) === false, 'expired token denied');

    // Fresh token for real concurrent redeem
    $reissue2 = PersonelAccountOnboardingService::reissueActivation($pdo, $disUserId, $actor);
    parse_str(parse_url($reissue2['activation']['activation_url'], PHP_URL_FRAGMENT) ?: '', $disFrag3);
    $disToken3 = (string) ($disFrag3['token'] ?? '');

    // E: concurrent issue/reissue — at most one live invitation; FOR UPDATE serializes on user row
    $signal = tempnam(sys_get_temp_dir(), 'pso-lock-');
    if ($signal === false) {
        throw new RuntimeException('signal file create failed');
    }
    @unlink($signal);
    $hold = psoSpawnChild(['lock-hold-user', (string) $disUserId, '600', $signal], $dbDsn);
    $waitStart = microtime(true);
    while (!is_file($signal) && (microtime(true) - $waitStart) < 5.0) {
        usleep(20_000);
    }
    psoAssert(is_file($signal), 'FOR UPDATE hold child acquired user lock');
    $blockedReissue = psoSpawnChild(['reissue', (string) $disUserId], $dbDsn);
    $raceStart = microtime(true);
    $blockedOut = psoFinishChild($blockedReissue);
    $blockedElapsedMs = (microtime(true) - $raceStart) * 1000;
    psoFinishChild($hold);
    @unlink($signal);
    psoAssert(strpos($blockedOut, 'OK:') === 0, 'reissue succeeds after FOR UPDATE holder releases');
    psoAssert($blockedElapsedMs >= 200, 'competing reissue waited on locked user row');
    $liveAfterHold = (int) $pdo->query(
        'SELECT COUNT(*) FROM personel_account_activation_invitations
         WHERE user_id = ' . $disUserId . ' AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL'
    )->fetchColumn();
    psoAssert($liveAfterHold === 1, 'lock-hold + reissue leaves one live invitation');

    $raceA = psoSpawnChild(['reissue', (string) $disUserId], $dbDsn);
    $raceB = psoSpawnChild(['reissue', (string) $disUserId], $dbDsn);
    $raceOut = [psoFinishChild($raceA), psoFinishChild($raceB)];
    $raceOk = 0;
    foreach ($raceOut as $line) {
        if (strpos($line, 'OK:') === 0) {
            $raceOk++;
        }
    }
    psoAssert($raceOk === 2, 'two concurrent reissues both complete under serialization');
    $liveAfterRace = (int) $pdo->query(
        'SELECT COUNT(*) FROM personel_account_activation_invitations
         WHERE user_id = ' . $disUserId . ' AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL'
    )->fetchColumn();
    psoAssert($liveAfterRace === 1, 'concurrent reissue leaves at most one valid invitation');

    // Refresh live token after race for redeem concurrency
    $liveInv = $pdo->query(
        'SELECT token_hash FROM personel_account_activation_invitations
         WHERE user_id = ' . $disUserId . ' AND consumed_at_utc IS NULL AND revoked_at_utc IS NULL
         ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    // Reissue once more so we own a known raw token for dual redeem
    $reissue3 = PersonelAccountOnboardingService::reissueActivation($pdo, $disUserId, $actor);
    parse_str(parse_url($reissue3['activation']['activation_url'], PHP_URL_FRAGMENT) ?: '', $disFrag4);
    $disToken4 = (string) ($disFrag4['token'] ?? '');
    $hashBeforeRedeem = (string) $pdo->query(
        'SELECT password_hash FROM users WHERE id = ' . $disUserId
    )->fetchColumn();
    $auditBefore = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = 'ACTIVATION_COMPLETED' AND user_id = " . $disUserId
    )->fetchColumn();

    // F: concurrent redeem of same valid token
    $redeemA = psoSpawnChild(['redeem', $disToken4, 'DisPassWord9'], $dbDsn);
    $redeemB = psoSpawnChild(['redeem', $disToken4, 'OtherPassWord8'], $dbDsn);
    $redeemResults = [psoFinishChild($redeemA), psoFinishChild($redeemB)];
    $redeemOk = 0;
    $redeemFail = 0;
    foreach ($redeemResults as $line) {
        if ($line === 'OK') {
            $redeemOk++;
        } elseif (psoExtractErrorCode($line) === PersonelAccountOnboardingService::ERR_ACTIVATION_INVALID
            || strpos($line, 'Aktivasyon') !== false
            || $line !== ''
        ) {
            if ($line !== 'OK') {
                $redeemFail++;
            }
        }
    }
    psoAssert($redeemOk === 1, 'concurrent redeem exactly one success');
    psoAssert($redeemFail === 1, 'concurrent redeem exactly one failure');
    $afterDual = $pdo->query('SELECT password_hash, activation_required FROM users WHERE id = ' . $disUserId)->fetch(PDO::FETCH_ASSOC);
    psoAssert((int) $afterDual['activation_required'] === 0, 'concurrent redeem clears activation_required once');
    $pwMatchesOne =
        PasswordHasher::verify('DisPassWord9', (string) $afterDual['password_hash'])
        || PasswordHasher::verify('OtherPassWord8', (string) $afterDual['password_hash']);
    $pwMatchesBoth =
        PasswordHasher::verify('DisPassWord9', (string) $afterDual['password_hash'])
        && PasswordHasher::verify('OtherPassWord8', (string) $afterDual['password_hash']);
    psoAssert($pwMatchesOne && !$pwMatchesBoth, 'exactly one password change applied');
    psoAssert((string) $afterDual['password_hash'] !== $hashBeforeRedeem, 'password hash changed once');
    $auditAfter = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = 'ACTIVATION_COMPLETED' AND user_id = " . $disUserId
    )->fetchColumn();
    psoAssert($auditAfter === $auditBefore + 1, 'no double activation audit corruption');
    unset($liveInv, $disToken3);

    // Post-activation DIS_KAYNAK capability still denied
    $capsAfter = PersonelMobileCapabilityService::resolve($pdo, 11);
    psoAssert($capsAfter['qr_scan'] === true, 'post-activation DIS_KAYNAK qr_scan enabled');
    psoAssert(
        $capsAfter['coming_soon_message'] === null,
        'post-activation DIS_KAYNAK no blanket coming-soon'
    );

    // Sicil change must NOT rename username
    $usernameBeforeSicil = (string) $pdo->query(
        'SELECT username FROM users WHERE id = ' . (int) $userRow['id']
    )->fetchColumn();
    $pdo->exec("UPDATE personeller SET sicil_no = 'SIC-100B' WHERE id = 10");
    $synced = $pdo->query('SELECT username, password_hash, rol, personel_id FROM users WHERE id = ' . (int) $userRow['id'])->fetch(PDO::FETCH_ASSOC);
    psoAssert($synced['username'] === $usernameBeforeSicil, 'sicil change does not rename username');
    psoAssert($synced['username'] === 'ilkerA', 'username remains ilkerA after sicil change');
    psoAssert($synced['password_hash'] === $afterActivate['password_hash'], 'password unchanged during sicil change');
    psoAssert($synced['rol'] === 'PERSONEL', 'role unchanged during sicil change');
    psoAssert((int) $synced['personel_id'] === 10, 'binding unchanged during sicil change');
    $sicilNow = (string) $pdo->query('SELECT sicil_no FROM personeller WHERE id = 10')->fetchColumn();
    psoAssert($sicilNow === 'SIC-100B', 'personel sicil updated independently');
    $syncAudit = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = 'USERNAME_SYNCED_FROM_SICIL'"
    )->fetchColumn();
    psoAssert($syncAudit === 0, 'no username sync-from-sicil audit events');

    // Additional name fixtures
    $oz = PersonelAccountOnboardingService::onboardAndIssue($pdo, 16, $actor);
    psoAssert(($oz['user']['username'] ?? '') === 'ozkanE', 'Özkan Erçin → ozkanE');
    $ku = PersonelAccountOnboardingService::onboardAndIssue($pdo, 17, $actor);
    psoAssert(($ku['user']['username'] ?? '') === 'kursatK', 'Kürşat Kederoğlu → kursatK');
    $ma = PersonelAccountOnboardingService::onboardAndIssue($pdo, 18, $actor);
    psoAssert(($ma['user']['username'] ?? '') === 'mehmetY', 'Mehmet Ali Yılmaz → mehmetY');

    // Existing collideY account username remains untouched by other onboarding
    $stillTaken = $pdo->query("SELECT username FROM users WHERE username = 'collideY'")->fetch(PDO::FETCH_ASSOC);
    psoAssert(is_array($stillTaken), 'existing colliding username account preserved');

    // ---------------------------------------------------------------------------
    // NEW ACCOUNT ACCEPTANCE: canonical create -> ilk giris -> zorunlu sifre degisimi
    // ---------------------------------------------------------------------------
    $GLOBALS['config']['db_host'] = '127.0.0.1';
    $GLOBALS['config']['db_name'] = $database;
    $GLOBALS['config']['db_user'] = (string) (getenv('MEDISA_TEST_MYSQL_USER') ?: 'root');
    // PDO dogrudan enjekte edilir; bu deger yalniz medisa_config_ready() icin doldurulur.
    $GLOBALS['config']['db_password'] = 'test';
    $GLOBALS['config']['jwt_secret'] = str_repeat('pso-first-login-', 4);
    $GLOBALS['config']['jwt_ttl_seconds'] = 3600;
    psoSetPdo($pdo);

    $serhanOnboard = PersonelAccountOnboardingService::onboardAndIssue($pdo, 19, $actor);
    $serhanUserId = (int) ($serhanOnboard['user']['id'] ?? 0);
    psoAssert(($serhanOnboard['user']['username'] ?? '') === 'serhanK', 'NEW: Serhan Kose -> serhanK');
    psoAssert(($serhanOnboard['user']['personel_id'] ?? 0) === 19, 'NEW: personel_id binding');
    psoAssert(($serhanOnboard['user']['activation_required'] ?? true) === false, 'NEW: activation_required=false');
    psoAssert(($serhanOnboard['user']['must_change_password'] ?? false) === true, 'NEW: must_change_password=true');
    psoAssert(!isset($serhanOnboard['activation']), 'NEW: activation URL returned=NO');

    $serhanRow = $pdo->query('SELECT * FROM users WHERE id = ' . $serhanUserId)->fetch(PDO::FETCH_ASSOC);
    psoAssert((int) $serhanRow['activation_required'] === 0, 'NEW: DB activation_required=0');
    psoAssert((int) $serhanRow['must_change_password'] === 1, 'NEW: DB must_change_password=1');
    psoAssert(
        PasswordHasher::verify('Kose123', (string) $serhanRow['password_hash']),
        'NEW: Kose123 hash ile dogrulandi'
    );
    $serhanInvitations = (int) $pdo->query(
        'SELECT COUNT(*) FROM personel_account_activation_invitations WHERE user_id = ' . $serhanUserId
    )->fetchColumn();
    psoAssert($serhanInvitations === 0, 'NEW: activation invitation created=0');

    // Ilk giris: username + template sifre -> SUCCESS + must_change_password
    psoSetAuthUser(null);
    $firstLogin = psoCapture(static function (): void {
        LoginController::login(psoRequest(['username' => 'serhanK', 'password' => 'Kose123']));
    });
    psoAssert(!empty($firstLogin['data']['token']), 'NEW: template credential ile login SUCCESS');
    psoAssert(($firstLogin['data']['must_change_password'] ?? null) === true, 'NEW: login must_change_password=true');
    psoAssert(($firstLogin['data']['user']['rol'] ?? null) === 'PERSONEL', 'NEW: login rol PERSONEL');

    // Zorunlu sifre degisimi (forced password change)
    psoSetAuthUser(['id' => $serhanUserId, 'rol' => 'PERSONEL', 'must_change_password' => true]);
    $forcedChange = psoCapture(static function (): void {
        ChangePasswordController::change(
            psoRequest(
                ['current_password' => 'Kose123', 'new_password' => 'YeniSifre-2026'],
                '/auth/change-password'
            )
        );
    });
    psoAssert(
        ($forcedChange['data']['must_change_password'] ?? null) === false,
        'NEW: change-password must_change_password=false'
    );
    $serhanAfterChange = $pdo->query('SELECT * FROM users WHERE id = ' . $serhanUserId)->fetch(PDO::FETCH_ASSOC);
    psoAssert((int) $serhanAfterChange['must_change_password'] === 0, 'NEW: DB must_change_password=0');
    psoAssert(
        PasswordHasher::verify('YeniSifre-2026', (string) $serhanAfterChange['password_hash']),
        'NEW: yeni kalici sifre hash ile dogrulandi'
    );

    // Degisim sonrasi template sifresi DENIED, yeni sifre SUCCESS
    psoSetAuthUser(null);
    $templateAfterChange = psoCapture(static function (): void {
        LoginController::login(psoRequest(['username' => 'serhanK', 'password' => 'Kose123']));
    });
    psoAssert(
        ($templateAfterChange['errors'][0]['code'] ?? null) === 'INVALID_CREDENTIALS',
        'NEW: POST_CHANGE template sifresi DENIED'
    );
    $newPasswordLogin = psoCapture(static function (): void {
        LoginController::login(psoRequest(['username' => 'serhanK', 'password' => 'YeniSifre-2026']));
    });
    psoAssert(!empty($newPasswordLogin['data']['token']), 'NEW: POST_CHANGE yeni sifre ile SUCCESS');
    psoAssert(
        ($newPasswordLogin['data']['must_change_password'] ?? null) === false,
        'NEW: POST_CHANGE must_change_password=false'
    );
    psoSetAuthUser(null);

    // Collision davranisi korunuyor: ayni isim ikinci kez hesap acamaz.
    $duplicateSerhan = psoFinishChild(psoSpawnChild(['onboard', '19'], $dbDsn));
    psoAssert(
        psoExtractErrorCode($duplicateSerhan) === PersonelAccountOnboardingService::ERR_ALREADY_PROVISIONED,
        'NEW: ikinci create fail-closed (ALREADY_PROVISIONED)'
    );

    // ---------------------------------------------------------------------------
    // PERSONEL 219: canonical onboarding + master-data correction (AYNI transaction)
    // ---------------------------------------------------------------------------
    $p219Before = $pdo->query('SELECT ad, soyad FROM personeller WHERE id = 219')->fetch(PDO::FETCH_ASSOC);
    psoAssert(
        $p219Before['ad'] === 'DOĞU' && $p219Before['soyad'] === 'BERKAN ATMACA',
        '219 locked preimage fixture (DOĞU / BERKAN ATMACA)'
    );

    // B) ad preimage mismatch -> fail-closed; hicbir mutation (user/binding/audit) yok
    $pdo->exec("UPDATE personeller SET ad = 'YANLIS' WHERE id = 219");
    $adMismatch = psoCapture(static function () use ($pdo, $actor): void {
        PersonelAccountOnboardingService::onboardAndIssue($pdo, 219, $actor);
    });
    psoAssert(
        ($adMismatch['errors'][0]['code'] ?? '') === PersonelAccountOnboardingService::ERR_NAME_CORRECTION_PREIMAGE,
        '219 ad preimage mismatch fail-closed'
    );
    $afterAdMismatch = $pdo->query('SELECT ad, soyad FROM personeller WHERE id = 219')->fetch(PDO::FETCH_ASSOC);
    psoAssert(
        $afterAdMismatch['ad'] === 'YANLIS' && $afterAdMismatch['soyad'] === 'BERKAN ATMACA',
        '219 ad mismatch leaves master data unchanged'
    );
    psoAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id = 219')->fetchColumn() === 0,
        '219 ad mismatch creates no user'
    );
    psoAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE personel_id = 219')->fetchColumn() === 0,
        '219 ad mismatch writes no onboarding audit'
    );
    psoAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM user_personel_binding_audit WHERE new_personel_id = 219')->fetchColumn() === 0,
        '219 ad mismatch writes no binding audit'
    );
    $pdo->exec("UPDATE personeller SET ad = 'DOĞU' WHERE id = 219");

    // C) soyad preimage mismatch -> ayni fail-closed davranis
    $pdo->exec("UPDATE personeller SET soyad = 'YANLIS SOYAD' WHERE id = 219");
    $soyadMismatch = psoCapture(static function () use ($pdo, $actor): void {
        PersonelAccountOnboardingService::onboardAndIssue($pdo, 219, $actor);
    });
    psoAssert(
        ($soyadMismatch['errors'][0]['code'] ?? '') === PersonelAccountOnboardingService::ERR_NAME_CORRECTION_PREIMAGE,
        '219 soyad preimage mismatch fail-closed'
    );
    $afterSoyadMismatch = $pdo->query('SELECT ad, soyad FROM personeller WHERE id = 219')->fetch(PDO::FETCH_ASSOC);
    psoAssert(
        $afterSoyadMismatch['ad'] === 'DOĞU' && $afterSoyadMismatch['soyad'] === 'YANLIS SOYAD',
        '219 soyad mismatch leaves master data unchanged'
    );
    $pdo->exec("UPDATE personeller SET soyad = 'BERKAN ATMACA' WHERE id = 219");

    // D) doguA collision -> correction dahil tüm transaction rollback
    $doguCollideHash = password_hash('CollidePass-24chars!!!!!', PASSWORD_BCRYPT);
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, must_change_password)
         VALUES ('doguA', " . $pdo->quote($doguCollideHash) . ", 'Collide Dogu', 'GENEL_YONETICI', 'AKTIF', 0)"
    );
    $doguCollision = psoCapture(static function () use ($pdo, $actor): void {
        PersonelAccountOnboardingService::onboardAndIssue($pdo, 219, $actor);
    });
    psoAssert(
        ($doguCollision['errors'][0]['code'] ?? '') === PersonelAccountOnboardingService::ERR_USERNAME_COLLISION,
        '219 doguA collision fail-closed'
    );
    $afterCollision = $pdo->query('SELECT ad, soyad FROM personeller WHERE id = 219')->fetch(PDO::FETCH_ASSOC);
    psoAssert(
        $afterCollision['ad'] === 'DOĞU' && $afterCollision['soyad'] === 'BERKAN ATMACA',
        '219 collision rolls back master-data correction'
    );
    psoAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id = 219')->fetchColumn() === 0,
        '219 collision creates no bound user'
    );
    $pdo->exec("DELETE FROM users WHERE username = 'doguA'");

    // A) happy path: master-data correction + user create + binding tek transaction
    $dogu = PersonelAccountOnboardingService::onboardAndIssue($pdo, 219, $actor);
    psoAssert(($dogu['user']['username'] ?? '') === 'doguA', '219 canonical username doguA');
    psoAssert(($dogu['user']['personel_id'] ?? 0) === 219, '219 binding personel_id');
    psoAssert(($dogu['user']['rol'] ?? '') === 'PERSONEL', '219 rol PERSONEL');
    psoAssert(($dogu['user']['activation_required'] ?? true) === false, '219 activation_required=false');
    psoAssert(($dogu['user']['must_change_password'] ?? false) === true, '219 must_change_password=true');
    psoAssert(!isset($dogu['activation']), '219 no activation payload');

    $doguRow = $pdo->query("SELECT * FROM users WHERE username = 'doguA'")->fetch(PDO::FETCH_ASSOC);
    psoAssert(is_array($doguRow), '219 account created');
    psoAssert((string) $doguRow['ad_soyad'] === 'Doğu Berkan Atmaca', '219 users.ad_soyad corrected canonical');
    psoAssert((int) $doguRow['activation_required'] === 0, '219 DB activation_required=0');
    psoAssert((int) $doguRow['must_change_password'] === 1, '219 DB must_change_password=1');
    psoAssert(
        PasswordHasher::verify('Atmaca123', (string) $doguRow['password_hash']),
        '219 initial password Atmaca123 hash ile dogrulandi'
    );
    $doguPersonel = $pdo->query('SELECT ad, soyad FROM personeller WHERE id = 219')->fetch(PDO::FETCH_ASSOC);
    psoAssert($doguPersonel['ad'] === 'Doğu Berkan', '219 master ad corrected');
    psoAssert($doguPersonel['soyad'] === 'Atmaca', '219 master soyad corrected');
    psoAssert(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM user_personel_binding_audit
             WHERE user_id = ' . (int) $doguRow['id'] . " AND action = 'SET' AND new_personel_id = 219"
        )->fetchColumn() === 1,
        '219 binding audit exact'
    );
    $doguAuditRow = $pdo->query(
        "SELECT detail_json FROM personel_account_onboarding_audit
          WHERE event_type = 'PERSONEL_ACCOUNT_CREATED' AND user_id = " . (int) $doguRow['id']
    )->fetch(PDO::FETCH_ASSOC);
    psoAssert(is_array($doguAuditRow), '219 account-created audit exists');
    $doguDetail = (string) ($doguAuditRow['detail_json'] ?? '');
    psoAssert(
        strpos($doguDetail, 'name_correction') !== false
            && strpos($doguDetail, 'DOĞU') !== false
            && strpos($doguDetail, 'Doğu Berkan') !== false,
        '219 audit records before/after name correction'
    );
    psoAssert(strpos($doguDetail, 'Atmaca123') === false, '219 audit has no plaintext password');
    psoAssert(strpos($doguDetail, 'password_hash') === false, '219 audit has no password_hash');
    psoAssert(strpos((string) json_encode($dogu), 'Atmaca123') === false, '219 response has no plaintext password');

    // E) existing binding -> ALREADY_PROVISIONED; correction commit EDILMEZ
    $pdo->exec("UPDATE personeller SET ad = 'DOĞU', soyad = 'BERKAN ATMACA' WHERE id = 219");
    $doguRebind = psoCapture(static function () use ($pdo, $actor): void {
        PersonelAccountOnboardingService::onboardAndIssue($pdo, 219, $actor);
    });
    psoAssert(
        ($doguRebind['errors'][0]['code'] ?? '') === PersonelAccountOnboardingService::ERR_ALREADY_PROVISIONED,
        '219 existing binding fail-closed ALREADY_PROVISIONED'
    );
    $afterRebind = $pdo->query('SELECT ad, soyad FROM personeller WHERE id = 219')->fetch(PDO::FETCH_ASSOC);
    psoAssert(
        $afterRebind['ad'] === 'DOĞU' && $afterRebind['soyad'] === 'BERKAN ATMACA',
        '219 existing binding does not commit master-data correction'
    );
    psoAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id = 219')->fetchColumn() === 1,
        '219 existing binding no duplicate user'
    );
    $pdo->exec("UPDATE personeller SET ad = 'Doğu Berkan', soyad = 'Atmaca' WHERE id = 219");

    // 5) correction'siz personel: master data degismez (mevcut davranis korunur)
    $p19After = $pdo->query('SELECT ad, soyad FROM personeller WHERE id = 19')->fetch(PDO::FETCH_ASSOC);
    psoAssert(
        $p19After['ad'] === 'Serhan' && $p19After['soyad'] === 'Köse',
        '19 no-correction master data unchanged'
    );

    // 7) ilkerA / personel 10 untouched
    $p10After = $pdo->query('SELECT ad, soyad FROM personeller WHERE id = 10')->fetch(PDO::FETCH_ASSOC);
    psoAssert($p10After['ad'] === 'İlker' && $p10After['soyad'] === 'Akel', 'ilkerA personel master data untouched');
    $ilkerUser = $pdo->query("SELECT username, personel_id, rol FROM users WHERE username = 'ilkerA'")->fetch(PDO::FETCH_ASSOC);
    psoAssert(
        is_array($ilkerUser) && (int) $ilkerUser['personel_id'] === 10 && $ilkerUser['rol'] === 'PERSONEL',
        'ilkerA user untouched'
    );

    $t1 = PersonelAccountOnboardingService::generateActivationToken();
    $t2 = PersonelAccountOnboardingService::generateActivationToken();
    psoAssert(strlen($t1) === 64 && strlen($t2) === 64 && $t1 !== $t2, 'token generator 256-bit unique');
    psoAssert(PersonelAccountOnboardingService::activationTtlMinutes() === 1440, 'ttl default 1440 via config owner');

    echo PHP_EOL . '[DONE] PersonelSecureOnboardingMysqlTestRunner' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
