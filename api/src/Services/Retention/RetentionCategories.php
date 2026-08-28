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
    /** Non-employment test fixture archive — never resolves as termination. */
    public const TRIGGER_TEST_FIXTURE_ARCHIVE = 'TEST_FIXTURE_ARCHIVE';

    /** Medisa saklama politikası — company policy note (never statutory claim). */
    public const POLICY_NOTE = 'Medisa saklama politikası';

    /**
     * Canonical company-wide minimum retention floor (years).
     * No category may retain for less. A longer legal/policy period always wins.
     */
    public const MIN_RETENTION_YEARS = 10;

    /** Canonical company retention duration. Kept as the historical owner name. */
    public const POLICY_RETENTION_YEARS = self::MIN_RETENTION_YEARS;

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
     * Declared canonical retention duration per category, in years.
     * Every category is declared at the company floor; a category may only ever be
     * raised above it (longer legal/policy period), never lowered.
     *
     * @return array<string, int>
     */
    public static function declaredRetentionYears()
    {
        $map = [];
        foreach (self::all() as $category) {
            $map[$category] = self::MIN_RETENTION_YEARS;
        }

        return $map;
    }

    /**
     * Effective retention duration = max(company floor, declared category duration).
     * Makes a shorter-than-floor category period structurally unreachable while
     * preserving any longer declared period.
     *
     * @return int years
     */
    public static function retentionYearsForCategory($category)
    {
        $declared = self::declaredRetentionYears();
        $category = (string) $category;
        $years = isset($declared[$category]) ? (int) $declared[$category] : self::MIN_RETENTION_YEARS;

        return $years > self::MIN_RETENTION_YEARS ? $years : self::MIN_RETENTION_YEARS;
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
