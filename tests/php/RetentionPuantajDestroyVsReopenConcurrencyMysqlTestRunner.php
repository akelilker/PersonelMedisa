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
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

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
    $env = array_merge(getenv(), [
        'MEDISA_TEST_MYSQL_DSN' => (string) getenv('MEDISA_TEST_MYSQL_DSN'),
        'MEDISA_TEST_MYSQL_USER' => (string) getenv('MEDISA_TEST_MYSQL_USER'),
        'MEDISA_TEST_MYSQL_PASSWORD' => (string) (getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: ''),
        'MEDISA_DVR_DB' => $db,
        'MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED' => '1',
    ]);
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

    $signal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'medisa_dvr_' . bin2hex(random_bytes(4)) . '.signal';
    @unlink($signal);

    $destroyChild = dvrSpawn(
        ['destroy', $signal, (string) (int) $ap['id'], $planHash],
        $database
    );
    $reopenChild = dvrSpawn(
        ['reopen', $signal, (string) $yil, (string) $ay],
        $database
    );
    usleep(100000);
    file_put_contents($signal, 'go');

    $destroyOut = dvrFinish($destroyChild);
    $reopenOut = dvrFinish($reopenChild);
    @unlink($signal);

    $destroyed = PuantajPhysicalDestructionGate::isPeriodDestroyed($pdo, 1, $yil, $ay);
    $open = PuantajDonemPeriodService::findOpenReopenTalep($pdo, 1, $yil, $ay);
    $both = $destroyed && $open !== null;
    dvrAssert(!$both, 'never both destroy EXECUTED and active reopen (' . $destroyOut . ' | ' . $reopenOut . ')');

    $destroyWon = strpos($destroyOut, 'DESTROY:' . PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED) === 0;
    $reopenWon = $reopenOut === 'REOPEN:OK';
    $reopenBlockedDestroyed = $reopenOut === 'REOPEN:' . PuantajPhysicalDestructionGate::CODE_PERIOD_PHYSICALLY_DESTROYED;
    $destroyBlockedOpen = strpos($destroyOut, 'ERR:' . PhysicalDestructionCodes::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS) === 0
        || strpos($destroyOut, 'DESTROY:' . PhysicalDestructionCodes::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS) === 0;

    dvrAssert(
        ($destroyWon && ($reopenBlockedDestroyed || strpos($reopenOut, 'REOPEN:') === 0 && !$reopenWon))
        || ($reopenWon && !$destroyed)
        || ($destroyWon && !$reopenWon)
        || ($reopenWon && $destroyBlockedOpen),
        'exactly one lifecycle wins (destroy=' . $destroyOut . ', reopen=' . $reopenOut . ', destroyed=' . ($destroyed ? '1' : '0') . ')'
    );

    // Stronger invariant already asserted: not both.
    if ($destroyed) {
        dvrAssert($open === null, 'when destroyed, no active reopen remains');
    }
    if ($open !== null) {
        dvrAssert(!$destroyed, 'when active reopen exists, destroy not executed');
        $dailyLeft = (int) $pdo->query(
            "SELECT COUNT(*) FROM gunluk_puantaj WHERE personel_id=10 AND YEAR(tarih)={$yil} AND MONTH(tarih)={$ay}"
        )->fetchColumn();
        dvrAssert($dailyLeft === 1, 'payload remains when reopen won');
    }

    // Lock-order smoke: hold period lock then destroy waits / reopen waits
    $pdoHold = dvrPdoForDb($database);
    $pdoHold->beginTransaction();
    PuantajDonemKilidiService::acquire($pdoHold, 1, $yil, $ay);
    $pdoRace = dvrPdoForDb($database);
    $pdoRace->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $pdoRace->beginTransaction();
    $lockBlocked = false;
    try {
        PuantajDonemKilidiService::acquire($pdoRace, 1, $yil, $ay);
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
