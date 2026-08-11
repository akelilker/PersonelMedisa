<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use Medisa\Api\Http\JsonResponse;
use PDO;

/**
 * Bounded self-service gunluk_puantaj read (S3B). Safe fields only; no TC / payroll.
 */
class SelfPuantajReadService
{
    private const MAX_WINDOW_DAYS_INCLUSIVE = 366;

    /**
     * @return array{from:string,to:string}
     */
    public static function defaultMonthRange()
    {
        try {
            $tz = new \DateTimeZone('Europe/Istanbul');
            $now = new \DateTimeImmutable('now', $tz);
        } catch (\Throwable $e) {
            $now = new \DateTimeImmutable('now');
        }

        $from = $now->modify('first day of this month')->format('Y-m-d');
        $to = $now->modify('last day of this month')->format('Y-m-d');

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @return array<string, mixed>
     */
    public static function listForPersonel(PDO $pdo, $personelId, $from, $to)
    {
        $personelId = (int) $personelId;
        $from = self::assertDateYmd($from, 'from');
        $to = self::assertDateYmd($to, 'to');

        if ($from > $to) {
            JsonResponse::badRequest(
                'from tarihi to tarihinden sonra olamaz.',
                'VALIDATION_ERROR',
                'from'
            );
        }

        $fromDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        $days = (int) $fromDt->diff($toDt)->days + 1;
        if ($days > self::MAX_WINDOW_DAYS_INCLUSIVE) {
            JsonResponse::badRequest(
                'Tarih penceresi en fazla 366 gun (dahil) olabilir.',
                'VALIDATION_ERROR',
                'to'
            );
        }

        $optional = self::probeOptionalColumns($pdo);
        $selectParts = [
            'tarih',
            'giris_saati',
            'cikis_saati',
        ];
        if ($optional['gun_tipi']) {
            $selectParts[] = 'gun_tipi';
        }
        if ($optional['net_calisma_suresi_dakika']) {
            $selectParts[] = 'net_calisma_suresi_dakika';
        }
        if ($optional['gunluk_brut_sure_dakika']) {
            $selectParts[] = 'gunluk_brut_sure_dakika';
        }
        if ($optional['gec_kalma_dakika']) {
            $selectParts[] = 'gec_kalma_dakika';
        }
        if ($optional['erken_cikis_dakika']) {
            $selectParts[] = 'erken_cikis_dakika';
        }
        if ($optional['fazla_calisma_dakika']) {
            $selectParts[] = 'fazla_calisma_dakika';
        }

        $sql = 'SELECT ' . implode(', ', $selectParts)
            . ' FROM gunluk_puantaj'
            . ' WHERE personel_id = :personel_id AND tarih BETWEEN :from_date AND :to_date'
            . ' ORDER BY tarih ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'personel_id' => $personelId,
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        $calismaGunAdet = 0;
        $gecKalmaAdet = 0;
        $gecKalmaToplam = 0;
        $erkenCikisAdet = 0;
        $erkenCikisToplam = 0;
        $fazlaCalismaToplam = 0;

        foreach ($rows as $row) {
            $mapped = self::mapSafeRow($row, $optional);
            $items[] = $mapped;

            $hasWork = ($mapped['giris_saati'] !== null && $mapped['giris_saati'] !== '')
                || ($mapped['net_calisma_suresi_dakika'] !== null && (int) $mapped['net_calisma_suresi_dakika'] > 0)
                || ($mapped['gunluk_brut_sure_dakika'] !== null && (int) $mapped['gunluk_brut_sure_dakika'] > 0);
            if ($hasWork) {
                $calismaGunAdet += 1;
            }

            $gec = $mapped['gec_kalma_dakika'];
            if ($gec !== null && (int) $gec > 0) {
                $gecKalmaAdet += 1;
                $gecKalmaToplam += (int) $gec;
            }

            $erken = $mapped['erken_cikis_dakika'];
            if ($erken !== null && (int) $erken > 0) {
                $erkenCikisAdet += 1;
                $erkenCikisToplam += (int) $erken;
            }

            $fm = $mapped['fazla_calisma_dakika'];
            if ($fm !== null && (int) $fm > 0) {
                $fazlaCalismaToplam += (int) $fm;
            }
        }

        return [
            'personel_id' => $personelId,
            'from' => $from,
            'to' => $to,
            'items' => $items,
            'ozet' => [
                'calisma_gun_adet' => $calismaGunAdet,
                'gec_kalma_adet' => $gecKalmaAdet,
                'gec_kalma_dakika_toplam' => $gecKalmaToplam,
                'erken_cikis_adet' => $erkenCikisAdet,
                'erken_cikis_dakika_toplam' => $erkenCikisToplam,
                'fazla_calisma_dakika_toplam' => $fazlaCalismaToplam,
            ],
        ];
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function assertDateYmd($value, $field)
    {
        $raw = trim((string) $value);
        if ($raw === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            JsonResponse::badRequest(
                $field . ' YYYY-MM-DD formatinda olmalidir.',
                'VALIDATION_ERROR',
                $field
            );
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            !$dt
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $dt->format('Y-m-d') !== $raw
        ) {
            JsonResponse::badRequest(
                $field . ' gecersiz tarih.',
                'VALIDATION_ERROR',
                $field
            );
        }

        return $raw;
    }

    /**
     * @return array<string, bool>
     */
    private static function probeOptionalColumns(PDO $pdo)
    {
        $names = [
            'gun_tipi',
            'net_calisma_suresi_dakika',
            'gunluk_brut_sure_dakika',
            'gec_kalma_dakika',
            'erken_cikis_dakika',
            'fazla_calisma_dakika',
        ];
        $out = [];
        foreach ($names as $name) {
            $out[$name] = self::hasColumn($pdo, 'gunluk_puantaj', $name);
        }

        return $out;
    }

    private static function hasColumn(PDO $pdo, $table, $column)
    {
        try {
            $col = $pdo->query(
                "SHOW COLUMNS FROM `" . str_replace('`', '``', (string) $table)
                . "` LIKE " . $pdo->quote((string) $column)
            );
            $exists = $col !== false && $col->fetch(PDO::FETCH_ASSOC) !== false;
            if ($col !== false) {
                $col->closeCursor();
            }

            return $exists;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, bool> $optional
     * @return array<string, mixed>
     */
    private static function mapSafeRow(array $row, array $optional)
    {
        return [
            'tarih' => (string) $row['tarih'],
            'gun_tipi' => !empty($optional['gun_tipi']) && array_key_exists('gun_tipi', $row)
                ? ($row['gun_tipi'] !== null ? (string) $row['gun_tipi'] : null)
                : null,
            'giris_saati' => $row['giris_saati'] !== null && $row['giris_saati'] !== ''
                ? (string) $row['giris_saati']
                : null,
            'cikis_saati' => $row['cikis_saati'] !== null && $row['cikis_saati'] !== ''
                ? (string) $row['cikis_saati']
                : null,
            'net_calisma_suresi_dakika' => !empty($optional['net_calisma_suresi_dakika'])
                ? self::nullableInt($row['net_calisma_suresi_dakika'] ?? null)
                : null,
            'gunluk_brut_sure_dakika' => !empty($optional['gunluk_brut_sure_dakika'])
                ? self::nullableInt($row['gunluk_brut_sure_dakika'] ?? null)
                : null,
            'gec_kalma_dakika' => !empty($optional['gec_kalma_dakika'])
                ? self::nullableInt($row['gec_kalma_dakika'] ?? null)
                : null,
            'erken_cikis_dakika' => !empty($optional['erken_cikis_dakika'])
                ? self::nullableInt($row['erken_cikis_dakika'] ?? null)
                : null,
            'fazla_calisma_dakika' => !empty($optional['fazla_calisma_dakika'])
                ? self::nullableInt($row['fazla_calisma_dakika'] ?? null)
                : null,
        ];
    }

    /** @param mixed $value */
    private static function nullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
