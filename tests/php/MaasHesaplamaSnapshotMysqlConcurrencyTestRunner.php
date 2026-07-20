<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/BildirimDonemContextService.php';
require_once __DIR__ . '/../../api/src/Services/PuantajDonemKilidiService.php';
require_once __DIR__ . '/../../api/src/Services/MaasHesaplamaException.php';
require_once __DIR__ . '/../../api/src/Services/PersonelBordroKapsamService.php';
require_once __DIR__ . '/../../api/src/Services/MaasHesaplamaSnapshotService.php';

use Medisa\Api\Services\MaasHesaplamaException;
use Medisa\Api\Services\MaasHesaplamaSnapshotService as Svc;

function mhsMysqlPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('Isolated MySQL test credentials are required.');
    }
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

    return $pdo;
}

function mhsAssert(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** @return array<int, string> */
function mhsSplitMigrationStatements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $inTrigger = false;
    foreach (preg_split('/\r?\n/', $sql) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        if (!$inTrigger && preg_match('/^CREATE\s+TRIGGER/i', $trimmed)) {
            $inTrigger = true;
        }
        $buffer .= $line . "\n";
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded ? (bool) preg_match('/^END\s+IF;$/i', $trimmed) : $endsWithSemicolon;
            if ($complete) {
                $statements[] = trim($buffer);
                $buffer = '';
                $inTrigger = false;
            }
            continue;
        }
        if ($endsWithSemicolon) {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

/** @return array{process: resource, pipes: array<int, resource>} */
function mhsSpawnChild(array $args): array
{
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows') {
        $extensionDir = ini_get('extension_dir');
        if (is_string($extensionDir) && $extensionDir !== '') {
            $phpArgs[] = '-d';
            $phpArgs[] = 'extension_dir=' . $extensionDir;
        }
        $phpArgs[] = '-d';
        $phpArgs[] = 'extension=pdo_mysql';
    }
    $command = array_merge([PHP_BINARY], $phpArgs, [__FILE__, '--child'], $args);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Child process could not be started.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

function mhsFinishChild(array $child): string
{
    $stdout = stream_get_contents($child['pipes'][1]);
    $stderr = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $code = proc_close($child['process']);
    if ($code !== 0) {
        throw new RuntimeException('Child failed: ' . trim($stderr . ' ' . $stdout));
    }
    $lines = preg_split('/\R/', trim((string) $stdout)) ?: [];
    $token = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && stripos($line, 'Warning:') !== 0) {
            $token = $line;
        }
    }

    return $token;
}

function mhsChildMode(array $argv): void
{
    $action = $argv[2] ?? '';
    $pdo = mhsMysqlPdo();
    $actor = ['id' => (int) ($argv[6] ?? 99), 'rol' => 'MUHASEBE'];

    if ($action === 'create') {
        [$sube, $yil, $ay] = [(int) $argv[3], (int) $argv[4], (int) $argv[5]];
        $mode = (string) ($argv[7] ?? 'fresh');
        $hash = $mode === 'stale'
            ? str_repeat('0', 64)
            : (string) Svc::buildPreflight($pdo, $sube, $yil, $ay)['preflight_hash'];
        try {
            $result = Svc::createSnapshot($pdo, $sube, $yil, $ay, $hash, $actor);
            echo ($result['idempotent'] ? 'EXISTING:' : 'CREATED:') . (int) $result['snapshot']['id'] . PHP_EOL;
        } catch (MaasHesaplamaException $e) {
            echo $e->getCodeString() . PHP_EOL;
        }

        return;
    }

    if ($action === 'cancel') {
        [$snapshotId, $neden] = [(int) $argv[3], (string) $argv[4]];
        try {
            $result = Svc::cancelSnapshot($pdo, $snapshotId, $neden, ['id' => 99, 'rol' => 'MUHASEBE']);
            echo ($result['idempotent'] ? 'CANCEL_IDEMPOTENT' : 'CANCELLED') . PHP_EOL;
        } catch (MaasHesaplamaException $e) {
            echo $e->getCodeString() . PHP_EOL;
        }

        return;
    }

    throw new RuntimeException('Unknown child action: ' . $action);
}

if (($argv[1] ?? '') === '--child') {
    mhsChildMode($argv);
    exit(0);
}

$admin = mhsMysqlPdo();
$database = 'medisa_s77c_snapshot_concurrency_test';
$admin->exec('DROP DATABASE IF EXISTS ' . $database);
$admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
putenv('MEDISA_TEST_MYSQL_DSN=' . preg_replace('/dbname=[^;]*/', 'dbname=' . $database, (string) getenv('MEDISA_TEST_MYSQL_DSN')));
$pdo = mhsMysqlPdo();

function mhsSeal(PDO $pdo, int $subeId, int $yil, int $ay, int $rowCount = 2): int
{
    $donem = sprintf('%04d-%02d', $yil, $ay);
    $pdo->prepare("INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by)
        VALUES (?, ?, ?, ?, 'MUHURLENDI', ?, 1)")->execute([$subeId, $yil, $ay, $donem, $rowCount]);
    $muhurId = (int) $pdo->lastInsertId();
    for ($i = 0; $i < $rowCount; $i++) {
        $personelId = $subeId === 1 ? ($i % 2 === 0 ? 7 : 8) : 9;
        $pdo->prepare("INSERT INTO puantaj_aylik_muhur_satirlari
            (muhur_id, personel_id, tarih, gun_tipi, kontrol_durumu, kaynak)
            VALUES (?, ?, ?, 'NORMAL', 'AMIR_KONTROL_ETTI', 'SISTEM')")
            ->execute([$muhurId, $personelId, sprintf('%s-%02d', $donem, 4 + $i)]);
    }

    return $muhurId;
}

function mhsReset(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'maas_hesaplama_snapshot_auditleri', 'maas_hesaplama_girdi_snapshotlari',
        'maas_hesaplama_personel_snapshotlari', 'maas_hesaplama_donem_snapshotlari',
        'puantaj_donem_kilitleri', 'mevzuat_parametreleri', 'ek_odeme_kesinti',
        'bildirim_puantaj_etki_cakisma_cozumleri', 'onayli_bildirim_puantaj_etki_adaylari',
        'puantaj_aylik_muhur_satirlari', 'puantaj_aylik_muhurleri', 'personel_ucret_gecmisi', 'surecler',
    ] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $pdo->exec("INSERT INTO personel_ucret_gecmisi (personel_id, ucret_tutari, ucret_turu, gecerlilik_baslangic, gecerlilik_bitis, state)
        VALUES (7, 30000, 'NET', '2025-01-01', NULL, 'AKTIF'),
               (8, 28000, 'NET', '2025-01-01', NULL, 'AKTIF'),
               (9, 27000, 'NET', '2025-01-01', NULL, 'AKTIF')");
}

try {
    // Base schema (FK hedefleri gercek migration kolon tipleriyle uyumlu)
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY, kod VARCHAR(32), ad VARCHAR(120)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE departmanlar (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(120)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE gorevler (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(120)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE personel_tipleri (id INT UNSIGNED NOT NULL PRIMARY KEY, ad VARCHAR(120)) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE personeller (
        id INT UNSIGNED NOT NULL PRIMARY KEY, tc_kimlik_no CHAR(11), ad VARCHAR(80), soyad VARCHAR(80),
        sicil_no VARCHAR(32), ise_giris_tarihi DATE, sube_id INT UNSIGNED NOT NULL,
        departman_id INT UNSIGNED NULL, gorev_id INT UNSIGNED NULL, personel_tipi_id INT UNSIGNED NULL,
        bagli_amir_id INT UNSIGNED NULL, aktif_durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
        ucret_tipi_id INT UNSIGNED NULL, maas_tutari DECIMAL(12,2) NULL, prim_kurali_id INT UNSIGNED NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE surecler (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, personel_id INT UNSIGNED NOT NULL,
        surec_turu VARCHAR(64) NOT NULL, alt_tur VARCHAR(64) NULL, baslangic_tarihi DATE NOT NULL,
        bitis_tarihi DATE NULL, ucretli_mi TINYINT(1) NOT NULL DEFAULT 0, aciklama TEXT NULL,
        state VARCHAR(32) NOT NULL DEFAULT 'AKTIF', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE personel_ucret_gecmisi (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, personel_id INT UNSIGNED NOT NULL,
        ucret_tutari DECIMAL(12,2) NOT NULL, ucret_turu VARCHAR(8) NOT NULL,
        para_birimi CHAR(3) NOT NULL DEFAULT 'TRY', gecerlilik_baslangic DATE NOT NULL,
        gecerlilik_bitis DATE NULL, state VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
        kaynak VARCHAR(40) NOT NULL DEFAULT 'MANUEL', aciklama VARCHAR(500) NULL,
        revision_no INT UNSIGNED NOT NULL DEFAULT 1
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE puantaj_aylik_muhurleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sube_id INT UNSIGNED NOT NULL,
        yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL, donem CHAR(7) NOT NULL,
        durum VARCHAR(32) NOT NULL DEFAULT 'MUHURLENDI', muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_test_seal (sube_id, yil, ay)
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE puantaj_aylik_muhur_satirlari (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, muhur_id INT UNSIGNED NOT NULL,
        personel_id INT UNSIGNED NOT NULL, tarih DATE NOT NULL, gun_tipi VARCHAR(40) NULL,
        hareket_durumu VARCHAR(40) NULL, dayanak VARCHAR(40) NULL, durumu_bildirdi_mi TINYINT(1) NULL,
        durum_bildirim_aciklamasi TEXT NULL, hesap_etkisi VARCHAR(40) NULL,
        beklenen_giris_saati VARCHAR(8) NULL, beklenen_cikis_saati VARCHAR(8) NULL,
        giris_saati VARCHAR(8) NULL, cikis_saati VARCHAR(8) NULL,
        gec_kalma_dakika INT UNSIGNED NULL, erken_cikis_dakika INT UNSIGNED NULL,
        gercek_mola_dakika INT UNSIGNED NULL, hesaplanan_mola_dakika INT UNSIGNED NULL,
        net_calisma_suresi_dakika INT UNSIGNED NULL, gunluk_brut_sure_dakika INT UNSIGNED NULL,
        hafta_tatili_hak_kazandi_mi TINYINT(1) NULL, kontrol_durumu VARCHAR(32) NOT NULL DEFAULT 'BEKLIYOR',
        kaynak VARCHAR(32) NULL, aciklama TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE onayli_bildirim_puantaj_etki_adaylari (
        id INT UNSIGNED NOT NULL PRIMARY KEY, sube_id INT UNSIGNED NOT NULL, ay CHAR(7) NOT NULL,
        personel_id INT UNSIGNED NOT NULL, tarih DATE NOT NULL,
        bildirim_turu VARCHAR(32) NOT NULL DEFAULT 'DIGER', bildirim_alt_tur VARCHAR(64) NULL,
        etki_turu VARCHAR(64) NOT NULL DEFAULT 'BILGI', etki_miktari INT UNSIGNED NULL,
        etki_birimi VARCHAR(16) NULL, state VARCHAR(32) NOT NULL, conflict_code VARCHAR(64) NULL,
        source_hash CHAR(64) NULL, mevcut_puantaj_id INT UNSIGNED NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE bildirim_puantaj_etki_cakisma_cozumleri (
        id INT UNSIGNED NOT NULL PRIMARY KEY, aday_id INT UNSIGNED NOT NULL,
        conflict_class VARCHAR(32) NOT NULL, karar_turu VARCHAR(64) NOT NULL, gerekce TEXT NULL,
        sonuc_hash CHAR(64) NOT NULL, karar_veren_user_id INT UNSIGNED NOT NULL, karar_zamani DATETIME NOT NULL
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE ek_odeme_kesinti (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, personel_id INT UNSIGNED NOT NULL,
        donem CHAR(7) NOT NULL, kalem_turu VARCHAR(32) NOT NULL, tutar DECIMAL(12,2) NOT NULL,
        gun_sayisi INT UNSIGNED NULL, aciklama TEXT NULL, state VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
        created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $pdo->exec("CREATE TABLE mevzuat_parametreleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, parametre_kodu VARCHAR(80) NOT NULL,
        deger_tipi VARCHAR(16) NOT NULL, sayisal_deger DECIMAL(18,6) NULL, metin_deger VARCHAR(255) NULL,
        birim VARCHAR(32) NULL, gecerlilik_baslangic DATE NOT NULL, gecerlilik_bitis DATE NULL,
        kaynak_referansi VARCHAR(255) NULL, state VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
        revision_no INT UNSIGNED NOT NULL DEFAULT 1
    ) ENGINE=InnoDB");

    $lockMigration = file_get_contents(__DIR__ . '/../../api/migrations/014_puantaj_donem_kilitleri.sql');
    $lockMigration = preg_replace('/^\s*--.*$/m', '', (string) $lockMigration);
    foreach (array_filter(array_map('trim', explode(';', (string) $lockMigration))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    foreach ([
        '020_maas_hesaplama_snapshotlari.sql',
        '021_maas_hesaplama_snapshot_guvenlik_indexleri.sql',
    ] as $file) {
        $sql = file_get_contents(__DIR__ . '/../../api/migrations/' . $file);
        foreach (mhsSplitMigrationStatements((string) $sql) as $statement) {
            $pdo->exec($statement);
        }
    }

    $pdo->exec("INSERT INTO subeler VALUES (1, 'MRK', 'Merkez'), (2, 'SB2', 'Sube 2')");
    $pdo->exec('INSERT INTO users VALUES (1), (11), (12), (13), (14), (15), (16), (17), (18), (19), (20), (99)');
    $pdo->exec("INSERT INTO personeller (id, tc_kimlik_no, ad, soyad, sicil_no, ise_giris_tarihi, sube_id)
        VALUES (7, '11111111111', 'Ali', 'Yilmaz', 'S007', '2020-01-01', 1),
               (8, '22222222222', 'Ayse', 'Demir', 'S008', '2020-01-01', 1),
               (9, '33333333333', 'Can', 'Kaya', 'S009', '2020-01-01', 2)");

    $actor = ['id' => 99, 'rol' => 'MUHASEBE'];

    // 1-2) Ayni donem paralel create (her child kendi fresh preflight'i ile)
    mhsReset($pdo);
    mhsSeal($pdo, 1, 2026, 3);
    $childA = mhsSpawnChild(['create', '1', '2026', '3', '11', 'fresh']);
    $childB = mhsSpawnChild(['create', '1', '2026', '3', '12', 'fresh']);
    $resultA = mhsFinishChild($childA);
    $resultB = mhsFinishChild($childB);
    $tokens = [$resultA, $resultB];
    $createdCount = count(array_filter($tokens, static fn (string $t) => strpos($t, 'CREATED:') === 0));
    $existingCount = count(array_filter($tokens, static fn (string $t) => strpos($t, 'EXISTING:') === 0));
    mhsAssert($createdCount === 1 && $existingCount === 1, 'paralel create tek kanonik snapshot + bir idempotent sonuc (' . implode(' / ', $tokens) . ')');
    $idA = (int) substr($resultA, strpos($resultA, ':') + 1);
    $idB = (int) substr($resultB, strpos($resultB, ':') + 1);
    mhsAssert($idA === $idB, 'iki paralel create ayni snapshot id dondurdu');
    mhsAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 1, 'duplicate snapshot satiri yok');
    $snapshotId = $idA;
    $girdiSayisi = (int) $pdo->query("SELECT girdi_sayisi FROM maas_hesaplama_donem_snapshotlari WHERE id = $snapshotId")->fetchColumn();
    mhsAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId")->fetchColumn() === $girdiSayisi, 'partial/duplicate child girdi yok');
    mhsAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_snapshot_auditleri WHERE aksiyon = 'SNAPSHOT_CREATE' AND sonuc = 'CREATED'")->fetchColumn() === 1, 'tek success audit');

    // 3) Snapshot create ile finans kaydi ekleme yarisi (TOCTOU korumasi)
    mhsReset($pdo);
    mhsSeal($pdo, 1, 2026, 4);
    $race = mhsMysqlPdo();
    $race->beginTransaction();
    $race->exec("INSERT INTO puantaj_donem_kilitleri (sube_id, yil, ay) VALUES (1, 2026, 4)
        ON DUPLICATE KEY UPDATE sube_id = sube_id");
    $race->query('SELECT * FROM puantaj_donem_kilitleri WHERE sube_id = 1 AND yil = 2026 AND ay = 4 FOR UPDATE')->fetchAll();
    $blockedChild = mhsSpawnChild(['create', '1', '2026', '4', '13', 'fresh']);
    usleep(400000);
    $race->exec("INSERT INTO ek_odeme_kesinti (personel_id, donem, kalem_turu, tutar) VALUES (7, '2026-04', 'PRIM', 999)");
    $race->commit();
    $blockedResult = mhsFinishChild($blockedChild);
    mhsAssert($blockedResult === 'PAYROLL_PREFLIGHT_STALE', 'finans yarisi stale preflight ile reddedildi');
    mhsAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 0, 'stale create snapshot birakmadi');
    $fresh = Svc::buildPreflight($pdo, 1, 2026, 4);
    $created = Svc::createSnapshot($pdo, 1, 2026, 4, (string) $fresh['preflight_hash'], $actor);
    $financeGirdi = (int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_girdi_snapshotlari
        WHERE donem_snapshot_id = " . (int) $created['snapshot']['id'] . " AND kaynak_turu = 'FINANS'")->fetchColumn();
    mhsAssert($financeGirdi === 1, 'guncel preflight sonrasi finans kaydi snapshot girdisine dahil');

    // 4) Ucret segmenti revision yarisi
    mhsReset($pdo);
    mhsSeal($pdo, 1, 2026, 5);
    $race = mhsMysqlPdo();
    $race->beginTransaction();
    $race->exec("INSERT INTO puantaj_donem_kilitleri (sube_id, yil, ay) VALUES (1, 2026, 5)
        ON DUPLICATE KEY UPDATE sube_id = sube_id");
    $race->query('SELECT * FROM puantaj_donem_kilitleri WHERE sube_id = 1 AND yil = 2026 AND ay = 5 FOR UPDATE')->fetchAll();
    $blockedChild = mhsSpawnChild(['create', '1', '2026', '5', '14', 'fresh']);
    usleep(400000);
    $race->exec("UPDATE personel_ucret_gecmisi SET gecerlilik_bitis = '2026-05-14', revision_no = 2 WHERE personel_id = 7");
    $race->exec("INSERT INTO personel_ucret_gecmisi (personel_id, ucret_tutari, ucret_turu, gecerlilik_baslangic, state)
        VALUES (7, 35000, 'NET', '2026-05-15', 'AKTIF')");
    $race->commit();
    $blockedResult = mhsFinishChild($blockedChild);
    mhsAssert($blockedResult === 'PAYROLL_PREFLIGHT_STALE', 'ucret revision yarisi stale preflight ile reddedildi');
    mhsAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 0, 'ucret yarisinda partial snapshot yok');

    // 5) Create sirasinda cancel yarisi + 9) stale hash
    mhsReset($pdo);
    mhsSeal($pdo, 1, 2026, 6);
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 6);
    $created = Svc::createSnapshot($pdo, 1, 2026, 6, (string) $preflight['preflight_hash'], $actor);
    $snapshotId = (int) $created['snapshot']['id'];
    $createChild = mhsSpawnChild(['create', '1', '2026', '6', '15', 'fresh']);
    $cancelChild = mhsSpawnChild(['cancel', (string) $snapshotId, 'Concurrency cancel yarisi']);
    $createResult = mhsFinishChild($createChild);
    $cancelResult = mhsFinishChild($cancelChild);
    mhsAssert(in_array($cancelResult, ['CANCELLED', 'CANCEL_IDEMPOTENT'], true), 'cancel yarisi deterministik tamamlandi');
    // Create tarafi: idempotent EXISTING, iptal sonrasi CREATED/STALE, veya
    // aktif snapshot gorulup fingerprint uyusmazliginda SOURCE_CHANGED.
    mhsAssert(
        strpos($createResult, 'EXISTING:') === 0
        || strpos($createResult, 'CREATED:') === 0
        || in_array($createResult, ['PAYROLL_PREFLIGHT_STALE', 'PAYROLL_SNAPSHOT_SOURCE_CHANGED'], true),
        'cancel yarisindaki create deterministik sonuc dondurdu (' . $createResult . ')'
    );
    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari WHERE sube_id = 1 AND yil = 2026 AND ay = 6 AND state = 'OLUSTURULDU'")->fetchColumn();
    mhsAssert($activeCount <= 1, 'cancel yarisi sonrasi en fazla bir aktif snapshot');
    // Yarismanin sonunda kanonik olarak ya tek aktif snapshot kalir ya da hic kalmaz;
    // partial child satiri olusmaz.
    $orphanGirdi = (int) $pdo->query(
        "SELECT COUNT(*) FROM maas_hesaplama_girdi_snapshotlari g
         LEFT JOIN maas_hesaplama_donem_snapshotlari d ON d.id = g.donem_snapshot_id
         WHERE d.id IS NULL"
    )->fetchColumn();
    mhsAssert($orphanGirdi === 0, 'cancel yarisi orphan girdi birakmadi');

    $staleResult = mhsFinishChild(mhsSpawnChild(['create', '1', '2026', '6', '16', 'stale']));
    mhsAssert(in_array($staleResult, ['PAYROLL_PREFLIGHT_STALE', 'PAYROLL_SNAPSHOT_SOURCE_CHANGED'], true) || strpos($staleResult, 'EXISTING:') === 0, 'stale hash create kanonik snapshot uretmedi (' . $staleResult . ')');

    // 6) Ayni snapshot'i paralel iptal
    mhsReset($pdo);
    mhsSeal($pdo, 1, 2026, 7);
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 7);
    $created = Svc::createSnapshot($pdo, 1, 2026, 7, (string) $preflight['preflight_hash'], $actor);
    $snapshotId = (int) $created['snapshot']['id'];
    $cancelA = mhsSpawnChild(['cancel', (string) $snapshotId, 'Paralel iptal']);
    $cancelB = mhsSpawnChild(['cancel', (string) $snapshotId, 'Paralel iptal']);
    $cancelTokens = [mhsFinishChild($cancelA), mhsFinishChild($cancelB)];
    mhsAssert(in_array('CANCELLED', $cancelTokens, true) && in_array('CANCEL_IDEMPOTENT', $cancelTokens, true), 'paralel iptal: bir gercek, bir idempotent');
    mhsAssert((int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_snapshot_auditleri WHERE aksiyon = 'SNAPSHOT_CANCEL'")->fetchColumn() === 1, 'paralel iptal duplicate audit uretmedi');
    $childCount = (int) $pdo->query("SELECT COUNT(*) FROM maas_hesaplama_girdi_snapshotlari WHERE donem_snapshot_id = $snapshotId")->fetchColumn();
    mhsAssert($childCount === (int) $created['snapshot']['girdi_sayisi'], 'iptal sonrasi child payloadlar korundu');

    // 7) Iptal sonrasi paralel revision create
    $revA = mhsSpawnChild(['create', '1', '2026', '7', '17', 'fresh']);
    $revB = mhsSpawnChild(['create', '1', '2026', '7', '18', 'fresh']);
    $revTokens = [mhsFinishChild($revA), mhsFinishChild($revB)];
    $revCreated = count(array_filter($revTokens, static fn (string $t) => strpos($t, 'CREATED:') === 0));
    $revExisting = count(array_filter($revTokens, static fn (string $t) => strpos($t, 'EXISTING:') === 0));
    mhsAssert($revCreated === 1 && $revExisting === 1, 'paralel revision create tek yeni revision uretti');
    $revisionRow = $pdo->query("SELECT revision_no, parent_snapshot_id FROM maas_hesaplama_donem_snapshotlari
        WHERE sube_id = 1 AND yil = 2026 AND ay = 7 AND state = 'OLUSTURULDU'")->fetch();
    mhsAssert((int) $revisionRow['revision_no'] === 2 && (int) $revisionRow['parent_snapshot_id'] === $snapshotId, 'revision_no 2 ve parent baglantisi dogru');

    // 8) Farkli sube ayni donem paralel create
    mhsReset($pdo);
    mhsSeal($pdo, 1, 2026, 8);
    mhsSeal($pdo, 2, 2026, 8, 1);
    $subeA = mhsSpawnChild(['create', '1', '2026', '8', '19', 'fresh']);
    $subeB = mhsSpawnChild(['create', '2', '2026', '8', '20', 'fresh']);
    $subeTokens = [mhsFinishChild($subeA), mhsFinishChild($subeB)];
    mhsAssert(
        count(array_filter($subeTokens, static fn (string $t) => strpos($t, 'CREATED:') === 0)) === 2,
        'farkli subeler ayni donemde paralel snapshot olusturabildi'
    );

    // 10) Transaction ortasinda hata: partial row kalmaz (BEFORE INSERT fail trigger)
    mhsReset($pdo);
    mhsSeal($pdo, 1, 2026, 9);
    $pdo->exec("CREATE TRIGGER trg_test_girdi_fail BEFORE INSERT ON maas_hesaplama_girdi_snapshotlari
        FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TEST_FAIL_INJECTION'");
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 9);
    $failed = false;
    try {
        Svc::createSnapshot(mhsMysqlPdo(), 1, 2026, 9, (string) $preflight['preflight_hash'], $actor);
    } catch (Throwable $e) {
        $failed = true;
    }
    $pdo->exec('DROP TRIGGER trg_test_girdi_fail');
    mhsAssert($failed, 'girdi insert hatasi create islemini durdurdu');
    mhsAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_donem_snapshotlari')->fetchColumn() === 0, 'hata sonrasi root satiri kalmadi');
    mhsAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_personel_snapshotlari')->fetchColumn() === 0, 'hata sonrasi partial personel satiri kalmadi');
    mhsAssert((int) $pdo->query('SELECT COUNT(*) FROM maas_hesaplama_girdi_snapshotlari')->fetchColumn() === 0, 'hata sonrasi partial girdi satiri kalmadi');

    // Immutability: gercek migration triggerlari servis verisi uzerinde calisiyor
    $preflight = Svc::buildPreflight($pdo, 1, 2026, 9);
    $created = Svc::createSnapshot($pdo, 1, 2026, 9, (string) $preflight['preflight_hash'], $actor);
    $snapshotId = (int) $created['snapshot']['id'];
    $immutableBlocked = false;
    try {
        $pdo->exec("UPDATE maas_hesaplama_girdi_snapshotlari SET payload_json = '{}' WHERE donem_snapshot_id = $snapshotId LIMIT 1");
    } catch (PDOException $e) {
        $immutableBlocked = strpos($e->getMessage(), 'PAYROLL_SNAPSHOT_IMMUTABLE') !== false;
    }
    mhsAssert($immutableBlocked, 'girdi payload UPDATE DB triggeri ile reddedildi');
    $deleteBlocked = false;
    try {
        $pdo->exec("DELETE FROM maas_hesaplama_personel_snapshotlari WHERE donem_snapshot_id = $snapshotId");
    } catch (PDOException $e) {
        $deleteBlocked = strpos($e->getMessage(), 'PAYROLL_SNAPSHOT_IMMUTABLE') !== false;
    }
    mhsAssert($deleteBlocked, 'personel snapshot DELETE DB triggeri ile reddedildi');
    $detail = Svc::getSnapshotDetail($pdo, $snapshotId);
    mhsAssert($detail !== null && $detail['hash_dogrulama']['dogrulandi'] === true, 'immutability denemeleri sonrasi hash dogrulaniyor');

    echo 'verify-maas-hesaplama-mysql-concurrency: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS ' . $database);
}
