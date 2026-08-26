<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

class PersonelMobileCapabilityException extends \RuntimeException
{
    /** @var string */
    private $errorCode;
    /** @var int */
    private $httpStatus;

    public function __construct($errorCode, $message, $httpStatus = 403)
    {
        parent::__construct((string) $message);
        $this->errorCode = (string) $errorCode;
        $this->httpStatus = (int) $httpStatus;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }
}
