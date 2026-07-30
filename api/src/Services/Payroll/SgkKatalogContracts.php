<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1 / S98: Shared deterministic helpers for SGK catalog readiness (no seed).
 */
final class SgkKatalogContracts
{
    public const BLOCKER_TAMLIK = 'SGK_KATALOG_TAMLIK_KANITI_EKSIK';
    public const BLOCKER_SUREC_BULUNAMADI = 'SGK_SUREC_KOD_ESLEMESI_BULUNAMADI';
    public const BLOCKER_SUREC_CAKISTI = 'SGK_SUREC_KOD_ESLEMESI_CAKISTI';
    public const BLOCKER_SUREC_KAYNAK = 'SGK_SUREC_ESLEME_KAYNAGI_EKSIK';
    public const BLOCKER_COKLU_BULUNAMADI = 'SGK_COKLU_NEDEN_BIRLESIK_KOD_BULUNAMADI';
    public const BLOCKER_COKLU_CAKISTI = 'SGK_COKLU_NEDEN_BIRLESIK_KOD_CAKISTI';
    public const BLOCKER_OP_KANIT = 'SGK_OPERASYONEL_KANIT_ICERIGI_DOGRULANAMADI';
    public const BLOCKER_KISMI_KURAL = 'SGK_KISMI_SURELI_HESAP_KURALI_EKSIK';
    public const BLOCKER_KISMI_BELGE = 'SGK_KISMI_SURELI_SOZLESME_BELGESI_EKSIK';
    public const BLOCKER_BILDIRIM = 'SGK_BILDIRIM_DONEMI_POLITIKASI_EKSIK';
    public const BLOCKER_KOD_KURAL = 'SGK_EKSIK_GUN_KODU_CAKISTI';
    public const BLOCKER_YABANCI = 'SGK_YABANCI_KOD_IZNI_YOK';
    public const BLOCKER_TARIHSEL = 'SGK_TARIHSEL_KOD_GECERSIZ';
    public const BLOCKER_LEGACY = 'SGK_LEGACY_CANONICAL_CELISKI';
    public const BLOCKER_ATTESTATION = 'SGK_KATALOG_ATTESTATION_EKSIK';

    /** @var list<string> */
    public const GECERLILIK_TARIH_DURUMU = ['RESMI_YURURLUK', 'ILK_RESMI_KANIT', 'BELIRLENEMEDI'];

    /** @var list<string> */
    public const TAMLIK_DURUMU = ['TASLAK', 'RESMI_KAYNAKLI_KISITLI', 'DOGRULANMIS_TAM'];

    public const KANIT_RESMI_PRIMARY = 'RESMI_PRIMARY';
    public const KANIT_EXPERT_DRAFT = 'EXPERT_DRAFT';
    public const KANIT_OPERASYONEL = 'OPERASYONEL';

    /** @var list<string> */
    public const CANONICAL_SUREC_TURLERI = [
        'HASTALIK',
        'IS_KAZASI',
        'MESLEK_HASTALIGI',
        'ANALIK',
        'UCRETSIZ_IZIN',
        'YILLIK_IZIN',
        'MAZERETSIZ_DEVAMSIZLIK',
        'KISMI_SURELI_CALISMA',
        'PUANTAJ_EKSIK_GUN',
        'DIGER_MANUEL_INCELEME',
    ];

    /** @var list<string> */
    public const BELGE_ZORUNLULUK = ['YOK', 'KOSULLU', 'ZORUNLU'];

    /** @var list<string> */
    public const BIRLIKTE_KULLANIM = ['YASAK', 'KOSULLU', 'SERBEST'];

    /** @var list<string> */
    public const ONAY_STATES = ['TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL'];

    /** @var list<string> */
    public const AKTIFLIK_DURUMU = ['AKTIF', 'TARIHSEL', 'BAGLAMA_OZGUN', 'PORTAL_TEYIT_BEKLIYOR'];

    /** @var list<string> */
    public const SIFIR_GUN_DURUMU = ['IZINLI', 'YASAK', 'KOSULLU', 'TEYITSIZ'];

    /** @var list<string> */
    public const BELGE_SAKLAMA_IBRAZ = [
        'YOK',
        'ISVERENCE_SAKLA_TALEPTE_IBRAZ',
        'ELEKTRONIK_KAYNAKTAN',
        'KURUMA_GONDER',
        'KOSULLU',
        'TEYITSIZ',
    ];

    /** @var list<string> */
    public const YABANCI_KULLANIM = ['IZINLI', 'YASAK', 'KOSULLU', 'TEYITSIZ'];

    /** @var list<string> */
    public const PORTAL_TEYIT = ['TEYIT_EDILDI', 'TEYIT_BEKLIYOR', 'TARIHSEL'];

    /** Official portal-allowed base codes for foreign nationals (resmi temel matris). */
    public const YABANCI_TEMEL_KODLAR = ['01', '04', '05', '08', '09', '10', '11'];

    /** Additional official context codes (not automatic general alternatives). */
    public const YABANCI_BAGLAM_KODLAR = ['18', '26', '27'];

    /** Codes that are never auto-allowed for foreign nationals without explicit matrix. */
    public const YABANCI_OTOMATIK_YASAK_ORNEK = ['07', '12', '15', '20', '21'];

    public static function sha256Canonical(array $payload): string
    {
        return hash('sha256', self::canonicalJson($payload));
    }

    public static function canonicalJson(array $payload): string
    {
        self::ksortRecursive($payload);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('SGK catalog canonical JSON encode failed.');
        }

        return $json;
    }

    public static function ksortRecursive(array &$arr): void
    {
        ksort($arr);
        foreach ($arr as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }

    /**
     * @param list<string> $codes
     * @return list<string>
     */
    public static function normalizeKodSet(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $c = strtoupper(trim((string) $code));
            if ($c === '') {
                continue;
            }
            $out[$c] = $c;
        }
        $list = array_values($out);
        sort($list, SORT_STRING);

        return $list;
    }

    public static function kodSetHash(array $codes): string
    {
        return self::sha256Canonical(['kodlar' => self::normalizeKodSet($codes)]);
    }

    /**
     * Known official rule fixture (not a catalog seed): code 07 cannot be used with 0 day / 0 earnings.
     */
    public static function assert07ZeroEarningsRule(string $kod, int $primGun, float $kazanc): ?array
    {
        if ($kod === '07' && $primGun === 0 && abs($kazanc) < 0.0000001) {
            return self::blocker(
                self::BLOCKER_KOD_KURAL,
                '07-Puantaj kayitlari 0 gun / 0 kazanc bildirimlerinde kullanilamaz.',
                '0/0 bildirimde 07 disinda resmi kod kullanin veya bildirimi duzeltin.'
            );
        }

        return null;
    }

    /**
     * @param array{
     *   eksik_gun_kodu?: string,
     *   prim_gun?: int,
     *   kazanc?: float|int,
     *   kismi_sureli_sozlesme_var_mi?: bool,
     *   saat_75_bolme_kullanildi_mi?: bool,
     *   puantaj_imzali_mi?: bool|null,
     *   calisma_gunu_sayisi?: int|null,
     *   hafta_tatili_degerlendirildi_mi?: bool|null
     * } $input
     * @return list<array<string,mixed>>
     */
    public static function assert07PuantajRules(array $input): array
    {
        $kod = strtoupper(trim((string) ($input['eksik_gun_kodu'] ?? '')));
        if ($kod !== '07') {
            return [];
        }

        $blockers = [];
        $primGun = (int) ($input['prim_gun'] ?? -1);
        $kazanc = (float) ($input['kazanc'] ?? 0.0);
        $zero = self::assert07ZeroEarningsRule('07', $primGun, $kazanc);
        if ($zero !== null) {
            $blockers[] = $zero;
        }

        if (
            empty($input['kismi_sureli_sozlesme_var_mi'])
            && !empty($input['saat_75_bolme_kullanildi_mi'])
        ) {
            $blockers[] = self::blocker(
                self::BLOCKER_KOD_KURAL,
                '07: Kismi sureli is sozlesmesi yokken toplam saat / 7,5 yontemi kullanilamaz.',
                'Yazili kismi sureli sozlesme olmadan imzali puantaj gununu tam gun kabul edin.'
            );
        }

        if (array_key_exists('puantaj_imzali_mi', $input) && $input['puantaj_imzali_mi'] === false) {
            $blockers[] = self::blocker(
                self::BLOCKER_KOD_KURAL,
                '07: Imzasiz puantaj kabul edilmez.',
                'Imzali puantaj kaydi sunun; imzali calisilan gun tam gun sayilir.'
            );
        }

        if (
            isset($input['calisma_gunu_sayisi'])
            && (int) $input['calisma_gunu_sayisi'] === 6
            && array_key_exists('hafta_tatili_degerlendirildi_mi', $input)
            && $input['hafta_tatili_degerlendirildi_mi'] === false
        ) {
            $blockers[] = self::blocker(
                self::BLOCKER_KOD_KURAL,
                '07: Haftanin alti gunu calisma varsa hafta tatili degerlendirilmelidir.',
                'Imzali alti calisma gununde yedinci gun hafta tatili hakki degerlendirilir.'
            );
        }

        return $blockers;
    }

    /**
     * Signed worked day under code 07 is a full day (official semantic helper, not a seed).
     */
    public static function assert07ImzaliGunTamGun(bool $imzali, bool $calisilanGun): ?string
    {
        if ($imzali && $calisilanGun) {
            return 'TAM_GUN';
        }

        return null;
    }

    /**
     * @param array{
     *   eksik_gun_kodu?: string,
     *   eksik_gun_sayisi?: int,
     *   prim_gun?: int,
     *   kazanc?: float|int,
     *   isci_talebi_var_mi?: bool,
     *   yillik_izin_baska_yer_belgesi_var_mi?: bool
     * } $input
     * @return list<array<string,mixed>>
     */
    public static function assert20UcretsizYolIzniRules(array $input): array
    {
        $kod = strtoupper(trim((string) ($input['eksik_gun_kodu'] ?? '')));
        if ($kod !== '20') {
            return [];
        }

        $blockers = [];
        $eksik = (int) ($input['eksik_gun_sayisi'] ?? 0);
        if ($eksik > 4) {
            $blockers[] = self::blocker(
                self::BLOCKER_KOD_KURAL,
                '20-Ucretsiz yol izni toplam en fazla 4 gundur.',
                'Eksik gun sayisini en fazla 4 ile sinirlayin veya resmi baska kod kullanin.'
            );
        }

        $primGun = (int) ($input['prim_gun'] ?? -1);
        $kazanc = (float) ($input['kazanc'] ?? 0.0);
        if ($primGun === 0 && abs($kazanc) < 0.0000001) {
            $blockers[] = self::blocker(
                self::BLOCKER_KOD_KURAL,
                '20: Butun ayi kapsayan 0 gun / 0 kazanc bildirimlerinde kullanilamaz.',
                '0/0 bildirimde 20 kodunu kullanmayin.'
            );
        }

        if (
            empty($input['isci_talebi_var_mi'])
            || empty($input['yillik_izin_baska_yer_belgesi_var_mi'])
        ) {
            $blockers[] = self::blocker(
                self::BLOCKER_KOD_KURAL,
                '20: Isci talebi ve yillik izni baska yerde gecirecegine dair belge gerekir.',
                'Talep ve belge kanitini sunmadan 20 kodunu kullanmayin.'
            );
        }

        return $blockers;
    }

    /**
     * Official 18/27 combination gate (not a seeded catalog matrix).
     *
     * @param list<string> $nedenKodlari
     * @return array{sonuc_eksik_gun_kodu: ?string, blocker_detaylari: list<array<string,mixed>>, kaynak_kod_set_hash: string}
     */
    public static function assert1827Combination(array $nedenKodlari, ?string $sonucKodu = null): array
    {
        $normalized = self::normalizeKodSet($nedenKodlari);
        $hash = self::kodSetHash($normalized);
        $blockers = [];
        $has18 = in_array('18', $normalized, true);
        $others = array_values(array_filter($normalized, static fn (string $c): bool => $c !== '18'));

        $expected = null;
        if ($has18 && $others === []) {
            $expected = '18';
        } elseif ($has18 && $others !== []) {
            $expected = '27';
        }

        if ($expected === '27' && $sonucKodu === '12') {
            $blockers[] = self::blocker(
                self::BLOCKER_KOD_KURAL,
                'Kisa calisma odeneği + baska eksik gun nedeninde genel kod 12 kullanilamaz; sonuc 27 olmalidir.',
                '18 + baska neden seti icin sonuc kodu 27 secin.'
            );
        }

        if ($expected !== null && $sonucKodu !== null && $sonucKodu !== $expected) {
            $blockers[] = self::blocker(
                self::BLOCKER_KOD_KURAL,
                '18/27 birlesim kurali ihlali: beklenen sonuc ' . $expected . ', gelen ' . $sonucKodu . '.',
                'Yalniz kisa calisma icin 18; 18 + baska neden icin 27 kullanin.'
            );
        }

        return [
            'sonuc_eksik_gun_kodu' => $expected,
            'blocker_detaylari' => $blockers,
            'kaynak_kod_set_hash' => $hash,
            'kodlar_normalize' => $normalized,
        ];
    }

    /**
     * @param array{
     *   eksik_gun_kodu?: string,
     *   yabanci_uyruklu_mu?: bool,
     *   kismi_istihdam_izinli_mi?: bool,
     *   kisa_calisma_baglami_mi?: bool,
     *   analik_4857_74_baglami_mi?: bool
     * } $input
     * @return list<array<string,mixed>>
     */
    public static function assertYabanciKodIzni(array $input): array
    {
        if (empty($input['yabanci_uyruklu_mu'])) {
            return [];
        }

        $kod = strtoupper(trim((string) ($input['eksik_gun_kodu'] ?? '')));
        if ($kod === '') {
            return [];
        }

        if (in_array($kod, self::YABANCI_TEMEL_KODLAR, true)) {
            return [];
        }

        if ($kod === '26') {
            if (!empty($input['kismi_istihdam_izinli_mi'])) {
                return [];
            }

            return [self::blocker(
                self::BLOCKER_YABANCI,
                '26 yalniz kismi istihdama izin verilen yabanci uyruklu sigortali baglaminda kullanilir; genel 06 alternatifi degildir.',
                'Resmi kismi istihdam izni baglamini kanitlayin.'
            )];
        }

        if ($kod === '18' || $kod === '27') {
            if (!empty($input['kisa_calisma_baglami_mi'])) {
                return [];
            }

            return [self::blocker(
                self::BLOCKER_YABANCI,
                $kod . ' yalniz kisa calisma odeneği baglaminda yabanci sigortali icin degerlendirilir.',
                'Kisa calisma baglamini resmi olarak isaretleyin.'
            )];
        }

        if (!empty($input['analik_4857_74_baglami_mi'])) {
            return [self::blocker(
                self::BLOCKER_YABANCI,
                'Analik / 4857 md.74 baglami ayri mevzuat kosulu gerektirir; otomatik izinli degildir.',
                '4857 madde 74 kapsamini resmi kanit ile kosullu olarak degerlendirin.'
            )];
        }

        return [self::blocker(
            self::BLOCKER_YABANCI,
            'Kod ' . $kod . ' yabanci uyruklu calisan icin resmi izin matrisinde otomatik izinli degildir.',
            'Yalniz resmi portal temel/baglam kodlarini veya kanitli kosulu kullanin.'
        )];
    }

    /**
     * Evidence gate for codes 22-29 (replaces blanket reject).
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $manifest
     * @return list<string> error codes
     */
    public static function assertKod22_29EvidenceGate(string $kod, array $row, ?array $manifest): array
    {
        if (preg_match('/^(2[2-9])$/', $kod) !== 1) {
            return [];
        }

        $errors = [];
        $aktiflik = strtoupper((string) ($row['aktiflik_durumu'] ?? ''));
        $portal = strtoupper((string) ($row['portal_teyit_durumu'] ?? ''));
        $bas = (string) ($row['gecerlilik_baslangic'] ?? '');
        $bit = $row['gecerlilik_bitis'] ?? null;
        $bit = is_string($bit) && $bit !== '' ? $bit : null;
        $rules = $row['mevzuat_kurallari_json'] ?? ($row['mevzuat_kurallari'] ?? null);

        $primaryOk = self::isPrimaryOfficialManifest($manifest);
        $explicitKodKaniti = !empty($row['resmi_primary_kod_kaniti_var_mi']);

        // 22-25: fail-closed unless explicit primary code evidence is supplied.
        if (preg_match('/^(2[2-5])$/', $kod) === 1) {
            if (!$primaryOk || !$explicitKodKaniti) {
                $errors[] = 'KAYNAKSIZ_KOD_ARALIGI_22_29';
                $errors[] = 'PRIMARY_MANIFEST_EKSIK';

                return array_values(array_unique($errors));
            }
        } elseif (!$primaryOk) {
            $errors[] = 'KAYNAKSIZ_KOD_ARALIGI_22_29';
            $errors[] = 'PRIMARY_MANIFEST_EKSIK';
        }

        if ($aktiflik === '' || !in_array($aktiflik, self::AKTIFLIK_DURUMU, true)) {
            $errors[] = 'AKTIFLIK_DURUMU_EKSIK';
        }
        if ($portal === '' || !in_array($portal, self::PORTAL_TEYIT, true)) {
            $errors[] = 'PORTAL_TEYIT_EKSIK';
        }

        if ($aktiflik === 'AKTIF' && $portal !== 'TEYIT_EDILDI') {
            $errors[] = 'PORTAL_TEYITSIZ_AKTIF_RED';
        }

        if ($aktiflik === 'TARIHSEL') {
            if (!self::isDate($bas) || $bit === null || !self::isDate($bit)) {
                $errors[] = 'TARIHSEL_YURURLUK_ARALIGI_EKSIK';
            }
        }

        if ($aktiflik === 'BAGLAMA_OZGUN') {
            if (!self::isValidMevzuatKurallari($rules)) {
                $errors[] = 'BAGLAMA_OZGUN_MEVZUAT_JSON_EKSIK';
            }
        }

        if ($kod === '26') {
            $baglam = !empty($row['kismi_istihdam_izinli_mi']) || !empty($row['yabanci_kismi_istihdam_baglami_mi']);
            if (!$baglam) {
                $errors[] = 'KOD_26_BAGLAM_EKSIK';
            }
        }

        if ($kod === '27') {
            $setHash = (string) ($row['kaynak_kod_set_hash'] ?? '');
            if (!self::isSha256($setHash)) {
                $errors[] = 'KOD_27_KAYNAK_SET_HASH_EKSIK';
            }
            $kaynakKodlar = $row['kaynak_kodlar'] ?? null;
            if (!is_array($kaynakKodlar) || !in_array('18', self::normalizeKodSet($kaynakKodlar), true)) {
                $errors[] = 'KOD_27_18_BAGLAM_EKSIK';
            }
        }

        if ($kod === '28' || $kod === '29') {
            if ($aktiflik === 'AKTIF') {
                $errors[] = 'TARIHSEL_KOD_AKTIF_RED';
            }
            if ($aktiflik !== 'TARIHSEL') {
                $errors[] = 'TARIHSEL_KOD_AKTIFLIK_ZORUNLU';
            }
            if (!self::isDate($bas) || $bit === null || !self::isDate($bit)) {
                $errors[] = 'TARIHSEL_YURURLUK_ARALIGI_EKSIK';
            }
        }

        // 22-25: without primary evidence remain fail-closed (already flagged).
        return array_values(array_unique($errors));
    }

    public static function isPrimaryOfficialManifest(?array $manifest): bool
    {
        if ($manifest === null) {
            return false;
        }
        $durum = strtoupper((string) ($manifest['durum'] ?? ''));
        if ($durum === 'PASIF') {
            return false;
        }
        $sinif = strtoupper((string) ($manifest['kanit_sinifi'] ?? self::KANIT_RESMI_PRIMARY));
        if ($sinif === self::KANIT_EXPERT_DRAFT || $sinif === self::KANIT_OPERASYONEL) {
            return false;
        }
        $id = (string) ($manifest['kaynak_id'] ?? $manifest['id'] ?? '');

        return $id !== '';
    }

    /**
     * @param mixed $rules
     */
    public static function isValidMevzuatKurallari($rules): bool
    {
        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);
            $rules = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($rules)) {
            return false;
        }

        return isset($rules['schema_version']) && (int) $rules['schema_version'] >= 1;
    }

    /**
     * Canonical → legacy projection. Never silently maps KOSULLU/TEYITSIZ to true.
     *
     * @param array<string,mixed> $canonical
     * @return array{
     *   sifir_gun_sifir_kazanc_kullanilabilir_mi: bool,
     *   aktif_mi: bool,
     *   belge_zorunlulugu: string,
     *   warnings: list<string>,
     *   blockers: list<array<string,mixed>>
     * }
     */
    public static function projectCanonicalToLegacy(array $canonical): array
    {
        $warnings = [];
        $blockers = [];

        $sifir = strtoupper((string) ($canonical['sifir_gun_sifir_kazanc_durumu'] ?? 'TEYITSIZ'));
        $sifirLegacy = false;
        if ($sifir === 'IZINLI') {
            $sifirLegacy = true;
        } elseif ($sifir === 'YASAK') {
            $sifirLegacy = false;
        } elseif ($sifir === 'KOSULLU' || $sifir === 'TEYITSIZ') {
            $sifirLegacy = false;
            $warnings[] = 'LEGACY_SIFIR_GUN_FALSE_KOSULLU_VEYA_TEYITSIZ';
            $blockers[] = self::blocker(
                self::BLOCKER_LEGACY,
                'sifir_gun_sifir_kazanc_durumu=' . $sifir . ' legacy true uretemez.',
                'Kosullu/teyitsiz degeri sessizce izinli yapmayin; canonical durumu kullanin.'
            );
        }

        $aktiflik = strtoupper((string) ($canonical['aktiflik_durumu'] ?? 'PORTAL_TEYIT_BEKLIYOR'));
        $portal = strtoupper((string) ($canonical['portal_teyit_durumu'] ?? 'TEYIT_BEKLIYOR'));
        $aktifLegacy = false;
        if ($aktiflik === 'AKTIF' && $portal === 'TEYIT_EDILDI') {
            $aktifLegacy = true;
        } else {
            $aktifLegacy = false;
            if ($aktiflik !== 'AKTIF') {
                $warnings[] = 'LEGACY_AKTIF_FALSE_' . $aktiflik;
            }
            if ($portal === 'TEYIT_BEKLIYOR') {
                $warnings[] = 'LEGACY_PORTAL_TEYIT_BEKLIYOR';
            }
        }

        $belgeSaklama = strtoupper((string) ($canonical['belge_saklama_ibraz_durumu'] ?? 'TEYITSIZ'));
        $belgeLegacy = 'KOSULLU';
        if ($belgeSaklama === 'YOK') {
            $belgeLegacy = 'YOK';
        } elseif ($belgeSaklama === 'KURUMA_GONDER') {
            $belgeLegacy = 'ZORUNLU';
        } elseif (
            $belgeSaklama === 'ISVERENCE_SAKLA_TALEPTE_IBRAZ'
            || $belgeSaklama === 'ELEKTRONIK_KAYNAKTAN'
            || $belgeSaklama === 'KOSULLU'
        ) {
            $belgeLegacy = 'KOSULLU';
        } else {
            $belgeLegacy = 'KOSULLU';
            $warnings[] = 'LEGACY_BELGE_TEYITSIZ';
        }

        return [
            'sifir_gun_sifir_kazanc_kullanilabilir_mi' => $sifirLegacy,
            'aktif_mi' => $aktifLegacy,
            'belge_zorunlulugu' => $belgeLegacy,
            'warnings' => $warnings,
            'blockers' => $blockers,
        ];
    }

    /**
     * Detect conflict between explicit legacy booleans and canonical statuses.
     *
     * @param array<string,mixed> $row
     * @return list<string>
     */
    public static function assertLegacyCanonicalConsistency(array $row): array
    {
        $errors = [];
        if (
            array_key_exists('sifir_gun_sifir_kazanc_durumu', $row)
            && array_key_exists('sifir_gun_sifir_kazanc_kullanilabilir_mi', $row)
        ) {
            $proj = self::projectCanonicalToLegacy($row);
            $legacy = (bool) $row['sifir_gun_sifir_kazanc_kullanilabilir_mi'];
            $sifir = strtoupper((string) $row['sifir_gun_sifir_kazanc_durumu']);
            if (($sifir === 'KOSULLU' || $sifir === 'TEYITSIZ') && $legacy === true) {
                $errors[] = 'LEGACY_CANONICAL_CELISKI:sifir_gun';
            }
            if ($sifir === 'YASAK' && $legacy === true) {
                $errors[] = 'LEGACY_CANONICAL_CELISKI:sifir_gun';
            }
            if ($sifir === 'IZINLI' && $legacy === false) {
                $errors[] = 'LEGACY_CANONICAL_CELISKI:sifir_gun';
            }
            unset($proj);
        }

        if (array_key_exists('aktiflik_durumu', $row) && array_key_exists('aktif_mi', $row)) {
            $aktiflik = strtoupper((string) $row['aktiflik_durumu']);
            $aktif = (bool) $row['aktif_mi'];
            if ($aktiflik !== 'AKTIF' && $aktif === true) {
                $errors[] = 'LEGACY_CANONICAL_CELISKI:aktif_mi';
            }
            $portal = strtoupper((string) ($row['portal_teyit_durumu'] ?? ''));
            if ($aktiflik === 'AKTIF' && $portal === 'TEYIT_BEKLIYOR' && $aktif === true) {
                $errors[] = 'LEGACY_CANONICAL_CELISKI:portal_teyit';
            }
        }

        return $errors;
    }

    public static function blocker(string $code, string $message, string $cozum): array
    {
        return [
            'severity' => 'BLOCKER',
            'code' => $code,
            'message' => $message,
            'domain' => 'SGK_KATALOG',
            'cozum_onerisi' => $cozum,
        ];
    }

    public static function isSha256(?string $value): bool
    {
        return is_string($value) && (bool) preg_match('/^[0-9a-f]{64}$/', $value);
    }

    public static function isDate(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $dt !== false && $dt->format('Y-m-d') === $value;
    }
}
