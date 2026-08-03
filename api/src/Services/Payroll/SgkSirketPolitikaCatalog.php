<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S98: Company SGK policy codes consumed by SgkPrimGunuService / SgkPrimGunuEngine.
 *
 * bildirim_donem_tipi is NOT a degerler code — it lives on sgk_sirket_politika_surumleri
 * as ENUM('AY_1_SON_GUN','AY_15_SONRAKI_AY_14').
 */
final class SgkSirketPolitikaCatalog
{
    public const BILDIRIM_DONEM_TIPLERI = ['AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14'];

    /** @var array<string, array<string, mixed>> */
    public const CODES = [
        'SGK_ODENEK_MAHSUP_MODU' => [
            'deger_turu' => 'METIN',
            'zorunlu' => true,
            'allowed_values' => ['UCRET_MODELINE_GORE'],
            'aciklama' => 'Gunluk/saatlik: raporlu gun ucreti odenmez. Maktu aylik: aylik tam odenir, SGK odenegi mahsup edilir. Varsayilan yok.',
            'engine_kullanimi' => 'SgkPrimGunuEngine politika hash + allowed value dogrular; icerik UCRET_MODELINE_GORE olmalidir.',
        ],
    ];

    /** @return list<string> */
    public static function knownCodes(): array
    {
        $codes = array_keys(self::CODES);
        sort($codes);

        return $codes;
    }

    /** @return array<string, mixed>|null */
    public static function definition(string $code): ?array
    {
        return self::CODES[$code] ?? null;
    }

    public static function isKnownCode(string $code): bool
    {
        return isset(self::CODES[$code]);
    }
}
