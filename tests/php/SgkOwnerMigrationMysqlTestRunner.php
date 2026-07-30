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

    applySgkMigration($pdo, '040_sgk_mevzuat_canonical_schema.sql');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'aktiflik_durumu'")->fetchColumn() === 1, '040 aktiflik_durumu kolonu');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'sifir_gun_sifir_kazanc_durumu'")->fetchColumn() === 1, '040 sifir_gun canonical kolonu');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'belge_saklama_ibraz_durumu'")->fetchColumn() === 1, '040 belge saklama kolonu');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'yabanci_kullanim_durumu'")->fetchColumn() === 1, '040 yabanci kullanim kolonu');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'portal_teyit_durumu'")->fetchColumn() === 1, '040 portal teyit kolonu');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'mevzuat_kurallari_json'")->fetchColumn() === 1, '040 mevzuat_kurallari_json kolonu');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'belge_zorunlulugu'")->fetchColumn() === 1, 'legacy belge_zorunlulugu korunur');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'aktif_mi'")->fetchColumn() === 1, 'legacy aktif_mi korunur');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_eksik_gun_kodlari')->fetchColumn() === 0, '040 katalog kodu seed etmedi');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM sgk_kaynak_manifestleri WHERE kaynak_id = 'SGK_EKSIK_GUN_BELGELERI_20180417' AND durum = 'AKTIF' AND belge_tarihi = '2018-04-17'")->fetchColumn() === 1, '040 dogru tarihli kaynak aktif');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM sgk_kaynak_manifestleri WHERE kaynak_id = 'SGK_EKSIK_GUN_BELGELERI_20221116' AND durum = 'PASIF'")->fetchColumn() === 1, '040 eski kaynak PASIF');
    $yerine = (int) $pdo->query("SELECT yerine_gecen_kaynak_id FROM sgk_kaynak_manifestleri WHERE kaynak_id = 'SGK_EKSIK_GUN_BELGELERI_20221116'")->fetchColumn();
    $yeniId = (int) $pdo->query("SELECT id FROM sgk_kaynak_manifestleri WHERE kaynak_id = 'SGK_EKSIK_GUN_BELGELERI_20180417'")->fetchColumn();
    migrationAssert($yerine === $yeniId && $yeniId > 0, '040 yerine_gecen_kaynak_id baglantisi');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_kaynak_manifestleri')->fetchColumn() === 9, '040 sonrasi dokuz manifest (8+1 replacement)');

    applySgkMigration($pdo, '036_sgk_prim_gunu_owner.sql');
    applySgkMigration($pdo, '037_sgk_resmi_kaynak_manifesti_v1.sql');
    applySgkMigration($pdo, '040_sgk_mevzuat_canonical_schema.sql');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_kaynak_manifestleri')->fetchColumn() === 9, 'ikinci apply 040 dahil idempotent kaldi');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM sgk_kaynak_manifestleri WHERE kaynak_id = 'SGK_EKSIK_GUN_BELGELERI_20221116' AND durum = 'PASIF'")->fetchColumn() === 1, 'ikinci apply eski kaynak PASIF kaldi');

    // Seed one catalog row before 042 to prove additive apply does not delete.
    $seedHash = str_repeat('b', 64);
    $pdo->exec("INSERT INTO sgk_eksik_gun_katalog_surumleri
        (surum_kodu, gecerlilik_baslangic, gecerlilik_bitis, tamlik_durumu, state, manifest_set_hash, aciklama)
        VALUES ('S106_PRE_042', '2020-01-01', NULL, 'TASLAK', 'TASLAK', '$seedHash', 'pre-042 baseline')");
    $preSurumId = (int) $pdo->query("SELECT id FROM sgk_eksik_gun_katalog_surumleri WHERE surum_kodu = 'S106_PRE_042'")->fetchColumn();
    $ek9Id = (int) $pdo->query("SELECT id FROM sgk_kaynak_manifestleri WHERE kaynak_id = 'SGK_EK9_APHB_20260722'")->fetchColumn();
    $pdo->exec("INSERT INTO sgk_eksik_gun_kodlari
        (katalog_surum_id, eksik_gun_kodu, resmi_aciklama, gecerlilik_baslangic, gecerlilik_bitis,
         kaynak_manifest_id, belge_zorunlulugu, sifir_gun_sifir_kazanc_kullanilabilir_mi,
         kismi_sureli_sozlesme_gerekli_mi, tek_basina_kullanilabilir_mi, diger_nedenlerle_birlikte_kullanim,
         aktif_mi)
        VALUES ($preSurumId, '01', 'Istirahat', '2020-01-01', NULL,
         $ek9Id, 'KOSULLU', 0, 0, 1, 'KOSULLU', 0)");
    $preKodCount = (int) $pdo->query('SELECT COUNT(*) FROM sgk_eksik_gun_kodlari')->fetchColumn();

    applySgkMigration($pdo, '042_sgk_resmi_kaynakli_kisitli_katalog.sql');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'gecerlilik_tarih_durumu'")->fetchColumn() === 1, '042 gecerlilik_tarih_durumu');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'ilk_resmi_kanit_tarihi'")->fetchColumn() === 1, '042 ilk_resmi_kanit_tarihi');
    $nullable = (string) $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_kodlari' AND COLUMN_NAME = 'gecerlilik_baslangic'")->fetchColumn();
    migrationAssert($nullable === 'YES', '042 gecerlilik_baslangic NULL olabilir');
    $enumTamlik = (string) $pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_katalog_surumleri' AND COLUMN_NAME = 'tamlik_durumu'")->fetchColumn();
    migrationAssert(strpos($enumTamlik, 'RESMI_KAYNAKLI_KISITLI') !== false && strpos($enumTamlik, 'DOGRULANMIS_TAM') !== false, '042 tamlik enum RESMI_KAYNAKLI_KISITLI icerir');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_eksik_gun_kodlari')->fetchColumn() === $preKodCount, '042 mevcut kod satirlari silinmedi');

    applySgkMigration($pdo, '042_sgk_resmi_kaynakli_kisitli_katalog.sql');
    migrationAssert((int) $pdo->query('SELECT COUNT(*) FROM sgk_eksik_gun_kodlari')->fetchColumn() === $preKodCount, '042 ikinci apply idempotent; veri korunur');
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sgk_eksik_gun_katalog_surumleri' AND COLUMN_NAME = 'katalog_payload_hash'")->fetchColumn() === 1, '042 katalog_payload_hash');

    // ONAYLANDI + RESMI_KAYNAKLI_KISITLI check constraint kabul
    $pdo->exec("UPDATE sgk_eksik_gun_katalog_surumleri SET tamlik_durumu = 'RESMI_KAYNAKLI_KISITLI', state = 'ONAY_BEKLIYOR' WHERE id = $preSurumId");
    $pdo->exec("UPDATE sgk_eksik_gun_katalog_surumleri SET state = 'ONAYLANDI', onaylayan_id = 1, onay_zamani = '2026-07-31 00:00:00',
        resmi_kaynaklar_incelendi_mi = 1, belirsiz_tarihler_uydurulmadi_mi = 1, kisitli_kullanim_kabul_edildi_mi = 1
        WHERE id = $preSurumId");
    migrationAssert((string) $pdo->query("SELECT state FROM sgk_eksik_gun_katalog_surumleri WHERE id = $preSurumId")->fetchColumn() === 'ONAYLANDI', '042 ONAYLANDI + RESMI_KAYNAKLI_KISITLI kabul');

    // NULL baslangic insert
    $pdo->exec("INSERT INTO sgk_eksik_gun_kodlari
        (katalog_surum_id, eksik_gun_kodu, resmi_aciklama, gecerlilik_baslangic, gecerlilik_bitis, gecerlilik_tarih_durumu,
         kaynak_manifest_id, belge_zorunlulugu, sifir_gun_sifir_kazanc_kullanilabilir_mi,
         kismi_sureli_sozlesme_gerekli_mi, tek_basina_kullanilabilir_mi, diger_nedenlerle_birlikte_kullanim,
         aktif_mi)
        VALUES ($preSurumId, '03', 'Disiplin cezasi', NULL, NULL, 'BELIRLENEMEDI',
         $ek9Id, 'KOSULLU', 0, 0, 1, 'KOSULLU', 0)");
    migrationAssert((int) $pdo->query("SELECT COUNT(*) FROM sgk_eksik_gun_kodlari WHERE eksik_gun_kodu = '03' AND gecerlilik_baslangic IS NULL")->fetchColumn() === 1, '042 NULL gecerlilik_baslangic insert');

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
