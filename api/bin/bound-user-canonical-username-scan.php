#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Read-only CLI for bound-user canonical username reconciliation.
 *
 * Usage (cPanel / local with DB):
 *   php api/bin/bound-user-canonical-username-scan.php
 *
 * Never writes. Collisions are reported as blockers; no auto-fix runs here.
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Auth\BoundUserCanonicalUsernameReconciliationService;

try {
    $pdo = Connection::get();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . PHP_EOL);
    exit(2);
}

$report = BoundUserCanonicalUsernameReconciliationService::scan($pdo);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$mismatch = (int) ($report['totals']['mismatch'] ?? 0);
$collisions = (int) ($report['totals']['collision_blocker_count'] ?? 0);
if (!empty($report['ready']) && ($mismatch > 0 || $collisions > 0)) {
    exit(1);
}

exit(0);
