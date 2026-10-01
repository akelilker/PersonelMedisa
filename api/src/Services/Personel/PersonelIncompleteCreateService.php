<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

/**
 * Controlled incomplete personel create — explicit intent only (GENEL_YONETICI / SISTEM_YONETICISI).
 * Strict create path remains unchanged when intent flag is absent.
 */
final class PersonelIncompleteCreateService
{
    public const INTENT_FIELD = 'eksik_bilgi_ile_olustur';
    public const ERROR_FORBIDDEN = 'PERSONEL_INCOMPLETE_CREATE_FORBIDDEN';
    public const ERROR_INTENT_REQUIRED = 'PERSONEL_INCOMPLETE_CREATE_INTENT_REQUIRED';

    /** @var list<string> */
    public const AUTHORIZED_ROLES = ['GENEL_YONETICI', 'SISTEM_YONETICISI'];

    /**
     * @param array<string, mixed> $body
     */
    public static function hasIntent(array $body): bool
    {
        if (!array_key_exists(self::INTENT_FIELD, $body)) {
            return false;
        }
        $raw = $body[self::INTENT_FIELD];

        return $raw === true || $raw === 1 || $raw === '1' || $raw === 'true';
    }

    /**
     * @param array<string, mixed> $user
     */
    public static function assertAuthorized(array $user): void
    {
        $rol = strtoupper(trim((string) ($user['rol'] ?? '')));
        if (!in_array($rol, self::AUTHORIZED_ROLES, true)) {
            throw new PersonelValidationException(
                self::INTENT_FIELD,
                'Eksik bilgi ile personel olusturma yalniz yetkili yonetici rolleri icindir.',
                self::ERROR_FORBIDDEN
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function normalizePayload(array $body): array
    {
        if (!self::hasIntent($body)) {
            throw new PersonelValidationException(
                self::INTENT_FIELD,
                'Eksik bilgi ile olusturma icin eksik_bilgi_ile_olustur=true zorunludur.',
                self::ERROR_INTENT_REQUIRED
            );
        }

        $kapsam = PersonelCalisanKapsamService::normalize($body['calisan_kapsami'] ?? PersonelCalisanKapsamService::IC_PERSONEL);
        $ad = PersonelCanonicalValidator::requireTrimmedStringPublic($body, 'ad', 'Ad zorunludur.');
        $soyad = PersonelCanonicalValidator::requireTrimmedStringPublic($body, 'soyad', 'Soyad zorunludur.');
        $iseGirisTarihi = PersonelCanonicalValidator::requireValidDatePublic($body, 'ise_giris_tarihi', 'Ise giris tarihi zorunludur.');

        if (!array_key_exists('aktif_durum', $body)) {
            throw new PersonelValidationException('aktif_durum', 'Aktif durum zorunludur.');
        }
        $aktifDurum = PersonelCanonicalValidator::requireCreateAktifDurum($body['aktif_durum']);

        $payload = [
            'ad' => $ad,
            'soyad' => $soyad,
            'ise_giris_tarihi' => $iseGirisTarihi,
            'aktif_durum' => $aktifDurum,
            'calisan_kapsami' => $kapsam,
            'tc_kimlik_no' => self::optionalTc($body),
            'dogum_tarihi' => self::optionalDate($body, 'dogum_tarihi'),
            'telefon' => PersonelCanonicalValidator::optionalTrimmedStringPublic($body, 'telefon'),
            'acil_durum_kisi' => PersonelCanonicalValidator::optionalTrimmedStringPublic($body, 'acil_durum_kisi'),
            'acil_durum_telefon' => PersonelCanonicalValidator::optionalTrimmedStringPublic($body, 'acil_durum_telefon'),
            'sicil_no' => PersonelCanonicalValidator::optionalTrimmedStringPublic($body, 'sicil_no'),
            'sube_id' => PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'sube_id'),
            'departman_id' => PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'departman_id'),
            'gorev_id' => PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'gorev_id'),
            'personel_tipi_id' => PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'personel_tipi_id'),
            'bagli_amir_id' => PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'bagli_amir_id'),
            'dogum_yeri' => PersonelCanonicalValidator::optionalTrimmedStringPublic($body, 'dogum_yeri'),
            'kan_grubu' => PersonelCanonicalValidator::optionalTrimmedStringPublic($body, 'kan_grubu'),
            'ucret_tipi_id' => PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'ucret_tipi_id'),
            'prim_kurali_id' => PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'prim_kurali_id'),
            'maas_tutari' => null,
            self::INTENT_FIELD => true,
        ];

        if (array_key_exists('sgk_isveren_id', $body)) {
            $payload['sgk_isveren_id'] = PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'sgk_isveren_id');
        }
        if (array_key_exists('calisma_lokasyonu_id', $body)) {
            $payload['calisma_lokasyonu_id'] = PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'calisma_lokasyonu_id');
        }
        if (array_key_exists('bolum_id', $body)) {
            $payload['bolum_id'] = PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'bolum_id');
        }
        if (array_key_exists('birim_id', $body)) {
            $payload['birim_id'] = PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'birim_id');
        }
        if (array_key_exists('pozisyon_id', $body)) {
            $payload['pozisyon_id'] = PersonelCanonicalValidator::optionalPositiveIntPublic($body, 'pozisyon_id');
        }

        // SGK/bordro kaynağı ayrı eksen: DIS için farklı şirketin AKTİF SGK
        // işvereni geçerli olabilir, bu yüzden değer sıfırlanmaz.

        if ($payload['kan_grubu'] !== null && !in_array($payload['kan_grubu'], PersonelCanonicalValidator::validKanGruplari(), true)) {
            throw new PersonelValidationException('kan_grubu', 'Gecersiz kan grubu.');
        }

        if (array_key_exists('cinsiyet', $body)) {
            $cinsiyet = PersonelCanonicalValidator::optionalTrimmedStringPublic($body, 'cinsiyet');
            if ($cinsiyet !== null && !in_array($cinsiyet, PersonelCanonicalValidator::validCinsiyetValues(), true)) {
                throw new PersonelValidationException('cinsiyet', 'Gecersiz cinsiyet.');
            }
            $payload['cinsiyet'] = $cinsiyet;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalTc(array $body): ?string
    {
        if (!array_key_exists('tc_kimlik_no', $body)) {
            return null;
        }
        $raw = trim((string) $body['tc_kimlik_no']);
        if ($raw === '') {
            return null;
        }
        if (!PersonelCanonicalValidator::isValidTcKimlikNo($raw)) {
            throw new PersonelValidationException('tc_kimlik_no', 'T.C. Kimlik No 11 hane olmalidir.');
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalDate(array $body, string $field): ?string
    {
        if (!array_key_exists($field, $body)) {
            return null;
        }
        $raw = trim((string) $body[$field]);
        if ($raw === '') {
            return null;
        }
        $canonical = PersonelCanonicalValidator::normalizeDateToCanonical($raw);
        if ($canonical === null) {
            throw new PersonelValidationException($field, 'Gecerli bir tarih olmalidir.');
        }

        return $canonical;
    }
}
