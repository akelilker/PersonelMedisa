<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Izin;

use Medisa\Api\Services\Payroll\SirketCalismaPolitikasiCatalog;
use Medisa\Api\Services\ResmiTatilTakvimiService;
use Medisa\Api\Services\SirketCalismaPolitikasiService;
use PDO;

/**
 * Yıllık izin kullanımı: surecler (IZIN + YILLIK_IZIN, non-IPTAL) + gün tipi sınıflandırma.
 * Fail-closed: sınıflandırılamayan tarih varsa kullanilan_gun = null.
 */
class YillikIzinKullanimService
{
    /**
     * @return array{
     *   kullanilan_gun:int|null,
     *   sayilan_normal_gun:int,
     *   haric_tutulan_hafta_tatili_gun:int,
     *   haric_tutulan_ubgt_gun:int,
     *   takvim_dogrulandi_mi:bool,
     *   eksik_takvim_tarihleri:array<int,string>
     * }
     */
    public static function computeForPersonel(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        $surecler = self::fetchYillikIzinSurecler($pdo, $personelId);
        $tarihler = self::collectInclusiveDates($surecler);
        if (count($tarihler) === 0) {
            return self::emptyOzet(true);
        }

        $puantajMap = self::fetchPuantajGunTipiMap($pdo, $personelId, $tarihler);
        $haftaTatiliDays = self::resolveHaftaTatiliWeekdays($pdo, $tarihler);

        return self::classifyDates($tarihler, $puantajMap, $pdo, $haftaTatiliDays);
    }

    /**
     * @param array<int, array<string, mixed>> $surecler
     * @param array<string, string> $canonicalGunTipiByDate
     * @return array{
     *   kullanilan_gun:int|null,
     *   sayilan_normal_gun:int,
     *   haric_tutulan_hafta_tatili_gun:int,
     *   haric_tutulan_ubgt_gun:int,
     *   takvim_dogrulandi_mi:bool,
     *   eksik_takvim_tarihleri:array<int,string>
     * }
     */
    public static function classifyFromSurecler(array $surecler, array $canonicalGunTipiByDate = [])
    {
        $tarihler = self::collectInclusiveDates($surecler);
        if (count($tarihler) === 0) {
            return self::emptyOzet(true);
        }

        $sayilanNormal = 0;
        $haricHt = 0;
        $haricUbgt = 0;
        $eksik = [];

        foreach ($tarihler as $tarih) {
            $gunTipi = isset($canonicalGunTipiByDate[$tarih]) ? (string) $canonicalGunTipiByDate[$tarih] : '';
            if ($gunTipi === '') {
                $eksik[] = $tarih;
                continue;
            }
            if ($gunTipi === 'Hafta_Tatili_Pazar') {
                $haricHt += 1;
            } elseif ($gunTipi === 'UBGT_Resmi_Tatil') {
                $haricUbgt += 1;
            } else {
                $sayilanNormal += 1;
            }
        }

        $eksik = array_values(array_unique($eksik));
        sort($eksik);
        $ok = count($eksik) === 0;

        return [
            'kullanilan_gun' => $ok ? $sayilanNormal : null,
            'sayilan_normal_gun' => $sayilanNormal,
            'haric_tutulan_hafta_tatili_gun' => $haricHt,
            'haric_tutulan_ubgt_gun' => $haricUbgt,
            'takvim_dogrulandi_mi' => $ok,
            'eksik_takvim_tarihleri' => $eksik,
        ];
    }

    /**
     * @param array<int, string> $tarihler
     * @param array<string, string> $puantajMap
     * @param array<int, int>|null $haftaTatiliDays
     * @return array{
     *   kullanilan_gun:int|null,
     *   sayilan_normal_gun:int,
     *   haric_tutulan_hafta_tatili_gun:int,
     *   haric_tutulan_ubgt_gun:int,
     *   takvim_dogrulandi_mi:bool,
     *   eksik_takvim_tarihleri:array<int,string>
     * }
     */
    private static function classifyDates(array $tarihler, array $puantajMap, PDO $pdo, $haftaTatiliDays)
    {
        $sayilanNormal = 0;
        $haricHt = 0;
        $haricUbgt = 0;
        $eksik = [];

        foreach ($tarihler as $tarih) {
            $gunTipi = self::resolveGunTipi($pdo, $tarih, $puantajMap, $haftaTatiliDays);
            if ($gunTipi === null) {
                $eksik[] = $tarih;
                continue;
            }
            if ($gunTipi === 'Hafta_Tatili_Pazar') {
                $haricHt += 1;
            } elseif ($gunTipi === 'UBGT_Resmi_Tatil') {
                $haricUbgt += 1;
            } else {
                $sayilanNormal += 1;
            }
        }

        $eksik = array_values(array_unique($eksik));
        sort($eksik);
        $ok = count($eksik) === 0;

        return [
            'kullanilan_gun' => $ok ? $sayilanNormal : null,
            'sayilan_normal_gun' => $sayilanNormal,
            'haric_tutulan_hafta_tatili_gun' => $haricHt,
            'haric_tutulan_ubgt_gun' => $haricUbgt,
            'takvim_dogrulandi_mi' => $ok,
            'eksik_takvim_tarihleri' => $eksik,
        ];
    }

    /**
     * @param array<string, string> $puantajMap
     * @param array<int, int>|null $haftaTatiliDays
     * @return string|null
     */
    private static function resolveGunTipi(PDO $pdo, $tarih, array $puantajMap, $haftaTatiliDays)
    {
        if (isset($puantajMap[$tarih]) && $puantajMap[$tarih] !== '') {
            return (string) $puantajMap[$tarih];
        }

        try {
            $ubgt = ResmiTatilTakvimiService::resolveActiveForDate($pdo, $tarih, 'UBGT');
            if (is_array($ubgt)) {
                return 'UBGT_Resmi_Tatil';
            }
        } catch (\Throwable $e) {
            // Fail closed below if no other classifier.
        }

        if (!is_array($haftaTatiliDays)) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $tarih);
        if ($dt === false) {
            return null;
        }
        $weekday = (int) $dt->format('w'); // 0=Sun … 6=Sat
        if (in_array($weekday, $haftaTatiliDays, true)) {
            return 'Hafta_Tatili_Pazar';
        }

        // Politika mevcut → kalan günler iş günü.
        return 'Normal_Is_Gunu';
    }

    /**
     * @param array<int, string> $tarihler
     * @return array<int, int>|null null = politika yok / çözülemedi → fail-closed for non-puantaj days
     */
    private static function resolveHaftaTatiliWeekdays(PDO $pdo, array $tarihler)
    {
        sort($tarihler);
        $bas = $tarihler[0];
        $bit = $tarihler[count($tarihler) - 1];

        try {
            $resolved = SirketCalismaPolitikasiService::resolveApprovedForPeriod($pdo, $bas, $bit);
        } catch (\Throwable $e) {
            return null;
        }

        if (!is_array($resolved['politika'] ?? null)) {
            return null;
        }

        $byCode = isset($resolved['degerler_by_code']) && is_array($resolved['degerler_by_code'])
            ? $resolved['degerler_by_code']
            : [];
        $raw = '';
        if (isset($byCode['HAFTA_TATILI_GUNLERI'])) {
            $row = $byCode['HAFTA_TATILI_GUNLERI'];
            $raw = (string) ($row['metin_deger'] ?? '');
            if ($raw === '' && isset($row['sayisal_deger'])) {
                $raw = (string) $row['sayisal_deger'];
            }
        }
        if ($raw === '') {
            $raw = SirketCalismaPolitikasiCatalog::LEGACY_HAFTA_TATILI_GUNLERI;
        }

        $parsed = SirketCalismaPolitikasiCatalog::parseHaftaTatiliGunleri($raw);
        if (!($parsed['ok'] ?? false) || !isset($parsed['days']) || !is_array($parsed['days'])) {
            return null;
        }

        return array_map('intval', $parsed['days']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchYillikIzinSurecler(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            "SELECT id, personel_id, surec_turu, alt_tur, baslangic_tarihi, bitis_tarihi, state
             FROM surecler
             WHERE personel_id = :pid
               AND surec_turu = 'IZIN'
               AND state <> 'IPTAL'
               AND (alt_tur IS NULL OR alt_tur = '' OR alt_tur = 'YILLIK_IZIN')
             ORDER BY baslangic_tarihi ASC, id ASC"
        );
        $stmt->execute(['pid' => (int) $personelId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int, array<string, mixed>> $surecler
     * @return array<int, string>
     */
    private static function collectInclusiveDates(array $surecler)
    {
        $set = [];
        foreach ($surecler as $surec) {
            if (strtoupper((string) ($surec['surec_turu'] ?? '')) !== 'IZIN') {
                continue;
            }
            $altTur = isset($surec['alt_tur']) ? trim((string) $surec['alt_tur']) : '';
            if ($altTur !== '' && strtoupper($altTur) !== 'YILLIK_IZIN') {
                continue;
            }
            if (strtoupper((string) ($surec['state'] ?? '')) === 'IPTAL') {
                continue;
            }

            $bas = isset($surec['baslangic_tarihi']) ? self::parseDateKey((string) $surec['baslangic_tarihi']) : null;
            if ($bas === null) {
                continue;
            }
            $bitRaw = isset($surec['bitis_tarihi']) ? (string) $surec['bitis_tarihi'] : '';
            $bit = $bitRaw !== '' ? self::parseDateKey($bitRaw) : $bas;
            if ($bit === null) {
                $bit = $bas;
            }
            foreach (self::listInclusiveDateKeys($bas, $bit) as $tarih) {
                $set[$tarih] = true;
            }
        }
        $out = array_keys($set);
        sort($out);

        return $out;
    }

    /**
     * @param array<int, string> $tarihler
     * @return array<string, string>
     */
    private static function fetchPuantajGunTipiMap(PDO $pdo, $personelId, array $tarihler)
    {
        if (count($tarihler) === 0) {
            return [];
        }

        $placeholders = [];
        $params = ['pid' => (int) $personelId];
        foreach ($tarihler as $i => $tarih) {
            $key = 't' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $tarih;
        }

        $sql = 'SELECT tarih, gun_tipi FROM gunluk_puantaj
                WHERE personel_id = :pid AND tarih IN (' . implode(', ', $placeholders) . ')';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $tarih = (string) ($row['tarih'] ?? '');
            $gunTipi = trim((string) ($row['gun_tipi'] ?? ''));
            if ($tarih === '' || $gunTipi === '') {
                continue;
            }
            if (!isset($map[$tarih])) {
                $map[$tarih] = $gunTipi;
                continue;
            }
            // Çakışan farklı tipler → sınıflandırılamaz (fail-closed).
            if ($map[$tarih] !== $gunTipi) {
                $map[$tarih] = '';
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private static function listInclusiveDateKeys($baslangic, $bitis)
    {
        $bas = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $baslangic);
        $bit = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $bitis);
        if ($bas === false || $bit === false || $bit < $bas) {
            return [];
        }

        $out = [];
        $cursor = $bas;
        while ($cursor <= $bit) {
            $out[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $out;
    }

    /** @return string|null */
    private static function parseDateKey($value)
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) !== 1) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);
        if ($dt === false) {
            return null;
        }

        return $dt->format('Y-m-d');
    }

    /**
     * @return array{
     *   kullanilan_gun:int|null,
     *   sayilan_normal_gun:int,
     *   haric_tutulan_hafta_tatili_gun:int,
     *   haric_tutulan_ubgt_gun:int,
     *   takvim_dogrulandi_mi:bool,
     *   eksik_takvim_tarihleri:array<int,string>
     * }
     */
    private static function emptyOzet($ok)
    {
        return [
            'kullanilan_gun' => $ok ? 0 : null,
            'sayilan_normal_gun' => 0,
            'haric_tutulan_hafta_tatili_gun' => 0,
            'haric_tutulan_ubgt_gun' => 0,
            'takvim_dogrulandi_mi' => (bool) $ok,
            'eksik_takvim_tarihleri' => [],
        ];
    }
}
