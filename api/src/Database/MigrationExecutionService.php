<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;
use PDOException;
use Throwable;

final class MigrationExecutionService
{
    /**
     * @return array{applied: list<string>, pending: list<string>, latest: string|null}
     */
    public static function apply(
        PDO $pdo,
        MigrationSourceProvider|string $source,
        ?string $baseline
    ): array
    {
        return MigrationRunner::run($pdo, $source, $baseline);
    }

    /**
     * @return array{applied_count: int, pending: list<string>, latest: string|null}
     */
    public static function verify(PDO $pdo, MigrationSourceProvider|string $source): array
    {
        return MigrationRunner::verify($pdo, $source);
    }

    public static function sourceForRuntime(
        string $apiDirectory,
        bool $requireBundle = false
    ): MigrationSourceProvider
    {
        $bundlePath = rtrim($apiDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'runtime-build'
            . DIRECTORY_SEPARATOR . 'canonical-migrations.php';
        if (is_file($bundlePath)) {
            return new BundledMigrationSourceProvider($bundlePath);
        }
        if ($requireBundle) {
            throw new \RuntimeException('Canonical migration bundle is missing.');
        }

        return new FilesystemMigrationSourceProvider(
            rtrim($apiDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'migrations'
        );
    }

    public static function classify(Throwable $exception): string
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
        if (str_contains($message, 'migration ledger was initialized without')) {
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
            || str_contains($message, 'canonical migration bundle')
            || str_contains($message, 'migration ledger bootstrap is missing')
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

    public static function safeDetail(Throwable $exception): ?string
    {
        $reason = self::classify($exception);
        $message = $exception->getMessage();

        if (preg_match('/password|passwd|secret|token|dsn|authorization|bearer/i', $message) === 1) {
            return null;
        }
        if (in_array($reason, ['DB_CONNECTION_FAILED', 'PHP_EXTENSION_MISSING'], true)) {
            return null;
        }
        if ($reason === 'MIGRATION_CHECKSUM_MISMATCH' && preg_match('/:\s*([0-9]{3})$/', $message, $matches) === 1) {
            return 'migration_version=' . $matches[1];
        }
        if ($reason === 'MIGRATION_LEDGER_GAP' && preg_match('/:\s*([0-9]{3})$/', $message, $matches) === 1) {
            return 'migration_version=' . $matches[1];
        }
        if ($reason === 'MIGRATION_SOURCE_MISSING' && preg_match('/:\s*([A-Za-z0-9_.-]+)$/', $message, $matches) === 1) {
            return 'migration_source=' . $matches[1];
        }
        if ($reason === 'MIGRATION_APPLY_FAILED' && preg_match('/:\s*([A-Za-z0-9_.-]+)$/', $message, $matches) === 1) {
            return 'migration_source=' . $matches[1];
        }

        return null;
    }
}
