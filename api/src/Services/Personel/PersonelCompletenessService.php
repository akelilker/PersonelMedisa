<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

/**
 * Canonical personel master-data completeness owner.
 * Document/evrak completeness is intentionally separate.
 */
class PersonelCompletenessService
{
    public const SEVERITY_CRITICAL = 'CRITICAL';
    public const SEVERITY_WARNING = 'WARNING';

    public const CATEGORY_KIMLIK = 'KIMLIK';
    public const CATEGORY_ILETISIM = 'ILETISIM';
    public const CATEGORY_ISTIHDAM = 'ISTIHDAM';

    /**
     * SQL predicate: personel row has at least one required master-data gap.
     * Must stay in parity with evaluate().
     */
    public static function sqlHasMissingPredicate(
        $alias = 'p',
        $hasOrgStructure = true,
        $hasCalisanKapsami = true,
        $hasOrgLocation = true
    ) {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
        if ($a === '') {
            $a = 'p';
        }

        $calisanKapsami = $hasCalisanKapsami
            ? "IFNULL({$a}.calisan_kapsami, 'IC_PERSONEL')"
            : "'IC_PERSONEL'";

        // Branch/location/manager and organization fields are critical for both
        // employee scopes. SGK is IC-only (DIS_KAYNAK must keep NULL payroll employer).
        $sharedOrgPredicate = $hasOrgStructure
            ? " OR IFNULL({$a}.sube_id, 0) <= 0"
                . " OR IFNULL({$a}.calisma_lokasyonu_id, 0) <= 0"
                . " OR IFNULL({$a}.bagli_amir_id, 0) <= 0"
                . " OR IFNULL({$a}.departman_id, 0) <= 0"
                . " OR IFNULL({$a}.bolum_id, 0) <= 0"
                . " OR IFNULL({$a}.birim_id, 0) <= 0"
                . " OR IFNULL({$a}.gorev_id, 0) <= 0"
                . " OR IFNULL({$a}.pozisyon_id, 0) <= 0"
            : " OR IFNULL({$a}.sube_id, 0) <= 0"
                . " OR IFNULL({$a}.departman_id, 0) <= 0"
                . " OR IFNULL({$a}.gorev_id, 0) <= 0";

        $icSgkPredicate = $hasOrgLocation
            ? " OR ("
                . "  {$calisanKapsami} <> 'DIS_KAYNAK'"
                . "  AND IFNULL({$a}.sgk_isveren_id, 0) <= 0"
                . " )"
            : '';

        return "("
            . "TRIM(IFNULL({$a}.sicil_no, '')) = ''"
            . " OR TRIM(IFNULL({$a}.ise_giris_tarihi, '')) = ''"
            . $sharedOrgPredicate
            . $icSgkPredicate
            . " OR ("
            . "  {$calisanKapsami} <> 'DIS_KAYNAK'"
            . "  AND ("
            . "    TRIM(IFNULL({$a}.tc_kimlik_no, '')) = ''"
            . "    OR TRIM(IFNULL({$a}.dogum_tarihi, '')) = ''"
            . "    OR TRIM(IFNULL({$a}.telefon, '')) = ''"
            . "    OR IFNULL({$a}.personel_tipi_id, 0) <= 0"
            . "  )"
            . " )"
            . ")";
    }

    /**
     * @param array<string, mixed> $personel Mapped or raw personel row
     * @param bool $includeFields When false, omit full missing_fields (list-light)
     * @return array{
     *   is_complete: bool,
     *   missing_count: int,
     *   critical_missing_labels: list<string>,
     *   missing_fields?: list<array{key:string,label:string,category:string,severity:string,edit_target:string}>
     * }
     */
    public static function evaluate(array $personel, $includeFields = true)
    {
        $scope = self::resolveScope($personel);
        $missing = [];

        foreach (self::rules() as $rule) {
            if (!in_array($scope, $rule['scopes'], true)) {
                continue;
            }
            if (!self::isMissing($personel, $rule['key'])) {
                continue;
            }
            $missing[] = [
                'key' => $rule['key'],
                'label' => $rule['label'],
                'category' => $rule['category'],
                'severity' => $rule['severity'],
                'edit_target' => $rule['edit_target'],
            ];
        }

        $labels = [];
        foreach ($missing as $field) {
            $labels[] = $field['label'];
        }

        $result = [
            'is_complete' => count($missing) === 0,
            'missing_count' => count($missing),
            'critical_missing_labels' => $labels,
        ];
        if ($includeFields) {
            $result['missing_fields'] = $missing;
        }

        return $result;
    }

    /**
     * @return list<array{
     *   key:string,
     *   label:string,
     *   category:string,
     *   severity:string,
     *   edit_target:string,
     *   scopes:list<string>
     * }>
     */
    public static function rules()
    {
        $both = [
            PersonelCalisanKapsamService::IC_PERSONEL,
            PersonelCalisanKapsamService::DIS_KAYNAK,
        ];
        $icOnly = [PersonelCalisanKapsamService::IC_PERSONEL];

        return [
            [
                'key' => 'tc_kimlik_no',
                'label' => 'T.C. Kimlik No',
                'category' => self::CATEGORY_KIMLIK,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $icOnly,
            ],
            [
                'key' => 'sicil_no',
                'label' => 'Sicil No',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'dogum_tarihi',
                'label' => 'Doğum Tarihi',
                'category' => self::CATEGORY_KIMLIK,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $icOnly,
            ],
            [
                'key' => 'telefon',
                'label' => 'Telefon',
                'category' => self::CATEGORY_ILETISIM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $icOnly,
            ],
            [
                'key' => 'ise_giris_tarihi',
                'label' => 'İşe Giriş Tarihi',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'sube_id',
                'label' => 'Şube',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'sgk_isveren_id',
                'label' => 'SGK İşveren',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $icOnly,
            ],
            [
                'key' => 'calisma_lokasyonu_id',
                'label' => 'Çalışma Lokasyonu',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'bagli_amir_id',
                'label' => 'Yönetici',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'departman_id',
                'label' => 'Departman',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'bolum_id',
                'label' => 'Bölüm',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'birim_id',
                'label' => 'Birim',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'gorev_id',
                'label' => 'Unvan / Görev',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'pozisyon_id',
                'label' => 'Pozisyon',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'genel',
                'scopes' => $both,
            ],
            [
                'key' => 'personel_tipi_id',
                'label' => 'Statü',
                'category' => self::CATEGORY_ISTIHDAM,
                'severity' => self::SEVERITY_CRITICAL,
                'edit_target' => 'pozisyon',
                'scopes' => $icOnly,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $personel
     */
    private static function resolveScope(array $personel)
    {
        $raw = isset($personel['calisan_kapsami']) ? strtoupper(trim((string) $personel['calisan_kapsami'])) : '';
        if ($raw === PersonelCalisanKapsamService::DIS_KAYNAK) {
            return PersonelCalisanKapsamService::DIS_KAYNAK;
        }

        return PersonelCalisanKapsamService::IC_PERSONEL;
    }

    /**
     * @param array<string, mixed> $personel
     */
    private static function isMissing(array $personel, $key)
    {
        switch ($key) {
            case 'tc_kimlik_no':
            case 'sicil_no':
            case 'dogum_tarihi':
            case 'telefon':
            case 'ise_giris_tarihi':
                return !self::hasText(isset($personel[$key]) ? $personel[$key] : null);
            case 'departman_id':
            case 'bolum_id':
            case 'birim_id':
            case 'gorev_id':
            case 'pozisyon_id':
            case 'personel_tipi_id':
            case 'sube_id':
            case 'sgk_isveren_id':
            case 'calisma_lokasyonu_id':
            case 'bagli_amir_id':
                return !self::hasPositiveId(isset($personel[$key]) ? $personel[$key] : null);
            default:
                return false;
        }
    }

    /** @param mixed $value */
    private static function hasText($value)
    {
        return is_string($value) && trim($value) !== '';
    }

    /** @param mixed $value */
    private static function hasPositiveId($value)
    {
        if (is_int($value)) {
            return $value > 0;
        }
        if (is_string($value) && ctype_digit(trim($value))) {
            return (int) $value > 0;
        }
        if (is_float($value)) {
            return (int) $value > 0 && (float) (int) $value === $value;
        }

        return false;
    }
}
