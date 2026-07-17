<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S77-D2 zorunlu mevzuat parametre katalogu (Engine V2).
 * Degerler burada sabitlenmez; yalniz kod/tip/birim kontrati tanimlanir.
 */
final class MaasHesaplamaLegalParameterCatalog
{
    public const ENGINE_VERSION = 'S77D_PAYROLL_ENGINE_V2';

    /** @var array<string, array{deger_tipi: string, birim: string, zorunlu: bool}> */
    private static $codes = [
        'ASGARI_UCRET_BRUT' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'ASGARI_UCRET_GELIR_VERGISI_ISTISNA_MATRAHI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'ASGARI_UCRET_DAMGA_VERGISI_ISTISNA_MATRAHI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'SGK_ISCI_PRIM_ORANI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'ORAN', 'zorunlu' => true],
        'ISSIZLIK_ISCI_PRIM_ORANI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'ORAN', 'zorunlu' => true],
        'SGK_GUNLUK_TABAN' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'SGK_GUNLUK_TAVAN' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'DAMGA_VERGISI_ORANI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'ORAN', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_1_LIMIT' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_1_ORAN' => ['deger_tipi' => 'SAYISAL', 'birim' => 'ORAN', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_2_LIMIT' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_2_ORAN' => ['deger_tipi' => 'SAYISAL', 'birim' => 'ORAN', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_3_LIMIT' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_3_ORAN' => ['deger_tipi' => 'SAYISAL', 'birim' => 'ORAN', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_4_LIMIT' => ['deger_tipi' => 'SAYISAL', 'birim' => 'TRY', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_4_ORAN' => ['deger_tipi' => 'SAYISAL', 'birim' => 'ORAN', 'zorunlu' => true],
        'GELIR_VERGISI_DILIM_5_ORAN' => ['deger_tipi' => 'SAYISAL', 'birim' => 'ORAN', 'zorunlu' => true],
        'NORMAL_AY_GUN_SAYISI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'GUN', 'zorunlu' => true],
        'GUNLUK_CALISMA_SAATI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'SAAT', 'zorunlu' => true],
        'AYLIK_NORMAL_CALISMA_SAATI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'SAAT', 'zorunlu' => true],
        'HAFTALIK_IS_GUNU_SAYISI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'GUN', 'zorunlu' => true],
        'FAZLA_MESAI_CARPANI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'CARPAN', 'zorunlu' => true],
        'FAZLA_SURELERLE_CALISMA_CARPANI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'CARPAN', 'zorunlu' => true],
        'HAFTA_TATILI_CARPANI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'CARPAN', 'zorunlu' => true],
        'UBGT_CARPANI' => ['deger_tipi' => 'SAYISAL', 'birim' => 'CARPAN', 'zorunlu' => true],
        'HAFTA_TATILI_HESAP_MODU' => ['deger_tipi' => 'METIN', 'birim' => 'MOD', 'zorunlu' => true],
        'UBGT_HESAP_MODU' => ['deger_tipi' => 'METIN', 'birim' => 'MOD', 'zorunlu' => true],
    ];

    /** @return array<string, array{deger_tipi: string, birim: string, zorunlu: bool}> */
    public static function all()
    {
        return self::$codes;
    }

    /** @return array<int, string> */
    public static function requiredCodes()
    {
        $out = [];
        foreach (self::$codes as $code => $meta) {
            if ($meta['zorunlu']) {
                $out[] = $code;
            }
        }
        sort($out);

        return $out;
    }

    public static function isKnown($code)
    {
        return isset(self::$codes[(string) $code]);
    }

    public static function isRequired($code)
    {
        $code = (string) $code;

        return isset(self::$codes[$code]) && self::$codes[$code]['zorunlu'];
    }

    /** @return array{deger_tipi: string, birim: string, zorunlu: bool}|null */
    public static function meta($code)
    {
        $code = (string) $code;

        return isset(self::$codes[$code]) ? self::$codes[$code] : null;
    }
}
