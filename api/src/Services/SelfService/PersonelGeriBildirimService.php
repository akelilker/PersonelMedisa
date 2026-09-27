<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use PDO;

/**
 * Named self-service feedback. Anonymous submit is rejected.
 */
class PersonelGeriBildirimService
{
    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, $personelId, array $body)
    {
        self::assertSchema($pdo);
        if (!empty($body['anonim']) || !empty($body['anonymous'])) {
            throw new PersonelSelfProductException(
                'VALIDATION_ERROR',
                'Anonim gonderim kapali.',
                422,
                'anonim'
            );
        }
        $personelId = (int) $personelId;
        $tur = strtoupper(trim((string) (isset($body['tur']) ? $body['tur'] : '')));
        if (!in_array($tur, ['ONERI', 'SIKAYET'], true)) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Tur ONERI veya SIKAYET olmalidir.', 422, 'tur');
        }
        $konu = self::requireText(isset($body['konu']) ? $body['konu'] : null, 200, 'konu');
        $aciklama = self::requireText(isset($body['aciklama']) ? $body['aciklama'] : null, 2000, 'aciklama');

        $stmt = $pdo->prepare(
            'INSERT INTO personel_geri_bildirimler (personel_id, tur, konu, aciklama, durum)
             VALUES (:pid, :tur, :konu, :aciklama, \'ALINDI\')'
        );
        $stmt->execute([
            'pid' => $personelId,
            'tur' => $tur,
            'konu' => $konu,
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
            'SELECT id, personel_id, tur, konu, aciklama, durum, sonuc, created_at
             FROM personel_geri_bildirimler
             WHERE personel_id = :pid
             ORDER BY created_at DESC, id DESC
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'personel_geri_bildirimler'");
        if ($stmt === false || $stmt->fetch(PDO::FETCH_NUM) === false) {
            throw new PersonelSelfProductException(
                'GERI_BILDIRIM_SCHEMA_NOT_READY',
                'Geri bildirim semasi hazir degil.',
                503
            );
        }
    }

    /** @param mixed $value */
    private static function requireText($value, $max, $field)
    {
        $text = trim((string) $value);
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $len > $max) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Alan zorunlu veya cok uzun.', 422, $field);
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private static function fetchOwn(PDO $pdo, $personelId, $id)
    {
        $stmt = $pdo->prepare(
            'SELECT id, personel_id, tur, konu, aciklama, durum, sonuc, created_at
             FROM personel_geri_bildirimler
             WHERE id = :id AND personel_id = :pid
             LIMIT 1'
        );
        $stmt->execute(['id' => (int) $id, 'pid' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new PersonelSelfProductException('NOT_FOUND', 'Geri bildirim bulunamadi.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row)
    {
        $created = (string) ($row['created_at'] ?? '');
        $tarih = preg_match('/^(\d{4}-\d{2}-\d{2})/', $created, $m) === 1 ? $m[1] : $created;

        return [
            'id' => (int) $row['id'],
            'tur' => (string) $row['tur'],
            'konu' => (string) $row['konu'],
            'aciklama' => (string) $row['aciklama'],
            'tarih' => $tarih,
            'durum' => (string) $row['durum'],
            'sonuc' => $row['sonuc'] !== null && trim((string) $row['sonuc']) !== ''
                ? (string) $row['sonuc']
                : null,
        ];
    }
}
