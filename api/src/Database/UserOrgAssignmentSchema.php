<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;

/**
 * Readiness for user↔bolum / user↔birim assignment tables (071).
 */
class UserOrgAssignmentSchema
{
    /** @var bool|null */
    private static $ready = null;

    /** @var bool|null */
    private static $subeYoneticisiEnumReady = null;

    public static function isReady(PDO $pdo)
    {
        if (self::$ready !== null) {
            return self::$ready;
        }

        try {
            $bolum = $pdo->query("SHOW TABLES LIKE 'user_bolumler'");
            $hasBolum = $bolum !== false && $bolum->fetch(PDO::FETCH_NUM) !== false;
            if ($bolum !== false) {
                $bolum->closeCursor();
            }
            $birim = $pdo->query("SHOW TABLES LIKE 'user_birimler'");
            $hasBirim = $birim !== false && $birim->fetch(PDO::FETCH_NUM) !== false;
            if ($birim !== false) {
                $birim->closeCursor();
            }
            self::$ready = $hasBolum && $hasBirim;
        } catch (\Throwable $e) {
            self::$ready = false;
        }

        return self::$ready;
    }

    /** users.rol ENUM includes SUBE_YONETICISI (071). */
    public static function isSubeYoneticisiRoleReady(PDO $pdo)
    {
        if (self::$subeYoneticisiEnumReady !== null) {
            return self::$subeYoneticisiEnumReady;
        }

        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'rol'");
            $row = $col !== false ? $col->fetch(PDO::FETCH_ASSOC) : false;
            if ($col !== false) {
                $col->closeCursor();
            }
            $type = is_array($row) && isset($row['Type']) ? (string) $row['Type'] : '';
            self::$subeYoneticisiEnumReady = stripos($type, 'SUBE_YONETICISI') !== false;
        } catch (\Throwable $e) {
            self::$subeYoneticisiEnumReady = false;
        }

        return self::$subeYoneticisiEnumReady;
    }

    /** @return array<int, int> */
    public static function loadUserBolumIds(PDO $pdo, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || !self::isReady($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT bolum_id FROM user_bolumler WHERE user_id = :user_id ORDER BY bolum_id ASC');
        $stmt->execute(['user_id' => $userId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (int) $row['bolum_id'];
        }

        return $ids;
    }

    /** @return array<int, int> */
    public static function loadUserBirimIds(PDO $pdo, $userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || !self::isReady($pdo)) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT birim_id FROM user_birimler WHERE user_id = :user_id ORDER BY birim_id ASC');
        $stmt->execute(['user_id' => $userId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (int) $row['birim_id'];
        }

        return $ids;
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<int, int>>
     */
    public static function loadBolumIdsByUserIds(PDO $pdo, array $userIds)
    {
        return self::loadLinkIdsByUserIds($pdo, $userIds, 'user_bolumler', 'bolum_id');
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<int, int>>
     */
    public static function loadBirimIdsByUserIds(PDO $pdo, array $userIds)
    {
        return self::loadLinkIdsByUserIds($pdo, $userIds, 'user_birimler', 'birim_id');
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<int, int>>
     */
    private static function loadLinkIdsByUserIds(PDO $pdo, array $userIds, $table, $column)
    {
        $map = [];
        foreach ($userIds as $id) {
            $map[(int) $id] = [];
        }
        if (count($userIds) === 0 || !self::isReady($pdo)) {
            return $map;
        }
        $placeholders = implode(', ', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT user_id, {$column} AS link_id FROM {$table} WHERE user_id IN ($placeholders) ORDER BY user_id ASC, {$column} ASC"
        );
        $stmt->execute(array_values($userIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int) $row['user_id'];
            if (!isset($map[$uid])) {
                $map[$uid] = [];
            }
            $map[$uid][] = (int) $row['link_id'];
        }

        return $map;
    }

    /** Test helper — clear process cache. */
    public static function resetCache()
    {
        self::$ready = null;
        self::$subeYoneticisiEnumReady = null;
    }
}
