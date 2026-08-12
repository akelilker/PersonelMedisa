<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * IZIN: delete the canonical IZIN surec row only (no cross-personel / other tur).
 * Blocked if SGK belge links or disiplin vaka still reference the surec.
 */
final class IzinDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::IZIN;
    }

    public function executionMode()
    {
        return PhysicalDestructionCodes::MODE_DELETE_ROWS;
    }

    public function isExecutable()
    {
        return true;
    }

    public function plan(PDO $pdo, array $talep, array $context)
    {
        $surecId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        $count = 0;
        if ($surecId > 0) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM surecler WHERE id = :id AND surec_turu = 'IZIN'"
            );
            $stmt->execute(['id' => $surecId]);
            $count = (int) $stmt->fetchColumn();
        }

        return [
            'db_operation_codes' => ['DELETE_IZIN_SUREC'],
            'expected_row_counts' => ['surecler' => $count],
            'external_file_count' => 0,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $surecId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        if ($surecId <= 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }

        $stmt = $pdo->prepare(
            "SELECT id, personel_id, surec_turu FROM surecler WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || strtoupper((string) ($row['surec_turu'] ?? '')) !== 'IZIN') {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId > 0 && (int) ($row['personel_id'] ?? 0) !== $personelId) {
            throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
        }

        if ($this->tableExists($pdo, 'sgk_belge_surec_baglantilari')) {
            $c = $pdo->prepare('SELECT COUNT(*) FROM sgk_belge_surec_baglantilari WHERE surec_id = :id');
            $c->execute(['id' => $surecId]);
            if ((int) $c->fetchColumn() > 0) {
                throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
            }
        }
        if ($this->tableExists($pdo, 'disiplin_vakalar')) {
            $c = $pdo->prepare('SELECT COUNT(*) FROM disiplin_vakalar WHERE surec_id = :id');
            $c->execute(['id' => $surecId]);
            if ((int) $c->fetchColumn() > 0) {
                throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
            }
        }

        $del = $pdo->prepare("DELETE FROM surecler WHERE id = :id AND surec_turu = 'IZIN'");
        $del->execute(['id' => $surecId]);
        if ((int) $del->rowCount() !== 1) {
            throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => ['surecler' => 1],
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
