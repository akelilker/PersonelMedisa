<?php

declare(strict_types=1);

/**
 * Focused MariaDB integrity for sube_muhasebe_yetkilileri + atomic branch ACL replace.
 *
 * php tests/php/BranchAccountingVisibilityMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Organizasyon\OrganizasyonException;
use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use Medisa\Api\Services\Organizasyon\SubeMuhasebeYetkiSchema;

function bavAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function bavPdo(string $dsn): PDO
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

$db = 'medisa_bav_' . bin2hex(random_bytes(5));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = bavPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = bavPdo($rootDsn . ';dbname=' . $db);
    SubeMuhasebeYetkiSchema::resetCache();

    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(64) NOT NULL,
            ad_soyad VARCHAR(120) NOT NULL DEFAULT '',
            rol VARCHAR(32) NOT NULL,
            durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE subeler (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            kod VARCHAR(32) NOT NULL,
            ad VARCHAR(120) NOT NULL,
            durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE departmanlar (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(80) NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE sube_departmanlar (
            sube_id INT UNSIGNED NOT NULL,
            departman_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (sube_id, departman_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE sube_muhasebe_yetkilileri (
            sube_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (sube_id, user_id),
            CONSTRAINT fk_bav_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE CASCADE,
            CONSTRAINT fk_bav_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'Muhasebe')");
    $pdo->exec("INSERT INTO users (id, username, ad_soyad, rol, durum) VALUES
        (10, 'muhasebe', 'Muhasebe User', 'MUHASEBE', 'AKTIF'),
        (11, 'muhasebe2', 'Muhasebe Two', 'MUHASEBE', 'AKTIF'),
        (13, 'pasifMuh', 'Pasif Muh', 'MUHASEBE', 'PASIF')");

    SubeMuhasebeYetkiSchema::resetCache();
    bavAssert(SubeMuhasebeYetkiSchema::isReady($pdo), 'schema ready');

    // Flat create path avoids OrganizasyonSchema hierarchy readiness for this focused ACL test.
    $created = OrganizasyonService::createSube($pdo, [
        'kod' => 'MRK',
        'ad' => 'Fabrika',
        'durum' => 'AKTIF',
        'departman_ids' => [1],
        'muhasebe_kisit_aktif' => true,
        'muhasebe_yetkili_user_ids' => [10, 11],
    ], null);
    bavAssert((int) $created['id'] > 0, 'branch created');
    bavAssert($created['muhasebe_kisit_aktif'] === true, 'restriction enabled on create');
    bavAssert($created['muhasebe_yetkili_user_ids'] === [10, 11], 'multi accountants stored');

    $denied = false;
    try {
        OrganizasyonService::updateSube($pdo, $created['id'], [
            'ad' => 'Fabrika',
            'durum' => 'AKTIF',
            'departman_ids' => [1],
            'muhasebe_kisit_aktif' => true,
            'muhasebe_yetkili_user_ids' => [13],
        ], null);
    } catch (OrganizasyonException $e) {
        $denied = $e->errorCode === 'VALIDATION_ERROR';
    }
    bavAssert($denied, 'inactive accountant cannot be newly selected');
    $still = SubeMuhasebeYetkiSchema::loadUserIdsForSube($pdo, (int) $created['id']);
    bavAssert($still === [10, 11], 'failed validation leaves ACL unchanged');

    $cleared = OrganizasyonService::updateSube($pdo, $created['id'], [
        'ad' => 'Fabrika',
        'durum' => 'AKTIF',
        'departman_ids' => [1],
        'muhasebe_kisit_aktif' => false,
        'muhasebe_yetkili_user_ids' => [10],
    ], null);
    bavAssert($cleared['muhasebe_kisit_aktif'] === false, 'unchecked disables restriction');
    bavAssert($cleared['muhasebe_yetkili_user_ids'] === [], 'unchecked clears selected ids');

    echo "ALL_PASS\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
