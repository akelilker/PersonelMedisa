<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/Services/PuantajDonemKilidiService.php';
require_once __DIR__ . '/../../api/src/Services/BildirimDonemContextService.php';
require_once __DIR__ . '/../../api/src/Services/DonemKapanisAuditService.php';
require_once __DIR__ . '/../../api/src/Services/DonemKapanisPreflightService.php';

use Medisa\Api\Services\DonemKapanisAuditService;
use Medisa\Api\Services\DonemKapanisPreflightService;
use Medisa\Api\Services\PuantajDonemKilidiService;

function closeMysqlPdo(): PDO
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

function assertCloseMysql(bool $condition, string $name): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function closeSignalReady(string $path): void
{
    file_put_contents($path, 'ready');
}

function closeChildStdoutToken(string $stdout): string
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

/** @return array{process: resource, pipes: array<int, resource>, signal: string} */
function closeSpawnChild(array $args): array
{
    $signal = tempnam(sys_get_temp_dir(), 'medisa-close-signal-');
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

function closeWaitReady(array $child): void
{
    $deadline = microtime(true) + 8;
    while (!is_file($child['signal'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Child did not acquire the lock in time.');
        }
        usleep(20000);
    }
}

function closeFinishChild(array $child): string
{
    $stdout = stream_get_contents($child['pipes'][1]);
    $stderr = stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $code = proc_close($child['process']);
    @unlink($child['signal']);
    if ($code !== 0) {
        throw new RuntimeException('Child failed: ' . trim($stderr . ' ' . $stdout));
    }

    return closeChildStdoutToken((string) $stdout);
}

function closeResetMysql(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'donem_kapanis_auditleri', 'gunluk_puantaj', 'test_adaylar', 'puantaj_aylik_muhurleri',
        'puantaj_donem_kilitleri', 'onayli_bildirim_puantaj_etki_adaylari',
    ] as $table) {
        $pdo->exec('TRUNCATE TABLE ' . $table);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function closeAttempt(PDO $pdo, int $subeId, int $yil, int $ay, int $actorId, int $holdMs = 0, ?callable $afterLock = null): string
{
    $pdo->beginTransaction();
    PuantajDonemKilidiService::acquire($pdo, $subeId, $yil, $ay);
    $existing = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = ? AND yil = ? AND ay = ?');
    $existing->execute([$subeId, $yil, $ay]);
    if ($existing->fetch()) {
        $pdo->commit();

        return 'IDEMPOTENT';
    }
    $preflight = DonemKapanisPreflightService::evaluate($pdo, $subeId, $yil, $ay);
    $requestHash = DonemKapanisAuditService::computeRequestHash(
        ['id' => $actorId],
        $subeId,
        $yil,
        $ay,
        [],
        (string) ($preflight['preflight_hash'] ?? '')
    );
    if ((int) ($preflight['blocker_count'] ?? 0) > 0) {
        DonemKapanisAuditService::recordBlocked($pdo, $preflight, ['id' => $actorId], $subeId, $yil, $ay, $requestHash);
        $pdo->commit();

        return 'BLOCKED';
    }
    if ($afterLock !== null) {
        $afterLock();
    }
    $donem = sprintf('%04d-%02d', $yil, $ay);
    $insert = $pdo->prepare('INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by) VALUES (?, ?, ?, ?, ?, 0, ?)');
    $insert->execute([$subeId, $yil, $ay, $donem, 'MUHURLENDI', $actorId]);
    $muhurId = (int) $pdo->lastInsertId();
    DonemKapanisAuditService::recordSuccess($pdo, $preflight, ['id' => $actorId], $subeId, $yil, $ay, $muhurId, $requestHash);
    if ($holdMs > 0) {
        usleep($holdMs * 1000);
    }
    $pdo->commit();

    return 'SEALED';
}

function closeChildMode(array $argv): void
{
    $action = $argv[2] ?? '';
    $pdo = closeMysqlPdo();
    if ($action === 'close-hold') {
        [$sube, $yil, $ay, $actor, $milliseconds, $signal] = array_slice($argv, 3, 6);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquire($pdo, (int) $sube, (int) $yil, (int) $ay);
        closeSignalReady($signal);
        usleep((int) $milliseconds * 1000);
        $existing = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = ? AND yil = ? AND ay = ?');
        $existing->execute([(int) $sube, (int) $yil, (int) $ay]);
        if ($existing->fetch()) {
            $pdo->commit();
            echo 'IDEMPOTENT' . PHP_EOL;
            return;
        }
        $preflight = DonemKapanisPreflightService::evaluate($pdo, (int) $sube, (int) $yil, (int) $ay);
        if ((int) ($preflight['blocker_count'] ?? 0) > 0) {
            $requestHash = DonemKapanisAuditService::computeRequestHash(['id' => (int) $actor], (int) $sube, (int) $yil, (int) $ay, [], (string) ($preflight['preflight_hash'] ?? ''));
            DonemKapanisAuditService::recordBlocked($pdo, $preflight, ['id' => (int) $actor], (int) $sube, (int) $yil, (int) $ay, $requestHash);
            $pdo->commit();
            echo 'BLOCKED' . PHP_EOL;
            return;
        }
        $donem = sprintf('%04d-%02d', (int) $yil, (int) $ay);
        $insert = $pdo->prepare('INSERT INTO puantaj_aylik_muhurleri (sube_id, yil, ay, donem, durum, muhurlenen_kayit_sayisi, created_by) VALUES (?, ?, ?, ?, ?, 0, ?)');
        $insert->execute([(int) $sube, (int) $yil, (int) $ay, $donem, 'MUHURLENDI', (int) $actor]);
        $muhurId = (int) $pdo->lastInsertId();
        $requestHash = DonemKapanisAuditService::computeRequestHash(['id' => (int) $actor], (int) $sube, (int) $yil, (int) $ay, [], (string) ($preflight['preflight_hash'] ?? ''));
        DonemKapanisAuditService::recordSuccess($pdo, $preflight, ['id' => (int) $actor], (int) $sube, (int) $yil, (int) $ay, $muhurId, $requestHash);
        $pdo->commit();
        echo 'SEALED' . PHP_EOL;
        return;
    }
    if ($action === 'candidate-apply') {
        [$candidateId, $subeId, $tarih, $signal, $holdMilliseconds] = array_slice($argv, 3, 5);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquireForDate($pdo, (int) $subeId, (string) $tarih);
        $stmt = $pdo->prepare('SELECT * FROM test_adaylar WHERE id = ? FOR UPDATE');
        $stmt->execute([(int) $candidateId]);
        $candidate = $stmt->fetch();
        closeSignalReady($signal);
        if (!$candidate) {
            $pdo->rollBack();
            echo 'MISSING' . PHP_EOL;
            return;
        }
        if ($candidate['state'] === 'UYGULANDI') {
            $pdo->commit();
            echo 'IDEMPOTENT' . PHP_EOL;
            return;
        }
        $seal = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = ? AND yil = ? AND ay = ?');
        $seal->execute([(int) $candidate['sube_id'], (int) substr((string) $candidate['tarih'], 0, 4), (int) substr((string) $candidate['tarih'], 5, 2)]);
        if ($seal->fetch()) {
            $pdo->rollBack();
            echo 'PERIOD_LOCKED' . PHP_EOL;
            return;
        }
        $insert = $pdo->prepare('INSERT INTO gunluk_puantaj (personel_id, sube_id, tarih, state, kontrol_durumu) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([(int) $candidate['personel_id'], (int) $candidate['sube_id'], $candidate['tarih'], 'ACIK', 'BEKLIYOR']);
        $update = $pdo->prepare('UPDATE test_adaylar SET state = ?, uygulanan_puantaj_id = ? WHERE id = ?');
        $update->execute(['UYGULANDI', (int) $pdo->lastInsertId(), (int) $candidateId]);
        usleep((int) $holdMilliseconds * 1000);
        $pdo->commit();
        echo 'APPLIED' . PHP_EOL;
        return;
    }
    if ($action === 'puantaj-upsert') {
        [$personelId, $subeId, $tarih, $signal, $holdMilliseconds] = array_slice($argv, 3, 5);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquireForDate($pdo, (int) $subeId, (string) $tarih);
        $seal = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = ? AND yil = ? AND ay = ?');
        $seal->execute([(int) $subeId, (int) substr((string) $tarih, 0, 4), (int) substr((string) $tarih, 5, 2)]);
        if ($seal->fetch()) {
            $pdo->rollBack();
            echo 'PERIOD_LOCKED' . PHP_EOL;
            return;
        }
        closeSignalReady($signal);
        $upsert = $pdo->prepare(
            'INSERT INTO gunluk_puantaj (personel_id, sube_id, tarih, state, kontrol_durumu, kaynak)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), kontrol_durumu = VALUES(kontrol_durumu)'
        );
        $upsert->execute([(int) $personelId, (int) $subeId, (string) $tarih, 'ACIK', 'BEKLIYOR', 'MANUEL']);
        usleep((int) $holdMilliseconds * 1000);
        $pdo->commit();
        echo 'UPSERTED' . PHP_EOL;
        return;
    }
    if ($action === 'amir-kontrol') {
        [$puantajId, $subeId, $tarih, $signal, $holdMilliseconds] = array_slice($argv, 3, 5);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquireForDate($pdo, (int) $subeId, (string) $tarih);
        $seal = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = ? AND yil = ? AND ay = ?');
        $seal->execute([(int) $subeId, (int) substr((string) $tarih, 0, 4), (int) substr((string) $tarih, 5, 2)]);
        if ($seal->fetch()) {
            $pdo->rollBack();
            echo 'PERIOD_LOCKED' . PHP_EOL;
            return;
        }
        closeSignalReady($signal);
        $update = $pdo->prepare('UPDATE gunluk_puantaj SET kontrol_durumu = ? WHERE id = ?');
        $update->execute(['AMIR_KONTROL_ETTI', (int) $puantajId]);
        usleep((int) $holdMilliseconds * 1000);
        $pdo->commit();
        echo 'CONTROLLED' . PHP_EOL;
        return;
    }
    if ($action === 'generate-candidate') {
        [$subeId, $yil, $ay, $signal, $holdMilliseconds] = array_slice($argv, 3, 5);
        $pdo->beginTransaction();
        PuantajDonemKilidiService::acquire($pdo, (int) $subeId, (int) $yil, (int) $ay);
        $seal = $pdo->prepare('SELECT id FROM puantaj_aylik_muhurleri WHERE sube_id = ? AND yil = ? AND ay = ?');
        $seal->execute([(int) $subeId, (int) $yil, (int) $ay]);
        if ($seal->fetch()) {
            $pdo->rollBack();
            echo 'PERIOD_LOCKED' . PHP_EOL;
            return;
        }
        closeSignalReady($signal);
        $insert = $pdo->prepare('INSERT INTO onayli_bildirim_puantaj_etki_adaylari (id, sube_id, ay, personel_id, tarih, state, uygulama_modu) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([99, (int) $subeId, sprintf('%04d-%02d', (int) $yil, (int) $ay), 7, '2026-05-15', 'HAZIR', 'OTOMATIK']);
        usleep((int) $holdMilliseconds * 1000);
        $pdo->commit();
        echo 'GENERATED' . PHP_EOL;
        return;
    }
    throw new RuntimeException('Unknown child action: ' . $action);
}

if (($argv[1] ?? '') === '--child') {
    closeChildMode($argv);
    exit(0);
}

$admin = closeMysqlPdo();
$database = 'medisa_s76_period_close_test';
$admin->exec('DROP DATABASE IF EXISTS ' . $database);
$admin->exec('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$dsn = getenv('MEDISA_TEST_MYSQL_DSN');
putenv('MEDISA_TEST_MYSQL_DSN=' . preg_replace('/dbname=[^;]*/', 'dbname=' . $database, $dsn));
$pdo = closeMysqlPdo();

try {
    $pdo->exec('CREATE TABLE subeler (
        id INT UNSIGNED NOT NULL PRIMARY KEY, kod VARCHAR(32) NOT NULL, ad VARCHAR(120) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO subeler (id, kod, ad) VALUES (1, \'MRK\', \'Merkez\'), (2, \'DEP\', \'Depolama\')');
    $pdo->exec('CREATE TABLE personeller (
        id INT UNSIGNED NOT NULL PRIMARY KEY, sube_id INT UNSIGNED NOT NULL,
        departman_id INT UNSIGNED NULL, aktif_durum VARCHAR(16) NOT NULL, maas_tutari DECIMAL(12,2) NULL
    ) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO personeller (id, sube_id, departman_id, aktif_durum, maas_tutari) VALUES (7, 1, 3, \'AKTIF\', 25000), (8, 1, 3, \'AKTIF\', NULL)');
    $migration = file_get_contents(__DIR__ . '/../../api/migrations/014_puantaj_donem_kilitleri.sql');
    $migration = preg_replace('/^\s*--.*$/m', '', (string) $migration);
    foreach (array_filter(array_map('trim', explode(';', (string) $migration))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    $pdo->exec('CREATE TABLE puantaj_aylik_muhurleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sube_id INT UNSIGNED NOT NULL,
        yil SMALLINT UNSIGNED NOT NULL, ay TINYINT UNSIGNED NOT NULL, donem CHAR(7) NOT NULL,
        durum VARCHAR(32) NOT NULL, muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
        created_by INT UNSIGNED NULL, UNIQUE KEY uniq_test_seal (sube_id, yil, ay)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE donem_kapanis_auditleri (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sube_id INT UNSIGNED NOT NULL,
        yil SMALLINT UNSIGNED NOT NULL,
        ay TINYINT UNSIGNED NOT NULL,
        action VARCHAR(40) NOT NULL,
        result_state VARCHAR(40) NOT NULL,
        muhur_id INT UNSIGNED NULL,
        blocker_count INT UNSIGNED NOT NULL DEFAULT 0,
        warning_count INT UNSIGNED NOT NULL DEFAULT 0,
        preflight_hash CHAR(64) NOT NULL,
        request_hash CHAR(64) NOT NULL,
        result_hash CHAR(64) NOT NULL,
        preflight_snapshot JSON NOT NULL,
        actor_user_id INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_dka_idempotency (sube_id, yil, ay, action, request_hash),
        KEY idx_dka_sube_donem_created (sube_id, yil, ay, created_at)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE gunluk_puantaj (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, personel_id INT UNSIGNED NOT NULL,
        sube_id INT UNSIGNED NOT NULL, tarih DATE NOT NULL, state VARCHAR(32) NOT NULL,
        kontrol_durumu VARCHAR(32) NOT NULL, kaynak VARCHAR(32) NOT NULL DEFAULT \'MANUEL\',
        aciklama TEXT NULL,
        UNIQUE KEY uniq_test_personel_tarih (personel_id, tarih)
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE test_adaylar (
        id INT UNSIGNED NOT NULL PRIMARY KEY, personel_id INT UNSIGNED NOT NULL, sube_id INT UNSIGNED NOT NULL,
        tarih DATE NOT NULL, state VARCHAR(32) NOT NULL, uygulanan_puantaj_id INT UNSIGNED NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE onayli_bildirim_puantaj_etki_adaylari (
        id INT UNSIGNED NOT NULL PRIMARY KEY, sube_id INT UNSIGNED NOT NULL, ay CHAR(7) NOT NULL,
        personel_id INT UNSIGNED NOT NULL, tarih DATE NOT NULL, state VARCHAR(32) NOT NULL,
        uygulama_modu VARCHAR(16) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE gunluk_bildirimler (
        id INT UNSIGNED NOT NULL PRIMARY KEY, personel_id INT UNSIGNED NOT NULL, tarih DATE NOT NULL,
        sube_id INT UNSIGNED NOT NULL, departman_id INT UNSIGNED NULL, state VARCHAR(32) NOT NULL,
        created_by INT UNSIGNED NULL, haftalik_mutabakat_id INT UNSIGNED NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE haftalik_bildirim_mutabakatlari (
        id INT UNSIGNED NOT NULL PRIMARY KEY, sube_id INT UNSIGNED NOT NULL, birim_amiri_user_id INT UNSIGNED NOT NULL,
        hafta_baslangic DATE NOT NULL, hafta_bitis DATE NOT NULL, state VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE aylik_bildirim_onaylari (
        id INT UNSIGNED NOT NULL PRIMARY KEY, sube_id INT UNSIGNED NOT NULL, birim_amiri_user_id INT UNSIGNED NOT NULL,
        ay CHAR(7) NOT NULL, ay_baslangic DATE NOT NULL, ay_bitis DATE NOT NULL, state VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE genel_yonetici_bildirim_onaylari (
        id INT UNSIGNED NOT NULL PRIMARY KEY, sube_id INT UNSIGNED NOT NULL, birim_amiri_user_id INT UNSIGNED NOT NULL,
        ay CHAR(7) NOT NULL, aylik_bildirim_onayi_id INT UNSIGNED NOT NULL, state VARCHAR(32) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE ek_odeme_kesinti (
        id INT UNSIGNED NOT NULL PRIMARY KEY, personel_id INT UNSIGNED NOT NULL, donem CHAR(7) NOT NULL, state VARCHAR(16) NOT NULL
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE aylik_ozet_satirlari (
        id INT UNSIGNED NOT NULL PRIMARY KEY, ay CHAR(7) NOT NULL, sube_id INT UNSIGNED NOT NULL, kapanis_durumu VARCHAR(16) NOT NULL
    ) ENGINE=InnoDB');

    closeResetMysql($pdo);
    $first = closeSpawnChild(['close-hold', '1', '2026', '5', '11', '700', '{SIGNAL}']);
    closeWaitReady($first);
    $second = closeSpawnChild(['close-hold', '1', '2026', '5', '12', '0', '{SIGNAL}']);
    $firstResult = closeFinishChild($first);
    $secondResult = closeFinishChild($second);
    assertCloseMysql(
        in_array('SEALED', [$firstResult, $secondResult], true)
        && in_array('IDEMPOTENT', [$firstResult, $secondResult], true),
        'parallel close leaves one seal and one idempotent result'
    );

    closeResetMysql($pdo);
    $pdo->exec("INSERT INTO test_adaylar VALUES (1, 7, 1, '2026-05-15', 'HAZIR', NULL)");
    $apply = closeSpawnChild(['candidate-apply', '1', '1', '2026-05-15', '{SIGNAL}', '500']);
    closeWaitReady($apply);
    $closePdo = closeMysqlPdo();
    $closeResult = closeAttempt($closePdo, 1, 2026, 5, 21);
    $applyResult = closeFinishChild($apply);
    assertCloseMysql($applyResult === 'APPLIED' && in_array($closeResult, ['BLOCKED', 'SEALED'], true), 'candidate apply vs close resolves deterministically');

    closeResetMysql($pdo);
    $pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, sube_id, tarih, state, kontrol_durumu) VALUES (55, 7, 1, '2026-05-15', 'ACIK', 'BEKLIYOR')");
    $upsert = closeSpawnChild(['puantaj-upsert', '7', '1', '2026-05-15', '{SIGNAL}', '500']);
    closeWaitReady($upsert);
    $closeResult = closeAttempt(closeMysqlPdo(), 1, 2026, 5, 22);
    $upsertResult = closeFinishChild($upsert);
    assertCloseMysql(in_array($upsertResult, ['UPSERTED', 'PERIOD_LOCKED'], true), 'puantaj upsert vs close is deterministic');

    closeResetMysql($pdo);
    $pdo->exec("INSERT INTO gunluk_puantaj (id, personel_id, sube_id, tarih, state, kontrol_durumu) VALUES (56, 7, 1, '2026-05-15', 'ACIK', 'BEKLIYOR')");
    $control = closeSpawnChild(['amir-kontrol', '56', '1', '2026-05-15', '{SIGNAL}', '500']);
    closeWaitReady($control);
    $closeResult = closeAttempt(closeMysqlPdo(), 1, 2026, 5, 23);
    $controlResult = closeFinishChild($control);
    assertCloseMysql(in_array($controlResult, ['CONTROLLED', 'PERIOD_LOCKED'], true), 'amir kontrol vs close is deterministic');

    closeResetMysql($pdo);
    $pdo->exec("INSERT INTO onayli_bildirim_puantaj_etki_adaylari (id, sube_id, ay, personel_id, tarih, state, uygulama_modu) VALUES (2, 1, '2026-05', 7, '2026-05-15', 'HAZIR', 'OTOMATIK')");
    $blocked = closeAttempt($pdo, 1, 2026, 5, 24);
    $blockedRetry = closeAttempt($pdo, 1, 2026, 5, 24);
    assertCloseMysql($blocked === 'BLOCKED' && $blockedRetry === 'BLOCKED', 'blocked close retries stay blocked');
    assertCloseMysql((int) $pdo->query('SELECT COUNT(*) FROM donem_kapanis_auditleri')->fetchColumn() === 1, 'blocked audit idempotency keeps one row');

    closeResetMysql($pdo);
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, sube_id, tarih, state, kontrol_durumu) VALUES (7, 1, '2026-05-15', 'ACIK', 'AMIR_KONTROL_ETTI')");
    $sealed = closeAttempt($pdo, 1, 2026, 5, 25);
    $retry = closeAttempt($pdo, 1, 2026, 5, 25);
    assertCloseMysql($sealed === 'SEALED' && $retry === 'IDEMPOTENT', 'seal retry is idempotent under concurrency protocol');

    closeResetMysql($pdo);
    $pdo->exec("INSERT INTO gunluk_puantaj (personel_id, sube_id, tarih, state, kontrol_durumu) VALUES (7, 1, '2026-05-15', 'ACIK', 'AMIR_KONTROL_ETTI')");
    $generate = closeSpawnChild(['generate-candidate', '1', '2026', '5', '{SIGNAL}', '500']);
    closeWaitReady($generate);
    $closeResult = closeAttempt(closeMysqlPdo(), 1, 2026, 5, 26);
    $generateResult = closeFinishChild($generate);
    assertCloseMysql(in_array($generateResult, ['GENERATED', 'PERIOD_LOCKED'], true), 'candidate generation race resolves deterministically');

    echo 'verify-donem-kapanis-mysql-concurrency: OK' . PHP_EOL;
} finally {
    $admin->exec('DROP DATABASE IF EXISTS ' . $database);
}
