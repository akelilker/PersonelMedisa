<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction;

use Medisa\Api\Services\PuantajDonemReopenException;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;

/**
 * Post-destruction lifecycle gate for PUANTAJ periods.
 *
 * Source of truth: retention_imha_executionlari (EXECUTED) joined to
 * retention_imha_talepleri (category=PUANTAJ, canonical period).
 *
 * Preserved seal headers after snapshot-pinned destroy are evidence-only;
 * they must not re-enter reopen/reseal write lifecycle.
 */
final class PuantajPhysicalDestructionGate
{
    public const CODE_PERIOD_PHYSICALLY_DESTROYED = 'PUANTAJ_PERIOD_PHYSICALLY_DESTROYED';
    public const CODE_OPEN_REOPEN_REQUEST_EXISTS = 'PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS';
    public const CODE_SOURCE_ALREADY_DESTROYED_AS_APPROVED = 'SOURCE_ALREADY_DESTROYED_AS_APPROVED';

    /**
     * @param string|null $sourceVersionIdentity optional exact identity parity check
     */
    public static function isPeriodDestroyed(PDO $pdo, $subeId, $yil, $ay, $sourceVersionIdentity = null)
    {
        return self::findExecutedEvidence($pdo, $subeId, $yil, $ay, $sourceVersionIdentity) !== null;
    }

    /**
     * @throws PuantajDonemReopenException
     */
    public static function assertPeriodNotDestroyed(PDO $pdo, $subeId, $yil, $ay)
    {
        if (self::isPeriodDestroyed($pdo, $subeId, $yil, $ay)) {
            throw new PuantajDonemReopenException(
                self::CODE_PERIOD_PHYSICALLY_DESTROYED,
                'Donem fiziksel imha edilmis; reopen/reseal yazimi engellendi.',
                409,
                [
                    'sube_id' => (int) $subeId,
                    'yil' => (int) $yil,
                    'ay' => (int) $ay,
                ]
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findExecutedEvidence(PDO $pdo, $subeId, $yil, $ay, $sourceVersionIdentity = null)
    {
        $subeId = (int) $subeId;
        $yil = (int) $yil;
        $ay = (int) $ay;
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            return null;
        }
        if (!self::tableExists($pdo, 'retention_imha_executionlari')
            || !self::tableExists($pdo, 'retention_imha_talepleri')
        ) {
            return null;
        }

        $sql = "SELECT e.id AS execution_id, e.imha_talep_id, e.result_code,
                       e.source_version_identity_snapshot AS execution_source_identity,
                       t.source_version_identity_snapshot AS talep_source_identity,
                       t.record_id, t.canonical_sube_id, t.period_yil, t.period_ay
                FROM retention_imha_executionlari e
                INNER JOIN retention_imha_talepleri t ON t.id = e.imha_talep_id
                WHERE e.execution_state = :state
                  AND t.category = :category
                  AND t.canonical_sube_id = :sube_id
                  AND t.period_yil = :yil
                  AND t.period_ay = :ay";
        $params = [
            'state' => PhysicalDestructionCodes::STATE_EXECUTED,
            'category' => RetentionCategories::PUANTAJ,
            'sube_id' => $subeId,
            'yil' => $yil,
            'ay' => $ay,
        ];

        $sourceVersionIdentity = $sourceVersionIdentity !== null
            ? trim((string) $sourceVersionIdentity)
            : '';
        if ($sourceVersionIdentity !== '') {
            $sql .= ' AND (
                t.source_version_identity_snapshot = :svi
                OR e.source_version_identity_snapshot = :svi
            )';
            $params['svi'] = $sourceVersionIdentity;
        }

        $sql .= ' ORDER BY e.id DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
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
