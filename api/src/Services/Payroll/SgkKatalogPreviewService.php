<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Partial-time and reporting-period preview skeletons (no calculation).
 */
final class SgkKatalogPreviewService
{
    /**
     * @param array<string,mixed> $input
     */
    public static function kismiSureliPreview(array $input): array
    {
        $blockers = [
            SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_KISMI_KURAL,
                'Kismi sureli prim gunu hesap kurali resmi olarak kanitlanmadi.',
                'Resmi formul/kanit tamamlanmadan hesap uretilmez; saat/7,5 kullanilmaz.'
            ),
        ];
        if (empty($input['yazili_kismi_sureli_sozlesme_var_mi'])) {
            $blockers[] = SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_KISMI_BELGE,
                'Yazili kismi sureli sozlesme belgesi eksik.',
                'Sozlesme belgesini dogrulanmis belge ownerina baglayin.'
            );
        }

        $girdiler = [
            'sozlesme_turu' => $input['sozlesme_turu'] ?? null,
            'sozlesme_baslangic' => $input['sozlesme_baslangic'] ?? null,
            'sozlesme_bitis' => $input['sozlesme_bitis'] ?? null,
            'aylik_calisma_saati' => $input['aylik_calisma_saati'] ?? null,
            'yazili_kismi_sureli_sozlesme_var_mi' => !empty($input['yazili_kismi_sureli_sozlesme_var_mi']),
            'bildirim_donemi' => $input['bildirim_donemi'] ?? null,
        ];

        $out = [
            'preview_modu' => 'BLOCKER_ONLY',
            'hesap_sonucu_uretildi_mi' => false,
            'saat_bol_7_5_kullanildi_mi' => false,
            'girdiler' => $girdiler,
            'gerekli_kanitlar' => [
                'Resmi kismi sureli prim gunu hesap kurali',
                'Yazili kismi sureli is sozlesmesi',
                'Onayli SGK katalog/kod surumu',
            ],
            'blocker_kodlari' => array_values(array_map(static fn (array $b) => $b['code'], $blockers)),
            'blocker_detaylari' => $blockers,
        ];
        $out['response_hash'] = SgkKatalogContracts::sha256Canonical($out);

        return $out;
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function bildirimDonemiPreview(array $input): array
    {
        $tip = strtoupper((string) ($input['bildirim_donem_tipi'] ?? ''));
        $blockers = [
            SgkKatalogContracts::blocker(
                SgkKatalogContracts::BLOCKER_BILDIRIM,
                'Bildirim donemi sirket politikasi resmi/yetkili karar olmadan aktif edilemez.',
                'AY_1_SON_GUN veya AY_15_SONRAKI_AY_14 icin onayli politika surumu olmadan varsayilan gecis yapilmaz.'
            ),
        ];

        $out = [
            'preview_modu' => 'BLOCKER_ONLY',
            'bildirim_donem_tipi' => $tip !== '' ? $tip : null,
            'aktif_edildi_mi' => false,
            'varsayilan_15_14_uygulandi_mi' => false,
            'donem_baslangic' => null,
            'donem_bitis' => null,
            'gerekli_kanitlar' => [
                'Onayli sirket SGK bildirim donemi politikasi',
                'Resmi/yetkili dayanak',
            ],
            'blocker_kodlari' => array_values(array_map(static fn (array $b) => $b['code'], $blockers)),
            'blocker_detaylari' => $blockers,
        ];
        $out['response_hash'] = SgkKatalogContracts::sha256Canonical($out);

        return $out;
    }
}
