<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Medisa\Api\Services\Payroll\MaasHesaplamaEngine;
use PDO;

/**
 * Puantaj satirina resmi tatil takvimi projection alanlari.
 */
class ResmiTatilTakvimProjectionService
{
    public const TATIL_DONEMI_CALISMA_INTERVALI_EKSIK = 'TATIL_DONEMI_CALISMA_INTERVALI_EKSIK';

    /**
     * @param array<string, mixed> $row en az: tarih, gun_tipi, giris_saati, cikis_saati,
     *                                   gercek_mola_dakika, net_calisma_suresi_dakika
     * @return array<string, mixed>
     */
    public static function projectForPuantajRow(PDO $pdo, array $row)
    {
        $gunTipi = (string) ($row['gun_tipi'] ?? '');
        $tarih = (string) ($row['tarih'] ?? '');
        if ($tarih === '') {
            return self::emptyProjection();
        }

        $activeRows = ResmiTatilTakvimiService::listActiveForDate($pdo, $tarih, 'UBGT');
        $activeCount = count($activeRows);
        $isUbgtGunTipi = $gunTipi === 'UBGT_Resmi_Tatil';
        $isHtOnly = $gunTipi === 'Hafta_Tatili_Pazar';
        $hasUbgtCalendar = $activeCount > 0;
        $htUbgtSameDay = $isHtOnly && $hasUbgtCalendar;

        if (!$isUbgtGunTipi && !$htUbgtSameDay) {
            return self::emptyProjection();
        }

        if ($isHtOnly && !$hasUbgtCalendar) {
            return self::emptyProjection();
        }

        $out = self::emptyProjection();

        if ($htUbgtSameDay) {
            $out['ht_ubgt_ayni_gun_mi'] = true;
            $out['gun_siniflandirmalari'] = ['Hafta_Tatili_Pazar', 'UBGT_Resmi_Tatil'];
        } elseif ($isUbgtGunTipi) {
            $out['ht_ubgt_ayni_gun_mi'] = false;
            $out['gun_siniflandirmalari'] = ['UBGT_Resmi_Tatil'];
        }

        if ($isUbgtGunTipi && $activeCount === 0) {
            $out['tatil_siniflandirma_durumu'] = 'KAYNAK_EKSIK';
            $out['tatil_snapshot_hash'] = self::snapshotHash($out);

            return $out;
        }

        if ($activeCount > 1) {
            $out['tatil_siniflandirma_durumu'] = 'CAKISMA';
            $out['tatil_snapshot_hash'] = self::snapshotHash($out);

            return $out;
        }

        if ($activeCount === 1) {
            $calendar = $activeRows[0];
            $out['tatil_takvim_id'] = (int) $calendar['id'];
            $out['tatil_turu'] = (string) $calendar['tatil_turu'];
            $out['tatil_gun_kapsami'] = (string) $calendar['gun_kapsami'];
            $out['tatil_interval_baslangic'] = $calendar['tatil_interval_baslangic'];
            $out['tatil_interval_bitis'] = $calendar['tatil_interval_bitis'];
            $out['tatil_kaynak_referansi'] = (string) $calendar['kaynak_referansi'];
            $out['tatil_siniflandirma_durumu'] = 'DOGRULANDI';

            $donemi = self::resolveTatilDonemiMinutes($row, $out);
            $out['tatil_donemi_brut_calisma_dakika'] = $donemi['brut'];
            $out['tatil_donemi_ara_dinlenme_dakika'] = $donemi['mola'];
            $out['tatil_donemi_net_calisma_dakika'] = $donemi['net'];
            $out['tatil_snapshot_hash'] = self::snapshotHash($out);

            return $out;
        }

        $out['tatil_siniflandirma_durumu'] = 'BILINMIYOR';
        $out['tatil_snapshot_hash'] = self::snapshotHash($out);

        return $out;
    }

    /**
     * Narrowest safe tatil-donemi dakika cozumu.
     * - Authoritative payload/existing deger varsa koru.
     * - Giris/cikis + genel mola ile rastgele dagitim YAPMA.
     * - Yalniz guvenli kapsama durumlarinda uret.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $projection
     * @return array{brut: int|null, mola: int|null, net: int|null}
     */
    public static function resolveTatilDonemiMinutes(array $row, array $projection)
    {
        $existingNet = array_key_exists('tatil_donemi_net_calisma_dakika', $row)
            ? $row['tatil_donemi_net_calisma_dakika'] : null;
        $existingBrut = array_key_exists('tatil_donemi_brut_calisma_dakika', $row)
            ? $row['tatil_donemi_brut_calisma_dakika'] : null;
        $existingMola = array_key_exists('tatil_donemi_ara_dinlenme_dakika', $row)
            ? $row['tatil_donemi_ara_dinlenme_dakika'] : null;
        if ($existingNet !== null) {
            return [
                'brut' => $existingBrut !== null ? max(0, (int) $existingBrut) : null,
                'mola' => $existingMola !== null ? max(0, (int) $existingMola) : null,
                'net' => max(0, (int) $existingNet),
            ];
        }

        $kapsam = strtoupper(trim((string) ($projection['tatil_gun_kapsami'] ?? '')));
        $netDk = isset($row['net_calisma_suresi_dakika']) && $row['net_calisma_suresi_dakika'] !== null
            ? max(0, (int) $row['net_calisma_suresi_dakika']) : null;
        $brutDk = isset($row['gunluk_brut_sure_dakika']) && $row['gunluk_brut_sure_dakika'] !== null
            ? max(0, (int) $row['gunluk_brut_sure_dakika']) : null;
        $molaDk = isset($row['gercek_mola_dakika']) && $row['gercek_mola_dakika'] !== null
            ? max(0, (int) $row['gercek_mola_dakika']) : 0;

        // Fiili calisma yok → tatil donemi net 0 (tahmin degil).
        if ($netDk === 0) {
            return ['brut' => 0, 'mola' => 0, 'net' => 0];
        }

        if ($kapsam === 'TAM_GUN') {
            // Tam gun: tatil donemi = gunun tamamindaki fiili net (varsa).
            if ($netDk === null) {
                return ['brut' => null, 'mola' => null, 'net' => null];
            }

            return [
                'brut' => $brutDk,
                'mola' => $molaDk,
                'net' => $netDk,
            ];
        }

        if ($kapsam !== 'YARIM_GUN') {
            return ['brut' => null, 'mola' => null, 'net' => null];
        }

        $intervalStart = self::timeToMinutes($projection['tatil_interval_baslangic'] ?? null);
        $intervalEnd = self::timeToMinutes($projection['tatil_interval_bitis'] ?? null);
        if ($intervalStart === null || $intervalEnd === null || $intervalStart >= $intervalEnd) {
            return ['brut' => null, 'mola' => null, 'net' => null];
        }

        $giris = self::timeToMinutes($row['giris_saati'] ?? null);
        $cikis = self::timeToMinutes($row['cikis_saati'] ?? null);
        if ($giris === null || $cikis === null) {
            // Giris/cikis yokken net>0 tahmin edilmez.
            return ['brut' => null, 'mola' => null, 'net' => null];
        }
        if ($cikis <= $giris) {
            // Gece vardiyasi / coklu interval: guvenli degil.
            return ['brut' => null, 'mola' => null, 'net' => null];
        }

        $overlap = self::overlapMinutes($giris, $cikis, $intervalStart, $intervalEnd);
        if ($overlap === 0) {
            return ['brut' => 0, 'mola' => 0, 'net' => 0];
        }

        $fullyInside = $giris >= $intervalStart && $cikis <= $intervalEnd;
        $fullyOutsideHolidayBreakAmbiguous = !$fullyInside && $molaDk > 0;
        if ($fullyOutsideHolidayBreakAmbiguous) {
            // Genel mola hangi bolume dustu bilinmiyor → rastgele dagitim yok.
            return ['brut' => null, 'mola' => null, 'net' => null];
        }

        if ($fullyInside) {
            // Tum calisma + mola tatil intervalinde → net = satirdaki net (authoritative).
            if ($netDk === null) {
                $computedNet = max(0, $overlap - $molaDk);

                return ['brut' => $overlap, 'mola' => $molaDk, 'net' => $computedNet];
            }

            return ['brut' => $brutDk !== null ? $brutDk : $overlap, 'mola' => $molaDk, 'net' => $netDk];
        }

        // Kismi overlap + mola=0: overlap brut=net (mola dagitimi gerekmez).
        if ($molaDk === 0) {
            return ['brut' => $overlap, 'mola' => 0, 'net' => $overlap];
        }

        return ['brut' => null, 'mola' => null, 'net' => null];
    }

    /** @param mixed $value */
    private static function timeToMinutes($value)
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m) !== 1) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) {
            return null;
        }

        return $h * 60 + $i;
    }

    private static function overlapMinutes($aStart, $aEnd, $bStart, $bEnd)
    {
        $start = max((int) $aStart, (int) $bStart);
        $end = min((int) $aEnd, (int) $bEnd);

        return max(0, $end - $start);
    }

    /** @param array<string, mixed> $fields */
    public static function snapshotHash(array $fields)
    {
        $canonical = [
            'tatil_takvim_id' => $fields['tatil_takvim_id'] ?? null,
            'tatil_turu' => $fields['tatil_turu'] ?? null,
            'tatil_gun_kapsami' => $fields['tatil_gun_kapsami'] ?? null,
            'tatil_interval_baslangic' => $fields['tatil_interval_baslangic'] ?? null,
            'tatil_interval_bitis' => $fields['tatil_interval_bitis'] ?? null,
            'tatil_siniflandirma_durumu' => $fields['tatil_siniflandirma_durumu'] ?? null,
            'tatil_kaynak_referansi' => $fields['tatil_kaynak_referansi'] ?? null,
        ];

        return MaasHesaplamaEngine::hashCanonical($canonical);
    }

    /** @return array<string, mixed> */
    private static function emptyProjection()
    {
        return [
            'tatil_takvim_id' => null,
            'tatil_turu' => null,
            'tatil_gun_kapsami' => null,
            'tatil_interval_baslangic' => null,
            'tatil_interval_bitis' => null,
            'tatil_siniflandirma_durumu' => null,
            'tatil_snapshot_hash' => null,
            'tatil_kaynak_referansi' => null,
            'tatil_donemi_brut_calisma_dakika' => null,
            'tatil_donemi_ara_dinlenme_dakika' => null,
            'tatil_donemi_net_calisma_dakika' => null,
        ];
    }
}
