<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use PDO;
use RuntimeException;

/**
 * Fail-closed schema readiness checks for Phase C retention tables.
 * Missing legal_holdlar must never be treated as "no hold".
 */
class RetentionSchemaGate
{
    public const CODE_SCHEMA_NOT_READY = 'SCHEMA_NOT_READY';

    /** @return array<int, string> */
    public static function archiveAccessTables()
    {
        return ['arsiv_erisim_auditleri'];
    }

    /** @return array<int, string> */
    public static function legalHoldTables()
    {
        return ['legal_holdlar', 'legal_hold_auditleri'];
    }

    /** @return array<int, string> */
    public static function destructionTables()
    {
        return [
            'arsiv_manifestleri',
            'legal_holdlar',
            'legal_hold_auditleri',
            'arsiv_erisim_auditleri',
            'retention_imha_talepleri',
            'retention_imha_auditleri',
        ];
    }

    /** @return array<int, string> */
    public static function physicalDestructionTables()
    {
        return array_merge(self::destructionTables(), [
            'retention_imha_executionlari',
        ]);
    }

    /** @return array<int, string> */
    public static function manifestTables()
    {
        return ['arsiv_manifestleri'];
    }

    /**
     * @param array<int, string> $requiredTables
     */
    public static function assertReady(PDO $pdo, array $requiredTables)
    {
        foreach ($requiredTables as $table) {
            if (!self::tableExists($pdo, (string) $table)) {
                throw new RuntimeException(self::CODE_SCHEMA_NOT_READY);
            }
        }
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }
}
