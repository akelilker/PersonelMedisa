<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Pack7F — calisan_kapsami / nullable identity schema readiness owner.
 * Unconditional SELECT/INSERT against the new column is forbidden until ready.
 */
final class PersonelCalisanKapsamSchema
{
    public const ERROR_CODE = 'SCHEMA_NOT_READY';

    public static function isReady(PDO $pdo): bool
    {
        return self::columnExists($pdo, 'personeller', 'calisan_kapsami')
            && self::isColumnNullable($pdo, 'tc_kimlik_no')
            && self::isColumnNullable($pdo, 'soyad')
            && self::isColumnNullable($pdo, 'dogum_tarihi')
            && self::isColumnNullable($pdo, 'telefon');
    }

    public static function isTcNullable(PDO $pdo): bool
    {
        return self::isColumnNullable($pdo, 'tc_kimlik_no');
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function payloadRequestsDisKaynak(array $payload): bool
    {
        if (!array_key_exists('calisan_kapsami', $payload)) {
            return false;
        }

        return PersonelCalisanKapsamService::normalize($payload['calisan_kapsami'])
            === PersonelCalisanKapsamService::DIS_KAYNAK;
    }

    /**
     * DIS_KAYNAK writes require full 066. Omitted / IC_PERSONEL continue on 065.
     *
     * @param array<string, mixed> $payload
     * @throws PersonelValidationException
     */
    public static function assertReadyForDisKaynakWrite(PDO $pdo, array $payload): void
    {
        if (!self::payloadRequestsDisKaynak($payload)) {
            return;
        }
        if (self::isReady($pdo)) {
            return;
        }
        throw new PersonelValidationException(
            'calisan_kapsami',
            'Calisan kapsami semasi henuz hazir degil. DIS_KAYNAK yazilamaz.',
            self::ERROR_CODE
        );
    }

    private static function isColumnNullable(PDO $pdo, string $column): bool
    {
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return self::columnExists($pdo, 'personeller', 'calisan_kapsami');
            }
            $stmt = $pdo->prepare(
                "SELECT IS_NULLABLE FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'personeller'
                   AND column_name = :c
                 LIMIT 1"
            );
            $stmt->execute(['c' => $column]);
            $v = $stmt->fetchColumn();

            return is_string($v) && strtoupper($v) === 'YES';
        } catch (\Throwable $e) {
            return false;
        }
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
