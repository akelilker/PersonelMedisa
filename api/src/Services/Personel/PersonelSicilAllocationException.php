<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

/**
 * Raised when the canonical sicil sequence cannot hand out a free numeric sicil.
 */
final class PersonelSicilAllocationException extends PersonelValidationException
{
    public function __construct($message)
    {
        parent::__construct('sicil_no', $message, PersonelSicilAllocator::ERROR_CODE);
    }
}
