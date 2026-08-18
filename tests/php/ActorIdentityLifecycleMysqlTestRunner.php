<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Auth/RolePermissions.php';
require_once __DIR__ . '/../../api/src/Services/Payroll/SgkKararPaketiAuthz.php';
require_once __DIR__ . '/../../api/src/Services/Auth/ActorIdentityService.php';

use Medisa\Api\Services\Auth\ActorIdentityException;
use Medisa\Api\Services\Auth\ActorIdentityService;
use Medisa\Api\Services\Payroll\SgkKararPaketiAuthz;

function actorPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Disposable MariaDB credentials are required.');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function actorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function actorExpectCode(callable $operation, string $code, string $message): void
{
    try {
        $operation();
    } catch (ActorIdentityException $e) {
        actorAssert($e->errorCode === $code, $message . ' code=' . $code);
        return;
    }

    throw new RuntimeException('[FAIL] ' . $message . ' expected=' . $code);
}

$root = actorPdo();
$database = 'medisa_actor_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $pdo = new PDO((string) $dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE TABLE subeler (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        durum ENUM('AKTIF','PASIF') NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE personeller (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        ad VARCHAR(80) NOT NULL,
        soyad VARCHAR(80) NOT NULL,
        aktif_durum ENUM('AKTIF','PASIF') NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE actor_identities (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        identity_code VARCHAR(64) NOT NULL,
        display_name VARCHAR(191) NOT NULL,
        normalized_name VARCHAR(191) NOT NULL,
        status ENUM('PENDING','VERIFIED','REVOKED') NOT NULL DEFAULT 'PENDING',
        verification_source ENUM('HUMAN_CONFIRMED','PERSONEL_LINKED','MIGRATED') NOT NULL,
        personel_id INT UNSIGNED NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_actor_code (identity_code),
        UNIQUE KEY uq_actor_personel (personel_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE users (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        username VARCHAR(64) NOT NULL,
        ad_soyad VARCHAR(191) NOT NULL,
        rol VARCHAR(64) NOT NULL,
        durum ENUM('AKTIF','PASIF') NOT NULL,
        personel_id INT UNSIGNED NULL,
        actor_identity_id INT UNSIGNED NULL,
        UNIQUE KEY uq_user_actor (actor_identity_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE user_subeler (
        user_id INT UNSIGNED NOT NULL,
        sube_id INT UNSIGNED NOT NULL,
        PRIMARY KEY (user_id, sube_id)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE actor_identity_audits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        actor_identity_id INT UNSIGNED NOT NULL,
        target_user_id INT UNSIGNED NULL,
        action VARCHAR(32) NOT NULL,
        changed_by_user_id INT UNSIGNED NOT NULL,
        details_json JSON NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB");

    $pdo->exec("INSERT INTO subeler VALUES (4, 'AKTIF')");
    $pdo->exec("INSERT INTO personeller VALUES (160, 'Sedanur', 'Bulut', 'AKTIF')");
    $pdo->exec("INSERT INTO users VALUES
        (10, 'ilkerA', 'İlker AKEL', 'GENEL_YONETICI', 'AKTIF', NULL, NULL),
        (11, 'sedanurB', 'Sedanur BULUT', 'IK_SORUMLUSU', 'AKTIF', 160, NULL),
        (12, 'baskaYonetici', 'Başka Yönetici', 'GENEL_YONETICI', 'AKTIF', NULL, NULL),
        (13, 'genel_yonetici', 'Generic Yönetici', 'GENEL_YONETICI', 'AKTIF', NULL, NULL),
        (14, 'bosAd', '', 'GENEL_YONETICI', 'AKTIF', NULL, NULL),
        (15, 'pasifYonetici', 'Pasif Yönetici', 'GENEL_YONETICI', 'PASIF', NULL, NULL),
        (90, 'ayriDogrulayici', 'Ayrı Doğrulayıcı', 'GENEL_YONETICI', 'AKTIF', NULL, NULL)");
    $pdo->exec("INSERT INTO user_subeler VALUES (10,4),(11,4),(12,4),(13,4),(14,4),(15,4),(90,4)");

    $admin = ['id' => 90, 'rol' => 'GENEL_YONETICI'];
    $ilker = ['id' => 10, 'rol' => 'GENEL_YONETICI'];

    $created = ActorIdentityService::create($pdo, $admin, 10);
    $ilkerIdentityId = (int) $created['actor_identity_id'];
    $row = $pdo->query("SELECT * FROM actor_identities WHERE id = $ilkerIdentityId")->fetch();
    actorAssert($row['personel_id'] === null, 'NON_PERSONEL_FORMAL_ACTOR_CREATE personel NULL');
    actorAssert($row['identity_code'] === 'USER-10', 'NON_PERSONEL_FORMAL_ACTOR_CREATE deterministic owner code');
    actorAssert($row['display_name'] === 'İlker AKEL', 'NON_PERSONEL_FORMAL_ACTOR_CREATE user display name');
    actorAssert($row['status'] === 'PENDING', 'NON_PERSONEL_FORMAL_ACTOR_CREATE PENDING');
    actorAssert($row['verification_source'] === 'HUMAN_CONFIRMED', 'NON_PERSONEL_FORMAL_ACTOR_CREATE source');

    actorExpectCode(
        static function () use ($pdo, $ilker, $ilkerIdentityId): void {
            ActorIdentityService::verify($pdo, $ilker, $ilkerIdentityId);
        },
        'ACTOR_IDENTITY_SELF_VERIFY_FORBIDDEN',
        'SELF_VERIFY_BEFORE_BIND fail closed'
    );

    $verified = ActorIdentityService::verify($pdo, $admin, $ilkerIdentityId);
    actorAssert($verified['actor_status'] === 'VERIFIED', 'NON_PERSONEL_FORMAL_ACTOR_VERIFY');

    actorExpectCode(
        static function () use ($pdo, $admin, $ilkerIdentityId): void {
            ActorIdentityService::bind($pdo, $admin, 12, $ilkerIdentityId);
        },
        'ACTOR_IDENTITY_OWNER_MISMATCH',
        'ARBITRARY_NULL_PERSONEL_BIND fail closed'
    );

    $bound = ActorIdentityService::bind($pdo, $admin, 10, $ilkerIdentityId);
    actorAssert($bound['actor_identity_id'] === $ilkerIdentityId, 'NON_PERSONEL_FORMAL_ACTOR_BIND');
    actorAssert($bound['ready'] === true, 'NON_PERSONEL_FORMAL_ACTOR ready');

    actorExpectCode(
        static function () use ($pdo, $ilker, $ilkerIdentityId): void {
            ActorIdentityService::verify($pdo, $ilker, $ilkerIdentityId);
        },
        'ACTOR_IDENTITY_SELF_VERIFY_FORBIDDEN',
        'SELF_VERIFY_AFTER_BIND fail closed'
    );

    $sedanurCreated = ActorIdentityService::create($pdo, $admin, 11);
    $sedanurIdentityId = (int) $sedanurCreated['actor_identity_id'];
    $sedanurRow = $pdo->query("SELECT * FROM actor_identities WHERE id = $sedanurIdentityId")->fetch();
    actorAssert((int) $sedanurRow['personel_id'] === 160, 'PERSONEL_LINKED_ACTOR personel preserved');
    actorAssert($sedanurRow['identity_code'] === 'PERSONEL-160', 'PERSONEL_LINKED_ACTOR code preserved');
    actorAssert($sedanurRow['verification_source'] === 'PERSONEL_LINKED', 'PERSONEL_LINKED_ACTOR source preserved');
    ActorIdentityService::verify($pdo, $admin, $sedanurIdentityId);
    $sedanurBound = ActorIdentityService::bind($pdo, $admin, 11, $sedanurIdentityId);
    actorAssert($sedanurBound['ready'] === true, 'SEDANUR_FLOW_REGRESSION');

    actorExpectCode(
        static function () use ($pdo, $admin): void {
            ActorIdentityService::create($pdo, $admin, 13);
        },
        'ACTOR_GENERIC_USER_FORBIDDEN',
        'GENERIC_USERNAME fail closed'
    );
    actorExpectCode(
        static function () use ($pdo, $admin): void {
            ActorIdentityService::create($pdo, $admin, 14);
        },
        'ACTOR_DISPLAY_NAME_REQUIRED',
        'EMPTY_USER_DISPLAY_NAME fail closed'
    );
    actorExpectCode(
        static function () use ($pdo, $admin): void {
            ActorIdentityService::create($pdo, $admin, 15);
        },
        'USER_INACTIVE',
        'INACTIVE_USER fail closed'
    );

    $selfApproval = SgkKararPaketiAuthz::denySelfApproval($ilker, 10);
    actorAssert(empty($selfApproval['ok']), 'SAME_PERSON_DUAL_CONTROL fail closed');

    echo 'verify-actor-identity-lifecycle: OK' . PHP_EOL;
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$database`");
}
