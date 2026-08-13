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
 * FAZLA_CALISMA: category-owned leaf COMPOSITE for one haftalık kapanış.
 *
 * Shared haftalik_kapanislar / satırlar headers preserved (co-identity with SERBEST_ZAMAN).
 * Gate: no remaining SERBEST_ZAMAN events/aktif_olusum for this kapanis.
 * Then: delete tercih audit → tercih; zero FM minute fields + clear notlar_json on satırlar.
 * imzali_talep_belge surec is PERSONEL_BELGE-owned — left intact (FK from tercih removed with delete).
 */
final class FazlaCalismaDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::FAZLA_CALISMA;
    }

    public function executionMode()
    {
        return PhysicalDestructionCodes::MODE_COMPOSITE;
    }

    public function isExecutable()
    {
        return true;
    }

    public function plan(PDO $pdo, array $talep, array $context)
    {
        $kapanisId = $this->resolveKapanisId($talep, $context);
        $counts = $this->countScope($pdo, $kapanisId);

        return [
            'db_operation_codes' => [
                'GATE_SERBEST_ZAMAN_CLEARED',
                'DELETE_FAZLA_CALISMA_ODEME_TERCIHI_AUDIT',
                'DELETE_FAZLA_CALISMA_ODEME_TERCIHLERI',
                'ANONYMIZE_HAFTALIK_SATIR_FM_FIELDS',
            ],
            'expected_row_counts' => [
                'fazla_calisma_odeme_tercihi_audit' => $counts['audit'],
                'fazla_calisma_odeme_tercihleri' => $counts['tercih'],
                'haftalik_kapanis_satirlari' => $counts['satir'],
            ],
            'external_file_count' => 0,
            'policy_blocker' => null,
            'kapanis_id' => $kapanisId,
        ];
    }

    public function execute(PDO $pdo, array $talep, array $context, array $plan)
    {
        $kapanisId = $this->resolveKapanisId($talep, $context);
        if ($kapanisId <= 0 || !DependentRetentionGate::tableExists($pdo, 'haftalik_kapanislar')) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $stmt = $pdo->prepare(
            "SELECT id, state FROM haftalik_kapanislar WHERE id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $kapanisId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (string) ($row['state'] ?? '') !== 'KAPANDI') {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_TARGET_ALREADY_MISSING);
        }

        $this->assertSerbestZamanCleared($pdo, $kapanisId);

        $counts = $this->countScope($pdo, $kapanisId);
        foreach (['fazla_calisma_odeme_tercihi_audit' => 'audit', 'fazla_calisma_odeme_tercihleri' => 'tercih', 'haftalik_kapanis_satirlari' => 'satir'] as $planKey => $countKey) {
            $expected = isset($plan['expected_row_counts'][$planKey])
                ? (int) $plan['expected_row_counts'][$planKey]
                : -1;
            if ($expected >= 0 && (int) $counts[$countKey] !== $expected) {
                throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
            }
        }

        $tercihIds = $this->tercihIds($pdo, $kapanisId);
        $deletedAudit = 0;
        $deletedTercih = 0;

        if (count($tercihIds) > 0 && DependentRetentionGate::tableExists($pdo, 'fazla_calisma_odeme_tercihi_audit')) {
            $ph = implode(',', array_fill(0, count($tercihIds), '?'));
            $delA = $pdo->prepare(
                "DELETE FROM fazla_calisma_odeme_tercihi_audit WHERE tercih_id IN ({$ph})"
            );
            $delA->execute($tercihIds);
            $deletedAudit = (int) $delA->rowCount();
        }

        if (count($tercihIds) > 0 && DependentRetentionGate::tableExists($pdo, 'fazla_calisma_odeme_tercihleri')) {
            $ph = implode(',', array_fill(0, count($tercihIds), '?'));
            $delT = $pdo->prepare(
                "DELETE FROM fazla_calisma_odeme_tercihleri WHERE id IN ({$ph}) AND kapanis_id = ?"
            );
            $delT->execute(array_merge($tercihIds, [$kapanisId]));
            $deletedTercih = (int) $delT->rowCount();
        }

        $anonymizedSatir = 0;
        if (DependentRetentionGate::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            $upd = $pdo->prepare(
                "UPDATE haftalik_kapanis_satirlari SET
                    fazla_calisma_dakika = 0,
                    fazla_surelerle_calisma_dakika = 0,
                    notlar_json = NULL
                 WHERE kapanis_id = :kid
                   AND (
                     fazla_calisma_dakika <> 0
                     OR fazla_surelerle_calisma_dakika <> 0
                     OR notlar_json IS NOT NULL
                   )"
            );
            $upd->execute(['kid' => $kapanisId]);
            $anonymizedSatir = (int) $upd->rowCount();

            // Ensure all satırlar touched for plan expectation even if already zeroed.
            $all = $pdo->prepare(
                'SELECT COUNT(*) FROM haftalik_kapanis_satirlari WHERE kapanis_id = :kid'
            );
            $all->execute(['kid' => $kapanisId]);
            $satirTotal = (int) $all->fetchColumn();
            if ($satirTotal > 0 && $anonymizedSatir === 0) {
                // Idempotent re-apply zeros (rowCount may be 0 when already destroyed leaves).
                $pdo->prepare(
                    "UPDATE haftalik_kapanis_satirlari SET
                        fazla_calisma_dakika = 0,
                        fazla_surelerle_calisma_dakika = 0,
                        notlar_json = NULL
                     WHERE kapanis_id = :kid"
                )->execute(['kid' => $kapanisId]);
                $anonymizedSatir = $satirTotal;
            } elseif ($satirTotal > 0) {
                $anonymizedSatir = $satirTotal;
            }
        }

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => [
                    'fazla_calisma_odeme_tercihi_audit' => $deletedAudit,
                    'fazla_calisma_odeme_tercihleri' => $deletedTercih,
                ],
                'rows_anonymized' => [
                    'haftalik_kapanis_satirlari' => $anonymizedSatir,
                ],
                'files_deleted' => 0,
                'shared_haftalik_kapanis_preserved' => 1,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $talep
     * @param array<string, mixed> $context
     */
    private function resolveKapanisId(array $talep, array $context)
    {
        if (isset($context['haftalik_kapanis_id']) && (int) $context['haftalik_kapanis_id'] > 0) {
            return (int) $context['haftalik_kapanis_id'];
        }

        return (int) ($talep['record_id'] ?? $context['record_id'] ?? 0);
    }

    private function assertSerbestZamanCleared(PDO $pdo, $kapanisId)
    {
        $kapanisId = (int) $kapanisId;
        $snapshotIds = [];
        if (DependentRetentionGate::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            $s = $pdo->prepare('SELECT id FROM haftalik_kapanis_satirlari WHERE kapanis_id = :kid');
            $s->execute(['kid' => $kapanisId]);
            while ($id = $s->fetchColumn()) {
                $snapshotIds[] = (int) $id;
            }
        }
        $tercihIds = $this->tercihIds($pdo, $kapanisId);

        if (DependentRetentionGate::tableExists($pdo, 'serbest_zaman_events')) {
            if (count($snapshotIds) > 0) {
                $ph = implode(',', array_fill(0, count($snapshotIds), '?'));
                DependentRetentionGate::assertNoRows(
                    $pdo,
                    "SELECT COUNT(*) FROM serbest_zaman_events WHERE kaynak_snapshot_id IN ({$ph})",
                    $snapshotIds
                );
            }
            if (count($tercihIds) > 0) {
                $ph = implode(',', array_fill(0, count($tercihIds), '?'));
                DependentRetentionGate::assertNoRows(
                    $pdo,
                    "SELECT COUNT(*) FROM serbest_zaman_events WHERE kaynak_odeme_tercihi_id IN ({$ph})",
                    $tercihIds
                );
            }
        }
        if (count($tercihIds) > 0 && DependentRetentionGate::tableExists($pdo, 'serbest_zaman_aktif_olusumlar')) {
            $ph = implode(',', array_fill(0, count($tercihIds), '?'));
            DependentRetentionGate::assertNoRows(
                $pdo,
                "SELECT COUNT(*) FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id IN ({$ph})",
                $tercihIds
            );
        }
    }

    /**
     * @return list<int>
     */
    private function tercihIds(PDO $pdo, $kapanisId)
    {
        $ids = [];
        if (!DependentRetentionGate::tableExists($pdo, 'fazla_calisma_odeme_tercihleri')) {
            return $ids;
        }
        $t = $pdo->prepare('SELECT id FROM fazla_calisma_odeme_tercihleri WHERE kapanis_id = :kid');
        $t->execute(['kid' => (int) $kapanisId]);
        while ($id = $t->fetchColumn()) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * @return array{audit:int,tercih:int,satir:int}
     */
    private function countScope(PDO $pdo, $kapanisId)
    {
        $kapanisId = (int) $kapanisId;
        $tercih = 0;
        $audit = 0;
        $satir = 0;
        $tercihIds = $this->tercihIds($pdo, $kapanisId);
        $tercih = count($tercihIds);
        if ($tercih > 0 && DependentRetentionGate::tableExists($pdo, 'fazla_calisma_odeme_tercihi_audit')) {
            $ph = implode(',', array_fill(0, $tercih, '?'));
            $a = $pdo->prepare(
                "SELECT COUNT(*) FROM fazla_calisma_odeme_tercihi_audit WHERE tercih_id IN ({$ph})"
            );
            $a->execute($tercihIds);
            $audit = (int) $a->fetchColumn();
        }
        if (DependentRetentionGate::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            $s = $pdo->prepare(
                'SELECT COUNT(*) FROM haftalik_kapanis_satirlari WHERE kapanis_id = :kid'
            );
            $s->execute(['kid' => $kapanisId]);
            $satir = (int) $s->fetchColumn();
        }

        return ['audit' => $audit, 'tercih' => $tercih, 'satir' => $satir];
    }
}
