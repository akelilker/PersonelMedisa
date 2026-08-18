<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const LOCK_NAME = 'medisa_canonical_migrations';

    /**
     * @param MigrationSourceProvider|string $source
     * @param string|null $baselineVersion
     * @return array{applied: list<string>, pending: list<string>, latest: string|null}
     */
    public static function run(
        PDO $pdo,
        MigrationSourceProvider|string $source,
        ?string $baselineVersion = null
    ): array {
        $migrations = self::resolve($source);
        if ($migrations === []) {
            throw new RuntimeException('No canonical migration files were discovered.');
        }

        self::acquireLock($pdo);
        try {
            $ledgerMigration = $migrations[0];
            $ledgerBootstrapped = false;
            if (!self::tableExists($pdo, 'medisa_schema_migrations')) {
                if ($ledgerMigration['version'] !== '000') {
                    throw new RuntimeException('Migration ledger bootstrap is missing.');
                }
                self::applyOne($pdo, $ledgerMigration);
                $ledgerBootstrapped = true;
            }

            self::ensureLedgerShape($pdo);
            if ($ledgerBootstrapped && $baselineVersion === null) {
                throw new RuntimeException(
                    'Migration ledger was initialized without a production baseline.'
                );
            }
            if ($baselineVersion !== null && self::ledgerHasOnlyBootstrap($pdo)) {
                self::recordBaseline($pdo, $migrations, $baselineVersion);
            }

            $appliedRows = self::readLedger($pdo);
            self::ensureLedgerOrder($migrations, $appliedRows);
            $applied = [];
            $pending = [];

            foreach ($migrations as $migration) {
                $version = $migration['version'];
                $checksum = $migration['checksum'];

                if (isset($appliedRows[$version])) {
                    if (!hash_equals($appliedRows[$version]['checksum'], $checksum)) {
                        throw new RuntimeException("Applied migration checksum mismatch: {$version}");
                    }
                    $applied[] = $version;
                    continue;
                }

                $pending[] = $version;
                self::applyOne($pdo, $migration);
                $appliedRows[$version] = ['checksum' => $checksum];
                $applied[] = $version;
            }

            return [
                'applied' => $applied,
                'pending' => $pending,
                'latest' => $migrations[count($migrations) - 1]['version'],
            ];
        } finally {
            self::releaseLock($pdo);
        }
    }

    /**
     * @param string $migrationDirectory
     * @return list<array{version: string, name: string, checksum: string, sql: string}>
     */
    public static function discover(string $migrationDirectory): array
    {
        return (new FilesystemMigrationSourceProvider($migrationDirectory))->all();
    }

    /**
     * @param MigrationSourceProvider|string $source
     * @return list<array{version: string, name: string, checksum: string, sql: string}>
     */
    private static function resolve(MigrationSourceProvider|string $source): array
    {
        return is_string($source)
            ? self::discover($source)
            : $source->all();
    }

    /**
     * @param array{version: string, name: string, checksum: string, sql: string} $migration
     */
    private static function applyOne(PDO $pdo, array $migration): void
    {
        $sql = $migration['sql'];
        if ($sql === '') {
            throw new RuntimeException("Migration source is unreadable: {$migration['name']}");
        }

        $startedAt = microtime(true);
        try {
            $pdo->beginTransaction();
            $pdo->exec($sql);
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }
            $statement = $pdo->prepare(
                'INSERT INTO medisa_schema_migrations (version, checksum, execution_ms) '
                . 'VALUES (:version, :checksum, :execution_ms)'
            );
            $statement->execute([
                ':version' => $migration['version'],
                ':checksum' => $migration['checksum'],
                ':execution_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new RuntimeException(
                "Canonical migration failed: {$migration['name']}",
                0,
                $exception
            );
        }
    }

    /**
     * @param list<array{version: string, name: string, checksum: string, sql: string}> $migrations
     */
    private static function recordBaseline(PDO $pdo, array $migrations, string $baselineVersion): void
    {
        if (preg_match('/^\d{3}$/', $baselineVersion) !== 1) {
            throw new RuntimeException('Migration baseline must be a three-digit version.');
        }

        $baselineExists = false;
        $statement = $pdo->prepare(
            'INSERT INTO medisa_schema_migrations (version, checksum, execution_ms) '
            . 'VALUES (:version, :checksum, 0)'
        );
        foreach ($migrations as $migration) {
            if ((int) $migration['version'] > (int) $baselineVersion) {
                continue;
            }
            if ($migration['version'] === $baselineVersion) {
                $baselineExists = true;
            }
            if ($migration['version'] !== '000') {
                $statement->execute([
                    ':version' => $migration['version'],
                    ':checksum' => $migration['checksum'],
                ]);
            }
        }

        if (!$baselineExists) {
            throw new RuntimeException("Requested migration baseline is not present: {$baselineVersion}");
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute([':table' => $table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private static function ensureLedgerShape(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'medisa_schema_migrations')) {
            throw new RuntimeException('Migration ledger is not ready.');
        }
    }

    private static function ledgerHasOnlyBootstrap(PDO $pdo): bool
    {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM medisa_schema_migrations WHERE version <> '000'"
        )->fetchColumn() === 0;
    }

    /**
     * @param MigrationSourceProvider|string $source
     * @return array{applied_count: int, pending: list<string>, latest: string|null}
     */
    public static function verify(PDO $pdo, MigrationSourceProvider|string $source): array
    {
        $migrations = self::resolve($source);
        if ($migrations === [] || !self::tableExists($pdo, 'medisa_schema_migrations')) {
            throw new RuntimeException('Canonical migration schema is not ready.');
        }

        $ledger = self::readLedger($pdo);
        self::ensureLedgerOrder($migrations, $ledger);
        $pending = [];
        foreach ($migrations as $migration) {
            if (!isset($ledger[$migration['version']])) {
                $pending[] = $migration['version'];
                continue;
            }
            if (!hash_equals($ledger[$migration['version']]['checksum'], $migration['checksum'])) {
                throw new RuntimeException(
                    "Applied migration checksum mismatch: {$migration['version']}"
                );
            }
        }

        if ($pending !== []) {
            throw new RuntimeException(
                'Schema is not ready; pending canonical migrations remain: '
                . implode(',', $pending)
            );
        }

        return [
            'applied_count' => count($ledger),
            'pending' => [],
            'latest' => $migrations[count($migrations) - 1]['version'],
        ];
    }

    /**
     * @param list<array{version: string, name: string, checksum: string, sql: string}> $migrations
     * @param array<string, array{checksum: string}> $ledger
     */
    private static function ensureLedgerOrder(array $migrations, array $ledger): void
    {
        $pendingSeen = false;
        foreach ($migrations as $migration) {
            $version = $migration['version'];
            if (!isset($ledger[$version])) {
                $pendingSeen = true;
                continue;
            }
            if ($pendingSeen) {
                throw new RuntimeException(
                    "Migration ledger has a gap before applied version: {$version}"
                );
            }
        }
    }

    /**
     * @return array<string, array{checksum: string}>
     */
    private static function readLedger(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT version, checksum FROM medisa_schema_migrations ORDER BY version'
        )->fetchAll(PDO::FETCH_ASSOC);
        $ledger = [];
        foreach ($rows as $row) {
            $ledger[(string) $row['version']] = ['checksum' => (string) $row['checksum']];
        }
        return $ledger;
    }

    private static function acquireLock(PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 60)');
        $statement->execute([':lock_name' => self::LOCK_NAME]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire canonical migration lock.');
        }
    }

    private static function releaseLock(PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute([':lock_name' => self::LOCK_NAME]);
    }
}
