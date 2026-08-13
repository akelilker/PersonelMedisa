<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DependentRetentionGate;
use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * DISIPLIN: delete DISIPLIN surec shell after OLAY/SAVUNMA category material is cleared.
 *
 * Shared disiplin_vakalar row is owned field-wise by OLAY + SAVUNMA (Pack 2).
 * This handler never anonymizes those fields; it DEPENDENCY_GATE then deletes
 * audit → vaka → surec (DELETE_ROWS).
 */
final class DisiplinDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::DISIPLIN;
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
        $surecCount = 0;
        $vakaCount = 0;
        $auditCount = 0;
        if ($surecId > 0 && DependentRetentionGate::tableExists($pdo, 'surecler')) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM surecler WHERE id = :id AND surec_turu = 'DISIPLIN'"
            );
            $stmt->execute(['id' => $surecId]);
            $surecCount = (int) $stmt->fetchColumn();
            if ($surecCount > 0 && DependentRetentionGate::tableExists($pdo, 'disiplin_vakalar')) {
                $v = $pdo->prepare('SELECT id FROM disiplin_vakalar WHERE surec_id = :sid LIMIT 1');
                $v->execute(['sid' => $surecId]);
                $vakaId = (int) ($v->fetchColumn() ?: 0);
                if ($vakaId > 0) {
                    $vakaCount = 1;
                    if (DependentRetentionGate::tableExists($pdo, 'disiplin_vaka_auditleri')) {
                        $a = $pdo->prepare(
                            'SELECT COUNT(*) FROM disiplin_vaka_auditleri WHERE disiplin_vaka_id = :vid'
                        );
                        $a->execute(['vid' => $vakaId]);
                        $auditCount = (int) $a->fetchColumn();
                    }
                }
            }
        }

        return [
            'db_operation_codes' => [
                'GATE_OLAY_SAVUNMA_CLEARED',
                'DELETE_DISIPLIN_VAKA_AUDIT',
                'DELETE_DISIPLIN_VAKA',
                'DELETE_DISIPLIN_SUREC',
            ],
            'expected_row_counts' => [
                'surecler' => $surecCount,
                'disiplin_vakalar' => $vakaCount,
                'disiplin_vaka_auditleri' => $auditCount,
            ],
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
        $surec = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$surec || strtoupper((string) ($surec['surec_turu'] ?? '')) !== 'DISIPLIN') {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId > 0 && (int) ($surec['personel_id'] ?? 0) !== $personelId) {
            throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
        }

        $vaka = null;
        $vakaId = 0;
        if (DependentRetentionGate::tableExists($pdo, 'disiplin_vakalar')) {
            $vStmt = $pdo->prepare(
                'SELECT * FROM disiplin_vakalar WHERE surec_id = :sid LIMIT 1'
            );
            $vStmt->execute(['sid' => $surecId]);
            $vaka = $vStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $vakaId = $vaka ? (int) $vaka['id'] : 0;
        }

        if ($vaka) {
            $this->assertOlaySavunmaCleared($vaka);
        }

        $deletedAudit = 0;
        $deletedVaka = 0;
        if ($vakaId > 0) {
            if (DependentRetentionGate::tableExists($pdo, 'disiplin_vaka_auditleri')) {
                $expectedAudit = isset($plan['expected_row_counts']['disiplin_vaka_auditleri'])
                    ? (int) $plan['expected_row_counts']['disiplin_vaka_auditleri']
                    : -1;
                $delA = $pdo->prepare(
                    'DELETE FROM disiplin_vaka_auditleri WHERE disiplin_vaka_id = :vid'
                );
                $delA->execute(['vid' => $vakaId]);
                $deletedAudit = (int) $delA->rowCount();
                if ($expectedAudit >= 0 && $deletedAudit !== $expectedAudit) {
                    throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
                }
            }
            $delV = $pdo->prepare('DELETE FROM disiplin_vakalar WHERE id = :id AND surec_id = :sid');
            $delV->execute(['id' => $vakaId, 'sid' => $surecId]);
            $deletedVaka = (int) $delV->rowCount();
            if ($deletedVaka !== 1) {
                throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
            }
        }

        $delS = $pdo->prepare("DELETE FROM surecler WHERE id = :id AND surec_turu = 'DISIPLIN'");
        $delS->execute(['id' => $surecId]);
        if ((int) $delS->rowCount() !== 1) {
            throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => [
                    'disiplin_vaka_auditleri' => $deletedAudit,
                    'disiplin_vakalar' => $deletedVaka,
                    'surecler' => 1,
                ],
                'files_deleted' => 0,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $vaka
     */
    private function assertOlaySavunmaCleared(array $vaka)
    {
        // OLAY Pack2 marker — required before shared-row shell delete.
        if ((string) ($vaka['olay_turu'] ?? '') !== 'DESTROYED') {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
        }

        // SAVUNMA Pack2 marker OR never-requested (all fields empty).
        if ((string) ($vaka['savunma_konu'] ?? '') === 'DESTROYED') {
            return;
        }
        foreach ([
            'savunma_konu',
            'savunma_yer',
            'savunma_talep_tarihi',
            'savunma_deadline_at',
            'savunma_belge_surec_id',
            'savunma_received_at',
            'savunma_isteyen_user_id',
        ] as $field) {
            if (isset($vaka[$field]) && $vaka[$field] !== null && (string) $vaka[$field] !== '') {
                throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
            }
        }
    }
}
