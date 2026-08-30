<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use RuntimeException;

/**
 * Domain failure carrying its own HTTP contract, so the controller stays a thin
 * translation layer and the same rule produces the same status wherever it is
 * enforced.
 */
final class OrganizasyonException extends RuntimeException
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
     * The hierarchy endpoints are unusable before migration 079 is applied. That
     * is a known, explainable state — it must never surface as a 500 or an
     * "unknown table" driver error.
     */
    public static function schemaNotReady(): self
    {
        return new self(
            409,
            'ORGANIZASYON_SCHEMA_NOT_READY',
            'Şirket-şube hiyerarşisi şeması bu ortamda henüz hazır değil. Şube yönetimi eski düz listeyle sürüyor.'
        );
    }
}
