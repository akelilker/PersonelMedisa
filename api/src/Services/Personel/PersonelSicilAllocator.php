<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use PDO;

/**
 * Canonical owner of automatic personel sicil allocation.
 *
 * Model:
 * - Persistent single-row counter (personel_sicil_sequence.next_value) is the floor.
 * - Allocation runs inside the caller's personel-create transaction under a row lock,
 *   so two concurrent creates can never observe the same candidate.
 * - Numeric sicils are formatted with at least three digits (1 → 001, 382 → 382, 1000 → 1000).
 * - Non-numeric legacy sicils are ignored by the counter and never rewritten.
 */
final class PersonelSicilAllocator
{
    public const SEQUENCE_TABLE = 'personel_sicil_sequence';
    public const SEQUENCE_ID = 1;
    public const ERROR_CODE = 'SICIL_AUTO_ASSIGN_FAILED';
    public const MIN_WIDTH = 3;

    /** Upper bound for candidate probing inside one allocation. */
    public const MAX_PROBE = 10000;

    public static function isAutoRequest($sicilNo): bool
    {
        return $sicilNo === null || trim((string) $sicilNo) === '';
    }

    public static function format(int $value): string
    {
        return str_pad((string) $value, self::MIN_WIDTH, '0', STR_PAD_LEFT);
    }

    /** @param mixed $sicilNo @return int|null Numeric value, or null for legacy/non-numeric sicils. */
    public static function numericValue($sicilNo)
    {
        if ($sicilNo === null) {
            return null;
        }
        $raw = trim((string) $sicilNo);
        if ($raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Lowest candidate implied by an existing sicil set (non-numeric entries ignored).
     *
     * @param iterable<mixed> $sicilList
     */
    public static function floorFromExisting($sicilList): int
    {
        $max = 0;
        foreach ($sicilList as $sicil) {
            $numeric = self::numericValue($sicil);
            if ($numeric !== null && $numeric > $max) {
                $max = $numeric;
            }
        }

        return $max + 1;
    }

    public static function schemaReady(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '" . self::SEQUENCE_TABLE . "'");

            return $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Allocate the next free numeric sicil. Must run inside the create transaction.
     */
    public static function allocateInTransaction(PDO $pdo): string
    {
        if (!$pdo->inTransaction()) {
            throw new PersonelSicilAllocationException(
                'Sicil tahsisi personel kayit transaction icinde yapilmalidir.'
            );
        }
        if (!self::schemaReady($pdo)) {
            throw new PersonelSicilAllocationException(
                'Sicil sirasi semasi hazir degil; otomatik sicil atanamadi.'
            );
        }

        $locked = self::lockSequenceRow($pdo);
        if ($locked === null) {
            self::insertSequenceRow($pdo);
            $locked = self::lockSequenceRow($pdo);
        }
        if ($locked === null) {
            throw new PersonelSicilAllocationException('Sicil sirasi kilitlenemedi.');
        }

        $candidate = max(1, $locked, self::maxNumericSicil($pdo) + 1);
        $probes = 0;
        while (self::sicilTaken($pdo, self::format($candidate))) {
            $candidate++;
            $probes++;
            if ($probes >= self::MAX_PROBE) {
                throw new PersonelSicilAllocationException('Bos sicil numarasi bulunamadi.');
            }
        }

        $upd = $pdo->prepare(
            'UPDATE ' . self::SEQUENCE_TABLE . '
             SET next_value = GREATEST(next_value, :next_value)
             WHERE id = :id'
        );
        $upd->execute(['next_value' => $candidate + 1, 'id' => self::SEQUENCE_ID]);

        return self::format($candidate);
    }

    /** @return int|null Locked next_value, or null when the singleton row is missing. */
    private static function lockSequenceRow(PDO $pdo)
    {
        $forUpdate = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $stmt = $pdo->prepare(
            'SELECT next_value FROM ' . self::SEQUENCE_TABLE . ' WHERE id = :id' . $forUpdate
        );
        $stmt->execute(['id' => self::SEQUENCE_ID]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private static function insertSequenceRow(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO ' . self::SEQUENCE_TABLE . ' (id, next_value) VALUES (:id, :next_value)'
        );
        $stmt->execute([
            'id' => self::SEQUENCE_ID,
            'next_value' => self::maxNumericSicil($pdo) + 1,
        ]);
    }

    private static function maxNumericSicil(PDO $pdo): int
    {
        $stmt = $pdo->query(
            "SELECT COALESCE(MAX(CAST(sicil_no AS UNSIGNED)), 0)
             FROM personeller
             WHERE sicil_no REGEXP '^[0-9]+$'"
        );
        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }

    private static function sicilTaken(PDO $pdo, string $sicilNo): bool
    {
        $stmt = $pdo->prepare('SELECT id FROM personeller WHERE sicil_no = :sicil_no LIMIT 1');
        $stmt->execute(['sicil_no' => $sicilNo]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }
}
