<?php

declare(strict_types=1);

namespace Medisa\Api\Services\SelfService;

use PDO;

/**
 * Own published payroll slip acknowledgment (read receipt). No payroll mutation.
 */
class PersonelBordroOkumaService
{
    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public static function listForPersonel(PDO $pdo, $personelId, $userId)
    {
        self::assertSchema($pdo);
        $personelId = (int) $personelId;
        $userId = (int) $userId;
        if ($personelId <= 0) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Personel gecersiz.', 422);
        }

        $stmt = $pdo->prepare(
            'SELECT c.id AS calistirma_id, c.yil, c.ay, c.sube_id,
                    a.bordro_onay_durumu,
                    o.okundu_at, o.okundu_by_user_id
             FROM maas_hesaplama_adaylari a
             INNER JOIN maas_hesaplama_calistirmalari c ON c.id = a.calistirma_id
             INNER JOIN maas_hesaplama_personel_snapshotlari ps ON ps.id = a.personel_snapshot_id
             LEFT JOIN personel_bordro_okumalari o
                ON o.personel_id = :pid AND o.calistirma_id = c.id
             WHERE ps.personel_id = :pid
               AND a.bordro_onay_durumu = \'KESINLESTI\'
             ORDER BY c.yil DESC, c.ay DESC, c.id DESC
             LIMIT 36'
        );
        $stmt->execute(['pid' => $personelId]);
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'calistirma_id' => (int) $row['calistirma_id'],
                'yil' => (int) $row['yil'],
                'ay' => (int) $row['ay'],
                'sube_id' => (int) $row['sube_id'],
                'donem_label' => sprintf('%04d-%02d', (int) $row['yil'], (int) $row['ay']),
                'okundu' => $row['okundu_at'] !== null && $row['okundu_at'] !== '',
                'okundu_at' => $row['okundu_at'] !== null ? (string) $row['okundu_at'] : null,
                'okundu_by_user_id' => $row['okundu_by_user_id'] !== null
                    ? (int) $row['okundu_by_user_id']
                    : null,
            ];
        }

        return ['items' => $items];
    }

    /**
     * Idempotent ack for own published row only.
     *
     * @return array<string, mixed>
     */
    public static function acknowledge(PDO $pdo, $personelId, $userId, $calistirmaId)
    {
        self::assertSchema($pdo);
        $personelId = (int) $personelId;
        $userId = (int) $userId;
        $calistirmaId = (int) $calistirmaId;
        if ($personelId <= 0 || $userId <= 0 || $calistirmaId <= 0) {
            throw new PersonelSelfProductException('VALIDATION_ERROR', 'Gecersiz istek.', 422);
        }

        $stmt = $pdo->prepare(
            'SELECT c.id, c.yil, c.ay, c.sube_id
             FROM maas_hesaplama_adaylari a
             INNER JOIN maas_hesaplama_calistirmalari c ON c.id = a.calistirma_id
             INNER JOIN maas_hesaplama_personel_snapshotlari ps ON ps.id = a.personel_snapshot_id
             WHERE ps.personel_id = :pid
               AND c.id = :cid
               AND a.bordro_onay_durumu = \'KESINLESTI\'
             LIMIT 1'
        );
        $stmt->execute(['pid' => $personelId, 'cid' => $calistirmaId]);
        $published = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($published)) {
            throw new PersonelSelfProductException('NOT_FOUND', 'Yayinlanan bordro bulunamadi.', 404);
        }

        $ins = $pdo->prepare(
            'INSERT INTO personel_bordro_okumalari
                (personel_id, calistirma_id, yil, ay, okundu_by_user_id)
             VALUES
                (:pid, :cid, :yil, :ay, :uid)
             ON DUPLICATE KEY UPDATE
                okundu_at = okundu_at'
        );
        $ins->execute([
            'pid' => $personelId,
            'cid' => $calistirmaId,
            'yil' => (int) $published['yil'],
            'ay' => (int) $published['ay'],
            'uid' => $userId,
        ]);

        $read = $pdo->prepare(
            'SELECT okundu_at, okundu_by_user_id
             FROM personel_bordro_okumalari
             WHERE personel_id = :pid AND calistirma_id = :cid
             LIMIT 1'
        );
        $read->execute(['pid' => $personelId, 'cid' => $calistirmaId]);
        $ack = $read->fetch(PDO::FETCH_ASSOC);

        return [
            'calistirma_id' => $calistirmaId,
            'yil' => (int) $published['yil'],
            'ay' => (int) $published['ay'],
            'donem_label' => sprintf('%04d-%02d', (int) $published['yil'], (int) $published['ay']),
            'okundu' => true,
            'okundu_at' => is_array($ack) ? (string) $ack['okundu_at'] : gmdate('Y-m-d H:i:s'),
            'okundu_by_user_id' => is_array($ack) ? (int) $ack['okundu_by_user_id'] : $userId,
        ];
    }

    private static function assertSchema(PDO $pdo)
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'personel_bordro_okumalari'");
        if ($stmt === false || $stmt->fetch(PDO::FETCH_NUM) === false) {
            throw new PersonelSelfProductException(
                'BORDRO_OKUMA_SCHEMA_NOT_READY',
                'Bordro okuma semasi hazir degil.',
                503
            );
        }
    }
}
