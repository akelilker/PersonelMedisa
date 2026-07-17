<?php

declare(strict_types=1);

function migrationAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
$user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
$password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
if ($dsn === '' || $user === '') {
    throw new RuntimeException('Isolated MariaDB credentials are required.');
}
$admin = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'medisa_s77_migration_' . bin2hex(random_bytes(4));
$admin->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $testDsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $dsn);
    $pdo = new PDO($testDsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE TABLE subeler (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE users (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE personeller (
        id INT UNSIGNED PRIMARY KEY, maas_tutari DECIMAL(12,2) NULL,
        ise_giris_tarihi DATE NULL, sube_id INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");

    foreach (['018_personel_ucret_gecmisi.sql', '019_mevzuat_parametreleri.sql'] as $file) {
        $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
        if ($sql === false) {
            throw new RuntimeException('Migration okunamadi: ' . $file);
        }
        migrationAssert(!preg_match('/^\s*(DROP|TRUNCATE|DELETE)\b/im', $sql), $file . ' destructive SQL icermiyor');
        $pdo->exec($sql);
    }

    foreach ([
        'personel_ucret_gecmisi',
        'personel_ucret_auditleri',
        'mevzuat_parametreleri',
        'mevzuat_parametre_auditleri',
    ] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :db AND table_name = :table');
        $stmt->execute(['db' => $database, 'table' => $table]);
        migrationAssert((int) $stmt->fetchColumn() === 1, $table . ' tablosu olusturuldu');
    }

    $column = $pdo->query(
        "SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'personel_ucret_gecmisi' AND column_name = 'ucret_tutari'"
    )->fetch();
    migrationAssert(
        $column && $column['DATA_TYPE'] === 'decimal' && (int) $column['NUMERIC_PRECISION'] === 12 && (int) $column['NUMERIC_SCALE'] === 2,
        'ucret tutari DECIMAL(12,2)'
    );
    $legalColumn = $pdo->query(
        "SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'mevzuat_parametreleri' AND column_name = 'sayisal_deger'"
    )->fetch();
    migrationAssert(
        $legalColumn && $legalColumn['DATA_TYPE'] === 'decimal' && (int) $legalColumn['NUMERIC_PRECISION'] === 18 && (int) $legalColumn['NUMERIC_SCALE'] === 6,
        'mevzuat sayisal degeri DECIMAL(18,6)'
    );
    $legacy = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'personeller' AND column_name = 'maas_tutari'"
    )->fetchColumn();
    migrationAssert((int) $legacy === 1, 'legacy personeller.maas_tutari korundu');
    $indexes = $pdo->query(
        "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics
         WHERE table_schema = DATABASE() AND table_name = 'personel_ucret_gecmisi'
           AND index_name IN ('uq_pug_open_ended_aktif', 'uq_pug_personel_baslangic_state')"
    )->fetchColumn();
    migrationAssert((int) $indexes === 2, 'salary uniqueness indexes exist');
    $foreignKeys = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.referential_constraints
         WHERE constraint_schema = DATABASE()
           AND table_name IN ('personel_ucret_gecmisi', 'personel_ucret_auditleri')"
    )->fetchColumn();
    migrationAssert((int) $foreignKeys >= 7, 'salary foreign keys exist');

    echo 'verify-personel-ucret-migrations: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
