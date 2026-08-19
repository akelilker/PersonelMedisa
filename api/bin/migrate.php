<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use Medisa\Api\Database\Connection;
use Medisa\Api\Database\MigrationExecutionService;

$apiDirectory = dirname(__DIR__);
$verifyOnly = in_array('--verify', $argv, true);
$baseline = null;

foreach ($argv as $argument) {
    if (strpos($argument, '--baseline=') === 0) {
        $baseline = substr($argument, strlen('--baseline='));
    }
}

try {
    $migrationSource = MigrationExecutionService::sourceForRuntime($apiDirectory, true);
    $pdo = Connection::get();
    if ($verifyOnly) {
        $result = MigrationExecutionService::verify($pdo, $migrationSource);
        fwrite(STDOUT, sprintf(
            "schema_ready=true applied_count=%d latest=%s\n",
            $result['applied_count'],
            $result['latest'] ?? 'none'
        ));
        exit(0);
    }

    $result = MigrationExecutionService::apply($pdo, $migrationSource, $baseline);
    fwrite(STDOUT, sprintf(
        "migration_apply=ok applied_count=%d pending_count=%d latest=%s\n",
        count($result['applied']),
        count($result['pending']),
        $result['latest'] ?? 'none'
    ));
    exit(0);
} catch (\Throwable $exception) {
    $reason = MigrationExecutionService::classify($exception);
    $detail = MigrationExecutionService::safeDetail($exception);
    $line = "migration_apply=failed reason_code=" . $reason;
    if ($detail !== null) {
        // detail is already sanitized by safeDetail
        $line .= " detail=" . $detail;
    }
    fwrite(STDERR, $line . "\n");
    exit(1);
}
