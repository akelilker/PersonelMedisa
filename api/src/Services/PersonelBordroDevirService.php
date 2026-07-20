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
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            $row = self::upsertInternal($pdo, $payload, $user);
            if ($ownsTx) {
                $pdo->commit();
            }

            return $row;
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Caller must own the transaction (S83 batch import commit/rollback).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function upsertInTransaction(PDO $pdo, array $payload, array $user)
    {
        return self::upsertInternal($pdo, $payload, $user);
    }

    /**
     * S83 import dry-run / commit classification.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function processImport(PDO $pdo, $subeId, $yil, $ay, array $rows, $dryRun, array $user)
    {
        $counts = [
            'eklenecek' => 0,
            'guncellenecek' => 0,
            'degismeyecek' => 0,
            'hatali' => 0,
            'eslesmeyen' => 0,
            'duplicate' => 0,
            'scope_disi' => 0,
        ];
        $satirlar = [];
        $seen = [];
        $commitRows = [];

        foreach ($rows as $index => $row) {
            $satirNo = $index + 1;
            $sicil = self::canonicalizeSicil((string) ($row['sicil'] ?? $row['sicil_no'] ?? ''));
            if ($sicil === '') {
                $counts['hatali']++;
                $satirlar[] = ['satir' => $satirNo, 'ok' => false, 'sinif' => 'hatali', 'hata' => 'sicil zorunlu'];
                continue;
            }
            if (isset($seen[$sicil])) {
                $counts['duplicate']++;
                $satirlar[] = [
                    'satir' => $satirNo,
                    'sicil' => $sicil,
                    'ok' => false,
                    'sinif' => 'duplicate',
                    'hata' => 'duplicate personel/donem',
                ];
                continue;
            }
            $seen[$sicil] = true;

            $personel = self::findPersonelBySicilAnySube($pdo, $sicil);
            if (!$personel) {
                $counts['eslesmeyen']++;
                $satirlar[] = [
                    'satir' => $satirNo,
                    'sicil' => $sicil,
                    'ok' => false,
                    'sinif' => 'eslesmeyen',
                    'hata' => 'personel bulunamadi',
                ];
                continue;
            }
            if ((int) $personel['sube_id'] !== (int) $subeId) {
                $counts['scope_disi']++;
                $satirlar[] = [
                    'satir' => $satirNo,
                    'sicil' => $sicil,
                    'ok' => false,
                    'sinif' => 'scope_disi',
                    'hata' => 'personel aktif sube kapsaminda degil',
                ];
                continue;
            }

            $matrah = self::normalizeMoneyString($row['onceki_kumulatif_gelir_vergisi_matrahi'] ?? $row['gv_matrah'] ?? '0');
            $vergi = self::normalizeMoneyString($row['onceki_kumulatif_gelir_vergisi'] ?? $row['gv'] ?? '0');
            $sgkRaw = $row['onceki_kumulatif_sgk_matrahi'] ?? $row['sgk_matrah'] ?? null;
            $sgk = $sgkRaw !== null && trim((string) $sgkRaw) !== ''
                ? self::normalizeMoneyString($sgkRaw) : null;
            $aciklama = isset($row['aciklama']) ? trim((string) $row['aciklama']) : null;

            try {
                self::assertNonNegativeDecimal($matrah, 'onceki_kumulatif_gelir_vergisi_matrahi');
                self::assertNonNegativeDecimal($vergi, 'onceki_kumulatif_gelir_vergisi');
                if ($sgk !== null) {
                    self::assertNonNegativeDecimal($sgk, 'onceki_kumulatif_sgk_matrahi');
                }
            } catch (MaasHesaplamaException $e) {
                $counts['hatali']++;
                $satirlar[] = [
                    'satir' => $satirNo,
                    'sicil' => $sicil,
                    'ok' => false,
                    'sinif' => 'hatali',
                    'hata' => $e->getMessage(),
                ];
                continue;
            }

            $aktif = self::findActive($pdo, (int) $personel['id'], (int) $yil, (int) $ay, false);
            $sinif = 'eklenecek';
            if ($aktif) {
                $same = self::decimalEqual((string) $aktif['onceki_kumulatif_gelir_vergisi_matrahi'], $matrah)
                    && self::decimalEqual((string) $aktif['onceki_kumulatif_gelir_vergisi'], $vergi)
                    && self::nullableDecimalEqual(
                        $aktif['onceki_kumulatif_sgk_matrahi'] !== null ? (string) $aktif['onceki_kumulatif_sgk_matrahi'] : null,
                        $sgk
                    );
                $sinif = $same ? 'degismeyecek' : 'guncellenecek';
            }
            $counts[$sinif]++;
            $payload = [
                'personel_id' => (int) $personel['id'],
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
                'onceki_kumulatif_gelir_vergisi_matrahi' => $matrah,
                'onceki_kumulatif_gelir_vergisi' => $vergi,
                'onceki_kumulatif_sgk_matrahi' => $sgk,
                'devir_kaynagi' => 'CSV_IMPORT',
                'aciklama' => $aciklama,
            ];
            if ($sinif !== 'degismeyecek') {
                $commitRows[] = $payload;
            }
            $satirlar[] = [
                'satir' => $satirNo,
                'sicil' => $sicil,
                'ok' => true,
                'sinif' => $sinif,
                'personel_id' => (int) $personel['id'],
            ];
        }

        $failureCount = $counts['hatali'] + $counts['eslesmeyen'] + $counts['duplicate'] + $counts['scope_disi'];
        if (!$dryRun) {
            if ($failureCount > 0) {
                throw new MaasHesaplamaException(
                    'DEVIR_IMPORT_VALIDATION_FAILED',
                    'Devir import commit icin tum satirlar gecerli olmali.',
                    422,
                    ['counts' => $counts, 'satirlar' => $satirlar]
                );
            }
            $pdo->beginTransaction();
            try {
                foreach ($commitRows as $payload) {
                    self::upsertInTransaction($pdo, $payload, $user);
                }
                $pdo->prepare(
                    'INSERT INTO personel_bordro_devir_importlari (sube_id, yil, ay, dry_run, toplam_satir, basarili_satir, hatali_satir, hata_ozeti, actor_id)
                     VALUES (:s, :y, :a, 0, :t, :b, :h, :ozet, :actor)'
                )->execute([
                    's' => (int) $subeId,
                    'y' => (int) $yil,
                    'a' => (int) $ay,
                    't' => count($rows),
                    'b' => $counts['eklenecek'] + $counts['guncellenecek'] + $counts['degismeyecek'],
                    'h' => $failureCount,
                    'ozet' => json_encode(['counts' => $counts, 'satirlar' => $satirlar], JSON_UNESCAPED_UNICODE),
                    'actor' => isset($user['id']) ? (int) $user['id'] : null,
                ]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        return [
            'dry_run' => (bool) $dryRun,
            'toplam_satir' => count($rows),
            'basarili_satir' => $counts['eklenecek'] + $counts['guncellenecek'] + $counts['degismeyecek'],
            'hatali_satir' => $failureCount,
            'counts' => $counts,
            'eklenecek' => $counts['eklenecek'],
            'guncellenecek' => $counts['guncellenecek'],
            'degismeyecek' => $counts['degismeyecek'],
            'hatali' => $counts['hatali'],
            'eslesmeyen' => $counts['eslesmeyen'],
            'duplicate' => $counts['duplicate'],
            'scope_disi' => $counts['scope_disi'],
            'satirlar' => $satirlar,
        ];
    }

    public static function canonicalizeSicil($sicil)
    {
        return strtoupper(trim((string) $sicil));
    }

    /** @return array<string, mixed>|null */
    public static function findPersonelBySicilAnySube(PDO $pdo, $sicil)
    {
        $canonical = self::canonicalizeSicil($sicil);
        $stmt = $pdo->prepare(
            "SELECT id, ad, soyad, sicil_no, sube_id, departman_id, durum
             FROM personeller
             WHERE UPPER(TRIM(sicil_no)) = :sicil
             LIMIT 1"
        );
        $stmt->execute(['sicil' => $canonical]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $user @return array<string, mixed> */
    private static function upsertInternal(PDO $pdo, array $payload, array $user)
    {
        $personelId = (int) $payload['personel_id'];
        $subeId = (int) $payload['sube_id'];
        $yil = (int) $payload['yil'];
        $ay = (int) $payload['ay'];
        $matrah = self::normalizeMoneyString($payload['onceki_kumulatif_gelir_vergisi_matrahi'] ?? '0');
        $vergi = self::normalizeMoneyString($payload['onceki_kumulatif_gelir_vergisi'] ?? '0');
        $sgk = array_key_exists('onceki_kumulatif_sgk_matrahi', $payload) && $payload['onceki_kumulatif_sgk_matrahi'] !== null
            ? self::normalizeMoneyString($payload['onceki_kumulatif_sgk_matrahi']) : null;
        $kaynak = trim((string) ($payload['devir_kaynagi'] ?? 'MANUEL'));
        $aciklama = isset($payload['aciklama']) ? trim((string) $payload['aciklama']) : null;

        self::assertNonNegativeDecimal($matrah, 'onceki_kumulatif_gelir_vergisi_matrahi');
        self::assertNonNegativeDecimal($vergi, 'onceki_kumulatif_gelir_vergisi');
        if ($sgk !== null) {
            self::assertNonNegativeDecimal($sgk, 'onceki_kumulatif_sgk_matrahi');
        }

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

        return self::mapRow($row);
    }

    private static function normalizeMoneyString($value)
    {
        $raw = trim(str_replace(',', '.', (string) $value));
        if ($raw === '') {
            return '0.00';
        }
        if (!preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return $raw;
        }
        if (strpos($raw, '.') === false) {
            return $raw . '.00';
        }
        [$int, $frac] = explode('.', $raw, 2);
        $frac = substr(str_pad($frac, 2, '0'), 0, 2);

        return $int . '.' . $frac;
    }

    private static function decimalEqual($a, $b)
    {
        return bccomp((string) $a, (string) $b, 2) === 0;
    }

    private static function nullableDecimalEqual($a, $b)
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }

        return self::decimalEqual($a, $b);
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
