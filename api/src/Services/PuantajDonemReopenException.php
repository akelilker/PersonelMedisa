<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use Exception;

class PuantajDonemReopenException extends Exception
{
    /** @var string */
    private $errorCode;

    /** @var array<string, mixed> */
    private $meta;

    /** @param array<string, mixed> $meta */
    public function __construct($errorCode, $message, $httpStatus = 409, array $meta = [])
    {
        parent::__construct((string) $message, (int) $httpStatus);
        $this->errorCode = (string) $errorCode;
        $this->meta = $meta;
    }

    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /** @return array<string, mixed> */
    public function getMeta()
    {
        return $this->meta;
    }
}
