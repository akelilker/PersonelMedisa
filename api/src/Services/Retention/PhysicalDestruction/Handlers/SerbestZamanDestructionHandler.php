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
 * + aktif_olusumlar for those tercihler.
 *
 * Canonical balance is a personel global pool (Σ OLUSUM − Σ KULLANIM). Schema 029 forces
 * KULLANIM.kaynak_snapshot_id / kaynak_odeme_tercihi_id / hedef_event_id = NULL — no lot
 * provenance. FIFO/LIFO allocation is NOT a canonical contract. Therefore: if any affected
 * personel has unallocated KULLANIM (or KULLANIM correction/iptal chain evidence), fail-closed
 * with SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED and mutate nothing.
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
                'GATE_SERBEST_ZAMAN_USAGE_ALLOCATION',
                'DELETE_SERBEST_ZAMAN_AKTIF_OLUSUMLAR',
                'DELETE_SERBEST_ZAMAN_EVENT_GRAPH_LEAF_FIRST',
            ],
            'expected_row_counts' => [
                'serbest_zaman_aktif_olusumlar' => count($scope['aktif_ids']),
                'serbest_zaman_events' => count($scope['event_ids']),
            ],
            'external_file_count' => 0,
            // Gate throws SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED on execute (not policy_blocker /
            // DEPENDENT_RETENTION_RECORDS_REMAIN) so the reason stays reportable.
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

        // Fail-closed before any mutation when unallocated usage evidence exists.
        if (count($scope['event_ids']) > 0
            && $this->hasUnresolvedUsageAllocation($pdo, $scope['personel_ids'])
        ) {
            throw new RuntimeException(
                PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED
            );
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
     * Unallocated KULLANIM (schema-forced NULL provenance) or any DUZELTME/IPTAL that
     * targets a KULLANIM for the affected personel → allocation unresolved.
     *
     * @param list<int> $personelIds
     */
    private function hasUnresolvedUsageAllocation(PDO $pdo, array $personelIds)
    {
        if (count($personelIds) === 0
            || !DependentRetentionGate::tableExists($pdo, 'serbest_zaman_events')
        ) {
            return false;
        }

        $ph = implode(',', array_fill(0, count($personelIds), '?'));
        // Active/historical KULLANIM rows (schema: no lot FK).
        $k = $pdo->prepare(
            "SELECT COUNT(*) FROM serbest_zaman_events
             WHERE personel_id IN ({$ph})
               AND event_tipi = 'SERBEST_ZAMAN_KULLANIM'"
        );
        $k->execute($personelIds);
        if ((int) $k->fetchColumn() > 0) {
            return true;
        }

        // Correction/iptal chain evidence targeting KULLANIM (even if parent already odd).
        $c = $pdo->prepare(
            "SELECT COUNT(*) FROM serbest_zaman_events
             WHERE personel_id IN ({$ph})
               AND event_tipi IN ('SERBEST_ZAMAN_DUZELTME', 'SERBEST_ZAMAN_IPTAL')
               AND hedef_event_tipi = 'SERBEST_ZAMAN_KULLANIM'"
        );
        $c->execute($personelIds);

        return (int) $c->fetchColumn() > 0;
    }

    /**
     * @return array{event_ids:list<int>,aktif_ids:list<int>,personel_ids:list<int>}
     */
    private function collectScope(PDO $pdo, $kapanisId)
    {
        $kapanisId = (int) $kapanisId;
        $eventIds = [];
        $aktifIds = [];
        $personelMap = [];
        if ($kapanisId <= 0) {
            return ['event_ids' => [], 'aktif_ids' => [], 'personel_ids' => []];
        }

        $snapshotIds = [];
        if (DependentRetentionGate::tableExists($pdo, 'haftalik_kapanis_satirlari')) {
            $s = $pdo->prepare(
                'SELECT id, personel_id FROM haftalik_kapanis_satirlari WHERE kapanis_id = :kid'
            );
            $s->execute(['kid' => $kapanisId]);
            while ($row = $s->fetch(PDO::FETCH_ASSOC)) {
                $snapshotIds[] = (int) $row['id'];
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $personelMap[$pid] = true;
                }
            }
        }

        $tercihIds = [];
        if (DependentRetentionGate::tableExists($pdo, 'fazla_calisma_odeme_tercihleri')) {
            $t = $pdo->prepare(
                'SELECT id, personel_id FROM fazla_calisma_odeme_tercihleri WHERE kapanis_id = :kid'
            );
            $t->execute(['kid' => $kapanisId]);
            while ($row = $t->fetch(PDO::FETCH_ASSOC)) {
                $tercihIds[] = (int) $row['id'];
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $personelMap[$pid] = true;
                }
            }
        }

        if (!DependentRetentionGate::tableExists($pdo, 'serbest_zaman_events')) {
            return [
                'event_ids' => [],
                'aktif_ids' => [],
                'personel_ids' => array_map('intval', array_keys($personelMap)),
            ];
        }

        $seed = [];
        if (count($snapshotIds) > 0) {
            $ph = implode(',', array_fill(0, count($snapshotIds), '?'));
            $q = $pdo->prepare(
                "SELECT id, personel_id FROM serbest_zaman_events WHERE kaynak_snapshot_id IN ({$ph})"
            );
            $q->execute($snapshotIds);
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $seed[(int) $row['id']] = true;
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $personelMap[$pid] = true;
                }
            }
        }
        if (count($tercihIds) > 0) {
            $ph = implode(',', array_fill(0, count($tercihIds), '?'));
            $q = $pdo->prepare(
                "SELECT id, personel_id FROM serbest_zaman_events WHERE kaynak_odeme_tercihi_id IN ({$ph})"
            );
            $q->execute($tercihIds);
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $seed[(int) $row['id']] = true;
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $personelMap[$pid] = true;
                }
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
                'SELECT id, personel_id FROM serbest_zaman_events WHERE hedef_event_id = :id'
            );
            $child->execute(['id' => $id]);
            while ($row = $child->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) $row['id'];
                if (!isset($all[$cid])) {
                    $all[$cid] = true;
                    $frontier[] = $cid;
                }
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $personelMap[$pid] = true;
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

        $personelIds = array_map('intval', array_keys($personelMap));
        sort($personelIds);

        return [
            'event_ids' => $eventIds,
            'aktif_ids' => $aktifIds,
            'personel_ids' => $personelIds,
        ];
    }
}
