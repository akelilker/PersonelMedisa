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
            // DB yalnizca tek mola dakikasi tutar; coklu interval olmadan donem dakikasi hesaplanmaz.
            $out['tatil_donemi_brut_calisma_dakika'] = null;
            $out['tatil_donemi_ara_dinlenme_dakika'] = null;
            $out['tatil_donemi_net_calisma_dakika'] = null;
            $out['tatil_snapshot_hash'] = self::snapshotHash($out);

            return $out;
        }

        $out['tatil_siniflandirma_durumu'] = 'BILINMIYOR';
        $out['tatil_snapshot_hash'] = self::snapshotHash($out);

        return $out;
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
