<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use RuntimeException;

final class FilesystemMigrationSourceProvider implements MigrationSourceProvider
{
    public function __construct(private readonly string $migrationDirectory)
    {
    }

    /**
     * @return list<array{version: string, name: string, checksum: string, sql: string}>
     */
    public function all(): array
    {
        $paths = [];
        $ledgerPath = dirname($this->migrationDirectory)
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Database'
            . DIRECTORY_SEPARATOR . 'migration_ledger.sql';

        if (is_file($ledgerPath)) {
            $paths[] = ['version' => '000', 'name' => 'migration_ledger.sql', 'path' => $ledgerPath];
        }

        foreach (glob(rtrim($this->migrationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [] as $path) {
            $name = basename($path);
            if (preg_match('/^(\d+)_([A-Za-z0-9_-]+)\.sql$/', $name, $matches) !== 1) {
                continue;
            }
            $paths[] = [
                'version' => str_pad($matches[1], 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'path' => $path,
            ];
        }

        $migrations = [];
        foreach ($paths as $source) {
            $sql = file_get_contents($source['path']);
            if ($sql === false || $sql === '') {
                throw new RuntimeException('Migration source is unreadable: ' . $source['name']);
            }
            $migrations[] = [
                'version' => $source['version'],
                'name' => $source['name'],
                'checksum' => hash('sha256', $sql),
                'sql' => $sql,
            ];
        }

        usort(
            $migrations,
            static fn (array $left, array $right): int => [
                (int) $left['version'],
                $left['name'],
            ] <=> [
                (int) $right['version'],
                $right['name'],
            ]
        );

        for ($index = 1, $count = count($migrations); $index < $count; $index++) {
            if ($migrations[$index - 1]['version'] === $migrations[$index]['version']) {
                throw new RuntimeException(
                    'Duplicate canonical migration version: ' . $migrations[$index]['version']
                );
            }
        }

        if ($migrations === [] || $migrations[0]['version'] !== '000') {
            throw new RuntimeException('Migration ledger bootstrap is missing.');
        }

        return $migrations;
    }
}
