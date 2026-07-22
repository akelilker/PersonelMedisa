<?php

declare(strict_types=1);

function rttPdo(): PDO
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
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
}

/** @return array<int, string> */
function splitRttMigration(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) !== ';') {
            continue;
        }
        $statements[] = trim($buffer);
        $buffer = '';
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function applyRttMigration(PDO $pdo, string $file): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (splitRttMigration($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function rttAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$root = rttPdo();
$database = 'medisa_rtt_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $pdo = new PDO((string) $dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);

    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE personeller (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE gunluk_puantaj (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        personel_id INT UNSIGNED NOT NULL,
        tarih DATE NOT NULL,
        gun_tipi VARCHAR(40) NULL,
        UNIQUE KEY uq_gp (personel_id, tarih)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE puantaj_aylik_muhurleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sube_id INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE puantaj_aylik_muhur_satirlari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        muhur_id INT UNSIGNED NOT NULL,
        personel_id INT UNSIGNED NOT NULL,
        tarih DATE NOT NULL,
        gun_tipi VARCHAR(40) NULL,
        UNIQUE KEY uq_pams (muhur_id, personel_id, tarih),
        CONSTRAINT fk_pams_muhur FOREIGN KEY (muhur_id) REFERENCES puantaj_aylik_muhurleri (id)
    ) ENGINE=InnoDB");
    $pdo->exec('INSERT INTO users VALUES (1)');
    $pdo->exec("INSERT INTO personeller VALUES (1, 'Baseline')");
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, tarih, gun_tipi) VALUES (1, '2026-04-23', 'UBGT_Resmi_Tatil')");
    $pdo->exec('INSERT INTO puantaj_aylik_muhurleri VALUES (1, 1)');
    $pdo->exec("INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, gun_tipi) VALUES (1, 1, '2026-04-23', 'UBGT_Resmi_Tatil')");

    applyRttMigration($pdo, '039_ubgt_gun_kapsami_tatil_takvimi.sql');
    rttAssert(
        (string) $pdo->query("SELECT gun_tipi FROM gunluk_puantaj WHERE id = 1")->fetchColumn() === 'UBGT_Resmi_Tatil',
        '039 additive apply mevcut puantaj satirini degistirmedi'
    );
    rttAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'resmi_tatil_takvimi'")->fetchColumn() === 1,
        'resmi_tatil_takvimi tablosu olustu'
    );
    rttAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'gunluk_puantaj' AND column_name = 'tatil_gun_kapsami'")->fetchColumn() === 1,
        'gunluk_puantaj tatil_gun_kapsami eklendi'
    );
    rttAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'puantaj_aylik_muhur_satirlari' AND column_name = 'tatil_siniflandirma_durumu'")->fetchColumn() === 1,
        'muhur satirlari tatil_siniflandirma_durumu eklendi'
    );

    // Idempotent second apply
    applyRttMigration($pdo, '039_ubgt_gun_kapsami_tatil_takvimi.sql');
    rttAssert(true, '039 ikinci apply idempotent');

    // No seed dates
    rttAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM resmi_tatil_takvimi')->fetchColumn() === 0,
        '039 production/seed tatil tarihi yazmadi'
    );

    // TAM_GUN ok
    $pdo->exec("INSERT INTO resmi_tatil_takvimi (
        tarih, tatil_kodu, tatil_adi, tatil_turu, gun_kapsami, durum, kaynak_turu, kaynak_referansi, yapan_kullanici_id
    ) VALUES (
        '2026-04-23', 'TEST_UBGT', 'Test', 'UBGT', 'TAM_GUN', 'AKTIF', 'TEST', 'ref-1', 1
    )");
    rttAssert(true, 'TAM_GUN aktif UBGT kaydi eklenebilir');

    // Unique aktif UBGT ayni tarih
    $dupFailed = false;
    try {
        $pdo->exec("INSERT INTO resmi_tatil_takvimi (
            tarih, tatil_kodu, tatil_adi, tatil_turu, gun_kapsami, durum, kaynak_turu, kaynak_referansi, yapan_kullanici_id
        ) VALUES (
            '2026-04-23', 'TEST_UBGT2', 'Test2', 'UBGT', 'TAM_GUN', 'AKTIF', 'TEST', 'ref-2', 1
        )");
    } catch (Throwable $e) {
        $dupFailed = true;
    }
    rttAssert($dupFailed, 'ayni tarihte ikinci aktif UBGT reddedilir');

    // YARIM_GUN interval zorunlu
    $halfFailed = false;
    try {
        $pdo->exec("INSERT INTO resmi_tatil_takvimi (
            tarih, tatil_kodu, tatil_adi, tatil_turu, gun_kapsami, durum, kaynak_turu, kaynak_referansi, yapan_kullanici_id
        ) VALUES (
            '2026-05-01', 'HALF', 'Half', 'UBGT', 'YARIM_GUN', 'TASLAK', 'TEST', 'ref-h', 1
        )");
    } catch (Throwable $e) {
        $halfFailed = true;
    }
    rttAssert($halfFailed, 'YARIM_GUN intervalsiz reddedilir');

    $pdo->exec("INSERT INTO resmi_tatil_takvimi (
        tarih, tatil_kodu, tatil_adi, tatil_turu, gun_kapsami,
        tatil_interval_baslangic, tatil_interval_bitis,
        durum, kaynak_turu, kaynak_referansi, yapan_kullanici_id
    ) VALUES (
        '2026-05-01', 'HALF', 'Half', 'UBGT', 'YARIM_GUN',
        '13:00:00', '23:59:59',
        'TASLAK', 'TEST', 'ref-h', 1
    )");
    rttAssert(true, 'YARIM_GUN gecerli interval kabul edilir');

    echo 'verify-rtt-039-migration-mysql: OK' . PHP_EOL;
} finally {
    $root->exec("DROP DATABASE IF EXISTS `$database`");
}
