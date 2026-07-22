<?php

declare(strict_types=1);

function sgkMigrationPdo(): PDO
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

/** @return array<int, string> */
function splitSgkMigration(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) !== ';') {
            continue;
        }
        if ($inTrigger || !$inTrigger) {
            $statements[] = trim($buffer);
            $buffer = '';
            $inTrigger = false;
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function applySgkMigration(PDO $pdo, string $file): void
{
    $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi: ' . $file);
    }
    foreach (splitSgkMigration($sql) as $statement) {
        $pdo->exec($statement);
    }
}

function migrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

$root = sgkMigrationPdo();
$database = 'medisa_sgk_' . bin2hex(random_bytes(5));
$root->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, getenv('MEDISA_TEST_MYSQL_DSN') ?: '');
    $pdo = new PDO((string) $dsn, getenv('MEDISA_TEST_MYSQL_USER') ?: '', getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(120) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE personeller (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(80) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE surecler (id INT UNSIGNED NOT NULL PRIMARY KEY, personel_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_donem_snapshotlari (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE maas_hesaplama_personel_snapshotlari (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO users VALUES (1)");
    $pdo->exec("INSERT INTO subeler VALUES (1, 'Merkez')");
    $pdo->exec("INSERT INTO personeller VALUES (7, 'Baseline Personel')");
    $pdo->exec("INSERT INTO surecler VALUES (70, 7)");
    $pdo->exec("INSERT INTO maas_hesaplama_donem_snapshotlari VALUES (10)");
    $pdo->exec("INSERT INTO maas_hesaplama_personel_snapshotlari VALUES (20)");
    $pdo->exec('CREATE TABLE personeller_restore_copy AS SELECT * FROM personeller');

    applySgkMigration($pdo, '036_sgk_prim_gunu_owner.sql');
    applySgkMigration($pdo, '037_sgk_resmi_kaynak_manifesti_v1.sql');
    migrationAssert((string) $pdo->query('SELECT ad FROM personeller WHERE id = 7')->fetchColumn() === 'Baseline Personel', 'additive apply mevcut personel verisini degistirmedi');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_kaynak_manifestleri')->fetchColumn() === 8, 'resmi kaynak manifesti sekiz dogrulanmis kaynak iceriyor');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM sgk_kaynak_manifestleri WHERE observed_at IS NOT NULL AND arsiv_kopyasi_repoda_mi = 0")->fetchColumn() === 8, 'manifest OBSERVED_AT tasir ve arsiv kopyasi yok');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'sgk_kaynak_manifestleri\' AND COLUMN_NAME = \'indirilen_dosya_byte\'')->fetchColumn() === 1, 'byte boyutu kolonu mevcut');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_eksik_gun_kodlari')->fetchColumn() === 0, 'dogrulanmamis eksik gun kodu seed edilmedi');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_sirket_politika_surumleri')->fetchColumn() === 0, 'null politika false varsayimina donusturulmedi');

    applySgkMigration($pdo, '036_sgk_prim_gunu_owner.sql');
    applySgkMigration($pdo, '037_sgk_resmi_kaynak_manifesti_v1.sql');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_kaynak_manifestleri')->fetchColumn() === 8, 'ikinci apply idempotent kaldi');

    $hash = str_repeat('a', 64);
    $pdo->exec("INSERT INTO maas_hesaplama_sgk_snapshotlari (
        donem_snapshot_id, personel_snapshot_id, personel_id,
        hesaplanan_prim_gunu, eksik_gun_sayisi,
        kaynak_surec_idleri_json, kaynak_puantaj_idleri_json, kaynak_belge_idleri_json,
        sgk_hesap_hash, gunluk_karar_dokumu_hash, gunluk_karar_dokumu_json,
        manuel_inceleme_gerekli_mi, blocker_kodlari_json, blocker_detaylari_json,
        ucret_modeli, ilk_iki_gun_politika_ozeti_json, sgk_odenek_durumu,
        is_goremezlik_finans_ozeti_json, source_hash
    ) VALUES (10, 20, 7, 30, 0, '[]', '[]', '[]', '$hash', '$hash', '[]', 0, '[]', '[]',
              'MAKTU_AYLIK', '[]', 'UYGULANMAZ', '[]', '$hash')");
    $immutableUpdate = false;
    try {
        $pdo->exec('UPDATE maas_hesaplama_sgk_snapshotlari SET hesaplanan_prim_gunu = 29 WHERE id = 1');
    } catch (PDOException $e) {
        $immutableUpdate = strpos($e->getMessage(), 'PAYROLL_SGK_SNAPSHOT_IMMUTABLE') !== false;
    }
    migrationAssert($immutableUpdate, 'SGK snapshot UPDATE immutable trigger ile reddedildi');

    $pdo->exec("INSERT INTO sgk_hesap_auditleri (
        donem_snapshot_id, personel_id, yil, ay, aksiyon, sonuc,
        request_hash, source_hash, result_hash, blocker_kodlari_json, actor_id
    ) VALUES (10, 7, 2026, 3, 'SNAPSHOT_CREATE', 'CREATED', '$hash', '$hash', '$hash', '[]', 1)");
    $immutableAuditUpdate = false;
    try {
        $pdo->exec("UPDATE sgk_hesap_auditleri SET sonuc = 'READ_ONLY' WHERE id = 1");
    } catch (PDOException $e) {
        $immutableAuditUpdate = strpos($e->getMessage(), 'PAYROLL_SGK_AUDIT_IMMUTABLE') !== false;
    }
    migrationAssert($immutableAuditUpdate, 'SGK audit UPDATE immutable trigger ile reddedildi');

    $pdo->exec("UPDATE personeller SET ad = 'Restore Test' WHERE id = 7");
    $pdo->exec('UPDATE personeller p INNER JOIN personeller_restore_copy b ON b.id = p.id SET p.ad = b.ad');
    migrationAssert((string) $pdo->query('SELECT ad FROM personeller WHERE id = 7')->fetchColumn() === 'Baseline Personel', 'disposable backup restore provasi baseline veriyi geri getirdi');

    echo 'verify-sgk-owner-migration-mysql: OK' . PHP_EOL;
} finally {
    unset($pdo);
    $root->exec("DROP DATABASE IF EXISTS `$database`");
}
