<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Medisa\Api\Database\Connection;
use Medisa\Api\Database\MigrationRunner;

$migrationDirectory = dirname(__DIR__) . '/migrations';
$verifyOnly = in_array('--verify', $argv, true);
$baseline = null;

foreach ($argv as $argument) {
    if (strpos($argument, '--baseline=') === 0) {
        $baseline = substr($argument, strlen('--baseline='));
    }
}

try {
    $pdo = Connection::get();
    if ($verifyOnly) {
        $result = MigrationRunner::verify($pdo, $migrationDirectory);
        fwrite(STDOUT, sprintf(
            "schema_ready=true applied_count=%d latest=%s\n",
            $result['applied_count'],
            $result['latest'] ?? 'none'
        ));
        exit(0);
    }

    $result = MigrationRunner::run($pdo, $migrationDirectory, $baseline);
    fwrite(STDOUT, sprintf(
        "migration_apply=ok applied_count=%d pending_count=%d latest=%s\n",
        count($result['applied']),
        count($result['pending']),
        $result['latest'] ?? 'none'
    ));
    exit(0);
} catch (\Throwable $exception) {
    fwrite(
        STDERR,
        "migration_apply=failed reason_code=" . classifyMigrationFailure($exception) . "\n"
    );
    exit(1);
}

function classifyMigrationFailure(\Throwable $exception): string
{
    $message = strtolower($exception->getMessage());

    if (
        str_contains($message, 'database configuration is incomplete')
        || str_contains($message, 'sqlstate')
        || str_contains($message, 'could not find driver')
        || $exception instanceof PDOException
    ) {
        return 'DB_CONNECTION_FAILED';
    }
    if (
        str_contains($message, 'undefined function')
        || str_contains($message, 'undefined class')
        || str_contains($message, 'extension')
    ) {
        return 'PHP_EXTENSION_MISSING';
    }
    if (str_contains($message, 'migration ledger bootstrap is missing')) {
        return 'MIGRATION_LEDGER_BOOTSTRAP_FAILED';
    }
    if (
        str_contains($message, 'baseline')
        && str_contains($message, 'initialized without')
    ) {
        return 'MIGRATION_BASELINE_REQUIRED';
    }
    if (
        str_contains($message, 'migration baseline must')
        || str_contains($message, 'requested migration baseline')
    ) {
        return 'MIGRATION_BASELINE_INVALID';
    }
    if (str_contains($message, 'checksum mismatch')) {
        return 'MIGRATION_CHECKSUM_MISMATCH';
    }
    if (str_contains($message, 'ledger has a gap')) {
        return 'MIGRATION_LEDGER_GAP';
    }
    if (
        str_contains($message, 'no canonical migration files')
        || str_contains($message, 'migration source is unreadable')
    ) {
        return 'MIGRATION_SOURCE_MISSING';
    }
    if (str_contains($message, 'could not acquire canonical migration lock')) {
        return 'MIGRATION_LOCK_FAILED';
    }
    if (
        str_contains($message, 'canonical migration schema is not ready')
        || str_contains($message, 'schema is not ready')
    ) {
        return 'SCHEMA_VERIFY_FAILED';
    }
    if (str_contains($message, 'canonical migration failed')) {
        return 'MIGRATION_APPLY_FAILED';
    }

    return 'UNKNOWN_MIGRATION_FAILURE';
}
