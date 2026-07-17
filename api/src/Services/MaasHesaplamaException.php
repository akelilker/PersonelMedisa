<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use RuntimeException;

class MaasHesaplamaException extends RuntimeException
{
    /** @var string */
    private $codeString;

    /** @var int */
    private $httpStatus;

    /** @var array<string, mixed> */
    private $details;

    /** @param array<string, mixed> $details */
    public function __construct($codeString, $message, $httpStatus = 400, array $details = [])
    {
        parent::__construct((string) $message);
        $this->codeString = (string) $codeString;
        $this->httpStatus = (int) $httpStatus;
        $this->details = $details;
    }

    public function getCodeString()
    {
        return $this->codeString;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /** @return array<string, mixed> */
    public function getDetails()
    {
        return $this->details;
    }
}
