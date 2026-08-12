<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use Exception;

class QrAttendanceException extends Exception
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
        $this->field = $field !== null && $field !== '' ? (string) $field : null;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /** @return string|null */
    public function getField()
    {
        return $this->field;
    }
}
