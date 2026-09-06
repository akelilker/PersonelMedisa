<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Organizasyon;

use PDO;

/**
 * Readiness + load/replace owner for branch accounting visibility ACL.
 *
 * Relation presence on a branch enables the restriction; empty = disabled.
 * Only MUHASEBE enforcement consumes this table (see OrgScope).
 */
final class SubeMuhasebeYetkiSchema
{
    /** @var bool|null */
    private static $ready = null;

    public static function isReady(PDO $pdo): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }

        try {
            $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->query(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sube_muhasebe_yetkilileri' LIMIT 1"
                );
                self::$ready = $stmt !== false && $stmt->fetchColumn() !== false;

                return self::$ready;
            }

            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table
                 LIMIT 1'
            );
            $stmt->execute(['table' => 'sube_muhasebe_yetkilileri']);
            self::$ready = $stmt->fetchColumn() !== false;

            return self::$ready;
        } catch (\Throwable $e) {
            self::$ready = false;

            return false;
        }
    }

    /** Test/harness helper — production callers never reset. */
    public static function resetCache(): void
    {
        self::$ready = null;
    }

    /**
     * @return array<int, int>
     */
    public static function loadUserIdsForSube(PDO $pdo, int $subeId): array
    {
        if ($subeId <= 0 || !self::isReady($pdo)) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT user_id FROM sube_muhasebe_yetkilileri WHERE sube_id = :sube_id ORDER BY user_id ASC'
        );
        $stmt->execute(['sube_id' => $subeId]);
        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['user_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Map of restricted branch id => selected user ids.
     *
     * @param array<int, int> $subeIds
     * @return array<int, array<int, int>>
     */
    public static function loadRestrictedSubeUserMap(PDO $pdo, array $subeIds = []): array
    {
        if (!self::isReady($pdo)) {
            return [];
        }

        $sql = 'SELECT sube_id, user_id FROM sube_muhasebe_yetkilileri';
        $params = [];
        if (count($subeIds) > 0) {
            $placeholders = [];
            foreach (array_values($subeIds) as $index => $subeId) {
                $key = 's' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = (int) $subeId;
            }
            $sql .= ' WHERE sube_id IN (' . implode(', ', $placeholders) . ')';
        }
        $sql .= ' ORDER BY sube_id ASC, user_id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subeId = (int) ($row['sube_id'] ?? 0);
            $userId = (int) ($row['user_id'] ?? 0);
            if ($subeId <= 0 || $userId <= 0) {
                continue;
            }
            if (!isset($map[$subeId])) {
                $map[$subeId] = [];
            }
            $map[$subeId][] = $userId;
        }

        return $map;
    }

    /**
     * Branches that currently have an accounting restriction enabled.
     *
     * @return array<int, int>
     */
    public static function loadRestrictedSubeIds(PDO $pdo): array
    {
        if (!self::isReady($pdo)) {
            return [];
        }

        $stmt = $pdo->query(
            'SELECT DISTINCT sube_id FROM sube_muhasebe_yetkilileri ORDER BY sube_id ASC'
        );
        if ($stmt === false) {
            return [];
        }

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) ($row['sube_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<int, int> $userIds
     */
    public static function replaceForSube(PDO $pdo, int $subeId, array $userIds): void
    {
        if ($subeId <= 0) {
            throw OrganizasyonException::validation('Geçersiz şube id.', 'id');
        }
        if (!self::isReady($pdo)) {
            throw OrganizasyonException::conflict(
                'SUBE_MUHASEBE_YETKILERI_TABLE_MISSING',
                'Şube muhasebe yetkilisi tablosu bu ortamda henüz hazır değil.'
            );
        }

        $unique = [];
        foreach ($userIds as $userId) {
            $parsed = (int) $userId;
            if ($parsed > 0) {
                $unique[$parsed] = $parsed;
            }
        }
        $unique = array_values($unique);

        $delete = $pdo->prepare('DELETE FROM sube_muhasebe_yetkilileri WHERE sube_id = :sube_id');
        $delete->execute(['sube_id' => $subeId]);

        if (count($unique) === 0) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO sube_muhasebe_yetkilileri (sube_id, user_id) VALUES (:sube_id, :user_id)'
        );
        foreach ($unique as $userId) {
            $insert->execute(['sube_id' => $subeId, 'user_id' => $userId]);
        }
    }
}
