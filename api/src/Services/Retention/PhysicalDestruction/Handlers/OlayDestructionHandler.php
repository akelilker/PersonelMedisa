<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * OLAY: anonymize olay-scoped fields on disiplin_vakalar (shared row with SAVUNMA).
 * Does not delete shared parent while SAVUNMA fields remain meaningful — field-level only.
 */
final class OlayDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::OLAY;
    }

    public function executionMode()
    {
        return PhysicalDestructionCodes::MODE_ANONYMIZE_FIELDS;
    }

    public function isExecutable()
    {
        return true;
    }

    public function plan(PDO $pdo, array $talep, array $context)
    {
        $vakaId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        $count = 0;
        if ($vakaId > 0 && $this->tableExists($pdo, 'disiplin_vakalar')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM disiplin_vakalar WHERE id = :id');
            $stmt->execute(['id' => $vakaId]);
            $count = (int) $stmt->fetchColumn();
        }

        return [
            'db_operation_codes' => ['ANONYMIZE_DISIPLIN_VAKA_OLAY_FIELDS'],
            'expected_row_counts' => ['disiplin_vakalar' => $count],
            'external_file_count' => 0,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $vakaId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        if ($vakaId <= 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }

        $stmt = $pdo->prepare('SELECT id, personel_id, olay_turu FROM disiplin_vakalar WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $vakaId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }
        if ((string) ($row['olay_turu'] ?? '') === 'DESTROYED') {
            // Idempotent field state without prior execution evidence is still "already missing" semantics
            // for first execute — treat as already anonymized target absent of new work.
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId > 0 && (int) ($row['personel_id'] ?? 0) !== $personelId) {
            throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
        }

        // source_hash UNIQUE — rewrite to deterministic destroyed hash for this vaka.
        $newHash = hash('sha256', 'destroyed:olay:disiplin_vaka:' . $vakaId);
        $upd = $pdo->prepare(
            "UPDATE disiplin_vakalar SET
                olay_turu = 'DESTROYED',
                raw_dakika = NULL,
                source_identity = :sid,
                source_hash = :sh,
                nihai_karar = NULL,
                nihai_karar_gerekce = NULL
             WHERE id = :id"
        );
        $upd->execute([
            'sid' => 'destroyed:olay:' . $vakaId,
            'sh' => $newHash,
            'id' => $vakaId,
        ]);

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_anonymized' => ['disiplin_vakalar' => 1],
                'files_deleted' => 0,
            ],
        ];
    }

    private function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }
}
