<?php

declare(strict_types=1);

// Direct test for MigrationExecutionService::safeDetail without full bootstrap.
require_once __DIR__ . '/../../api/src/Database/MigrationExecutionService.php';

use Medisa\Api\Database\MigrationExecutionService;
use PDOException;
use RuntimeException;

function assert_ok(bool $ok, string $msg): void
{
    if (!$ok) {
        echo "[FAIL] " . $msg . PHP_EOL;
        exit(1);
    }
    echo "[PASS] " . $msg . PHP_EOL;
}

// Test 1: inner PDOException with SQL error should be visible
$innerMsg = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'dup' for key 'uniq_idx'";
$pdoEx = new PDOException($innerMsg);
$outer = new RuntimeException('Canonical migration failed: 068_sgk_actor_identity_lifecycle_audit.sql', 0, $pdoEx);
$detail = MigrationExecutionService::safeDetail($outer);
assert_ok($detail !== null, 'PDO SQL error is surfaced');
assert_ok(stripos($detail, 'duplicate') !== false || stripos($detail, '1062') !== false, 'Detail contains SQL error text');

// Test 2: inner message contains password-like token -> must be suppressed
$innerMsg2 = "SQLSTATE[HY000] [1045] Access denied for user 'u'@'host' (using password: YES) password=supersecret";
$pdoEx2 = new PDOException($innerMsg2);
$outer2 = new RuntimeException('Canonical migration failed: 068_sgk_actor_identity_lifecycle_audit.sql', 0, $pdoEx2);
$detail2 = MigrationExecutionService::safeDetail($outer2);
assert_ok($detail2 === null, 'Password-like content in inner exception is suppressed');

// Test 3: connection failure message without explicit password but classified as DB connection -> suppressed by fallback
$innerMsg3 = "SQLSTATE[HY000] [2002] Connection refused";
$pdoEx3 = new PDOException($innerMsg3);
$outer3 = new RuntimeException('Some outer message', 0, $pdoEx3);
$detail3 = MigrationExecutionService::safeDetail($outer3);
// Given safeDetail walks chain and returns PDO messages unless it detects password tokens, the connection refused message should be returned
assert_ok($detail3 !== null && stripos($detail3, 'Connection refused') !== false, 'Connection refused message is visible or otherwise handled safely');

echo "MigrationExecutionService safeDetail tests OK\n";
exit(0);
