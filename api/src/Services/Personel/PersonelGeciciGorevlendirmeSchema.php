<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * 076 personel_gecici_gorevlendirmeler readiness.
 */
final class PersonelGeciciGorevlendirmeSchema
{
    public const ERROR_CODE = 'GECICI_GOREVLENDIRME_SCHEMA_NOT_READY';

    public static function isReady(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'personel_gecici_gorevlendirmeler'
                 LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function assertReady(PDO $pdo): void
    {
        if (!self::isReady($pdo)) {
            throw new PersonelValidationException(
                'schema',
                'Gecici gorevlendirme semasi hazir degil (migration 076).',
                self::ERROR_CODE
            );
        }
    }
}
