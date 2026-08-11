<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use DateTimeImmutable;

/**
 * Server clock for retention maturity. Test override is PHPUnit/runner only — never HTTP.
 */
class RetentionClock
{
    /** @var DateTimeImmutable|null */
    private static $override = null;

    /**
     * @return DateTimeImmutable date-only (Y-m-d 00:00:00)
     */
    public static function now()
    {
        if (self::$override instanceof DateTimeImmutable) {
            return self::$override;
        }

        $raw = date('Y-m-d');
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $raw);

        return $dt ?: new DateTimeImmutable('today');
    }

    /**
     * Tests only — not for HTTP/controller context.
     *
     * @param DateTimeImmutable|null $asOf
     */
    public static function setOverride($asOf)
    {
        self::$override = $asOf instanceof DateTimeImmutable ? $asOf : null;
    }

    public static function clearOverride()
    {
        self::$override = null;
    }

    /**
     * Whether test clock override is currently active (runners may inspect).
     */
    public static function isTestOverrideAllowed()
    {
        return true;
    }
}
