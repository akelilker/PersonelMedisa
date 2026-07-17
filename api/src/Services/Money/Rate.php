<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Money;

/**
 * Oran: parts-per-million (ppm).
 * %15 = 150000 ppm; carpan 1.5 = 1500000 ppm.
 * SCALE = 1_000_000.
 */
final class Rate
{
    public const SCALE = 1000000;

    /** @var int */
    private $ppm;

    private function __construct($ppm)
    {
        $this->ppm = (int) $ppm;
    }

    public static function fromPpm($ppm)
    {
        return new self((int) $ppm);
    }

    /**
     * Decimal oran string: "0.15" => %15, "1.5" => carpan 1.5.
     * Float kullanilmaz.
     */
    public static function fromDecimalString($value)
    {
        $raw = trim(str_replace(',', '.', (string) $value));
        if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            throw new \InvalidArgumentException('Gecersiz oran: ' . $raw);
        }
        $negative = $raw[0] === '-';
        if ($negative) {
            $raw = substr($raw, 1);
        }
        $parts = explode('.', $raw, 2);
        $whole = (int) $parts[0];
        $frac = isset($parts[1]) ? $parts[1] : '';
        // Need 6 decimal digits for ppm
        $frac = str_pad(substr($frac, 0, 7), 7, '0', STR_PAD_RIGHT);
        $six = (int) substr($frac, 0, 6);
        $seventh = (int) substr($frac, 6, 1);
        if ($seventh >= 5) {
            $six++;
        }
        if ($six >= self::SCALE) {
            $whole++;
            $six -= self::SCALE;
        }
        $ppm = $whole * self::SCALE + $six;
        if ($negative) {
            $ppm = -$ppm;
        }

        return new self($ppm);
    }

    /**
     * Yuzde string: "15" veya "15.5" => oran.
     */
    public static function fromPercentString($value)
    {
        $moneyLike = Money::fromDecimalString($value);
        // percent * 10000 = ppm  (15.00% -> 150000 ppm): kurus of percent * 10000? 
        // Better: percent decimal * 10000.
        // "15" -> 15 * 10000 = 150000 ppm
        // Use mulDiv from percent as "integer hundredths of percent"
        // percent_kurus style: 15.00 stored as 1500 (2dp) then * 100 = 150000
        return self::fromPpm($moneyLike->kurus() * 100);
    }

    public function ppm()
    {
        return $this->ppm;
    }

    public function toDecimalString()
    {
        $sign = $this->ppm < 0 ? '-' : '';
        $abs = abs($this->ppm);
        $whole = intdiv($abs, self::SCALE);
        $frac = $abs % self::SCALE;

        return $sign . $whole . '.' . str_pad((string) $frac, 6, '0', STR_PAD_LEFT);
    }
}
