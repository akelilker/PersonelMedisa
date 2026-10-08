<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Database\UsersSchema;
use PDO;

/**
 * Kalıcı Sil (hard delete) for Kullanıcı Yönetimi.
 *
 * Owner of the safe permanent-delete flow. It never reuses or alters the
 * Erişimi Kaldır (access-revoke) behaviour; this is a separate, stricter path:
 *
 *  - GENEL_YONETICI only (enforced by the controller via RolePermissions).
 *  - Fail-closed eligibility: SİLİNEBİLİR / ENGELLENDİ / DOĞRULANAMADI.
 *  - Self, protected admins, GENEL_YONETICI role, personel binding and any
 *    non-scope dependency (FK, CASCADE, SET NULL, or FK-less candidate column)
 *    all block deletion. Only the five technical org-scope link tables may be
 *    cleaned up.
 *  - Hard delete runs inside a transaction with `SELECT ... FOR UPDATE`, a
 *    re-check of eligibility, mandatory gerekçe + username re-type confirmation,
 *    and an audit row (099) that survives username reuse.
 */
final class KullaniciKaliciSilService
{
    public const VERDICT_SILINEBILIR = 'SİLİNEBİLİR';
    public const VERDICT_ENGELLENDI = 'ENGELLENDİ';
    public const VERDICT_DOGRULANAMADI = 'DOĞRULANAMADI';

    public const AUDIT_TABLE = 'user_kalici_silme_auditleri';

    public const CODE_SELF_DELETE = 'SELF_DELETE_FORBIDDEN';
    public const CODE_PROTECTED_ACCOUNT = 'PROTECTED_ACCOUNT';
    public const CODE_PROTECTED_ADMIN_ROLE = 'PROTECTED_ADMIN_ROLE';
    public const CODE_PERSONEL_BINDING = 'PERSONEL_BINDING';
    public const CODE_DEPENDENCY_EXISTS = 'DEPENDENCY_EXISTS';
    public const CODE_DEPENDENCY_UNVERIFIED = 'DEPENDENCY_UNVERIFIED';
    public const CODE_AUDIT_SCHEMA_NOT_READY = 'AUDIT_SCHEMA_NOT_READY';

    /**
     * Accounts that must never be treated as orphans merely because they lack a
     * personeller binding. Explicit canonical usernames, plus the role guard
     * below, keep live management identities safe.
     */
    public const PROTECTED_USERNAMES = ['ilkerA', 'serhan.kose'];

    /**
     * The only tables whose rows may be removed as part of a hard delete. Every
     * other reference (FK or FK-less) blocks deletion.
     */
    public const ALLOWED_SCOPE_TABLES = [
        'user_subeler',
        'user_bolumler',
        'user_birimler',
        'user_sirketler',
        'user_sgk_isverenler',
    ];

    /**
     * Columns that may reference users.id without a declared FK (actor/audit/
     * creator columns). Any row referencing the target in one of these is a
     * blocker unless the table is in ALLOWED_SCOPE_TABLES. Over-inclusion is
     * safe (fail-closed) by design.
     *
     * `target_user_id` is intentionally absent: every existing target_user_id is
     * FK-declared (RESTRICT), and the audit table's own FK-less target_user_id
     * references an already-deleted user, so it must never block.
     */
    private const CANDIDATE_COLUMNS = [
        'user_id',
        'actor_user_id',
        'actor_id',
        'changed_by',
        'created_by',
        'updated_by',
        'archived_by',
        'assigned_by',
        'classified_by',
        'executed_by',
        'locked_by',
        'reopened_by',
        'released_by',
        'corrected_by',
        'onaylayan_user_id',
        'onaylayan_id',
        'karar_veren_user_id',
        'savunma_isteyen_user_id',
        'nihai_karar_veren_user_id',
        'decided_by_user_id',
        'birim_amiri_user_id',
        'tamamlayan_user_id',
        'correction_requested_by',
        'olusturan_user_id',
        'sonlandiran_user_id',
        'iptal_edildi_by',
    ];

    private const MAX_GEREKCE_LENGTH = 500;

    /**
     * Read-only eligibility check. Never writes, never locks.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function checkEligibility(PDO $pdo, int $userId, array $actor): array
    {
        $target = self::loadTarget($pdo, $userId);
        if ($target === null) {
            throw KullaniciKaliciSilException::notFound('Kullanıcı bulunamadı.');
        }

        return self::evaluateEligibility($pdo, $target, $actor);
    }

    /**
     * Hard delete with locking, re-check, scope cleanup, user delete and audit
     * evidence, all inside a transaction.
     *
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function delete(
        PDO $pdo,
        int $userId,
        array $actor,
        string $confirmUsername,
        string $gerekce,
        string $requestHash
    ): array {
        $actorId = isset($actor['id']) ? (int) $actor['id'] : 0;
        if ($actorId <= 0) {
            throw KullaniciKaliciSilException::validation('Denetim kaydı için geçerli bir aktör gerekli.', 'actor');
        }

        $confirmUsername = trim($confirmUsername);
        $gerekce = trim($gerekce);
        if ($confirmUsername === '') {
            throw KullaniciKaliciSilException::validation('Onay için kullanıcı adı girilmelidir.', 'confirm_username');
        }
        if ($gerekce === '') {
            throw KullaniciKaliciSilException::validation('Gerekçe zorunludur.', 'gerekce');
        }
        if (mb_strlen($gerekce) > self::MAX_GEREKCE_LENGTH) {
            throw KullaniciKaliciSilException::validation('Gerekçe en fazla 500 karakter olabilir.', 'gerekce');
        }
        if (strlen($requestHash) !== 64 || !preg_match('/^[a-f0-9]{64}$/', $requestHash)) {
            throw KullaniciKaliciSilException::validation('Geçersiz işlem kanıtı.', 'request_hash');
        }

        self::assertAuditReady($pdo);

        // Serialize concurrent hard deletes of the same user with a namespaced
        // advisory lock. It is also the coordination point a FK-less reference
        // writer must respect; without the lock the final re-verification below
        // still refuses the delete when a late reference is observed.
        $lockName = 'medisa_kalici_sil_user_' . $userId;
        $locked = (int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 10)')->fetchColumn();
        if ($locked !== 1) {
            throw KullaniciKaliciSilException::conflict(
                'KALICI_SIL_KILIT_ALINAMADI',
                'Silme için eşzamanlılık kilidi alınamadı; daha sonra tekrar deneyin.'
            );
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            // READ COMMITTED lets the final re-verification observe FK-less
            // references committed by other connections after the first check.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
        }

        try {
            $target = self::lockTarget($pdo, $userId);
            if ($target === null) {
                throw KullaniciKaliciSilException::notFound('Kullanıcı bulunamadı.');
            }

            $eligibility = self::evaluateEligibility($pdo, $target, $actor);
            if ($eligibility['verdict'] !== self::VERDICT_SILINEBILIR) {
                throw KullaniciKaliciSilException::conflict(
                    'KALICI_SIL_ENGELLENDI',
                    'Hesap silinebilir durumda değil; silme reddedildi.'
                );
            }

            if ((string) $target['username'] !== $confirmUsername) {
                throw KullaniciKaliciSilException::validation('Kullanıcı adı onayı uyuşmuyor.', 'confirm_username');
            }

            $cleanedScope = self::deleteScopeRows($pdo, $userId);

            // Final fail-closed re-verification immediately before the row is
            // deleted: a FK-less reference committed between the initial check
            // and now refuses the delete rather than leaving an orphan.
            self::reverifyDependencies($pdo, $userId);

            $deleted = self::deleteUserRow($pdo, $userId);
            if ($deleted !== 1) {
                throw KullaniciKaliciSilException::conflict(
                    'KALICI_SIL_SATIR_YOK',
                    'Silinecek kullanıcı satırı bulunamadı; silme iptal edildi.'
                );
            }

            $auditId = self::persistAudit($pdo, $target, $cleanedScope, $actorId, $gerekce, $requestHash);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'deleted' => true,
                'target_user_id' => (int) $target['id'],
                'target_username' => (string) $target['username'],
                'cleaned_scope_rows' => $cleanedScope,
                'audit_id' => $auditId,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
        }
    }

    /**
     * Final fail-closed re-verification run immediately before the user row is
     * deleted. Under READ COMMITTED this observes FK-less references committed
     * by other connections after the initial eligibility check.
     */
    private static function reverifyDependencies(PDO $pdo, int $userId): void
    {
        foreach (self::inventoryDependencies($pdo, $userId) as $dep) {
            $unverified = $dep['row_count'] === null;
            $blocker = !$unverified
                && (int) $dep['row_count'] > 0
                && !in_array((string) $dep['table'], self::ALLOWED_SCOPE_TABLES, true);
            if ($unverified || $blocker) {
                throw KullaniciKaliciSilException::conflict(
                    'KALICI_SIL_YARIS_KORUNDU',
                    'Silme sırasında yeni veya doğrulanamayan bir bağımlılık oluştu; işlem reddedildi.'
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    private static function evaluateEligibility(PDO $pdo, array $target, array $actor): array
    {
        $userId = (int) $target['id'];
        $targetInfo = [
            'id' => (int) $target['id'],
            'username' => (string) $target['username'],
            'ad_soyad' => (string) $target['ad_soyad'],
            'rol' => (string) $target['rol'],
            'durum' => (string) $target['durum'],
        ];

        // Fail-closed: without the audit evidence table and its append-only
        // triggers, eligibility must never report SİLİNEBİLİR.
        if (!self::hasAuditReady($pdo)) {
            return [
                'verdict' => self::VERDICT_DOGRULANAMADI,
                'target' => $targetInfo,
                'blockers' => [],
                'unverified' => [[
                    'code' => self::CODE_AUDIT_SCHEMA_NOT_READY,
                    'table' => self::AUDIT_TABLE,
                    'column' => null,
                    'reason' => 'audit_schema_not_ready',
                ]],
                'cleanable_scope' => [],
                'dependencies' => [],
            ];
        }

        $blockers = [];

        $protected = self::protectedBlocker($target, $actor);
        if ($protected !== null) {
            $blockers[] = $protected;
        }

        $dependencies = self::inventoryDependencies($pdo, $userId);

        $unverified = [];
        $nonScope = [];
        $cleanable = [];
        foreach ($dependencies as $dep) {
            if ($dep['row_count'] === null) {
                $unverified[] = $dep;
                continue;
            }
            if ((int) $dep['row_count'] === 0) {
                continue;
            }
            if (in_array((string) $dep['table'], self::ALLOWED_SCOPE_TABLES, true)) {
                $cleanable[] = $dep;
            } else {
                $nonScope[] = $dep;
            }
        }

        foreach ($nonScope as $dep) {
            $blockers[] = [
                'code' => self::CODE_DEPENDENCY_EXISTS,
                'table' => (string) $dep['table'],
                'column' => $dep['column'] !== null ? (string) $dep['column'] : null,
                'row_count' => (int) $dep['row_count'],
                'delete_rule' => (string) $dep['delete_rule'],
                'reason' => 'rows_present',
            ];
        }

        if (count($blockers) > 0) {
            $verdict = self::VERDICT_ENGELLENDI;
        } elseif (count($unverified) > 0) {
            $verdict = self::VERDICT_DOGRULANAMADI;
        } else {
            $verdict = self::VERDICT_SILINEBILIR;
        }

        $unverifiedBlockers = [];
        foreach ($unverified as $dep) {
            $unverifiedBlockers[] = [
                'code' => self::CODE_DEPENDENCY_UNVERIFIED,
                'table' => (string) $dep['table'],
                'column' => $dep['column'] !== null ? (string) $dep['column'] : null,
                'reason' => (string) $dep['reason'],
            ];
        }

        return [
            'verdict' => $verdict,
            'target' => $targetInfo,
            'blockers' => $blockers,
            'unverified' => $unverifiedBlockers,
            'cleanable_scope' => $cleanable,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $actor
     * @return array<string, mixed>|null
     */
    private static function protectedBlocker(array $target, array $actor): ?array
    {
        $targetId = (int) $target['id'];
        $actorId = isset($actor['id']) ? (int) $actor['id'] : 0;

        if ($actorId > 0 && $actorId === $targetId) {
            return ['code' => self::CODE_SELF_DELETE, 'reason' => 'Kendi hesabınız silinemez.'];
        }

        // Stable DB flag is the authoritative protection anchor: it survives
        // later username edits (099 backfill converts the canonical usernames).
        if (isset($target['silinmesi_korunur']) && (int) $target['silinmesi_korunur'] === 1) {
            return ['code' => self::CODE_PROTECTED_ACCOUNT, 'reason' => 'Korunan yönetici hesabı.'];
        }

        // Canonical username is a secondary fail-closed hint, never the sole
        // basis: it catches accounts not yet flagged by the 099 backfill.
        if (in_array((string) $target['username'], self::PROTECTED_USERNAMES, true)) {
            return ['code' => self::CODE_PROTECTED_ACCOUNT, 'reason' => 'Korunan yönetici hesabı.'];
        }

        if (strtoupper(trim((string) $target['rol'])) === 'GENEL_YONETICI') {
            return ['code' => self::CODE_PROTECTED_ADMIN_ROLE, 'reason' => 'Genel yönetici hesabı korunur.'];
        }

        if (isset($target['personel_id']) && (int) $target['personel_id'] > 0) {
            return ['code' => self::CODE_PERSONEL_BINDING, 'reason' => 'Hesabın personel bağlantısı var.'];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function inventoryDependencies(PDO $pdo, int $userId): array
    {
        $refs = self::discoverUserReferences($pdo);
        if ($refs === null) {
            // Fail-closed: the schema reference inventory could not be read, so
            // eligibility must resolve to DOĞRULANAMADI rather than guess.
            return [
                [
                    'table' => '*',
                    'column' => null,
                    'columns' => [],
                    'referenced_columns' => [],
                    'constraint' => null,
                    'delete_rule' => 'NONE',
                    'row_count' => null,
                    'reason' => 'inventory_unavailable',
                ],
            ];
        }
        $out = [];
        foreach ($refs as $ref) {
            $table = (string) $ref['table'];
            $columns = array_map('strval', $ref['columns']);
            $referencedColumns = array_map('strval', $ref['referenced_columns']);
            $column = $ref['column'] !== null ? (string) $ref['column'] : null;

            $base = [
                'table' => $table,
                'column' => $column,
                'columns' => $columns,
                'constraint' => $ref['constraint'],
                'delete_rule' => (string) $ref['delete_rule'],
            ];

            // A column whose name signals a user reference but that is neither
            // FK-covered nor in CANDIDATE_COLUMNS is unclassified: its row count
            // is irrelevant, it must fail closed.
            if (($ref['classified'] ?? true) === false) {
                $out[] = $base + ['row_count' => null, 'reason' => 'unclassified_reference'];
                continue;
            }

            $identifiersSafe = count($columns) > 0;
            foreach (array_merge([$table], $columns, $referencedColumns) as $identifier) {
                if (!preg_match('/^[a-z0-9_]+$/', $identifier)) {
                    $identifiersSafe = false;
                }
            }
            if (!$identifiersSafe) {
                $out[] = $base + ['row_count' => null, 'reason' => 'unsafe_identifier'];
                continue;
            }

            $count = null;
            try {
                if (count($columns) === 1 && $column !== null) {
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :uid");
                    $stmt->execute(['uid' => $userId]);
                    $count = (int) $stmt->fetchColumn();
                } else {
                    $joins = [];
                    foreach ($columns as $index => $col) {
                        $joins[] = "c.`{$col}` = u.`{$referencedColumns[$index]}`";
                    }
                    $stmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM `{$table}` c INNER JOIN `users` u ON " . implode(' AND ', $joins) . ' WHERE u.`id` = :uid'
                    );
                    $stmt->execute(['uid' => $userId]);
                    $count = (int) $stmt->fetchColumn();
                }
            } catch (\Throwable $e) {
                $count = null;
            }

            $out[] = $base + [
                'row_count' => $count,
                'reason' => $count === null ? 'count_failed' : ($count > 0 ? 'rows_present' : 'empty'),
            ];
        }

        return $out;
    }

    /**
     * Discover every reference to users.id: declared FKs (with their delete
     * rule) plus FK-less candidate columns that were not already covered by an FK.
     *
     * @return array<int, array<string, mixed>>|null null when the inventory cannot be read (fail-closed)
     */
    private static function discoverUserReferences(PDO $pdo): ?array
    {
        try {
            $fk = $pdo->query(
                "SELECT kcu.TABLE_NAME AS tbl,
                        kcu.CONSTRAINT_NAME AS constraint_name,
                        kcu.COLUMN_NAME AS col,
                        kcu.REFERENCED_COLUMN_NAME AS ref_col,
                        rc.DELETE_RULE AS delete_rule
                 FROM information_schema.KEY_COLUMN_USAGE kcu
                 INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                   ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                  AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                  AND rc.TABLE_NAME = kcu.TABLE_NAME
                 WHERE kcu.TABLE_SCHEMA = DATABASE()
                   AND kcu.REFERENCED_TABLE_NAME = 'users'
                   AND kcu.REFERENCED_COLUMN_NAME = 'id'
                 ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION"
            );
        } catch (\Throwable $e) {
            return null;
        }
        if ($fk === false) {
            return null;
        }

        $byConstraint = [];
        $covered = [];
        foreach ($fk->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['tbl'];
            $constraint = (string) $row['constraint_name'];
            $col = (string) $row['col'];
            $refCol = (string) $row['ref_col'];
            $key = $table . '|' . $constraint;
            if (!isset($byConstraint[$key])) {
                $byConstraint[$key] = [
                    'table' => $table,
                    'column' => null,
                    'columns' => [],
                    'referenced_columns' => [],
                    'constraint' => $constraint,
                    'delete_rule' => strtoupper((string) $row['delete_rule']),
                ];
            }
            $byConstraint[$key]['columns'][] = $col;
            $byConstraint[$key]['referenced_columns'][] = $refCol;
            if ($refCol === 'id') {
                $byConstraint[$key]['column'] = $col;
            }
            $covered[$table . '|' . $col] = true;
        }

        $refs = array_values($byConstraint);

        $placeholders = implode(',', array_fill(0, count(self::CANDIDATE_COLUMNS), '?'));
        try {
            $colQuery = $pdo->prepare(
                "SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND COLUMN_NAME IN ($placeholders)
                   AND TABLE_NAME <> 'users'"
            );
            $colQuery->execute(self::CANDIDATE_COLUMNS);
        } catch (\Throwable $e) {
            // A reference column we cannot enumerate is exactly the case where
            // the whole deletion must fail closed.
            return null;
        }
        foreach ($colQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['tbl'];
            $col = (string) $row['col'];
            if (isset($covered[$table . '|' . $col])) {
                continue;
            }
            $covered[$table . '|' . $col] = true;
            $refs[] = [
                'table' => $table,
                'column' => $col,
                'columns' => [$col],
                'referenced_columns' => ['id'],
                'constraint' => null,
                'delete_rule' => 'NONE',
            ];
        }

        // Fail-closed enumeration of UNCLASSIFIED references. Any integer or
        // JSON/TEXT column whose name signals a user reference (user / kullanici
        // / actor / aktor / _by), that is covered by no FK at all and is not in
        // CANDIDATE_COLUMNS, is treated as an unverifiable dependency. JSON/TEXT
        // columns are included because their content can embed user ids that a
        // column scan cannot see. The audit table is excluded: its target_user_id
        // records an already-deleted user by design and must never block.
        $signal = "(
            LOCATE('user', c.COLUMN_NAME) > 0
            OR LOCATE('kullanici', c.COLUMN_NAME) > 0
            OR LOCATE('actor', c.COLUMN_NAME) > 0
            OR LOCATE('aktor', c.COLUMN_NAME) > 0
            OR RIGHT(c.COLUMN_NAME, 3) = '_by'
        )";
        try {
            $unclassified = $pdo->prepare(
                "SELECT c.TABLE_NAME AS tbl, c.COLUMN_NAME AS col
                 FROM information_schema.COLUMNS c
                 WHERE c.TABLE_SCHEMA = DATABASE()
                   AND c.TABLE_NAME <> 'users'
                   AND c.TABLE_NAME <> :audit_table
                   AND c.DATA_TYPE IN ('int','tinyint','smallint','mediumint','bigint','json','longtext','text','mediumtext')
                   AND {$signal}
                   AND NOT EXISTS (
                       SELECT 1 FROM information_schema.KEY_COLUMN_USAGE kcu
                       WHERE kcu.TABLE_SCHEMA = c.TABLE_SCHEMA
                         AND kcu.TABLE_NAME = c.TABLE_NAME
                         AND kcu.COLUMN_NAME = c.COLUMN_NAME
                         AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                   )"
            );
            $unclassified->execute(['audit_table' => self::AUDIT_TABLE]);
        } catch (\Throwable $e) {
            // Unable to enumerate unclassified references → fail closed.
            return null;
        }
        foreach ($unclassified->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['tbl'];
            $col = (string) $row['col'];
            if (isset($covered[$table . '|' . $col]) || in_array($col, self::CANDIDATE_COLUMNS, true)) {
                continue;
            }
            $covered[$table . '|' . $col] = true;
            $refs[] = [
                'table' => $table,
                'column' => $col,
                'columns' => [$col],
                'referenced_columns' => ['id'],
                'constraint' => null,
                'delete_rule' => 'NONE',
                'classified' => false,
            ];
        }

        usort($refs, static function (array $a, array $b) {
            return ($a['table'] <=> $b['table'])
                ?: strcmp((string) ($a['column'] ?? ''), (string) ($b['column'] ?? ''));
        });

        return $refs;
    }

    /** @return array<string, mixed>|null */
    private static function loadTarget(PDO $pdo, int $userId): ?array
    {
        return self::readTarget($pdo, $userId, false);
    }

    /** @return array<string, mixed>|null */
    private static function lockTarget(PDO $pdo, int $userId): ?array
    {
        return self::readTarget($pdo, $userId, true);
    }

    /** @return array<string, mixed>|null */
    private static function readTarget(PDO $pdo, int $userId, bool $forUpdate): ?array
    {
        $cols = ['id', 'username', 'ad_soyad', 'rol', 'durum'];
        if (UsersSchema::hasPersonelId($pdo)) {
            $cols[] = 'personel_id';
        }
        if (UsersSchema::hasSilinmesiKorunur($pdo)) {
            $cols[] = 'silinmesi_korunur';
        }
        $sql = 'SELECT ' . implode(', ', $cols) . ' FROM users WHERE id = :id LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if (!array_key_exists('personel_id', $row)) {
            $row['personel_id'] = null;
        }
        $row['personel_id'] = isset($row['personel_id']) && $row['personel_id'] !== null ? (int) $row['personel_id'] : null;

        if (!array_key_exists('silinmesi_korunur', $row)) {
            $row['silinmesi_korunur'] = 0;
        }
        $row['silinmesi_korunur'] = (int) $row['silinmesi_korunur'];

        return $row;
    }

    /** @return array<int, array<string, mixed>> */
    private static function deleteScopeRows(PDO $pdo, int $userId): array
    {
        $cleaned = [];
        foreach (self::ALLOWED_SCOPE_TABLES as $table) {
            if (!self::tableExists($pdo, $table)) {
                continue;
            }
            $count = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE user_id = :uid");
            $count->execute(['uid' => $userId]);
            $existing = (int) $count->fetchColumn();

            $delete = $pdo->prepare("DELETE FROM `{$table}` WHERE user_id = :uid");
            $delete->execute(['uid' => $userId]);

            if ($existing > 0) {
                $cleaned[] = ['table' => $table, 'removed' => $existing];
            }
        }

        return $cleaned;
    }

    private static function deleteUserRow(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);

        return $stmt->rowCount();
    }

    /** @param array<string, mixed> $target @return int */
    private static function persistAudit(
        PDO $pdo,
        array $target,
        array $cleanedScope,
        int $actorId,
        string $gerekce,
        string $requestHash
    ): int {
        $totalCleaned = 0;
        foreach ($cleanedScope as $scope) {
            $totalCleaned += (int) ($scope['removed'] ?? 0);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::AUDIT_TABLE . '
                (target_user_id, target_username, target_ad_soyad, target_rol, onceki_durum,
                 korunan_personel_id, temizlenen_scope_satiri, gerekce, actor_user_id, request_hash)
             VALUES
                (:target_user_id, :target_username, :target_ad_soyad, :target_rol, :onceki_durum,
                 :korunan_personel_id, :temizlenen_scope_satiri, :gerekce, :actor_user_id, :request_hash)'
        );
        $stmt->execute([
            'target_user_id' => (int) $target['id'],
            'target_username' => (string) $target['username'],
            'target_ad_soyad' => (string) $target['ad_soyad'],
            'target_rol' => (string) $target['rol'],
            'onceki_durum' => strtoupper(trim((string) $target['durum'])),
            'korunan_personel_id' => isset($target['personel_id']) && (int) $target['personel_id'] > 0 ? (int) $target['personel_id'] : null,
            'temizlenen_scope_satiri' => $totalCleaned,
            'gerekce' => $gerekce,
            'actor_user_id' => $actorId,
            'request_hash' => $requestHash,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private static function assertAuditReady(PDO $pdo): void
    {
        if (!self::hasAuditReady($pdo)) {
            throw KullaniciKaliciSilException::auditSchemaNotReady();
        }
    }

    /**
     * The audit evidence table (099) and its append-only triggers must all be
     * present before any deletion decision; otherwise the delete is not
     * auditable and must be refused / reported DOĞRULANAMADI.
     */
    private static function hasAuditReady(PDO $pdo): bool
    {
        if (!self::tableExists($pdo, self::AUDIT_TABLE)) {
            return false;
        }
        foreach (['trg_ksa_no_update', 'trg_ksa_no_delete'] as $trigger) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :name"
            );
            $stmt->execute(['name' => $trigger]);
            if ((int) $stmt->fetchColumn() === 0) {
                return false;
            }
        }

        return true;
    }
}
