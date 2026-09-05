<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Append-only durable audit for HISTORICAL_EXIT_DATE_CORRECTION.
 * Must be written in the same transaction as surec UPDATE + retention remint.
 */
final class PersonelHistoricalExitDateCorrectionAuditService
{
    public const TABLE = 'personel_historical_exit_date_correction_auditleri';

    public static function hasTable(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . self::TABLE . "'");
            if ($stmt && $stmt->fetch()) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :n LIMIT 1"
            );
            $stmt->execute(['n' => self::TABLE]);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Must be called inside an open DB transaction owned by the mutation caller.
     *
     * @param array{
     *   actor_user_id:int,
     *   mutation_id:?string,
     *   personel_id:int,
     *   surec_id:int,
     *   old_baslangic_tarihi:string,
     *   old_bitis_tarihi:string,
     *   new_baslangic_tarihi:string,
     *   new_bitis_tarihi:string,
     *   old_aciklama:?string,
     *   reason:?string
     * } $record
     * @return int inserted audit id
     */
    public static function appendInTransaction(PDO $pdo, array $record): int
    {
        if (!self::hasTable($pdo)) {
            throw new \RuntimeException(self::TABLE . ' missing');
        }

        $actorUserId = (int) ($record['actor_user_id'] ?? 0);
        if ($actorUserId <= 0) {
            throw new \RuntimeException('HISTORICAL_EXIT_CORRECTION_AUDIT_ACTOR_REQUIRED');
        }

        $mutationId = isset($record['mutation_id']) ? trim((string) $record['mutation_id']) : '';
        $mutationId = $mutationId !== '' ? $mutationId : null;

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                operation_type, actor_user_id, mutation_id, personel_id, surec_id,
                old_baslangic_tarihi, old_bitis_tarihi,
                new_baslangic_tarihi, new_bitis_tarihi,
                old_aciklama, reason
             ) VALUES (
                :operation_type, :actor_user_id, :mutation_id, :personel_id, :surec_id,
                :old_baslangic_tarihi, :old_bitis_tarihi,
                :new_baslangic_tarihi, :new_bitis_tarihi,
                :old_aciklama, :reason
             )'
        );
        $stmt->execute([
            'operation_type' => PersonelHistoricalExitDateCorrectionService::OPERATION_TYPE,
            'actor_user_id' => $actorUserId,
            'mutation_id' => $mutationId,
            'personel_id' => (int) $record['personel_id'],
            'surec_id' => (int) $record['surec_id'],
            'old_baslangic_tarihi' => (string) $record['old_baslangic_tarihi'],
            'old_bitis_tarihi' => (string) $record['old_bitis_tarihi'],
            'new_baslangic_tarihi' => (string) $record['new_baslangic_tarihi'],
            'new_bitis_tarihi' => (string) $record['new_bitis_tarihi'],
            'old_aciklama' => $record['old_aciklama'] ?? null,
            'reason' => $record['reason'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }
}
