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

function sanitize_result(string $path, string $expectedInstanceId): void
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
        'ops_instance_id',
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
        'backup_table_count',
        'backup_view_count',
        'backup_trigger_count',
        'backup_routine_count',
        'backup_event_count',
        'backup_consistency',
        'backup_engine_guard',
        'error',
    ];
    if (!array_key_exists('ok', $decoded)) {
        fail('Endpoint result is missing ok.');
    }
    if (preg_match('/^m067-[0-9]+-[0-9]+-[a-f0-9]{16}$/', $expectedInstanceId) !== 1
        || ($decoded['ops_instance_id'] ?? null) !== $expectedInstanceId) {
        fail('Endpoint instance identity mismatch.');
    }
    if ($decoded['ok'] !== true) {
        fail('Endpoint operation failed.');
    }
    if (array_key_exists('backup_created', $decoded)) {
        if ($decoded['backup_created'] !== true
            || !is_string($decoded['backup_filename'] ?? null)
            || !preg_match('/^karmotor_medisa_pre_067_[0-9]{8}_[0-9]{6}\.sql$/', $decoded['backup_filename'])
            || (int) ($decoded['backup_size_bytes'] ?? 0) <= 0
            || !is_string($decoded['backup_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/i', $decoded['backup_sha256']) !== 1
            || ($decoded['backup_location_class'] ?? '') !== 'OUTSIDE_WEBROOT_PERSISTENT') {
            fail('Backup result failed validation.');
        }
    } elseif (!in_array($decoded['classification'] ?? '', [
        'LEGACY_EXACT',
        'CANONICAL_EXACT',
        'DRIFT',
        'BELOW_066_OR_DRIFT',
    ], true)) {
        fail('Precheck classification is missing or invalid.');
    }
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
if ($command === '--sanitize-result'
    && isset($argv[2], $argv[3], $argv[4])
    && $argv[3] === '--expected-instance-id') {
    sanitize_result($argv[2], $argv[4]);
    exit(0);
}
fail('Usage: php scripts/migration-067-production-precheck.php --verify-source|--sanitize-result FILE');
