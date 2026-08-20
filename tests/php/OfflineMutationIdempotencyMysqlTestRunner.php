<?php

declare(strict_types=1);

/**
 * Offline mutation idempotency ledger — MariaDB runtime acceptance.
 * Requires MEDISA_TEST_MYSQL_DSN + MEDISA_TEST_MYSQL_USER.
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\OfflineMutationIdempotencyService;

function omiAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function omiRootPdo(): PDO
{
    $dsn = getenv('MEDISA_TEST_MYSQL_DSN') ?: '';
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $password = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    if ($dsn === '' || $user === '') {
        throw new RuntimeException('SKIP: Disposable MariaDB credentials are required.');
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** @return list<string> */
function omiSplitSql(string $sql): array
{
    $statements = [];
    $buffer = '';
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || strpos($trimmed, '--') === 0) {
            continue;
        }
        $buffer .= $line . "\n";
        if (substr($trimmed, -1) === ';') {
            $statements[] = trim($buffer);
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

function omiApply(PDO $pdo, string $file): void
{
    $path = __DIR__ . '/../../api/migrations/' . $file;
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Migration okunamadi');
    }
    foreach (omiSplitSql($sql) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function omiBusinessCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM omi_business_events')->fetchColumn();
}

/**
 * Apply one mutation under idempotency claim (same TX).
 * @return 'ok'|'replay'|'conflict'|'in_flight'
 */
function omiMutateOnce(PDO $pdo, int $actor, string $scope, string $key, string $hash, string $marker): string
{
    $completed = OfflineMutationIdempotencyService::findCompletedReplay($pdo, $actor, $scope, $key, $hash);
    if (is_array($completed)) {
        return 'replay';
    }

    $pdo->beginTransaction();
    try {
        // Capture JsonResponse exits via subprocess for conflict paths; here we use claim and detect via exception wrapper.
        $replay = OfflineMutationIdempotencyService::claimInTransaction($pdo, $actor, $scope, $key, $hash);
        if (is_array($replay)) {
            $pdo->commit();
            return 'replay';
        }

        $ins = $pdo->prepare('INSERT INTO omi_business_events (marker) VALUES (:m)');
        $ins->execute(['m' => $marker]);

        OfflineMutationIdempotencyService::completeInTransaction(
            $pdo,
            $actor,
            $scope,
            $key,
            201,
            'event',
            (int) $pdo->lastInsertId(),
            $marker
        );
        $pdo->commit();
        return 'ok';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Run claim conflict path in a child process (JsonResponse::error exits).
 */
function omiChildConflictProbe(string $dsn, string $user, string $pass, int $actor, string $scope, string $key, string $hash): array
{
    $script = <<<'PHP'
<?php
require_once getenv('OMI_BOOTSTRAP');
use Medisa\Api\Services\OfflineMutationIdempotencyService;
$pdo = new PDO(getenv('OMI_DSN'), getenv('OMI_USER'), getenv('OMI_PASS'), [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
]);
$actor = (int) getenv('OMI_ACTOR');
$scope = (string) getenv('OMI_SCOPE');
$key = (string) getenv('OMI_KEY');
$hash = (string) getenv('OMI_HASH');
OfflineMutationIdempotencyService::findCompletedReplay($pdo, $actor, $scope, $key, $hash);
echo "UNEXPECTED_CONTINUE\n";
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'omi_');
    if ($tmp === false) {
        throw new RuntimeException('tempnam failed');
    }
    file_put_contents($tmp, $script);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = array_merge($_ENV, [
        'OMI_BOOTSTRAP' => __DIR__ . '/../../api/src/bootstrap.php',
        'OMI_DSN' => $dsn,
        'OMI_USER' => $user,
        'OMI_PASS' => $pass,
        'OMI_ACTOR' => (string) $actor,
        'OMI_SCOPE' => $scope,
        'OMI_KEY' => $key,
        'OMI_HASH' => $hash,
    ]);
    $proc = proc_open((defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php') . ' ' . escapeshellarg($tmp), $descriptors, $pipes, null, $env);
    if (!is_resource($proc)) {
        @unlink($tmp);
        throw new RuntimeException('proc_open failed');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    @unlink($tmp);

    return [
        'code' => $code,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

/**
 * Concurrent workers: both claim same key; exactly one business insert.
 */
function omiConcurrentWorkers(string $dsn, string $user, string $pass, int $actor, string $scope, string $key, string $hash): void
{
    $worker = <<<'PHP'
<?php
require_once getenv('OMI_BOOTSTRAP');
use Medisa\Api\Services\OfflineMutationIdempotencyService;
$pdo = new PDO(getenv('OMI_DSN'), getenv('OMI_USER'), getenv('OMI_PASS'), [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  PDO::ATTR_EMULATE_PREPARES => false,
]);
$actor = (int) getenv('OMI_ACTOR');
$scope = (string) getenv('OMI_SCOPE');
$key = (string) getenv('OMI_KEY');
$hash = (string) getenv('OMI_HASH');
$marker = (string) getenv('OMI_MARKER');
usleep((int) getenv('OMI_SLEEP_US'));
try {
  $pdo->beginTransaction();
  $replay = OfflineMutationIdempotencyService::claimInTransaction($pdo, $actor, $scope, $key, $hash);
  if (is_array($replay)) {
    $pdo->commit();
    echo "REPLAY\n";
    exit(0);
  }
  $pdo->prepare('INSERT INTO omi_business_events (marker) VALUES (:m)')->execute(['m' => $marker]);
  OfflineMutationIdempotencyService::completeInTransaction($pdo, $actor, $scope, $key, 201, 'event', (int)$pdo->lastInsertId(), $marker);
  $pdo->commit();
  echo "OK\n";
  exit(0);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) { $pdo->rollBack(); }
  // JsonResponse exits before here; claim conflict exits process.
  echo "ERR:" . $e->getMessage() . "\n";
  exit(1);
}
PHP;

    $tmp = tempnam(sys_get_temp_dir(), 'omi_w_');
    file_put_contents($tmp, $worker);

    $launch = function (string $marker, int $sleepUs) use ($tmp, $dsn, $user, $pass, $actor, $scope, $key, $hash) {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = array_merge($_ENV, [
            'OMI_BOOTSTRAP' => __DIR__ . '/../../api/src/bootstrap.php',
            'OMI_DSN' => $dsn,
            'OMI_USER' => $user,
            'OMI_PASS' => $pass,
            'OMI_ACTOR' => (string) $actor,
            'OMI_SCOPE' => $scope,
            'OMI_KEY' => $key,
            'OMI_HASH' => $hash,
            'OMI_MARKER' => $marker,
            'OMI_SLEEP_US' => (string) $sleepUs,
        ]);
        $proc = proc_open((defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php') . ' ' . escapeshellarg($tmp), $descriptors, $pipes, null, $env);
        return [$proc, $pipes];
    };

    [$p1, $pipes1] = $launch('c1', 0);
    [$p2, $pipes2] = $launch('c2', 5000);
    if (!is_resource($p1) || !is_resource($p2)) {
        @unlink($tmp);
        throw new RuntimeException('concurrent launch failed');
    }
    fclose($pipes1[0]);
    fclose($pipes2[0]);
    $out1 = stream_get_contents($pipes1[1]);
    $out2 = stream_get_contents($pipes2[1]);
    fclose($pipes1[1]);
    fclose($pipes1[2]);
    fclose($pipes2[1]);
    fclose($pipes2[2]);
    proc_close($p1);
    proc_close($p2);
    @unlink($tmp);

    $combined = $out1 . $out2;
    omiAssert(strpos($combined, 'OK') !== false, 'concurrent at least one OK');
    // Loser is REPLAY or JsonResponse conflict exit (empty / JSON body).
    echo '[INFO] concurrent outs: ' . str_replace("\n", ' | ', trim($combined)) . PHP_EOL;
}

try {
    $root = omiRootPdo();
} catch (RuntimeException $e) {
    if (strpos($e->getMessage(), 'SKIP:') === 0) {
        echo $e->getMessage() . PHP_EOL;
        exit(0);
    }
    throw $e;
}

$database = 'medisa_omi_070_' . bin2hex(random_bytes(4));
$root->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

try {
    $dsnBase = (string) getenv('MEDISA_TEST_MYSQL_DSN');
    $dsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $database, $dsnBase);
    $user = getenv('MEDISA_TEST_MYSQL_USER') ?: '';
    $pass = getenv('MEDISA_TEST_MYSQL_PASSWORD') ?: '';
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    omiApply($pdo, '070_offline_mutation_idempotency.sql');
    omiApply($pdo, '070_offline_mutation_idempotency.sql'); // idempotent IF NOT EXISTS

    $uq = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'offline_mutation_idempotency'
           AND INDEX_NAME = 'uq_omi_actor_scope_key'"
    )->fetchColumn();
    omiAssert((int) $uq === 1, 'unique actor/scope/key constraint');

    $pdo->exec('CREATE TABLE omi_business_events (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      marker VARCHAR(64) NOT NULL,
      created_at DATETIME(3) NOT NULL DEFAULT NOW(3)
    ) ENGINE=InnoDB');

    $scope = 'personeller.create';
    $key = 'q_omi_stable_key_01';
    $payloadA = ['op' => $scope, 'payload' => ['ad' => 'A', 'soyad' => 'B']];
    $payloadB = ['op' => $scope, 'payload' => ['ad' => 'X', 'soyad' => 'Y']];
    $hashA = OfflineMutationIdempotencyService::hashPayload($payloadA);
    $hashB = OfflineMutationIdempotencyService::hashPayload($payloadB);
    omiAssert($hashA !== $hashB, 'payload hashes differ');

    // A) sequential same key + same payload → one business mutation
    $r1 = omiMutateOnce($pdo, 10, $scope, $key, $hashA, 'seq1');
    $r2 = omiMutateOnce($pdo, 10, $scope, $key, $hashA, 'seq2');
    omiAssert($r1 === 'ok', 'sequential first ok');
    omiAssert($r2 === 'replay', 'sequential second replay');
    omiAssert(omiBusinessCount($pdo) === 1, 'sequential business mutation count = 1');

    // E) response-lost style replay (COMPLETED already) still once
    $r3 = omiMutateOnce($pdo, 10, $scope, $key, $hashA, 'seq3');
    omiAssert($r3 === 'replay', 'response-lost replay');
    omiAssert(omiBusinessCount($pdo) === 1, 'response-lost business still 1');

    // C) same key different payload → 409 conflict JSON
    $probe = omiChildConflictProbe($dsn, $user, $pass, 10, $scope, $key, $hashB);
    omiAssert(strpos($probe['stdout'], 'IDEMPOTENCY_KEY_CONFLICT') !== false, 'payload mismatch IDEMPOTENCY_KEY_CONFLICT');
    omiAssert(omiBusinessCount($pdo) === 1, 'payload mismatch original unchanged');

    // D) different actor same key → independent mutation
    $rCross = omiMutateOnce($pdo, 99, $scope, $key, $hashA, 'cross');
    omiAssert($rCross === 'ok', 'cross-actor independent ok');
    omiAssert(omiBusinessCount($pdo) === 2, 'cross-actor second business row');

    // B) concurrent same key on fresh key
    $ckey = 'q_omi_concurrent_key_02';
    $before = omiBusinessCount($pdo);
    omiConcurrentWorkers($dsn, $user, $pass, 10, 'puantaj.upsert:1:2026-08-01', $ckey, $hashA);
    $after = omiBusinessCount($pdo);
    omiAssert($after - $before === 1, 'concurrent business mutation count = 1');

    // UPDATE / CANCEL class scopes smoke
    $updKey = 'q_omi_update_key_03';
    $updScope = 'surecler.update:42';
    $updHash = OfflineMutationIdempotencyService::hashPayload(['op' => $updScope, 'id' => 42, 'durum' => 'X']);
    omiAssert(omiMutateOnce($pdo, 10, $updScope, $updKey, $updHash, 'upd') === 'ok', 'update-class claim ok');
    omiAssert(omiMutateOnce($pdo, 10, $updScope, $updKey, $updHash, 'upd2') === 'replay', 'update-class replay');

    $cancelKey = 'q_omi_cancel_key_04';
    $cancelScope = 'bildirimler.cancel:7';
    $cancelHash = OfflineMutationIdempotencyService::hashPayload(['op' => $cancelScope, 'id' => 7]);
    omiAssert(omiMutateOnce($pdo, 10, $cancelScope, $cancelKey, $cancelHash, 'cancel') === 'ok', 'cancel-class claim ok');
    omiAssert(omiMutateOnce($pdo, 10, $cancelScope, $cancelKey, $cancelHash, 'cancel2') === 'replay', 'cancel-class replay');

    // No raw payload columns
    $cols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'offline_mutation_idempotency'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $colBlob = implode(',', $cols);
    omiAssert(strpos($colBlob, 'payload_hash') !== false, 'payload_hash column');
    omiAssert(strpos($colBlob, 'request_body') === false, 'no raw request_body');
    omiAssert(strpos($colBlob, 'response_body') === false, 'no raw response_body');

    echo "OfflineMutationIdempotencyMysqlTestRunner: ALL PASS\n";
} finally {
    $root->exec('DROP DATABASE IF EXISTS `' . $database . '`');
}
