<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use RuntimeException;

/**
 * Fail-closed signal for the operations-only organisation mapping owners.
 *
 * Carries a machine reason code plus an optional bounded, non-PII detail. The
 * reason is what workflows and status files publish; the detail names the field
 * or row id that failed so an operator can fix the spec without reading the
 * database. Never carries a value read from a personnel row.
 */
final class OrganizationMappingFailure extends RuntimeException
{
    /** @var string */
    public $reason;

    /** @var string|null */
    public $detail;

    public function __construct(string $reason, ?string $detail = null)
    {
        $this->reason = $reason;
        $this->detail = $detail === null ? null : self::bound($detail);
        parent::__construct($this->detail === null ? $reason : $reason . ': ' . $this->detail);
    }

    public static function of(string $reason, ?string $detail = null): self
    {
        return new self($reason, $detail);
    }

    /**
     * Details reach CI logs and status files, so they stay short and limited to
     * the characters a field name, reason code or numeric id needs.
     */
    private static function bound(string $detail): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.,:\\-\\[\\]= ]/', '_', $detail);
        $safe = is_string($safe) ? $safe : 'unknown';

        return substr($safe, 0, 180);
    }
}
