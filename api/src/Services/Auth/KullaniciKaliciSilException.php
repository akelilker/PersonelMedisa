<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use RuntimeException;

/**
 * Domain failure for the Kalıcı Sil (hard delete) flow, carrying its own HTTP
 * contract so the controller stays a thin translation layer and the same rule
 * produces the same status wherever it is enforced.
 */
final class KullaniciKaliciSilException extends RuntimeException
{
    /** @var int */
    public $httpStatus;

    /** @var string */
    public $errorCode;

    /** @var string|null */
    public $field;

    public function __construct(int $httpStatus, string $errorCode, string $message, ?string $field = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCode = $errorCode;
        $this->field = $field;
    }

    public static function validation(string $message, ?string $field = null): self
    {
        return new self(400, 'VALIDATION_ERROR', $message, $field);
    }

    public static function conflict(string $code, string $message, ?string $field = null): self
    {
        return new self(409, $code, $message, $field);
    }

    public static function notFound(string $message): self
    {
        return new self(404, 'NOT_FOUND', $message);
    }

    /**
     * The audit evidence table (099) is absent, so an un-auditable hard delete
     * is refused instead of being performed silently.
     */
    public static function auditSchemaNotReady(): self
    {
        return new self(
            409,
            'KALICI_SIL_AUDIT_SCHEMA_NOT_READY',
            'Kalıcı sil denetim şeması bu ortamda hazır değil; denetlenemeyen silme reddedildi.'
        );
    }
}
