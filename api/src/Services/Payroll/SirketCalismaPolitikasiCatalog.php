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
        // SIRKET_KARARI (2026-08-01): normal hastalik ilk 2 gun isveren odemez.
        'NORMAL_HASTALIK_ILK_IKI_GUN_ISVEREN_ODEMESI' => [
            'etiket' => 'Normal Hastalık İlk İki Gün İşveren Ödemesi',
            'aciklama' => 'SIRKET_KARARI: Normal hastalik raporunda ilk 2 gun isveren odemez (HAYIR). Is kazasi, meslek hastaligi ve analiga uygulanmaz. Muhurlu eski donemler yeniden hesaplanmaz.',
            'deger_tipi' => 'METIN',
            'birim' => 'KARAR',
        ],
        // SIRKET_KARARI: tum personel icin haftalik normal sure 2700 dk (45 saat).
        'HAFTALIK_NORMAL_CALISMA_DAKIKA' => [
            'etiket' => 'Haftalık Normal Çalışma Dakikası',
            'aciklama' => 'SIRKET_KARARI: Atomik haftalik normal calisma dakikasi. Production varsayilan 2700 (45 saat). Sistem gunluk×is_gunu ile turetemez; explicit deger zorunlu.',
            'deger_tipi' => 'SAYISAL',
            'birim' => 'DAKIKA',
        ],
        // Dinlenme / hafta tatili gunleri (JS/PHP date('w'): 0=Pazar ... 6=Cumartesi).
        // Legacy yoksa runtime '0' (Pazar) uygulanir; production davranisi degismez.
        'HAFTA_TATILI_GUNLERI' => [
            'etiket' => 'Hafta Tatili / Dinlenme Günleri',
            'aciklama' => 'Virgulle ayrilmis weekday kodlari (0=Pazar … 6=Cumartesi). Ornek production: 0. Gelecek Cumartesi+Pazar: 6,0.',
            'deger_tipi' => 'METIN',
            'birim' => 'GUN_KODU',
        ],
    ];

    /** Production hesabında kabul edilen tek çakışma modu. */
    public const TATIL_FSC_FM_APPROVED_MODE = 'YARGITAY_7_5_SAAT_AYRIMI';

    /** Legacy default when HAFTA_TATILI_GUNLERI absent (Pazar). */
    public const LEGACY_HAFTA_TATILI_GUNLERI = '0';

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

    /**
     * Eksik legacy satirlarda runtime default uygulanabilen kodlar.
     * Yeni draft'larda yine catalog'da yer alir; assertComplete bunlari default ile tamamlar.
     *
     * @return array<string, string>
     */
    public static function legacyDefaultValues()
    {
        return [
            'HAFTA_TATILI_GUNLERI' => self::LEGACY_HAFTA_TATILI_GUNLERI,
        ];
    }

    /**
     * @param string $raw
     * @return array{ok:bool, days?:array<int,int>, error?:string}
     */
    public static function parseHaftaTatiliGunleri($raw)
    {
        $normalized = strtoupper(trim((string) $raw));
        if ($normalized === '') {
            return ['ok' => false, 'error' => 'HAFTA_TATILI_GUNLERI bos olamaz.'];
        }
        // Legacy token
        if ($normalized === 'PAZAR' || $normalized === 'HAFTA_TATILI_PAZAR' || $normalized === 'SUNDAY') {
            return ['ok' => true, 'days' => [0]];
        }
        $parts = preg_split('/[,\s;|]+/', $normalized);
        if (!is_array($parts) || count($parts) === 0) {
            return ['ok' => false, 'error' => 'HAFTA_TATILI_GUNLERI gecersiz.'];
        }
        $days = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if ($part === 'PAZAR' || $part === 'SUNDAY') {
                $day = 0;
            } elseif ($part === 'CUMARTESI' || $part === 'SATURDAY') {
                $day = 6;
            } elseif (preg_match('/^[0-6]$/', $part) === 1) {
                $day = (int) $part;
            } else {
                return ['ok' => false, 'error' => 'HAFTA_TATILI_GUNLERI gecersiz weekday: ' . $part];
            }
            if (in_array($day, $days, true)) {
                return ['ok' => false, 'error' => 'HAFTA_TATILI_GUNLERI duplicate weekday.'];
            }
            $days[] = $day;
        }
        if (count($days) === 0) {
            return ['ok' => false, 'error' => 'HAFTA_TATILI_GUNLERI en az bir dinlenme gunu icermelidir.'];
        }
        sort($days);

        return ['ok' => true, 'days' => $days];
    }

    /**
     * Atomik workweek tutarlilik: gunluk×is_gunu == haftalik_normal; is+dinlenme=7.
     *
     * @param array<int,int> $restDays
     * @return array{ok:bool, error?:string}
     */
    public static function assertWorkweekAtomicConsistency($gunlukDk, $haftalikIsGunu, $haftalikNormalDk, array $restDays)
    {
        $gunlukDk = (int) $gunlukDk;
        $haftalikIsGunu = (int) $haftalikIsGunu;
        $haftalikNormalDk = (int) $haftalikNormalDk;
        if ($gunlukDk < 1 || $haftalikIsGunu < 1 || $haftalikNormalDk < 1) {
            return ['ok' => false, 'error' => 'Workweek sayisal degerleri pozitif olmali.'];
        }
        if (count($restDays) < 1) {
            return ['ok' => false, 'error' => 'En az bir dinlenme gunu zorunlu.'];
        }
        if ($haftalikIsGunu + count($restDays) !== 7) {
            return ['ok' => false, 'error' => 'HAFTALIK_IS_GUNU_SAYISI + dinlenme gunu sayisi 7 olmali.'];
        }
        if ($gunlukDk * $haftalikIsGunu !== $haftalikNormalDk) {
            return ['ok' => false, 'error' => 'HAFTALIK_NORMAL_CALISMA_DAKIKA gunluk×is_gunu ile tutarsiz.'];
        }

        return ['ok' => true];
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
