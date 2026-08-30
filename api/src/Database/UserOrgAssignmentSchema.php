<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use Medisa\Api\Services\Organizasyon\OrganizasyonSchema;
use PDO;

/**
 * Readiness and loaders for the user↔org assignment tables:
 * user_bolumler / user_birimler (071) and user_sirketler / user_sgk_isverenler (079).
 */
class UserOrgAssignmentSchema
{
    /** @var bool|null */
    private static $ready = null;

    /** @var bool|null */
    private static $subeYoneticisiEnumReady = null;

    /** @var bool|null */
    private static $hierarchyScopeReady = null;

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

    /** user_sirketler / user_sgk_isverenler scope tables (079). */
    public static function isHierarchyScopeReady(PDO $pdo)
    {
        if (self::$hierarchyScopeReady !== null) {
            return self::$hierarchyScopeReady;
        }

        self::$hierarchyScopeReady = OrganizasyonSchema::isSchemaReady($pdo);

        return self::$hierarchyScopeReady;
    }

    /** @return array<int, int> */
    public static function loadUserSirketIds(PDO $pdo, $userId)
    {
        return self::loadHierarchyScopeIds($pdo, $userId, 'user_sirketler', 'sirket_id');
    }

    /** @return array<int, int> */
    public static function loadUserSgkIsverenIds(PDO $pdo, $userId)
    {
        return self::loadHierarchyScopeIds($pdo, $userId, 'user_sgk_isverenler', 'sgk_isveren_id');
    }

    /**
     * Branches currently owned by the given companies.
     *
     * Resolved live on every request on purpose: this is what makes a company
     * scope dynamic. Materialising it into user_subeler would freeze the grant
     * at assignment time, so a branch created later would stay invisible until
     * somebody remembered to re-copy the ids.
     *
     * @param array<int, int> $sirketIds
     * @return array<int, int>
     */
    public static function resolveSubeIdsForSirketIds(PDO $pdo, array $sirketIds)
    {
        $sirketIds = self::normalizeIds($sirketIds);
        if (count($sirketIds) === 0 || !self::isHierarchyScopeReady($pdo)) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($sirketIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id FROM subeler WHERE sirket_id IN ($placeholders) ORDER BY id ASC"
        );
        $stmt->execute($sirketIds);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /** @param array<int, int> $sirketIds */
    public static function replaceUserSirketler(PDO $pdo, $userId, array $sirketIds)
    {
        self::replaceHierarchyScope($pdo, $userId, 'user_sirketler', 'sirket_id', $sirketIds);
    }

    /** @param array<int, int> $sgkIsverenIds */
    public static function replaceUserSgkIsverenler(PDO $pdo, $userId, array $sgkIsverenIds)
    {
        self::replaceHierarchyScope($pdo, $userId, 'user_sgk_isverenler', 'sgk_isveren_id', $sgkIsverenIds);
    }

    /** @return array<int, int> */
    private static function loadHierarchyScopeIds(PDO $pdo, $userId, $table, $column)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || !self::isHierarchyScopeReady($pdo)) {
            return [];
        }

        $stmt = $pdo->prepare("SELECT {$column} AS link_id FROM {$table} WHERE user_id = :user_id ORDER BY {$column} ASC");
        $stmt->execute(['user_id' => $userId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (int) $row['link_id'];
        }

        return $ids;
    }

    /** @param array<int, int> $ids */
    private static function replaceHierarchyScope(PDO $pdo, $userId, $table, $column, array $ids)
    {
        $userId = (int) $userId;
        if ($userId <= 0 || !self::isHierarchyScopeReady($pdo)) {
            return;
        }

        $delete = $pdo->prepare("DELETE FROM {$table} WHERE user_id = :user_id");
        $delete->execute(['user_id' => $userId]);

        $ids = self::normalizeIds($ids);
        if (count($ids) === 0) {
            return;
        }

        $insert = $pdo->prepare("INSERT INTO {$table} (user_id, {$column}) VALUES (:user_id, :link_id)");
        foreach ($ids as $id) {
            $insert->execute(['user_id' => $userId, 'link_id' => $id]);
        }
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    private static function normalizeIds(array $ids)
    {
        $normalized = [];
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /** Test helper — clear process cache. */
    public static function resetCache()
    {
        self::$ready = null;
        self::$subeYoneticisiEnumReady = null;
        self::$hierarchyScopeReady = null;
        OrganizasyonSchema::resetCache();
    }
}
