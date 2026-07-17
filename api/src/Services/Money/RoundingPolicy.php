<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Money;

/**
 * Merkezi yuvarlama politikasi. Tum bordro yuvarlamalari buradan gecer.
 */
final class RoundingPolicy
{
    public const HALF_UP = 'HALF_UP';

    /**
     * Para sonucu her zaman 2 ondalik (kurus). Money zaten kurus tutar.
     */
    public static function moneyToDecimal(Money $money)
    {
        return $money->toDecimalString();
    }

    /**
     * Dilim parcasi veya ara carpim: half-up ile kurusa indir.
     */
    public static function mulDivToMoney(Money $base, $numerator, $denominator)
    {
        return $base->mulDiv($numerator, $denominator);
    }

    public static function applyRateToMoney(Money $base, Rate $rate)
    {
        return $base->applyRate($rate);
    }

    /**
     * Negatif sifiri normalize et.
     */
    public static function normalizeZero(Money $money)
    {
        return $money->isZero() ? Money::zero() : $money;
    }
}
