<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Payroll;

/**
 * S85-C1: Shared deterministic helpers for SGK catalog readiness (no seed).
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
                'SGK_EKSIK_GUN_KODU_CAKISTI',
                '07-Puantaj kayitlari 0 gun / 0 kazanc bildirimlerinde kullanilamaz.',
                '0/0 bildirimde 07 disinda resmi kod kullanin veya bildirimi duzeltin.'
            );
        }

        return null;
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
