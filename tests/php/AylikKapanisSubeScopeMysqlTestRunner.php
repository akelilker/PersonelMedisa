<?php

declare(strict_types=1);

/**
 * Monthly closing chain — DB-backed acceptance against a real MariaDB.
 *
 * Asserts the two properties that could not be proven before: the aggregated
 * closing state is keyed on the server-owned canonical branch (so one branch's
 * closing cannot move another branch's state inside the same month), and the
 * approving actor is persisted so separation of duties on
 * bolum-onay -> ay-kapat is provable rather than inferred.
 *
 * The controller's private owners are invoked directly through reflection, so
 * the assertions run against the real SQL the production endpoints execute
 * instead of a re-implementation.
 *
 * php tests/php/AylikKapanisSubeScopeMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Auth\DualControl;
use Medisa\Api\Controllers\YonetimController;

function akssAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function akssPdo(string $dsn): PDO
{
    return new PDO(
        $dsn,
        getenv('MEDISA_TEST_MYSQL_USER') ?: 'root',
        getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function akssUser(int $id, string $rol, array $sube = []): array
{
    return ['id' => $id, 'rol' => $rol, 'sube_ids' => $sube, 'bolum_ids' => [], 'birim_ids' => []];
}

function akssFilters(string $ay, int $subeId = 0, int $departmanId = 0): array
{
    return ['ay' => $ay, 'sube_id' => $subeId, 'departman_id' => $departmanId, 'sadece_revizeli' => false];
}

/** Invokes a private static owner of YonetimController without changing its visibility contract. */
function akssCall(string $method, array $args)
{
    $ref = new ReflectionMethod(YonetimController::class, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs(null, $args);
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
if ($dsn === '' || stripos($dsn, 'karmotor_medisa') !== false) {
    echo "SKIP: Disposable MariaDB credentials are required.\n";
    exit(0);
}
if (preg_match('/host=([^;]+)/i', $dsn, $hostMatch)
    && !in_array(strtolower($hostMatch[1]), ['127.0.0.1', 'localhost', '::1'], true)
) {
    throw new RuntimeException('Unsafe MariaDB host refused.');
}

$db = 'medisa_akss_' . bin2hex(random_bytes(5));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = akssPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = akssPdo((preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn));

try {
    // Production shape of 001 plus the 079 columns, so both pre- and post-migration
    // behaviour can be exercised on the same fixture.
    $pdo->exec(
        "CREATE TABLE aylik_kapanis_state (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ay CHAR(7) NOT NULL,
            sube_id INT UNSIGNED NOT NULL DEFAULT 0,
            state ENUM('BOLUM_ONAYINDA','BOLUM_ONAYLANDI','REVIZE_ISTENDI','KAPANDI')
                NOT NULL DEFAULT 'BOLUM_ONAYINDA',
            PRIMARY KEY (id),
            UNIQUE KEY uq_aylik_kapanis_state_ay_sube (ay, sube_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE aylik_ozet_satirlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ay CHAR(7) NOT NULL,
            personel_id INT UNSIGNED NOT NULL,
            ad_soyad VARCHAR(160) NOT NULL,
            sicil_no VARCHAR(32) NULL,
            sube_id INT UNSIGNED NULL,
            sube VARCHAR(120) NOT NULL,
            departman_id INT UNSIGNED NULL,
            bolum VARCHAR(120) NOT NULL,
            bagli_amir_adi VARCHAR(120) NULL,
            devamsizlik_gun INT NOT NULL DEFAULT 0,
            gec_kalma_adet INT NOT NULL DEFAULT 0,
            izinli_gelmedi INT NOT NULL DEFAULT 0,
            izinsiz_gelmedi INT NOT NULL DEFAULT 0,
            raporlu INT NOT NULL DEFAULT 0,
            tesvik_tutari DECIMAL(12,2) NOT NULL DEFAULT 0,
            ceza_kesinti_tutari DECIMAL(12,2) NOT NULL DEFAULT 0,
            bolum_onay_durumu ENUM('BOLUM_ONAYINDA','BOLUM_ONAYLANDI','REVIZE_ISTENDI')
                NOT NULL DEFAULT 'BOLUM_ONAYINDA',
            revize_var_mi TINYINT(1) NOT NULL DEFAULT 0,
            son_islem VARCHAR(255) NULL,
            bolum_onay_actor_user_id INT UNSIGNED NULL,
            bolum_onay_actor_identity_id INT UNSIGNED NULL,
            bolum_onay_at DATETIME(3) NULL,
            kapanis_durumu ENUM('ACIK','KAPANDI') NOT NULL DEFAULT 'ACIK',
            kapanis_actor_user_id INT UNSIGNED NULL,
            kapanis_actor_identity_id INT UNSIGNED NULL,
            kapanis_at DATETIME(3) NULL,
            PRIMARY KEY (id),
            KEY idx_aylik_ozet_ay (ay),
            KEY idx_aylik_ozet_sube (sube_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ay = '2026-06';
    // Two branches in the same month: 1 = Medisa merkez, 2 = Giresun, 3 = Kayseri.
    $seed = function (PDO $pdo, string $ay, array $rows): void {
        $pdo->exec('DELETE FROM aylik_ozet_satirlari');
        $pdo->exec('DELETE FROM aylik_kapanis_state');
        $stmt = $pdo->prepare(
            'INSERT INTO aylik_ozet_satirlari
                (ay, personel_id, ad_soyad, sube_id, sube, departman_id, bolum,
                 bolum_onay_durumu, kapanis_durumu, bolum_onay_actor_user_id)
             VALUES (:ay, :pid, :ad, :sube_id, :sube, :dep, :bolum, :onay, :kapanis, :actor)'
        );
        foreach ($rows as $row) {
            $stmt->execute([
                'ay' => $ay,
                'pid' => $row[0],
                'ad' => $row[1],
                'sube_id' => $row[2],
                'sube' => $row[3],
                'dep' => $row[4],
                'bolum' => 'Bolum',
                'onay' => $row[5],
                'kapanis' => $row[6],
                'actor' => $row[7],
            ]);
        }
    };

    $stateOf = function (PDO $pdo, string $ay, int $subeId) {
        $stmt = $pdo->prepare('SELECT state FROM aylik_kapanis_state WHERE ay = :ay AND sube_id = :s');
        $stmt->execute(['ay' => $ay, 's' => $subeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (string) $row['state'] : null;
    };

    // ------------------------------------------------------------------
    // 1) Server-owned canonical branch resolution
    // ------------------------------------------------------------------
    $seed($pdo, $ay, [
        [1, 'Merkez A', 1, 'Medisa', 10, 'BOLUM_ONAYLANDI', 'ACIK', 501],
        [2, 'Merkez B', 1, 'Medisa', 10, 'BOLUM_ONAYLANDI', 'ACIK', 501],
        [3, 'Giresun A', 2, 'Giresun', 20, 'BOLUM_ONAYINDA', 'ACIK', null],
        [4, 'Kayseri A', 3, 'Kayseri', 30, 'BOLUM_ONAYLANDI', 'ACIK', 502],
    ]);

    $genel = akssUser(900, 'GENEL_YONETICI');
    $merkez = akssUser(901, 'SUBE_YONETICISI', [1]);
    $giresun = akssUser(902, 'SUBE_YONETICISI', [2]);

    akssAssert(
        akssCall('resolveCanonicalAylikSubeIds', [$pdo, akssFilters($ay, 1), $genel]) === [1],
        'canonical branch is resolved from closing rows, not echoed from the request'
    );
    akssAssert(
        akssCall('resolveCanonicalAylikSubeIds', [$pdo, akssFilters($ay), $genel]) === [1, 2, 3],
        'unrestricted actor resolves every branch present in the month'
    );
    akssAssert(
        akssCall('resolveCanonicalAylikSubeIds', [$pdo, akssFilters($ay), $merkez]) === [1],
        'empty-scope-free branch actor resolves only its own branch'
    );
    akssAssert(
        akssCall('resolveCanonicalAylikSubeIds', [$pdo, akssFilters($ay, 2), $merkez]) === [],
        'cross-branch target resolves to no canonical branch for a scoped actor'
    );

    $crossWhere = akssCall('buildAylikOzetWhereClause', [akssFilters($ay, 2), $merkez]);
    akssAssert(
        strpos($crossWhere['sql'], 'sube_id = :sube_id') !== false
        && strpos($crossWhere['sql'], 'sube_id IN (') !== false,
        'requested branch is ANDed with the assignment instead of replacing it'
    );
    $crossRows = $pdo->prepare('SELECT COUNT(*) FROM aylik_ozet_satirlari WHERE ' . $crossWhere['sql']);
    $crossRows->execute($crossWhere['params']);
    akssAssert(
        (int) $crossRows->fetchColumn() === 0,
        'cross-branch deny: a foreign sube_id matches no row even without the gate in front'
    );

    // ------------------------------------------------------------------
    // 2) One branch's closing must not touch another branch's state
    // ------------------------------------------------------------------
    $pdo->exec("UPDATE aylik_ozet_satirlari SET kapanis_durumu = 'KAPANDI' WHERE sube_id = 1");
    akssCall('syncAylikKapanisState', [$pdo, $ay, [1]]);

    akssAssert($stateOf($pdo, $ay, 1) === 'KAPANDI', 'closed branch reaches KAPANDI on its own row');
    akssAssert($stateOf($pdo, $ay, 2) === null, 'closing branch 1 never writes branch 2 state');
    akssAssert($stateOf($pdo, $ay, 3) === null, 'closing branch 1 never writes branch 3 state');

    $openRows = (int) $pdo->query(
        "SELECT COUNT(*) FROM aylik_ozet_satirlari WHERE sube_id <> 1 AND kapanis_durumu = 'KAPANDI'"
    )->fetchColumn();
    akssAssert($openRows === 0, 'closing branch 1 never closes another branch rows');

    akssCall('syncAylikKapanisState', [$pdo, $ay, [2, 3]]);
    akssAssert($stateOf($pdo, $ay, 2) === 'BOLUM_ONAYINDA', 'pending branch keeps its own pending state');
    akssAssert($stateOf($pdo, $ay, 3) === 'BOLUM_ONAYLANDI', 'approved-but-open branch keeps BOLUM_ONAYLANDI');
    akssAssert($stateOf($pdo, $ay, 1) === 'KAPANDI', 'already closed branch state survives a sibling sync');

    $stateRows = (int) $pdo->query('SELECT COUNT(*) FROM aylik_kapanis_state')->fetchColumn();
    akssAssert($stateRows === 3, 'state is one row per (ay, sube_id), not one global row per month');

    // Revision on one branch must not leak into the other branches.
    $pdo->exec("UPDATE aylik_ozet_satirlari SET bolum_onay_durumu = 'REVIZE_ISTENDI' WHERE sube_id = 2");
    akssCall('syncAylikKapanisState', [$pdo, $ay, [2]]);
    akssAssert($stateOf($pdo, $ay, 2) === 'REVIZE_ISTENDI', 'revision moves only its own branch state');
    akssAssert($stateOf($pdo, $ay, 1) === 'KAPANDI', 'revision on branch 2 leaves branch 1 KAPANDI');
    akssAssert($stateOf($pdo, $ay, 3) === 'BOLUM_ONAYLANDI', 'revision on branch 2 leaves branch 3 intact');

    // ------------------------------------------------------------------
    // 3) Reported state is scoped to what the caller may see
    // ------------------------------------------------------------------
    akssAssert(
        akssCall('readAylikKapanisState', [$pdo, akssFilters($ay, 1), $genel]) === 'KAPANDI',
        'payload state for branch 1 is that branch own state'
    );
    akssAssert(
        akssCall('readAylikKapanisState', [$pdo, akssFilters($ay), $giresun]) === 'REVIZE_ISTENDI',
        'branch-restricted actor sees only its own branch state'
    );
    akssAssert(
        akssCall('readAylikKapanisState', [$pdo, akssFilters($ay), $genel]) === 'REVIZE_ISTENDI',
        'cross-branch view folds to the most blocking state, never to a false KAPANDI'
    );

    $payload = akssCall('buildAylikOzetPayload', [$pdo, akssFilters($ay, 1), $genel]);
    akssAssert($payload['state'] === 'KAPANDI', 'payload state matches the scoped branch');
    akssAssert(count($payload['items']) === 2, 'payload items stay branch filtered');
    akssAssert(
        $payload['summary']['toplam_personel'] === count($payload['items']),
        'count/state parity: summary total equals the returned rows'
    );

    $giresunPayload = akssCall('buildAylikOzetPayload', [$pdo, akssFilters($ay), $giresun]);
    akssAssert(count($giresunPayload['items']) === 1, 'cross-branch rows never leak into a scoped payload');
    akssAssert(
        $giresunPayload['state'] === 'REVIZE_ISTENDI',
        'state and items describe the same branch for a scoped actor'
    );

    // ------------------------------------------------------------------
    // 4) Persisted actor + fail-closed separation of duties
    // ------------------------------------------------------------------
    $seed($pdo, $ay, [
        [1, 'Merkez A', 1, 'Medisa', 10, 'BOLUM_ONAYLANDI', 'ACIK', 501],
        [2, 'Merkez B', 1, 'Medisa', 10, 'BOLUM_ONAYLANDI', 'ACIK', 501],
    ]);

    $persisted = $pdo->query(
        'SELECT bolum_onay_actor_user_id FROM aylik_ozet_satirlari WHERE personel_id = 1'
    )->fetchColumn();
    akssAssert((int) $persisted === 501, 'section approver is persisted and queryable on the row');

    akssAssert(
        akssCall('aylikOzetActorColumnsSupported', [$pdo]) === true,
        'actor columns are detected when the 079 schema is present'
    );

    $selfApprover = akssUser(501, 'GENEL_YONETICI');
    akssAssert(
        DualControl::violation($selfApprover, 501, $pdo) !== null,
        'same user closing rows it approved is a DualControl violation'
    );
    akssAssert(
        DualControl::violation($selfApprover, 501, $pdo)['code'] === DualControl::CODE_SELF_APPROVAL,
        'self-approval is denied with the canonical SELF_APPROVAL_FORBIDDEN code'
    );
    akssAssert(
        DualControl::violation(akssUser(900, 'GENEL_YONETICI'), 501, $pdo) === null,
        'a distinct final approver passes separation of duties'
    );
    akssAssert(
        DualControl::violation(akssUser(900, 'GENEL_YONETICI'), null, $pdo) !== null,
        'unknown section approver fails closed instead of being auto-approved'
    );

    // Same human behind two accounts must also be denied.
    $pdo->exec(
        "CREATE TABLE actor_identities (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            identity_code VARCHAR(64) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            actor_identity_id INT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec("INSERT INTO actor_identities (id, identity_code) VALUES (77, 'AI-77')");
    $pdo->exec('INSERT INTO users (id, actor_identity_id) VALUES (501, 77), (900, 77), (901, NULL)');

    $twinViolation = DualControl::violation(akssUser(900, 'GENEL_YONETICI'), 501, $pdo);
    akssAssert($twinViolation !== null, 'second account of the same actor identity is denied');
    akssAssert(
        $twinViolation['code'] === DualControl::CODE_SAME_IDENTITY,
        'same actor identity is denied with SAME_ACTOR_IDENTITY_FORBIDDEN'
    );
    akssAssert(
        DualControl::violation(akssUser(901, 'GENEL_YONETICI'), 501, $pdo) === null,
        'an unrelated identity is still allowed to give the final approval'
    );

    // ------------------------------------------------------------------
    // 5) Pre-079 schema keeps working (production runs before migration apply)
    // ------------------------------------------------------------------
    $pdo->exec('DROP TABLE aylik_kapanis_state');
    $pdo->exec(
        "CREATE TABLE aylik_kapanis_state (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ay CHAR(7) NOT NULL,
            state ENUM('BOLUM_ONAYINDA','BOLUM_ONAYLANDI','REVIZE_ISTENDI','KAPANDI')
                NOT NULL DEFAULT 'BOLUM_ONAYINDA',
            PRIMARY KEY (id),
            UNIQUE KEY uq_aylik_kapanis_state_ay (ay)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    akssAssert(
        akssCall('aylikKapanisStateSubeScopeSupported', [$pdo]) === false,
        'branch scope is reported unsupported on a pre-079 schema'
    );

    $pdo->exec("UPDATE aylik_ozet_satirlari SET kapanis_durumu = 'KAPANDI'");
    akssCall('syncAylikKapanisState', [$pdo, $ay, [1]]);
    $legacy = $pdo->query('SELECT ay, state FROM aylik_kapanis_state')->fetchAll(PDO::FETCH_ASSOC);
    akssAssert(count($legacy) === 1, 'pre-079 schema keeps exactly one month-keyed state row');
    akssAssert(
        (string) $legacy[0]['state'] === 'KAPANDI' && (string) $legacy[0]['ay'] === $ay,
        'pre-079 aggregate behaviour is preserved verbatim, so nothing breaks before migration apply'
    );
    akssAssert(
        akssCall('readAylikKapanisState', [$pdo, akssFilters($ay), $genel]) === 'KAPANDI',
        'pre-079 read path still resolves the legacy month-only row'
    );

    // ------------------------------------------------------------------
    // 6) Source contracts — the write paths must keep their owners
    // ------------------------------------------------------------------
    $source = file_get_contents(__DIR__ . '/../../api/src/Controllers/YonetimController.php');
    akssAssert(
        strpos($source, 'self::syncAylikKapanisState($pdo, $filters[\'ay\'])') === false,
        'no caller passes the month alone to the state sync any more'
    );
    akssAssert(
        substr_count($source, 'resolveCanonicalAylikSubeIds($pdo, $filters, $user)') >= 3,
        'both write paths and the read path resolve the canonical branch server-side'
    );
    akssAssert(
        strpos($source, 'self::assertAylikKapanisSeparationOfDuties($pdo, $filters, $user)') !== false,
        'ay-kapat runs the separation-of-duties gate before closing'
    );
    akssAssert(
        strpos($source, 'DualControl::violation($user, $row[\'approver\']') !== false,
        'the separation gate delegates to the canonical DualControl owner'
    );
    akssAssert(
        strpos($source, 'bolum_onay_actor_user_id = :actor_user_id') !== false
        && strpos($source, 'kapanis_actor_user_id = :actor_user_id') !== false,
        'both approval steps persist their acting user'
    );

    akssAssert(
        substr_count($source, 'OrgScope::assertRequiredAssignment($user)') >= 2,
        'empty-scope deny is delegated to the canonical OrgScope owner on read and write'
    );

    $rolePermissions = file_get_contents(__DIR__ . '/../../api/src/Auth/RolePermissions.php');
    akssAssert(
        substr_count($rolePermissions, "'bildirimler.cancel'") === 0,
        'dead bildirimler.cancel grant is removed from the backend matrix'
    );

    echo 'verify-aylik-kapanis-sube-scope-mysql: OK' . PHP_EOL;
} finally {
    $pdo = null;
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
