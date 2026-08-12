<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

/**
 * S87: aylik muhur revision + donem state (SEALED / REOPEN_PENDING / REOPENED).
 */
class PuantajDonemPeriodService
{
    public const STATE_ACIK = 'ACIK';
    public const STATE_SEALED = 'SEALED';
    public const STATE_REOPEN_PENDING = 'REOPEN_PENDING';
    public const STATE_REOPENED = 'REOPENED';

    public const TALEP_ONAY_BEKLIYOR = 'ONAY_BEKLIYOR';
    public const TALEP_ONAYLANDI = 'ONAYLANDI';
    public const TALEP_REDDEDILDI = 'REDDEDILDI';
    public const TALEP_UYGULANDI = 'UYGULANDI';

    public const DURUM_MUHURLENDI = 'MUHURLENDI';
    public const DURUM_SUPERSEDED = 'SUPERSEDED';

    /** @return array<string, mixed>|null */
    public static function findEffectiveSeal(PDO $pdo, $subeId, $yil, $ay, $forUpdate = false)
    {
        $sql = "SELECT * FROM puantaj_aylik_muhurleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay AND durum = 'MUHURLENDI'
             LIMIT 1";
        if ($forUpdate) {
            $sql .= self::forUpdate($pdo);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function hasEffectiveSeal(PDO $pdo, $subeId, $yil, $ay)
    {
        return self::findEffectiveSeal($pdo, $subeId, $yil, $ay) !== null;
    }

    /** @return array<string, mixed>|null open = ONAY_BEKLIYOR | ONAYLANDI */
    public static function findOpenReopenTalep(PDO $pdo, $subeId, $yil, $ay, $forUpdate = false)
    {
        if (!self::tableExists($pdo, 'puantaj_donem_reopen_talepleri')) {
            return null;
        }
        $sql = "SELECT * FROM puantaj_donem_reopen_talepleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
               AND talep_durumu IN ('ONAY_BEKLIYOR', 'ONAYLANDI')
             ORDER BY id DESC LIMIT 1";
        if ($forUpdate) {
            $sql .= self::forUpdate($pdo);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    public static function findActivePayrollSnapshot(PDO $pdo, $subeId, $yil, $ay, $forUpdate = false)
    {
        if (!self::tableExists($pdo, 'maas_hesaplama_donem_snapshotlari')) {
            return null;
        }
        $sql = "SELECT * FROM maas_hesaplama_donem_snapshotlari
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay AND state = 'OLUSTURULDU'
             LIMIT 1";
        if ($forUpdate) {
            $sql .= self::forUpdate($pdo);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function resolvePeriodState(PDO $pdo, $subeId, $yil, $ay)
    {
        $seal = self::findEffectiveSeal($pdo, $subeId, $yil, $ay);
        if ($seal === null) {
            return self::STATE_ACIK;
        }
        $talep = self::findOpenReopenTalep($pdo, $subeId, $yil, $ay);
        if ($talep === null) {
            return self::STATE_SEALED;
        }
        if ((string) $talep['talep_durumu'] === self::TALEP_ONAY_BEKLIYOR) {
            return self::STATE_REOPEN_PENDING;
        }
        if ((string) $talep['talep_durumu'] === self::TALEP_ONAYLANDI) {
            return self::STATE_REOPENED;
        }

        return self::STATE_SEALED;
    }

    public static function isPeriodReopened(PDO $pdo, $subeId, $yil, $ay)
    {
        return self::resolvePeriodState($pdo, $subeId, $yil, $ay) === self::STATE_REOPENED;
    }

    /**
     * Non-canonical domain writes (bildirim apply, FM tercih, etc.):
     * locked whenever an effective seal exists (reopen does not unlock these).
     */
    public static function isOperationallySealed(PDO $pdo, $subeId, $yil, $ay)
    {
        return self::hasEffectiveSeal($pdo, $subeId, $yil, $ay);
    }

    /**
     * Classic PERIOD_LOCKED for generic callers that should treat REOPENED as unlocked
     * only via assertCanonicalWriteAllowed.
     */
    public static function isWriteLocked(PDO $pdo, $subeId, $yil, $ay)
    {
        $state = self::resolvePeriodState($pdo, $subeId, $yil, $ay);

        return $state === self::STATE_SEALED || $state === self::STATE_REOPEN_PENDING;
    }

    /**
     * Read-only mirror of assertCanonicalWriteAllowed (S3E candidate period metadata).
     *
     * @return array{
     *   state: string,
     *   period_write_locked: bool,
     *   canonical_write_open: bool,
     *   canonical_write_block_code: string|null
     * }
     */
    public static function resolveCanonicalWriteContext(PDO $pdo, $subeId, $yil, $ay)
    {
        $state = self::resolvePeriodState($pdo, $subeId, $yil, $ay);
        if ($state === self::STATE_ACIK) {
            return [
                'state' => $state,
                'period_write_locked' => false,
                'canonical_write_open' => true,
                'canonical_write_block_code' => null,
            ];
        }
        if ($state === self::STATE_SEALED || $state === self::STATE_REOPEN_PENDING) {
            return [
                'state' => $state,
                'period_write_locked' => true,
                'canonical_write_open' => false,
                'canonical_write_block_code' => 'PERIOD_LOCKED',
            ];
        }
        $snap = self::findActivePayrollSnapshot($pdo, $subeId, $yil, $ay);
        if ($snap !== null) {
            return [
                'state' => $state,
                'period_write_locked' => false,
                'canonical_write_open' => false,
                'canonical_write_block_code' => 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED',
            ];
        }

        return [
            'state' => $state,
            'period_write_locked' => false,
            'canonical_write_open' => true,
            'canonical_write_block_code' => null,
        ];
    }

    /**
     * @throws PuantajDonemReopenException
     */
    public static function assertCanonicalWriteAllowed(PDO $pdo, $subeId, $yil, $ay)
    {
        $state = self::resolvePeriodState($pdo, $subeId, $yil, $ay);
        if ($state === self::STATE_ACIK) {
            return;
        }
        if ($state === self::STATE_SEALED || $state === self::STATE_REOPEN_PENDING) {
            throw new PuantajDonemReopenException(
                'PERIOD_LOCKED',
                'Bu donem muhurlenmis, puantaj kaydi guncellenemez.',
                409
            );
        }
        // REOPENED
        $snap = self::findActivePayrollSnapshot($pdo, $subeId, $yil, $ay);
        if ($snap !== null) {
            throw new PuantajDonemReopenException(
                'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED',
                'Aktif maas snapshot iptal edilmeden canonical duzeltme yapilamaz.',
                409,
                ['snapshot_id' => (int) $snap['id'], 'revision_no' => (int) $snap['revision_no']]
            );
        }
    }

    /**
     * @throws PuantajDonemReopenException
     */
    public static function assertPayrollMutationAllowed(PDO $pdo, $subeId, $yil, $ay)
    {
        if (self::isPeriodReopened($pdo, $subeId, $yil, $ay)) {
            throw new PuantajDonemReopenException(
                'PERIOD_REOPENED',
                'Donem reopen oturumunda; maas snapshot/hesaplama yapilamaz.',
                409
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public static function listSealHistory(PDO $pdo, $subeId, $yil, $ay)
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM puantaj_aylik_muhurleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
             ORDER BY revision_no ASC, id ASC'
        );
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function listReopenTalepleri(PDO $pdo, $subeId, $yil, $ay)
    {
        if (!self::tableExists($pdo, 'puantaj_donem_reopen_talepleri')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT * FROM puantaj_donem_reopen_talepleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay
             ORDER BY id ASC'
        );
        $stmt->execute([
            'sube_id' => (int) $subeId,
            'yil' => (int) $yil,
            'ay' => (int) $ay,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function nextRevisionNo(PDO $pdo, $subeId, $yil, $ay)
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COALESCE(MAX(revision_no), 0) AS max_rev
                 FROM puantaj_aylik_muhurleri
                 WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay'
            );
            $stmt->execute([
                'sube_id' => (int) $subeId,
                'yil' => (int) $yil,
                'ay' => (int) $ay,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['max_rev' => 0];

            return ((int) $row['max_rev']) + 1;
        } catch (\Throwable $e) {
            throw new \RuntimeException('revision_no hesaplanamadi: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @param mixed $value */
    public static function hashCanonical($value)
    {
        return hash('sha256', json_encode(self::canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param mixed $value @return mixed */
    public static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out = [];
            foreach ($value as $item) {
                $out[] = self::canonicalize($item);
            }

            return $out;
        }
        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = self::canonicalize($item);
        }

        return $out;
    }

    private static function forUpdate(PDO $pdo)
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        return $driver === 'sqlite' ? '' : ' FOR UPDATE';
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :t");
            $stmt->execute(['t' => $table]);

            return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => $table]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
