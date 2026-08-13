<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DependentRetentionGate;
use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use PDO;
use RuntimeException;

/**
 * Shared DELETE_ROWS for termination-scoped surec categories (RAPOR / IS_KAZASI).
 * Mirrors IZIN leaf scope + SGK/finans/resmi-etki dependency gates. Never touches other tur.
 */
abstract class SurecTurDestructionHandler implements DestructionHandlerInterface
{
    /** @return string RetentionCategories::* */
    abstract public function category();

    /** @return string surecler.surec_turu */
    abstract protected function surecTuru();

    /** @return string operation code for plan */
    abstract protected function deleteOperationCode();

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
        $tur = $this->surecTuru();
        $count = 0;
        if ($surecId > 0 && DependentRetentionGate::tableExists($pdo, 'surecler')) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM surecler WHERE id = :id AND surec_turu = :tur'
            );
            $stmt->execute(['id' => $surecId, 'tur' => $tur]);
            $count = (int) $stmt->fetchColumn();
        }

        return [
            'db_operation_codes' => [$this->deleteOperationCode()],
            'expected_row_counts' => ['surecler' => $count],
            'external_file_count' => 0,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $surecId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        $tur = $this->surecTuru();
        if ($surecId <= 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }

        $stmt = $pdo->prepare(
            'SELECT id, personel_id, surec_turu FROM surecler WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || strtoupper((string) ($row['surec_turu'] ?? '')) !== strtoupper($tur)) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId > 0 && (int) ($row['personel_id'] ?? 0) !== $personelId) {
            throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
        }

        DependentRetentionGate::assertSurecLifecycleDependentsClear($pdo, $surecId);

        $del = $pdo->prepare('DELETE FROM surecler WHERE id = :id AND surec_turu = :tur');
        $del->execute(['id' => $surecId, 'tur' => $tur]);
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
}
