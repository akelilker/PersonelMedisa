<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SerbestZaman;

use PDO;
use RuntimeException;

/**
 * Pack 4A: explicit KULLANIM→OLUSUM allocation ledger (append-only deltas).
 *
 * POLICY (product/canonical, not a legal claim): EARLIEST_EXPIRY_FIRST_V1
 * Release on reduce/cancel: REVERSE_EARLIEST_EXPIRY_FIRST_V1
 * Expiry boundary: reuse canonical `$referans > $son_kullanim_tarihi` (usable on expiry day).
 * Legacy pre-061 KULLANIM: NO auto-backfill; LEGACY_UNALLOCATED fail-closed on new writes.
 */
final class SerbestZamanAllocationService
{
    public const POLICY_CONSUME = 'EARLIEST_EXPIRY_FIRST_V1';
    public const POLICY_RELEASE = 'REVERSE_EARLIEST_EXPIRY_FIRST_V1';

    public const STATE_ALLOCATED = 'ALLOCATED';
    public const STATE_LEGACY_UNALLOCATED = 'LEGACY_UNALLOCATED';
    public const STATE_INVARIANT_BROKEN = 'INVARIANT_BROKEN';
    public const STATE_ZERO = 'ZERO';
    public const STATE_NO_USAGE = 'NO_USAGE';

    public const CODE_LEGACY_ALLOCATION_REQUIRED = 'SERBEST_ZAMAN_LEGACY_ALLOCATION_REQUIRED';
    public const CODE_ALLOCATION_INVARIANT_BROKEN = 'SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN';
    public const CODE_SCHEMA_NOT_READY = 'SERBEST_ZAMAN_ALLOCATION_SCHEMA_NOT_READY';
    public const CODE_OLUSUM_HAS_ALLOCATIONS = 'SERBEST_ZAMAN_OLUSUM_HAS_ALLOCATIONS';

    public static function tableExists(PDO $pdo)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => 'serbest_zaman_kullanim_tahsisleri']);

        return (bool) $stmt->fetchColumn();
    }

    public static function assertSchemaReady(PDO $pdo)
    {
        if (!self::tableExists($pdo)) {
            throw new RuntimeException(self::CODE_SCHEMA_NOT_READY);
        }
    }

    /**
     * Canonical effective dakika for OLUSUM/KULLANIM after IPTAL/DUZELTME chain.
     * IPTAL of target → 0. Latest non-cancelled DUZELTME wins (by id ASC apply).
     *
     * @param list<array<string, mixed>> $events
     */
    public static function effectiveEventDakika(array $events, $eventId, $personelId)
    {
        $eventId = (int) $eventId;
        $personelId = (int) $personelId;
        $target = null;
        foreach ($events as $event) {
            if ((int) ($event['id'] ?? 0) === $eventId
                && (int) ($event['personel_id'] ?? 0) === $personelId
            ) {
                $target = $event;
                break;
            }
        }
        if ($target === null) {
            return 0;
        }

        $iptalHedefIds = self::iptalHedefIds($events, $personelId);
        if (isset($iptalHedefIds[$eventId])) {
            return 0;
        }

        $overrides = self::duzeltmeOverrides($events, $personelId, $iptalHedefIds);
        if (isset($overrides[$eventId])) {
            return (int) $overrides[$eventId];
        }

        return (int) ($target['dakika'] ?? 0);
    }

    /**
     * Per-KULLANIM allocation provenance (event-level).
     *
     * effective > 0 && net == 0 → LEGACY_UNALLOCATED
     * effective > 0 && net == effective → ALLOCATED
     * effective == 0 && net == 0 → ZERO (cancelled / zeroed)
     * net != effective && net != 0 → INVARIANT_BROKEN
     * (also effective == 0 && net > 0 → INVARIANT_BROKEN)
     *
     * @param list<array<string, mixed>> $events
     * @return array{state:string,effective:int,net:int}
     */
    public static function usageAllocationState(PDO $pdo, array $events, $personelId, $kullanimEventId)
    {
        $personelId = (int) $personelId;
        $kullanimEventId = (int) $kullanimEventId;
        $effective = self::effectiveEventDakika($events, $kullanimEventId, $personelId);
        $net = self::tableExists($pdo) ? self::netAllocatedForUsage($pdo, $kullanimEventId) : 0;

        if ($effective === 0 && $net === 0) {
            return [
                'state' => self::STATE_ZERO,
                'effective' => 0,
                'net' => 0,
            ];
        }
        if ($effective > 0 && $net === 0) {
            return [
                'state' => self::STATE_LEGACY_UNALLOCATED,
                'effective' => $effective,
                'net' => 0,
            ];
        }
        if ($effective > 0 && $net === $effective) {
            return [
                'state' => self::STATE_ALLOCATED,
                'effective' => $effective,
                'net' => $net,
            ];
        }

        return [
            'state' => self::STATE_INVARIANT_BROKEN,
            'effective' => $effective,
            'net' => $net,
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{state:string,legacy_unallocated_usage_count:int,invariant_broken_count:int}
     */
    public static function personelAllocationState(PDO $pdo, array $events, $personelId)
    {
        $personelId = (int) $personelId;
        if (!self::tableExists($pdo)) {
            return [
                'state' => self::STATE_NO_USAGE,
                'legacy_unallocated_usage_count' => 0,
                'invariant_broken_count' => 0,
            ];
        }

        $legacy = 0;
        $broken = 0;
        $hasUsage = false;
        foreach ($events as $event) {
            if ((string) ($event['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_KULLANIM') {
                continue;
            }
            if ((int) ($event['personel_id'] ?? 0) !== $personelId) {
                continue;
            }
            $eid = (int) ($event['id'] ?? 0);
            $usage = self::usageAllocationState($pdo, $events, $personelId, $eid);
            if ($usage['state'] === self::STATE_ZERO) {
                continue;
            }
            if ($usage['state'] === self::STATE_LEGACY_UNALLOCATED) {
                $hasUsage = true;
                $legacy++;
                continue;
            }
            if ($usage['state'] === self::STATE_INVARIANT_BROKEN) {
                $hasUsage = true;
                $broken++;
                continue;
            }
            // ALLOCATED
            $hasUsage = true;
        }

        if ($broken > 0) {
            $state = self::STATE_INVARIANT_BROKEN;
        } elseif ($legacy > 0) {
            $state = self::STATE_LEGACY_UNALLOCATED;
        } elseif ($hasUsage) {
            $state = self::STATE_ALLOCATED;
        } else {
            $state = self::STATE_NO_USAGE;
        }

        return [
            'state' => $state,
            'legacy_unallocated_usage_count' => $legacy,
            'invariant_broken_count' => $broken,
        ];
    }

    public static function assertWritableForNewUsage(PDO $pdo, array $events, $personelId)
    {
        $state = self::personelAllocationState($pdo, $events, $personelId);
        if ($state['state'] === self::STATE_LEGACY_UNALLOCATED) {
            throw new RuntimeException(self::CODE_LEGACY_ALLOCATION_REQUIRED);
        }
        if ($state['state'] === self::STATE_INVARIANT_BROKEN) {
            throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
        }
    }

    /**
     * KULLANIM DUZELTME: block LEGACY_UNALLOCATED (no invented provenance) and INVARIANT_BROKEN.
     *
     * @param list<array<string, mixed>> $events
     */
    public static function assertUsageMutableForCorrection(PDO $pdo, array $events, $personelId, $kullanimEventId)
    {
        $usage = self::usageAllocationState($pdo, $events, $personelId, $kullanimEventId);
        if ($usage['state'] === self::STATE_LEGACY_UNALLOCATED) {
            throw new RuntimeException(self::CODE_LEGACY_ALLOCATION_REQUIRED);
        }
        if ($usage['state'] === self::STATE_INVARIANT_BROKEN) {
            throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
        }
    }

    /**
     * KULLANIM IPTAL: INVARIANT_BROKEN blocked (no auto-repair).
     * LEGACY_UNALLOCATED may proceed (full cancel invents no lot provenance).
     *
     * @param list<array<string, mixed>> $events
     */
    public static function assertUsageMutableForCancel(PDO $pdo, array $events, $personelId, $kullanimEventId)
    {
        $usage = self::usageAllocationState($pdo, $events, $personelId, $kullanimEventId);
        if ($usage['state'] === self::STATE_INVARIANT_BROKEN) {
            throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
        }
    }

    /**
     * Allocate positive deltas for a new or increased KULLANIM (consume policy).
     *
     * @param list<array<string, mixed>> $events
     * @return list<array{olusum_event_id:int,delta:int}>
     */
    public static function allocateConsume(
        PDO $pdo,
        array $events,
        $personelId,
        $kullanimEventId,
        $kaynakEventId,
        $dakika,
        $referansTarih
    ) {
        $personelId = (int) $personelId;
        $kullanimEventId = (int) $kullanimEventId;
        $kaynakEventId = (int) $kaynakEventId;
        $dakika = (int) $dakika;
        $referansTarih = (string) $referansTarih;
        if ($dakika <= 0) {
            return [];
        }

        $lots = self::eligibleLotsForConsume($pdo, $events, $personelId, $referansTarih);
        $remaining = $dakika;
        $plan = [];
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $available = (int) $lot['available_dakika'];
            if ($available <= 0) {
                continue;
            }
            $take = min($remaining, $available);
            $plan[] = [
                'olusum_event_id' => (int) $lot['olusum_event_id'],
                'delta' => $take,
            ];
            $remaining -= $take;
        }
        if ($remaining > 0) {
            throw new RuntimeException('INSUFFICIENT_BALANCE');
        }

        foreach ($plan as $row) {
            self::insertDelta(
                $pdo,
                $personelId,
                $kullanimEventId,
                (int) $row['olusum_event_id'],
                $kaynakEventId,
                (int) $row['delta'],
                self::POLICY_CONSUME
            );
        }

        return $plan;
    }

    /**
     * Release negative deltas (reduce / cancel) using reverse policy.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array{olusum_event_id:int,delta:int}>
     */
    public static function allocateRelease(
        PDO $pdo,
        array $events,
        $personelId,
        $kullanimEventId,
        $kaynakEventId,
        $dakika
    ) {
        $personelId = (int) $personelId;
        $kullanimEventId = (int) $kullanimEventId;
        $kaynakEventId = (int) $kaynakEventId;
        $dakika = (int) $dakika;
        if ($dakika <= 0) {
            return [];
        }

        $held = self::netAllocationByLotForUsage($pdo, $kullanimEventId);
        if (array_sum($held) < $dakika) {
            throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
        }

        $olusumMeta = [];
        foreach ($events as $event) {
            if ((string) ($event['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_OLUSUM') {
                continue;
            }
            $oid = (int) ($event['id'] ?? 0);
            $olusumMeta[$oid] = $event;
        }

        uksort($held, static function ($a, $b) use ($olusumMeta) {
            $ea = $olusumMeta[$a] ?? [];
            $eb = $olusumMeta[$b] ?? [];
            $sa = (string) ($ea['son_kullanim_tarihi'] ?? '');
            $sb = (string) ($eb['son_kullanim_tarihi'] ?? '');
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }
            $ta = (string) ($ea['event_tarihi'] ?? '');
            $tb = (string) ($eb['event_tarihi'] ?? '');
            if ($ta !== $tb) {
                return $tb <=> $ta;
            }

            return ((int) $b) <=> ((int) $a);
        });

        $remaining = $dakika;
        $plan = [];
        foreach ($held as $olusumId => $net) {
            if ($remaining <= 0) {
                break;
            }
            $net = (int) $net;
            if ($net <= 0) {
                continue;
            }
            $release = min($remaining, $net);
            $plan[] = [
                'olusum_event_id' => (int) $olusumId,
                'delta' => -$release,
            ];
            $remaining -= $release;
        }
        if ($remaining > 0) {
            throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
        }

        foreach ($plan as $row) {
            self::insertDelta(
                $pdo,
                $personelId,
                $kullanimEventId,
                (int) $row['olusum_event_id'],
                $kaynakEventId,
                (int) $row['delta'],
                self::POLICY_RELEASE
            );
        }

        return $plan;
    }

    /**
     * Bring allocation net for a KULLANIM to match desired effective dakika.
     *
     * @param list<array<string, mixed>> $events
     */
    public static function reconcileUsageToEffective(
        PDO $pdo,
        array $events,
        $personelId,
        $kullanimEventId,
        $kaynakEventId,
        $desiredEffective,
        $referansTarih
    ) {
        $desiredEffective = (int) $desiredEffective;
        $kullanimEventId = (int) $kullanimEventId;
        $kaynakEventId = (int) $kaynakEventId;
        $personelId = (int) $personelId;

        // Provenance against pre-mutation events (exclude the DUZELTME/IPTAL kaynak row).
        $eventsPre = [];
        foreach ($events as $event) {
            if ((int) ($event['id'] ?? 0) === $kaynakEventId) {
                continue;
            }
            $eventsPre[] = $event;
        }
        $pre = self::usageAllocationState($pdo, $eventsPre, $personelId, $kullanimEventId);
        if ($pre['state'] === self::STATE_INVARIANT_BROKEN) {
            throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
        }
        if ($pre['state'] === self::STATE_LEGACY_UNALLOCATED) {
            // Full IPTAL (desired 0, net 0) may proceed — no provenance invented.
            // Any positive/reconcile-up path is forbidden (would invent lots).
            if ($desiredEffective !== 0) {
                throw new RuntimeException(self::CODE_LEGACY_ALLOCATION_REQUIRED);
            }
            $currentLegacy = self::netAllocatedForUsage($pdo, $kullanimEventId);
            if ($currentLegacy !== 0) {
                throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
            }
            self::assertUsageInvariant($pdo, $events, $personelId, $kullanimEventId);
            self::assertLotInvariants($pdo, $events, $personelId);

            return;
        }

        $current = self::netAllocatedForUsage($pdo, $kullanimEventId);
        $delta = $desiredEffective - $current;
        if ($delta > 0) {
            self::allocateConsume(
                $pdo,
                $events,
                $personelId,
                $kullanimEventId,
                $kaynakEventId,
                $delta,
                $referansTarih
            );
        } elseif ($delta < 0) {
            self::allocateRelease(
                $pdo,
                $events,
                $personelId,
                $kullanimEventId,
                $kaynakEventId,
                -$delta
            );
        }
        self::assertUsageInvariant($pdo, $events, $personelId, $kullanimEventId);
        self::assertLotInvariants($pdo, $events, $personelId);
    }

    public static function assertUsageInvariant(PDO $pdo, array $events, $personelId, $kullanimEventId)
    {
        $effective = self::effectiveEventDakika($events, $kullanimEventId, $personelId);
        $net = self::netAllocatedForUsage($pdo, $kullanimEventId);
        if ($net !== $effective) {
            throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
        }
    }

    public static function assertLotInvariants(PDO $pdo, array $events, $personelId)
    {
        $personelId = (int) $personelId;
        $byLot = self::netAllocationByLotForPersonel($pdo, $personelId);
        foreach ($byLot as $olusumId => $net) {
            $net = (int) $net;
            if ($net < 0) {
                throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
            }
            $effective = self::effectiveEventDakika($events, $olusumId, $personelId);
            // Every allocation-bearing lot: 0 <= net <= effective OLUSUM (no stranded skip).
            if ($net > $effective) {
                throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
            }
        }
    }

    public static function assertOlusumHasNoNetAllocation(PDO $pdo, $olusumEventId)
    {
        $net = self::netAllocatedToLot($pdo, (int) $olusumEventId);
        if ($net > 0) {
            throw new RuntimeException(self::CODE_OLUSUM_HAS_ALLOCATIONS);
        }
    }

    /**
     * OLUSUM DUZELTME: new effective must cover current net allocation.
     * yeni_dakika < net → SERBEST_ZAMAN_OLUSUM_HAS_ALLOCATIONS (fail-closed).
     */
    public static function assertOlusumEffectiveCoversAllocation(PDO $pdo, $olusumEventId, $yeniDakika)
    {
        $net = self::netAllocatedToLot($pdo, (int) $olusumEventId);
        if ((int) $yeniDakika < $net) {
            throw new RuntimeException(self::CODE_OLUSUM_HAS_ALLOCATIONS);
        }
    }

    public static function netAllocatedForUsage(PDO $pdo, $kullanimEventId)
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(tahsis_delta_dakika), 0)
             FROM serbest_zaman_kullanim_tahsisleri
             WHERE kullanim_event_id = :kid'
        );
        $stmt->execute(['kid' => (int) $kullanimEventId]);

        return (int) $stmt->fetchColumn();
    }

    public static function netAllocatedToLot(PDO $pdo, $olusumEventId)
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(SUM(tahsis_delta_dakika), 0)
             FROM serbest_zaman_kullanim_tahsisleri
             WHERE olusum_event_id = :oid'
        );
        $stmt->execute(['oid' => (int) $olusumEventId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, int> olusum_event_id => net
     */
    public static function netAllocationByLotForUsage(PDO $pdo, $kullanimEventId)
    {
        $stmt = $pdo->prepare(
            'SELECT olusum_event_id, SUM(tahsis_delta_dakika) AS net
             FROM serbest_zaman_kullanim_tahsisleri
             WHERE kullanim_event_id = :kid
             GROUP BY olusum_event_id
             HAVING SUM(tahsis_delta_dakika) <> 0'
        );
        $stmt->execute(['kid' => (int) $kullanimEventId]);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $row['olusum_event_id']] = (int) $row['net'];
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    public static function netAllocationByLotForPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT olusum_event_id, SUM(tahsis_delta_dakika) AS net
             FROM serbest_zaman_kullanim_tahsisleri
             WHERE personel_id = :pid
             GROUP BY olusum_event_id'
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $row['olusum_event_id']] = (int) $row['net'];
        }

        return $map;
    }

    /**
     * Lot projection for allocated-mode balance / 6-month foundation.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function projectLots(PDO $pdo, array $events, $personelId, $referansTarih)
    {
        $personelId = (int) $personelId;
        $referansTarih = (string) $referansTarih;
        $allocated = self::tableExists($pdo)
            ? self::netAllocationByLotForPersonel($pdo, $personelId)
            : [];
        $lots = [];
        foreach ($events as $event) {
            if ((string) ($event['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_OLUSUM') {
                continue;
            }
            if ((int) ($event['personel_id'] ?? 0) !== $personelId) {
                continue;
            }
            $oid = (int) ($event['id'] ?? 0);
            $effective = self::effectiveEventDakika($events, $oid, $personelId);
            if ($effective <= 0) {
                continue;
            }
            $alloc = isset($allocated[$oid]) ? (int) $allocated[$oid] : 0;
            $available = max($effective - $alloc, 0);
            $son = (string) ($event['son_kullanim_tarihi'] ?? '');
            $expired = ($son !== '' && $referansTarih > $son);
            if ($available === 0) {
                $expiryState = 'CONSUMED';
            } elseif ($expired) {
                $expiryState = 'EXPIRED';
            } else {
                $expiryState = 'ACTIVE';
            }
            $lots[] = [
                'olusum_event_id' => $oid,
                'event_tarihi' => (string) ($event['event_tarihi'] ?? ''),
                'son_kullanim_tarihi' => $son,
                'effective_hak_dakika' => $effective,
                'allocated_dakika' => $alloc,
                'available_dakika' => $available,
                'expiry_state' => $expiryState,
            ];
        }

        return $lots;
    }

    /**
     * @param list<array<string, mixed>> $lots
     * @return array{usable_dakika:int,expired_unused_dakika:int}
     */
    public static function summarizeLotBalance(array $lots)
    {
        $usable = 0;
        $expiredUnused = 0;
        foreach ($lots as $lot) {
            $available = (int) ($lot['available_dakika'] ?? 0);
            $state = (string) ($lot['expiry_state'] ?? '');
            if ($state === 'EXPIRED') {
                $expiredUnused += $available;
            } elseif ($state === 'ACTIVE') {
                $usable += $available;
            }
        }

        return [
            'usable_dakika' => $usable,
            'expired_unused_dakika' => $expiredUnused,
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private static function eligibleLotsForConsume(PDO $pdo, array $events, $personelId, $referansTarih)
    {
        $lots = self::projectLots($pdo, $events, $personelId, $referansTarih);
        $eligible = [];
        foreach ($lots as $lot) {
            if ((string) $lot['expiry_state'] !== 'ACTIVE') {
                continue;
            }
            if ((int) $lot['available_dakika'] <= 0) {
                continue;
            }
            $eligible[] = $lot;
        }
        usort($eligible, static function ($a, $b) {
            $sa = (string) $a['son_kullanim_tarihi'];
            $sb = (string) $b['son_kullanim_tarihi'];
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            $ta = (string) $a['event_tarihi'];
            $tb = (string) $b['event_tarihi'];
            if ($ta !== $tb) {
                return $ta <=> $tb;
            }

            return ((int) $a['olusum_event_id']) <=> ((int) $b['olusum_event_id']);
        });

        return $eligible;
    }

    private static function insertDelta(
        PDO $pdo,
        $personelId,
        $kullanimEventId,
        $olusumEventId,
        $kaynakEventId,
        $delta,
        $politika
    ) {
        $delta = (int) $delta;
        if ($delta === 0) {
            throw new RuntimeException(self::CODE_ALLOCATION_INVARIANT_BROKEN);
        }
        $stmt = $pdo->prepare(
            'INSERT INTO serbest_zaman_kullanim_tahsisleri
                (personel_id, kullanim_event_id, olusum_event_id, kaynak_event_id,
                 tahsis_delta_dakika, politika_kodu)
             VALUES
                (:pid, :kid, :oid, :sid, :delta, :politika)'
        );
        $stmt->execute([
            'pid' => (int) $personelId,
            'kid' => (int) $kullanimEventId,
            'oid' => (int) $olusumEventId,
            'sid' => (int) $kaynakEventId,
            'delta' => $delta,
            'politika' => (string) $politika,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<int, true>
     */
    private static function iptalHedefIds(array $events, $personelId)
    {
        $map = [];
        foreach ($events as $event) {
            if ((string) ($event['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_IPTAL') {
                continue;
            }
            if ((int) ($event['personel_id'] ?? 0) !== (int) $personelId) {
                continue;
            }
            $hid = (int) ($event['hedef_event_id'] ?? 0);
            if ($hid > 0) {
                $map[$hid] = true;
            }
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<int, true> $iptalHedefIds
     * @return array<int, int>
     */
    private static function duzeltmeOverrides(array $events, $personelId, array $iptalHedefIds)
    {
        $duzeltmeler = [];
        foreach ($events as $event) {
            if ((string) ($event['event_tipi'] ?? '') !== 'SERBEST_ZAMAN_DUZELTME') {
                continue;
            }
            if ((int) ($event['personel_id'] ?? 0) !== (int) $personelId) {
                continue;
            }
            $duzeltmeler[] = $event;
        }
        usort($duzeltmeler, static function ($a, $b) {
            return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
        });
        $overrides = [];
        foreach ($duzeltmeler as $event) {
            $hid = (int) ($event['hedef_event_id'] ?? 0);
            if ($hid <= 0 || isset($iptalHedefIds[$hid])) {
                continue;
            }
            // DUZELTME itself cancelled → ignore
            $did = (int) ($event['id'] ?? 0);
            if (isset($iptalHedefIds[$did])) {
                continue;
            }
            $overrides[$hid] = (int) ($event['yeni_dakika'] ?? 0);
        }

        return $overrides;
    }
}
