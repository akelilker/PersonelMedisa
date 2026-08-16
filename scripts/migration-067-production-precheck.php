<?php
/**
 * Local/CI driver for the temporary Migration 067 ops endpoint.
 * It never connects to production and never applies a migration.
 */
declare(strict_types=1);

const MIGRATION_067_SOURCE = __DIR__ . '/../api/migrations/067_personel_canonical_reference_gate.sql';
const MIGRATION_067_SOURCE_SHA256 = 'afa8e99867b9c670af9f8ab84a814d72231f602fa2cd01e3f8d73c06cdb8c5b9';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function verify_source(): void
{
    if (!is_file(MIGRATION_067_SOURCE)) {
        fail('Migration 067 source is missing.');
    }
    $actual = hash_file('sha256', MIGRATION_067_SOURCE);
    if (!is_string($actual) || !hash_equals(MIGRATION_067_SOURCE_SHA256, $actual)) {
        fail('Migration 067 source hash mismatch.');
    }
    fwrite(STDOUT, "MIGRATION_067_SOURCE_SHA256=PASS\n");
}

function sanitize_result(string $path): void
{
    if (!is_file($path)) {
        fail('Result file is missing.');
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fail('Result is not JSON.');
    }
    $allowed = [
        'ok',
        'DB_NAME',
        'SCHEMA_066_FINGERPRINT',
        'DEPARTMAN_1',
        'BOLUM_3',
        'BOLUM_5',
        'BIRIM_10',
        'DUPLICATE_ACTIVE_GUVENLIK_COUNT',
        'LEGACY_ACTIVE_CHILD_COUNT',
        'PERSONEL_BOLUM5_COUNT',
        'PERSONEL_BIRIM10_COUNT',
        'classification',
        'backup_created',
        'backup_filename',
        'backup_size_bytes',
        'backup_sha256',
        'backup_location_class',
        'error',
    ];
    $sanitized = array_intersect_key($decoded, array_flip($allowed));
    $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        fail('Result could not be sanitized.');
    }
    fwrite(STDOUT, $json . PHP_EOL);
}

$command = $argv[1] ?? '';
if ($command === '--verify-source') {
    verify_source();
    exit(0);
}
if ($command === '--sanitize-result' && isset($argv[2])) {
    sanitize_result($argv[2]);
    exit(0);
}
fail('Usage: php scripts/migration-067-production-precheck.php --verify-source|--sanitize-result FILE');
