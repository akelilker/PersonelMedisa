<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use RuntimeException;

class PersonelValidationException extends RuntimeException
{
    /** @var string */
    private $field;

    /** @var string */
    private $codeString;

    public function __construct($field, $message, $codeString = 'VALIDATION_ERROR')
    {
        parent::__construct((string) $message);
        $this->field = (string) $field;
        $this->codeString = (string) $codeString;
    }

    public function getField()
    {
        return $this->field;
    }

    public function getCodeString()
    {
        return $this->codeString;
    }
}
