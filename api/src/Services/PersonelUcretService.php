<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use DateTimeImmutable;
use PDO;
use PDOException;

class PersonelUcretService
{
    /** @return array<string, mixed>|null */
    public static function getCurrentSalary(PDO $pdo, $personelId, $date = null)
    {
        try {
            return self::resolveSalaryForDate($pdo, $personelId, $date ?: date('Y-m-d'));
        } catch (PersonelUcretException $e) {
            if ($e->getCodeString() === 'SALARY_MISSING') {
                return null;
            }
            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function listSalaryHistory(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM personel_ucret_gecmisi
             WHERE personel_id = :personel_id
             ORDER BY gecerlilik_baslangic DESC, id DESC'
        );
        $stmt->execute(['personel_id' => (int) $personelId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    public static function resolveSalaryForDate(PDO $pdo, $personelId, $date)
    {
        $date = self::validDate($date, 'gecerlilik_tarihi');
        $stmt = $pdo->prepare(
            "SELECT * FROM personel_ucret_gecmisi
             WHERE personel_id = :personel_id
               AND state = 'AKTIF'
               AND gecerlilik_baslangic <= :tarih
               AND (gecerlilik_bitis IS NULL OR :tarih_bitis <= gecerlilik_bitis)
             ORDER BY gecerlilik_baslangic DESC, id DESC"
        );
        $stmt->execute([
            'personel_id' => (int) $personelId,
            'tarih' => $date,
            'tarih_bitis' => $date,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 1) {
            throw new PersonelUcretException('SALARY_OVERLAP_DATA_ERROR', 'Ucret gecmisinde cakisan kayitlar var.', 409);
        }
        if (count($rows) === 1) {
            return $rows[0];
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM personel_ucret_gecmisi WHERE personel_id = :personel_id');
        $countStmt->execute(['personel_id' => (int) $personelId]);
        if ((int) $countStmt->fetchColumn() === 0) {
            $legacyStmt = $pdo->prepare(
                'SELECT id, maas_tutari, ise_giris_tarihi FROM personeller WHERE id = :id LIMIT 1'
            );
            $legacyStmt->execute(['id' => (int) $personelId]);
            $legacy = $legacyStmt->fetch(PDO::FETCH_ASSOC);
            if ($legacy && $legacy['maas_tutari'] !== null && (float) $legacy['maas_tutari'] > 0) {
                $start = !empty($legacy['ise_giris_tarihi']) ? (string) $legacy['ise_giris_tarihi'] : '1900-01-01';
                if ($start <= $date) {
                    return [
                        'id' => null,
                        'personel_id' => (int) $personelId,
                        'ucret_tutari' => (string) $legacy['maas_tutari'],
                        'ucret_turu' => 'NET',
                        'para_birimi' => 'TRY',
                        'gecerlilik_baslangic' => $start,
                        'gecerlilik_bitis' => null,
                        'state' => 'AKTIF',
                        'kaynak' => 'PERSONEL_KAYDI_MIGRASYON',
                        'virtual' => true,
                    ];
                }
            }
        }

        throw new PersonelUcretException('SALARY_MISSING', 'Belirtilen tarihte gecerli ucret kaydi yok.', 404);
    }

    /**
     * Inclusive range semantics: start <= D and (end is null or D <= end).
     * Two ranges overlap when each starts on or before the other range ends.
     *
     * @return bool
     */
    public static function validateNoOverlap(PDO $pdo, $personelId, $start, $end = null, $excludeId = null)
    {
        $start = self::validDate($start, 'gecerlilik_baslangic');
        $end = self::optionalValidDate($end, 'gecerlilik_bitis');
        self::assertRange($start, $end);
        $sql = "SELECT id FROM personel_ucret_gecmisi
                WHERE personel_id = :personel_id AND state = 'AKTIF'
                  AND gecerlilik_baslangic <= :yeni_bitis
                  AND (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= :yeni_baslangic)";
        $params = [
            'personel_id' => (int) $personelId,
            'yeni_bitis' => $end ?: '9999-12-31',
            'yeni_baslangic' => $start,
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = (int) $excludeId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new PersonelUcretException('SALARY_DATE_OVERLAP', 'Ucret gecerlilik tarihleri mevcut kayitla cakisiyor.', 409);
        }

        return true;
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $actor @return array<string, mixed> */
    public static function createSalaryRecord(PDO $pdo, $personelId, array $payload, array $actor = [], $requestHash = null)
    {
        $personelId = (int) $personelId;
        $normalized = self::normalizePayload($payload, true);
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $personel = self::lockPersonel($pdo, $personelId);
            if (!$personel) {
                throw new PersonelUcretException('SALARY_RECORD_NOT_FOUND', 'Personel bulunamadi.', 404);
            }

            $historyCount = $pdo->prepare('SELECT COUNT(*) FROM personel_ucret_gecmisi WHERE personel_id = :personel_id');
            $historyCount->execute(['personel_id' => $personelId]);
            $legacyStart = !empty($personel['ise_giris_tarihi']) ? (string) $personel['ise_giris_tarihi'] : '1900-01-01';
            if (
                (int) $historyCount->fetchColumn() === 0
                && $normalized['kaynak'] !== 'PERSONEL_KAYDI_MIGRASYON'
                && $personel['maas_tutari'] !== null
                && (float) $personel['maas_tutari'] > 0
                && $legacyStart < $normalized['gecerlilik_baslangic']
            ) {
                $legacyEnd = (new DateTimeImmutable($normalized['gecerlilik_baslangic']))->modify('-1 day')->format('Y-m-d');
                $legacyInsert = $pdo->prepare(
                    "INSERT INTO personel_ucret_gecmisi (
                       personel_id, ucret_tutari, ucret_turu, para_birimi,
                       gecerlilik_baslangic, gecerlilik_bitis, state, kaynak, aciklama,
                       created_by, updated_by
                     ) VALUES (
                       :personel_id, :ucret_tutari, 'NET', 'TRY',
                       :baslangic, :bitis, 'AKTIF', 'PERSONEL_KAYDI_MIGRASYON',
                       :aciklama, :created_by, :updated_by
                     )"
                );
                $legacyInsert->execute([
                    'personel_id' => $personelId,
                    'ucret_tutari' => $personel['maas_tutari'],
                    'baslangic' => $legacyStart,
                    'bitis' => $legacyEnd,
                    'aciklama' => 'Legacy personel maasindan tarihce gecisi',
                    'created_by' => self::actorId($actor),
                    'updated_by' => self::actorId($actor),
                ]);
                $legacyRecord = self::fetchRecord($pdo, (int) $pdo->lastInsertId());
                self::writeAudit($pdo, 'MIGRATE', null, $legacyRecord, $personel, $actor, $requestHash);
            }

            $openStmt = $pdo->prepare(
                "SELECT * FROM personel_ucret_gecmisi
                 WHERE personel_id = :personel_id AND state = 'AKTIF' AND gecerlilik_bitis IS NULL
                 ORDER BY gecerlilik_baslangic DESC, id DESC LIMIT 1" . self::forUpdate($pdo)
            );
            $openStmt->execute(['personel_id' => $personelId]);
            $open = $openStmt->fetch(PDO::FETCH_ASSOC);
            if ($open && (string) $open['gecerlilik_baslangic'] <= $normalized['gecerlilik_baslangic']) {
                if ((string) $open['gecerlilik_baslangic'] === $normalized['gecerlilik_baslangic']) {
                    throw new PersonelUcretException('SALARY_DATE_OVERLAP', 'Ayni baslangic tarihli aktif ucret kaydi zaten var.', 409);
                }
                $closeDate = (new DateTimeImmutable($normalized['gecerlilik_baslangic']))->modify('-1 day')->format('Y-m-d');
                if ($closeDate < (string) $open['gecerlilik_baslangic']) {
                    throw new PersonelUcretException('DATE_RANGE_INVALID', 'Kapanis tarihi kayit baslangicindan once olamaz.', 400);
                }
                $close = $pdo->prepare(
                    'UPDATE personel_ucret_gecmisi
                     SET gecerlilik_bitis = :bitis, updated_by = :updated_by, revision_no = revision_no + 1
                     WHERE id = :id'
                );
                $close->execute([
                    'bitis' => $closeDate,
                    'updated_by' => self::actorId($actor),
                    'id' => (int) $open['id'],
                ]);
                $closed = self::fetchRecord($pdo, (int) $open['id']);
                self::writeAudit($pdo, 'CLOSE', $open, $closed, $personel, $actor, $requestHash);
            }

            self::validateNoOverlap(
                $pdo,
                $personelId,
                $normalized['gecerlilik_baslangic'],
                $normalized['gecerlilik_bitis']
            );
            $stmt = $pdo->prepare(
                'INSERT INTO personel_ucret_gecmisi (
                    personel_id, ucret_tutari, ucret_turu, para_birimi,
                    gecerlilik_baslangic, gecerlilik_bitis, state, kaynak, aciklama,
                    created_by, updated_by
                 ) VALUES (
                    :personel_id, :ucret_tutari, :ucret_turu, :para_birimi,
                    :gecerlilik_baslangic, :gecerlilik_bitis, \'AKTIF\', :kaynak, :aciklama,
                    :created_by, :updated_by
                 )'
            );
            $stmt->execute([
                'personel_id' => $personelId,
                'ucret_tutari' => $normalized['ucret_tutari'],
                'ucret_turu' => $normalized['ucret_turu'],
                'para_birimi' => $normalized['para_birimi'],
                'gecerlilik_baslangic' => $normalized['gecerlilik_baslangic'],
                'gecerlilik_bitis' => $normalized['gecerlilik_bitis'],
                'kaynak' => $normalized['kaynak'],
                'aciklama' => $normalized['aciklama'],
                'created_by' => self::actorId($actor),
                'updated_by' => self::actorId($actor),
            ]);
            $record = self::fetchRecord($pdo, (int) $pdo->lastInsertId());
            self::writeAudit(
                $pdo,
                $normalized['kaynak'] === 'PERSONEL_KAYDI_MIGRASYON' ? 'MIGRATE' : 'CREATE',
                null,
                $record,
                $personel,
                $actor,
                $requestHash
            );
            self::syncLegacySalary($pdo, $personelId);
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $record ?: [];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PersonelUcretException) {
                throw $e;
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new PersonelUcretException('SALARY_DATE_OVERLAP', 'Ucret gecerlilik tarihleri mevcut kayitla cakisiyor.', 409);
            }
            throw $e;
        }
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $actor @return array<string, mixed> */
    public static function updateFutureSalaryRecord(PDO $pdo, $recordId, array $payload, array $actor = [], $requestHash = null)
    {
        return self::mutate($pdo, $recordId, $actor, function (array $record) use ($pdo, $payload) {
            if ((string) $record['state'] !== 'AKTIF' || (string) $record['gecerlilik_baslangic'] <= date('Y-m-d')) {
                throw new PersonelUcretException('SALARY_CHANGE_FORBIDDEN', 'Baslamis veya iptal edilmis ucret kaydi degistirilemez.', 409);
            }
            $merged = self::normalizePayload(array_merge($record, $payload), true);
            self::validateNoOverlap(
                $pdo,
                (int) $record['personel_id'],
                $merged['gecerlilik_baslangic'],
                $merged['gecerlilik_bitis'],
                (int) $record['id']
            );
            $stmt = $pdo->prepare(
                'UPDATE personel_ucret_gecmisi SET
                   ucret_tutari = :ucret_tutari, ucret_turu = :ucret_turu, para_birimi = :para_birimi,
                   gecerlilik_baslangic = :baslangic, gecerlilik_bitis = :bitis,
                   kaynak = :kaynak, aciklama = :aciklama, updated_by = :updated_by,
                   revision_no = revision_no + 1
                 WHERE id = :id'
            );
            $stmt->execute([
                'ucret_tutari' => $merged['ucret_tutari'],
                'ucret_turu' => $merged['ucret_turu'],
                'para_birimi' => $merged['para_birimi'],
                'baslangic' => $merged['gecerlilik_baslangic'],
                'bitis' => $merged['gecerlilik_bitis'],
                'kaynak' => $merged['kaynak'],
                'aciklama' => $merged['aciklama'],
                'updated_by' => self::actorId($actor),
                'id' => (int) $record['id'],
            ]);
        }, 'UPDATE', $requestHash);
    }

    /** @param array<string, mixed> $actor @return array<string, mixed> */
    public static function closeSalaryRecord(PDO $pdo, $recordId, $endDate, array $actor = [], $requestHash = null)
    {
        $endDate = self::validDate($endDate, 'gecerlilik_bitis');
        return self::mutate($pdo, $recordId, $actor, function (array $record) use ($pdo, $endDate, $actor) {
            if ((string) $record['state'] !== 'AKTIF') {
                throw new PersonelUcretException('SALARY_CHANGE_FORBIDDEN', 'Iptal edilmis ucret kaydi kapatilamaz.', 409);
            }
            self::assertRange((string) $record['gecerlilik_baslangic'], $endDate);
            self::validateNoOverlap($pdo, (int) $record['personel_id'], (string) $record['gecerlilik_baslangic'], $endDate, (int) $record['id']);
            $stmt = $pdo->prepare(
                'UPDATE personel_ucret_gecmisi
                 SET gecerlilik_bitis = :bitis, updated_by = :updated_by, revision_no = revision_no + 1
                 WHERE id = :id'
            );
            $stmt->execute(['bitis' => $endDate, 'updated_by' => self::actorId($actor), 'id' => (int) $record['id']]);
        }, 'CLOSE', $requestHash);
    }

    /** @param array<string, mixed> $actor @return array<string, mixed> */
    public static function cancelSalaryRecord(PDO $pdo, $recordId, array $actor = [], $requestHash = null)
    {
        return self::mutate($pdo, $recordId, $actor, function (array $record) use ($pdo, $actor) {
            if ((string) $record['state'] !== 'AKTIF') {
                throw new PersonelUcretException('SALARY_CHANGE_FORBIDDEN', 'Ucret kaydi zaten iptal.', 409);
            }
            $stmt = $pdo->prepare(
                "UPDATE personel_ucret_gecmisi SET state = 'IPTAL',
                   iptal_edildi_at = CURRENT_TIMESTAMP, iptal_edildi_by = :actor_id,
                   updated_by = :updated_by, revision_no = revision_no + 1
                 WHERE id = :id"
            );
            $stmt->execute([
                'actor_id' => self::actorId($actor),
                'updated_by' => self::actorId($actor),
                'id' => (int) $record['id'],
            ]);
        }, 'CANCEL', $requestHash);
    }

    /** @return array<string, mixed>|null */
    private static function fetchRecord(PDO $pdo, $recordId)
    {
        $stmt = $pdo->prepare('SELECT * FROM personel_ucret_gecmisi WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $recordId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param callable(array<string, mixed>): void $callback
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    private static function mutate(PDO $pdo, $recordId, array $actor, callable $callback, $action, $requestHash)
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $ownerStmt = $pdo->prepare('SELECT personel_id FROM personel_ucret_gecmisi WHERE id = :id LIMIT 1');
            $ownerStmt->execute(['id' => (int) $recordId]);
            $personelId = $ownerStmt->fetchColumn();
            if ($personelId === false) {
                throw new PersonelUcretException('SALARY_RECORD_NOT_FOUND', 'Ucret kaydi bulunamadi.', 404);
            }
            $personel = self::lockPersonel($pdo, (int) $personelId);
            $stmt = $pdo->prepare(
                'SELECT * FROM personel_ucret_gecmisi WHERE id = :id LIMIT 1' . self::forUpdate($pdo)
            );
            $stmt->execute(['id' => (int) $recordId]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                throw new PersonelUcretException('SALARY_RECORD_NOT_FOUND', 'Ucret kaydi bulunamadi.', 404);
            }
            $callback($before);
            $after = self::fetchRecord($pdo, (int) $recordId);
            self::writeAudit($pdo, $action, $before, $after, $personel ?: [], $actor, $requestHash);
            self::syncLegacySalary($pdo, (int) $before['personel_id']);
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $after ?: [];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PersonelUcretException) {
                throw $e;
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new PersonelUcretException('SALARY_DATE_OVERLAP', 'Ucret gecerlilik tarihleri mevcut kayitla cakisiyor.', 409);
            }
            throw $e;
        }
    }

    /** @return array<string, mixed>|null */
    private static function lockPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, sube_id, maas_tutari, ise_giris_tarihi
             FROM personeller WHERE id = :id LIMIT 1' . self::forUpdate($pdo)
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private static function syncLegacySalary(PDO $pdo, $personelId)
    {
        try {
            $current = self::resolveSalaryForDate($pdo, $personelId, date('Y-m-d'));
            $amount = $current['ucret_tutari'];
        } catch (PersonelUcretException $e) {
            if ($e->getCodeString() !== 'SALARY_MISSING') {
                throw $e;
            }
            $amount = null;
        }
        $stmt = $pdo->prepare('UPDATE personeller SET maas_tutari = :amount WHERE id = :id');
        $stmt->execute(['amount' => $amount, 'id' => (int) $personelId]);
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $personel
     * @param array<string, mixed> $actor
     */
    private static function writeAudit(PDO $pdo, $action, $before, $after, array $personel, array $actor, $requestHash)
    {
        $record = $after ?: $before;
        $stmt = $pdo->prepare(
            'INSERT INTO personel_ucret_auditleri (
               personel_id, ucret_kaydi_id, aksiyon, onceki_snapshot, sonraki_snapshot,
               actor_id, actor_rol, sube_id, request_hash
             ) VALUES (
               :personel_id, :ucret_kaydi_id, :aksiyon, :onceki_snapshot, :sonraki_snapshot,
               :actor_id, :actor_rol, :sube_id, :request_hash
             )'
        );
        $stmt->execute([
            'personel_id' => (int) ($record['personel_id'] ?? 0),
            'ucret_kaydi_id' => isset($record['id']) ? (int) $record['id'] : null,
            'aksiyon' => (string) $action,
            'onceki_snapshot' => self::snapshotJson($before),
            'sonraki_snapshot' => self::snapshotJson($after),
            'actor_id' => self::actorId($actor),
            'actor_rol' => isset($actor['rol']) ? (string) $actor['rol'] : null,
            'sube_id' => isset($personel['sube_id']) ? (int) $personel['sube_id'] : null,
            'request_hash' => $requestHash ? (string) $requestHash : null,
        ]);
    }

    /** @param array<string, mixed>|null $snapshot */
    private static function snapshotJson($snapshot)
    {
        if ($snapshot === null) {
            return null;
        }
        ksort($snapshot);

        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private static function normalizePayload(array $payload, $required)
    {
        $amount = $payload['ucret_tutari'] ?? null;
        if (!is_numeric($amount) || (float) $amount <= 0) {
            throw new PersonelUcretException('SALARY_AMOUNT_INVALID', 'Ucret tutari sifirdan buyuk olmalidir.', 400);
        }
        $type = strtoupper(trim((string) ($payload['ucret_turu'] ?? '')));
        if (!in_array($type, ['BRUT', 'NET'], true)) {
            throw new PersonelUcretException('SALARY_TYPE_INVALID', 'Ucret turu BRUT veya NET olmalidir.', 400);
        }
        $currency = strtoupper(trim((string) ($payload['para_birimi'] ?? 'TRY')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new PersonelUcretException('SALARY_CURRENCY_INVALID', 'Para birimi uc harfli ISO kodu olmalidir.', 400);
        }
        $start = self::validDate($payload['gecerlilik_baslangic'] ?? null, 'gecerlilik_baslangic');
        $end = self::optionalValidDate($payload['gecerlilik_bitis'] ?? null, 'gecerlilik_bitis');
        self::assertRange($start, $end);
        $source = strtoupper(trim((string) ($payload['kaynak'] ?? 'MANUEL')));
        if (!in_array($source, ['MANUEL', 'PERSONEL_KAYDI_MIGRASYON', 'SISTEM'], true)) {
            throw new PersonelUcretException('SALARY_SOURCE_INVALID', 'Gecersiz ucret kaynagi.', 400);
        }

        return [
            'ucret_tutari' => number_format((float) $amount, 2, '.', ''),
            'ucret_turu' => $type,
            'para_birimi' => $currency,
            'gecerlilik_baslangic' => $start,
            'gecerlilik_bitis' => $end,
            'kaynak' => $source,
            'aciklama' => self::optionalString($payload['aciklama'] ?? null, 500),
        ];
    }

    private static function assertRange($start, $end)
    {
        if ($end !== null && $end < $start) {
            throw new PersonelUcretException('DATE_RANGE_INVALID', 'Bitis tarihi baslangic tarihinden once olamaz.', 400);
        }
    }

    private static function validDate($value, $field)
    {
        $value = is_string($value) ? trim($value) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new PersonelUcretException('DATE_INVALID', $field . ' gecerli bir tarih olmalidir.', 400);
        }

        return $value;
    }

    private static function optionalValidDate($value, $field)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::validDate($value, $field);
    }

    private static function optionalString($value, $maxLength)
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (strlen($value) > (int) $maxLength) {
            throw new PersonelUcretException('VALIDATION_ERROR', 'Metin izin verilen uzunlugu asiyor.', 422);
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
}
