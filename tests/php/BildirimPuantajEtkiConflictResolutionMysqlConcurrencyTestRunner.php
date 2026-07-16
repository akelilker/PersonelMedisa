<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/PuantajDonemKilidiService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiDecisionPolicy.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiProjectionService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiPuantajMapper.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiManualApplyService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiConflictClassificationService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimPuantajEtkiConflictResolutionService.php';

use Medisa\Api\Services\BildirimPuantajEtkiConflictResolutionService;
use Medisa\Api\Services\BildirimPuantajEtkiProjectionService;
use Medisa\Api\Services\BildirimPuantajEtkiPuantajMapper;
use Medisa\Api\Services\PuantajDonemKilidiService;

function conflictMysqlPdo(): PDO
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

function assertConflictMysql(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function conflictStdoutToken(string $stdout): string
{
    $lines = preg_split('/\R/', trim($stdout)) ?: [];
    $token = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'Warning:') === 0) {
            continue;
        }
        $token = $line;
    }

    return $token;
}

function signalConflictReady(string $path): void
{
    file_put_contents($path, 'ready');
}

function conflictSourceSnapshot(): array
{
    return [
        'gunluk_bildirim_id' => 901,
        'personel_id' => 1,
        'tarih' => '2026-06-04',
        'bildirim_turu' => 'GELMEDI',
        'bildirim_dakika' => null,
    ];
}

function conflictSeedFixture(PDO $pdo): array
{
    $snapshot = conflictSourceSnapshot();
    $hash = BildirimPuantajEtkiProjectionService::computeSourceHash($snapshot);
    $pdo->exec('DELETE FROM bildirim_puantaj_etki_cakisma_cozumleri');
    $pdo->exec('DELETE FROM onayli_bildirim_puantaj_etki_adaylari');
    $pdo->exec('DELETE FROM gunluk_puantaj');
    $pdo->exec('DELETE FROM puantaj_aylik_muhurleri');

    $pdo->exec("INSERT INTO gunluk_puantaj (
        id, personel_id, sube_id, tarih, state, gun_tipi, hareket_durumu, dayanak,
        durumu_bildirdi_mi, durum_bildirim_aciklamasi, hesap_etkisi,
        beklenen_giris_saati, beklenen_cikis_saati, giris_saati, cikis_saati,
        gec_kalma_dakika, erken_cikis_dakika, gercek_mola_dakika,
        hesaplanan_mola_dakika, net_calisma_suresi_dakika, gunluk_brut_sure_dakika,
        hafta_tatili_hak_kazandi_mi, kontrol_durumu, kaynak, aciklama, muhur_id, updated_at
    ) VALUES (
        55, 1, 1, '2026-06-04', 'ACIK', NULL, 'Geldi', 'Yok_Izinsiz',
        0, NULL, 'Tam_Yevmiye_Ver',
        '08:00:00', '17:00:00', '08:30:00', '17:30:00',
        NULL, NULL, 60,
        60, 480, 540,
        0, 'BEKLIYOR', 'MANUEL', 'baseline', NULL, '2026-06-10 08:00:00'
    )");

    $stmt = $pdo->prepare("INSERT INTO onayli_bildirim_puantaj_etki_adaylari (
        id, gunluk_bildirim_id, personel_id, sube_id, tarih, bildirim_turu, bildirim_dakika,
        state, etki_turu, etki_miktari, etki_birimi, conflict_code, bildirim_aciklama,
        source_snapshot, source_hash, projection_version, uygulama_modu
    ) VALUES (
        3, 901, 1, 1, '2026-06-04', 'GELMEDI', NULL,
        'INCELEME_GEREKLI', 'DEVAMSIZLIK_GUN', NULL, NULL, 'MEVCUT_PUANTAJ_VAR',
        'Gelmedi bildirimi', :snapshot, :hash, 'S74_V1', 'OTOMATIK'
    )");
    $stmt->execute([
        'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'hash' => $hash,
    ]);

    $puantaj = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    $aday = $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();

    return [
        'aday' => $aday,
        'puantaj' => $puantaj,
        'hash' => BildirimPuantajEtkiPuantajMapper::computeCurrentPuantajHash($puantaj),
        'gerekce' => 'Kontrollu S75 paralel revize kabul gerekcesi.',
    ];
}

function conflictResolveOnce(
    PDO $pdo,
    array $fixture,
    string $kararTuru,
    int $holdMs = 0,
    ?callable $afterLocks = null
): array {
    $pdo->beginTransaction();
    try {
        PuantajDonemKilidiService::acquireForDate($pdo, 1, '2026-06-04');
        if (PuantajDonemKilidiService::isSealed($pdo, [
            'sube_id' => 1,
            'yil' => 2026,
            'ay' => 6,
        ])) {
            $pdo->rollBack();

            return ['status' => 'conflict', 'code' => 'PERIOD_LOCKED'];
        }

        $adayStmt = $pdo->prepare('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3 FOR UPDATE');
        $adayStmt->execute();
        $aday = $adayStmt->fetch();
        $puantaj = BildirimPuantajEtkiConflictResolutionService::fetchPuantajForUpdate($pdo, 1, '2026-06-04');
        if ($afterLocks !== null) {
            $afterLocks();
        }
        if ($holdMs > 0) {
            usleep($holdMs * 1000);
        }

        $result = BildirimPuantajEtkiConflictResolutionService::resolve(
            $pdo,
            $aday,
            $puantaj ?: null,
            'INCELEME_GEREKLI',
            $kararTuru,
            $fixture['gerekce'],
            55,
            $fixture['hash'],
            5
        );

        $status = (string) ($result['status'] ?? '');
        if ($status === 'success') {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }

        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function conflictChildMode(array $argv): void
{
    $action = $argv[2] ?? '';
    $pdo = conflictMysqlPdo();

    if ($action === 'revise-service') {
        [$signal, $holdMilliseconds, $kararTuru] = array_slice($argv, 3, 3);
        $fixture = [
            'hash' => (string) ($argv[6] ?? ''),
            'gerekce' => (string) ($argv[7] ?? ''),
        ];
        $result = conflictResolveOnce(
            $pdo,
            $fixture,
            (string) $kararTuru,
            (int) $holdMilliseconds,
            static function () use ($signal): void {
                signalConflictReady($signal);
            }
        );
        $status = (string) ($result['status'] ?? '');
        if ($status === 'success') {
            echo 'REVISED' . PHP_EOL;
            return;
        }
        if ($status === 'idempotent') {
            echo 'IDEMPOTENT' . PHP_EOL;
            return;
        }
        echo (string) ($result['code'] ?? 'CONFLICT') . PHP_EOL;
        return;
    }

    if ($action === 'upsert-hold') {
        [$milliseconds, $signal] = array_slice($argv, 3, 2);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquireForDate($pdo, 1, '2026-06-04');
        $stmt = $pdo->prepare('SELECT * FROM gunluk_puantaj WHERE personel_id = 1 AND tarih = ? FOR UPDATE');
        $stmt->execute(['2026-06-04']);
        signalConflictReady($signal);
        usleep((int) $milliseconds * 1000);
        $update = $pdo->prepare("UPDATE gunluk_puantaj SET aciklama = 'upsert-race', updated_at = '2026-06-10 09:00:00' WHERE id = 55");
        $update->execute();
        $pdo->commit();
        echo 'UPSERTED' . PHP_EOL;
        return;
    }

    if ($action === 'seal-hold') {
        [$milliseconds, $signal] = array_slice($argv, 3, 2);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquire($pdo, 1, 2026, 6);
        $pdo->exec('INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay) VALUES (1, 2026, 6)');
        signalConflictReady($signal);
        usleep((int) $milliseconds * 1000);
        $pdo->commit();
        echo 'SEALED' . PHP_EOL;
        return;
    }

    throw new RuntimeException('Unknown child action: ' . $action);
}

/** @return array{process: resource, pipes: array<int, resource>, signal: string} */
function spawnConflictChild(array $args): array
{
    $signal = tempnam(sys_get_temp_dir(), 'medisa-conflict-signal-');
    if ($signal === false) {
        throw new RuntimeException('Signal file could not be created.');
    }
    unlink($signal);
    foreach ($args as &$arg) {
        if ($arg === '{SIGNAL}') {
            $arg = $signal;
        }
    }
    unset($arg);

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

    return ['process' => $process, 'pipes' => $pipes, 'signal' => $signal];
}

function waitConflictReady(array $child, int $timeoutMs = 10000): void
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    while (microtime(true) < $deadline) {
        if (is_file($child['signal']) && file_get_contents($child['signal']) === 'ready') {
            return;
        }
        usleep(20000);
    }
    throw new RuntimeException('Child did not become ready.');
}

function finishConflictChild(array $child): string
{
    $stdout = stream_get_contents($child['pipes'][1]);
    $stderr = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $code = proc_close($child['process']);
    @unlink($child['signal']);
    if ($code !== 0) {
        throw new RuntimeException('Child failed: ' . trim((string) $stderr));
    }

    return conflictStdoutToken((string) $stdout);
}

function createConflictSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE subeler (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE users (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE personeller (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO subeler (id) VALUES (1), (2)');
    $pdo->exec('INSERT INTO users (id) VALUES (5)');
    $pdo->exec('INSERT INTO personeller (id) VALUES (1)');

    $migration014 = file_get_contents(__DIR__ . '/../../api/migrations/014_puantaj_donem_kilitleri.sql');
    $migration014 = preg_replace('/^\s*--.*$/m', '', (string) $migration014);
    foreach (array_filter(array_map('trim', explode(';', (string) $migration014))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }

    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sube_id INT UNSIGNED NOT NULL,
        yil SMALLINT UNSIGNED NOT NULL,
        ay TINYINT UNSIGNED NOT NULL,
        UNIQUE KEY uniq_test_seal (sube_id, yil, ay)
    ) ENGINE=InnoDB');

    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        personel_id INT UNSIGNED NOT NULL,
        sube_id INT UNSIGNED NOT NULL,
        tarih DATE NOT NULL,
        state VARCHAR(32) NOT NULL,
        gun_tipi VARCHAR(64) NULL,
        hareket_durumu VARCHAR(64) NULL,
        dayanak VARCHAR(64) NULL,
        durumu_bildirdi_mi TINYINT(1) NULL,
        durum_bildirim_aciklamasi TEXT NULL,
        hesap_etkisi VARCHAR(64) NULL,
        beklenen_giris_saati TIME NULL,
        beklenen_cikis_saati TIME NULL,
        giris_saati TIME NULL,
        cikis_saati TIME NULL,
        gec_kalma_dakika INT NULL,
        erken_cikis_dakika INT NULL,
        gercek_mola_dakika INT NULL,
        hesaplanan_mola_dakika INT NULL,
        net_calisma_suresi_dakika INT NULL,
        gunluk_brut_sure_dakika INT NULL,
        hafta_tatili_hak_kazandi_mi TINYINT(1) NULL,
        kontrol_durumu VARCHAR(32) NULL,
        kaynak VARCHAR(64) NULL,
        aciklama TEXT NULL,
        muhur_id INT UNSIGNED NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY uniq_test_personel_tarih (personel_id, tarih)
    ) ENGINE=InnoDB');

    $pdo->exec('CREATE TABLE onayli_bildirim_puantaj_etki_adaylari (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        gunluk_bildirim_id INT UNSIGNED NOT NULL,
        personel_id INT UNSIGNED NOT NULL,
        sube_id INT UNSIGNED NOT NULL,
        tarih DATE NOT NULL,
        bildirim_turu VARCHAR(32) NOT NULL,
        bildirim_dakika INT NULL,
        state VARCHAR(32) NOT NULL,
        etki_turu VARCHAR(64) NOT NULL,
        etki_miktari INT NULL,
        etki_birimi VARCHAR(16) NULL,
        conflict_code VARCHAR(64) NULL,
        bildirim_aciklama TEXT NULL,
        source_snapshot JSON NULL,
        source_hash CHAR(64) NULL,
        projection_version VARCHAR(32) NULL,
        uygulama_modu VARCHAR(32) NULL,
        karar_veren_user_id INT UNSIGNED NULL,
        karar_zamani DATETIME NULL,
        karar_gerekcesi TEXT NULL,
        uygulanan_puantaj_id INT UNSIGNED NULL,
        onceki_puantaj_snapshot JSON NULL,
        sonraki_puantaj_snapshot JSON NULL,
        uygulama_hash CHAR(64) NULL
    ) ENGINE=InnoDB');

    $migration015 = file_get_contents(__DIR__ . '/../../api/migrations/015_bildirim_puantaj_etki_cakisma_cozumleri.sql');
    $migration015 = preg_replace('/^\s*--.*$/m', '', (string) $migration015);
    foreach (array_filter(array_map('trim', explode(';', (string) $migration015))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

if (($argv[1] ?? '') === '--child') {
    conflictChildMode($argv);
    exit(0);
}

$admin = conflictMysqlPdo();
$database = 'medisa_s75_conflict_resolution_test';
$admin->exec('DROP DATABASE IF EXISTS ' . $database);
$admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dsn = getenv('MEDISA_TEST_MYSQL_DSN');
putenv('MEDISA_TEST_MYSQL_DSN=' . preg_replace('/dbname=[^;]*/', 'dbname=' . $database, (string) $dsn));
$pdo = conflictMysqlPdo();
$june = null;
$july = null;
$sealPdo = null;

try {
    createConflictSchema($pdo);
    echo 'META ' . json_encode([
        'version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
        'engine' => $pdo->query('SELECT @@default_storage_engine')->fetchColumn(),
        'isolation' => $pdo->query('SELECT @@transaction_isolation')->fetchColumn(),
        'connections' => 2,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $pdo->beginTransaction();
    PuantajDonemKilidiService::acquire($pdo, 1, 2026, 6);
    $pdo->commit();
    $pdo->beginTransaction();
    PuantajDonemKilidiService::acquire($pdo, 1, 2026, 7);
    $pdo->commit();

    // 1) Parallel same revise
    $fixture = conflictSeedFixture($pdo);
    $first = spawnConflictChild([
        'revise-service',
        '{SIGNAL}',
        '400',
        'ADAY_ETKISIYLE_REVIZE_ET',
        $fixture['hash'],
        $fixture['gerekce'],
    ]);
    waitConflictReady($first);
    $second = spawnConflictChild([
        'revise-service',
        '{SIGNAL}',
        '0',
        'ADAY_ETKISIYLE_REVIZE_ET',
        $fixture['hash'],
        $fixture['gerekce'],
    ]);
    $firstResult = finishConflictChild($first);
    $secondResult = finishConflictChild($second);
    $aday = $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();
    $puantaj = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM bildirim_puantaj_etki_cakisma_cozumleri')->fetchColumn();
    assertConflictMysql(
        in_array('REVISED', [$firstResult, $secondResult], true)
        && in_array('IDEMPOTENT', [$firstResult, $secondResult], true)
        && $auditCount === 1
        && (string) $aday['state'] === 'UYGULANDI'
        && (string) $aday['uygulama_modu'] === 'CAKISMA_COZUM'
        && (int) $aday['uygulanan_puantaj_id'] === 55
        && (int) $puantaj['id'] === 55
        && (string) $puantaj['kaynak'] === 'BILDIRIM_ETKI_REVIZYON',
        'parallel same revise yields one update and one idempotent result'
    );

    // 2) Different karar race
    $fixture = conflictSeedFixture($pdo);
    $keepChild = spawnConflictChild([
        'revise-service',
        '{SIGNAL}',
        '400',
        'MEVCUT_PUANTAJI_KORU',
        $fixture['hash'],
        $fixture['gerekce'],
    ]);
    waitConflictReady($keepChild);
    $reviseChild = spawnConflictChild([
        'revise-service',
        '{SIGNAL}',
        '0',
        'ADAY_ETKISIYLE_REVIZE_ET',
        $fixture['hash'],
        $fixture['gerekce'],
    ]);
    $keepResult = finishConflictChild($keepChild);
    $reviseResult = finishConflictChild($reviseChild);
    $aday = $pdo->query('SELECT * FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();
    $puantaj = $pdo->query('SELECT * FROM gunluk_puantaj WHERE id = 55')->fetch();
    $auditCount = (int) $pdo->query('SELECT COUNT(*) FROM bildirim_puantaj_etki_cakisma_cozumleri')->fetchColumn();
    assertConflictMysql(
        $keepResult === 'REVISED'
        && $reviseResult === 'REVISION_DECISION_CONFLICT'
        && $auditCount === 1
        && (string) $aday['state'] === 'YOK_SAYILDI'
        && (string) $puantaj['kaynak'] === 'MANUEL',
        'different karar race commits one decision and conflicts the other'
    );

    // 3) Revise vs upsert: upsert first completes, revise sees stale hash
    $fixture = conflictSeedFixture($pdo);
    $upsert = spawnConflictChild(['upsert-hold', '400', '{SIGNAL}']);
    waitConflictReady($upsert);
    $stale = conflictResolveOnce($pdo, $fixture, 'ADAY_ETKISIYLE_REVIZE_ET');
    $upsertResult = finishConflictChild($upsert);
    assertConflictMysql(
        $upsertResult === 'UPSERTED'
        && ($stale['code'] ?? '') === 'PUANTAJ_STALE'
        && (int) $pdo->query('SELECT COUNT(*) FROM bildirim_puantaj_etki_cakisma_cozumleri')->fetchColumn() === 0,
        'revise sees stale hash after concurrent upsert'
    );

    // 4) Revise first, seal waits and snapshots updated row
    $fixture = conflictSeedFixture($pdo);
    $revise = spawnConflictChild([
        'revise-service',
        '{SIGNAL}',
        '500',
        'ADAY_ETKISIYLE_REVIZE_ET',
        $fixture['hash'],
        $fixture['gerekce'],
    ]);
    waitConflictReady($revise);
    $sealStarted = microtime(true);
    $sealPdo = conflictMysqlPdo();
    $sealPdo->beginTransaction();
    PuantajDonemKilidiService::acquire($sealPdo, 1, 2026, 6);
    $sealElapsed = microtime(true) - $sealStarted;
    $snapshotKaynak = (string) $sealPdo->query("SELECT kaynak FROM gunluk_puantaj WHERE id = 55")->fetchColumn();
    $sealPdo->exec('INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay) VALUES (1, 2026, 6)');
    $sealPdo->commit();
    $reviseResult = finishConflictChild($revise);
    assertConflictMysql(
        $reviseResult === 'REVISED'
        && $sealElapsed >= 0.35
        && $snapshotKaynak === 'BILDIRIM_ETKI_REVIZYON',
        'seal waits for revise and snapshots revised row'
    );

    // 5) Seal first, revise becomes PERIOD_LOCKED
    $fixture = conflictSeedFixture($pdo);
    $seal = spawnConflictChild(['seal-hold', '500', '{SIGNAL}']);
    waitConflictReady($seal);
    $locked = conflictResolveOnce($pdo, $fixture, 'ADAY_ETKISIYLE_REVIZE_ET');
    $sealResult = finishConflictChild($seal);
    $aday = $pdo->query('SELECT state, uygulanan_puantaj_id FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetch();
    assertConflictMysql(
        $sealResult === 'SEALED'
        && ($locked['code'] ?? '') === 'PERIOD_LOCKED'
        && (string) $aday['state'] === 'INCELEME_GEREKLI'
        && $aday['uygulanan_puantaj_id'] === null
        && (int) $pdo->query('SELECT COUNT(*) FROM bildirim_puantaj_etki_cakisma_cozumleri')->fetchColumn() === 0,
        'revise blocked by seal with PERIOD_LOCKED and no mutation'
    );

    // 6) Different periods independent
    $june = conflictMysqlPdo();
    $june->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($june, 1, '2026-06-04');
    $july = conflictMysqlPdo();
    $july->beginTransaction();
    PuantajDonemKilidiService::acquireForDate($july, 1, '2026-07-04');
    $june->commit();
    $july->commit();
    assertConflictMysql(true, 'different period tuples lock independently');

    // 7) Fail-closed without lock table
    $pdo->exec('DROP TABLE puantaj_donem_kilitleri');
    $fixture = conflictSeedFixture($pdo);
    $failClosed = false;
    try {
        conflictResolveOnce($pdo, $fixture, 'ADAY_ETKISIYLE_REVIZE_ET');
    } catch (Throwable $e) {
        $failClosed = true;
    }
    assertConflictMysql(
        $failClosed
        && (string) $pdo->query('SELECT state FROM onayli_bildirim_puantaj_etki_adaylari WHERE id = 3')->fetchColumn() === 'INCELEME_GEREKLI'
        && (int) $pdo->query('SELECT COUNT(*) FROM bildirim_puantaj_etki_cakisma_cozumleri')->fetchColumn() === 0,
        'missing lock table fails closed without revise mutation'
    );

    echo 'verify-bildirim-puantaj-etki-conflict-resolution-mysql-concurrency: OK' . PHP_EOL;
} finally {
    foreach ([$pdo, $june, $july, $sealPdo] as $handle) {
        if ($handle instanceof PDO && $handle->inTransaction()) {
            $handle->rollBack();
        }
    }
    $pdo = null;
    $june = null;
    $july = null;
    $sealPdo = null;
    gc_collect_cycles();

    // Fail-safe: MariaDB refuses DROP DATABASE while any session still uses it.
    $killStmt = $admin->prepare(
        'SELECT ID FROM information_schema.PROCESSLIST WHERE DB = ? AND ID <> CONNECTION_ID()'
    );
    $killStmt->execute([$database]);
    foreach ($killStmt->fetchAll(PDO::FETCH_COLUMN) as $processId) {
        $admin->exec('KILL ' . (int) $processId);
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . $database);
}
