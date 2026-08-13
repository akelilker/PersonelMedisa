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
    /**
     * SERBEST_ZAMAN: personel has unallocated KULLANIM (legacy provenance unknown).
     * Week-owned OLUSUM destruction would corrupt cross-lot balance — fail-closed, no mutation.
     */
    public const CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED = 'SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED';
    /**
     * SERBEST_ZAMAN: a KULLANIM that touches target OLUSUM lots also retains allocation
     * provenance outside the current approved destruction scope — fail-closed, no mutation.
     */
    public const CODE_SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS = 'SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS';
    /**
     * SERBEST_ZAMAN: allocation ledger invariant broken for affected personel — fail-closed.
     */
    public const CODE_SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN = 'SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN';
    /**
     * SERBEST_ZAMAN: required allocation / destroy-gate schema missing — fail-closed.
     */
    public const CODE_SERBEST_ZAMAN_ALLOCATION_SCHEMA_NOT_READY = 'SERBEST_ZAMAN_ALLOCATION_SCHEMA_NOT_READY';
    /** RAPOR/IS_KAZASI blocked while personel_belge_* rows still REFERENCE the surec (038 RESTRICT). */
    public const CODE_PERSONEL_BELGE_REMAINS = 'PERSONEL_BELGE_REMAINS';
    /** PUANTAJ blocked while typed ONAY_AUDIT qr_pc_decision ledger still RESTRICTs period daily rows. */
    public const CODE_PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT = 'PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT';
    /** PUANTAJ blocked while open reopen lifecycle (ONAY_BEKLIYOR|ONAYLANDI) remains. */
    public const CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS = 'PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS';
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
            self::CODE_SERBEST_ZAMAN_USAGE_ALLOCATION_UNRESOLVED =>
                'SERBEST_ZAMAN KULLANIM lot tahsisi cozumlenmedi; OLUSUM imha engellendi.',
            self::CODE_SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS =>
                'SERBEST_ZAMAN KULLANIM hedef disi OLUSUM tahsisi tasiyor; capraz scope imha engellendi.',
            self::CODE_SERBEST_ZAMAN_ALLOCATION_INVARIANT_BROKEN =>
                'SERBEST_ZAMAN tahsis invariant bozuk; imha engellendi.',
            self::CODE_SERBEST_ZAMAN_ALLOCATION_SCHEMA_NOT_READY =>
                'SERBEST_ZAMAN tahsis / destroy gate semasi hazir degil; imha engellendi.',
            self::CODE_PERSONEL_BELGE_REMAINS =>
                'PERSONEL_BELGE dosya/audit kayitlari surece bagli; once belge imha gerekir.',
            self::CODE_PUANTAJ_BLOCKED_BY_QR_ONAY_AUDIT => 'PUANTAJ imha, once typed ONAY_AUDIT (qr_pc_decision) imha gerektirir.',
            self::CODE_PUANTAJ_OPEN_REOPEN_REQUEST_EXISTS => 'PUANTAJ imha, acik reopen talebi varken engellendi.',
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
