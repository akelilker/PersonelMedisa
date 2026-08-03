<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S98-R1: Normalize mapping decision rules into kosullar_json + prim effect.
 * Free-form JSON is never accepted from import; only enum-controlled fields.
 */
final class SgkEslemeKararContract
{
    public const CONTRACT_VERSION = 'S98R1_ESLEME_KARAR_V1';

    /**
     * @param array{
     *   karar_kurali: string,
     *   kod_secim_modu: string,
     *   eksik_gun_kodu?: string|null,
     *   cozulmus_prim_gunu_etkisi?: string|null
     * } $input
     * @return array{
     *   prim_gunu_etkisi: string,
     *   eksik_gun_kodu: string|null,
     *   kosullar_json: array<string,mixed>,
     *   errors: list<string>
     * }
     */
    public static function normalize(array $input): array
    {
        $errors = [];
        $kural = strtoupper(trim((string) ($input['karar_kurali'] ?? '')));
        $kodModu = strtoupper(trim((string) ($input['kod_secim_modu'] ?? '')));
        $kod = strtoupper(trim((string) ($input['eksik_gun_kodu'] ?? '')));
        $kod = $kod === '' ? null : $kod;

        if ($kural === '' || !in_array($kural, SgkKatalogContracts::KARAR_KURALLARI, true)) {
            $errors[] = 'GECERSIZ_KARAR_KURALI';
        }
        if ($kodModu === '' || !in_array($kodModu, SgkKatalogContracts::KOD_SECIM_MODLARI, true)) {
            $errors[] = 'GECERSIZ_KOD_SECIM_MODU';
        }

        $prim = 'MANUEL';
        if ($kural === 'HER_ZAMAN_DAHIL') {
            $prim = 'DAHIL';
            if ($kodModu !== 'KOD_YOK') {
                $errors[] = 'DAHIL_ICIN_KOD_YOK_ZORUNLU';
            }
            if ($kod !== null) {
                $errors[] = 'DAHIL_ILE_KOD_CELISKISI';
            }
            $kod = null;
        } elseif ($kural === 'HER_ZAMAN_DUSUR') {
            $prim = 'DUSUR';
            if ($kodModu === 'KOD_YOK') {
                $errors[] = 'DUSUR_ICIN_KOD_ZORUNLU';
            }
            if ($kodModu === 'SABIT_KOD' && $kod === null) {
                $errors[] = 'DUSUR_ICIN_KOD_ZORUNLU';
            }
            if ($kodModu === 'OLAYDAN_TURET') {
                $kod = null;
            }
        } elseif (in_array($kural, ['UCRET_MODELINE_GORE', 'UCRET_KESINTISI_SECIMINE_GORE', 'OLAY_NEDENINE_GORE', 'YAZILI_KISMI_SOZLESME_ZORUNLU'], true)) {
            $prim = 'KOSULLU';
            if ($kural === 'OLAY_NEDENINE_GORE' && $kodModu !== 'OLAYDAN_TURET' && $kodModu !== 'YETKILI_MANUEL') {
                $errors[] = 'OLAY_NEDENI_ICIN_OLAYDAN_TURET_VEYA_MANUEL';
            }
            if ($kural === 'YAZILI_KISMI_SOZLESME_ZORUNLU' && $kodModu === 'SABIT_KOD' && $kod === null) {
                $errors[] = 'DUSUR_ICIN_KOD_ZORUNLU';
            }
            if ($kural === 'UCRET_MODELINE_GORE' || $kural === 'UCRET_KESINTISI_SECIMINE_GORE') {
                if ($kodModu === 'SABIT_KOD') {
                    $errors[] = 'KOSULLU_KURAL_SABIT_KOD_YASAK';
                }
                // Conditional codes resolved at runtime; template may leave empty.
                $kod = null;
            }
            if ($kural === 'OLAY_NEDENINE_GORE' && $kodModu === 'OLAYDAN_TURET') {
                $kod = null;
            }
        }

        $kosullar = [
            'contract_version' => self::CONTRACT_VERSION,
            'karar_kurali' => $kural,
            'kod_secim_modu' => $kodModu,
        ];
        if ($kural === 'OLAY_NEDENINE_GORE') {
            $kosullar['olay_neden_kod_haritasi'] = SgkKatalogContracts::OLAY_NEDEN_KOD_HARITASI;
        }
        if ($kural === 'YAZILI_KISMI_SOZLESME_ZORUNLU') {
            $kosullar['prim_gunu_hesap'] = 'SAAT_BOL_7_5_YUKARI';
            $kosullar['yazili_sozlesme_zorunlu'] = true;
        }

        return [
            'prim_gunu_etkisi' => $prim,
            'eksik_gun_kodu' => $kod,
            'kosullar_json' => $kosullar,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * Resolve runtime effect + code from mapping + personel/process context.
     *
     * @param array<string,mixed> $process enriched process row
     * @param array<string,mixed> $personel
     * @return array{effect: string, code: string|null, blockers: list<array<string,mixed>>}
     */
    public static function resolveRuntime(array $process, array $personel): array
    {
        $blockers = [];
        $conditions = is_array($process['kosullar_json'] ?? null)
            ? $process['kosullar_json']
            : (is_string($process['kosullar_json'] ?? null)
                ? (json_decode((string) $process['kosullar_json'], true) ?: [])
                : []);
        if (!is_array($conditions)) {
            $conditions = [];
        }

        $kural = strtoupper(trim((string) ($conditions['karar_kurali'] ?? '')));
        $kodModu = strtoupper(trim((string) ($conditions['kod_secim_modu'] ?? '')));
        $canonical = strtoupper(trim((string) ($process['canonical_surec_turu'] ?? '')));
        $mappedCode = isset($process['eksik_gun_kodu']) && $process['eksik_gun_kodu'] !== null && $process['eksik_gun_kodu'] !== ''
            ? strtoupper(trim((string) $process['eksik_gun_kodu']))
            : null;

        // Legacy rows without karar_kurali: fall back to stored prim_gunu_etkisi.
        if ($kural === '') {
            $effect = strtoupper((string) ($process['prim_gunu_etkisi'] ?? ''));
            if ($effect === 'KOSULLU') {
                $effect = strtoupper((string) ($process['cozulmus_prim_gunu_etkisi'] ?? ''));
            }

            return ['effect' => $effect !== '' ? $effect : 'MANUEL', 'code' => $mappedCode, 'blockers' => $blockers];
        }

        if ($kural === 'HER_ZAMAN_DAHIL' || $canonical === 'KISMI_SURE_DEVAMSIZLIK') {
            return ['effect' => 'DAHIL', 'code' => null, 'blockers' => $blockers];
        }
        if ($kural === 'HER_ZAMAN_DUSUR') {
            if ($mappedCode === null && $kodModu !== 'OLAYDAN_TURET') {
                $blockers[] = ['code' => 'SGK_EKSIK_GUN_KODU_BULUNAMADI', 'message' => 'DUSUR karari icin sabit kod yok.'];
            }

            return ['effect' => 'DUSUR', 'code' => $mappedCode, 'blockers' => $blockers];
        }

        if ($kural === 'UCRET_MODELINE_GORE') {
            $model = strtoupper(trim((string) ($personel['ucret_modeli'] ?? '')));
            if ($model === 'GUNLUK' || $model === 'SAATLIK') {
                return ['effect' => 'DUSUR', 'code' => '01', 'blockers' => $blockers];
            }
            if ($model === 'MAKTU_AYLIK') {
                return ['effect' => 'DAHIL', 'code' => null, 'blockers' => $blockers];
            }
            $blockers[] = ['code' => 'UCRET_MODELI_BELIRSIZ', 'message' => 'Ucret modeline gore karar cozulemedi.'];

            return ['effect' => 'MANUEL', 'code' => null, 'blockers' => $blockers];
        }

        if ($kural === 'UCRET_KESINTISI_SECIMINE_GORE') {
            if (!array_key_exists('ucretli_mi', $process)) {
                $blockers[] = ['code' => 'MAZERET_UCRET_KARARI_EKSIK', 'message' => 'Mazeret ucret kesilsin mi / ucretli_mi karari yok.'];

                return ['effect' => 'MANUEL', 'code' => null, 'blockers' => $blockers];
            }
            $ucretli = (bool) $process['ucretli_mi'];
            if ($ucretli) {
                return ['effect' => 'DAHIL', 'code' => null, 'blockers' => $blockers];
            }

            // Tam gun unpaid mazeret → code 21. Partial-hour SGK day reduction denied.
            $tamGun = !empty($process['tam_gun_mu']);
            if ($tamGun === false && array_key_exists('tam_gun_mu', $process)) {
                return ['effect' => 'DAHIL', 'code' => null, 'blockers' => $blockers];
            }

            return ['effect' => 'DUSUR', 'code' => '21', 'blockers' => $blockers];
        }

        if ($kural === 'OLAY_NEDENINE_GORE') {
            $neden = strtoupper(trim((string) ($process['sgk_eksik_gun_neden_tipi'] ?? $process['olay_neden_tipi'] ?? '')));
            $map = is_array($conditions['olay_neden_kod_haritasi'] ?? null)
                ? $conditions['olay_neden_kod_haritasi']
                : SgkKatalogContracts::OLAY_NEDEN_KOD_HARITASI;
            if ($kodModu === 'YETKILI_MANUEL') {
                if ($mappedCode === null) {
                    $blockers[] = ['code' => 'SGK_EKSIK_GUN_KODU_BULUNAMADI', 'message' => 'Manuel override kodu yok.'];

                    return ['effect' => 'MANUEL', 'code' => null, 'blockers' => $blockers];
                }
                if (empty($process['manuel_override_audit_ok_mi'])) {
                    $blockers[] = ['code' => 'SGK_MANUEL_OVERRIDE_AUDIT_EKSIK', 'message' => 'Manuel kod override audit/gerekce/belge eksik.'];

                    return ['effect' => 'MANUEL', 'code' => null, 'blockers' => $blockers];
                }

                return ['effect' => 'DUSUR', 'code' => $mappedCode, 'blockers' => $blockers];
            }
            if ($neden === '' || $neden === 'BILINMIYOR' || !isset($map[$neden])) {
                $blockers[] = ['code' => 'SGK_OLAY_NEDENI_BELIRSIZ', 'message' => 'Puantaj eksik gun neden tipi cozulemedi.'];

                return ['effect' => 'MANUEL', 'code' => null, 'blockers' => $blockers];
            }

            return ['effect' => 'DUSUR', 'code' => (string) $map[$neden], 'blockers' => $blockers];
        }

        if ($kural === 'YAZILI_KISMI_SOZLESME_ZORUNLU') {
            if ((string) ($personel['sozlesme_turu'] ?? '') !== 'KISMI_SURELI') {
                $blockers[] = ['code' => 'SGK_KAYNAK_SUREC_CELISKILI', 'message' => 'Kismi sureli hesap icin sozlesme turu KISMI_SURELI degil.'];

                return ['effect' => 'MANUEL', 'code' => null, 'blockers' => $blockers];
            }
            if (empty($process['sozlesme_belgesi_dogrulandi_mi']) && empty($personel['yazili_kismi_sureli_sozlesme_var_mi'])) {
                $blockers[] = ['code' => 'SGK_EKSIK_GUN_BELGESI_EKSIK', 'message' => 'Yazili kismi sureli sozlesme belgesi yok.'];

                return ['effect' => 'MANUEL', 'code' => null, 'blockers' => $blockers];
            }

            return ['effect' => 'DUSUR', 'code' => $mappedCode ?? '06', 'blockers' => $blockers];
        }

        $blockers[] = ['code' => 'SGK_PRIM_GUNU_HESAPLANAMADI', 'message' => 'Karar kurali cozulemedi.'];

        return ['effect' => 'MANUEL', 'code' => null, 'blockers' => $blockers];
    }

    /** ceil(hours / 7.5), capped 0..30. */
    public static function roundPartialPrimDays(float $monthlyHours): int
    {
        if ($monthlyHours <= 0) {
            return 0;
        }
        $days = (int) ceil($monthlyHours / 7.5);

        return max(0, min(30, $days));
    }
}
