<?php

declare(strict_types=1);

namespace Medisa\Api\Services;

use PDO;

/**
 * Serializes every puantaj mutation for the same (sube, yil, ay) tuple.
 * The caller owns the transaction and must acquire this lock before row locks.
 */
class PuantajDonemKilidiService
{
    private const TABLE = 'puantaj_donem_kilitleri';

    /** @return array{sube_id: int, yil: int, ay: int} */
    public static function acquire(PDO $pdo, $subeId, $yil, $ay)
    {
        $subeId = (int) $subeId;
        $yil = (int) $yil;
        $ay = (int) $ay;
        if (!$pdo->inTransaction()) {
            throw new \LogicException('Puantaj donem kilidi aktif transaction gerektirir.');
        }
        if ($subeId < 1 || $yil < 2000 || $yil > 2100 || $ay < 1 || $ay > 12) {
            throw new \InvalidArgumentException('Gecersiz puantaj donemi.');
        }

        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insertSql = ($driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT')
            . ' INTO ' . self::TABLE . ' (sube_id, yil, ay) VALUES (:sube_id, :yil, :ay)';
        $insert = $pdo->prepare($insertSql);
        try {
            $insert->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);
        } catch (\PDOException $e) {
            if ($driver === 'sqlite' || !self::isDuplicateKey($e)) {
                throw $e;
            }
        }

        $selectSql = 'SELECT sube_id, yil, ay FROM ' . self::TABLE
            . ' WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay LIMIT 1'
            . ($driver === 'sqlite' ? '' : ' FOR UPDATE');
        $select = $pdo->prepare($selectSql);
        $select->execute(['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay]);
        if (!$select->fetch(PDO::FETCH_ASSOC)) {
            throw new \RuntimeException('Puantaj donem kilidi alinamadi.');
        }

        return ['sube_id' => $subeId, 'yil' => $yil, 'ay' => $ay];
    }

    /** @param array{sube_id: int, yil: int, ay: int} $lock */
    public static function isSealed(PDO $pdo, array $lock)
    {
        $stmt = $pdo->prepare(
            'SELECT id FROM puantaj_aylik_muhurleri
             WHERE sube_id = :sube_id AND yil = :yil AND ay = :ay LIMIT 1'
        );
        $stmt->execute([
            'sube_id' => (int) $lock['sube_id'],
            'yil' => (int) $lock['yil'],
            'ay' => (int) $lock['ay'],
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /** @return array{sube_id: int, yil: int, ay: int} */
    public static function acquireForDate(PDO $pdo, $subeId, $tarih)
    {
        $tarih = (string) $tarih;
        if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $tarih, $matches)) {
            throw new \InvalidArgumentException('Gecersiz puantaj tarihi.');
        }

        return self::acquire($pdo, $subeId, (int) $matches[1], (int) $matches[2]);
    }

    /** @param array{sube_id: int, yil: int, ay: int} $lock */
    public static function matchesDate(array $lock, $subeId, $tarih)
    {
        $tarih = (string) $tarih;

        return (int) $lock['sube_id'] === (int) $subeId
            && (int) $lock['yil'] === (int) substr($tarih, 0, 4)
            && (int) $lock['ay'] === (int) substr($tarih, 5, 2);
    }

    private static function isDuplicateKey(\PDOException $e)
    {
        $info = $e->errorInfo ?? [];

        return (isset($info[1]) && (int) $info[1] === 1062)
            || strpos($e->getMessage(), '1062') !== false;
    }
}
