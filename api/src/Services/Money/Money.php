<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Money;

/**
 * Integer-kurus money helper. PHP float yasaktir.
 * 1 TRY = 100 kurus.
 */
final class Money
{
    /** @var int */
    private $kurus;

    private function __construct($kurus)
    {
        $this->kurus = (int) $kurus;
    }

    public static function zero()
    {
        return new self(0);
    }

    public static function fromKurus($kurus)
    {
        if (!is_int($kurus) && !(is_string($kurus) && preg_match('/^-?\d+$/', $kurus))) {
            throw new \InvalidArgumentException('Money::fromKurus integer bekler.');
        }

        return new self((int) $kurus);
    }

    /**
     * Decimal string (ornegin "1234.56" veya "1234,56") -> kurus.
     * Float cast kullanilmaz.
     */
    public static function fromDecimalString($value)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            throw new \InvalidArgumentException('Bos decimal string.');
        }
        $raw = str_replace(',', '.', $raw);
        if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            throw new \InvalidArgumentException('Gecersiz decimal string: ' . $raw);
        }
        $negative = $raw[0] === '-';
        if ($negative) {
            $raw = substr($raw, 1);
        }
        $parts = explode('.', $raw, 2);
        $whole = $parts[0];
        $frac = isset($parts[1]) ? $parts[1] : '';
        if (strlen($frac) > 2) {
            // Half-up to 2 decimals using integer digits only
            $frac = substr($frac . '00', 0, 3);
            $firstTwo = (int) substr($frac, 0, 2);
            $third = (int) substr($frac, 2, 1);
            if ($third >= 5) {
                $firstTwo++;
            }
            if ($firstTwo >= 100) {
                $whole = (string) ((int) $whole + 1);
                $firstTwo -= 100;
            }
            $frac = str_pad((string) $firstTwo, 2, '0', STR_PAD_LEFT);
        } else {
            $frac = str_pad($frac, 2, '0', STR_PAD_RIGHT);
        }
        $kurus = ((int) $whole) * 100 + (int) $frac;
        if ($negative) {
            $kurus = -$kurus;
        }

        return new self($kurus);
    }

    public function kurus()
    {
        return $this->kurus;
    }

    public function toDecimalString()
    {
        $sign = $this->kurus < 0 ? '-' : '';
        $abs = abs($this->kurus);
        $whole = intdiv($abs, 100);
        $frac = $abs % 100;

        return $sign . $whole . '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
    }

    public function add(Money $other)
    {
        return new self($this->kurus + $other->kurus);
    }

    public function sub(Money $other)
    {
        return new self($this->kurus - $other->kurus);
    }

    public function neg()
    {
        return new self(-$this->kurus);
    }

    public function abs()
    {
        return new self(abs($this->kurus));
    }

    public function isNegative()
    {
        return $this->kurus < 0;
    }

    public function isZero()
    {
        return $this->kurus === 0;
    }

    public function cmp(Money $other)
    {
        if ($this->kurus === $other->kurus) {
            return 0;
        }

        return $this->kurus < $other->kurus ? -1 : 1;
    }

    public function min(Money $other)
    {
        return $this->cmp($other) <= 0 ? $this : $other;
    }

    public function max(Money $other)
    {
        return $this->cmp($other) >= 0 ? $this : $other;
    }

    /**
     * Multiply by integer quantity then divide by integer divisor with half-up rounding.
     * Ornek: daily = monthly * days / NORMAL_AY_GUN
     */
    public function mulDiv($numerator, $denominator)
    {
        $num = (int) $numerator;
        $den = (int) $denominator;
        if ($den === 0) {
            throw new \InvalidArgumentException('Sifira bolme.');
        }
        $product = (int) $this->kurus * $num;
        $sign = ($product < 0) !== ($den < 0) ? -1 : 1;
        $absProduct = abs($product);
        $absDen = abs($den);
        $q = intdiv($absProduct, $absDen);
        $r = $absProduct % $absDen;
        // half-up
        if ($r * 2 >= $absDen) {
            $q++;
        }

        return new self($sign * $q);
    }

    /**
     * Apply Rate (ppm) to this money: result = kurus * ppm / 1_000_000 half-up.
     */
    public function applyRate(Rate $rate)
    {
        return $this->mulDiv($rate->ppm(), Rate::SCALE);
    }
}
