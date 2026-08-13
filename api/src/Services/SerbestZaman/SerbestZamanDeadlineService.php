<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SerbestZaman;

use PDO;

/**
 * Pack 4B: read-only Serbest Zaman 6-month deadline / ops follow-up projection.
 *
 * COMPLIANCE_MODE = WARNING_AND_OPERATIONAL_FOLLOWUP
 * PAYROLL_HARD_BLOCK = NO
 * Warning window (operational only): 30 days — not the legal 6-month boundary.
 * Expiry boundary: referans_tarih <= son_kullanim_tarihi → ACTIVE; > → EXPIRED.
 * No auto-backfill; LEGACY_UNALLOCATED / INVARIANT_BROKEN → ALLOCATION_UNRESOLVED.
 */
final class SerbestZamanDeadlineService
{
    /** Operational warning threshold only — not the legal 6-month entitlement boundary. */
    public const WARNING_DAYS = 30;

    public const COMPLIANCE_MODE = 'WARNING_AND_OPERATIONAL_FOLLOWUP';
    public const PAYROLL_HARD_BLOCK = false;

    public const DEADLINE_NORMAL = 'NORMAL';
    public const DEADLINE_YAKLASIYOR = 'YAKLASIYOR';
    public const DEADLINE_SURESI_DOLDU = 'SURESI_DOLDU';
    public const DEADLINE_ALLOCATION_UNRESOLVED = 'ALLOCATION_UNRESOLVED';

    public const ACTION_NONE = 'NONE';
    public const ACTION_WARN_APPROACHING = 'WARN_APPROACHING';
    public const ACTION_MARK_EXPIRED_UNUSED = 'MARK_EXPIRED_UNUSED';
    public const ACTION_MANUAL_ALLOCATION_REVIEW = 'MANUAL_ALLOCATION_REVIEW';

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    public static function projectPersonelDeadlineRows(
        PDO $pdo,
        array $events,
        $personelId,
        $referansTarih,
        array $personelMeta = []
    ) {
        $personelId = (int) $personelId;
        $referansTarih = (string) $referansTarih;
        $allocState = SerbestZamanAllocationService::tableExists($pdo)
            ? SerbestZamanAllocationService::personelAllocationState($pdo, $events, $personelId)
            : [
                'state' => SerbestZamanAllocationService::STATE_NO_USAGE,
                'legacy_unallocated_usage_count' => 0,
                'invariant_broken_count' => 0,
            ];

        $baseMeta = [
            'personel_id' => $personelId,
            'ad_soyad' => (string) ($personelMeta['ad_soyad'] ?? ''),
            'sicil_no' => (string) ($personelMeta['sicil_no'] ?? ''),
            'sube_id' => isset($personelMeta['sube_id']) ? (int) $personelMeta['sube_id'] : null,
            'sube_ad' => (string) ($personelMeta['sube_ad'] ?? ''),
            'bolum_ad' => (string) ($personelMeta['bolum_ad'] ?? ''),
            'allocation_state' => (string) $allocState['state'],
            'compliance_mode' => self::COMPLIANCE_MODE,
            'payroll_hard_block' => self::PAYROLL_HARD_BLOCK,
            'warning_days' => self::WARNING_DAYS,
            'referans_tarih' => $referansTarih,
        ];

        if ($allocState['state'] === SerbestZamanAllocationService::STATE_LEGACY_UNALLOCATED
            || $allocState['state'] === SerbestZamanAllocationService::STATE_INVARIANT_BROKEN
        ) {
            return [[
                'personel_id' => $personelId,
                'ad_soyad' => $baseMeta['ad_soyad'],
                'sicil_no' => $baseMeta['sicil_no'],
                'sube_id' => $baseMeta['sube_id'],
                'sube_ad' => $baseMeta['sube_ad'],
                'bolum_ad' => $baseMeta['bolum_ad'],
                'allocation_state' => (string) $allocState['state'],
                'olusum_event_id' => null,
                'son_kullanim_tarihi' => null,
                'available_dakika' => null,
                'kalan_gun' => null,
                'deadline_state' => self::DEADLINE_ALLOCATION_UNRESOLVED,
                'compliance_action' => self::ACTION_MANUAL_ALLOCATION_REVIEW,
                'expiry_state' => null,
            ]];
        }

        if ($allocState['state'] !== SerbestZamanAllocationService::STATE_ALLOCATED
            && $allocState['state'] !== SerbestZamanAllocationService::STATE_NO_USAGE
        ) {
            return [];
        }

        if (!SerbestZamanAllocationService::tableExists($pdo)) {
            return [];
        }

        $lots = SerbestZamanAllocationService::projectLots($pdo, $events, $personelId, $referansTarih);
        $rows = [];
        foreach ($lots as $lot) {
            $available = (int) ($lot['available_dakika'] ?? 0);
            if ($available <= 0) {
                // Fully consumed lots must not create deadline noise.
                continue;
            }
            $son = (string) ($lot['son_kullanim_tarihi'] ?? '');
            $expiry = (string) ($lot['expiry_state'] ?? '');
            $kalanGun = null;
            if ($son !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $son)) {
                $kalanGun = self::daysBetween($referansTarih, $son);
            }

            if ($expiry === 'EXPIRED') {
                $deadlineState = self::DEADLINE_SURESI_DOLDU;
                $action = self::ACTION_MARK_EXPIRED_UNUSED;
            } elseif ($kalanGun !== null && $kalanGun >= 0 && $kalanGun <= self::WARNING_DAYS) {
                $deadlineState = self::DEADLINE_YAKLASIYOR;
                $action = self::ACTION_WARN_APPROACHING;
            } else {
                $deadlineState = self::DEADLINE_NORMAL;
                $action = self::ACTION_NONE;
            }

            $rows[] = [
                'personel_id' => $personelId,
                'ad_soyad' => $baseMeta['ad_soyad'],
                'sicil_no' => $baseMeta['sicil_no'],
                'sube_id' => $baseMeta['sube_id'],
                'sube_ad' => $baseMeta['sube_ad'],
                'bolum_ad' => $baseMeta['bolum_ad'],
                'allocation_state' => (string) $allocState['state'],
                'olusum_event_id' => (int) ($lot['olusum_event_id'] ?? 0),
                'son_kullanim_tarihi' => $son !== '' ? $son : null,
                'available_dakika' => $available,
                'kalan_gun' => $kalanGun,
                'deadline_state' => $deadlineState,
                'compliance_action' => $action,
                'expiry_state' => $expiry !== '' ? $expiry : null,
            ];
        }

        return $rows;
    }

    /**
     * Deterministic sort: SURESI_DOLDU → YAKLASIYOR → ALLOCATION_UNRESOLVED → NORMAL,
     * then earliest son_kullanim_tarihi, then personel_id, then olusum_event_id.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sortDeadlineRows(array $rows)
    {
        usort($rows, static function ($a, $b) {
            $ra = self::deadlineRank((string) ($a['deadline_state'] ?? ''));
            $rb = self::deadlineRank((string) ($b['deadline_state'] ?? ''));
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            $sa = (string) ($a['son_kullanim_tarihi'] ?? '');
            $sb = (string) ($b['son_kullanim_tarihi'] ?? '');
            if ($sa === '' && $sb !== '') {
                return 1;
            }
            if ($sa !== '' && $sb === '') {
                return -1;
            }
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            $pa = (int) ($a['personel_id'] ?? 0);
            $pb = (int) ($b['personel_id'] ?? 0);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return ((int) ($a['olusum_event_id'] ?? 0)) <=> ((int) ($b['olusum_event_id'] ?? 0));
        });

        return $rows;
    }

    /**
     * Summary totals independent of pagination (filtered full set).
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public static function summarize(array $rows, $referansTarih)
    {
        $yaklasanLot = 0;
        $yaklasanDk = 0;
        $suresiDolanLot = 0;
        $suresiDolanDk = 0;
        $unresolvedPersonel = [];
        foreach ($rows as $row) {
            $state = (string) ($row['deadline_state'] ?? '');
            $available = $row['available_dakika'];
            if ($state === self::DEADLINE_YAKLASIYOR) {
                $yaklasanLot++;
                $yaklasanDk += (int) $available;
            } elseif ($state === self::DEADLINE_SURESI_DOLDU) {
                $suresiDolanLot++;
                $suresiDolanDk += (int) $available;
            } elseif ($state === self::DEADLINE_ALLOCATION_UNRESOLVED) {
                $pid = (int) ($row['personel_id'] ?? 0);
                if ($pid > 0) {
                    $unresolvedPersonel[$pid] = true;
                }
            }
        }

        return [
            'referans_tarih' => (string) $referansTarih,
            'warning_days' => self::WARNING_DAYS,
            'compliance_mode' => self::COMPLIANCE_MODE,
            'payroll_hard_block' => self::PAYROLL_HARD_BLOCK,
            'yaklasan_lot_sayisi' => $yaklasanLot,
            'yaklasan_dakika' => $yaklasanDk,
            'suresi_dolmus_lot_sayisi' => $suresiDolanLot,
            'suresi_dolmus_kullanilmamis_dakika' => $suresiDolanDk,
            'allocation_unresolved_personel_sayisi' => count($unresolvedPersonel),
        ];
    }

    /**
     * Inclusive remaining days: same day → 0. Negative when referans after son.
     */
    public static function daysBetween($fromYmd, $toYmd)
    {
        $from = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $fromYmd);
        $to = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $toYmd);
        if (!$from || !$to) {
            return null;
        }
        $from = $from->setTime(0, 0, 0);
        $to = $to->setTime(0, 0, 0);

        return (int) $from->diff($to)->format('%r%a');
    }

    private static function deadlineRank($state)
    {
        switch ((string) $state) {
            case self::DEADLINE_SURESI_DOLDU:
                return 0;
            case self::DEADLINE_YAKLASIYOR:
                return 1;
            case self::DEADLINE_ALLOCATION_UNRESOLVED:
                return 2;
            case self::DEADLINE_NORMAL:
                return 3;
            default:
                return 9;
        }
    }
}
