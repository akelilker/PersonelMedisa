<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use PDO;

/**
 * Announcement owner. Separate from the attendance inbox.
 */
class DuyuruService
{
    /**
     * @param array<string, mixed> $ctx
     * @return array{items:array<int, array<string, mixed>>, unread_count:int}
     */
    public static function listForContext(PDO $pdo, array $ctx)
    {
        self::assertSchema($pdo);
        $personelId = (int) $ctx['personel_id'];
        $today = self::istanbulToday();
        $rows = self::visibleRows($pdo, $ctx, $today);
        $items = [];
        $unread = 0;
        foreach ($rows as $row) {
            $okundu = $row['okundu_at'] !== null && $row['okundu_at'] !== '';
            if (!$okundu) {
                $unread += 1;
            }
            $items[] = self::map($row, $okundu);
        }

        return [
            'items' => $items,
            'unread_count' => $unread,
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    public static function markRead(PDO $pdo, array $ctx, $duyuruId)
    {
        self::assertSchema($pdo);
        $personelId = (int) $ctx['personel_id'];
        $duyuruId = (int) $duyuruId;
        $today = self::istanbulToday();
        $visible = false;
        foreach (self::visibleRows($pdo, $ctx, $today) as $row) {
            if ((int) $row['id'] === $duyuruId) {
                $visible = true;
                break;
            }
        }
        if (!$visible) {
            throw new PersonelSelfProductException('NOT_FOUND', 'Duyuru bulunamadi.', 404);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO duyuru_okumalari (duyuru_id, personel_id)
             VALUES (:did, :pid)
             ON DUPLICATE KEY UPDATE okundu_at = okundu_at'
        );
        $stmt->execute(['did' => $duyuruId, 'pid' => $personelId]);

        $listed = self::listForContext($pdo, $ctx);
        foreach ($listed['items'] as $item) {
            if ((int) $item['id'] === $duyuruId) {
                return $item;
            }
        }

        throw new PersonelSelfProductException('NOT_FOUND', 'Duyuru bulunamadi.', 404);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function create(PDO $pdo, array $user, array $body)
    {
        self::assertSchema($pdo);
        $baslik = self::requireText(isset($body['baslik']) ? $body['baslik'] : null, 200, 'baslik');
        $aciklama = self::requireText(isset($body['aciklama']) ? $body['aciklama'] : null, 4000, 'aciklama');
        $yayin = self::requireDate(isset($body['yayin_tarihi']) ? $body['yayin_tarihi'] : null, 'yayin_tarihi');
        $bitis = null;
        if (isset($body['bitis_tarihi']) && $body['bitis_tarihi'] !== null && trim((string) $body['bitis_tarihi']) !== '') {
            $bitis = self::requireDate($body['bitis_tarihi'], 'bitis_tarihi');
            if ($bitis < $yayin) {
                throw new PersonelSelfProductException(
                    'VALIDATION_ERROR',
                    'Bitis tarihi yayin tarihinden once olamaz.',
                    422,
                    'bitis_tarihi'
                );
            }
        }
        $aktif = !array_key_exists('aktif', $body) || self::truthy($body['aktif']) ? 1 : 0;
        $subeId = self::optionalPositiveId(isset($body['sube_id']) ? $body['sube_id'] : null, 'sube_id');
        $bolumId = self::optionalPositiveId(isset($body['bolum_id']) ? $body['bolum_id'] : null, 'bolum_id');
        $birimId = self::optionalPositiveId(isset($body['birim_id']) ? $body['birim_id'] : null, 'birim_id');

        $stmt = $pdo->prepare(
            'INSERT INTO duyurular (
                baslik, aciklama, yayin_tarihi, bitis_tarihi, aktif,
                sube_id, bolum_id, birim_id, created_by_user_id
             ) VALUES (
                :baslik, :aciklama, :yayin, :bitis, :aktif,
                :sube, :bolum, :birim, :uid
             )'
        );
        $stmt->execute([
            'baslik' => $baslik,
            'aciklama' => $aciklama,
            'yayin' => $yayin,
            'bitis' => $bitis,
            'aktif' => $aktif,
            'sube' => $subeId,
            'bolum' => $bolumId,
            'birim' => $birimId,
            'uid' => (int) $user['id'],
        ]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'baslik' => $baslik,
            'aciklama' => $aciklama,
            'yayin_tarihi' => $yayin,
            'bitis_tarihi' => $bitis,
            'aktif' => $aktif === 1,
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, array<string, mixed>>
     */
    private static function visibleRows(PDO $pdo, array $ctx, $today)
    {
        $stmt = $pdo->prepare(
            'SELECT d.id, d.baslik, d.aciklama, d.yayin_tarihi, d.bitis_tarihi, d.aktif,
                    o.okundu_at
             FROM duyurular d
             LEFT JOIN duyuru_okumalari o
               ON o.duyuru_id = d.id AND o.personel_id = :pid
             WHERE d.aktif = 1
               AND d.yayin_tarihi <= :today_from
               AND (d.bitis_tarihi IS NULL OR d.bitis_tarihi >= :today_to)
               AND (d.sube_id IS NULL OR d.sube_id = :sube)
               AND (d.bolum_id IS NULL OR (:bolum_gate IS NOT NULL AND d.bolum_id = :bolum_id))
               AND (d.birim_id IS NULL OR (:birim_gate IS NOT NULL AND d.birim_id = :birim_id))
             ORDER BY d.yayin_tarihi DESC, d.id DESC
             LIMIT 100'
        );
        $sube = isset($ctx['sube_id']) ? (int) $ctx['sube_id'] : 0;
        $bolum = isset($ctx['bolum_id']) && $ctx['bolum_id'] !== null ? (int) $ctx['bolum_id'] : null;
        $birim = isset($ctx['birim_id']) && $ctx['birim_id'] !== null ? (int) $ctx['birim_id'] : null;
        $stmt->execute([
            'pid' => (int) $ctx['personel_id'],
            'today_from' => $today,
            'today_to' => $today,
            'sube' => $sube > 0 ? $sube : -1,
            'bolum_gate' => $bolum,
            'bolum_id' => $bolum,
            'birim_gate' => $birim,
            'birim_id' => $birim,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function map(array $row, $okundu)
    {
        return [
            'id' => (int) $row['id'],
            'baslik' => (string) $row['baslik'],
            'aciklama' => (string) $row['aciklama'],
            'yayin_tarihi' => (string) $row['yayin_tarihi'],
            'bitis_tarihi' => $row['bitis_tarihi'] !== null ? (string) $row['bitis_tarihi'] : null,
            'okundu' => (bool) $okundu,
        ];
    }

    private static function assertSchema(PDO $pdo)
    {
        foreach (['duyurular', 'duyuru_okumalari'] as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            if ($stmt === false || $stmt->fetch(PDO::FETCH_NUM) === false) {
                throw new PersonelSelfProductException(
                    'DUYURU_SCHEMA_NOT_READY',
                    'Duyuru semasi hazir degil.',
                    503
                );
            }
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

    /** @param mixed $value */
    private static function requireDate($value, $field)
    {
        $raw = trim((string) $value);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($raw === '' || $dt === false || $dt->format('Y-m-d') !== $raw) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Tarih YYYY-MM-DD olmalidir.', 422, $field);
        }

        return $raw;
    }

    /** @param mixed $value */
    private static function optionalPositiveId($value, $field)
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $id = (int) $value;
        if ((string) $id !== trim((string) $value) && !is_int($value)) {
            if (!preg_match('/^\d+$/', trim((string) $value))) {
                throw new PersonelSelfProductException('VALIDATION_ERROR', 'Kapsam kimligi gecersiz.', 422, $field);
            }
        }
        if ($id <= 0) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Kapsam kimligi gecersiz.', 422, $field);
        }

        return $id;
    }

    /** @param mixed $value */
    private static function truthy($value)
    {
        if (is_bool($value)) {
            return $value;
        }
        $raw = strtolower(trim((string) $value));

        return !in_array($raw, ['0', 'false', 'hayir', 'pasif'], true);
    }

    private static function istanbulToday()
    {
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        } catch (\Throwable $e) {
            return (new \DateTimeImmutable('now'))->format('Y-m-d');
        }
    }
}
