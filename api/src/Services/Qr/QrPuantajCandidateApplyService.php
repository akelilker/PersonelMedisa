<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

use PDO;

/**
 * Narrow existing-row QR apply: only giris_saati / cikis_saati (+ required workflow fields).
 * Does NOT recalculate late/early/OT/absence/derived fields.
 */
class QrPuantajCandidateApplyService
{
    /**
     * Snapshot fields for audit + dependent-field guard visibility.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function buildPuantajSnapshot(array $row)
    {
        $snap = [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'personel_id' => isset($row['personel_id']) ? (int) $row['personel_id'] : null,
            'tarih' => isset($row['tarih']) ? (string) $row['tarih'] : null,
            'giris_saati' => self::normalizeTime($row['giris_saati'] ?? null),
            'cikis_saati' => self::normalizeTime($row['cikis_saati'] ?? null),
            'kontrol_durumu' => isset($row['kontrol_durumu']) ? (string) $row['kontrol_durumu'] : null,
            'state' => isset($row['state']) ? (string) $row['state'] : null,
            'muhur_id' => isset($row['muhur_id']) && $row['muhur_id'] !== null && $row['muhur_id'] !== ''
                ? (int) $row['muhur_id']
                : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
        foreach (QrPuantajCandidateDecisionPolicy::$dependentGuardFields as $field) {
            $snap[$field] = array_key_exists($field, $row) ? $row[$field] : null;
        }

        return $snap;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function fetchForUpdate(PDO $pdo, $personelId, $tarih)
    {
        $driver = '';
        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (\Throwable $e) {
            $driver = '';
        }
        $forUpdate = $driver === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare(
            'SELECT * FROM gunluk_puantaj
             WHERE personel_id = :personel_id AND tarih = :tarih
             LIMIT 1' . $forUpdate
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'tarih' => (string) $tarih,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $lockedRow
     * @return array<string,mixed>
     */
    public static function applyEntryExit(PDO $pdo, array $lockedRow, $girisSaati, $cikisSaati)
    {
        $id = (int) ($lockedRow['id'] ?? 0);
        if ($id < 1) {
            throw new \InvalidArgumentException('Missing puantaj id');
        }

        $stmt = $pdo->prepare(
            'UPDATE gunluk_puantaj
             SET giris_saati = :giris_saati,
                 cikis_saati = :cikis_saati,
                 kontrol_durumu = :kontrol_durumu,
                 state = :state,
                 muhur_id = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            'giris_saati' => self::normalizeTime($girisSaati),
            'cikis_saati' => self::normalizeTime($cikisSaati),
            'kontrol_durumu' => 'BEKLIYOR',
            'state' => 'ACIK',
            'id' => $id,
        ]);

        $reload = $pdo->prepare('SELECT * FROM gunluk_puantaj WHERE id = :id LIMIT 1');
        $reload->execute(['id' => $id]);
        $after = $reload->fetch(PDO::FETCH_ASSOC);
        if (!is_array($after)) {
            throw new \RuntimeException('QR_APPLY_RELOAD_FAILED');
        }

        return $after;
    }

    private static function normalizeTime($value)
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) {
            return $m[1] . ':' . $m[2] . ':' . (isset($m[3]) ? $m[3] : '00');
        }

        return $raw;
    }
}
