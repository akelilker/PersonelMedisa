<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Attendance;

use PDO;

/**
 * Information-only late/early evaluation against planned day times.
 * FINANCIAL_EFFECT = NONE. AUTOMATIC_PUANTAJ_EFFECT = NONE.
 *
 * PERSONEL UX policy (tolerance): late entry and early-exit post-info only after 30 minutes.
 * Early exit always requires pre-write confirmation when any minute early (tolerance does not waive confirm).
 * Early arrival and late exit never produce warnings.
 */
class LateEarlyInfoService
{
    public const DEFAULT_GEC_TOLERANS_DK = 30;
    public const DEFAULT_ERKEN_TOLERANS_DK = 30;

    /**
     * @param array<string, mixed>|null $gunlukPuantajRow expects beklenen_giris_saati / beklenen_cikis_saati HH:MM
     * @return array{kind:string,message:string,delta_dakika:int,card_label:?string,notification_title:string,notification_body:string}|null
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
            // Early arrival or within tolerance → no info.
            if ($delta <= $gecToleransDk) {
                return null;
            }

            $human = self::formatDurationHuman($delta);

            return [
                'kind' => 'LATE_ENTRY_INFO',
                'message' => $human . ' Geç Geldiniz, Amirinizle Görüşün.',
                'delta_dakika' => $delta,
                'card_label' => $human . ' Gecikme',
                'notification_title' => 'Geç Giriş',
                'notification_body' => $human . " Geç Geldiniz, Amirinizle Görüşün.\nÜcret Kesintisi Durumunu Amirinizle Görüşün.",
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
            // Late exit or within early tolerance → no post-info.
            if ($delta <= $erkenToleransDk) {
                return null;
            }

            $human = self::formatDurationHuman($delta);

            return [
                'kind' => 'EARLY_EXIT_INFO',
                'message' => 'Normal Mesai Bitiminden ' . $human . ' Önce Çıkış Yaptınız.',
                'delta_dakika' => $delta,
                'card_label' => 'Normal Mesai Bitiminden ' . $human . ' Önce Çıkış Yaptınız.',
                'notification_title' => 'Erken Çıkış',
                'notification_body' => 'Normal Mesai Bitiminden ' . $human . " Önce Çıkış Yaptınız.\nÜcret Kesintisi Durumunu Amirinizle Görüşün.",
            ];
        }

        return null;
    }

    /**
     * Pre-write early-exit confirmation. Any minute before planned exit requires confirm.
     * 30-minute tolerance does NOT waive this gate.
     *
     * @param array<string, mixed>|null $gunlukPuantajRow
     * @return array{kind:string,message:string,delta_dakika:int}|null
     */
    public static function evaluateEarlyExitConfirmation(
        $occurredAtIsoOrUtc,
        array $gunlukPuantajRow = null
    ) {
        $occurredLocal = self::toIstanbulMinutes($occurredAtIsoOrUtc);
        if ($occurredLocal === null) {
            return null;
        }
        $beklenen = self::hhmmToMinutes(
            is_array($gunlukPuantajRow) ? ($gunlukPuantajRow['beklenen_cikis_saati'] ?? null) : null
        );
        if ($beklenen === null) {
            return null;
        }
        $delta = $beklenen - $occurredLocal;
        if ($delta < 1) {
            return null;
        }

        $human = self::formatDurationHuman($delta);
        $message = $delta >= 60
            ? ('Çıkış Saatinize ' . $human . ' Var. Emin Misiniz?')
            : ('Çıkış Saatine ' . $human . ' Var. Emin Misiniz?');

        return [
            'kind' => 'EARLY_EXIT_CONFIRMATION_REQUIRED',
            'message' => $message,
            'delta_dakika' => $delta,
        ];
    }

    /**
     * Compact duration for personel UX: "31dk", "1 Saat 5dk", "4 Saat 12dk".
     * "dk" stays lowercase; remaining words use Title Case per product copy.
     */
    public static function formatDurationHuman($minutes)
    {
        $minutes = max(0, (int) $minutes);
        if ($minutes < 60) {
            return $minutes . 'dk';
        }
        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;
        if ($rem === 0) {
            return $hours . ' Saat';
        }

        return $hours . ' Saat ' . $rem . 'dk';
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

    /**
     * Most recent non-empty beklenen_cikis_saati on or before $businessDateYmd.
     * Used only as schedule template for correction deadline — never invents 08:30/17:40.
     *
     * @return string|null HH:MM
     */
    public static function findRecentBeklenenCikis(PDO $pdo, $personelId, $businessDateYmd)
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT beklenen_cikis_saati
                 FROM gunluk_puantaj
                 WHERE personel_id = :pid
                   AND tarih <= :tarih
                   AND beklenen_cikis_saati IS NOT NULL
                   AND TRIM(beklenen_cikis_saati) <> ''
                 ORDER BY tarih DESC
                 LIMIT 1"
            );
            $stmt->execute([
                'pid' => (int) $personelId,
                'tarih' => (string) $businessDateYmd,
            ]);
            $val = $stmt->fetchColumn();
            if ($val === false || $val === null) {
                return null;
            }
            $minutes = self::hhmmToMinutes($val);
            if ($minutes === null) {
                return null;
            }

            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return int|null minutes from midnight Istanbul */
    public static function toIstanbulMinutes($value)
    {
        try {
            $raw = (string) $value;
            // Accept UTC microtime strings and ISO8601 with offset.
            if (preg_match('/[zZ]|[+\-]\d{2}:?\d{2}$/', $raw) || strpos($raw, 'T') !== false) {
                $dt = new \DateTimeImmutable($raw);
            } else {
                $dt = new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
            }
            $local = $dt->setTimezone(new \DateTimeZone('Europe/Istanbul'));

            return ((int) $local->format('H')) * 60 + (int) $local->format('i');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @return int|null */
    public static function hhmmToMinutes($value)
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
