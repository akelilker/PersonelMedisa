<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * SGK_EKSIK_GUN (Pack 3B):
 * - DECISION_06 OPTION A: nested period SGK evidence only
 * - DECISION_07 OPTION A: keep maas_hesaplama_donem_snapshotlari header unchanged
 *
 * Never deletes catalogs, ops belge/finans, BORDRO run tree, or PERSONEL_BELGE.
 */
final class SgkEksikGunDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::SGK_EKSIK_GUN;
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
        $snapshotId = $this->resolveSnapshotId($pdo, $talep, $context);
        $counts = $snapshotId > 0 ? $this->countNested($pdo, $snapshotId) : [
            'maas_hesaplama_sgk_snapshotlari' => 0,
            'sgk_hesap_auditleri' => 0,
            'maas_hesaplama_donem_snapshotlari' => 0,
        ];

        return [
            'db_operation_codes' => [
                'SGK_NESTED_EVIDENCE_DELETE',
                'SGK_DONEM_SNAPSHOT_HEADER_PRESERVE',
                'SGK_CATALOG_PRESERVE',
            ],
            'expected_row_counts' => [
                'maas_hesaplama_sgk_snapshotlari' => $counts['maas_hesaplama_sgk_snapshotlari'],
                'sgk_hesap_auditleri' => $counts['sgk_hesap_auditleri'],
                'maas_hesaplama_donem_snapshotlari_header' => $counts['maas_hesaplama_donem_snapshotlari'],
            ],
            'external_file_count' => 0,
            'policy_blocker' => null,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $snapshotId = $this->resolveSnapshotId($pdo, $talep, $context);
        if ($snapshotId <= 0 || !$this->tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $header = $this->loadSnapshot($pdo, $snapshotId);
        if ($header === null) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $subeId = (int) ($context['sube_id'] ?? $talep['canonical_sube_id'] ?? 0);
        $yil = (int) ($context['yil'] ?? $talep['period_yil'] ?? 0);
        $ay = (int) ($context['ay'] ?? $talep['period_ay'] ?? 0);
        if ($subeId > 0 && (int) $header['sube_id'] !== $subeId) {
            throw new RuntimeException('RETENTION_TARGET_SCOPE_MISMATCH');
        }
        if ($yil > 0 && (int) $header['yil'] !== $yil) {
            throw new RuntimeException('RETENTION_TARGET_SCOPE_MISMATCH');
        }
        if ($ay > 0 && (int) $header['ay'] !== $ay) {
            throw new RuntimeException('RETENTION_TARGET_SCOPE_MISMATCH');
        }

        $counts = $this->countNested($pdo, $snapshotId);
        $expectedSgk = isset($plan['expected_row_counts']['maas_hesaplama_sgk_snapshotlari'])
            ? (int) $plan['expected_row_counts']['maas_hesaplama_sgk_snapshotlari']
            : -1;
        $expectedAudit = isset($plan['expected_row_counts']['sgk_hesap_auditleri'])
            ? (int) $plan['expected_row_counts']['sgk_hesap_auditleri']
            : -1;
        if ($expectedSgk >= 0 && $counts['maas_hesaplama_sgk_snapshotlari'] !== $expectedSgk) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }
        if ($expectedAudit >= 0 && $counts['sgk_hesap_auditleri'] !== $expectedAudit) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        // Nested evidence must exist on first execute — empty nested with present header is fail-closed.
        if ($counts['maas_hesaplama_sgk_snapshotlari'] === 0 && $counts['sgk_hesap_auditleri'] === 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $headerBefore = $header;
        $deleted = [
            'sgk_hesap_auditleri' => 0,
            'maas_hesaplama_sgk_snapshotlari' => 0,
        ];

        // Direct children only (repo-proven): audits + sgk snapshots for this donem_snapshot_id.
        if ($this->tableExists($pdo, 'sgk_hesap_auditleri')) {
            $del = $pdo->prepare(
                'DELETE FROM sgk_hesap_auditleri WHERE donem_snapshot_id = :id'
            );
            $del->execute(['id' => $snapshotId]);
            $deleted['sgk_hesap_auditleri'] = (int) $del->rowCount();
        }

        if ($this->tableExists($pdo, 'maas_hesaplama_sgk_snapshotlari')) {
            $del = $pdo->prepare(
                'DELETE FROM maas_hesaplama_sgk_snapshotlari WHERE donem_snapshot_id = :id'
            );
            $del->execute(['id' => $snapshotId]);
            $deleted['maas_hesaplama_sgk_snapshotlari'] = (int) $del->rowCount();
        }

        $headerAfter = $this->loadSnapshot($pdo, $snapshotId);
        if ($headerAfter === null) {
            throw new RuntimeException('SGK_DONEM_SNAPSHOT_HEADER_LOST');
        }
        if ((string) ($headerAfter['state'] ?? '') !== (string) ($headerBefore['state'] ?? '')
            || (int) ($headerAfter['revision_no'] ?? 0) !== (int) ($headerBefore['revision_no'] ?? 0)
            || (string) ($headerAfter['snapshot_hash'] ?? '') !== (string) ($headerBefore['snapshot_hash'] ?? '')
        ) {
            throw new RuntimeException('SGK_DONEM_SNAPSHOT_HEADER_MUTATED');
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => $deleted,
                'files_deleted' => 0,
                'donem_snapshot_id' => $snapshotId,
                'preserved' => [
                    'maas_hesaplama_donem_snapshotlari' => true,
                    'sgk_catalogs' => true,
                    'sgk_eksik_gun_belgeleri' => true,
                    'bordro_run_tree' => true,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     */
    private function resolveSnapshotId(PDO $pdo, array $talep, array $context)
    {
        $identity = (string) (
            $context['source_version_identity']
            ?? $talep['source_version_identity_snapshot']
            ?? ''
        );
        if (preg_match('/^sgk_snapshot:(\d+):/', $identity, $m)) {
            return (int) $m[1];
        }

        $recordId = (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
        if ($recordId > 0 && $this->loadSnapshot($pdo, $recordId) !== null) {
            return $recordId;
        }

        $subeId = (int) ($context['sube_id'] ?? $talep['canonical_sube_id'] ?? 0);
        $yil = (int) ($context['yil'] ?? $talep['period_yil'] ?? 0);
        $ay = (int) ($context['ay'] ?? $talep['period_ay'] ?? 0);
        if ($subeId <= 0 || $yil < 2000 || $ay < 1 || $ay > 12) {
            return 0;
        }
        if (!$this->tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            return 0;
        }
        $stmt = $pdo->prepare(
            "SELECT id FROM maas_hesaplama_donem_snapshotlari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND state = 'OLUSTURULDU'
             ORDER BY revision_no DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadSnapshot(PDO $pdo, $snapshotId)
    {
        if (!$this->tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT id, sube_id, yil, ay, revision_no, state, snapshot_hash, cutoff_at
             FROM maas_hesaplama_donem_snapshotlari WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $snapshotId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, int>
     */
    private function countNested(PDO $pdo, $snapshotId)
    {
        $snapshotId = (int) $snapshotId;
        $sgk = 0;
        $audit = 0;
        if ($this->tableExists($pdo, 'maas_hesaplama_sgk_snapshotlari')) {
            $c = $pdo->prepare(
                'SELECT COUNT(*) FROM maas_hesaplama_sgk_snapshotlari WHERE donem_snapshot_id = :id'
            );
            $c->execute(['id' => $snapshotId]);
            $sgk = (int) $c->fetchColumn();
        }
        if ($this->tableExists($pdo, 'sgk_hesap_auditleri')) {
            $c = $pdo->prepare(
                'SELECT COUNT(*) FROM sgk_hesap_auditleri WHERE donem_snapshot_id = :id'
            );
            $c->execute(['id' => $snapshotId]);
            $audit = (int) $c->fetchColumn();
        }

        return [
            'maas_hesaplama_sgk_snapshotlari' => $sgk,
            'sgk_hesap_auditleri' => $audit,
            'maas_hesaplama_donem_snapshotlari' => $this->loadSnapshot($pdo, $snapshotId) !== null ? 1 : 0,
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
