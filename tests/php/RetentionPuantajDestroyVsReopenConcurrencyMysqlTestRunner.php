<?php

declare(strict_types=1);

/**
 * Pack 3B follow-up: destroy vs reopen period-lock concurrency.
 * Exactly one wins — never both EXECUTED destruction and a new active reopen.
 *
 * php tests/php/RetentionPuantajDestroyVsReopenConcurrencyMysqlTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\PuantajDonemKilidiService;
use Medisa\Api\Services\PuantajDonemPeriodService;
use Medisa\Api\Services\PuantajDonemReopenException;
use Medisa\Api\Services\PuantajDonemReopenService;
use Medisa\Api\Services\Retention\ArchiveManifestService;
use Medisa\Api\Services\Retention\DestructionWorkflowService;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionService;
use Medisa\Api\Services\Retention\PhysicalDestruction\PuantajPhysicalDestructionGate;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\Retention\RetentionClock;

function dvrAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function dvrRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        echo "SKIP: Disposable MariaDB credentials are required.\n";
        exit(0);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function dvrPdoForDb(string $db): PDO
{
    $dsn = (string) getenv('MEDISA_TEST_MYSQL_DSN');
    $user = (string) getenv('MEDISA_TEST_MYSQL_USER');
    $password = (string) (getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '');
    if (stripos($dsn, 'dbname=') !== false) {
        $dsn = preg_replace('/dbname=[^;]+/i', 'dbname=' . $db, $dsn) ?: $dsn;
    } else {
        $dsn .= (substr($dsn, -1) === ';' ? '' : ';') . 'dbname=' . $db;
    }
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 15');

    return $pdo;
}

/** @return list<string> */
function dvrSplitSql(string $sql): array
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
        $endsWithSemicolon = substr($trimmed, -1) === ';';
        if ($inTrigger) {
            $isGuarded = (bool) preg_match('/\bTHEN\b/i', $buffer);
            $complete = $isGuarded
                ? (bool) preg_match('/^END\s+IF;$/i', $trimmed)
                : $endsWithSemicolon;
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

function dvrApply(PDO $pdo, string $file): void
{
    $sql = file_get_contents($file);
    if ($sql === false) {
        throw new RuntimeException('Cannot read ' . $file);
    }
    foreach (dvrSplitSql($sql) as $stmt) {
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
    }
}

/** @return list<string> */
function dvrMigrationFiles(): array
{
    $dir = realpath(__DIR__ . '/../../api/migrations');
    if ($dir === false) {
        throw new RuntimeException('migrations missing');
    }
    $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    $files = array_values(array_filter(
        $files,
        static fn(string $file): bool => basename($file) !== '067_personel_canonical_reference_gate.sql'
            && basename($file) !== '068_sgk_actor_identity_lifecycle_audit.sql'
            && basename($file) !== '069_personel_credential_onboarding.sql'
    ));
    sort($files, SORT_STRING);

    return $files;
}

function dvrHash(): string
{
    return str_repeat('b', 64);
}

function dvrNonce(): string
{
    return bin2hex(random_bytes(32));
}

function dvrFlagOn(): void
{
    putenv('MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED=1');
    $_ENV['MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED'] = '1';
}

/** @return array{id:int,rol:string} */
function dvrGm(): array
{
    return ['id' => 1, 'rol' => 'GENEL_YONETICI'];
}

/**
 * @return array{process: resource, pipes: array<int, resource>}
 */
function dvrSpawn(array $args, string $db): array
{
    $phpArgs = [];
    if (PHP_OS_FAMILY === 'Windows' && !extension_loaded('pdo_mysql')) {
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
    // Explicit env only — do not rely on getenv() merge (CI/Linux proc_open replaces env).
    $env = [
        'PATH' => (string) (getenv('PATH') ?: ''),
        'SystemRoot' => (string) (getenv('SystemRoot') ?: ''),
        'MEDISA_TEST_MYSQL_DSN' => (string) getenv('MEDISA_TEST_MYSQL_DSN'),
        'MEDISA_TEST_MYSQL_USER' => (string) getenv('MEDISA_TEST_MYSQL_USER'),
        'MEDISA_TEST_MYSQL_PASSWORD' => (string) (getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: ''),
        'MEDISA_DVR_DB' => $db,
        'MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED' => '1',
    ];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $env
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Child process could not start.');
    }
    fclose($pipes[0]);

    return ['process' => $process, 'pipes' => $pipes];
}

function dvrFinish(array $child): string
{
    $stdout = trim((string) stream_get_contents($child['pipes'][1]));
    $stderr = trim((string) stream_get_contents($child['pipes'][2]));
    fclose($child['pipes'][1]);
    fclose($child['pipes'][2]);
    $status = proc_close($child['process']);
    if ($status !== 0 && $stdout === '') {
        throw new RuntimeException('Child failed: ' . $stderr);
    }
    $lines = preg_split("/\r\n|\n|\r/", $stdout) ?: [];
    $meaningful = array_values(array_filter($lines, static function (string $line): bool {
        $line = trim($line);

        return $line !== '' && stripos($line, 'Warning:') !== 0;
    }));

    return $meaningful === [] ? '' : (string) end($meaningful);
}

if (($argv[1] ?? '') === '--child') {
    $db = (string) (getenv('MEDISA_DVR_DB') ?: '');
    $action = (string) ($argv[2] ?? '');
    $signal = (string) ($argv[3] ?? '');
    $pdo = dvrPdoForDb($db);
    dvrFlagOn();
    RetentionClock::setOverride('2037-01-01');

    if ($signal !== '' && $signal !== '-') {
        $deadline = microtime(true) + 15;
        while (!is_file($signal) && microtime(true) < $deadline) {
            usleep(20000);
        }
        if (!is_file($signal)) {
            fwrite(STDERR, "signal timeout\n");
            exit(2);
        }
    }

    try {
        if ($action === 'destroy') {
            $talepId = (int) ($argv[4] ?? 0);
            $planHash = (string) ($argv[5] ?? '');
            $res = PhysicalDestructionService::execute($pdo, dvrGm(), $talepId, [
                'expected_plan_hash' => $planHash,
                'execution_nonce' => dvrNonce(),
                'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
            ]);
            echo 'DESTROY:' . (string) ($res['execution']['code'] ?? '?') . PHP_EOL;
        } elseif ($action === 'reopen') {
            $yil = (int) ($argv[4] ?? 0);
            $ay = (int) ($argv[5] ?? 0);
            // Prefer seeing latest committed destroy evidence after period-lock wait.
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            try {
                PuantajDonemReopenService::createReopenRequest(
                    $pdo,
                    dvrGm(),
                    1,
                    $yil,
                    $ay,
                    'concurrency reopen race'
                );
                $pdo->commit();
                echo 'REOPEN:OK' . PHP_EOL;
            } catch (PuantajDonemReopenException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                echo 'REOPEN:' . $e->getErrorCode() . PHP_EOL;
            }
        } else {
            throw new RuntimeException('unknown action');
        }
        exit(0);
    } catch (Throwable $e) {
        echo 'ERR:' . $e->getMessage() . PHP_EOL;
        exit(0);
    }
}

$root = dvrRootPdo();
$database = 'medisa_dvr_conc_' . substr(bin2hex(random_bytes(4)), 0, 8);
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = dvrPdoForDb($database);

try {
    foreach (dvrMigrationFiles() as $file) {
        dvrApply($pdo, $file);
    }
    $hash = password_hash('DvrConcTestPass-24chars!!', PASSWORD_BCRYPT);
    $pdo->exec("INSERT INTO subeler (id, kod, ad, durum) VALUES (1, 'A', 'Sube A', 'AKTIF')");
    $pdo->exec(
        "INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
         (1, 'genel', '{$hash}', 'Genel Yon', 'GENEL_YONETICI', 'AKTIF')"
    );
    $pdo->exec(
        "INSERT INTO personeller (
            id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
            sicil_no, ise_giris_tarihi, sube_id, aktif_durum
         ) VALUES
         (10, '11111111111', 'Aktif', 'Personel', '1990-01-01', '05000000000', 'Acil', '05000000001',
            'S001', '2010-01-01', 1, 'AKTIF')"
    );

    RetentionClock::setOverride('2037-01-01');
    dvrFlagOn();

    $yil = 2015;
    $ay = 6;
    $donem = '2015-06';
    $h = dvrHash();
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri
            (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
         VALUES (1, {$yil}, {$ay}, 1, '{$donem}', 'MUHURLENDI', 1, 1, '2015-06-28 10:00:00')"
    );
    $sealId = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '2015-06-15', 'MUHURLENDI', 'BEKLIYOR', {$sealId})"
    );
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES ({$sealId}, 10, '2015-06-15', 'BEKLIYOR')"
    );
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari (
            sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
            state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
            personel_sayisi, girdi_sayisi, created_by
         ) VALUES (
            1, {$yil}, {$ay}, '{$donem}', '2015-06-01', '2015-06-30', {$sealId}, 1,
            'OLUSTURULDU', '2015-06-28 12:00:00', '{$h}', '{$h}', '{$h}',
            1, 0, 1
         )"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, $yil, $ay, $sealId, 1);

    $req = DestructionWorkflowService::requestDestruction($pdo, dvrGm(), [
        'category' => RetentionCategories::PUANTAJ,
        'entity_type' => 'puantaj',
        'record_id' => $sealId,
        'sube_id' => 1,
        'yil' => $yil,
        'ay' => $ay,
        'reason' => 'concurrency destroy',
    ]);
    $ap = DestructionWorkflowService::approveDestruction(
        $pdo,
        dvrGm(),
        (int) $req['item']['id'],
        'GM',
        true
    );
    $eval = PhysicalDestructionService::evaluate($pdo, dvrGm(), (int) $ap['id']);
    $planHash = (string) ($eval['plan']['plan_hash'] ?? '');
    dvrAssert($planHash !== '', 'plan hash ready');
    $talepRow = DestructionWorkflowService::getById($pdo, (int) $ap['id']);
    dvrAssert(
        (int) ($talepRow['canonical_sube_id'] ?? 0) === 1
            && (int) ($talepRow['period_yil'] ?? 0) === $yil
            && (int) ($talepRow['period_ay'] ?? 0) === $ay,
        'destruction talep has canonical period snapshot'
    );

    // Sequential: destroy first → reopen blocked by destroyed gate
    $resDestroyFirst = PhysicalDestructionService::execute($pdo, dvrGm(), (int) $ap['id'], [
        'expected_plan_hash' => $planHash,
        'execution_nonce' => dvrNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    dvrAssert(
        ($resDestroyFirst['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'sequential destroy-first EXECUTED'
    );
    $pdo->beginTransaction();
    try {
        PuantajDonemReopenService::createReopenRequest($pdo, dvrGm(), 1, $yil, $ay, 'after destroy sequential');
        $pdo->rollBack();
        dvrAssert(false, 'sequential reopen after destroy should fail');
    } catch (PuantajDonemReopenException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        dvrAssert(
            $e->getErrorCode() === PuantajPhysicalDestructionGate::CODE_PERIOD_PHYSICALLY_DESTROYED,
            'sequential reopen after destroy blocked'
        );
    }

    // Sequential: reopen first on fresh period → destroy blocked by open reopen
    $yil2 = 2015;
    $ay2 = 7;
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri
            (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
         VALUES (1, {$yil2}, {$ay2}, 1, '2015-07', 'MUHURLENDI', 1, 1, '2015-07-28 10:00:00')"
    );
    $seal2 = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '2015-07-15', 'MUHURLENDI', 'BEKLIYOR', {$seal2})"
    );
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES ({$seal2}, 10, '2015-07-15', 'BEKLIYOR')"
    );
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari (
            sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
            state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
            personel_sayisi, girdi_sayisi, created_by
         ) VALUES (
            1, {$yil2}, {$ay2}, '2015-07', '2015-07-01', '2015-07-31', {$seal2}, 1,
            'OLUSTURULDU', '2015-07-28 12:00:00', '{$h}', '{$h}', '{$h}',
            1, 0, 1
         )"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, $yil2, $ay2, $seal2, 1);
    $pdo->beginTransaction();
    PuantajDonemReopenService::createReopenRequest($pdo, dvrGm(), 1, $yil2, $ay2, 'reopen first sequential');
    $pdo->commit();
    $req2 = DestructionWorkflowService::requestDestruction($pdo, dvrGm(), [
        'category' => RetentionCategories::PUANTAJ,
        'entity_type' => 'puantaj',
        'record_id' => $seal2,
        'sube_id' => 1,
        'yil' => $yil2,
        'ay' => $ay2,
        'reason' => 'destroy after reopen',
    ]);
    $ap2 = DestructionWorkflowService::approveDestruction(
        $pdo,
        dvrGm(),
        (int) $req2['item']['id'],
        'GM',
        true
    );
    $eval2 = PhysicalDestructionService::evaluate($pdo, dvrGm(), (int) $ap2['id']);
    try {
        PhysicalDestructionService::execute($pdo, dvrGm(), (int) $ap2['id'], [
            'expected_plan_hash' => (string) $eval2['plan']['plan_hash'],
            'execution_nonce' => dvrNonce(),
            'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
        ]);
        dvrAssert(false, 'sequential destroy after open reopen should fail');
    } catch (Throwable $e) {
        dvrAssert(
            $e->getMessage() === PhysicalDestructionCodes::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS,
            'sequential destroy blocked by open reopen'
        );
    }

    // Concurrent lock protocol (same MariaDB, two connections — no proc_open race flake):
    // T-destroy holds period lock mid-flight; T-reopen cannot commit an open reopen alongside.
    $yil3 = 2015;
    $ay3 = 8;
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhurleri
            (sube_id, yil, ay, revision_no, donem, durum, muhurlenen_kayit_sayisi, created_by, created_at)
         VALUES (1, {$yil3}, {$ay3}, 1, '2015-08', 'MUHURLENDI', 1, 1, '2015-08-28 10:00:00')"
    );
    $seal3 = (int) $pdo->lastInsertId();
    $pdo->exec(
        "INSERT INTO gunluk_puantaj (personel_id, tarih, state, kontrol_durumu, muhur_id)
         VALUES (10, '2015-08-15', 'MUHURLENDI', 'BEKLIYOR', {$seal3})"
    );
    $pdo->exec(
        "INSERT INTO puantaj_aylik_muhur_satirlari (muhur_id, personel_id, tarih, kontrol_durumu)
         VALUES ({$seal3}, 10, '2015-08-15', 'BEKLIYOR')"
    );
    $pdo->exec(
        "INSERT INTO maas_hesaplama_donem_snapshotlari (
            sube_id, yil, ay, donem, donem_baslangic, donem_bitis, muhur_id, revision_no,
            state, cutoff_at, preflight_hash, source_hash, snapshot_hash,
            personel_sayisi, girdi_sayisi, created_by
         ) VALUES (
            1, {$yil3}, {$ay3}, '2015-08', '2015-08-01', '2015-08-31', {$seal3}, 1,
            'OLUSTURULDU', '2015-08-28 12:00:00', '{$h}', '{$h}', '{$h}',
            1, 0, 1
         )"
    );
    ArchiveManifestService::createPuantajPeriodManifests($pdo, 1, $yil3, $ay3, $seal3, 1);
    $req3 = DestructionWorkflowService::requestDestruction($pdo, dvrGm(), [
        'category' => RetentionCategories::PUANTAJ,
        'entity_type' => 'puantaj',
        'record_id' => $seal3,
        'sube_id' => 1,
        'yil' => $yil3,
        'ay' => $ay3,
        'reason' => 'concurrency destroy race',
    ]);
    $ap3 = DestructionWorkflowService::approveDestruction(
        $pdo,
        dvrGm(),
        (int) $req3['item']['id'],
        'GM',
        true
    );
    $eval3 = PhysicalDestructionService::evaluate($pdo, dvrGm(), (int) $ap3['id']);
    $planHash3 = (string) ($eval3['plan']['plan_hash'] ?? '');

    // Case G1: destroy holds period lock → reopen create blocks (lock wait).
    $pdoDestroy = dvrPdoForDb($database);
    $pdoDestroy->beginTransaction();
    PuantajDonemKilidiService::acquire($pdoDestroy, 1, $yil3, $ay3);
    $pdoReopen = dvrPdoForDb($database);
    $pdoReopen->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $pdoReopen->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdoReopen->beginTransaction();
    $reopenBlockedByLock = false;
    try {
        PuantajDonemReopenService::createReopenRequest(
            $pdoReopen,
            dvrGm(),
            1,
            $yil3,
            $ay3,
            'while destroy holds lock'
        );
        $pdoReopen->commit();
    } catch (Throwable $e) {
        $reopenBlockedByLock = true;
        if ($pdoReopen->inTransaction()) {
            $pdoReopen->rollBack();
        }
    }
    dvrAssert($reopenBlockedByLock, 'reopen waits/fails while destroy holds period lock');
    $pdoDestroy->rollBack();

    // Case G2: destroy commits under period lock → reopen afterwards hit destroyed gate.
    $resRaceDestroy = PhysicalDestructionService::execute($pdo, dvrGm(), (int) $ap3['id'], [
        'expected_plan_hash' => $planHash3,
        'execution_nonce' => dvrNonce(),
        'confirmation' => PhysicalDestructionCodes::CONFIRMATION_TOKEN,
    ]);
    dvrAssert(
        ($resRaceDestroy['execution']['code'] ?? '') === PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
        'lock-protocol destroy EXECUTED'
    );
    $pdoReopen2 = dvrPdoForDb($database);
    $pdoReopen2->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdoReopen2->beginTransaction();
    try {
        PuantajDonemReopenService::createReopenRequest(
            $pdoReopen2,
            dvrGm(),
            1,
            $yil3,
            $ay3,
            'after locked destroy'
        );
        $pdoReopen2->rollBack();
        dvrAssert(false, 'reopen after locked destroy should fail');
    } catch (PuantajDonemReopenException $e) {
        if ($pdoReopen2->inTransaction()) {
            $pdoReopen2->rollBack();
        }
        dvrAssert(
            $e->getErrorCode() === PuantajPhysicalDestructionGate::CODE_PERIOD_PHYSICALLY_DESTROYED,
            'reopen after locked destroy blocked by gate'
        );
    }

    $destroyed = PuantajPhysicalDestructionGate::isPeriodDestroyed($pdo, 1, $yil3, $ay3);
    $open = PuantajDonemPeriodService::findOpenReopenTalep($pdo, 1, $yil3, $ay3);
    dvrAssert($destroyed && $open === null, 'never both destroy EXECUTED and active reopen');

    // Lock-order smoke: hold period lock then peer acquire waits
    $pdoHold = dvrPdoForDb($database);
    $pdoHold->beginTransaction();
    PuantajDonemKilidiService::acquire($pdoHold, 1, $yil3, $ay3);
    $pdoRace = dvrPdoForDb($database);
    $pdoRace->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $pdoRace->beginTransaction();
    $lockBlocked = false;
    try {
        PuantajDonemKilidiService::acquire($pdoRace, 1, $yil3, $ay3);
    } catch (Throwable $e) {
        $lockBlocked = true;
        if ($pdoRace->inTransaction()) {
            $pdoRace->rollBack();
        }
    }
    $pdoHold->commit();
    dvrAssert($lockBlocked, 'period lock serializes concurrent writers');

    echo 'verify-retention-puantaj-destroy-vs-reopen-concurrency-mysql: OK' . PHP_EOL;
} finally {
    RetentionClock::clearOverride();
    try {
        $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable $e) {
        // best-effort
    }
}
