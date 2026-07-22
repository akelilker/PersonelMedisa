<?php

declare(strict_types=1);

namespace Medisa\Api\Services\PersonelBelge;

/**
 * S86: Canonical personel belge contracts (status, limits, allowlists).
 * Do not duplicate magic numbers in controllers or UI.
 */
final class PersonelBelgeContracts
{
    /** Days before expiry → SURESI_YAKLASIYOR (matches historical TS/PHP 30-day rule). */
    public const EXPIRY_WARNING_DAYS = 30;

    /** Decoded upload ceiling (10 MiB). Shared with FE PERSONEL_BELGE_MAX_DECODED_BYTES. */
    public const MAX_DECODED_BYTES = 10 * 1024 * 1024;

    public const STATUS_AKTIF = 'AKTIF';
    public const STATUS_SURESI_YAKLASIYOR = 'SURESI_YAKLASIYOR';
    public const STATUS_SURESI_DOLDU = 'SURESI_DOLDU';
    public const STATUS_IPTAL = 'IPTAL';
    public const STATUS_BELGE_DOSYASI_EKSIK = 'BELGE_DOSYASI_EKSIK';

    public const AUDIT_CREATED = 'CREATED';
    public const AUDIT_METADATA_UPDATED = 'METADATA_UPDATED';
    public const AUDIT_FILE_REPLACED = 'FILE_REPLACED';
    public const AUDIT_CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const KAYIT_TIPLERI = [
        'KIMLIK',
        'IS_SOZLESMESI',
        'DIPLOMA',
        'ADLI_SICIL',
        'SAGLIK_RAPORU',
        'IKAMETGAH',
        'SURUCU_BELGESI',
        'MESLEKI_YETERLILIK',
        'ISG_EGITIM',
        'EGITIM',
        'SERTIFIKA',
        'EHLIYET',
        'YETKINLIK',
        'DIGER',
    ];

    /** @var array<string, string> */
    public const KAYIT_TIPI_LABELS = [
        'KIMLIK' => 'Kimlik',
        'IS_SOZLESMESI' => 'Is sozlesmesi',
        'DIPLOMA' => 'Diploma / ogrenim belgesi',
        'ADLI_SICIL' => 'Adli sicil',
        'SAGLIK_RAPORU' => 'Saglik raporu',
        'IKAMETGAH' => 'Ikametgah',
        'SURUCU_BELGESI' => 'Surucu belgesi',
        'MESLEKI_YETERLILIK' => 'Mesleki yeterlilik',
        'ISG_EGITIM' => 'ISG / egitim sertifikasi',
        'EGITIM' => 'Egitim',
        'SERTIFIKA' => 'Sertifika',
        'EHLIYET' => 'Ehliyet',
        'YETKINLIK' => 'Yetkinlik',
        'DIGER' => 'Diger',
    ];

    /** @var array<string, list<string>> extension => mime allowlist */
    public const ALLOWED_EXTENSIONS = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];

    /** @var list<string> */
    public const BLOCKED_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'phar',
        'html', 'htm', 'shtml', 'svg', 'js', 'mjs', 'jsx', 'ts', 'tsx',
        'exe', 'bat', 'cmd', 'sh', 'ps1', 'cgi', 'pl', 'py', 'rb',
        'asp', 'aspx', 'jsp', 'war', 'jar', 'dll', 'so',
    ];

    /**
     * Deterministic takip durumu from lifecycle + expiry + active file presence.
     */
    public static function deriveTakipDurumu(
        string $lifecycleState,
        ?string $bitisTarihi,
        bool $hasActiveFile,
        ?string $referenceDateYmd = null
    ): string {
        if (strtoupper($lifecycleState) === 'IPTAL') {
            return self::STATUS_IPTAL;
        }
        if (!$hasActiveFile) {
            return self::STATUS_BELGE_DOSYASI_EKSIK;
        }

        $gecerlilik = self::computeGecerlilikDurumu($bitisTarihi, $referenceDateYmd);
        if ($gecerlilik === 'SURESI_DOLMUS') {
            return self::STATUS_SURESI_DOLDU;
        }
        if ($gecerlilik === 'YAKINDA_DOLUYOR') {
            return self::STATUS_SURESI_YAKLASIYOR;
        }

        return self::STATUS_AKTIF;
    }

    public static function computeGecerlilikDurumu(?string $bitisTarihi, ?string $referenceDateYmd = null): string
    {
        if ($bitisTarihi === null || trim($bitisTarihi) === '' || !self::isValidDate($bitisTarihi)) {
            return 'GECERLI';
        }

        $ref = $referenceDateYmd !== null && self::isValidDate($referenceDateYmd)
            ? $referenceDateYmd
            : gmdate('Y-m-d');

        $today = strtotime($ref . ' 00:00:00 UTC');
        $bitis = strtotime($bitisTarihi . ' 00:00:00 UTC');
        if ($today === false || $bitis === false) {
            return 'GECERLI';
        }

        $diffDays = (int) round(($bitis - $today) / 86400);
        if ($diffDays < 0) {
            return 'SURESI_DOLMUS';
        }
        if ($diffDays <= self::EXPIRY_WARNING_DAYS) {
            return 'YAKINDA_DOLUYOR';
        }

        return 'GECERLI';
    }

    public static function isValidKayitTipi(string $tip): bool
    {
        return in_array(strtoupper($tip), self::KAYIT_TIPLERI, true);
    }

    public static function isValidDate(?string $value): bool
    {
        if ($value === null || $value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }

    public static function maxEncodedLength(): int
    {
        return 4 * (int) ceil(self::MAX_DECODED_BYTES / 3);
    }

    /**
     * @return array{ok:true,extension:string,mime:string}|array{ok:false,code:string,message:string}
     */
    public static function validateFilenameAndMime(string $originalName, string $claimedMime): array
    {
        $name = trim($originalName);
        if ($name === '' || strpos($name, "\0") !== false) {
            return ['ok' => false, 'code' => 'PERSONEL_BELGE_DOSYA_ADI_GECERSIZ', 'message' => 'Dosya adi gecersiz.'];
        }
        if (preg_match('#[\\\\/]#', $name)) {
            return ['ok' => false, 'code' => 'PERSONEL_BELGE_PATH_GECERSIZ', 'message' => 'Dosya adi path iceremez.'];
        }

        $lower = strtolower($name);
        $parts = explode('.', $lower);
        if (count($parts) < 2) {
            return ['ok' => false, 'code' => 'PERSONEL_BELGE_UZANTI_GECERSIZ', 'message' => 'Dosya uzantisi zorunludur.'];
        }

        // Double extension: any blocked segment before final extension.
        for ($i = 1; $i < count($parts) - 1; $i++) {
            if (in_array($parts[$i], self::BLOCKED_EXTENSIONS, true)) {
                return ['ok' => false, 'code' => 'PERSONEL_BELGE_UZANTI_ENGELLENDI', 'message' => 'Calistirilabilir veya guvenli olmayan dosya tipi reddedildi.'];
            }
        }

        $ext = $parts[count($parts) - 1];
        if (in_array($ext, self::BLOCKED_EXTENSIONS, true) || !isset(self::ALLOWED_EXTENSIONS[$ext])) {
            return ['ok' => false, 'code' => 'PERSONEL_BELGE_UZANTI_ENGELLENDI', 'message' => 'Bu dosya tipi yuklenemez. Izinli: PDF, PNG, JPG, WEBP, DOC, DOCX.'];
        }

        $mime = strtolower(trim($claimedMime));
        if ($mime === '') {
            $mime = self::ALLOWED_EXTENSIONS[$ext][0];
        }
        if (!in_array($mime, self::ALLOWED_EXTENSIONS[$ext], true)) {
            return ['ok' => false, 'code' => 'PERSONEL_BELGE_MIME_UYUSMUYOR', 'message' => 'Dosya uzantisi ile MIME tipi uyusmuyor.'];
        }

        return ['ok' => true, 'extension' => $ext, 'mime' => $mime];
    }

    /**
     * Light magic-byte checks for known types.
     */
    public static function validateContentMagic(string $bytes, string $extension): bool
    {
        $len = strlen($bytes);
        if ($len < 4) {
            return false;
        }

        if ($extension === 'pdf') {
            return substr($bytes, 0, 5) === '%PDF-';
        }
        if ($extension === 'png') {
            return substr($bytes, 0, 8) === "\x89PNG\r\n\x1a\n";
        }
        if ($extension === 'jpg' || $extension === 'jpeg') {
            return substr($bytes, 0, 3) === "\xFF\xD8\xFF";
        }
        if ($extension === 'webp') {
            return substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP';
        }
        if ($extension === 'doc') {
            return substr($bytes, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        }
        if ($extension === 'docx') {
            return substr($bytes, 0, 2) === 'PK';
        }

        return false;
    }

    public static function maskBelgeNo(?string $belgeNo): ?string
    {
        if ($belgeNo === null) {
            return null;
        }
        $value = trim($belgeNo);
        if ($value === '') {
            return null;
        }
        $len = mb_strlen($value, 'UTF-8');
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', max(0, $len - 4)) . mb_substr($value, -4, null, 'UTF-8');
    }
}
