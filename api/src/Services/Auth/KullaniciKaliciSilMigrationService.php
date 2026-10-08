<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Database\MigrationExecutionService;
use Medisa\Api\Database\MigrationSourceProvider;
use PDO;

/**
 * Operations-only owner of the ONE-TIME 099 protected-account migration.
 *
 * Migration 099 needs two operator-verified users.id values (the protected
 * accounts) supplied on the same DB connection before its SQL runs. This is not
 * a feature and must never become one: it has no controller, no route and no
 * UI, and it is reachable only from the canonical control-plane worker.
 *
 * The safety model is preimage + fail-closed, not trust:
 *   - the ledger must be at tip 098 with exactly 099 pending;
 *   - the two ids must be positive, distinct and both present in users;
 *   - the 15 historically FK-less columns must contain no orphaned reference;
 *   - a previous interrupted round must be reconciled manually, never absorbed
 *     by a blind retry.
 *
 * Every statement in the read path is a SELECT; the only mutation is the
 * delegation to MigrationExecutionService::apply, which runs the migration's
 * own guards (ids + orphans) before its first DDL on the same connection.
 */
final class KullaniciKaliciSilMigrationService
{
    public const SCHEMA_VERSION = '1';
    public const MIGRATION_VERSION = '099';
    public const MIGRATION_NAME = '099_user_kalici_silme_auditleri.sql';

    /**
     * The 15 classified FK columns migration 099 adds, keyed by table. These are
     * exactly the columns that were FK-less before this migration and are the
     * only ones where an orphaned reference can exist.
     *
     * @var array<string, list<string>>
     */
    private const FK_REFERENCES = [
        'ek_odeme_kesinti' => ['created_by', 'updated_by'],
        'gunluk_bildirimler' => ['created_by', 'updated_by', 'correction_requested_by'],
        'legal_holdlar' => ['released_by'],
        'legal_hold_auditleri' => ['actor_user_id'],
        'offline_mutation_idempotency' => ['actor_user_id'],
        'personel_gecici_gorevlendirmeler' => ['olusturan_user_id', 'sonlandiran_user_id'],
        'personel_import_runs' => ['actor_id'],
        'personel_test_fixture_archive_kayitlari' => ['archived_by'],
        'personel_test_fixture_siniflandirmalari' => ['classified_by', 'iptal_edildi_by'],
        'retention_imha_auditleri' => ['actor_user_id'],
    ];

    /**
     * Read-only gate. Returns a publishable report; never touches a row.
     *
     * @return array<string, mixed>
     */
    public static function preflight(
        PDO $pdo,
        MigrationSourceProvider $source,
        string $deployedSha,
        int $ilkerId,
        int $serhanId
    ): array {
        $blockers = self::gate($pdo, $source, $deployedSha, $ilkerId, $serhanId);
        $ledger = MigrationExecutionService::ledgerFacts($pdo, $source);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => 'READ_ONLY_KALICI_SIL_MIGRATION_PREFLIGHT',
            'deployed_sha' => strtolower($deployedSha),
            'migration_version' => self::MIGRATION_VERSION,
            'ledger' => [
                'applied_tip' => $ledger['tip'],
                'pending_versions' => $ledger['pending_versions'],
            ],
            'protected_ids' => [
                'ilker_id' => $ilkerId,
                'serhan_id' => $serhanId,
                'distinct' => $ilkerId !== $serhanId,
            ],
            'orphans' => self::orphanCounts($pdo),
            'partial_state' => self::partialState($pdo),
            'blockers' => $blockers,
            'result' => $blockers === [] ? 'PASS' : 'BLOCKED',
        ];
    }

    /**
     * Apply 099 on the same connection the ids are supplied on.
     *
     * The caller must have completed a verified backup first; this owner
     * refuses to start without the evidence reference so "backup was skipped"
     * cannot be a silent path. The migration's own guards run inside
     * MigrationExecutionService::apply and halt before the first DDL.
     *
     * @param array<string, mixed> $backupEvidence
     * @return array<string, mixed>
     */
    public static function apply(
        PDO $pdo,
        MigrationSourceProvider $source,
        string $deployedSha,
        int $ilkerId,
        int $serhanId,
        array $backupEvidence
    ): array {
        self::assertBackupEvidence($backupEvidence);

        // Re-gate before mutating: between the published preflight and this
        // point another writer could have introduced an orphan or the ledger
        // could have moved, and neither may be absorbed silently.
        $blockers = self::gate($pdo, $source, $deployedSha, $ilkerId, $serhanId);
        if ($blockers !== []) {
            throw KullaniciKaliciSilMigrationFailure::of(
                'KALICI_SIL_PREFLIGHT_BLOCKED',
                implode(',', $blockers)
            );
        }

        // Same connection, same session: the migration's own guard reads these
        // user variables. Values are validated positive ints, never interpolated
        // from request text.
        $pdo->exec('SET @p099_protected_ilker_user_id = ' . (int) $ilkerId);
        $pdo->exec('SET @p099_protected_serhan_user_id = ' . (int) $serhanId);

        $applied = MigrationExecutionService::apply($pdo, $source, null, self::MIGRATION_VERSION);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'migration_version' => self::MIGRATION_VERSION,
            'deployed_sha' => strtolower($deployedSha),
            'backup_file' => (string) $backupEvidence['file'],
            'backup_sha256' => (string) $backupEvidence['sha256'],
            'applied_versions' => $applied['pending'],
        ];
    }

    /**
     * Read-only proof of what the database now holds after the apply.
     *
     * @param array<string, mixed> $backupEvidence
     * @return array<string, mixed>
     */
    public static function postcheck(
        PDO $pdo,
        MigrationSourceProvider $source,
        array $backupEvidence
    ): array {
        $unexpected = [];
        $missingFks = [];

        $ledger = MigrationExecutionService::ledgerFacts($pdo, $source);
        if ($ledger['tip'] !== self::MIGRATION_VERSION) {
            $unexpected[] = 'KALICI_SIL_LEDGER_NOT_APPLIED';
        }

        foreach (self::FK_REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                if (self::fkDeleteRule($pdo, $table, $column) !== 'RESTRICT') {
                    $missingFks[] = $table . '.' . $column;
                }
            }
        }
        if ($missingFks !== []) {
            $unexpected[] = 'KALICI_SIL_FK_MISSING';
        }

        $registryCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM user_kalici_silme_korunan_hesaplar
             WHERE protection_key IN ('ILKER_A', 'SERHAN_KOSE')"
        )->fetchColumn();
        if ($registryCount !== 2) {
            $unexpected[] = 'KALICI_SIL_REGISTRY_INCOMPLETE';
        }

        $flagCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM users u
             JOIN user_kalici_silme_korunan_hesaplar p ON p.user_id = u.id
             WHERE u.silinmesi_korunur = 1'
        )->fetchColumn();
        if ($flagCount !== 2) {
            $unexpected[] = 'KALICI_SIL_FLAG_INCOMPLETE';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'migration_version' => self::MIGRATION_VERSION,
            'fk_missing' => $missingFks,
            'backup_reference' => [
                'file' => (string) ($backupEvidence['file'] ?? ''),
                'sha256' => (string) ($backupEvidence['sha256'] ?? ''),
                'readback' => (string) ($backupEvidence['readback'] ?? ''),
            ],
            'unexpected_deltas' => array_values(array_unique($unexpected)),
            'result' => $unexpected === [] ? 'PASS' : 'BLOCKED',
        ];
    }

    /**
     * The single gate both the preflight and the apply run. Returns reason
     * codes; an empty list is the only thing that authorises a write.
     *
     * @return list<string>
     */
    private static function gate(
        PDO $pdo,
        MigrationSourceProvider $source,
        string $deployedSha,
        int $ilkerId,
        int $serhanId
    ): array {
        $blockers = [];

        $ledger = MigrationExecutionService::ledgerFacts($pdo, $source);
        if ($ledger['tip'] !== '098') {
            $blockers[] = 'KALICI_SIL_LEDGER_TIP_UNEXPECTED';
        }
        if ($ledger['pending_versions'] !== [self::MIGRATION_VERSION]) {
            $blockers[] = 'KALICI_SIL_PENDING_UNEXPECTED';
        }

        if ($ilkerId <= 0 || $serhanId <= 0 || $ilkerId === $serhanId) {
            $blockers[] = 'KALICI_SIL_IDS_INVALID';
        } else {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id IN (:a, :b)');
            $statement->execute([':a' => $ilkerId, ':b' => $serhanId]);
            if ((int) $statement->fetchColumn() !== 2) {
                $blockers[] = 'KALICI_SIL_IDS_NOT_FOUND';
            }
        }

        foreach (self::orphanCounts($pdo) as $reference => $count) {
            if ($count === null) {
                $blockers[] = 'KALICI_SIL_SCHEMA_PREIMAGE_MISSING';
            } elseif ($count > 0) {
                $blockers[] = 'KALICI_SIL_ORPHAN_PRESENT';
            }
        }

        if (self::partialState($pdo)['present'] === true) {
            $blockers[] = 'KALICI_SIL_PARTIAL_STATE';
        }

        return array_values(array_unique($blockers));
    }

    /**
     * Orphan count per FK-less reference. null marks a missing table/column so
     * the gate can name a schema-preimage problem instead of crashing on it.
     *
     * @return array<string, int|null>
     */
    private static function orphanCounts(PDO $pdo): array
    {
        $counts = [];
        foreach (self::FK_REFERENCES as $table => $columns) {
            if (!self::tableExists($pdo, $table)) {
                foreach ($columns as $column) {
                    $counts[$table . '.' . $column] = null;
                }
                continue;
            }
            foreach ($columns as $column) {
                if (!self::columnExists($pdo, $table, $column)) {
                    $counts[$table . '.' . $column] = null;
                    continue;
                }
                $counts[$table . '.' . $column] = (int) $pdo->query(
                    'SELECT COUNT(*) FROM `' . $table . '` t'
                    . ' WHERE t.`' . $column . '` IS NOT NULL'
                    . ' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id = t.`' . $column . '`)'
                )->fetchColumn();
            }
        }

        return $counts;
    }

    /**
     * Which 099 objects already exist: the preimage for a manual recovery after
     * an interrupted round. Never absorbed by a blind retry.
     *
     * @return array<string, mixed>
     */
    private static function partialState(PDO $pdo): array
    {
        $fkConstraints = self::fk099Present($pdo);
        $facts = [
            'audit_table' => self::tableExists($pdo, 'user_kalici_silme_auditleri'),
            'koruma_column' => self::columnExists($pdo, 'users', 'silinmesi_korunur'),
            'registry_table' => self::tableExists($pdo, 'user_kalici_silme_korunan_hesaplar'),
            'fk_constraints' => $fkConstraints,
        ];
        $facts['present'] = $facts['audit_table']
            || $facts['koruma_column']
            || $facts['registry_table']
            || $fkConstraints !== [];

        return $facts;
    }

    /**
     * @return list<string>
     */
    private static function fk099Present(PDO $pdo): array
    {
        $rows = $pdo->query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        )->fetchAll(PDO::FETCH_COLUMN);

        $names = [];
        foreach ($rows as $name) {
            $name = (string) $name;
            if (strpos($name, 'fk_p099_') === 0) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function fkDeleteRule(PDO $pdo, string $table, string $column): ?string
    {
        $statement = $pdo->prepare(
            "SELECT rc.DELETE_RULE
             FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              AND kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
              AND kcu.TABLE_NAME = rc.TABLE_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE()
               AND rc.TABLE_NAME = :table
               AND kcu.COLUMN_NAME = :column
               AND kcu.REFERENCED_TABLE_NAME = 'users'"
        );
        $statement->execute([':table' => $table, ':column' => $column]);
        $rule = $statement->fetchColumn();

        return is_string($rule) ? $rule : null;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
        );
        $statement->execute([':table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $statement->execute([':table' => $table, ':column' => $column]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private static function assertBackupEvidence(array $evidence): void
    {
        $file = (string) ($evidence['file'] ?? '');
        $sha256 = (string) ($evidence['sha256'] ?? '');
        if ($file === '' || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            throw KullaniciKaliciSilMigrationFailure::of('KALICI_SIL_BACKUP_EVIDENCE_MISSING');
        }
        if (($evidence['readback'] ?? '') !== 'VERIFIED') {
            throw KullaniciKaliciSilMigrationFailure::of('KALICI_SIL_BACKUP_NOT_VERIFIED');
        }
    }
}
