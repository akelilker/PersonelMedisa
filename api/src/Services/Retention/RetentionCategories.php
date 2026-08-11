<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

/**
 * Phase C — stable retention category / trigger catalog.
 * Wording: Medisa saklama politikası (company policy). Never statutory "kanunen 10 yıl".
 */
class RetentionCategories
{
    public const PERSONEL_OZLUK = 'PERSONEL_OZLUK';
    public const PUANTAJ = 'PUANTAJ';
    public const BORDRO = 'BORDRO';
    public const IZIN = 'IZIN';
    public const RAPOR = 'RAPOR';
    public const IS_KAZASI = 'IS_KAZASI';
    public const SGK_EKSIK_GUN = 'SGK_EKSIK_GUN';
    public const FAZLA_CALISMA = 'FAZLA_CALISMA';
    public const SERBEST_ZAMAN = 'SERBEST_ZAMAN';
    public const DISIPLIN = 'DISIPLIN';
    public const OLAY = 'OLAY';
    public const SAVUNMA = 'SAVUNMA';
    public const ISE_GIRIS_CIKIS = 'ISE_GIRIS_CIKIS';
    public const PERSONEL_BELGE = 'PERSONEL_BELGE';
    public const ONAY_AUDIT = 'ONAY_AUDIT';

    public const TRIGGER_PERIOD_CLOSURE = 'PERIOD_CLOSURE';
    public const TRIGGER_TERMINATION_DATE = 'TERMINATION_DATE';

    /** Medisa saklama politikası — company policy note (never statutory claim). */
    public const POLICY_NOTE = 'Medisa saklama politikası';
    public const POLICY_RETENTION_YEARS = 10;

    /**
     * Periodic (PERIOD_CLOSURE) categories.
     *
     * @return array<int, string>
     */
    public static function periodClosureCategories()
    {
        return [
            self::PUANTAJ,
            self::BORDRO,
            self::SGK_EKSIK_GUN,
            self::FAZLA_CALISMA,
            self::SERBEST_ZAMAN,
            self::ONAY_AUDIT,
        ];
    }

    /**
     * Lifecycle (TERMINATION_DATE) categories — employment-file lifecycle.
     *
     * @return array<int, string>
     */
    public static function terminationDateCategories()
    {
        return [
            self::PERSONEL_OZLUK,
            self::ISE_GIRIS_CIKIS,
            self::PERSONEL_BELGE,
            self::DISIPLIN,
            self::OLAY,
            self::SAVUNMA,
            self::IZIN,
            self::RAPOR,
            self::IS_KAZASI,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return array_values(array_unique(array_merge(
            self::periodClosureCategories(),
            self::terminationDateCategories()
        )));
    }

    public static function isKnown($category)
    {
        return in_array((string) $category, self::all(), true);
    }

    /**
     * @return string|null PERIOD_CLOSURE|TERMINATION_DATE
     */
    public static function triggerTypeForCategory($category)
    {
        $category = (string) $category;
        if (in_array($category, self::periodClosureCategories(), true)) {
            return self::TRIGGER_PERIOD_CLOSURE;
        }
        if (in_array($category, self::terminationDateCategories(), true)) {
            return self::TRIGGER_TERMINATION_DATE;
        }

        return null;
    }
}
