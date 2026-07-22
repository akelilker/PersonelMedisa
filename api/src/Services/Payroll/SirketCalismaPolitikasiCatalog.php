<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S82 sirket calisma politikasi katalogu.
 * Mevzuat parametrelerinden ayri; yalniz sirket karari ile belirlenen kodlar.
 */
final class SirketCalismaPolitikasiCatalog
{
    /** @var array<string, array{etiket: string, aciklama: string, deger_tipi: string, birim: string}> */
    private static $codes = [
        'NORMAL_AY_GUN_SAYISI' => [
            'etiket' => 'Normal Ay Gün Sayısı',
            'aciklama' => 'Aylık ücretin günlük ücrete bölünmesinde kullanılan gün sayısı.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'GUN',
        ],
        'GUNLUK_CALISMA_SAATI' => [
            'etiket' => 'Günlük Çalışma Saati',
            'aciklama' => 'Ara dinlenmeler hariç günlük net çalışma süresi.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'SAAT',
        ],
        'AYLIK_NORMAL_CALISMA_SAATI' => [
            'etiket' => 'Aylık Normal Çalışma Saati',
            'aciklama' => 'Saatlik ücret hesabında kullanılan aylık bölen.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'SAAT',
        ],
        'HAFTALIK_IS_GUNU_SAYISI' => [
            'etiket' => 'Haftalık İş Günü Sayısı',
            'aciklama' => 'Çalışanın normal haftalık iş günü sayısı.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'GUN',
        ],
        'HAFTA_TATILI_HESAP_MODU' => [
            'etiket' => 'Hafta Tatili Hesap Modu',
            'aciklama' => 'Hafta tatilinde çalışma ödeme yöntemi.',
            'deger_tipi' => 'METIN',
            'birim' => 'MOD',
        ],
        'HAFTA_TATILI_CARPANI' => [
            'etiket' => 'Hafta Tatili Çarpanı',
            'aciklama' => 'Hafta tatili çalışma katsayısı.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'CARPAN',
        ],
        'FAZLA_MESAI_CARPANI' => [
            'etiket' => 'Fazla Mesai Çarpanı',
            'aciklama' => 'Fazla mesai saat katsayısı.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'CARPAN',
        ],
        'FAZLA_SURELERLE_CALISMA_CARPANI' => [
            'etiket' => 'Fazla Sürelerle Çalışma Çarpanı',
            'aciklama' => 'Fazla sürelerle çalışma katsayısı.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'CARPAN',
        ],
        'UBGT_CARPANI' => [
            'etiket' => 'UBGT Çarpanı',
            'aciklama' => 'Ulusal bayram ve genel tatil çarpanı.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'CARPAN',
        ],
        'UBGT_HESAP_MODU' => [
            'etiket' => 'UBGT Hesap Modu',
            'aciklama' => 'UBGT çalışma ödeme yöntemi.',
            'deger_tipi' => 'METIN',
            'birim' => 'MOD',
        ],
        'TATIL_FSC_FM_CAKISMA_HESAP_MODU' => [
            'etiket' => 'Tatil ve Fazla Çalışma Çakışma Hesap Modu',
            'aciklama' => 'HT/UBGT çalışması ile FSC/FM çakıştığında uygulanacak, yetkili hukuk ve şirket onayı gerektiren hesap yöntemi. Desteklenen: YARGITAY_7_5_SAAT_AYRIMI.',
            'deger_tipi' => 'METIN',
            'birim' => 'MOD',
        ],
    ];

    /** Production hesabında kabul edilen tek çakışma modu. */
    public const TATIL_FSC_FM_APPROVED_MODE = 'YARGITAY_7_5_SAAT_AYRIMI';

    /** @return array<int, string> */
    public static function holidayOvertimeAllowedModes()
    {
        return [self::TATIL_FSC_FM_APPROVED_MODE];
    }

    public static function isHolidayOvertimeModeAllowed($mode)
    {
        $normalized = strtoupper(trim((string) $mode));

        return in_array($normalized, self::holidayOvertimeAllowedModes(), true);
    }

    /** @return array<string, array{etiket: string, aciklama: string, deger_tipi: string, birim: string}> */
    public static function all()
    {
        return self::$codes;
    }

    /** @return array<int, string> */
    public static function requiredCodes()
    {
        $out = array_keys(self::$codes);
        sort($out);

        return $out;
    }

    public static function isKnown($code)
    {
        return isset(self::$codes[(string) $code]);
    }

    /** @return array{etiket: string, aciklama: string, deger_tipi: string, birim: string}|null */
    public static function meta($code)
    {
        $code = (string) $code;

        return isset(self::$codes[$code]) ? self::$codes[$code] : null;
    }

    /** Mevzuat katalogundan ayristirilan sirket politika kodlari. */
    public static function isCompanyPolicyCode($code)
    {
        return self::isKnown($code);
    }
}
