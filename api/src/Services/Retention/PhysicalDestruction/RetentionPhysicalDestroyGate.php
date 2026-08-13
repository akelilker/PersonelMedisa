<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Retention\PhysicalDestruction;

use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;
use RuntimeException;

/**
 * Transaction-scoped, retention-only DELETE gate for immutable BORDRO/SGK/SERBEST tables.
 *
 * Opened only by PhysicalDestructionService after feature flag + eligibility + PREPARED evidence.
 * Triggers allow DELETE solely when CONNECTION_ID() has an open gate tied to PREPARED execution.
 * Connection reuse must not leak: close() deletes the gate row before commit/rollback cleanup.
 */
final class RetentionPhysicalDestroyGate
{
    /**
     * Categories whose target tables have BEFORE DELETE immutability triggers.
     *
     * @return list<string>
     */
    public static function gatedCategories()
    {
        return [
            RetentionCategories::BORDRO,
            RetentionCategories::SGK_EKSIK_GUN,
            RetentionCategories::SERBEST_ZAMAN,
        ];
    }

    public static function requiresGate($category)
    {
        return in_array((string) $category, self::gatedCategories(), true);
    }

    /**
     * Pack 4B SERBEST destroy readiness: allocation ledger + gate tables + 062-gated DELETE trigger.
     * Distinguishes Pack 4A hard-block trg_szkt_no_delete from Pack 4B retention-gated variant.
     */
    public static function isSerbestZamanPack4bReady(PDO $pdo)
    {
        if (!self::tableExists($pdo, 'serbest_zaman_kullanim_tahsisleri')
            || !self::tableExists($pdo, 'retention_physical_destroy_gates')
            || !self::tableExists($pdo, 'retention_imha_executionlari')
        ) {
            return false;
        }

        $stmt = $pdo->prepare(
            "SELECT ACTION_STATEMENT
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE()
               AND EVENT_OBJECT_TABLE = 'serbest_zaman_kullanim_tahsisleri'
               AND ACTION_TIMING = 'BEFORE'
               AND EVENT_MANIPULATION = 'DELETE'
             LIMIT 1"
        );
        $stmt->execute();
        $action = (string) $stmt->fetchColumn();
        if ($action === '') {
            return false;
        }

        // Semantic markers of 062 retention-gated DELETE (not Pack 4A hard SIGNAL-only).
        foreach (['retention_physical_destroy_gates', 'SERBEST_ZAMAN', 'PREPARED'] as $marker) {
            if (stripos($action, $marker) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @throws RuntimeException PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_ALLOCATION_SCHEMA_NOT_READY
     */
    public static function assertSerbestZamanPack4bReady(PDO $pdo)
    {
        if (!self::isSerbestZamanPack4bReady($pdo)) {
            throw new RuntimeException(
                PhysicalDestructionCodes::CODE_SERBEST_ZAMAN_ALLOCATION_SCHEMA_NOT_READY
            );
        }
    }

    /**
     * @throws RuntimeException when gate schema missing for a gated category
     */
    public static function open(PDO $pdo, $executionId, $imhaTalepId, $category)
    {
        $category = (string) $category;
        if (!self::requiresGate($category)) {
            return;
        }
        if (!self::tableExists($pdo, 'retention_physical_destroy_gates')
            || !self::tableExists($pdo, 'retention_imha_executionlari')
        ) {
            throw new RuntimeException('TECHNICAL_BLOCKER_TRIGGER_POLICY');
        }

        $executionId = (int) $executionId;
        $imhaTalepId = (int) $imhaTalepId;
        if ($executionId <= 0 || $imhaTalepId <= 0) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }

        // Fail-closed: gate only while PREPARED evidence exists for this execution + category.
        $chk = $pdo->prepare(
            "SELECT e.id
             FROM retention_imha_executionlari e
             INNER JOIN retention_imha_talepleri t ON t.id = e.imha_talep_id
             WHERE e.id = :eid
               AND e.imha_talep_id = :tid
               AND e.execution_state = 'PREPARED'
               AND t.category = :category
             LIMIT 1"
        );
        $chk->execute([
            'eid' => $executionId,
            'tid' => $imhaTalepId,
            'category' => $category,
        ]);
        if (!$chk->fetchColumn()) {
            throw new RuntimeException(PhysicalDestructionCodes::CODE_DESTRUCTION_EXECUTION_INVALID);
        }

        self::close($pdo);

        $token = hash(
            'sha256',
            $executionId . '|' . $imhaTalepId . '|' . $category . '|' . bin2hex(random_bytes(16))
        );
        $ins = $pdo->prepare(
            'INSERT INTO retention_physical_destroy_gates
                (connection_id, execution_id, imha_talep_id, category, token_hash)
             VALUES
                (CONNECTION_ID(), :eid, :tid, :category, :token)'
        );
        $ins->execute([
            'eid' => $executionId,
            'tid' => $imhaTalepId,
            'category' => $category,
            'token' => $token,
        ]);
    }

    public static function close(PDO $pdo)
    {
        if (!self::tableExists($pdo, 'retention_physical_destroy_gates')) {
            return;
        }
        $pdo->exec(
            'DELETE FROM retention_physical_destroy_gates WHERE connection_id = CONNECTION_ID()'
        );
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
