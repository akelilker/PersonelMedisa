<?php

declare(strict_types=1);

/**
 * Focused MariaDB integrity for sube_sorumlu_yoneticiler semantics.
 *
 * Proves manager assignment is independent of user_subeler / home branch /
 * physical location / primary role. No production writes.
 *
 * php tests/php/SubeSorumluYoneticiMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use Medisa\Api\Services\Organizasyon\SubeSorumluYoneticiSchema;

function ssyAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function ssyPdo(string $dsn): PDO
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

$db = 'medisa_ssy_' . bin2hex(random_bytes(5));
$rootDsn = preg_replace('/;?dbname=[^;]*/i', '', $dsn) ?: $dsn;
$root = ssyPdo($rootDsn);
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $pdo = ssyPdo($rootDsn . ';dbname=' . $db);
    SubeSorumluYoneticiSchema::resetCache();

    $pdo->exec(
        "CREATE TABLE users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(64) NOT NULL,
            ad_soyad VARCHAR(120) NOT NULL DEFAULT '',
            rol VARCHAR(32) NOT NULL,
            durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
            personel_id INT UNSIGNED NULL,
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
        'CREATE TABLE user_subeler (
            user_id INT UNSIGNED NOT NULL,
            sube_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, sube_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        "CREATE TABLE personeller (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ad VARCHAR(80) NOT NULL,
            soyad VARCHAR(80) NOT NULL,
            sube_id INT UNSIGNED NULL,
            calisma_lokasyonu_id INT UNSIGNED NULL,
            sgk_isveren_id INT UNSIGNED NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        'CREATE TABLE sube_sorumlu_yoneticiler (
            sube_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (sube_id, user_id),
            CONSTRAINT fk_ssy_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE CASCADE,
            CONSTRAINT fk_ssy_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE sube_muhasebe_yetkilileri (
            sube_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (sube_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec("INSERT INTO departmanlar (id, ad) VALUES (1, 'Operasyon')");
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES
        (1, 'MRK', 'Fabrika', 'AKTIF'),
        (5, 'ANK', 'Ankara', 'AKTIF'),
        (6, 'IST', 'Istanbul', 'AKTIF'),
        (13, 'SKR', 'Sakarya', 'AKTIF')");
    $pdo->exec("INSERT INTO personeller (id, ad, soyad, sube_id, calisma_lokasyonu_id, sgk_isveren_id)
        VALUES (173, 'Sinem', 'Hamaloglu', 1, 5, 1)");
    $pdo->exec("INSERT INTO users (id, username, ad_soyad, rol, durum, personel_id) VALUES
        (110, 'sinemH', 'Sinem Hamaloglu', 'GENEL_YONETICI', 'AKTIF', 173),
        (50, '040', 'Halil Senay', 'BIRIM_AMIRI', 'AKTIF', NULL)");
    $pdo->exec('INSERT INTO user_subeler (user_id, sube_id) VALUES (110, 1)');

    SubeSorumluYoneticiSchema::resetCache();
    ssyAssert(SubeSorumluYoneticiSchema::isReady($pdo), 'schema ready');

    // D) zero-manager branch valid (no rows)
    ssyAssert(
        SubeSorumluYoneticiSchema::loadUserIdsForSube($pdo, 5) === [],
        'D zero-manager branch valid'
    );

    // A/B/C/G: Fabrika-based GENEL_YONETICI manages Ankara/Istanbul/Sakarya without role demotion
    // and without mutating home/location/user_subeler.
    $ankara = OrganizasyonService::updateSube($pdo, 5, [
        'sorumlu_yonetici_user_ids' => [110],
    ], null);
    ssyAssert(
        ($ankara['sorumlu_yonetici_user_ids'] ?? null) === [110],
        'A assign Fabrika-based manager to Ankara'
    );

    $istanbul = OrganizasyonService::updateSube($pdo, 6, [
        'sorumlu_yonetici_user_ids' => [110],
    ], null);
    $sakarya = OrganizasyonService::updateSube($pdo, 13, [
        'sorumlu_yonetici_user_ids' => [110],
    ], null);
    $fabrika = OrganizasyonService::updateSube($pdo, 1, [
        'sorumlu_yonetici_user_ids' => [110],
    ], null);

    ssyAssert(
        SubeSorumluYoneticiSchema::loadManagedSubeIdsForUser($pdo, 110) === [1, 5, 6, 13],
        'C one person manages multiple branches'
    );

    $personel = $pdo->query('SELECT sube_id, calisma_lokasyonu_id FROM personeller WHERE id = 173')->fetch(PDO::FETCH_ASSOC);
    ssyAssert((int) $personel['sube_id'] === 1, 'A home branch unchanged');
    ssyAssert((int) $personel['calisma_lokasyonu_id'] === 5, 'B physical location unchanged');

    $user = $pdo->query('SELECT rol FROM users WHERE id = 110')->fetch(PDO::FETCH_ASSOC);
    ssyAssert($user['rol'] === 'GENEL_YONETICI', 'G primary role unchanged (no multi-role / no demotion)');

    $scope = $pdo->query('SELECT sube_id FROM user_subeler WHERE user_id = 110 ORDER BY sube_id')->fetchAll(PDO::FETCH_COLUMN);
    ssyAssert(array_map('intval', $scope) === [1], 'F user_subeler access scope unchanged');

    // Optional multiple managers on one branch
    OrganizasyonService::updateSube($pdo, 5, [
        'sorumlu_yonetici_user_ids' => [110, 50],
    ], null);
    ssyAssert(
        SubeSorumluYoneticiSchema::loadUserIdsForSube($pdo, 5) === [50, 110],
        'branch may have multiple managers'
    );

    // E) removal does not transfer personnel / does not touch scope
    OrganizasyonService::updateSube($pdo, 5, [
        'sorumlu_yonetici_user_ids' => [],
    ], null);
    ssyAssert(SubeSorumluYoneticiSchema::loadUserIdsForSube($pdo, 5) === [], 'E removal clears manager fact');
    $personelAfter = $pdo->query('SELECT sube_id, calisma_lokasyonu_id FROM personeller WHERE id = 173')->fetch(PDO::FETCH_ASSOC);
    ssyAssert((int) $personelAfter['sube_id'] === 1 && (int) $personelAfter['calisma_lokasyonu_id'] === 5, 'E removal no personnel transfer');
    $scopeAfter = $pdo->query('SELECT sube_id FROM user_subeler WHERE user_id = 110 ORDER BY sube_id')->fetchAll(PDO::FETCH_COLUMN);
    ssyAssert(array_map('intval', $scopeAfter) === [1], 'E removal leaves user_subeler intact');
    $userAfter = $pdo->query('SELECT rol FROM users WHERE id = 110')->fetch(PDO::FETCH_ASSOC);
    ssyAssert($userAfter['rol'] === 'GENEL_YONETICI', 'E removal leaves primary role intact');

    echo 'verify-sube-sorumlu-yonetici-mysql: OK' . PHP_EOL;
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $db . '`');
}
