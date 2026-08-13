<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction\Handlers;

use Medisa\Api\Services\Retention\PhysicalDestruction\DependentRetentionGate;
use Medisa\Api\Services\Retention\PhysicalDestruction\DestructionHandlerInterface;
use Medisa\Api\Services\Retention\PhysicalDestruction\PhysicalDestructionCodes;
use Medisa\Api\Services\Retention\PhysicalDestruction\RetentionPhysicalDestroyGate;
use Medisa\Api\Services\Retention\RetentionCategories;
use Medisa\Api\Services\SerbestZaman\SerbestZamanAllocationService;
use PDO;
use RuntimeException;

/**
 * SERBEST_ZAMAN: category-owned event-store leaf destruction for one haftalık kapanış.
 *
 * Pack 4B: allocation-aware. Does NOT delete shared haftalik_kapanislar /
 * haftalik_kapanis_satirlari / FM tercih rows (FAZLA_CALISMA co-identity).
 *
 * LEGACY_UNALLOCATED / INVARIANT_BROKEN → fail-closed (no mutation, no auto-backfill).
 * ALLOCATED usages absorbed only when all allocation provenance stays inside current scope.
 * Cross-scope allocation → SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS.
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
        $resolved = $this->resolveDestroyScope($pdo, $kapanisId, false);

        return [
            'db_operation_codes' => [
                'GATE_SERBEST_ZAMAN_USAGE_ALLOCATION',
                'DELETE_SERBEST_ZAMAN_KULLANIM_TAHSISLERI',
                'DELETE_SERBEST_ZAMAN_AKTIF_OLUSUMLAR',
                'DELETE_SERBEST_ZAMAN_EVENT_GRAPH_LEAF_FIRST',
            ],
            'expected_row_counts' => [
                'serbest_zaman_kullanim_tahsisleri' => count($resolved['allocation_ids']),
                'serbest_zaman_aktif_olusumlar' => count($resolved['aktif_ids']),
                'serbest_zaman_events' => count($resolved['event_ids']),
            ],
            'external_file_count' => 0,
            'policy_blocker' => null,
            'kapanis_id' => $kapanisId,
            'scope_fingerprint' => $resolved['scope_fingerprint'],
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

        // Serialize with SerbestZamanController write path (personel row lock).
        $base = $this->collectBaseScope($pdo, $kapanisId);
        $this->lockPersonelRows($pdo, $base['personel_ids']);

        $resolved = $this->resolveDestroyScope($pdo, $kapanisId, true);

        $expectedEvents = isset($plan['expected_row_counts']['serbest_zaman_events'])
            ? (int) $plan['expected_row_counts']['serbest_zaman_events']
            : -1;
        $expectedAktif = isset($plan['expected_row_counts']['serbest_zaman_aktif_olusumlar'])
            ? (int) $plan['expected_row_counts']['serbest_zaman_aktif_olusumlar']
            : -1;
        $expectedAlloc = isset($plan['expected_row_counts']['serbest_zaman_kullanim_tahsisleri'])
            ? (int) $plan['expected_row_counts']['serbest_zaman_kullanim_tahsisleri']
            : -1;
        $expectedFp = isset($plan['scope_fingerprint']) ? (string) $plan['scope_fingerprint'] : '';

        if ($expectedEvents >= 0 && count($resolved['event_ids']) !== $expectedEvents) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }
        if ($expectedAktif >= 0 && count($resolved['aktif_ids']) !== $expectedAktif) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }
        if ($expectedAlloc >= 0 && count($resolved['allocation_ids']) !== $expectedAlloc) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }
        if ($expectedFp !== '' && !hash_equals($expectedFp, (string) $resolved['scope_fingerprint'])) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_PLAN_CHANGED);
        }

        $deletedAlloc = 0;
        if (count($resolved['allocation_ids']) > 0
            && SerbestZamanAllocationService::tableExists($pdo)
        ) {
            $placeholders = implode(',', array_fill(0, count($resolved['allocation_ids']), '?'));
            $delAlloc = $pdo->prepare(
                "DELETE FROM serbest_zaman_kullanim_tahsisleri WHERE id IN ({$placeholders})"
            );
            $delAlloc->execute($resolved['allocation_ids']);
            $deletedAlloc = (int) $delAlloc->rowCount();
        }

        $deletedAktif = 0;
        if (count($resolved['aktif_ids']) > 0
            && DependentRetentionGate::tableExists($pdo, 'serbest_zaman_aktif_olusumlar')
        ) {
            $placeholders = implode(',', array_fill(0, count($resolved['aktif_ids']), '?'));
            $delA = $pdo->prepare(
                "DELETE FROM serbest_zaman_aktif_olusumlar WHERE odeme_tercihi_id IN ({$placeholders})"
            );
            $delA->execute($resolved['aktif_ids']);
            $deletedAktif = (int) $delA->rowCount();
        }

        $deletedEvents = 0;
        if (count($resolved['event_ids']) > 0
            && DependentRetentionGate::tableExists($pdo, 'serbest_zaman_events')
        ) {
            $remaining = $resolved['event_ids'];
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

        return [
            'result_code' => PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTED,
            'summary' => [
                'rows_deleted' => [
                    'serbest_zaman_kullanim_tahsisleri' => $deletedAlloc,
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
     * @param list<int> $personelIds
     */
    private function lockPersonelRows(PDO $pdo, array $personelIds)
    {
        if (count($personelIds) === 0 || !DependentRetentionGate::tableExists($pdo, 'personeller')) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $personelIds)));
        sort($ids);
        foreach ($ids as $pid) {
            if ($pid <= 0) {
                continue;
            }
            $stmt = $pdo->prepare('SELECT id FROM personeller WHERE id = :id LIMIT 1 FOR UPDATE');
            $stmt->execute(['id' => $pid]);
            $stmt->fetchColumn();
        }
    }

    /**
     * Resolve destroyable event/allocation/aktif scope with allocation provenance checks.
     *
     * @return array{
     *   event_ids:list<int>,
     *   aktif_ids:list<int>,
     *   allocation_ids:list<int>,
     *   personel_ids:list<int>,
     *   olusum_ids:list<int>,
     *   scope_fingerprint:string
     * }
     */
    private function resolveDestroyScope(PDO $pdo, $kapanisId, $forExecute)
    {
        // Pack 4B: readiness before empty-scope short-circuit — zero scope is not "legacy OK".
        RetentionPhysicalDestroyGate::assertSerbestZamanPack4bReady($pdo);

        $base = $this->collectBaseScope($pdo, $kapanisId);
        $eventIds = $base['event_ids'];
        $aktifIds = $base['aktif_ids'];
        $personelIds = $base['personel_ids'];
        $olusumIds = $base['olusum_ids'];
        $allocationIds = [];

        if (count($eventIds) === 0 && count($aktifIds) === 0) {
            return [
                'event_ids' => [],
                'aktif_ids' => [],
                'allocation_ids' => [],
                'personel_ids' => $personelIds,
                'olusum_ids' => [],
                'scope_fingerprint' => $this->fingerprintScope([], [], []),
            ];
        }

        $targetOlusumSet = [];
        foreach ($olusumIds as $oid) {
            $targetOlusumSet[(int) $oid] = true;
        }

        $absorbUsageIds = [];
        foreach ($personelIds as $personelId) {
            $events = $this->loadPersonelEvents($pdo, (int) $personelId);
            $personelState = SerbestZamanAllocationService::personelAllocationState(
                $pdo,
                $events,
                (int) $personelId
            );

            if ($personelState['state'] === SerbestZamanAllocationService::STATE_INVARIANT_BROKEN) {
                throw new RuntimeException(
                    PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN
                );
            }
            if ($personelState['state'] === SerbestZamanAllocationService::STATE_LEGACY_UNALLOCATED) {
                // Any effective legacy usage on affected personel can touch target lots — fail-closed.
                throw new RuntimeException(
                    PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED
                );
            }

            foreach ($events as $event) {
                if ((string) ($event['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_KULLANIM') {
                    continue;
                }
                if ((int) ($event['personel_id'] ?? 0) !== (int) $personelId) {
                    continue;
                }
                $kid = (int) ($event['id'] ?? 0);
                $usage = SerbestZamanAllocationService::usageAllocationState(
                    $pdo,
                    $events,
                    (int) $personelId,
                    $kid
                );
                if ($usage['state'] === SerbestZamanAllocationService::STATE_ZERO) {
                    // Fully cancelled/zeroed legacy: do not blanket-block.
                    continue;
                }
                if ($usage['state'] === SerbestZamanAllocationService::STATE_LEGACY_UNALLOCATED) {
                    throw new RuntimeException(
                        PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED
                    );
                }
                if ($usage['state'] === SerbestZamanAllocationService::STATE_INVARIANT_BROKEN) {
                    throw new RuntimeException(
                        PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN
                    );
                }
                if ($usage['state'] !== SerbestZamanAllocationService::STATE_ALLOCATED) {
                    continue;
                }

                $netByLot = SerbestZamanAllocationService::netAllocationByLotForUsage($pdo, $kid);
                $touchesTarget = false;
                foreach ($netByLot as $oid => $net) {
                    if ((int) $net > 0 && isset($targetOlusumSet[(int) $oid])) {
                        $touchesTarget = true;
                        break;
                    }
                }
                if (!$touchesTarget) {
                    continue;
                }

                // Conservative: any allocation history row to a non-target OLUSUM blocks
                // (including zero-net historical cross-scope provenance).
                $historyOlusumIds = SerbestZamanAllocationService::allocationOlusumIdsForUsage($pdo, $kid);
                foreach ($historyOlusumIds as $oid) {
                    if (!isset($targetOlusumSet[(int) $oid])) {
                        throw new RuntimeException(
                            PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS
                        );
                    }
                }
                // Also require current positive nets only on target (belt + suspenders).
                foreach ($netByLot as $oid => $net) {
                    if ((int) $net !== 0 && !isset($targetOlusumSet[(int) $oid])) {
                        throw new RuntimeException(
                            PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS
                        );
                    }
                }

                $absorbUsageIds[$kid] = true;
            }
        }

        // Expand destroy event graph with absorbed KULLANIM + DUZELTME/IPTAL chains.
        if (count($absorbUsageIds) > 0) {
            $eventSet = [];
            foreach ($eventIds as $eid) {
                $eventSet[(int) $eid] = true;
            }
            foreach (array_keys($absorbUsageIds) as $kid) {
                $eventSet[(int) $kid] = true;
                $this->expandDescendants($pdo, (int) $kid, $eventSet);
            }
            $eventIds = array_map('intval', array_keys($eventSet));
            sort($eventIds);
        }

        // Allocation rows: all rows for target OLUSUM lots + all rows for absorbed usages.
        $allocSet = [];
        $targetAllocRows = SerbestZamanAllocationService::allocationRowsForOlusumLots($pdo, $olusumIds);
        foreach ($targetAllocRows as $arow) {
            $allocSet[(int) $arow['id']] = true;
            $kid = (int) ($arow['kullanim_event_id'] ?? 0);
            // If a row points at target lot from a usage we did not absorb, that usage must
            // already have been rejected (cross-scope / legacy). Double-check absorb set.
            if ($kid > 0 && !isset($absorbUsageIds[$kid])) {
                // Zero-net-only rows on target with usage not absorbed: still delete target-lot
                // rows only if the usage has NO history outside target (already enforced above
                // when touchesTarget). If usage never touched positive net on target, orphan
                // zero-net target history rows are safe to remove with the lot.
                $historyOlusumIds = SerbestZamanAllocationService::allocationOlusumIdsForUsage($pdo, $kid);
                foreach ($historyOlusumIds as $oid) {
                    if (!isset($targetOlusumSet[(int) $oid])) {
                        throw new RuntimeException(
                            PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS
                        );
                    }
                }
                // Entire usage history is inside target lots → absorb usage graph too.
                $absorbUsageIds[$kid] = true;
                $eventSet = [];
                foreach ($eventIds as $eid) {
                    $eventSet[(int) $eid] = true;
                }
                $eventSet[$kid] = true;
                $this->expandDescendants($pdo, $kid, $eventSet);
                $eventIds = array_map('intval', array_keys($eventSet));
                sort($eventIds);
            }
        }
        foreach (array_keys($absorbUsageIds) as $kid) {
            foreach (SerbestZamanAllocationService::allocationRowIdsForUsage($pdo, $kid) as $aid) {
                $allocSet[(int) $aid] = true;
            }
        }
        $allocationIds = array_map('intval', array_keys($allocSet));
        sort($allocationIds);

        return [
            'event_ids' => $eventIds,
            'aktif_ids' => $aktifIds,
            'allocation_ids' => $allocationIds,
            'personel_ids' => $personelIds,
            'olusum_ids' => $olusumIds,
            'scope_fingerprint' => $this->fingerprintScope($eventIds, $allocationIds, $olusumIds),
        ];
    }

    /**
     * @param array<int, true> $eventSet
     */
    private function expandDescendants(PDO $pdo, $rootId, array &$eventSet)
    {
        $frontier = [(int) $rootId];
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
                if (!isset($eventSet[$cid])) {
                    $eventSet[$cid] = true;
                    $frontier[] = $cid;
                }
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPersonelEvents(PDO $pdo, $personelId)
    {
        if (!DependentRetentionGate::tableExists($pdo, 'serbest_zaman_events')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM serbest_zaman_events WHERE personel_id = :pid ORDER BY id ASC'
        );
        $stmt->execute(['pid' => (int) $personelId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param list<int> $eventIds
     * @param list<int> $allocationIds
     * @param list<int> $olusumIds
     */
    private function fingerprintScope(array $eventIds, array $allocationIds, array $olusumIds)
    {
        $material = [
            'allocation_ids' => array_values(array_map('intval', $allocationIds)),
            'event_ids' => array_values(array_map('intval', $eventIds)),
            'olusum_ids' => array_values(array_map('intval', $olusumIds)),
        ];
        sort($material['allocation_ids']);
        sort($material['event_ids']);
        sort($material['olusum_ids']);
        $json = json_encode($material, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('DESTRUCTION_PLAN_HASH_FAILED');
        }

        return hash('sha256', $json);
    }

    /**
     * Base week-owned OLUSUM graph (seed + descendants) before allocation absorption.
     *
     * @return array{
     *   event_ids:list<int>,
     *   aktif_ids:list<int>,
     *   personel_ids:list<int>,
     *   olusum_ids:list<int>
     * }
     */
    private function collectBaseScope(PDO $pdo, $kapanisId)
    {
        $kapanisId = (int) $kapanisId;
        $eventIds = [];
        $aktifIds = [];
        $personelMap = [];
        $olusumIds = [];
        if ($kapanisId <= 0) {
            return [
                'event_ids' => [],
                'aktif_ids' => [],
                'personel_ids' => [],
                'olusum_ids' => [],
            ];
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
                'olusum_ids' => [],
            ];
        }

        $seed = [];
        if (count($snapshotIds) > 0) {
            $ph = implode(',', array_fill(0, count($snapshotIds), '?'));
            $q = $pdo->prepare(
                "SELECT id, personel_id, event_tipi FROM serbest_zaman_events
                 WHERE kaynak_snapshot_id IN ({$ph})"
            );
            $q->execute($snapshotIds);
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $seed[(int) $row['id']] = true;
                if ((string) ($row['event_tipi'] ?? '') === 'SERBEST_ZAMAN_OLUSUM') {
                    $olusumIds[] = (int) $row['id'];
                }
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $personelMap[$pid] = true;
                }
            }
        }
        if (count($tercihIds) > 0) {
            $ph = implode(',', array_fill(0, count($tercihIds), '?'));
            $q = $pdo->prepare(
                "SELECT id, personel_id, event_tipi FROM serbest_zaman_events
                 WHERE kaynak_odeme_tercihi_id IN ({$ph})"
            );
            $q->execute($tercihIds);
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $seed[(int) $row['id']] = true;
                if ((string) ($row['event_tipi'] ?? '') === 'SERBEST_ZAMAN_OLUSUM') {
                    $olusumIds[] = (int) $row['id'];
                }
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $personelMap[$pid] = true;
                }
            }
        }

        $frontier = array_keys($seed);
        $all = $seed;
        $guard = 0;
        while (count($frontier) > 0 && $guard < 5000) {
            $guard++;
            $id = array_shift($frontier);
            $child = $pdo->prepare(
                'SELECT id, personel_id, event_tipi FROM serbest_zaman_events WHERE hedef_event_id = :id'
            );
            $child->execute(['id' => $id]);
            while ($row = $child->fetch(PDO::FETCH_ASSOC)) {
                $cid = (int) $row['id'];
                if (!isset($all[$cid])) {
                    $all[$cid] = true;
                    $frontier[] = $cid;
                }
                if ((string) ($row['event_tipi'] ?? '') === 'SERBEST_ZAMAN_OLUSUM') {
                    $olusumIds[] = $cid;
                }
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $personelMap[$pid] = true;
                }
            }
        }
        $eventIds = array_map('intval', array_keys($all));
        sort($eventIds);
        $olusumIds = array_values(array_unique(array_map('intval', $olusumIds)));
        sort($olusumIds);

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
            'olusum_ids' => $olusumIds,
        ];
    }
}
