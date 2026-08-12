<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction;

/**
 * Stable codes for physical destruction plan/execute (no PII).
 */
final class PhysicalDestructionCodes
{
    public const HANDLER_VERSION = 'RETENTION_PHYSICAL_V1';

    public const MODE_DELETE_ROWS = 'DELETE_ROWS';
    public const MODE_ANONYMIZE_FIELDS = 'ANONYMIZE_FIELDS';
    public const MODE_DELETE_FILE_AND_METADATA = 'DELETE_FILE_AND_METADATA';
    public const MODE_COMPOSITE = 'COMPOSITE';
    public const MODE_POLICY_DECISION_REQUIRED = 'POLICY_DECISION_REQUIRED';

    public const CODE_DESTRUCTION_EXECUTION_DISABLED = 'DESTRUCTION_EXECUTION_DISABLED';
    public const CODE_DESTRUCTION_PLAN_CHANGED = 'DESTRUCTION_PLAN_CHANGED';
    public const CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED = 'DESTRUCTION_HANDLER_POLICY_UNRESOLVED';
    public const CODE_DESTRUCTION_EXECUTED = 'DESTRUCTION_EXECUTED';
    public const CODE_ALREADY_EXECUTED = 'ALREADY_EXECUTED';
    public const CODE_TARGET_ALREADY_MISSING = 'TARGET_ALREADY_MISSING';
    public const CODE_DEPENDENT_RETENTION_RECORDS_REMAIN = 'DEPENDENT_RETENTION_RECORDS_REMAIN';
    public const CODE_DESTRUCTION_CONFIRMATION_REQUIRED = 'DESTRUCTION_CONFIRMATION_REQUIRED';
    public const CODE_DESTRUCTION_EXECUTION_INVALID = 'DESTRUCTION_EXECUTION_INVALID';
    public const CODE_DESTRUCTION_SCHEMA_NOT_READY = 'DESTRUCTION_EXECUTION_SCHEMA_NOT_READY';
    public const CODE_POST_STATE_DESTROYED_AS_APPROVED = 'DESTROYED_AS_APPROVED';

    public const CONFIRMATION_TOKEN = 'DESTROY_APPROVED_REQUEST';

    public const STATE_PREPARED = 'PREPARED';
    public const STATE_EXECUTED = 'EXECUTED';
    public const STATE_FAILED = 'FAILED';

    /**
     * @return array<string, string>
     */
    public static function messages()
    {
        return [
            self::CODE_DESTRUCTION_EXECUTION_DISABLED => 'Fiziksel imha feature flag kapali.',
            self::CODE_DESTRUCTION_PLAN_CHANGED => 'Imha plani degisti; execute reddedildi.',
            self::CODE_DESTRUCTION_HANDLER_POLICY_UNRESOLVED => 'Kategori imha politikasi cozumlenmedi.',
            self::CODE_DESTRUCTION_EXECUTED => 'Fiziksel imha tamamlandi.',
            self::CODE_ALREADY_EXECUTED => 'Imha talebi daha once yurutuldu.',
            self::CODE_TARGET_ALREADY_MISSING => 'Hedef kaynak ilk execute oncesi yok; fail-closed.',
            self::CODE_DEPENDENT_RETENTION_RECORDS_REMAIN => 'Bagimli saklama kayitlari hala mevcut.',
            self::CODE_DESTRUCTION_CONFIRMATION_REQUIRED => 'Explicit confirmation gerekli.',
            self::CODE_DESTRUCTION_EXECUTION_INVALID => 'Imha execute istegi gecersiz.',
            self::CODE_DESTRUCTION_SCHEMA_NOT_READY => 'Imha execution semasi hazir degil.',
            self::CODE_POST_STATE_DESTROYED_AS_APPROVED => 'Kaynak onayli imha ile yok edildi.',
        ];
    }

    public static function message($code)
    {
        $map = self::messages();
        $code = (string) $code;

        return isset($map[$code]) ? $map[$code] : $code;
    }
}
