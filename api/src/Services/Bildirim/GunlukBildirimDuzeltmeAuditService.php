<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Bildirim;

use PDO;

/**
 * Append-only old→new audit for gunluk_bildirim business field transitions.
 * Write owner for content update / iptal; request-correction is not a transition.
 */
class GunlukBildirimDuzeltmeAuditService
{
    public const OLAY_DUZELTME = 'DUZELTME';
    public const OLAY_IPTAL = 'IPTAL';

    /** @var array<int, string> */
    private static $businessKeys = [
        'bildirim_turu',
        'alt_tur',
        'baslangic_saati',
        'bitis_saati',
        'dakika',
        'aciklama',
    ];

    public static function hasTable(PDO $pdo)
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'gunluk_bildirim_duzeltme_auditleri'");
            if ($stmt && $stmt->fetch()) {
                return true;
            }
        } catch (\Throwable $e) {
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :n LIMIT 1"
            );
            $stmt->execute(['n' => 'gunluk_bildirim_duzeltme_auditleri']);

            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function businessSnapshot(array $row)
    {
        return [
            'bildirim_turu' => isset($row['bildirim_turu']) ? (string) $row['bildirim_turu'] : '',
            'alt_tur' => self::nullableString(isset($row['alt_tur']) ? $row['alt_tur'] : null),
            'baslangic_saati' => self::nullableString(isset($row['baslangic_saati']) ? $row['baslangic_saati'] : null),
            'bitis_saati' => self::nullableString(isset($row['bitis_saati']) ? $row['bitis_saati'] : null),
            'dakika' => self::nullableInt(isset($row['dakika']) ? $row['dakika'] : null),
            'aciklama' => self::nullableString(isset($row['aciklama']) ? $row['aciklama'] : null),
            'state' => isset($row['state']) ? (string) $row['state'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function businessFieldsChanged(array $before, array $after)
    {
        foreach (self::$businessKeys as $key) {
            $left = array_key_exists($key, $before) ? $before[$key] : null;
            $right = array_key_exists($key, $after) ? $after[$key] : null;
            if (self::normalizeComparable($left) !== self::normalizeComparable($right)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Must be called inside an open DB transaction owned by the mutation caller.
     *
     * @param array<string, mixed> $existingRow
     * @param array<string, mixed> $nextBusiness  keys: bildirim_turu, alt_tur, baslangic_saati, bitis_saati, dakika, aciklama [, state]
     * @return int inserted audit id
     */
    public static function appendInTransaction(
        PDO $pdo,
        array $existingRow,
        array $nextBusiness,
        $olayTipi,
        $actorUserId,
        $correctionReason = null
    ) {
        if (!self::hasTable($pdo)) {
            throw new \RuntimeException('gunluk_bildirim_duzeltme_auditleri missing');
        }

        $eski = self::businessSnapshot($existingRow);
        $yeni = self::businessSnapshot(array_merge($existingRow, $nextBusiness));
        $eskiTur = (string) $eski['bildirim_turu'];
        $yeniTur = isset($yeni['bildirim_turu']) && $yeni['bildirim_turu'] !== ''
            ? (string) $yeni['bildirim_turu']
            : null;

        $stmt = $pdo->prepare('
            INSERT INTO gunluk_bildirim_duzeltme_auditleri (
                gunluk_bildirim_id, personel_id, sube_id, tarih, olay_tipi,
                actor_user_id, correction_reason,
                eski_bildirim_turu, yeni_bildirim_turu,
                eski_alanlar, yeni_alanlar
            ) VALUES (
                :gunluk_bildirim_id, :personel_id, :sube_id, :tarih, :olay_tipi,
                :actor_user_id, :correction_reason,
                :eski_bildirim_turu, :yeni_bildirim_turu,
                :eski_alanlar, :yeni_alanlar
            )
        ');
        $stmt->execute([
            'gunluk_bildirim_id' => (int) $existingRow['id'],
            'personel_id' => (int) $existingRow['personel_id'],
            'sube_id' => (int) $existingRow['sube_id'],
            'tarih' => (string) $existingRow['tarih'],
            'olay_tipi' => (string) $olayTipi,
            'actor_user_id' => (int) $actorUserId,
            'correction_reason' => self::nullableString($correctionReason),
            'eski_bildirim_turu' => $eskiTur,
            'yeni_bildirim_turu' => $yeniTur,
            'eski_alanlar' => self::encodeJson($eski),
            'yeni_alanlar' => self::encodeJson($yeni),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listByBildirimId(PDO $pdo, $bildirimId)
    {
        if (!self::hasTable($pdo)) {
            return [];
        }

        $stmt = $pdo->prepare('
            SELECT
                id, gunluk_bildirim_id, personel_id, sube_id, tarih, olay_tipi,
                actor_user_id, correction_reason,
                eski_bildirim_turu, yeni_bildirim_turu,
                eski_alanlar, yeni_alanlar, created_at
            FROM gunluk_bildirim_duzeltme_auditleri
            WHERE gunluk_bildirim_id = :id
            ORDER BY id ASC
        ');
        $stmt->execute(['id' => (int) $bildirimId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = self::mapAuditRow($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function mapAuditRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'gunluk_bildirim_id' => (int) $row['gunluk_bildirim_id'],
            'personel_id' => (int) $row['personel_id'],
            'sube_id' => (int) $row['sube_id'],
            'tarih' => (string) $row['tarih'],
            'olay_tipi' => (string) $row['olay_tipi'],
            'actor_user_id' => (int) $row['actor_user_id'],
            'correction_reason' => self::nullableString(isset($row['correction_reason']) ? $row['correction_reason'] : null),
            'eski_bildirim_turu' => (string) $row['eski_bildirim_turu'],
            'yeni_bildirim_turu' => self::nullableString(isset($row['yeni_bildirim_turu']) ? $row['yeni_bildirim_turu'] : null),
            'eski_alanlar' => self::decodeJson(isset($row['eski_alanlar']) ? $row['eski_alanlar'] : null),
            'yeni_alanlar' => self::decodeJson(isset($row['yeni_alanlar']) ? $row['yeni_alanlar'] : null),
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function encodeJson(array $value)
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('audit snapshot encode failed');
        }

        return $json;
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private static function decodeJson($raw)
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function normalizeComparable($value)
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (is_int($value) || is_float($value)) {
            return (string) (int) $value;
        }

        return trim((string) $value);
    }

    /** @param mixed $value @return int|null */
    private static function nullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /** @param mixed $value @return string|null */
    private static function nullableString($value)
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
