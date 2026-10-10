<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

final class IlkYoneticiKurulumException extends \RuntimeException
{
    /** @var string */
    public $errorCode;

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}
