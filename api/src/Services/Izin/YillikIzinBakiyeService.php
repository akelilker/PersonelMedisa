<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Izin;

use PDO;

/**
 * Effective annual leave balance assembly (S2C).
 *
 * Formula (as-of single referans_tarih):
 *   raw = cumulative_statutory + effective_manual − used_as_of
 *   remaining = max(raw, 0) when used resolved; null when calendar fail-closed.
 *
 * ANNUAL_BAND_SEMANTIC = CURRENT_SERVICE_YEAR_BAND (mevcut_yillik_hak_gun)
 * BALANCE_LEGAL_OWNER  = CUMULATIVE_STATUTORY_ACCRUAL_AS_OF_REFERENCE_DATE (birikmis_yasal_hak_gun)
 *
 * Compatibility: yasal_hak_gun === birikmis_yasal_hak_gun (cumulative; NOT current-year band).
 */
class YillikIzinBakiyeService
{
    public const ANNUAL_BAND_SEMANTIC = 'CURRENT_SERVICE_YEAR_BAND';
    public const BALANCE_LEGAL_SEMANTIC = 'CUMULATIVE_STATUTORY_ACCRUAL_AS_OF_REFERENCE_DATE';
    /** @deprecated use BALANCE_LEGAL_SEMANTIC; kept for readers that still check this key */
    public const LEGAL_ENTITLEMENT_SEMANTIC = self::BALANCE_LEGAL_SEMANTIC;
    public const CONTRACT_VERSION = 's2c-v1';
    public const REVERSAL_EFFECTIVE_SEMANTIC = 'RESTATEMENT_FROM_ORIGINAL_EFFECTIVE_DATE';

    /**
     * Resolve canonical reference date once at the service boundary.
     * Absent/empty → today. Explicit malformed → VALIDATION_ERROR 422 (no silent today).
     *
     * @param mixed $referansTarih
     * @return string YYYY-MM-DD
     */
    public static function resolveReferansTarih($referansTarih)
    {
        if ($referansTarih === null) {
            return date('Y-m-d');
        }
        $raw = trim((string) $referansTarih);
        if ($raw === '') {
            return date('Y-m-d');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new YillikIzinHakDuzeltmeException(
                'VALIDATION_ERROR',
                'referans_tarih YYYY-MM-DD olmali.',
                422,
                'referans_tarih'
            );
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($dt === false || $dt->format('Y-m-d') !== $raw) {
            throw new YillikIzinHakDuzeltmeException(
                'VALIDATION_ERROR',
                'referans_tarih gecersiz.',
                422,
                'referans_tarih'
            );
        }

        return $raw;
    }

    /**
     * @return array<string, mixed>
     */
    public static function assemble(PDO $pdo, $personelId, $referansTarih = null)
    {
        $personelId = (int) $personelId;
        $personel = self::fetchPersonel($pdo, $personelId);
        if (!$personel) {
            throw new YillikIzinHakDuzeltmeException('NOT_FOUND', 'Personel bulunamadi.', 404);
        }

        $ref = self::resolveReferansTarih($referansTarih);

        $legal = YillikIzinHakEdisService::hesaplaBirikmisYasalHak([
            'ise_giris_tarihi' => (string) ($personel['ise_giris_tarihi'] ?? ''),
            'dogum_tarihi' => isset($personel['dogum_tarihi']) && $personel['dogum_tarihi'] !== null
                ? (string) $personel['dogum_tarihi']
                : null,
            'referans_tarih' => $ref,
        ]);

        $manualNet = YillikIzinHakDuzeltmeLedgerService::netSumAsOf($pdo, $personelId, $ref);
        $duzeltmeAdet = YillikIzinHakDuzeltmeLedgerService::countByPersonelAsOf($pdo, $personelId, $ref);
        $usedOzeti = YillikIzinKullanimService::computeForPersonel($pdo, $personelId, $ref);

        return self::buildResponse($personelId, $ref, $legal, $manualNet, $usedOzeti, $duzeltmeAdet);
    }

    /**
     * Pure assembly helper for unit tests (no DB).
     *
     * @param array{
     *   kidem_yil:int,
     *   yas:int|null,
     *   mevcut_yillik_hak_gun?:int,
     *   birikmis_yasal_hak_gun?:int,
     *   yillik_izin_gun?:int,
     *   yas_istisna_uygulandi:bool
     * } $legal
     * @param array{
     *   kullanilan_gun:int|null,
     *   sayilan_normal_gun?:int,
     *   haric_tutulan_hafta_tatili_gun?:int,
     *   haric_tutulan_ubgt_gun?:int,
     *   takvim_dogrulandi_mi?:bool,
     *   eksik_takvim_tarihleri?:array<int,string>
     * } $usedOzeti
     * @return array<string, mixed>
     */
    public static function assembleFromParts(array $legal, $manualNet, array $usedOzeti, $duzeltmeAdet = 0, $referansTarih = null)
    {
        return self::buildResponse(0, $referansTarih, $legal, $manualNet, $usedOzeti, (int) $duzeltmeAdet);
    }

    /**
     * @param array<string, mixed> $legal
     * @param array{
     *   kullanilan_gun:int|null,
     *   sayilan_normal_gun?:int,
     *   haric_tutulan_hafta_tatili_gun?:int,
     *   haric_tutulan_ubgt_gun?:int,
     *   takvim_dogrulandi_mi?:bool,
     *   eksik_takvim_tarihleri?:array<int,string>
     * } $usedOzeti
     * @return array<string, mixed>
     */
    private static function buildResponse($personelId, $referansTarih, array $legal, $manualNet, array $usedOzeti, $duzeltmeAdet)
    {
        $used = array_key_exists('kullanilan_gun', $usedOzeti) ? $usedOzeti['kullanilan_gun'] : null;
        $mevcut = array_key_exists('mevcut_yillik_hak_gun', $legal)
            ? (int) $legal['mevcut_yillik_hak_gun']
            : (int) ($legal['yillik_izin_gun'] ?? 0);
        $birikmis = array_key_exists('birikmis_yasal_hak_gun', $legal)
            ? (int) $legal['birikmis_yasal_hak_gun']
            : (int) ($legal['yillik_izin_gun'] ?? 0);
        $manual = (int) $manualNet;
        $efektif = $birikmis + $manual;
        $rawRemaining = null;
        $remaining = null;
        if ($used !== null) {
            $rawRemaining = $efektif - (int) $used;
            $remaining = max($rawRemaining, 0);
        }

        $eksik = isset($usedOzeti['eksik_takvim_tarihleri']) && is_array($usedOzeti['eksik_takvim_tarihleri'])
            ? array_values($usedOzeti['eksik_takvim_tarihleri'])
            : [];

        return [
            'personel_id' => (int) $personelId,
            'contract_version' => self::CONTRACT_VERSION,
            'referans_tarih' => $referansTarih,
            'annual_band_semantic' => self::ANNUAL_BAND_SEMANTIC,
            'balance_legal_semantic' => self::BALANCE_LEGAL_SEMANTIC,
            'legal_entitlement_semantic' => self::BALANCE_LEGAL_SEMANTIC,
            'reversal_effective_semantic' => self::REVERSAL_EFFECTIVE_SEMANTIC,
            'kidem_yil' => (int) ($legal['kidem_yil'] ?? 0),
            'yas' => array_key_exists('yas', $legal) ? $legal['yas'] : null,
            'yas_istisna_uygulandi' => (bool) ($legal['yas_istisna_uygulandi'] ?? false),
            'mevcut_yillik_hak_gun' => $mevcut,
            'birikmis_yasal_hak_gun' => $birikmis,
            // Compatibility: cumulative statutory (S2C balance legal owner), NOT current-year band.
            'yasal_hak_gun' => $birikmis,
            'manuel_duzeltme_gun' => $manual,
            'efektif_hak_gun' => $efektif,
            'kullanilan_gun' => $used,
            'ham_kalan_gun' => $rawRemaining,
            'kalan_gun' => $remaining,
            'takvim_dogrulandi_mi' => (bool) ($usedOzeti['takvim_dogrulandi_mi'] ?? ($used !== null)),
            'eksik_takvim_tarihleri' => $eksik,
            'sayilan_normal_gun' => (int) ($usedOzeti['sayilan_normal_gun'] ?? 0),
            'haric_tutulan_hafta_tatili_gun' => (int) ($usedOzeti['haric_tutulan_hafta_tatili_gun'] ?? 0),
            'haric_tutulan_ubgt_gun' => (int) ($usedOzeti['haric_tutulan_ubgt_gun'] ?? 0),
            'duzeltme_adet' => (int) $duzeltmeAdet,
            // Internal / pure-test aliases
            'legal_entitlement' => $birikmis,
            'manual_net' => $manual,
            'used' => $used,
            'raw_remaining' => $rawRemaining,
            'remaining' => $remaining,
            'legal' => $legal,
            'used_ozeti' => $usedOzeti,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function fetchPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, ise_giris_tarihi, dogum_tarihi, sube_id
             FROM personeller WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
