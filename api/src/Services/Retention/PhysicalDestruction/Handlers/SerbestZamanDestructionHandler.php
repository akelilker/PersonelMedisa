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
 * SERBEST_ZAMAN: category-owned event-store leaf destruction for one haftalık kapanış.
 *
 * Does NOT delete shared haftalik_kapanislar / haftalik_kapanis_satirlari / FM tercih rows.
 * Scope = OLUSUM events tied to this kapanis snapshots/tercihler + descendants via hedef_event_id
 * + aktif_olusumlar for those tercihler. Unlinked KULLANIM rows (no week FK) are out of scope.
 */
final class SerbestZamanDestructionHandler implements DestructionHandlerInterface
{
    public function category()
    {
        return RetentionCategories::SERBEST_ZAMAN;
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
        $kapanisId = $this->resolveKapanisId($talep, $context);
        $scope = $this->collectScope($pdo, $kapanisId);

        return [
            'db_operation_codes' => [
                'DELETE_SERBEST_ZAMAN_AKTIF_OLUSUMLAR',
                'DELETE_SERBEST_ZAMAN_EVENT_GRAPH_LEAF_FIRST',
            ],
            'expected_row_counts' => [
                'serbest_zaman_aktif_olusumlar' => count($scope['aktif_ids']),
                'serbest_zaman_events' => count($scope['event_ids']),
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

        $scope = $this->collectScope($pdo, $kapanisId);
        $expectedEvents = isset($plan['expected_row_counts']['serbest_zaman_events'])
            ? (int) $plan['expected_row_counts']['serbest_zaman_events']
            : -1;
        $expectedAktif = isset($plan['expected_row_counts']['serbest_zaman_aktif_olusumlar'])
            ? (int) $plan['expected_row_counts']['serbest_zaman_aktif_olusumlar']
            : -1;
        if ($expectedEvents >= 0 && count($scope['event_ids']) !== $expectedEvents) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }
        if ($expectedAktif >= 0 && count($scope['aktif_ids']) !== $expectedAktif) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $deletedAktif = 0;
        if (count($scope['aktif_ids']) > 0 && DependentRetentionGate::tableExists($pdo, 'serbest_zaman_aktif_olusumlar')) {
            $placeholders = implode(',', array_fill(0, count($scope['aktif_ids']), '?'));
            $delA = $pdo->prepare(
                "DELETE FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id IN ({$placeholders})"
            );
            $delA->execute($scope['aktif_ids']);
            $deletedAktif = (int) $delA->rowCount();
        }

        $deletedEvents = 0;
        if (count($scope['event_ids']) > 0 && DependentRetentionGate::tableExists($pdo, 'serbest_zaman_events')) {
            $remaining = $scope['event_ids'];
            $guard = 0;
            while (count($remaining) > 0 && $guard < 5000) {
                $guard++;
                $progress = false;
                foreach ($remaining as $idx => $eventId) {
                    $c = $pdo->prepare(
                        'SELECT COUNT(*) FROM serbest_zaman_events WHERE hedef_event_id = :id'
                    );
                    $c->execute(['id' => $eventId]);
                    if ((int) $c->fetchColumn() > 0) {
                        continue;
                    }
                    $del = $pdo->prepare('DELETE FROM serbest_zaman_events WHERE id = :id');
                    $del->execute(['id' => $eventId]);
                    $deletedEvents += (int) $del->rowCount();
                    unset($remaining[$idx]);
                    $progress = true;
                }
                if (!$progress) {
                    throw new RuntimeException(PhysicalDestructionCodes::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN);
                }
            }
        }

        // Shared kapanis header intentionally preserved (FAZLA_CALISMA co-identity).
        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => [
                    'serbest_zaman_aktif_olusumlar' => $deletedAktif,
                    'serbest_zaman_events' => $deletedEvents,
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

    /**
     * @return array{event_ids:list<int>,aktif_ids:list<int>}
     */
    private function collectScope(PDO $pdo, $kapanisId)
    {
        $kapanisId = (int) $kapanisId;
        $eventIds = [];
        $aktifIds = [];
        if ($kapanisId <= 0) {
            return ['event_ids' => [], 'aktif_ids' => []];
        }

        $snapshotIds = [];
        if (DependentRetentionGate::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            $s = $pdo->prepare('SELECT id FROM haftalik_kapanis_satirlari WHERE kapanis_id = :kid');
            $s->execute(['kid' => $kapanisId]);
            while ($id = $s->fetchColumn()) {
                $snapshotIds[] = (int) $id;
            }
        }

        $tercihIds = [];
        if (DependentRetentionGate::tableExists($pdo, 'fazla_calisma_odeme_tercihleri')) {
            $t = $pdo->prepare('SELECT id FROM fazla_calisma_odeme_tercihleri WHERE kapanis_id = :kid');
            $t->execute(['kid' => $kapanisId]);
            while ($id = $t->fetchColumn()) {
                $tercihIds[] = (int) $id;
            }
        }

        if (!DependentRetentionGate::tableExists($pdo, 'serbest_zaman_events')) {
            return ['event_ids' => [], 'aktif_ids' => []];
        }

        $seed = [];
        if (count($snapshotIds) > 0) {
            $ph = implode(',', array_fill(0, count($snapshotIds), '?'));
            $q = $pdo->prepare(
                "SELECT id FROM serbest_zaman_events WHERE kaynak_snapshot_id IN ({$ph})"
            );
            $q->execute($snapshotIds);
            while ($id = $q->fetchColumn()) {
                $seed[(int) $id] = true;
            }
        }
        if (count($tercihIds) > 0) {
            $ph = implode(',', array_fill(0, count($tercihIds), '?'));
            $q = $pdo->prepare(
                "SELECT id FROM serbest_zaman_events WHERE kaynak_odeme_tercihi_id IN ({$ph})"
            );
            $q->execute($tercihIds);
            while ($id = $q->fetchColumn()) {
                $seed[(int) $id] = true;
            }
        }

        // Expand descendants that reference seed events (DUZELTME/IPTAL chain).
        $frontier = array_keys($seed);
        $all = $seed;
        $guard = 0;
        while (count($frontier) > 0 && $guard < 5000) {
            $guard++;
            $id = array_shift($frontier);
            $child = $pdo->prepare(
                'SELECT id FROM serbest_zaman_events WHERE hedef_event_id = :id'
            );
            $child->execute(['id' => $id]);
            while ($cid = $child->fetchColumn()) {
                $cid = (int) $cid;
                if (!isset($all[$cid])) {
                    $all[$cid] = true;
                    $frontier[] = $cid;
                }
            }
        }
        $eventIds = array_map('intval', array_keys($all));
        sort($eventIds);

        if (count($tercihIds) > 0 && DependentRetentionGate::tableExists($pdo, 'serbest_zaman_aktif_olusumlar')) {
            $ph = implode(',', array_fill(0, count($tercihIds), '?'));
            $a = $pdo->prepare(
                "SELECT odeme_tercihi_id FROM serbest_zaman_aktif_olusumlar
                 WHERE odeme_tercihi_id IN ({$ph})"
            );
            $a->execute($tercihIds);
            while ($id = $a->fetchColumn()) {
                $aktifIds[] = (int) $id;
            }
            sort($aktifIds);
        }

        return ['event_ids' => $eventIds, 'aktif_ids' => $aktifIds];
    }
}
