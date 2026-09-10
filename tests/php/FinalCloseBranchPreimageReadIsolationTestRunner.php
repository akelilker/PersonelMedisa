<?php

declare(strict_types=1);

/**
 * Focused final-close branch-preimage read isolation — SQLite harness, no DB writes.
 *
 * The canonical branch-preimage owner performs exactly two live reads: the
 * `subeler` target read and the manager map read of SubeSorumluYoneticiSchema.
 * Each failure shape (thrown driver error and silent-mode false return) must be
 * attributed to its own bounded code, so the worker never reports the generic
 * FINAL_CLOSE_SNAPSHOT_BRANCH_READ_FAILED for an attributable failure, while the
 * already bounded target/schema codes stay exactly as they are.
 *
 * php tests/php/FinalCloseBranchPreimageReadIsolationTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Organizasyon\OrganizasyonService;
use Medisa\Api\Services\Organizasyon\SubeSorumluYoneticiSchema;

const FCBPR_TABLE_CODE = 'FINAL_CLOSE_BRANCH_TABLE_READ_FAILED';
const FCBPR_MAP_CODE = 'FINAL_CLOSE_MANAGER_MAP_READ_FAILED';
const FCBPR_SCHEMA_CODE = 'FINAL_CLOSE_MANAGER_SCHEMA_REQUIRED';
const FCBPR_MISSING_CODE = 'FINAL_CLOSE_BRANCH_MISSING';
const FCBPR_TARGET_CODE = 'FINAL_CLOSE_BRANCH_TARGET_INVALID';

function fcbprAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/** Bounded single-token code surfaced by a call, or a marker when it is not bounded. */
function fcbprSurfacedCode(callable $call): string
{
    try {
        $call();
    } catch (Throwable $error) {
        $message = $error->getMessage();

        return is_string($message) && preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $message) === 1 ? $message : '';
    }

    return 'NO_FAILURE';
}

/** Raw message of a failing call, used to prove no driver text is appended. */
function fcbprRawMessage(callable $call): string
{
    try {
        $call();
    } catch (Throwable $error) {
        return (string) $error->getMessage();
    }

    return '';
}

function fcbprBounded(callable $call, string $expected, string $name): void
{
    fcbprAssert(fcbprSurfacedCode($call) === $expected, $name . ' => ' . $expected);
}

function fcbprPdo(int $errMode): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, $errMode);
    SubeSorumluYoneticiSchema::resetCache();

    return $pdo;
}

function fcbprTargetTable(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE subeler (id INTEGER PRIMARY KEY, durum TEXT NOT NULL)');
    $pdo->exec("INSERT INTO subeler (id, durum) VALUES (1, 'AKTIF'), (2, 'AKTIF')");
}

function fcbprManagerTable(PDO $pdo, bool $withUserColumn = true): void
{
    $pdo->exec($withUserColumn
        ? 'CREATE TABLE sube_sorumlu_yoneticiler (sube_id INTEGER NOT NULL, user_id INTEGER NOT NULL)'
        : 'CREATE TABLE sube_sorumlu_yoneticiler (sube_id INTEGER NOT NULL)');
}

foreach ([PDO::ERRMODE_SILENT, PDO::ERRMODE_EXCEPTION] as $errMode) {
    $driver = $errMode === PDO::ERRMODE_SILENT ? 'silent driver' : 'exception driver';

    // 1) The subeler target read fails while the manager schema is ready.
    $noTarget = fcbprPdo($errMode);
    fcbprManagerTable($noTarget);
    fcbprBounded(
        static fn (): array => OrganizasyonService::readFinalCloseBranchPreimage($noTarget, [1, 2]),
        FCBPR_TABLE_CODE,
        'subeler target read failure (' . $driver . ')'
    );

    // 2) The manager map read fails while the target read succeeds.
    $noMap = fcbprPdo($errMode);
    fcbprTargetTable($noMap);
    fcbprManagerTable($noMap, false);
    fcbprBounded(
        static fn (): array => OrganizasyonService::readFinalCloseBranchPreimage($noMap, [1, 2]),
        FCBPR_MAP_CODE,
        'manager map read failure (' . $driver . ')'
    );
}

// 3) An unready manager schema keeps its own bounded code.
$noSchema = fcbprPdo(PDO::ERRMODE_EXCEPTION);
fcbprTargetTable($noSchema);
fcbprBounded(
    static fn (): array => OrganizasyonService::readFinalCloseBranchPreimage($noSchema, [1]),
    FCBPR_SCHEMA_CODE,
    'unready manager schema keeps the schema code'
);

// 4) An absent target is a successful read, not a read failure.
$partial = fcbprPdo(PDO::ERRMODE_EXCEPTION);
fcbprTargetTable($partial);
fcbprManagerTable($partial);
$partial->exec('INSERT INTO sube_sorumlu_yoneticiler (sube_id, user_id) VALUES (2, 10)');
fcbprBounded(
    static fn (): array => OrganizasyonService::readFinalCloseBranchPreimage($partial, [1, 5]),
    FCBPR_MISSING_CODE,
    'absent target keeps the missing-branch code'
);

// 5) An invalid target keeps its own bounded code.
fcbprBounded(
    static fn (): array => OrganizasyonService::readFinalCloseBranchPreimage($partial, []),
    FCBPR_TARGET_CODE,
    'empty target list keeps the invalid-target code'
);
fcbprBounded(
    static fn (): array => OrganizasyonService::readFinalCloseBranchPreimage($partial, [1, 0]),
    FCBPR_TARGET_CODE,
    'zero target keeps the invalid-target code'
);

// 6) The bounded projection itself stays unchanged.
fcbprAssert(
    OrganizasyonService::readFinalCloseBranchPreimage($partial, [1, 2]) === [
        1 => ['id' => 1, 'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => []],
        2 => ['id' => 2, 'durum' => 'AKTIF', 'sorumlu_yonetici_user_ids' => [10]],
    ],
    'bounded preimage projection is unchanged'
);

// 7) Only the bounded token is surfaced: no driver, SQL or table text is appended.
$rawTarget = fcbprPdo(PDO::ERRMODE_EXCEPTION);
fcbprManagerTable($rawTarget);
$rawMessage = fcbprRawMessage(
    static fn (): array => OrganizasyonService::readFinalCloseBranchPreimage($rawTarget, [1])
);
fcbprAssert(
    $rawMessage === FCBPR_TABLE_CODE
        && stripos($rawMessage, 'SQLSTATE') === false
        && stripos($rawMessage, 'subeler') === false
        && stripos($rawMessage, 'sqlite') === false,
    'surfaced target-read failure is the bounded code only'
);

echo 'FINAL_CLOSE_BRANCH_PREIMAGE_READ_ISOLATION: OK' . PHP_EOL;
