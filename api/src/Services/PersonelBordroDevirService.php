<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;
use PDOException;

/**
 * S77-D personel bordro yasal devir (kumulatif vergi) owner'i.
 */
class PersonelBordroDevirService
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function upsert(PDO $pdo, array $payload, array $user)
    {
        $personelId = (int) $payload['personel_id'];
        $subeId = (int) $payload['sube_id'];
        $yil = (int) $payload['yil'];
        $ay = (int) $payload['ay'];
        $matrah = (string) $payload['onceki_kumulatif_gelir_vergisi_matrahi'];
        $vergi = (string) $payload['onceki_kumulatif_gelir_vergisi'];
        $sgk = array_key_exists('onceki_kumulatif_sgk_matrahi', $payload) && $payload['onceki_kumulatif_sgk_matrahi'] !== null
            ? (string) $payload['onceki_kumulatif_sgk_matrahi'] : null;
        $kaynak = trim((string) ($payload['devir_kaynagi'] ?? 'MANUEL'));
        $aciklama = isset($payload['aciklama']) ? trim((string) $payload['aciklama']) : null;

        self::assertNonNegativeDecimal($matrah, 'onceki_kumulatif_gelir_vergisi_matrahi');
        self::assertNonNegativeDecimal($vergi, 'onceki_kumulatif_gelir_vergisi');
        if ($sgk !== null) {
            self::assertNonNegativeDecimal($sgk, 'onceki_kumulatif_sgk_matrahi');
        }

        $pdo->beginTransaction();
        try {
            $aktif = self::findActive($pdo, $personelId, $yil, $ay, true);
            $requestHash = hash('sha256', json_encode([
                'actor' => (int) ($user['id'] ?? 0),
                'personel_id' => $personelId,
                'yil' => $yil,
                'ay' => $ay,
                'matrah' => $matrah,
                'vergi' => $vergi,
                'sgk' => $sgk,
            ], JSON_UNESCAPED_UNICODE));

            if ($aktif) {
                $pdo->prepare("UPDATE personel_bordro_devirleri SET state = 'IPTAL', updated_by = :u WHERE id = :id")
                    ->execute(['u' => self::actorId($user), 'id' => (int) $aktif['id']]);
                $revision = (int) $aktif['revision_no'] + 1;
                $parentId = (int) $aktif['id'];
                $aksiyon = 'REVISION';
            } else {
                $max = $pdo->prepare('SELECT MAX(revision_no) FROM personel_bordro_devirleri WHERE personel_id = :p AND yil = :y AND ay = :a');
                $max->execute(['p' => $personelId, 'y' => $yil, 'a' => $ay]);
                $revision = ((int) $max->fetchColumn()) + 1;
                $parentId = null;
                $aksiyon = 'CREATE';
            }

            $ins = $pdo->prepare(
                'INSERT INTO personel_bordro_devirleri (
                    personel_id, sube_id, yil, ay,
                    onceki_kumulatif_gelir_vergisi_matrahi, onceki_kumulatif_gelir_vergisi, onceki_kumulatif_sgk_matrahi,
                    devir_kaynagi, aciklama, state, revision_no, parent_devir_id, created_by, updated_by
                 ) VALUES (
                    :personel_id, :sube_id, :yil, :ay,
                    :matrah, :vergi, :sgk,
                    :kaynak, :aciklama, \'AKTIF\', :revision_no, :parent_id, :created_by, :updated_by
                 )'
            );
            $ins->execute([
                'personel_id' => $personelId,
                'sube_id' => $subeId,
                'yil' => $yil,
                'ay' => $ay,
                'matrah' => $matrah,
                'vergi' => $vergi,
                'sgk' => $sgk,
                'kaynak' => $kaynak !== '' ? $kaynak : 'MANUEL',
                'aciklama' => $aciklama,
                'revision_no' => $revision,
                'parent_id' => $parentId,
                'created_by' => self::actorId($user),
                'updated_by' => self::actorId($user),
            ]);
            $id = (int) $pdo->lastInsertId();
            $row = self::fetchById($pdo, $id);
            self::writeAudit($pdo, $aksiyon, $aktif, $row, $user, $requestHash);
            $pdo->commit();

            return self::mapRow($row);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    public static function findActive(PDO $pdo, $personelId, $yil, $ay, $forUpdate = false)
    {
        $sql = "SELECT * FROM personel_bordro_devirleri
                WHERE personel_id = :p AND yil = :y AND ay = :a AND state = 'AKTIF' LIMIT 1";
        if ($forUpdate && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['p' => (int) $personelId, 'y' => (int) $yil, 'a' => (int) $ay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<int, int> $personelIds
     * @return array<int, array<string, mixed>>
     */
    public static function findActiveBatch(PDO $pdo, array $personelIds, $yil, $ay)
    {
        if (count($personelIds) === 0) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($personelIds), '?'));
        $params = array_map('intval', array_values($personelIds));
        $params[] = (int) $yil;
        $params[] = (int) $ay;
        $stmt = $pdo->prepare(
            "SELECT * FROM personel_bordro_devirleri
             WHERE personel_id IN ($ph) AND yil = ? AND ay = ? AND state = 'AKTIF'"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['personel_id']] = self::mapRow($row);
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function listForSube(PDO $pdo, $subeId, $yil, $ay)
    {
        $stmt = $pdo->prepare(
            "SELECT d.* FROM personel_bordro_devirleri d
             WHERE d.sube_id = :s AND d.yil = :y AND d.ay = :a AND d.state = 'AKTIF'
             ORDER BY d.personel_id ASC"
        );
        $stmt->execute(['s' => (int) $subeId, 'y' => (int) $yil, 'a' => (int) $ay]);

        return array_map([self::class, 'mapRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public static function fetchById(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM personel_bordro_devirleri WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function mapRow(array $row)
    {
        return [
            'id' => (int) $row['id'],
            'personel_id' => (int) $row['personel_id'],
            'sube_id' => (int) $row['sube_id'],
            'yil' => (int) $row['yil'],
            'ay' => (int) $row['ay'],
            'onceki_kumulatif_gelir_vergisi_matrahi' => (string) $row['onceki_kumulatif_gelir_vergisi_matrahi'],
            'onceki_kumulatif_gelir_vergisi' => (string) $row['onceki_kumulatif_gelir_vergisi'],
            'onceki_kumulatif_sgk_matrahi' => $row['onceki_kumulatif_sgk_matrahi'] !== null ? (string) $row['onceki_kumulatif_sgk_matrahi'] : null,
            'devir_kaynagi' => (string) $row['devir_kaynagi'],
            'aciklama' => $row['aciklama'] !== null ? (string) $row['aciklama'] : null,
            'state' => (string) $row['state'],
            'revision_no' => (int) $row['revision_no'],
            'parent_devir_id' => $row['parent_devir_id'] !== null ? (int) $row['parent_devir_id'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function assertNonNegativeDecimal($value, $field)
    {
        if (!preg_match('/^\d+(\.\d{1,2})?$/', (string) $value)) {
            throw new MaasHesaplamaException('VALIDATION_ERROR', $field . ' gecersiz.', 400);
        }
    }

    /**
     * @param array<string, mixed>|null $onceki
     * @param array<string, mixed>|null $sonraki
     * @param array<string, mixed> $user
     */
    private static function writeAudit(PDO $pdo, $aksiyon, $onceki, $sonraki, array $user, $requestHash)
    {
        $row = $sonraki ?: $onceki;
        if (!$row) {
            return;
        }
        try {
            $pdo->prepare(
                'INSERT INTO personel_bordro_devir_auditleri (
                    personel_id, sube_id, yil, ay, devir_id, aksiyon,
                    onceki_snapshot, sonraki_snapshot, actor_id, actor_rol, request_hash
                 ) VALUES (
                    :personel_id, :sube_id, :yil, :ay, :devir_id, :aksiyon,
                    :onceki, :sonraki, :actor_id, :actor_rol, :request_hash
                 )'
            )->execute([
                'personel_id' => (int) $row['personel_id'],
                'sube_id' => (int) $row['sube_id'],
                'yil' => (int) $row['yil'],
                'ay' => (int) $row['ay'],
                'devir_id' => isset($sonraki['id']) ? (int) $sonraki['id'] : null,
                'aksiyon' => (string) $aksiyon,
                'onceki' => $onceki ? json_encode(self::mapRow($onceki), JSON_UNESCAPED_UNICODE) : null,
                'sonraki' => $sonraki ? json_encode(self::mapRow($sonraki), JSON_UNESCAPED_UNICODE) : null,
                'actor_id' => self::actorId($user),
                'actor_rol' => isset($user['rol']) ? (string) $user['rol'] : null,
                'request_hash' => (string) $requestHash,
            ]);
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    /** @param array<string, mixed> $user */
    private static function actorId(array $user)
    {
        $id = isset($user['id']) ? (int) $user['id'] : 0;

        return $id > 0 ? $id : null;
    }
}
