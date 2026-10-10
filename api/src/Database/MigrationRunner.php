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
     * Yalnız mevcut kurulum verisini düzelten (şirkete özgü katalog düzeltmesi) migration'lar.
     * Hedef tabloların HEPSİ boşsa (yeni şirket kurulumu) düzeltilecek veri yoktur: dosya
     * çalıştırılmaz, aynı checksum ile ledger'a kaydedilir. Tablolarda tek satır bile varsa
     * migration normal çalışır ve kendi fail-closed kapılarını uygular. Dosya/checksum değişmez.
     *
     * @var array<string, list<string>>
     */
    private const DATA_CORRECTION_EMPTY_CATALOG_TABLES = [
        '067' => ['departmanlar', 'bolumler', 'birimler', 'personeller'],
    ];

    /**
     * @param MigrationSourceProvider|string $source
     * @param string|null $baselineVersion
     * @param string|null $applyThroughVersion Highest version this run may apply.
     *        Null keeps the historical behaviour of draining the whole chain. A
     *        version stops the run after it, so a control-plane request can own
     *        exactly one migration and exactly one backup.
     * @return array{applied: list<string>, pending: list<string>, latest: string|null}
     */
    public static function run(
        PDO $pdo,
        MigrationSourceProvider|string $source,
        ?string $baselineVersion = null,
        ?string $applyThroughVersion = null
    ): array {
        if ($applyThroughVersion !== null && preg_match('/^\d{3}$/', $applyThroughVersion) !== 1) {
            throw new RuntimeException('Migration target must be a three-digit version.');
        }
        $migrations = self::resolve($source);
        if ($migrations === []) {
            throw new RuntimeException('No canonical migration files were discovered.');
        }
        if ($applyThroughVersion !== null && !self::chainHasVersion($migrations, $applyThroughVersion)) {
            throw new RuntimeException("Migration target is not in the canonical chain: {$applyThroughVersion}");
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

                if ($applyThroughVersion !== null && (int) $version > (int) $applyThroughVersion) {
                    // Everything past the authorized target stays pending: a later
                    // request applies it behind its own backup.
                    break;
                }

                $pending[] = $version;
                if (self::isDataCorrectionOnEmptyCatalog($pdo, $version)) {
                    self::recordWithoutExecution($pdo, $migration);
                    $appliedRows[$version] = ['checksum' => $checksum];
                    $applied[] = $version;
                    continue;
                }
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

    private static function isDataCorrectionOnEmptyCatalog(PDO $pdo, string $version): bool
    {
        $tables = self::DATA_CORRECTION_EMPTY_CATALOG_TABLES[$version] ?? null;
        if ($tables === null) {
            return false;
        }
        foreach ($tables as $table) {
            if (!self::tableExists($pdo, $table)) {
                return false;
            }
            $row = $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            $hasRow = $row !== false && $row->fetchColumn() !== false;
            if ($row !== false) {
                $row->closeCursor();
            }
            if ($hasRow) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{version: string, name: string, checksum: string, sql: string} $migration
     */
    private static function recordWithoutExecution(PDO $pdo, array $migration): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO medisa_schema_migrations (version, checksum, execution_ms) '
            . 'VALUES (:version, :checksum, 0)'
        );
        $statement->execute([
            ':version' => $migration['version'],
            ':checksum' => $migration['checksum'],
        ]);
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
     * @param string|null $expectedThroughVersion Highest version expected to be
     *        applied. Null demands a fully drained chain; a version accepts the
     *        remainder as pending but still refuses anything applied beyond it.
     * @return array{applied_count: int, pending: list<string>, latest: string|null}
     */
    public static function verify(
        PDO $pdo,
        MigrationSourceProvider|string $source,
        ?string $expectedThroughVersion = null
    ): array {
        $migrations = self::resolve($source);
        if ($migrations === [] || !self::tableExists($pdo, 'medisa_schema_migrations')) {
            throw new RuntimeException('Canonical migration schema is not ready.');
        }
        if ($expectedThroughVersion !== null && !self::chainHasVersion($migrations, $expectedThroughVersion)) {
            throw new RuntimeException(
                "Migration target is not in the canonical chain: {$expectedThroughVersion}"
            );
        }

        $ledger = self::readLedger($pdo);
        self::ensureLedgerOrder($migrations, $ledger);
        $pending = [];
        foreach ($migrations as $migration) {
            if (!isset($ledger[$migration['version']])) {
                if ($expectedThroughVersion !== null
                    && (int) $migration['version'] <= (int) $expectedThroughVersion
                ) {
                    throw new RuntimeException(
                        'Authorized migration was not applied: ' . $migration['version']
                    );
                }
                $pending[] = $migration['version'];
                continue;
            }
            if ($expectedThroughVersion !== null
                && (int) $migration['version'] > (int) $expectedThroughVersion
            ) {
                throw new RuntimeException(
                    'Migration applied beyond the authorized target: ' . $migration['version']
                );
            }
            if (!hash_equals($ledger[$migration['version']]['checksum'], $migration['checksum'])) {
                throw new RuntimeException(
                    "Applied migration checksum mismatch: {$migration['version']}"
                );
            }
        }

        if ($expectedThroughVersion === null && $pending !== []) {
            throw new RuntimeException(
                'Schema is not ready; pending canonical migrations remain: '
                . implode(',', $pending)
            );
        }

        return [
            'applied_count' => count($ledger),
            'pending' => $pending,
            'latest' => $migrations[count($migrations) - 1]['version'],
        ];
    }

    /**
     * @param list<array{version: string, name: string, checksum: string, sql: string}> $migrations
     */
    private static function chainHasVersion(array $migrations, string $version): bool
    {
        foreach ($migrations as $migration) {
            if ((string) $migration['version'] === $version) {
                return true;
            }
        }

        return false;
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
