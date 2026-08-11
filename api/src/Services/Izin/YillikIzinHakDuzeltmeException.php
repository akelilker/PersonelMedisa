<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Izin;

class YillikIzinHakDuzeltmeException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    /** @var string|null */
    private $field;

    public function __construct($code, $message, $httpStatus = 400, $field = null)
    {
        parent::__construct((string) $message, (int) $httpStatus);
        $this->errorCode = (string) $code;
        $this->httpStatus = (int) $httpStatus;
        $this->field = $field !== null && (string) $field !== '' ? (string) $field : null;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        $code = (int) $this->httpStatus;

        return $code >= 400 && $code < 600 ? $code : 400;
    }

    /** @return string|null */
    public function getField()
    {
        return $this->field;
    }
}
