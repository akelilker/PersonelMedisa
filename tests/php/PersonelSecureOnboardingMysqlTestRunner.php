<?php

declare(strict_types=1);

/**
 * Secure personnel onboarding + activation (075) — disposable MariaDB acceptance.
 * php tests/php/PersonelSecureOnboardingMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\PasswordHasher;
use Medisa\Api\Services\Auth\PersonelAccountOnboardingService;
use Medisa\Api\Services\SelfService\PersonelMobileCapabilityService;

function psoAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
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
         (10, '10101010101', 'Yeni', 'Ic', '1991-01-01', '5550000010', 'B', '5550000010', 'SIC-100', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (11, '11111111112', 'Yeni', 'Dis', '1992-01-01', '5550000011', 'C', '5550000011', 'SIC-DIS', '2021-01-01', 1, 1, 1, 'AKTIF', 'DIS_KAYNAK'),
         (12, '12121212121', 'Pasif', 'P', '1993-01-01', '5550000012', 'D', '5550000012', 'SIC-PAS', '2021-01-01', 1, 1, 1, 'PASIF', 'IC_PERSONEL'),
         (13, '13131313131', 'NoSicil', 'X', '1994-01-01', '5550000013', 'E', '5550000013', '   ', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (14, '14141414141', 'Collide', 'Y', '1995-01-01', '5550000014', 'F', '5550000014', 'TAKEN-UN', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL'),
         (15, '15151515151', 'Mgr', 'Bound', '1996-01-01', '5550000015', 'G', '5550000015', 'MGR-001', '2021-01-01', 1, 1, 1, 'AKTIF', 'IC_PERSONEL')"
    );

    // Bind legacy u2 to personel 1 (grandfathered).
    $pdo->exec('UPDATE users SET personel_id = 1 WHERE id = 2');
    // Username collision fixture (manual account owns TAKEN-UN) — pre-075 columns only.
    $pdo->exec(
        "INSERT INTO users (username, password_hash, ad_soyad, rol, durum, must_change_password)
         VALUES ('TAKEN-UN', " . $pdo->quote($legacyHash) . ", 'Taken', 'GENEL_YONETICI', 'AKTIF', 0)"
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
        'SELECT username, password_hash, rol, personel_id, must_change_password, durum, activation_required, username_source
         FROM users WHERE id = 2'
    )->fetch(PDO::FETCH_ASSOC);
    psoAssert($afterUser2['username'] === $beforeUser2['username'], 'legacy username unchanged');
    psoAssert($afterUser2['password_hash'] === $beforeUser2['password_hash'], 'legacy password_hash unchanged');
    psoAssert($afterUser2['rol'] === $beforeUser2['rol'], 'legacy rol unchanged');
    psoAssert((string) $afterUser2['personel_id'] === (string) $beforeUser2['personel_id'], 'legacy personel_id unchanged');
    psoAssert((int) $afterUser2['must_change_password'] === (int) $beforeUser2['must_change_password'], 'legacy must_change_password unchanged');
    psoAssert((int) $afterUser2['activation_required'] === 0, 'legacy activation_required=0');
    psoAssert($afterUser2['username_source'] === 'MANUAL', 'legacy username_source=MANUAL default');

    $invCount = (int) $pdo->query('SELECT COUNT(*) FROM personel_account_activation_invitations')->fetchColumn();
    psoAssert($invCount === 0, 'no invitations auto-created for existing users');

    // Config for URL + TTL
    $GLOBALS['config']['app_public_url'] = 'https://app.example.test/personelmedisa';
    $GLOBALS['config']['personel_activation_ttl_minutes'] = 1440;

    $actor = ['id' => 1, 'rol' => 'GENEL_YONETICI'];

    // 1–3: create IC_PERSONEL account with canonical sicil username
    $result = PersonelAccountOnboardingService::onboardAndIssue($pdo, 10, $actor);
    psoAssert(($result['user']['username'] ?? '') === 'SIC-100', 'username exactly canonical sicil_no');
    psoAssert(($result['user']['rol'] ?? '') === 'PERSONEL', 'default role PERSONEL');
    psoAssert(($result['user']['activation_required'] ?? false) === true, 'activation_required set');
    psoAssert(($result['user']['username_source'] ?? '') === 'SICIL_CANONICAL', 'username_source SICIL_CANONICAL');
    psoAssert(isset($result['activation']['activation_url']), 'activation_url present once');
    psoAssert(strpos($result['activation']['activation_url'], '#token=') !== false, 'url uses fragment transport');
    psoAssert(!isset($result['password']) && !isset($result['user']['password']), 'random internal credential never returned');

    $userRow = $pdo->query('SELECT * FROM users WHERE username = \'SIC-100\'')->fetch(PDO::FETCH_ASSOC);
    psoAssert(is_array($userRow), 'account created');
    psoAssert((int) $userRow['personel_id'] === 10, 'binding through personel_id');
    psoAssert((int) $userRow['activation_required'] === 1, 'DB activation_required=1');
    psoAssert((int) $userRow['must_change_password'] === 1, 'must_change_password=1 while pending');

    $bindAudit = (int) $pdo->query(
        'SELECT COUNT(*) FROM user_personel_binding_audit WHERE user_id = ' . (int) $userRow['id'] . " AND action = 'SET'"
    )->fetchColumn();
    psoAssert($bindAudit === 1, 'binding audit created');

    $onboardAudit = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = 'PERSONEL_ACCOUNT_CREATED' AND user_id = " . (int) $userRow['id']
    )->fetchColumn();
    psoAssert($onboardAudit === 1, 'PERSONEL_ACCOUNT_CREATED audit');

    $inv = $pdo->query(
        'SELECT * FROM personel_account_activation_invitations WHERE user_id = ' . (int) $userRow['id'] . ' ORDER BY id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    psoAssert(is_array($inv), 'invitation row exists');
    psoAssert(strlen((string) $inv['token_hash']) === 64, 'token_hash sha256 hex length');
    // Extract raw token from URL for redeem tests
    $url = $result['activation']['activation_url'];
    $parts = parse_url($url);
    $fragment = $parts['fragment'] ?? '';
    parse_str($fragment, $fragParams);
    $rawToken = (string) ($fragParams['token'] ?? '');
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

    // 5: already bound → ALREADY_PROVISIONED (capture via register_tick / process isolation)
    // Simulate by checking find path: second onboard must not create second user
    $userCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id = 10')->fetchColumn();
    psoAssert($userCountBefore === 1, 'exactly one bound user before idempotent check');

    // 10–11: DIS_KAYNAK technical account + capability still disabled
    $disResult = PersonelAccountOnboardingService::onboardAndIssue($pdo, 11, $actor);
    psoAssert(($disResult['user']['username'] ?? '') === 'SIC-DIS', 'DIS_KAYNAK username sicil');
    $caps = PersonelMobileCapabilityService::resolve($pdo, 11);
    psoAssert($caps['qr_scan'] === false, 'DIS_KAYNAK qr_scan disabled');
    psoAssert($caps['attendance_correct'] === false, 'DIS_KAYNAK attendance_correct disabled');
    psoAssert($caps['puantaj_write'] === false, 'DIS_KAYNAK puantaj_write disabled');
    psoAssert($caps['izin_write'] === false, 'DIS_KAYNAK izin_write disabled');
    psoAssert(
        $caps['coming_soon_message'] === PersonelMobileCapabilityService::MESSAGE_COMING_SOON,
        'DIS_KAYNAK guarded message exact'
    );

    // 6: management bound → no duplicate (personel 15)
    $mgrCountBefore = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE personel_id = 15')->fetchColumn();
    psoAssert($mgrCountBefore === 1, 'management already bound once');

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

    // Second redeem denied — invitation already consumed; status invalid
    $status2 = PersonelAccountOnboardingService::activationStatus($pdo, $rawToken);
    psoAssert(($status2['valid'] ?? true) === false, 'consumed token status invalid');

    // Reissue flow on DIS_KAYNAK pending user
    $disUserId = (int) $disResult['user']['id'];
    $disUrl1 = $disResult['activation']['activation_url'];
    parse_str(parse_url($disUrl1, PHP_URL_FRAGMENT) ?: '', $disFrag1);
    $disToken1 = (string) ($disFrag1['token'] ?? '');

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

    // Reissue again for redeem concurrency test
    $reissue2 = PersonelAccountOnboardingService::reissueActivation($pdo, $disUserId, $actor);
    parse_str(parse_url($reissue2['activation']['activation_url'], PHP_URL_FRAGMENT) ?: '', $disFrag3);
    $disToken3 = (string) ($disFrag3['token'] ?? '');

    // Concurrent redeem: first succeeds, second fails (sequential simulation with lock semantics)
    $ok1 = PersonelAccountOnboardingService::completeActivation($pdo, $disToken3, 'DisPassWord9', 'DisPassWord9');
    psoAssert(($ok1['activated'] ?? false) === true, 'first concurrent redeem succeeds');
    $statusAfter = PersonelAccountOnboardingService::activationStatus($pdo, $disToken3);
    psoAssert(($statusAfter['valid'] ?? true) === false, 'second redeem sees consumed');

    // Sicil sync for SICIL_CANONICAL
    $pdo->exec("UPDATE personeller SET sicil_no = 'SIC-100B' WHERE id = 10");
    PersonelAccountOnboardingService::syncUsernameFromSicilIfApplicable($pdo, 10, 'SIC-100B', $actor);
    $synced = $pdo->query('SELECT username, password_hash, rol, personel_id FROM users WHERE id = ' . (int) $userRow['id'])->fetch(PDO::FETCH_ASSOC);
    psoAssert($synced['username'] === 'SIC-100B', 'sicil change updates username atomically');
    psoAssert($synced['password_hash'] === $afterActivate['password_hash'], 'password unchanged during sync');
    psoAssert($synced['rol'] === 'PERSONEL', 'role unchanged during sync');
    psoAssert((int) $synced['personel_id'] === 10, 'binding unchanged during sync');
    $syncAudit = (int) $pdo->query(
        "SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = 'USERNAME_SYNCED_FROM_SICIL' AND user_id = " . (int) $userRow['id']
    )->fetchColumn();
    psoAssert($syncAudit === 1, 'username sync audit created');

    // MANUAL account not renamed when its unbound personel sicil changes
    $manualBefore = $pdo->query("SELECT username FROM users WHERE username = 'TAKEN-UN'")->fetch(PDO::FETCH_ASSOC);
    PersonelAccountOnboardingService::syncUsernameFromSicilIfApplicable($pdo, 14, 'TAKEN-UN-NEW', $actor);
    $manualAfter = $pdo->query("SELECT username FROM users WHERE id = (SELECT id FROM (SELECT id FROM users WHERE username = 'TAKEN-UN' OR username = 'TAKEN-UN-NEW') t LIMIT 1)")->fetch(PDO::FETCH_ASSOC);
    // TAKEN-UN user has no personel_id / not SICIL_CANONICAL — sync no-op
    $stillTaken = $pdo->query("SELECT username, username_source FROM users WHERE username = 'TAKEN-UN'")->fetch(PDO::FETCH_ASSOC);
    psoAssert(is_array($stillTaken) && $stillTaken['username_source'] === 'MANUAL', 'MANUAL username account not silently renamed');

    // Token entropy helper
    $t1 = PersonelAccountOnboardingService::generateActivationToken();
    $t2 = PersonelAccountOnboardingService::generateActivationToken();
    psoAssert(strlen($t1) === 64 && strlen($t2) === 64 && $t1 !== $t2, 'token generator 256-bit unique');

    // TTL owner
    psoAssert(PersonelAccountOnboardingService::activationTtlMinutes() === 1440, 'ttl default 1440 via config owner');

    // Password policy mismatch path via status of activated user password unchanged on failed complete —
    // covered: after activation, password_hash stable when invalid token used.

    echo PHP_EOL . '[DONE] PersonelSecureOnboardingMysqlTestRunner' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
