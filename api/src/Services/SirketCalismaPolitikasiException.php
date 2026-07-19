<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

class SirketCalismaPolitikasiException extends \RuntimeException
{
    /** @var array<string, mixed> */
    private $context;

    /** @param array<string, mixed> $context */
    public function __construct($code, $message, $httpStatus = 400, array $context = [])
    {
        parent::__construct((string) $message, (int) $httpStatus);
        $this->context = array_merge(['code' => (string) $code], $context);
    }

    public function getErrorCode()
    {
        return (string) ($this->context['code'] ?? 'POLICY_ERROR');
    }

    /** @return array<string, mixed> */
    public function getContext()
    {
        return $this->context;
    }
}
