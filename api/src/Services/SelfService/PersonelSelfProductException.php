<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

class PersonelSelfProductException extends \RuntimeException
{
    /** @var string */
    private $errorCode;
    /** @var int */
    private $httpStatus;
    /** @var string|null */
    private $field;

    public function __construct($errorCode, $message, $httpStatus = 400, $field = null)
    {
        parent::__construct((string) $message);
        $this->errorCode = (string) $errorCode;
        $this->httpStatus = (int) $httpStatus;
        $this->field = $field !== null ? (string) $field : null;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    public function getField()
    {
        return $this->field;
    }
}
