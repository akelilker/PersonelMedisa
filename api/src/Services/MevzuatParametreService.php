<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use DateTimeImmutable;
use PDO;
use PDOException;

class MevzuatParametreService
{
    /** @return array<int, array<string, mixed>> */
    public static function listParameters(PDO $pdo, $code = null)
    {
        $sql = 'SELECT * FROM mevzuat_parametreleri';
        $params = [];
        if ($code !== null && trim((string) $code) !== '') {
            $sql .= ' WHERE parametre_kodu = :kod';
            $params['kod'] = self::normalizeCode($code);
        }
        $sql .= ' ORDER BY parametre_kodu ASC, gecerlilik_baslangic DESC, id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    public static function resolveForDate(PDO $pdo, $code, $date)
    {
        $code = self::normalizeCode($code);
        $date = self::validDate($date);
        $stmt = $pdo->prepare(
            "SELECT * FROM mevzuat_parametreleri
             WHERE parametre_kodu = :kod AND state = 'AKTIF'
               AND gecerlilik_baslangic <= :tarih
               AND (gecerlilik_bitis IS NULL OR :tarih_bitis <= gecerlilik_bitis)
             ORDER BY gecerlilik_baslangic DESC, id DESC"
        );
        $stmt->execute(['kod' => $code, 'tarih' => $date, 'tarih_bitis' => $date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new MevzuatParametreException('LEGAL_PARAMETER_OVERLAP_DATA_ERROR', 'Mevzuat parametresinde cakisan kayitlar var.', 409);
        }
        if (!$rows) {
            throw new MevzuatParametreException('LEGAL_PARAMETER_MISSING', 'Belirtilen tarihte gecerli mevzuat parametresi yok.', 404);
        }

        return $rows[0];
    }

    public static function validateNoOverlap(PDO $pdo, $code, $start, $end = null, $excludeId = null)
    {
        $code = self::normalizeCode($code);
        $start = self::validDate($start);
        $end = self::optionalDate($end);
        self::assertRange($start, $end);
        $sql = "SELECT id FROM mevzuat_parametreleri
                WHERE parametre_kodu = :kod AND state = 'AKTIF'
                  AND gecerlilik_baslangic <= :yeni_bitis
                  AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :yeni_baslangic)";
        $params = ['kod' => $code, 'yeni_bitis' => $end ?: '9999-12-31', 'yeni_baslangic' => $start];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = (int) $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new MevzuatParametreException('LEGAL_PARAMETER_OVERLAP', 'Mevzuat parametresi tarih araligi mevcut kayitla cakisiyor.', 409);
        }

        return true;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $actor @return array<string, mixed> */
    public static function createParameter(PDO $pdo, array $payload, array $actor = [], $requestHash = null)
    {
        $data = self::normalizePayload($payload);
        $advisoryLock = self::acquireCodeLock($pdo, $data['parametre_kodu']);
        $owns = !$pdo->inTransaction();
        try {
            if ($owns) {
                $pdo->beginTransaction();
            }
            self::lockCode($pdo, $data['parametre_kodu']);
            $stmt = $pdo->prepare(
                "SELECT * FROM mevzuat_parametreleri
                 WHERE parametre_kodu = :kod AND state = 'AKTIF' AND gecerlilik_bitis IS NULL
                 ORDER BY gecerlilik_baslangic DESC LIMIT 1" . self::forUpdate($pdo)
            );
            $stmt->execute(['kod' => $data['parametre_kodu']]);
            $open = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($open && (string) $open['gecerlilik_baslangic'] <= $data['gecerlilik_baslangic']) {
                if ((string) $open['gecerlilik_baslangic'] === $data['gecerlilik_baslangic']) {
                    throw new MevzuatParametreException('DATE_RANGE_INVALID', 'Ayni baslangic tarihli acik parametre kapatilamaz.', 400);
                }
                $end = (new DateTimeImmutable($data['gecerlilik_baslangic']))->modify('-1 day')->format('Y-m-d');
                $close = $pdo->prepare(
                    'UPDATE mevzuat_parametreleri SET gecerlilik_bitis = :bitis,
                     updated_by = :actor, revision_no = revision_no + 1 WHERE id = :id'
                );
                $close->execute(['bitis' => $end, 'actor' => self::actorId($actor), 'id' => (int) $open['id']]);
                self::audit($pdo, 'CLOSE', $open, self::fetch($pdo, (int) $open['id']), $actor, $requestHash);
            }
            self::validateNoOverlap($pdo, $data['parametre_kodu'], $data['gecerlilik_baslangic'], $data['gecerlilik_bitis']);
            $insert = $pdo->prepare(
                'INSERT INTO mevzuat_parametreleri (
                   parametre_kodu, deger_tipi, sayisal_deger, metin_deger,
                   gecerlilik_baslangic, gecerlilik_bitis, birim, aciklama, kaynak_referansi,
                   state, created_by, updated_by
                 ) VALUES (
                   :kod, :tip, :sayisal, :metin, :baslangic, :bitis, :birim, :aciklama, :kaynak,
                   \'AKTIF\', :created_by, :updated_by
                 )'
            );
            $insert->execute([
                'kod' => $data['parametre_kodu'],
                'tip' => $data['deger_tipi'],
                'sayisal' => $data['sayisal_deger'],
                'metin' => $data['metin_deger'],
                'baslangic' => $data['gecerlilik_baslangic'],
                'bitis' => $data['gecerlilik_bitis'],
                'birim' => $data['birim'],
                'aciklama' => $data['aciklama'],
                'kaynak' => $data['kaynak_referansi'],
                'created_by' => self::actorId($actor),
                'updated_by' => self::actorId($actor),
            ]);
            $row = self::fetch($pdo, (int) $pdo->lastInsertId());
            self::audit($pdo, 'CREATE', null, $row, $actor, $requestHash);
            if ($owns) {
                $pdo->commit();
            }
            self::releaseCodeLock($pdo, $advisoryLock);

            return $row ?: [];
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::releaseCodeLock($pdo, $advisoryLock);
            if ($e instanceof MevzuatParametreException) {
                throw $e;
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new MevzuatParametreException('LEGAL_PARAMETER_OVERLAP', 'Mevzuat parametresi tarih araligi mevcut kayitla cakisiyor.', 409);
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $actor @return array<string, mixed> */
    public static function updateFutureParameter(PDO $pdo, $recordId, array $payload, array $actor = [], $requestHash = null)
    {
        return self::mutate($pdo, $recordId, $actor, 'UPDATE', $requestHash, function (array $before) use ($pdo, $payload, $actor) {
            if ((string) $before['state'] !== 'AKTIF' || (string) $before['gecerlilik_baslangic'] <= date('Y-m-d')) {
                throw new MevzuatParametreException('LEGAL_PARAMETER_CHANGE_FORBIDDEN', 'Baslamis veya iptal edilmis parametre degistirilemez.', 409);
            }
            $data = self::normalizePayload(array_merge($before, $payload));
            if ($data['parametre_kodu'] !== (string) $before['parametre_kodu']) {
                throw new MevzuatParametreException('LEGAL_PARAMETER_CHANGE_FORBIDDEN', 'Parametre kodu degistirilemez.', 409);
            }
            self::validateNoOverlap($pdo, $data['parametre_kodu'], $data['gecerlilik_baslangic'], $data['gecerlilik_bitis'], (int) $before['id']);
            $stmt = $pdo->prepare(
                'UPDATE mevzuat_parametreleri SET deger_tipi = :tip, sayisal_deger = :sayisal,
                 metin_deger = :metin, gecerlilik_baslangic = :baslangic, gecerlilik_bitis = :bitis,
                 birim = :birim, aciklama = :aciklama, kaynak_referansi = :kaynak,
                 updated_by = :actor, revision_no = revision_no + 1 WHERE id = :id'
            );
            $stmt->execute([
                'tip' => $data['deger_tipi'], 'sayisal' => $data['sayisal_deger'], 'metin' => $data['metin_deger'],
                'baslangic' => $data['gecerlilik_baslangic'], 'bitis' => $data['gecerlilik_bitis'],
                'birim' => $data['birim'], 'aciklama' => $data['aciklama'], 'kaynak' => $data['kaynak_referansi'],
                'actor' => self::actorId($actor), 'id' => (int) $before['id'],
            ]);
        });
    }

    /** @param array<string, mixed> $actor @return array<string, mixed> */
    public static function cancelParameter(PDO $pdo, $recordId, array $actor = [], $requestHash = null)
    {
        return self::mutate($pdo, $recordId, $actor, 'CANCEL', $requestHash, function (array $before) use ($pdo, $actor) {
            if ((string) $before['state'] !== 'AKTIF') {
                throw new MevzuatParametreException('LEGAL_PARAMETER_CHANGE_FORBIDDEN', 'Parametre zaten iptal.', 409);
            }
            $stmt = $pdo->prepare(
                "UPDATE mevzuat_parametreleri SET state = 'IPTAL', updated_by = :actor,
                 revision_no = revision_no + 1 WHERE id = :id"
            );
            $stmt->execute(['actor' => self::actorId($actor), 'id' => (int) $before['id']]);
        });
    }

    /** @param callable(array<string, mixed>): void $callback @param array<string, mixed> $actor */
    private static function mutate(PDO $pdo, $id, array $actor, $action, $requestHash, callable $callback)
    {
        $owns = !$pdo->inTransaction();
        $ownerStmt = $pdo->prepare('SELECT parametre_kodu FROM mevzuat_parametreleri WHERE id = :id LIMIT 1');
        $ownerStmt->execute(['id' => (int) $id]);
        $ownerCode = $ownerStmt->fetchColumn();
        if ($ownerCode === false) {
            throw new MevzuatParametreException('NOT_FOUND', 'Mevzuat parametresi bulunamadi.', 404);
        }
        $advisoryLock = self::acquireCodeLock($pdo, (string) $ownerCode);
        try {
            if ($owns) {
                $pdo->beginTransaction();
            }
            $stmt = $pdo->prepare('SELECT * FROM mevzuat_parametreleri WHERE id = :id LIMIT 1' . self::forUpdate($pdo));
            $stmt->execute(['id' => (int) $id]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new MevzuatParametreException('NOT_FOUND', 'Mevzuat parametresi bulunamadi.', 404);
            }
            self::lockCode($pdo, (string) $before['parametre_kodu']);
            $callback($before);
            $after = self::fetch($pdo, (int) $id);
            self::audit($pdo, $action, $before, $after, $actor, $requestHash);
            if ($owns) {
                $pdo->commit();
            }
            self::releaseCodeLock($pdo, $advisoryLock);

            return $after ?: [];
        } catch (\Throwable $e) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::releaseCodeLock($pdo, $advisoryLock);
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private static function fetch(PDO $pdo, $id)
    {
        $stmt = $pdo->prepare('SELECT * FROM mevzuat_parametreleri WHERE id = :id');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function lockCode(PDO $pdo, $code)
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM mevzuat_parametreleri WHERE parametre_kodu = :kod ORDER BY id LIMIT 1' . self::forUpdate($pdo)
        );
        $stmt->execute(['kod' => (string) $code]);
        $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after @param array<string, mixed> $actor */
    private static function audit(PDO $pdo, $action, $before, $after, array $actor, $requestHash)
    {
        $row = $after ?: $before;
        $stmt = $pdo->prepare(
            'INSERT INTO mevzuat_parametre_auditleri (
               parametre_kodu, parametre_kaydi_id, aksiyon, onceki_snapshot, sonraki_snapshot,
               actor_id, actor_rol, request_hash
             ) VALUES (:kod, :kayit_id, :aksiyon, :onceki, :sonraki, :actor, :rol, :hash)'
        );
        $stmt->execute([
            'kod' => (string) ($row['parametre_kodu'] ?? ''),
            'kayit_id' => isset($row['id']) ? (int) $row['id'] : null,
            'aksiyon' => (string) $action,
            'onceki' => self::json($before),
            'sonraki' => self::json($after),
            'actor' => self::actorId($actor),
            'rol' => isset($actor['rol']) ? (string) $actor['rol'] : null,
            'hash' => $requestHash ? (string) $requestHash : null,
        ]);
    }

    /** @param array<string, mixed>|null $value */
    private static function json($value)
    {
        if ($value === null) {
            return null;
        }
        ksort($value);

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function normalizePayload(array $payload)
    {
        $type = strtoupper(trim((string) ($payload['deger_tipi'] ?? '')));
        if (!in_array($type, ['SAYISAL', 'METIN'], true)) {
            throw new MevzuatParametreException('VALIDATION_ERROR', 'Deger tipi SAYISAL veya METIN olmalidir.', 422);
        }
        $numeric = $payload['sayisal_deger'] ?? null;
        $text = isset($payload['metin_deger']) ? trim((string) $payload['metin_deger']) : null;
        if ($type === 'SAYISAL' && (!is_numeric($numeric) || $text !== null && $text !== '')) {
            throw new MevzuatParametreException('VALIDATION_ERROR', 'Sayisal parametre yalniz sayisal deger icermelidir.', 422);
        }
        if ($type === 'METIN' && (($text === null || $text === '') || $numeric !== null && $numeric !== '')) {
            throw new MevzuatParametreException('VALIDATION_ERROR', 'Metin parametresi yalniz metin degeri icermelidir.', 422);
        }
        $start = self::validDate($payload['gecerlilik_baslangic'] ?? null);
        $end = self::optionalDate($payload['gecerlilik_bitis'] ?? null);
        self::assertRange($start, $end);

        return [
            'parametre_kodu' => self::normalizeCode($payload['parametre_kodu'] ?? ''),
            'deger_tipi' => $type,
            'sayisal_deger' => $type === 'SAYISAL' ? (string) $numeric : null,
            'metin_deger' => $type === 'METIN' ? $text : null,
            'gecerlilik_baslangic' => $start,
            'gecerlilik_bitis' => $end,
            'birim' => self::optionalString($payload['birim'] ?? null, 32),
            'aciklama' => self::optionalString($payload['aciklama'] ?? null, 500),
            'kaynak_referansi' => self::optionalString($payload['kaynak_referansi'] ?? null, 255),
        ];
    }

    private static function normalizeCode($code)
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || strlen($code) > 80 || !preg_match('/^[A-Z0-9_.-]+$/', $code)) {
            throw new MevzuatParametreException('VALIDATION_ERROR', 'Gecersiz parametre kodu.', 422);
        }

        return $code;
    }

    private static function validDate($value)
    {
        $value = is_string($value) ? trim($value) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new MevzuatParametreException('DATE_INVALID', 'Gecerli bir tarih zorunludur.', 400);
        }

        return $value;
    }

    private static function optionalDate($value)
    {
        return $value === null || $value === '' ? null : self::validDate($value);
    }

    private static function assertRange($start, $end)
    {
        if ($end !== null && $end < $start) {
            throw new MevzuatParametreException('DATE_RANGE_INVALID', 'Bitis tarihi baslangic tarihinden once olamaz.', 400);
        }
    }

    private static function optionalString($value, $max)
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (strlen($value) > $max) {
            throw new MevzuatParametreException('VALIDATION_ERROR', 'Metin izin verilen uzunlugu asiyor.', 422);
        }

        return $value;
    }

    /** @param array<string, mixed> $actor */
    private static function actorId(array $actor)
    {
        $id = isset($actor['id']) ? (int) $actor['id'] : 0;

        return $id > 0 ? $id : null;
    }

    private static function forUpdate(PDO $pdo)
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private static function acquireCodeLock(PDO $pdo, $code)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return null;
        }
        $name = 'medisa:mevzuat:' . hash('sha256', (string) $code);
        $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $stmt->execute(['lock_name' => $name]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new MevzuatParametreException('LEGAL_PARAMETER_OVERLAP', 'Parametre kodu icin islem kilidi alinamadi.', 409);
        }

        return $name;
    }

    private static function releaseCodeLock(PDO $pdo, $name)
    {
        if ($name === null || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $stmt->execute(['lock_name' => (string) $name]);
    }
}
