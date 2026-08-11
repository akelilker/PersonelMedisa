<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Izin;

use PDO;

/**
 * Effective annual leave balance assembly (S2B).
 *
 * remaining = max(raw, 0) when used resolved; null when calendar fail-closed.
 * LEGAL_ENTITLEMENT_SEMANTIC = CURRENT_SERVICE_YEAR_BAND (not cumulative).
 */
class YillikIzinBakiyeService
{
    public const LEGAL_ENTITLEMENT_SEMANTIC = 'CURRENT_SERVICE_YEAR_BAND';
    public const CONTRACT_VERSION = 's2b-v1';

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

        $ref = $referansTarih !== null && trim((string) $referansTarih) !== ''
            ? trim((string) $referansTarih)
            : date('Y-m-d');

        $legal = YillikIzinHakEdisService::hesaplaIzinHakEdis([
            'ise_giris_tarihi' => (string) ($personel['ise_giris_tarihi'] ?? ''),
            'dogum_tarihi' => isset($personel['dogum_tarihi']) && $personel['dogum_tarihi'] !== null
                ? (string) $personel['dogum_tarihi']
                : null,
            'referans_tarih' => $ref,
        ]);

        $manualNet = YillikIzinHakDuzeltmeLedgerService::netSum($pdo, $personelId);
        $duzeltmeAdet = YillikIzinHakDuzeltmeLedgerService::countByPersonel($pdo, $personelId);
        $usedOzeti = YillikIzinKullanimService::computeForPersonel($pdo, $personelId);

        return self::buildResponse($personelId, $ref, $legal, $manualNet, $usedOzeti, $duzeltmeAdet);
    }

    /**
     * Pure assembly helper for unit tests (no DB).
     *
     * @param array{kidem_yil:int, yas:int|null, yillik_izin_gun:int, yas_istisna_uygulandi:bool} $legal
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
    public static function assembleFromParts(array $legal, $manualNet, array $usedOzeti, $duzeltmeAdet = 0)
    {
        return self::buildResponse(0, null, $legal, $manualNet, $usedOzeti, (int) $duzeltmeAdet);
    }

    /**
     * @param array{kidem_yil:int, yas:int|null, yillik_izin_gun:int, yas_istisna_uygulandi:bool} $legal
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
        $legalGun = (int) $legal['yillik_izin_gun'];
        $manual = (int) $manualNet;
        $efektif = $legalGun + $manual;
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
            'legal_entitlement_semantic' => self::LEGAL_ENTITLEMENT_SEMANTIC,
            'kidem_yil' => (int) ($legal['kidem_yil'] ?? 0),
            'yas' => array_key_exists('yas', $legal) ? $legal['yas'] : null,
            'yas_istisna_uygulandi' => (bool) ($legal['yas_istisna_uygulandi'] ?? false),
            'yasal_hak_gun' => $legalGun,
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
            // Internal / pure-test aliases (kept for parity fixtures)
            'legal_entitlement' => $legalGun,
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
