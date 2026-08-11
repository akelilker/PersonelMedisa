<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention;

use PDO;

/**
 * Canonical branch scope for retention/legal-hold/destruction list rows.
 * personel_id NULL is NOT globally visible to branch-scoped users.
 */
class RetentionScopeResolver
{
    /**
     * Resolve owning sube_id for a legal-hold / destruction / audit style row.
     * Returns null when genuinely company-global (no record/personel/period owner).
     *
     * @param array<string, mixed> $row
     * @return int|null
     */
    public static function resolveSubeId(PDO $pdo, array $row)
    {
        if (!empty($row['personel_id']) && (int) $row['personel_id'] > 0) {
            $sube = self::personelSube($pdo, (int) $row['personel_id']);
            if ($sube !== null) {
                return $sube;
            }
        }

        if (!empty($row['canonical_sube_id']) && (int) $row['canonical_sube_id'] > 0) {
            return (int) $row['canonical_sube_id'];
        }

        $domain = strtolower(trim((string) (
            $row['target_domain'] ?? $row['entity_type'] ?? $row['target_type'] ?? ''
        )));
        $recordId = 0;
        if (!empty($row['target_record_id'])) {
            $recordId = (int) $row['target_record_id'];
        } elseif (!empty($row['record_id'])) {
            $recordId = (int) $row['record_id'];
        } elseif (!empty($row['target_id'])) {
            $recordId = (int) $row['target_id'];
        }

        if ($recordId > 0) {
            if (in_array($domain, ['personel', 'personeller'], true)) {
                return self::personelSube($pdo, $recordId);
            }
            if (in_array($domain, ['surec', 'surecler'], true)) {
                return self::surecSube($pdo, $recordId);
            }
            if (in_array($domain, ['belge', 'belge_kaydi', 'personel_belge'], true)) {
                // Belge kaydı = surec BELGE row.
                return self::surecSube($pdo, $recordId);
            }
            if ($domain === 'haftalik_kapanis' || $domain === 'haftalik_kapanislar') {
                return self::haftalikSube($pdo, $recordId);
            }
        }

        if (!empty($row['sube_id']) && (int) $row['sube_id'] > 0) {
            return (int) $row['sube_id'];
        }

        return null;
    }

    /**
     * Filter rows for branch-scoped users. Global-only rows require empty allowed list (GM).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int>|null $allowedSubeIds empty/null = global authorized
     * @return array<int, array<string, mixed>>
     */
    public static function filterRowsBySubeScope(PDO $pdo, array $rows, $allowedSubeIds)
    {
        if ($allowedSubeIds === null || (is_array($allowedSubeIds) && count($allowedSubeIds) === 0)) {
            return $rows;
        }

        $allowed = [];
        foreach ($allowedSubeIds as $sid) {
            $allowed[(int) $sid] = true;
        }

        $out = [];
        foreach ($rows as $row) {
            $sube = self::resolveSubeId($pdo, $row);
            if ($sube === null) {
                // Genuinely global — branch-scoped roles must not see.
                continue;
            }
            if (isset($allowed[$sube])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private static function personelSube(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare('SELECT sube_id FROM personeller WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['sube_id'] : null;
    }

    private static function surecSube(PDO $pdo, $surecId)
    {
        if (!self::tableExists($pdo, 'surecler')) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT p.sube_id
             FROM surecler s
             INNER JOIN personeller p ON p.id = s.personel_id
             WHERE s.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => (int) $surecId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['sube_id'] : null;
    }

    private static function haftalikSube(PDO $pdo, $id)
    {
        if (!self::tableExists($pdo, 'haftalik_kapanislar')) {
            return null;
        }
        $stmt = $pdo->prepare('SELECT sube_id FROM haftalik_kapanislar WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? (int) $row['sube_id'] : null;
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => (string) $table]);

        return (bool) $stmt->fetchColumn();
    }
}
