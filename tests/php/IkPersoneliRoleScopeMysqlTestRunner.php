<?php

declare(strict_types=1);

/**
 * MG-ORGANIZATION-HR-FINAL-CLOSEOUT-001 — migration 081 runtime acceptance
 * against a real MariaDB: the additive IK_PERSONELI role enum widening and the
 * append-only login-revocation audit owner.
 *
 * Everything runs on a disposable database created and dropped by this runner.
 *
 * php tests/php/IkPersoneliRoleScopeMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Database\UserOrgAssignmentSchema;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditContext;
use Medisa\Api\Services\Organizasyon\OrganizasyonAuditWriter;
use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;

function ikAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function ikPdo(string $dsn): PDO
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

function ikResetCaches(): void
{
    OrganizasyonSchema::resetCache();
    OrganizasyonAuditWriter::resetCache();
    UserOrgAssignmentSchema::resetCache();
}

function ikApplyMigration(PDO $pdo, string $file): void
{
    $sql = (string) file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    $pdo->exec($sql);
    ikResetCaches();
}

function ikScalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function ikContext(int $actorUserId, string $seed): OrganizasyonAuditContext
{
    return new OrganizasyonAuditContext($actorUserId, hash('sha256', $seed));
}

/** The pre-081 catalog: users.rol as migration 077 leaves it. */
function ikBaseSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(80) NOT NULL,
            ad_soyad VARCHAR(160) NOT NULL DEFAULT '',
            rol ENUM('GENEL_YONETICI','SISTEM_YONETICISI','SUBE_YONETICISI','BOLUM_YONETICISI','BIRIM_AMIRI','IK_SORUMLUSU','MUHASEBE','PERSONEL','AUTH_SMOKE_READONLY') NOT NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE sgk_isverenler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_sgk_isverenler_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(120) NOT NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_subeler_kod (kod),
            CONSTRAINT fk_subeler_sgk FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE calisma_lokasyonlari (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(160) NOT NULL,
            durum ENUM('AKTIF','PASIF') NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id),
            UNIQUE KEY uq_calisma_lokasyonlari_kod (kod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad_soyad VARCHAR(160) NOT NULL,
            sube_id INT UNSIGNED NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            calisma_lokasyonu_id INT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE user_subeler (
            user_id INT UNSIGNED NOT NULL,
            sube_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, sube_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
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

$db = 'medisa_ikrole_' . bin2hex(random_bytes(5));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$rootPdo = ikPdo($rootDsn);
$rootPdo->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = ikPdo($rootDsn . ';dbname=' . $db);
    ikBaseSchema($pdo);
    ikResetCaches();

    // ---------------------------------------------------------------------
    // Fail-closed before the migration.
    // ---------------------------------------------------------------------
    ikAssert(
        !OrganizasyonAuditWriter::isReady($pdo, OrganizasyonAuditWriter::USER_ACCESS_REVOKE_TABLE),
        'revocation audit reports not-ready before migration 081'
    );

    $notReady = null;
    try {
        OrganizasyonAuditWriter::assertReady($pdo, OrganizasyonAuditWriter::USER_ACCESS_REVOKE_TABLE);
    } catch (OrganizasyonException $exception) {
        $notReady = ['status' => $exception->httpStatus, 'code' => $exception->errorCode];
    }
    ikAssert(
        $notReady !== null
            && $notReady['status'] === 409
            && $notReady['code'] === OrganizasyonAuditWriter::SCHEMA_NOT_READY,
        'unauditable environment refuses the revocation write with 409'
    );

    $preRoleFailed = false;
    try {
        $pdo->exec("INSERT INTO users (username, rol) VALUES ('ik_early', 'IK_PERSONELI')");
    } catch (\Throwable $exception) {
        $preRoleFailed = true;
    }
    ikAssert($preRoleFailed, 'IK_PERSONELI is not storable before migration 081');

    // ---------------------------------------------------------------------
    // Migration chain.
    // ---------------------------------------------------------------------
    ikApplyMigration($pdo, '079_sirket_sube_hiyerarsisi.sql');
    ikApplyMigration($pdo, '080_organizasyon_audit_owners.sql');
    ikApplyMigration($pdo, '081_ik_personeli_rolu.sql');
    ikAssert(OrganizasyonSchema::isSchemaReady($pdo), 'migration chain 079 -> 081 up');

    $enumType = (string) ikScalar(
        $pdo,
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rol'"
    );
    ikAssert(
        $enumType === "enum('GENEL_YONETICI','SISTEM_YONETICISI','SUBE_YONETICISI','BOLUM_YONETICISI','BIRIM_AMIRI','IK_SORUMLUSU','IK_PERSONELI','MUHASEBE','PERSONEL','AUTH_SMOKE_READONLY')",
        'users.rol readback is the exact canonical catalog'
    );

    ikApplyMigration($pdo, '081_ik_personeli_rolu.sql');
    ikAssert(
        (string) ikScalar(
            $pdo,
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'rol'"
        ) === $enumType,
        'migration 081 is idempotent'
    );

    $pdo->exec("INSERT INTO users (id, username, ad_soyad, rol) VALUES (1, 'sorumlu', 'IK Sorumlusu', 'IK_SORUMLUSU')");
    $pdo->exec("INSERT INTO users (id, username, ad_soyad, rol) VALUES (2, 'personeli', 'IK Personeli', 'IK_PERSONELI')");
    ikAssert(
        (int) ikScalar($pdo, "SELECT COUNT(*) FROM users WHERE rol = 'IK_PERSONELI'") === 1,
        'IK_PERSONELI is storable and no role value was truncated'
    );
    ikAssert(
        (int) ikScalar($pdo, "SELECT COUNT(*) FROM users WHERE rol = '' OR rol IS NULL") === 0,
        'no blank role after the widening'
    );

    // ---------------------------------------------------------------------
    // Revocation audit owner.
    // ---------------------------------------------------------------------
    $auditId = OrganizasyonAuditWriter::recordUserAccessRevoke(
        $pdo,
        [
            'target_user_id' => 2,
            'target_username' => 'personeli',
            'onceki_durum' => 'AKTIF',
            'korunan_personel_id' => null,
            'temizlenen_scope_satiri' => 3,
        ],
        ikContext(1, 'revoke-1'),
        'Hesap kapatildi.'
    );
    ikAssert($auditId > 0, 'revocation audit row is written');

    $row = $pdo->query('SELECT * FROM user_erisim_kaldirma_auditleri WHERE id = ' . $auditId)
        ->fetch(PDO::FETCH_ASSOC);
    ikAssert(
        $row !== false
            && (int) $row['target_user_id'] === 2
            && (int) $row['actor_user_id'] === 1
            && $row['onceki_durum'] === 'AKTIF'
            && $row['yeni_durum'] === 'PASIF'
            && (int) $row['temizlenen_scope_satiri'] === 3
            && strlen((string) $row['request_hash']) === 64,
        'revocation audit records exact actor, preimage and postimage'
    );

    $updateBlocked = false;
    try {
        $pdo->exec('UPDATE user_erisim_kaldirma_auditleri SET onceki_durum = \'PASIF\' WHERE id = ' . $auditId);
    } catch (\Throwable $exception) {
        $updateBlocked = strpos($exception->getMessage(), 'ORG_AUDIT_IMMUTABLE') !== false;
    }
    ikAssert($updateBlocked, 'revocation audit row cannot be updated');

    $deleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM user_erisim_kaldirma_auditleri WHERE id = ' . $auditId);
    } catch (\Throwable $exception) {
        $deleteBlocked = strpos($exception->getMessage(), 'ORG_AUDIT_IMMUTABLE') !== false;
    }
    ikAssert($deleteBlocked, 'revocation audit row cannot be deleted');

    $userDeleteBlocked = false;
    try {
        $pdo->exec('DELETE FROM users WHERE id = 2');
    } catch (\Throwable $exception) {
        $userDeleteBlocked = true;
    }
    ikAssert(
        $userDeleteBlocked,
        'an audited account cannot be hard deleted, so past attribution survives'
    );

    // ---------------------------------------------------------------------
    // A failed audit write rolls the business mutation back with it.
    // ---------------------------------------------------------------------
    $pdo->beginTransaction();
    try {
        $pdo->exec("UPDATE users SET durum = 'PASIF' WHERE id = 2");
        OrganizasyonAuditWriter::recordUserAccessRevoke(
            $pdo,
            [
                'target_user_id' => 4242,
                'target_username' => 'yok',
                'onceki_durum' => 'AKTIF',
                'korunan_personel_id' => null,
                'temizlenen_scope_satiri' => 0,
            ],
            ikContext(1, 'revoke-rollback'),
            null
        );
        $pdo->commit();
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    ikAssert(
        (string) ikScalar($pdo, 'SELECT durum FROM users WHERE id = 2') === 'AKTIF',
        'audit failure rolls back the deactivation it could not explain'
    );

    echo 'IkPersoneliRoleScopeMysqlTestRunner: ALL PASS' . PHP_EOL;
} finally {
    $rootPdo->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
