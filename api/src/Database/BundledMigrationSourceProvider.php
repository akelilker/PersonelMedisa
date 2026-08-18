<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use RuntimeException;

final class BundledMigrationSourceProvider implements MigrationSourceProvider
{
    public function __construct(private readonly string $bundlePath)
    {
    }

    /**
     * @return list<array{version: string, name: string, checksum: string, sql: string}>
     */
    public function all(): array
    {
        if (!is_file($this->bundlePath) || !is_readable($this->bundlePath)) {
            throw new RuntimeException('Canonical migration bundle is missing.');
        }

        $bundle = require $this->bundlePath;
        if (!is_array($bundle) || $bundle === []) {
            throw new RuntimeException('Canonical migration bundle is malformed.');
        }

        $migrations = [];
        $seenVersions = [];
        $previousVersion = null;
        foreach ($bundle as $entry) {
            if (
                !is_array($entry)
                || !isset($entry['version'], $entry['name'], $entry['checksum'], $entry['sql_base64'])
                || !is_string($entry['version'])
                || !is_string($entry['name'])
                || !is_string($entry['checksum'])
                || !is_string($entry['sql_base64'])
            ) {
                throw new RuntimeException('Canonical migration bundle is malformed.');
            }
            if (preg_match('/^\d{3}$/', $entry['version']) !== 1 || preg_match('/^[a-f0-9]{64}$/', $entry['checksum']) !== 1) {
                throw new RuntimeException('Canonical migration bundle is malformed.');
            }
            if ($previousVersion !== null && (int) $entry['version'] <= (int) $previousVersion) {
                throw new RuntimeException('Canonical migration bundle ordering is invalid.');
            }
            if (isset($seenVersions[$entry['version']])) {
                throw new RuntimeException(
                    'Duplicate canonical migration version: ' . $entry['version']
                );
            }
            $sql = base64_decode($entry['sql_base64'], true);
            if ($sql === false || $sql === '') {
                throw new RuntimeException('Canonical migration bundle contains empty SQL.');
            }
            if (!hash_equals($entry['checksum'], hash('sha256', $sql))) {
                throw new RuntimeException(
                    'Canonical migration bundle checksum mismatch: ' . $entry['version']
                );
            }
            $seenVersions[$entry['version']] = true;
            $previousVersion = $entry['version'];
            $migrations[] = [
                'version' => $entry['version'],
                'name' => $entry['name'],
                'checksum' => $entry['checksum'],
                'sql' => $sql,
            ];
        }

        if ($migrations[0]['version'] !== '000') {
            throw new RuntimeException('Migration ledger bootstrap is missing.');
        }

        return $migrations;
    }
}
