<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use RuntimeException;

/**
 * Fail-closed errors for test-fixture personel archive lifecycle.
 */
class TestFixturePersonelArchiveException extends RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    /** @var string|null */
    private $field;

    public function __construct($errorCode, $message, $httpStatus = 409, $field = null)
    {
        parent::__construct((string) $message, 0);
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

    /** @return string|null */
    public function getField()
    {
        return $this->field;
    }
}
