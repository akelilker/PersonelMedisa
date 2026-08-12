<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

/**
 * Structured QR candidate decision / apply errors.
 */
class QrPuantajCandidateDecisionException extends \RuntimeException
{
    /** @var string */
    private $errorCode;

    /** @var int */
    private $httpStatus;

    /** @var array<string,mixed>|null */
    private $meta;

    /**
     * @param array<string,mixed>|null $meta
     */
    public function __construct($errorCode, $message, $httpStatus = 409, $meta = null)
    {
        parent::__construct((string) $message, 0);
        $this->errorCode = (string) $errorCode;
        $this->httpStatus = (int) $httpStatus;
        $this->meta = is_array($meta) ? $meta : null;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /** @return array<string,mixed>|null */
    public function getMeta()
    {
        return $this->meta;
    }
}
