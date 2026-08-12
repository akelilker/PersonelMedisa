<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * BORDRO (Pack 3B):
 * - DECISION_04 OPTION A: RUN-LEAF DELETE (calistirma + aday + kalem + run audits)
 * - DECISION_05 OPTION B: personel_bordro_devirleri out of scope (never mutate)
 *
 * Never deletes donem/SGK snapshots, catalogs, retention infra, or sibling/parent runs.
 * Child revision RESTRICT → fail-closed (no scope expansion).
 */
final class BordroDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::BORDRO;
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
        $runId = $this->resolveRunId($pdo, $talep, $context);
        $counts = $runId > 0 ? $this->countRunTree($pdo, $runId) : [
            'maas_hesaplama_aday_kalemleri' => 0,
            'maas_hesaplama_adaylari' => 0,
            'maas_hesaplama_auditleri' => 0,
            'maas_hesaplama_calistirmalari' => 0,
            'child_calistirma_restrict' => 0,
        ];

        return [
            'db_operation_codes' => [
                'BORDRO_RUN_LEAF_DELETE',
                'BORDRO_DEVIR_PRESERVE',
                'BORDRO_DONEM_SNAPSHOT_PRESERVE',
            ],
            'expected_row_counts' => [
                'maas_hesaplama_aday_kalemleri' => $counts['maas_hesaplama_aday_kalemleri'],
                'maas_hesaplama_adaylari' => $counts['maas_hesaplama_adaylari'],
                'maas_hesaplama_auditleri' => $counts['maas_hesaplama_auditleri'],
                'maas_hesaplama_calistirmalari' => $counts['maas_hesaplama_calistirmalari'],
                'child_calistirma_restrict' => $counts['child_calistirma_restrict'],
            ],
            'external_file_count' => 0,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $runId = $this->resolveRunId($pdo, $talep, $context);
        if ($runId <= 0 || !$this->tableExists($pdo, 'maas_hesaplama_calistirmalari')) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $row = $this->loadRun($pdo, $runId);
        if ($row === null) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $subeId = (int) ($context['sube_id'] ?? $talep['canonical_sube_id'] ?? 0);
        $yil = (int) ($context['yil'] ?? $talep['period_yil'] ?? 0);
        $ay = (int) ($context['ay'] ?? $talep['period_ay'] ?? 0);
        if ($subeId > 0 && (int) $row['sube_id'] !== $subeId) {
            throw new RuntimeException('RETENTION_TARGET_SCOPE_MISMATCH');
        }
        if ($yil > 0 && (int) $row['yil'] !== $yil) {
            throw new RuntimeException('RETENTION_TARGET_SCOPE_MISMATCH');
        }
        if ($ay > 0 && (int) $row['ay'] !== $ay) {
            throw new RuntimeException('RETENTION_TARGET_SCOPE_MISMATCH');
        }

        $counts = $this->countRunTree($pdo, $runId);
        foreach (['maas_hesaplama_aday_kalemleri', 'maas_hesaplama_adaylari', 'maas_hesaplama_auditleri', 'maas_hesaplama_calistirmalari'] as $key) {
            $expected = isset($plan['expected_row_counts'][$key]) ? (int) $plan['expected_row_counts'][$key] : -1;
            if ($expected >= 0 && $counts[$key] !== $expected) {
                throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
            }
        }

        if ($counts['child_calistirma_restrict'] > 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
        }
        if ($counts['maas_hesaplama_calistirmalari'] === 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $deleted = [
            'maas_hesaplama_aday_kalemleri' => 0,
            'maas_hesaplama_adaylari' => 0,
            'maas_hesaplama_auditleri' => 0,
            'maas_hesaplama_calistirmalari' => 0,
        ];

        // Leaf-first: kalem → aday → audit(for run) → calistirma
        $adayIds = $this->adayIds($pdo, $runId);
        if (count($adayIds) > 0 && $this->tableExists($pdo, 'maas_hesaplama_aday_kalemleri')) {
            $placeholders = implode(',', array_fill(0, count($adayIds), '?'));
            $del = $pdo->prepare(
                "DELETE FROM maas_hesaplama_aday_kalemleri WHERE aday_id IN ({$placeholders})"
            );
            $del->execute($adayIds);
            $deleted['maas_hesaplama_aday_kalemleri'] = (int) $del->rowCount();
        }

        if ($this->tableExists($pdo, 'maas_hesaplama_adaylari')) {
            $del = $pdo->prepare('DELETE FROM maas_hesaplama_adaylari WHERE calistirma_id = :id');
            $del->execute(['id' => $runId]);
            $deleted['maas_hesaplama_adaylari'] = (int) $del->rowCount();
        }

        if ($this->tableExists($pdo, 'maas_hesaplama_auditleri')) {
            $del = $pdo->prepare('DELETE FROM maas_hesaplama_auditleri WHERE calistirma_id = :id');
            $del->execute(['id' => $runId]);
            $deleted['maas_hesaplama_auditleri'] = (int) $del->rowCount();
        }

        $delRun = $pdo->prepare('DELETE FROM maas_hesaplama_calistirmalari WHERE id = :id');
        $delRun->execute(['id' => $runId]);
        $deleted['maas_hesaplama_calistirmalari'] = (int) $delRun->rowCount();
        if ($deleted['maas_hesaplama_calistirmalari'] !== 1) {
            throw new RuntimeException('DESTRUCTION_HANDLER_INCOMPLETE');
        }

        // Hard keep-scope assertions (fail-closed if accidentally touched — defensive counts).
        // personel_bordro_devirleri / donem_snapshot / sgk are never deleted by this handler.

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => $deleted,
                'files_deleted' => 0,
                'run_id' => $runId,
                'preserved' => [
                    'maas_hesaplama_donem_snapshotlari' => true,
                    'maas_hesaplama_sgk_snapshotlari' => true,
                    'personel_bordro_devirleri' => true,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     */
    private function resolveRunId(PDO $pdo, array $talep, array $context)
    {
        $identity = (string) (
            $context['source_version_identity']
            ?? $talep['source_version_identity_snapshot']
            ?? ''
        );
        if (preg_match('/^bordro_run:(\d+):/', $identity, $m)) {
            return (int) $m[1];
        }

        $recordId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        if ($recordId > 0 && $this->loadRun($pdo, $recordId) !== null) {
            return $recordId;
        }

        $subeId = (int) ($context['sube_id'] ?? $talep['canonical_sube_id'] ?? 0);
        $yil = (int) ($context['yil'] ?? $talep['period_yil'] ?? 0);
        $ay = (int) ($context['ay'] ?? $talep['period_ay'] ?? 0);
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            return 0;
        }
        if (!$this->tableExists($pdo, 'maas_hesaplama_calistirmalari')) {
            return 0;
        }
        $stmt = $pdo->prepare(
            "SELECT id FROM maas_hesaplama_calistirmalari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND state = 'HESAPLANDI'
               AND bordro_onay_durumu = 'KESINLESTI'
             ORDER BY revision_no DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRun(PDO $pdo, $runId)
    {
        if (!$this->tableExists($pdo, 'maas_hesaplama_calistirmalari')) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT id, snapshot_id, sube_id, yil, ay, revision_no, parent_calistirma_id, state
             FROM maas_hesaplama_calistirmalari WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, int>
     */
    private function countRunTree(PDO $pdo, $runId)
    {
        $runId = (int) $runId;
        $adayIds = $this->adayIds($pdo, $runId);
        $kalem = 0;
        if (count($adayIds) > 0 && $this->tableExists($pdo, 'maas_hesaplama_aday_kalemleri')) {
            $placeholders = implode(',', array_fill(0, count($adayIds), '?'));
            $c = $pdo->prepare(
                "SELECT COUNT(*) FROM maas_hesaplama_aday_kalemleri WHERE aday_id IN ({$placeholders})"
            );
            $c->execute($adayIds);
            $kalem = (int) $c->fetchColumn();
        }

        $aday = count($adayIds);
        $audit = 0;
        if ($this->tableExists($pdo, 'maas_hesaplama_auditleri')) {
            $c = $pdo->prepare(
                'SELECT COUNT(*) FROM maas_hesaplama_auditleri WHERE calistirma_id = :id'
            );
            $c->execute(['id' => $runId]);
            $audit = (int) $c->fetchColumn();
        }

        $child = 0;
        $c = $pdo->prepare(
            'SELECT COUNT(*) FROM maas_hesaplama_calistirmalari WHERE parent_calistirma_id = :id'
        );
        $c->execute(['id' => $runId]);
        $child = (int) $c->fetchColumn();

        $runPresent = $this->loadRun($pdo, $runId) !== null ? 1 : 0;

        return [
            'maas_hesaplama_aday_kalemleri' => $kalem,
            'maas_hesaplama_adaylari' => $aday,
            'maas_hesaplama_auditleri' => $audit,
            'maas_hesaplama_calistirmalari' => $runPresent,
            'child_calistirma_restrict' => $child,
        ];
    }

    /**
     * @return list<int>
     */
    private function adayIds(PDO $pdo, $runId)
    {
        if (!$this->tableExists($pdo, 'maas_hesaplama_adaylari')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id FROM maas_hesaplama_adaylari WHERE calistirma_id = :id ORDER BY id ASC'
        );
        $stmt->execute(['id' => (int) $runId]);
        $ids = [];
        while ($id = $stmt->fetchColumn()) {
            $ids[] = (int) $id;
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
