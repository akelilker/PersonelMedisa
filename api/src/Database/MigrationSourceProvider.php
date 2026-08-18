<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

interface MigrationSourceProvider
{
    /**
     * @return list<array{version: string, name: string, checksum: string, sql: string}>
     */
    public function all(): array;
}
