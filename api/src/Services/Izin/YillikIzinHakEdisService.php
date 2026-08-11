<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Izin;

/**
 * FE `izin-hesap-motoru.ts` hak ediş motorunun PHP portu.
 * LEGAL_ENTITLEMENT_SEMANTIC = current service-year band (yillik_izin_gun), NOT cumulative.
 */
class YillikIzinHakEdisService
{
    /**
     * @param array{ise_giris_tarihi:string, dogum_tarihi?:string|null, referans_tarih?:string|null} $girdi
     * @return array{kidem_yil:int, yas:int|null, yillik_izin_gun:int, yas_istisna_uygulandi:bool}
     */
    public static function hesaplaIzinHakEdis(array $girdi)
    {
        $iseGiris = isset($girdi['ise_giris_tarihi']) ? (string) $girdi['ise_giris_tarihi'] : '';
        $dogum = isset($girdi['dogum_tarihi']) && $girdi['dogum_tarihi'] !== null && $girdi['dogum_tarihi'] !== ''
            ? (string) $girdi['dogum_tarihi']
            : null;
        $ref = isset($girdi['referans_tarih']) && $girdi['referans_tarih'] !== null && $girdi['referans_tarih'] !== ''
            ? (string) $girdi['referans_tarih']
            : null;

        $kidemYil = self::hesaplaKidemYil($iseGiris, $ref);
        $yas = $dogum !== null ? self::hesaplaYas($dogum, $ref) : null;
        $gunSonuc = self::hesaplaYillikIzinGun([
            'ise_giris_tarihi' => $iseGiris,
            'dogum_tarihi' => $dogum,
            'referans_tarih' => $ref,
        ]);

        return [
            'kidem_yil' => $kidemYil,
            'yas' => $yas,
            'yillik_izin_gun' => (int) $gunSonuc['gun'],
            'yas_istisna_uygulandi' => (bool) $gunSonuc['yas_istisna_uygulandi'],
        ];
    }

    /**
     * @param array{ise_giris_tarihi:string, dogum_tarihi?:string|null, referans_tarih?:string|null} $girdi
     * @return array{gun:int, yas_istisna_uygulandi:bool}
     */
    public static function hesaplaYillikIzinGun(array $girdi)
    {
        $iseGiris = isset($girdi['ise_giris_tarihi']) ? (string) $girdi['ise_giris_tarihi'] : '';
        $giris = self::parseDate($iseGiris);
        if ($giris === null) {
            return ['gun' => 0, 'yas_istisna_uygulandi' => false];
        }

        $refRaw = isset($girdi['referans_tarih']) && $girdi['referans_tarih'] !== null && $girdi['referans_tarih'] !== ''
            ? (string) $girdi['referans_tarih']
            : null;
        $ref = $refRaw !== null ? (self::parseDate($refRaw) ?: self::today()) : self::today();
        if ($ref < $giris) {
            return ['gun' => 0, 'yas_istisna_uygulandi' => false];
        }

        $kidemYil = self::hesaplaKidemYil($iseGiris, $refRaw);
        $dogum = isset($girdi['dogum_tarihi']) && $girdi['dogum_tarihi'] !== null && $girdi['dogum_tarihi'] !== ''
            ? (string) $girdi['dogum_tarihi']
            : null;
        $yas = $dogum !== null ? self::hesaplaYas($dogum, $refRaw) : null;

        if ($kidemYil < 1) {
            return ['gun' => 0, 'yas_istisna_uygulandi' => false];
        }

        // 5-year boundary quirk (FE parity): exact anniversary stays at 14; day-after → 20.
        if ($kidemYil >= 15) {
            $gun = 26;
        } elseif (
            $kidemYil > 5
            || (
                $kidemYil === 5
                && (
                    (int) $ref->format('n') !== (int) $giris->format('n')
                    || (int) $ref->format('j') !== (int) $giris->format('j')
                )
            )
        ) {
            $gun = 20;
        } else {
            $gun = 14;
        }

        $yasIstisna = false;
        if (self::isYillikIzinYasIstisnasiKapsaminda($yas) && $gun < 20) {
            $gun = 20;
            $yasIstisna = true;
        }

        return ['gun' => $gun, 'yas_istisna_uygulandi' => $yasIstisna];
    }

    /** @return int */
    public static function hesaplaKidemYil($iseGirisTarihi, $referansTarih = null)
    {
        $giris = self::parseDate((string) $iseGirisTarihi);
        if ($giris === null) {
            return 0;
        }

        $ref = $referansTarih !== null && (string) $referansTarih !== ''
            ? (self::parseDate((string) $referansTarih) ?: self::today())
            : self::today();

        if ($ref < $giris) {
            return 0;
        }

        $diffYil = (int) $ref->format('Y') - (int) $giris->format('Y');
        $ayFark = (int) $ref->format('n') - (int) $giris->format('n');
        $gunFark = (int) $ref->format('j') - (int) $giris->format('j');

        if ($ayFark < 0 || ($ayFark === 0 && $gunFark < 0)) {
            return max($diffYil - 1, 0);
        }

        return $diffYil;
    }

    /** @return int|null */
    public static function hesaplaYas($dogumTarihi, $referansTarih = null)
    {
        $dogum = self::parseDate((string) $dogumTarihi);
        if ($dogum === null) {
            return null;
        }

        $ref = $referansTarih !== null && (string) $referansTarih !== ''
            ? (self::parseDate((string) $referansTarih) ?: self::today())
            : self::today();

        $diffYil = (int) $ref->format('Y') - (int) $dogum->format('Y');
        $ayFark = (int) $ref->format('n') - (int) $dogum->format('n');
        $gunFark = (int) $ref->format('j') - (int) $dogum->format('j');

        if ($ayFark < 0 || ($ayFark === 0 && $gunFark < 0)) {
            return max($diffYil - 1, 0);
        }

        return $diffYil;
    }

    /** @param int|null $yas */
    public static function isYillikIzinYasIstisnasiKapsaminda($yas)
    {
        return $yas !== null && ((int) $yas <= 18 || (int) $yas >= 50);
    }

    /** @return \DateTimeImmutable|null */
    private static function parseDate($value)
    {
        $value = trim((string) $value);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($dt === false) {
            return null;
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
            return null;
        }

        return $dt;
    }

    /** @return \DateTimeImmutable */
    private static function today()
    {
        $now = new \DateTimeImmutable('now');

        return $now->setTime(0, 0, 0);
    }
}
