<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use PDO;

/**
 * Information-only late/early evaluation against planned day times.
 * FINANCIAL_EFFECT = NONE. AUTOMATIC_PUANTAJ_EFFECT = NONE.
 */
class LateEarlyInfoService
{
    /** Configurable policy keys (minutes). Neutral default 0 = any positive delta informs. */
    public const DEFAULT_GEC_TOLERANS_DK = 0;
    public const DEFAULT_ERKEN_TOLERANS_DK = 0;

    /**
     * @param array<string, mixed>|null $gunlukPuantajRow expects beklenen_giris_saati / beklenen_cikis_saati HH:MM
     * @return array{kind:string,message:string,delta_dakika:int}|null
     */
    public static function evaluateAfterScan(
        $eventType,
        $occurredAtIsoOrUtc,
        array $gunlukPuantajRow = null,
        $gecToleransDk = null,
        $erkenToleransDk = null
    ) {
        $eventType = strtoupper(trim((string) $eventType));
        $gecToleransDk = $gecToleransDk === null ? self::DEFAULT_GEC_TOLERANS_DK : max(0, (int) $gecToleransDk);
        $erkenToleransDk = $erkenToleransDk === null ? self::DEFAULT_ERKEN_TOLERANS_DK : max(0, (int) $erkenToleransDk);

        $occurredLocal = self::toIstanbulMinutes($occurredAtIsoOrUtc);
        if ($occurredLocal === null) {
            return null;
        }

        if ($eventType === 'GIRIS') {
            $beklenen = self::hhmmToMinutes(
                is_array($gunlukPuantajRow) ? ($gunlukPuantajRow['beklenen_giris_saati'] ?? null) : null
            );
            if ($beklenen === null) {
                return null;
            }
            $delta = $occurredLocal - $beklenen;
            if ($delta <= $gecToleransDk) {
                return null;
            }

            return [
                'kind' => 'LATE_ENTRY_INFO',
                'message' => 'İşe ' . $delta . ' Dakika Geç Giriş Yaptınız.',
                'delta_dakika' => $delta,
            ];
        }

        if ($eventType === 'CIKIS') {
            $beklenen = self::hhmmToMinutes(
                is_array($gunlukPuantajRow) ? ($gunlukPuantajRow['beklenen_cikis_saati'] ?? null) : null
            );
            if ($beklenen === null) {
                return null;
            }
            $delta = $beklenen - $occurredLocal;
            if ($delta <= $erkenToleransDk) {
                return null;
            }

            return [
                'kind' => 'EARLY_EXIT_INFO',
                'message' => 'Planlanan çıkış saatinizden ' . $delta . ' dakika önce çıkış yaptınız.',
                'delta_dakika' => $delta,
            ];
        }

        return null;
    }

    /**
     * Load planned times for personel+business date from gunluk_puantaj when present.
     *
     * @return array<string, mixed>|null
     */
    public static function loadPlannedDay(PDO $pdo, $personelId, $businessDateYmd)
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT id, beklenen_giris_saati, beklenen_cikis_saati, giris_saati, cikis_saati
                 FROM gunluk_puantaj
                 WHERE personel_id = :pid AND tarih = :tarih
                 LIMIT 1'
            );
            $stmt->execute([
                'pid' => (int) $personelId,
                'tarih' => (string) $businessDateYmd,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return int|null minutes from midnight Istanbul */
    private static function toIstanbulMinutes($value)
    {
        try {
            $dt = new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
            $local = $dt->setTimezone(new \DateTimeZone('Europe/Istanbul'));

            return ((int) $local->format('H')) * 60 + (int) $local->format('i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return int|null */
    private static function hhmmToMinutes($value)
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', $raw, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            if ($h > 23 || $i > 59) {
                return null;
            }

            return $h * 60 + $i;
        }

        return null;
    }
}
