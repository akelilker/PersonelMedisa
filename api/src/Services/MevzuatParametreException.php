<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use RuntimeException;

class MevzuatParametreException extends RuntimeException
{
    /** @var string */
    private $codeString;

    /** @var int */
    private $httpStatus;

    public function __construct($codeString, $message, $httpStatus = 400)
    {
        parent::__construct((string) $message);
        $this->codeString = (string) $codeString;
        $this->httpStatus = (int) $httpStatus;
    }

    public function getCodeString()
    {
        return $this->codeString;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }
}
