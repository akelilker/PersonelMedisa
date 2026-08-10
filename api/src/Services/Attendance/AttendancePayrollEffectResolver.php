<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

/**
 * Pure attendance payroll effect resolver — no DB access.
 * Raw gec_kalma_dakika / erken_cikis_dakika are never mutated.
 * Sealed/canonical puantaj notice fact is never overwritten by decision metadata.
 */
final class AttendancePayrollEffectResolver
{
    /**
     * @param array<string, mixed> $row
     * @return array{raw:int,effective:int,karar:?string,block:bool,reason:?string}
     */
    public static function resolveLateDeduction(array $row)
    {
        $raw = isset($row['gec_kalma_dakika']) ? (int) $row['gec_kalma_dakika'] : 0;
        $karar = self::normalizeKarar($row);
        $bildirdi = self::isTruthy($row['durumu_bildirdi_mi'] ?? null);
        $approved = self::hasApprovedOfficialProcess($row);

        if (
            $karar === AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA
            && AttendanceDisciplineCatalog::isLateToleranceAllowed($raw)
        ) {
            return self::result($raw, 0, $karar, false, 'TOLERANS_UYGULA');
        }
        if ($karar === AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA) {
            $karar = AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA;
        }
        if ($karar === AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA) {
            return self::result($raw, $raw, $karar, false, 'KESINTI_UYGULA');
        }
        if ($karar === AttendanceDisciplineCatalog::KARAR_OFFICIAL_PROCESS_REQUIRED) {
            if ($approved) {
                return self::result($raw, 0, $karar, false, 'OFFICIAL_PROCESS_APPROVED');
            }

            return self::result($raw, 0, $karar, true, 'OFFICIAL_PROCESS_PENDING');
        }
        if ($approved && $raw > 0) {
            return self::result($raw, 0, $karar, false, 'OFFICIAL_PROCESS_APPROVED');
        }
        if ($bildirdi && ($karar === null || $karar === AttendanceDisciplineCatalog::KARAR_BEKLIYOR)) {
            return self::result($raw, 0, $karar, true, 'PENDING_MANAGER_DECISION');
        }

        return self::result($raw, $raw > 0 ? $raw : 0, $karar, false, null);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{raw:int,effective:int,karar:?string,block:bool,reason:?string}
     */
    public static function resolveEarlyDeduction(array $row)
    {
        $raw = isset($row['erken_cikis_dakika']) ? (int) $row['erken_cikis_dakika'] : 0;
        $karar = self::normalizeKarar($row);
        $bildirdi = self::isTruthy($row['durumu_bildirdi_mi'] ?? null);
        $approved = self::hasApprovedOfficialProcess($row);

        if ($karar === AttendanceDisciplineCatalog::KARAR_OFFICIAL_PROCESS_REQUIRED) {
            if ($approved) {
                return self::result($raw, 0, $karar, false, 'OFFICIAL_PROCESS_APPROVED');
            }

            return self::result($raw, 0, $karar, true, 'OFFICIAL_PROCESS_PENDING');
        }
        if ($approved && $raw > 0) {
            return self::result($raw, 0, $karar, false, 'OFFICIAL_PROCESS_APPROVED');
        }
        // TOLERANS_UYGULA is never valid for early exit — ignore and use actual.
        if ($karar === AttendanceDisciplineCatalog::KARAR_TOLERANS_UYGULA) {
            $karar = AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA;
        }
        if ($karar === AttendanceDisciplineCatalog::KARAR_KESINTI_UYGULA) {
            return self::result($raw, $raw, $karar, false, 'KESINTI_UYGULA');
        }
        if ($bildirdi && ($karar === null || $karar === AttendanceDisciplineCatalog::KARAR_BEKLIYOR)) {
            return self::result($raw, 0, $karar, true, 'PENDING_MANAGER_DECISION');
        }

        return self::result($raw, $raw > 0 ? $raw : 0, $karar, false, null);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $lateKararRow
     * @param array<string, mixed>|null $earlyKararRow
     * @return array<string, mixed>
     */
    public static function applyToPuantajRow(array $row, $lateKararRow = null, $earlyKararRow = null)
    {
        // Backward-compatible single kararRow call: applyToPuantajRow($row, $kararRow)
        if (func_num_args() === 2 && is_array($lateKararRow) && isset($lateKararRow['olay_turu'])) {
            $olay = strtoupper((string) $lateKararRow['olay_turu']);
            if ($olay === AttendanceDisciplineCatalog::OLAY_ERKEN_CIKIS) {
                $earlyKararRow = $lateKararRow;
                $lateKararRow = null;
            }
        }

        // Sealed/canonical notice fact is authoritative — never overwrite from decision metadata.
        $merged = $row;

        $lateKarar = is_array($lateKararRow) && isset($lateKararRow['karar'])
            ? (string) $lateKararRow['karar']
            : null;
        $earlyKarar = is_array($earlyKararRow) && isset($earlyKararRow['karar'])
            ? (string) $earlyKararRow['karar']
            : null;

        $lateRow = $merged;
        if ($lateKarar !== null) {
            $lateRow['puantaj_olay_karar'] = $lateKarar;
        } else {
            unset($lateRow['puantaj_olay_karar']);
        }
        $earlyRow = $merged;
        if ($earlyKarar !== null) {
            $earlyRow['puantaj_olay_karar'] = $earlyKarar;
        } else {
            unset($earlyRow['puantaj_olay_karar']);
        }

        $late = self::resolveLateDeduction($lateRow);
        $early = self::resolveEarlyDeduction($earlyRow);

        $merged['gec_kalma_effective_dakika'] = $late['effective'];
        $merged['erken_cikis_effective_dakika'] = $early['effective'];
        $merged['attendance_decision_pending'] = $late['block'] || $early['block'];
        $merged['attendance_late_raw_dakika'] = $late['raw'];
        $merged['attendance_early_raw_dakika'] = $early['raw'];
        $merged['attendance_late_karar'] = $late['karar'];
        $merged['attendance_early_karar'] = $early['karar'];
        $merged['attendance_late_block_reason'] = $late['reason'];
        $merged['attendance_early_block_reason'] = $early['reason'];
        if (is_array($lateKararRow) && isset($lateKararRow['id'])) {
            $merged['attendance_late_karar_id'] = (int) $lateKararRow['id'];
        }
        if (is_array($earlyKararRow) && isset($earlyKararRow['id'])) {
            $merged['attendance_early_karar_id'] = (int) $earlyKararRow['id'];
        }

        return $merged;
    }

    /**
     * @param array<int, array<string, mixed>> $puantajlar
     * @param array<string, array<string, mixed>> $kararByKey personelId|tarih|olay => karar row
     * @return array<int, array<string, mixed>>
     */
    public static function annotatePuantajlar(array $puantajlar, array $kararByKey)
    {
        $out = [];
        foreach ($puantajlar as $row) {
            if (!is_array($row)) {
                continue;
            }
            $personelId = isset($row['personel_id']) ? (int) $row['personel_id'] : 0;
            $tarih = (string) ($row['tarih'] ?? '');
            $lateKey = self::kararKey($personelId, $tarih, AttendanceDisciplineCatalog::OLAY_GEC_KALMA);
            $earlyKey = self::kararKey($personelId, $tarih, AttendanceDisciplineCatalog::OLAY_ERKEN_CIKIS);
            $lateKarar = isset($kararByKey[$lateKey]) ? $kararByKey[$lateKey] : null;
            $earlyKarar = isset($kararByKey[$earlyKey]) ? $kararByKey[$earlyKey] : null;

            // Prefer sealed snapshot karar payload when present (immutable payroll input).
            if (isset($row['olay_kararlari']) && is_array($row['olay_kararlari'])) {
                $sealed = $row['olay_kararlari'];
                if (isset($sealed[AttendanceDisciplineCatalog::OLAY_GEC_KALMA]) && is_array($sealed[AttendanceDisciplineCatalog::OLAY_GEC_KALMA])) {
                    $lateKarar = $sealed[AttendanceDisciplineCatalog::OLAY_GEC_KALMA];
                }
                if (isset($sealed[AttendanceDisciplineCatalog::OLAY_ERKEN_CIKIS]) && is_array($sealed[AttendanceDisciplineCatalog::OLAY_ERKEN_CIKIS])) {
                    $earlyKarar = $sealed[AttendanceDisciplineCatalog::OLAY_ERKEN_CIKIS];
                }
            }

            $out[] = self::applyToPuantajRow($row, $lateKarar, $earlyKarar);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $aday
     * @param array<string, mixed>|null $kararRow
     * @return array<string, mixed>
     */
    public static function enrichEtkiAday(array $aday, $kararRow)
    {
        if (!is_array($aday)) {
            return $aday;
        }
        $tur = strtoupper((string) ($aday['etki_turu'] ?? ''));
        if ($tur !== 'GEC_KALMA_DAKIKA' && $tur !== 'ERKEN_CIKIS_DAKIKA') {
            return $aday;
        }

        // Prefer canonical aday/puantaj notice fact over decision-table copy.
        $pseudoRow = [
            'gec_kalma_dakika' => $tur === 'GEC_KALMA_DAKIKA' ? (int) ($aday['etki_miktari'] ?? 0) : 0,
            'erken_cikis_dakika' => $tur === 'ERKEN_CIKIS_DAKIKA' ? (int) ($aday['etki_miktari'] ?? 0) : 0,
            'durumu_bildirdi_mi' => array_key_exists('durumu_bildirdi_mi', $aday)
                ? $aday['durumu_bildirdi_mi']
                : null,
            'dayanak' => $aday['dayanak'] ?? null,
            'onayli_izin_var' => $aday['onayli_izin_var'] ?? null,
            'approved_leave' => $aday['approved_leave'] ?? null,
        ];
        if (is_array($kararRow) && isset($kararRow['karar'])) {
            $pseudoRow['puantaj_olay_karar'] = (string) $kararRow['karar'];
        }

        $resolved = $tur === 'GEC_KALMA_DAKIKA'
            ? self::resolveLateDeduction($pseudoRow)
            : self::resolveEarlyDeduction($pseudoRow);

        $aday['effective_miktar'] = $resolved['effective'];
        $aday['raw_miktar'] = $resolved['raw'];
        $aday['pending_decision'] = $resolved['block'];
        if ($resolved['karar'] !== null) {
            $meta = is_array($aday['metadata'] ?? null) ? $aday['metadata'] : [];
            $meta['attendance_karar'] = $resolved['karar'];
            $meta['attendance_raw_dakika'] = $resolved['raw'];
            $meta['attendance_effective_dakika'] = $resolved['effective'];
            $meta['attendance_block_reason'] = $resolved['reason'];
            $aday['metadata'] = $meta;
        }

        return $aday;
    }

    public static function kararKey($personelId, $tarih, $olayTuru)
    {
        return (int) $personelId . '|' . trim((string) $tarih) . '|' . strtoupper(trim((string) $olayTuru));
    }

    /**
     * Snapshot-safe karar subset for attendancePayload / source hash.
     *
     * @param array<string, mixed> $kararRow
     * @return array<string, mixed>
     */
    public static function sealKararPayload(array $kararRow)
    {
        return [
            'id' => isset($kararRow['id']) ? (int) $kararRow['id'] : null,
            'personel_id' => isset($kararRow['personel_id']) ? (int) $kararRow['personel_id'] : null,
            'tarih' => isset($kararRow['tarih']) ? (string) $kararRow['tarih'] : null,
            'olay_turu' => isset($kararRow['olay_turu']) ? (string) $kararRow['olay_turu'] : null,
            'raw_dakika' => isset($kararRow['raw_dakika']) ? (int) $kararRow['raw_dakika'] : null,
            'karar' => isset($kararRow['karar']) ? (string) $kararRow['karar'] : null,
            'karar_veren_user_id' => isset($kararRow['karar_veren_user_id']) && $kararRow['karar_veren_user_id'] !== null
                ? (int) $kararRow['karar_veren_user_id'] : null,
            'karar_at' => isset($kararRow['karar_at']) && $kararRow['karar_at'] !== null
                ? (string) $kararRow['karar_at'] : null,
            'gerekce' => isset($kararRow['gerekce']) && $kararRow['gerekce'] !== null
                ? (string) $kararRow['gerekce'] : null,
            'source_hash' => isset($kararRow['source_hash']) ? (string) $kararRow['source_hash'] : null,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function normalizeKarar(array $row)
    {
        $karar = null;
        if (isset($row['puantaj_olay_karar'])) {
            $karar = strtoupper(trim((string) $row['puantaj_olay_karar']));
        } elseif (isset($row['karar'])) {
            $karar = strtoupper(trim((string) $row['karar']));
        }
        if ($karar === '') {
            return null;
        }

        return $karar;
    }

    /** @param mixed $value */
    private static function isTruthy($value)
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }

        return (int) $value === 1;
    }

    /**
     * Canonical approved official process evidence only — no broad string heuristics.
     *
     * @param array<string, mixed> $row
     */
    public static function hasApprovedOfficialProcess(array $row)
    {
        if (!empty($row['onayli_izin_var']) || !empty($row['approved_leave'])) {
            return true;
        }

        return AttendanceDisciplineCatalog::isAuthorizedAbsenceDayanak($row['dayanak'] ?? null);
    }

    /** @return array{raw:int,effective:int,karar:?string,block:bool,reason:?string} */
    private static function result($raw, $effective, $karar, $block, $reason)
    {
        return [
            'raw' => (int) $raw,
            'effective' => (int) $effective,
            'karar' => $karar,
            'block' => (bool) $block,
            'reason' => $reason,
        ];
    }
}
