<?php

declare(strict_types=1);

/**
 * Mükerrer hesap raporu (t900 B): yalnız okur, T.C. çıktıya konmaz, üç kaynak gruplanır.
 */

require_once __DIR__ . '/../../api/src/Services/Auth/MukerrerHesapRaporuService.php';

use Medisa\Api\Services\Auth\MukerrerHesapRaporuService;

function mhAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
$pass = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
if ($dsn === '' || $user === '') {
    throw new RuntimeException('Disposable MariaDB credentials are required.');
}
$root = new PDO($dsn, $user, $pass, $opts);
$db = 'medisa_mukerrer_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new PDO((string) preg_replace('/dbname=[^;]+/', 'dbname=' . $db, $dsn), $user, $pass, $opts);
    $pdo->exec('CREATE TABLE personeller (id INT PRIMARY KEY, tc_kimlik_no VARCHAR(11) NULL)');
    $pdo->exec('CREATE TABLE actor_identities (id INT PRIMARY KEY, personel_id INT NULL, status VARCHAR(20) NOT NULL)');
    $pdo->exec('CREATE TABLE users (id INT PRIMARY KEY, username VARCHAR(64) NOT NULL, durum VARCHAR(10) NOT NULL, personel_id INT NULL, actor_identity_id INT NULL)');
    $pdo->exec("INSERT INTO personeller VALUES (1,'11111111111'),(2,'11111111111'),(3,'22222222222'),(4,'33333333333'),(5,'44444444444'),(6,'55555555555')");
    $pdo->exec("INSERT INTO actor_identities VALUES (50, 5, 'VERIFIED')");
    $pdo->exec("INSERT INTO users VALUES
        (10,'a1','AKTIF',1,NULL),(11,'a2','PASIF',2,NULL),
        (12,'b1','AKTIF',3,NULL),(13,'b2','AKTIF',3,NULL),
        (14,'c1','AKTIF',4,NULL),
        (15,'d1','AKTIF',5,NULL),(16,'d2','AKTIF',6,50)");

    $before = $pdo->query('CHECKSUM TABLE users, personeller, actor_identities')->fetchAll();
    $report = MukerrerHesapRaporuService::scan($pdo);
    $after = $pdo->query('CHECKSUM TABLE users, personeller, actor_identities')->fetchAll();

    mhAssert($before === $after, 'MUKERRER_READ_ONLY veri değişmez');
    mhAssert($report['read_only'] === true && $report['totals']['grup'] === 3, 'MUKERRER_GROUP_COUNT 3');
    $by = [];
    foreach ($report['gruplar'] as $g) {
        $by[$g['kaynak']] = $g;
    }
    mhAssert(array_column($by['PERSONEL_KAYDI']['hesaplar'], 'id') === [12, 13], 'MUKERRER_PERSONEL_KAYDI 12+13');
    mhAssert(array_column($by['TC_ESLESME']['hesaplar'], 'id') === [10, 11] && $by['TC_ESLESME']['personel_ids'] === [1, 2], 'MUKERRER_TC_ESLESME 10+11');
    mhAssert(array_column($by['KIMLIK']['hesaplar'], 'id') === [15, 16], 'MUKERRER_KIMLIK 15+16');
    $json = json_encode($report);
    mhAssert(strpos((string) $json, '11111111111') === false && strpos((string) $json, 'tc_kimlik') === false, 'MUKERRER_NO_TC çıktıda T.C. yok');
    echo 'verify-mukerrer-hesap-raporu: OK' . PHP_EOL;
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$db`");
}
