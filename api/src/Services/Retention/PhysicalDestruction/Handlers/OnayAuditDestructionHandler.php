<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * ONAY_AUDIT typed S3F ledger: chain-aware delete of the target decision and its superseding descendants.
 * Generic parent-only ONAY_AUDIT (no qr_pc_decision entity) → POLICY fail-closed (no standalone rows).
 */
final class OnayAuditDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::ONAY_AUDIT;
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
        $entityType = strtolower((string) ($talep['entity_type'] ?? $context['entity_type'] ?? ''));
        if ($entityType !== 'qr_pc_decision') {
            return [
                'db_operation_codes' => ['POLICY_BLOCK_GENERIC_ONAY_AUDIT'],
                'expected_row_counts' => [],
                'external_file_count' => 0,
                'policy_blocker' => 'Generic parent ONAY_AUDIT has no standalone physical rows; destroy via parent category',
            ];
        }

        $ledgerId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        $chain = $this->collectChainIds($pdo, $ledgerId);

        return [
            'db_operation_codes' => ['DELETE_QR_PC_DECISION_LEDGER_CHAIN_LEAF_FIRST'],
            'expected_row_counts' => [
                'qr_puantaj_candidate_decision_ledger' => count($chain),
            ],
            'external_file_count' => 0,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $entityType = strtolower((string) ($talep['entity_type'] ?? $context['entity_type'] ?? ''));
        if ($entityType !== 'qr_pc_decision') {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED);
        }

        $ledgerId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        if ($ledgerId <= 0 || !$this->tableExists($pdo, 'qr_puantaj_candidate_decision_ledger')) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $stmt = $pdo->prepare(
            'SELECT id, personel_id FROM qr_puantaj_candidate_decision_ledger WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $ledgerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $personelId = isset($context['personel_id']) ? (int) $context['personel_id'] : 0;
        if ($personelId > 0 && (int) ($row['personel_id'] ?? 0) !== $personelId) {
            throw new RuntimeException('RETENTION_TARGET_PERSONEL_MISMATCH');
        }

        $chain = $this->collectChainIds($pdo, $ledgerId);
        $expected = isset($plan['expected_row_counts']['qr_puantaj_candidate_decision_ledger'])
            ? (int) $plan['expected_row_counts']['qr_puantaj_candidate_decision_ledger']
            : -1;
        if ($expected >= 0 && count($chain) !== $expected) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }
        if (count($chain) === 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        // Leaf-first: reverse topological by deleting nodes that nothing supersedes first.
        $deleted = 0;
        $remaining = $chain;
        $guard = 0;
        while (count($remaining) > 0 && $guard < 1000) {
            $guard++;
            $progress = false;
            foreach ($remaining as $idx => $id) {
                $c = $pdo->prepare(
                    'SELECT COUNT(*) FROM qr_puantaj_candidate_decision_ledger
                     WHERE supersedes_decision_id = :id'
                );
                $c->execute(['id' => $id]);
                if ((int) $c->fetchColumn() > 0) {
                    continue;
                }
                $del = $pdo->prepare('DELETE FROM qr_puantaj_candidate_decision_ledger WHERE id = :id');
                $del->execute(['id' => $id]);
                $deleted += (int) $del->rowCount();
                unset($remaining[$idx]);
                $progress = true;
            }
            if (!$progress) {
                throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
            }
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => [
                    'qr_puantaj_candidate_decision_ledger' => $deleted,
                ],
                'files_deleted' => 0,
            ],
        ];
    }

    /**
     * Target decision + descendants that supersede it (directly/indirectly).
     *
     * @return list<int>
     */
    private function collectChainIds(PDO $pdo, $rootId)
    {
        $rootId = (int) $rootId;
        if ($rootId <= 0 || !$this->tableExists($pdo, 'qr_puantaj_candidate_decision_ledger')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id FROM qr_puantaj_candidate_decision_ledger WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $rootId]);
        if (!$stmt->fetchColumn()) {
            return [];
        }

        $ids = [$rootId];
        $frontier = [$rootId];
        $guard = 0;
        while (count($frontier) > 0 && $guard < 1000) {
            $guard++;
            $id = array_shift($frontier);
            $child = $pdo->prepare(
                'SELECT id FROM qr_puantaj_candidate_decision_ledger
                 WHERE supersedes_decision_id = :id'
            );
            $child->execute(['id' => $id]);
            while ($cid = $child->fetchColumn()) {
                $cid = (int) $cid;
                if (!in_array($cid, $ids, true)) {
                    $ids[] = $cid;
                    $frontier[] = $cid;
                }
            }
        }

        return $ids;
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
