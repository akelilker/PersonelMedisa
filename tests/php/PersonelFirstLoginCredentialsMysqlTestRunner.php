<?php

declare(strict_types=1);

/**
 * PERSONEL canonical first-login credential gecisi — disposable MariaDB acceptance.
 * php tests/php/PersonelFirstLoginCredentialsMysqlTestRunner.php
 *
 * Kapsam: canonical username, template baslangic sifresi (Turkish fold), zorunlu
 * sifre degisimi akisi, eski template sifresinin reddi, protected hesap invariant'i,
 * collision fail-closed ve PASIF/destroyed anomaly fail-closed.
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

/** Reservation invariant: bu kullanici adi hicbir kosulda degismemeli. */
const PFLC_PROTECTED_USER_ID = 11;
const PFLC_PROTECTED_USERNAME = 'ilkerA';
const PFLC_SERHAN_USER_ID = 10;

function pflcAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function pflcRootPdo(): PDO
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

function pflcPdoForDb(string $database): PDO
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

function pflcSplitSql(string $sql): array
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

function pflcApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (pflcSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

/** Production ile ayni canonical zincir: 075 activation modeli + 089 legacy takeover. */
function pflcApplyCanonicalSchema(PDO $pdo): void
{
    pflcApply($pdo, '001_initial_schema.sql');
    pflcApply($pdo, '051_users_varsayilan_sube_id.sql');
    pflcApply($pdo, '054_canonical_role_consolidation.sql');
    pflcApply($pdo, '056_users_personel_binding.sql');
    pflcApply($pdo, '066_personel_calisan_kapsami.sql');
    pflcApply($pdo, '069_personel_credential_onboarding.sql');
    pflcApply($pdo, '075_personel_account_activation.sql');
    pflcApply($pdo, '089_personel_legacy_account_activation.sql');
}

function pflcSetPdo(PDO $pdo): void
{
    $ref = new ReflectionClass(Connection::class);
    $prop = $ref->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue(null, $pdo);
}

function pflcSetAuthUser($user): void
{
    $ref = new ReflectionClass(AuthMiddleware::class);
    $prop = $ref->getProperty('user');
    $prop->setAccessible(true);
    $prop->setValue(null, $user);
}

/** @param array<string, mixed> $body */
function pflcRequest(array $body, string $path = '/auth/login'): Request
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
function pflcCapture(callable $fn): array
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

function pflcErrorCode(array $envelope): ?string
{
    $code = $envelope['errors'][0]['code'] ?? null;

    return is_string($code) ? $code : null;
}

/** @return array<int, array<string, mixed>> */
function pflcCredentialRows(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT id, username, password_hash, rol, durum, personel_id, activation_required, must_change_password
           FROM users ORDER BY id ASC'
    );

    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function pflcHashOf(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);

    return (string) $stmt->fetchColumn();
}

function pflcAuditCount(PDO $pdo, string $eventType): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM personel_account_onboarding_audit WHERE event_type = :e');
    $stmt->execute(['e' => $eventType]);

    return (int) $stmt->fetchColumn();
}

function pflcSeedCatalog(PDO $pdo): void
{
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec("INSERT INTO departmanlar (id, ad, durum) VALUES (1, 'Dep', 'AKTIF')");
    $pdo->exec("INSERT INTO gorevler (id, ad, durum) VALUES (1, 'Gorev', 'AKTIF')");
}

function pflcInsertPersonel(PDO $pdo, int $id, string $ad, ?string $soyad, string $aktifDurum): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon,
            acil_durum_kisi, acil_durum_telefon, sicil_no, ise_giris_tarihi,
            sube_id, departman_id, gorev_id, aktif_durum, calisan_kapsami
         ) VALUES (:id, :tc, :ad, :soyad, :dogum, :tel, :ak, :aktel, :sicil, :ise, 1, 1, 1, :aktif, :kapsam)'
    );
    $stmt->execute([
        'id' => $id,
        'tc' => str_pad((string) $id, 11, '0', STR_PAD_LEFT),
        'ad' => $ad,
        'soyad' => $soyad,
        'dogum' => '1990-01-01',
        'tel' => '5550000' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
        'ak' => 'A',
        'aktel' => '5550010' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
        'sicil' => 'SIC-' . $id,
        'ise' => '2021-01-01',
        'aktif' => $aktifDurum,
        'kapsam' => 'IC_PERSONEL',
    ]);
}

function pflcInsertUser(
    PDO $pdo,
    int $id,
    string $username,
    string $hash,
    string $adSoyad,
    string $rol,
    string $durum,
    $personelId
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum, must_change_password, personel_id)
         VALUES (:id, :username, :hash, :adsoyad, :rol, :durum, 0, :pid)'
    );
    $stmt->execute([
        'id' => $id,
        'username' => $username,
        'hash' => $hash,
        'adsoyad' => $adSoyad,
        'rol' => $rol,
        'durum' => $durum,
        'pid' => $personelId,
    ]);
}

/** @param array<string, mixed> $row */
function pflcRowById(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, username, password_hash, rol, durum, personel_id, activation_required, must_change_password
           FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
}

/**
 * Business karari scenario (ayri disposable DB):
 *   - explicit override: 108 -> hakanAc, 109 -> hakanAt, 206 -> abdullah / Abdullah123
 *   - 5 kayit ad/soyad business correction (exact preimage guard'li)
 *   - ilkerA invariant, PASIF bagli hesap fail-closed
 *   - template credential ilk girisi -> zorunlu sifre degisimi -> eski template DENIED
 */
function pflcRunBusinessDecisionScenario(PDO $root): void
{
    $bizDb = 'medisa_pflc_biz_' . bin2hex(random_bytes(4));
    $root->exec('CREATE DATABASE `' . $bizDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    try {
        $pdo = pflcPdoForDb($bizDb);
        pflcApplyCanonicalSchema($pdo);
        pflcSeedCatalog($pdo);
        $legacyHash = PasswordHasher::hash('LegacyPass-24chars!!');

        // Kayitli (preimage) ad/soyad: name correction plani tam olarak bu degerlerle eslesmeli.
        pflcInsertPersonel($pdo, 108, 'Hakan', 'Açıkgöz', 'AKTIF');
        pflcInsertPersonel($pdo, 109, 'Hakan', 'Atay', 'AKTIF');
        pflcInsertPersonel($pdo, 200, 'RAED FAWAZ', null, 'AKTIF');
        pflcInsertPersonel($pdo, 201, 'SAIF TAREQ JASIM AL-GBURI', null, 'AKTIF');
        pflcInsertPersonel($pdo, 206, 'ABDULLAH', null, 'AKTIF');
        pflcInsertPersonel($pdo, 207, 'OKTAY ERSÖZ', null, 'AKTIF');
        pflcInsertPersonel($pdo, 209, 'MUQTADA MAZIN KHALEE', null, 'AKTIF');
        pflcInsertPersonel($pdo, 210, 'FAHRİ TAYLAN MERCAN', null, 'AKTIF');
        pflcInsertPersonel($pdo, 220, 'Pasif', 'Kisi', 'PASIF');
        pflcInsertPersonel($pdo, 230, 'İlker', 'Akel', 'AKTIF');

        // Actor FK'si icin auditte kullanilacak yonetim kullanicisi.
        pflcInsertUser($pdo, 1, 'admin', $legacyHash, 'Admin', 'GENEL_YONETICI', 'AKTIF', null);

        $bindings = [
            500 => [108, '329'],
            501 => [109, '006'],
            502 => [200, '176'],
            503 => [201, '197'],
            504 => [206, '283'],
            505 => [207, '285'],
            506 => [209, '375'],
            507 => [210, '407'],
            508 => [220, '4475'],
            509 => [230, 'ilkerA'],
        ];
        foreach ($bindings as $userId => $binding) {
            pflcInsertUser($pdo, (int) $userId, (string) $binding[1], $legacyHash, 'Biz Kisi', 'PERSONEL', 'AKTIF', (int) $binding[0]);
        }
        pflcApply($pdo, '089_personel_legacy_account_activation.sql');

        $expectedUsernames = [
            108 => 'hakanAc',
            109 => 'hakanAt',
            200 => 'raedF',
            201 => 'saifA',
            206 => 'abdullah',
            207 => 'oktayE',
            209 => 'muqtadaK',
            210 => 'fahriM',
        ];

        $protectedBefore = pflcRowById($pdo, 509);
        $dry = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials($pdo, 1, false);
        pflcAssert($dry['apply'] === false && $dry['blocked'] === false, 'BIZ: dry-run blocked=false');
        pflcAssert($dry['target_count'] === 8, 'BIZ: dry-run target_count = 8');
        pflcAssert(count($dry['collisions']) === 0, 'BIZ: USERNAME_COLLISION_COUNT = 0');
        pflcAssert(count($dry['excluded']['name_unresolved']) === 0, 'BIZ: NAME_UNRESOLVED_COUNT = 0');
        pflcAssert(count($dry['excluded']['protected_username']) === 1, 'BIZ: ilkerA protected_username olarak dislandi');
        pflcAssert(count($dry['excluded']['bound_personel_not_active']) === 1, 'BIZ: PASIF personel bagli hesap dislandi');

        $planByPersonel = [];
        foreach ($dry['plan'] as $planRow) {
            $planByPersonel[(int) $planRow['personel_id']] = $planRow;
        }
        foreach ($expectedUsernames as $personelId => $expectedUsername) {
            pflcAssert(
                isset($planByPersonel[$personelId]) && (string) $planByPersonel[$personelId]['new_username'] === $expectedUsername,
                'BIZ: plan personel ' . $personelId . ' -> ' . $expectedUsername
            );
        }
        pflcAssert(($planByPersonel[206]['business_override'] ?? null) === true, 'BIZ: 206 explicit business override');
        pflcAssert(($planByPersonel[108]['business_override'] ?? null) === true, 'BIZ: 108 explicit business override');
        $correctedIds = [];
        foreach ($dry['plan'] as $planRow) {
            if (!is_array($planRow['name_correction'])) {
                continue;
            }
            pflcAssert(
                ($planRow['name_correction']['preimage_match'] ?? false) === true,
                'BIZ: name correction preimage match personel ' . $planRow['personel_id']
            );
            $correctedIds[] = (int) $planRow['personel_id'];
        }
        sort($correctedIds);
        pflcAssert($correctedIds === [200, 201, 207, 209, 210], 'BIZ: name correction plani 5 kayit');
        pflcAssert((string) pflcRowById($pdo, 502)['username'] === '176', 'BIZ: dry-run hicbir satiri mutate etmedi');

        $apply = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials($pdo, 1, true);
        pflcAssert($apply['apply'] === true && $apply['applied_count'] === 8, 'BIZ: apply applied_count = 8');

        $userIdByPersonel = [
            108 => 500,
            109 => 501,
            200 => 502,
            201 => 503,
            206 => 504,
            207 => 505,
            209 => 506,
            210 => 507,
        ];
        $templatePasswords = [
            108 => 'Acikgoz123',
            109 => 'Atay123',
            200 => 'Fawaz123',
            201 => 'Algburi123',
            206 => 'Abdullah123',
            207 => 'Ersoz123',
            209 => 'Khalee123',
            210 => 'Mercan123',
        ];
        foreach ($userIdByPersonel as $personelId => $userId) {
            $row = pflcRowById($pdo, $userId);
            pflcAssert(
                (string) $row['username'] === $expectedUsernames[$personelId],
                'BIZ: ' . $personelId . ' username = ' . $expectedUsernames[$personelId]
            );
            pflcAssert(
                PasswordHasher::verify($templatePasswords[$personelId], (string) $row['password_hash']),
                'BIZ: ' . $personelId . ' template sifre hash dogrulandi'
            );
            pflcAssert(
                (int) $row['activation_required'] === 0 && (int) $row['must_change_password'] === 1,
                'BIZ: ' . $personelId . ' activation_required=0 / must_change_password=1'
            );
            pflcAssert(
                (string) $row['rol'] === 'PERSONEL' && (int) $row['personel_id'] === $personelId,
                'BIZ: ' . $personelId . ' rol/personel_id korundu'
            );
        }

        $personelName = static function (PDO $conn, int $personelId): array {
            $stmt = $conn->prepare('SELECT ad, soyad FROM personeller WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $personelId]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($found) ? $found : [];
        };
        pflcAssert($personelName($pdo, 200) === ['ad' => 'Raed', 'soyad' => 'Fawaz'], 'BIZ: 200 name correction uygulandi');
        pflcAssert($personelName($pdo, 201) === ['ad' => 'Saif Tareq Jasim', 'soyad' => 'Al-Gburi'], 'BIZ: 201 name correction uygulandi');
        pflcAssert($personelName($pdo, 207) === ['ad' => 'Oktay', 'soyad' => 'Ersöz'], 'BIZ: 207 name correction uygulandi');
        pflcAssert($personelName($pdo, 209) === ['ad' => 'Muqtada Mazin', 'soyad' => 'Khalee'], 'BIZ: 209 name correction uygulandi');
        pflcAssert($personelName($pdo, 210) === ['ad' => 'Fahri Taylan', 'soyad' => 'Mercan'], 'BIZ: 210 name correction uygulandi');
        pflcAssert($personelName($pdo, 206) === ['ad' => 'ABDULLAH', 'soyad' => null], 'ABDULLAH_SURNAME_MUTATED=NO (206 ad/soyad dokunulmadi)');
        pflcAssert($personelName($pdo, 108) === ['ad' => 'Hakan', 'soyad' => 'Açıkgöz'], 'BIZ: 108 ad/soyad dokunulmadi');
        pflcAssert($personelName($pdo, 109) === ['ad' => 'Hakan', 'soyad' => 'Atay'], 'BIZ: 109 ad/soyad dokunulmadi');
        pflcAssert($personelName($pdo, 230) === ['ad' => 'İlker', 'soyad' => 'Akel'], 'BIZ: ilkerA personel kaydi dokunulmadi');

        // K) ilkerA before/after exact invariant + plan disi
        pflcAssert(pflcRowById($pdo, 509) === $protectedBefore, 'K: ilkerA before/after exact invariant');
        pflcAssert(
            count(array_filter($apply['plan'], static function ($row) {
                return (int) $row['user_id'] === 509;
            })) === 0,
            'K: ilkerA apply planinda yok'
        );

        // P) PASIF bagli hesap mutation almadi; login fail-closed kalir
        $anomalyRow = pflcRowById($pdo, 508);
        pflcAssert((string) $anomalyRow['username'] === '4475', 'P: PASIF bagli hesap username dokunulmadi');
        pflcAssert((int) $anomalyRow['activation_required'] === 1, 'P: PASIF bagli hesap activation_required=1');
        pflcAssert(
            PasswordHasher::verify('LegacyPass-24chars!!', (string) $anomalyRow['password_hash']),
            'P: PASIF bagli hesap password_hash dokunulmadi'
        );

        // L-O) ilk giris -> zorunlu sifre degisimi -> eski template DENIED -> yeni sifre SUCCESS
        $GLOBALS['config']['db_host'] = '127.0.0.1';
        $GLOBALS['config']['db_name'] = $bizDb;
        $GLOBALS['config']['db_user'] = 'test';
        $GLOBALS['config']['db_password'] = 'test';
        $GLOBALS['config']['jwt_secret'] = str_repeat('pflc-biz-secret-', 3);
        $GLOBALS['config']['jwt_ttl_seconds'] = 3600;
        pflcSetPdo($pdo);

        $raedLogin = pflcCapture(static function (): void {
            LoginController::login(pflcRequest(['username' => 'raedF', 'password' => 'Fawaz123']));
        });
        pflcAssert(!empty($raedLogin['data']['token']), 'L: template credential ilk giris SUCCESS (raedF / Fawaz123)');
        pflcAssert(
            ($raedLogin['data']['must_change_password'] ?? null) === true,
            'M: login must_change_password=1 -> /change-password rotasi'
        );
        pflcAssert(($raedLogin['data']['user']['rol'] ?? null) === 'PERSONEL', 'PERSONEL post-login rolu');

        $abdullahLogin = pflcCapture(static function (): void {
            LoginController::login(pflcRequest(['username' => 'abdullah', 'password' => 'Abdullah123']));
        });
        pflcAssert(!empty($abdullahLogin['data']['token']), 'L: abdullah template credential SUCCESS (Abdullah123)');

        $oldSicilLogin = pflcCapture(static function (): void {
            LoginController::login(pflcRequest(['username' => '176', 'password' => 'LegacyPass-24chars!!']));
        });
        pflcAssert(pflcErrorCode($oldSicilLogin) === 'INVALID_CREDENTIALS', 'BIZ: eski sicil credential DENIED');

        pflcSetAuthUser(['id' => 502, 'rol' => 'PERSONEL', 'must_change_password' => true]);
        $change = pflcCapture(static function (): void {
            ChangePasswordController::change(
                pflcRequest(
                    ['current_password' => 'Fawaz123', 'new_password' => 'YeniSifre-2026'],
                    '/auth/change-password'
                )
            );
        });
        pflcAssert(pflcErrorCode($change) === null, 'M: forced password change PASS');
        pflcAssert(
            ($change['data']['must_change_password'] ?? null) === false,
            'M: change-password must_change_password=false dondu'
        );
        $raedAfterChange = pflcRowById($pdo, 502);
        pflcAssert((int) $raedAfterChange['must_change_password'] === 0, 'M: DB must_change_password = 0');
        pflcAssert(
            PasswordHasher::verify('YeniSifre-2026', (string) $raedAfterChange['password_hash']),
            'M: yeni sifre hash dogrulandi'
        );
        pflcSetAuthUser(null);

        $oldTemplateLogin = pflcCapture(static function (): void {
            LoginController::login(pflcRequest(['username' => 'raedF', 'password' => 'Fawaz123']));
        });
        pflcAssert(pflcErrorCode($oldTemplateLogin) === 'INVALID_CREDENTIALS', 'O: eski template sifresi DENIED');

        $newPasswordLogin = pflcCapture(static function (): void {
            LoginController::login(pflcRequest(['username' => 'raedF', 'password' => 'YeniSifre-2026']));
        });
        pflcAssert(!empty($newPasswordLogin['data']['token']), 'N: yeni sifre ile login SUCCESS');
        pflcAssert(($newPasswordLogin['data']['must_change_password'] ?? null) === false, 'N: must_change_password=0');

        $blockedPasif = pflcCapture(static function (): void {
            LoginController::login(pflcRequest(['username' => '4475', 'password' => 'LegacyPass-24chars!!']));
        });
        pflcAssert(pflcErrorCode($blockedPasif) === 'INVALID_CREDENTIALS', 'P: PASIF anomaly login fail-closed');
        $blockedIlker = pflcCapture(static function (): void {
            LoginController::login(pflcRequest(['username' => 'ilkerA', 'password' => 'Akel123']));
        });
        pflcAssert(pflcErrorCode($blockedIlker) === 'INVALID_CREDENTIALS', 'K: ilkerA login fail-closed (dokunulmadi)');
        pflcSetAuthUser(null);
    } finally {
        $root->exec('DROP DATABASE IF EXISTS `' . $bizDb . '`');
    }
}

// ---------------------------------------------------------------------------
// MAIN senaryo: canonical cohort gecisi + ilk giris + zorunlu sifre degisimi
// ---------------------------------------------------------------------------

$root = pflcRootPdo();
$mainDb = 'medisa_pflc_main_' . bin2hex(random_bytes(4));
$collideDb = 'medisa_pflc_collide_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $mainDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec('CREATE DATABASE `' . $collideDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = pflcPdoForDb($mainDb);
    pflcApplyCanonicalSchema($pdo);
    pflcSeedCatalog($pdo);

    $legacyTemplateHash = PasswordHasher::hash('LegacyPass-24chars!!');

    // 401 = rezerve hesabin personel kaydi; 404/405/407 = anomaly fixture'lari.
    // NOT: fixture id'leri explicit business override/correction id'lerinden (108/109/200/201/
    // 206/207/209/210) kasitli olarak ayridir; is karari senaryosu ayri DB'de kosar.
    pflcInsertPersonel($pdo, 400, 'Serhan', 'Köse', 'AKTIF');
    pflcInsertPersonel($pdo, 401, 'İlker', 'Akel', 'AKTIF');
    pflcInsertPersonel($pdo, 402, 'Musa', 'Taş', 'AKTIF');
    pflcInsertPersonel($pdo, 403, 'Emre', 'ÇELİK', 'AKTIF');
    pflcInsertPersonel($pdo, 404, 'Ayse', 'Demir', 'AKTIF');
    pflcInsertPersonel($pdo, 405, 'Pasif', 'Personel', 'PASIF');
    pflcInsertPersonel($pdo, 407, '', '', 'AKTIF');

    pflcInsertUser($pdo, 1, 'admin', $legacyTemplateHash, 'Admin', 'GENEL_YONETICI', 'AKTIF', null);
    pflcInsertUser($pdo, 10, '4471', $legacyTemplateHash, 'Serhan Kose', 'PERSONEL', 'AKTIF', 400);
    pflcInsertUser($pdo, 11, PFLC_PROTECTED_USERNAME, $legacyTemplateHash, 'Ilker Akel', 'PERSONEL', 'AKTIF', 401);
    pflcInsertUser($pdo, 12, '4472', $legacyTemplateHash, 'Musa Tas', 'PERSONEL', 'AKTIF', 402);
    pflcInsertUser($pdo, 13, '4473', $legacyTemplateHash, 'Emre Celik', 'PERSONEL', 'AKTIF', 403);
    pflcInsertUser($pdo, 14, '4474', $legacyTemplateHash, 'Ayse Demir', 'PERSONEL', 'PASIF', 404);
    pflcInsertUser($pdo, 15, '4475', $legacyTemplateHash, 'Pasif Personel', 'PERSONEL', 'AKTIF', 405);
    pflcInsertUser($pdo, 16, '4476', $legacyTemplateHash, 'Bindingsiz', 'PERSONEL', 'AKTIF', null);
    pflcInsertUser($pdo, 18, '4478', $legacyTemplateHash, 'Adsiz Kayit', 'PERSONEL', 'AKTIF', 407);

    // FK'li ortamda "bagli personel yok" anomalisi: yalniz fixture icin FK bypass.
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    pflcInsertUser($pdo, 17, '4477', $legacyTemplateHash, 'Hayalet', 'PERSONEL', 'AKTIF', 999);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    // Production durumu: 089 legacy PERSONEL hesaplarini aktivasyon bekleyen hale alir.
    pflcApply($pdo, '089_personel_legacy_account_activation.sql');
    $pflcPreApplyTen = pflcRowById($pdo, PFLC_SERHAN_USER_ID);
    pflcAssert((int) $pflcPreApplyTen['activation_required'] === 1, '089 sonrasi hedef hesap activation_required=1 (preimage)');

    $pflcProtectedBefore = pflcRowById($pdo, PFLC_PROTECTED_USER_ID);
    $pflcExcludedBefore = [
        14 => pflcRowById($pdo, 14),
        15 => pflcRowById($pdo, 15),
        16 => pflcRowById($pdo, 16),
        17 => pflcRowById($pdo, 17),
        18 => pflcRowById($pdo, 18),
    ];

    $dry = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials($pdo, 1, false);
    pflcAssert($dry['apply'] === false && $dry['blocked'] === false, 'dry-run blocked=false');
    pflcAssert($dry['target_count'] === 3, 'dry-run target_count = 3');
    pflcAssert(
        count($dry['excluded']['protected_username']) === 1
            && $dry['excluded']['protected_username'][0]['username'] === PFLC_PROTECTED_USERNAME,
        'dry-run ilkerA protected_username olarak dislandi'
    );
    pflcAssert(
        count($dry['excluded']['user_not_active']) === 1
            && count($dry['excluded']['bound_personel_not_active']) === 1
            && count($dry['excluded']['binding_missing']) === 1
            && count($dry['excluded']['bound_personel_missing']) === 1
            && count($dry['excluded']['name_unresolved']) === 1,
        'dry-run anomaly cohortlari dislandi (PASIF/missing/unresolved)'
    );
    pflcAssert(
        (string) pflcRowById($pdo, PFLC_SERHAN_USER_ID)['username'] === '4471',
        'dry-run hicbir username degistirmedi'
    );

    $apply = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials($pdo, 1, true);
    pflcAssert($apply['apply'] === true && $apply['applied_count'] === 3, 'apply applied_count = 3');
    pflcAssert($apply['audit_event'] === 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLIED', 'apply audit event adi');
    pflcAssert(
        pflcAuditCount($pdo, 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLIED') === 3,
        'audit izi 3 satir'
    );

    // A) Serhan Köse -> serhanK + Kose123 (hash uzerinden)
    $serhan = pflcRowById($pdo, PFLC_SERHAN_USER_ID);
    pflcAssert((string) $serhan['username'] === 'serhanK', 'A: Serhan Kose username = serhanK');
    pflcAssert(
        PasswordHasher::verify('Kose123', (string) $serhan['password_hash']),
        'A: Serhan Kose baslangic sifresi Kose123 hash ile dogrulandi'
    );
    pflcAssert(
        (string) $serhan['password_hash'] !== 'Kose123' && strpos((string) $serhan['password_hash'], '$2y$') === 0,
        'A: password_hash plaintext degil (bcrypt)'
    );
    pflcAssert((int) $serhan['activation_required'] === 0, 'F: hedef activation_required = 0');
    pflcAssert((int) $serhan['must_change_password'] === 1, 'F: hedef must_change_password = 1');
    pflcAssert((string) $serhan['rol'] === 'PERSONEL' && (int) $serhan['personel_id'] === 400, 'rol/personel_id korundu');

    // B) Turkish normalization
    pflcAssert(
        PersonelAccountOnboardingService::buildPersonelInitialPasswordFromNames('Serhan', 'KÖSE') === 'Kose123',
        'B: KÖSE -> Kose123'
    );
    pflcAssert(
        PersonelAccountOnboardingService::buildPersonelInitialPasswordFromNames('Emre', 'ÇELİK') === 'Celik123',
        'B: ÇELİK -> Celik123'
    );
    pflcAssert(
        PersonelAccountOnboardingService::buildPersonelInitialPasswordFromNames('X', 'ŞENAY') === 'Senay123',
        'B: ŞENAY -> Senay123'
    );
    pflcAssert(
        PersonelAccountOnboardingService::buildPersonelInitialPasswordFromNames('Musa', 'Taş') === 'Tas123',
        'B: Taş -> Tas123'
    );
    pflcAssert(
        PersonelAccountOnboardingService::buildPersonelUsernameFromNames('Musa', 'Taş') === 'musaT',
        'B: Musa Taş -> musaT'
    );
    pflcAssert(
        PersonelAccountOnboardingService::buildPersonelUsernameFromNames('Serhan', 'Köse') === 'serhanK',
        'B: Serhan Köse -> serhanK'
    );
    pflcAssert(
        (string) pflcRowById($pdo, 12)['username'] === 'musaT'
            && PasswordHasher::verify('Tas123', (string) pflcRowById($pdo, 12)['password_hash']),
        'B: Musa Tas hesabi musaT / Tas123'
    );
    pflcAssert(
        (string) pflcRowById($pdo, 13)['username'] === 'emreC'
            && PasswordHasher::verify('Celik123', (string) pflcRowById($pdo, 13)['password_hash']),
        'B: ÇELİK hesabi emreC / Celik123'
    );

    // G) Rezerve hesap: exact invariant (mutation cohort'unda DEGIL)
    $protectedAfter = pflcRowById($pdo, PFLC_PROTECTED_USER_ID);
    pflcAssert($protectedAfter === $pflcProtectedBefore, 'G: rezerve hesap before/after exact invariant');
    pflcAssert((int) $protectedAfter['activation_required'] === 1, 'G: rezerve hesap activation_required dokunulmadi');
    pflcAssert(
        (string) $protectedAfter['password_hash'] === (string) $pflcProtectedBefore['password_hash'],
        'G: rezerve hesap password_hash dokunulmadi'
    );
    pflcAssert(
        (string) $protectedAfter['rol'] === 'PERSONEL' && (string) $protectedAfter['durum'] === 'AKTIF'
            && (int) $protectedAfter['personel_id'] === 401,
        'G: rezerve hesap rol/durum/personel_id dokunulmadi'
    );
    pflcAssert(
        count(array_filter($apply['plan'], static function ($row) {
            return (int) $row['user_id'] === PFLC_PROTECTED_USER_ID;
        })) === 0,
        'G: rezerve hesap apply planinda yok'
    );

    // I) PASIF / destroyed anomaly hesaplar: login fail-closed korunur, mutation yok
    foreach ([14, 15, 16, 17, 18] as $excludedId) {
        $afterRow = pflcRowById($pdo, $excludedId);
        pflcAssert($afterRow === $pflcExcludedBefore[$excludedId], 'I: anomaly user ' . $excludedId . ' untouched');
        pflcAssert(
            (int) $afterRow['activation_required'] === 1,
            'I: anomaly user ' . $excludedId . ' activation_required=1 (fail-closed)'
        );
    }

    // C) PERSONEL ilk giris: template credential -> SUCCESS + must_change_password=1
    $GLOBALS['config']['db_host'] = '127.0.0.1';
    $GLOBALS['config']['db_name'] = $mainDb;
    $GLOBALS['config']['db_user'] = 'test';
    $GLOBALS['config']['db_password'] = 'test';
    $GLOBALS['config']['jwt_secret'] = str_repeat('pflc-secret-', 4);
    $GLOBALS['config']['jwt_ttl_seconds'] = 3600;
    pflcSetPdo($pdo);

    $loginOk = pflcCapture(static function (): void {
        LoginController::login(pflcRequest(['username' => 'serhanK', 'password' => 'Kose123']));
    });
    pflcAssert(!empty($loginOk['data']['token']), 'C: template credential ile login SUCCESS (token)');
    pflcAssert(($loginOk['data']['must_change_password'] ?? null) === true, 'C: login must_change_password=1 dondu');
    pflcAssert(($loginOk['data']['user']['rol'] ?? null) === 'PERSONEL', 'C: login rolu PERSONEL');

    $legacyLogin = pflcCapture(static function (): void {
        LoginController::login(pflcRequest(['username' => '4471', 'password' => 'LegacyPass-24chars!!']));
    });
    pflcAssert(pflcErrorCode($legacyLogin) === 'INVALID_CREDENTIALS', 'legacy sicil username artik gecersiz');

    // D) Zorunlu sifre degisimi + must_change_password = 0
    pflcSetAuthUser(['id' => PFLC_SERHAN_USER_ID, 'rol' => 'PERSONEL', 'must_change_password' => true]);
    $change = pflcCapture(static function (): void {
        ChangePasswordController::change(
            pflcRequest(
                ['current_password' => 'Kose123', 'new_password' => 'YeniSifre-2026'],
                '/auth/change-password'
            )
        );
    });
    pflcAssert(pflcErrorCode($change) === null, 'D: change-password hata dondurmedi');
    pflcAssert(($change['data']['must_change_password'] ?? null) === false, 'D: change-password must_change_password=false dondu');
    $serhanAfterChange = pflcRowById($pdo, PFLC_SERHAN_USER_ID);
    pflcAssert((int) $serhanAfterChange['must_change_password'] === 0, 'D: DB must_change_password = 0');
    pflcAssert(
        PasswordHasher::verify('YeniSifre-2026', (string) $serhanAfterChange['password_hash']),
        'D: yeni sifre hash ile dogrulandi'
    );

    // E) Eski template sifresi artik reddedilir; yeni sifre calisir
    pflcSetAuthUser(null);
    $oldAfterChange = pflcCapture(static function (): void {
        LoginController::login(pflcRequest(['username' => 'serhanK', 'password' => 'Kose123']));
    });
    pflcAssert(pflcErrorCode($oldAfterChange) === 'INVALID_CREDENTIALS', 'E: eski template sifresi DENIED');

    $newAfterChange = pflcCapture(static function (): void {
        LoginController::login(pflcRequest(['username' => 'serhanK', 'password' => 'YeniSifre-2026']));
    });
    pflcAssert(!empty($newAfterChange['data']['token']), 'POST_CHANGE_LOGIN: yeni sifre ile login SUCCESS');
    pflcAssert(($newAfterChange['data']['must_change_password'] ?? null) === false, 'POST_CHANGE_LOGIN: must_change_password=0');

    // I) PASIF / aktivasyon bekleyen / rezerve hesaplar login veremez (fail-closed)
    foreach ([11, 14, 15, 17] as $blockedUserId) {
        $blockedUsername = (string) pflcRowById($pdo, $blockedUserId)['username'];
        $blocked = pflcCapture(static function () use ($blockedUsername): void {
            LoginController::login(pflcRequest(['username' => $blockedUsername, 'password' => 'Kose123']));
        });
        pflcAssert(
            pflcErrorCode($blocked) === 'INVALID_CREDENTIALS',
            'I: user ' . $blockedUserId . ' login blocked (fail-closed)'
        );
    }
    pflcSetAuthUser(null);

    // H) Canonical username collision -> fail-closed, hicbir mutation yok
    $collidePdo = pflcPdoForDb($collideDb);
    pflcApplyCanonicalSchema($collidePdo);
    pflcSeedCatalog($collidePdo);
    pflcInsertPersonel($collidePdo, 300, 'Mehmet', 'Aslan', 'AKTIF');
    pflcInsertPersonel($collidePdo, 301, 'Ahmet', 'Yılmaz', 'AKTIF');
    pflcInsertPersonel($collidePdo, 302, 'Ahmet', 'Yıldız', 'AKTIF');
    // Yonetim hesabi hedef canonical username'i zaten tutuyor (cohort disi cakisma).
    pflcInsertUser($collidePdo, 1, 'mehmetA', $legacyTemplateHash, 'Yonetim', 'GENEL_YONETICI', 'AKTIF', null);
    pflcInsertUser($collidePdo, 30, '4480', $legacyTemplateHash, 'Mehmet Aslan', 'PERSONEL', 'AKTIF', 300);
    pflcInsertUser($collidePdo, 31, '4481', $legacyTemplateHash, 'Ahmet Yilmaz', 'PERSONEL', 'AKTIF', 301);
    pflcInsertUser($collidePdo, 32, '4482', $legacyTemplateHash, 'Ahmet Yildiz', 'PERSONEL', 'AKTIF', 302);
    pflcApply($collidePdo, '089_personel_legacy_account_activation.sql');

    $collideBefore = pflcCredentialRows($collidePdo);
    $collide = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials($collidePdo, 1, true);
    pflcAssert($collide['blocked'] === true, 'H: collision halinde blocked=true');
    pflcAssert($collide['blocker'] === 'PERSONEL_CANONICAL_USERNAME_COLLISION', 'H: blocker kodu');
    pflcAssert($collide['apply'] === false && $collide['applied_count'] === 0, 'H: apply yok (applied_count=0)');
    $collisionScopes = array_column($collide['collisions'], 'scope');
    pflcAssert(in_array('cohort', $collisionScopes, true), 'H: cohort ici collision tespit edildi');
    pflcAssert(in_array('outside_cohort', $collisionScopes, true), 'H: cohort disi collision tespit edildi');
    pflcAssert(pflcCredentialRows($collidePdo) === $collideBefore, 'H: collision halinde hicbir satir mutate edilmedi');
    pflcAssert(
        pflcAuditCount($collidePdo, 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLIED') === 0,
        'H: collision halinde audit izi yazilmadi'
    );

    // Business karari senaryosu (explicit override + name correction + ilk giris zinciri).
    pflcRunBusinessDecisionScenario($root);

    echo '[DONE] PersonelFirstLoginCredentialsMysqlTestRunner' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $mainDb . '`');
    $root->exec('DROP DATABASE IF EXISTS `' . $collideDb . '`');
}
