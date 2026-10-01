<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * 095 — personeller.cinsiyet schema readiness owner.
 * Unconditional SELECT/INSERT/UPDATE against the column is forbidden until ready.
 */
final class PersonelCinsiyetSchema
{
    public const ERROR_CODE = 'SCHEMA_NOT_READY';

    public static function isReady(PDO $pdo): bool
    {
        return self::columnExists($pdo, 'personeller', 'cinsiyet');
    }

    /**
     * @param array<string, mixed> $payload
     * @throws PersonelValidationException
     */
    public static function assertReadyForWrite(PDO $pdo, array $payload): void
    {
        if (!array_key_exists('cinsiyet', $payload)) {
            return;
        }
        if (self::isReady($pdo)) {
            return;
        }
        throw new PersonelValidationException(
            'cinsiyet',
            'Cinsiyet semasi henuz hazir degil.',
            self::ERROR_CODE
        );
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $stmt = $pdo->query('PRAGMA table_info(' . $pdo->quote($table) . ')');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    if ((string) ($row['name'] ?? '') === $column) {
                        return true;
                    }
                }

                return false;
            }
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c'
            );
            $stmt->execute(['t' => $table, 'c' => $column]);

            return (int) $stmt->fetchColumn() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
