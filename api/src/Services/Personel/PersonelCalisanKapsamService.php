<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Http\JsonResponse;
use PDO;

/**
 * Pack7F first-class workforce ownership owner.
 * IC_PERSONEL = MEDISA SGK/payroll. DIS_KAYNAK = directory-only.
 * Independent of personel_bordro_kapsamlari HARIC.
 */
final class PersonelCalisanKapsamService
{
    public const IC_PERSONEL = 'IC_PERSONEL';
    public const DIS_KAYNAK = 'DIS_KAYNAK';

    public const ERROR_OPERASYON = 'PERSONEL_OPERASYON_KAPSAM_DISI';
    public const ERROR_SGK_YASAK = 'DIS_KAYNAK_SGK_ISVEREN_YASAK';
    public const ERROR_SCHEMA = PersonelCalisanKapsamSchema::ERROR_CODE;

    /**
     * @param mixed $value
     */
    public static function normalize($value, $allowBlankAsIc = true): string
    {
        $raw = strtoupper(trim((string) $value));
        if ($raw === '' && $allowBlankAsIc) {
            return self::IC_PERSONEL;
        }
        if ($raw === self::IC_PERSONEL || $raw === self::DIS_KAYNAK) {
            return $raw;
        }
        throw new PersonelValidationException(
            'calisan_kapsami',
            'Calisan kapsami IC_PERSONEL veya DIS_KAYNAK olmalidir.'
        );
    }

    /**
     * Missing column / missing key / unexpected value → IC_PERSONEL (065-safe).
     *
     * @param array<string, mixed> $row
     */
    public static function resolveFromRow(array $row): string
    {
        if (!array_key_exists('calisan_kapsami', $row) || $row['calisan_kapsami'] === null || $row['calisan_kapsami'] === '') {
            return self::IC_PERSONEL;
        }
        $raw = strtoupper(trim((string) $row['calisan_kapsami']));
        if ($raw === self::DIS_KAYNAK) {
            return self::DIS_KAYNAK;
        }

        return self::IC_PERSONEL;
    }

    /**
     * Null-safe display name: ad + optional soyad. Never invents placeholders.
     *
     * @param mixed $ad
     * @param mixed $soyad
     */
    public static function formatAdSoyad($ad, $soyad): string
    {
        $adPart = trim((string) ($ad ?? ''));
        $soyadPart = trim((string) ($soyad ?? ''));
        if ($adPart === '') {
            return $soyadPart;
        }
        if ($soyadPart === '') {
            return $adPart;
        }

        return $adPart . ' ' . $soyadPart;
    }

    /**
     * SQL expression for null-safe ad+soyad (CONCAT_WS skips NULL parts).
     */
    public static function sqlAdSoyadExpr($alias = 'p'): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
        if ($safe === '') {
            $safe = 'p';
        }

        return "TRIM(CONCAT_WS(' ', {$safe}.ad, {$safe}.soyad))";
    }

    public static function isDisKaynak(PDO $pdo, $personelId): bool
    {
        $personelId = (int) $personelId;
        if ($personelId <= 0 || !PersonelCalisanKapsamSchema::isReady($pdo)) {
            return false;
        }
        $stmt = $pdo->prepare('SELECT calisan_kapsami FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }

        return self::resolveFromRow($row) === self::DIS_KAYNAK;
    }

    /**
     * SQL fragment: keep only IC_PERSONEL. 065 (column missing) → no extra predicate.
     */
    public static function sqlIcPersonelPredicate(PDO $pdo, $alias = 'p'): string
    {
        if (!PersonelCalisanKapsamSchema::isReady($pdo)) {
            return '1=1';
        }
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $alias);
        if ($safe === '') {
            $safe = 'p';
        }

        return $safe . ".calisan_kapsami = 'IC_PERSONEL'";
    }

    public static function assertOperationalEligible(PDO $pdo, $personelId): void
    {
        if (!self::isDisKaynak($pdo, $personelId)) {
            return;
        }
        JsonResponse::error(
            409,
            self::ERROR_OPERASYON,
            'Bu personel dizin kaydidir (DIS_KAYNAK); operasyonel islem yapilamaz.',
            'personel_id'
        );
    }

    public static function assertOperationalEligibleOrThrow(PDO $pdo, $personelId): void
    {
        if (!self::isDisKaynak($pdo, $personelId)) {
            return;
        }
        throw new PersonelValidationException(
            'personel_id',
            'Bu personel dizin kaydidir (DIS_KAYNAK); operasyonel islem yapilamaz.',
            self::ERROR_OPERASYON
        );
    }

    /**
     * @param mixed $sgkIsverenId
     */
    public static function assertSgkIsverenAllowed(string $kapsam, $sgkIsverenId): void
    {
        if ($kapsam !== self::DIS_KAYNAK) {
            return;
        }
        if ($sgkIsverenId === null || $sgkIsverenId === '') {
            return;
        }
        throw new PersonelValidationException(
            'sgk_isveren_id',
            'DIS_KAYNAK personeline PersonelMedisa SGK isvereni atanamaz.',
            self::ERROR_SGK_YASAK
        );
    }

    /**
     * Create/import contract: TC required for IC, optional-validated for DIS.
     *
     * @param array<string, mixed> $body
     * @return array{kapsam: string, tc: ?string}
     */
    public static function normalizeCreateIdentity(array $body): array
    {
        $kapsam = self::normalize($body['calisan_kapsami'] ?? self::IC_PERSONEL);
        $rawTc = array_key_exists('tc_kimlik_no', $body) ? trim((string) $body['tc_kimlik_no']) : '';
        if ($kapsam === self::IC_PERSONEL) {
            if ($rawTc === '') {
                throw new PersonelValidationException('tc_kimlik_no', 'T.C. Kimlik No zorunludur.');
            }
            if (!PersonelCanonicalValidator::isValidTcKimlikNo($rawTc)) {
                throw new PersonelValidationException('tc_kimlik_no', 'T.C. Kimlik No 11 hane olmalidir.');
            }

            return ['kapsam' => $kapsam, 'tc' => $rawTc];
        }
        if ($rawTc === '') {
            return ['kapsam' => $kapsam, 'tc' => null];
        }
        if (!PersonelCanonicalValidator::isValidTcKimlikNo($rawTc)) {
            throw new PersonelValidationException('tc_kimlik_no', 'T.C. Kimlik No 11 hane olmalidir.');
        }

        return ['kapsam' => $kapsam, 'tc' => $rawTc];
    }

    /**
     * Resulting IC_PERSONEL identity after update merge must satisfy full internal contract.
     *
     * @param array<string, mixed> $merged
     */
    public static function assertInternalIdentityComplete(array $merged): void
    {
        $tc = trim((string) ($merged['tc_kimlik_no'] ?? ''));
        if ($tc === '' || !PersonelCanonicalValidator::isValidTcKimlikNo($tc)) {
            throw new PersonelValidationException(
                'tc_kimlik_no',
                'Ic personel icin gecerli T.C. Kimlik No zorunludur.'
            );
        }
        if (trim((string) ($merged['soyad'] ?? '')) === '') {
            throw new PersonelValidationException('soyad', 'Ic personel icin soyad zorunludur.');
        }
        $dogum = trim((string) ($merged['dogum_tarihi'] ?? ''));
        if ($dogum === '' || !PersonelCanonicalValidator::isValidDateString($dogum)) {
            throw new PersonelValidationException('dogum_tarihi', 'Ic personel icin dogum tarihi zorunludur.');
        }
        if (trim((string) ($merged['telefon'] ?? '')) === '') {
            throw new PersonelValidationException('telefon', 'Ic personel icin telefon zorunludur.');
        }
    }
}
