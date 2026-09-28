<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use PDO;

/**
 * Advance *request* owner. Creating or listing a row never writes a finance AVANS kalemi.
 * Approval of this row is a status change only; payroll mutation stays outside this owner.
 */
class PersonelAvansTalepService
{
    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, $personelId, array $body)
    {
        self::assertSchema($pdo);
        $personelId = (int) $personelId;
        $tutar = self::requireAmount($body);
        $tarih = self::requireDate(isset($body['talep_tarihi']) ? $body['talep_tarihi'] : null);
        $aciklama = self::optionalText(isset($body['aciklama']) ? $body['aciklama'] : null, 500, 'aciklama');

        $stmt = $pdo->prepare(
            'INSERT INTO personel_avans_talepleri (personel_id, tutar, talep_tarihi, aciklama, durum)
             VALUES (:pid, :tutar, :tarih, :aciklama, \'BEKLIYOR\')'
        );
        $stmt->execute([
            'pid' => $personelId,
            'tutar' => $tutar,
            'tarih' => $tarih,
            'aciklama' => $aciklama,
        ]);

        return self::map(self::fetchOwn($pdo, $personelId, (int) $pdo->lastInsertId()));
    }

    /**
     * @return array{items:array<int, array<string, mixed>>}
     */
    public static function listForPersonel(PDO $pdo, $personelId)
    {
        self::assertSchema($pdo);
        $personelId = (int) $personelId;
        $stmt = $pdo->prepare(
            'SELECT id, personel_id, tutar, talep_tarihi, aciklama, durum, sonuc, created_at
             FROM personel_avans_talepleri
             WHERE personel_id = :pid
             ORDER BY talep_tarihi DESC, id DESC
             LIMIT 100'
        );
        $stmt->execute(['pid' => $personelId]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int) $row['personel_id'] !== $personelId) {
                continue;
            }
            $items[] = self::map($row);
        }

        return ['items' => $items];
    }

    private static function assertSchema(PDO $pdo)
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'personel_avans_talepleri'");
        if ($stmt === false || $stmt->fetch(PDO::FETCH_NUM) === false) {
            throw new PersonelSelfProductException(
                'AVANS_TALEP_SCHEMA_NOT_READY',
                'Avans talep semasi hazir degil.',
                503
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function requireAmount(array $body)
    {
        if (!array_key_exists('tutar', $body) || $body['tutar'] === null || trim((string) $body['tutar']) === '') {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Tutar zorunludur.', 422, 'tutar');
        }
        $raw = str_replace(',', '.', trim((string) $body['tutar']));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Tutar gecersiz.', 422, 'tutar');
        }
        $value = (float) $raw;
        if ($value <= 0 || $value > 9999999.99) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Tutar gecersiz.', 422, 'tutar');
        }

        return number_format($value, 2, '.', '');
    }

    /** @param mixed $value */
    private static function requireDate($value)
    {
        $raw = trim((string) $value);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($raw === '' || $dt === false || $dt->format('Y-m-d') !== $raw) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Tarih YYYY-MM-DD olmalidir.', 422, 'talep_tarihi');
        }

        return $raw;
    }

    /** @param mixed $value */
    private static function optionalText($value, $max, $field)
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (function_exists('mb_strlen') ? mb_strlen($text) > $max : strlen($text) > $max) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Aciklama cok uzun.', 422, $field);
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetchOwn(PDO $pdo, $personelId, $id)
    {
        $stmt = $pdo->prepare(
            'SELECT id, personel_id, tutar, talep_tarihi, aciklama, durum, sonuc, created_at
             FROM personel_avans_talepleri
             WHERE id = :id AND personel_id = :pid
             LIMIT 1'
        );
        $stmt->execute(['id' => (int) $id, 'pid' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new PersonelSelfProductException('NOT_FOUND', 'Avans talebi bulunamadi.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'tutar' => (string) $row['tutar'],
            'talep_tarihi' => (string) $row['talep_tarihi'],
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'durum' => (string) $row['durum'],
            'sonuc' => $row['sonuc'] !== null && trim((string) $row['sonuc']) !== ''
                ? (string) $row['sonuc']
                : null,
        ];
    }
}
