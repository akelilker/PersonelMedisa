<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

use Medisa\Api\Services\Retention\RetentionCategories;
use PDO;

/**
 * Canonical owner: fail-closed hard purge of confirmed TEST_FIXTURE personel.
 * Not a generic personel delete. Requires persisted classification evidence.
 * Shared/unknown/historical-real dependencies DENY. Linked business users are preserved.
 *
 * Relation classification is owner-attributed: sealed ledger/snapshot rows are owned by the
 * retention destruction owner, closed period artifacts by the aylik kapanis owner, and
 * append-only access audit rows by the archive access audit owner. None of those are
 * deletable by this owner (fail-closed + explicit handoff); audit integrity is never broken.
 */
class TestFixturePersonelPurgeService
{
    public const CONFIRM_TOKEN = 'PURGE_TEST_FIXTURE';
    public const CODE_REAL_EMPLOYEE = 'REAL_EMPLOYEE_OR_UNCLASSIFIED';
    public const CODE_SHARED_OR_UNKNOWN = 'SHARED_OR_UNKNOWN_DEPENDENCY';
    public const CODE_HISTORICAL = 'HISTORICAL_OR_SHARED_DEPENDENCY';
    public const CODE_AUDIT_IMMUTABLE = 'AUDIT_APPEND_ONLY_RETENTION_REQUIRED';
    public const CODE_USER_NOT_FIXTURE = 'USER_NOT_FIXTURE';
    public const CODE_CONFIRM_REQUIRED = 'PURGE_CONFIRM_REQUIRED';

    public const CLASS_CONFIRMED_DEMO = 'CONFIRMED_DEMO_DEPENDENCY';
    public const CLASS_GENERATED_DEMO = 'GENERATED_FROM_DEMO';
    public const CLASS_SHARED_OR_REAL = 'SHARED_OR_REAL_REFERENCE';
    public const CLASS_UNKNOWN = 'UNKNOWN';
    /** Append-only audit relation: its owner never deletes, so fixture purge must not either. */
    public const CLASS_AUDIT_APPEND_ONLY = 'AUDIT_APPEND_ONLY';
    /** Sealed/immutable ledger or snapshot evidence; destruction belongs to retention imha. */
    public const CLASS_SEALED_HISTORICAL = 'SEALED_HISTORICAL_EVIDENCE';
    /** Approved/closed period artifact; the period owner forbids post-close rewrite. */
    public const CLASS_CLOSED_PERIOD_ARTIFACT = 'CLOSED_PERIOD_ARTIFACT';

    public const HANDOFF_RETENTION_IMHA = 'RETENTION_IMHA_OWNER';
    public const HANDOFF_ARCHIVE_ACCESS_AUDIT = 'ARCHIVE_ACCESS_AUDIT_OWNER';
    public const HANDOFF_AYLIK_KAPANIS = 'AYLIK_KAPANIS_OWNER';

    /** Owner labels (service/controller that owns the row lifecycle). */
    public const OWNER_ARCHIVE_ACCESS_SERVICE = 'Medisa\\Api\\Services\\Retention\\ArchiveAccessService';
    public const OWNER_PUANTAJ_DESTRUCTION = 'Medisa\\Api\\Services\\Retention\\PhysicalDestruction\\Handlers\\PuantajDestructionHandler';
    public const OWNER_MAAS_SNAPSHOT_SERVICE = 'Medisa\\Api\\Services\\MaasHesaplamaSnapshotService';
    public const OWNER_AYLIK_KAPANIS = 'Medisa\\Api\\Controllers\\YonetimController';

    /**
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public static function purge(PDO $pdo, $personelId, array $actor, $dryRun = true, $confirm = null)
    {
        if (!TestFixturePersonelClassificationService::schemaReady($pdo)) {
            throw new TestFixturePersonelArchiveException(
                'SCHEMA_NOT_READY',
                'Test fixture classification semasi hazir degil.',
                503
            );
        }

        $personelId = (int) $personelId;
        if ($personelId <= 0) {
            throw new TestFixturePersonelArchiveException('PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 404, 'personel_id');
        }

        $dryRun = self::isTruthy($dryRun, true);
        if (!$dryRun) {
            if (trim((string) $confirm) !== self::CONFIRM_TOKEN) {
                throw new TestFixturePersonelArchiveException(
                    self::CODE_CONFIRM_REQUIRED,
                    'Hard purge icin confirm=PURGE_TEST_FIXTURE zorunlu.',
                    422,
                    'confirm'
                );
            }
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $personel = self::lockPersonel($pdo, $personelId);
            if ($personel === null) {
                throw new TestFixturePersonelArchiveException('PERSONEL_NOT_FOUND', 'Personel bulunamadi.', 404, 'personel_id');
            }

            $classification = TestFixturePersonelClassificationService::findActive($pdo, $personelId);
            if ($classification === null) {
                throw new TestFixturePersonelArchiveException(
                    self::CODE_REAL_EMPLOYEE,
                    'TEST_FIXTURE siniflandirmasi yok; gercek/unknown personel purge edilemez.',
                    409,
                    'classification'
                );
            }

            $plan = self::buildPlan($pdo, $personel, $classification);
            if ($plan['purge_safe'] !== true) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }

                return $plan;
            }

            if ($dryRun) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                $plan['status'] = 'DRY_RUN';
                $plan['executed'] = false;

                return $plan;
            }

            foreach ($plan['delete_order'] as $step) {
                self::executeDeleteStep($pdo, $step);
            }

            $plan['status'] = 'PURGED';
            $plan['executed'] = true;
            $plan['rollback_recovery'] = 'IRREVERSIBLE';
            $plan['audit'] = self::persistAudit($pdo, $plan, $actor, false);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return $plan;
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof TestFixturePersonelArchiveException) {
                throw $e;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $personel
     * @param array<string, mixed> $classification
     * @return array<string, mixed>
     */
    public static function buildPlan(PDO $pdo, array $personel, array $classification)
    {
        $personelId = (int) $personel['id'];
        $dependencies = self::inventoryDependencies($pdo, $personelId);
        $users = self::inventoryUsers($pdo, $personelId);

        $blockers = [];
        foreach ($users as $user) {
            if (($user['fixture_eligible'] ?? false) !== true) {
                $blockers[] = [
                    'code' => self::CODE_USER_NOT_FIXTURE,
                    'table' => 'users',
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'rol' => $user['rol'],
                    'class' => self::CLASS_SHARED_OR_REAL,
                ];
            }
        }

        foreach ($dependencies as $dep) {
            if ((int) ($dep['row_count'] ?? 0) <= 0) {
                continue;
            }
            $class = (string) ($dep['class'] ?? self::CLASS_UNKNOWN);
            $code = self::blockerCodeForClass($class);
            if ($code === null) {
                continue;
            }
            $blockers[] = [
                'code' => $code,
                'table' => $dep['table'],
                'row_count' => $dep['row_count'],
                'class' => $class,
                'reason' => $dep['reason'] ?? ($class === self::CLASS_UNKNOWN ? 'unknown_dependency' : 'historical_or_shared'),
                'owner' => $dep['owner'] ?? null,
                'retention_category' => $dep['retention_category'] ?? null,
                'handoff' => $dep['handoff'] ?? null,
            ];
        }

        $purgeSafe = count($blockers) === 0;
        $deleteOrder = $purgeSafe
            ? self::deterministicDeleteOrder($dependencies, $users, $personelId)
            : [];

        $preserved = [];
        foreach ($users as $user) {
            if (($user['fixture_eligible'] ?? false) !== true) {
                $preserved[] = [
                    'entity' => 'user',
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'action' => 'PRESERVE',
                ];
            }
        }

        return [
            'status' => $purgeSafe ? 'PURGE_SAFE' : 'FAIL_CLOSED',
            'personel_id' => $personelId,
            'sicil_no' => isset($personel['sicil_no']) ? (string) $personel['sicil_no'] : null,
            'aktif_durum' => isset($personel['aktif_durum']) ? (string) $personel['aktif_durum'] : null,
            'classification' => [
                'sinif' => (string) $classification['sinif'],
                'evidence_kodu' => (string) $classification['evidence_kodu'],
            ],
            'dependencies' => $dependencies,
            'linked_users' => $users,
            'blockers' => $blockers,
            'purge_safe' => $purgeSafe,
            'canonical_owner' => 'TestFixturePersonelPurgeService',
            'delete_order' => $deleteOrder,
            'preserved_data' => $preserved,
            'user_action' => count($preserved) > 0 ? 'PRESERVE_NON_FIXTURE_USERS' : 'NO_BUSINESS_USER',
            'expected_postimage' => $purgeSafe
                ? ['personel_exists' => false, 'fixture_rows_removed' => true]
                : ['personel_exists' => true, 'no_delete' => true],
            'rollback_recovery' => $purgeSafe ? 'IRREVERSIBLE' : 'NONE',
            'executed' => false,
            'fake_employment_exit_created' => false,
        ];
    }

    /**
     * Canonical blocker mapping per relation class.
     * null = fixture-owned dependent (not a blocker).
     *
     * @param mixed $class
     * @return string|null
     */
    private static function blockerCodeForClass($class)
    {
        $class = (string) $class;
        if ($class === self::CLASS_AUDIT_APPEND_ONLY) {
            return self::CODE_AUDIT_IMMUTABLE;
        }
        if (in_array($class, [
            self::CLASS_SHARED_OR_REAL,
            self::CLASS_SEALED_HISTORICAL,
            self::CLASS_CLOSED_PERIOD_ARTIFACT,
        ], true)) {
            return self::CODE_HISTORICAL;
        }
        if ($class === self::CLASS_UNKNOWN) {
            return self::CODE_SHARED_OR_UNKNOWN;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function inventoryDependencies(PDO $pdo, $personelId)
    {
        $personelId = (int) $personelId;
        $refs = self::discoverPersonelReferences($pdo);
        $out = [];
        foreach ($refs as $ref) {
            $table = (string) $ref['table'];
            $column = (string) $ref['column'];
            if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $column)) {
                continue;
            }
            $count = 0;
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = :pid");
                $stmt->execute(['pid' => $personelId]);
                $count = (int) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                $out[] = [
                    'table' => $table,
                    'column' => $column,
                    'row_count' => -1,
                    'delete_rule' => $ref['delete_rule'],
                    'class' => self::CLASS_UNKNOWN,
                    'reason' => 'count_failed',
                    'owner' => null,
                    'retention_category' => null,
                    'handoff' => null,
                    'shared_other_personel' => null,
                    'historical_semantics' => false,
                ];
                continue;
            }

            $shared = self::sharedOtherPersonel($pdo, $table, $personelId);
            $classMeta = self::classifyDependency($pdo, $personelId, $table, $ref['delete_rule'], $count, $shared);
            $out[] = [
                'table' => $table,
                'column' => $column,
                'row_count' => $count,
                'delete_rule' => $ref['delete_rule'],
                'class' => $classMeta['class'],
                'reason' => $classMeta['reason'],
                'owner' => $classMeta['owner'],
                'retention_category' => $classMeta['retention_category'],
                'handoff' => $classMeta['handoff'],
                'shared_other_personel' => $shared,
                'historical_semantics' => $classMeta['historical'],
            ];
        }

        usort($out, static function (array $a, array $b) {
            return strcmp((string) $a['table'], (string) $b['table']);
        });

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function discoverPersonelReferences(PDO $pdo)
    {
        $byTable = [];
        $fk = $pdo->query(
            "SELECT kcu.TABLE_NAME AS tbl, kcu.COLUMN_NAME AS col, rc.DELETE_RULE AS delete_rule
             FROM information_schema.KEY_COLUMN_USAGE kcu
             INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.REFERENCED_TABLE_NAME = 'personeller'
               AND kcu.REFERENCED_COLUMN_NAME = 'id'"
        );
        if ($fk) {
            foreach ($fk->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $table = (string) $row['tbl'];
                $byTable[$table] = [
                    'table' => $table,
                    'column' => (string) $row['col'],
                    'delete_rule' => strtoupper((string) $row['delete_rule']),
                ];
            }
        }

        $cols = $pdo->query(
            "SELECT TABLE_NAME AS tbl, COLUMN_NAME AS col
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND COLUMN_NAME IN ('personel_id', 'old_personel_id', 'new_personel_id')
               AND TABLE_NAME <> 'personeller'"
        );
        if ($cols) {
            foreach ($cols->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $table = (string) $row['tbl'];
                if (isset($byTable[$table])) {
                    continue;
                }
                $byTable[$table] = [
                    'table' => $table,
                    'column' => (string) $row['col'],
                    'delete_rule' => 'NONE',
                ];
            }
        }

        ksort($byTable);

        return array_values($byTable);
    }

    /**
     * @return int|null
     */
    private static function sharedOtherPersonel(PDO $pdo, $table, $personelId)
    {
        $table = (string) $table;
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            return null;
        }
        $periodCol = null;
        foreach (['donem_snapshot_id', 'muhur_id', 'ay', 'donem'] as $candidate) {
            if (self::columnExists($pdo, $table, $candidate)) {
                $periodCol = $candidate;
                break;
            }
        }
        if ($periodCol === null || !self::columnExists($pdo, $table, 'personel_id')) {
            return null;
        }
        $sql = "SELECT COUNT(DISTINCT o.personel_id)
                FROM `{$table}` mine
                INNER JOIN `{$table}` o ON o.`{$periodCol}` = mine.`{$periodCol}`
                WHERE mine.personel_id = :pid AND o.personel_id <> :pid2";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['pid' => (int) $personelId, 'pid2' => (int) $personelId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param mixed $deleteRule
     * @param mixed $count
     * @param mixed $sharedOther
     * @return array{class:string,reason:string,historical:bool,owner:?string,retention_category:?string,handoff:?string}
     */
    private static function classifyDependency(PDO $pdo, $personelId, $table, $deleteRule, $count, $sharedOther)
    {
        $table = (string) $table;
        if ($count <= 0) {
            return self::classification(self::CLASS_GENERATED_DEMO, 'empty', false);
        }

        // Owner-attributed relations win over FK/sharing heuristics: a per-personel row can
        // share a period with another personel and still be a fixture-owned dependent row.
        $catalog = self::relationOwnerCatalog();
        if (isset($catalog[$table])) {
            $entry = $catalog[$table];

            return self::classification(
                (string) $entry['class'],
                (string) $entry['reason'],
                (bool) $entry['historical'],
                (string) $entry['owner'],
                $entry['retention_category'] !== null ? (string) $entry['retention_category'] : null,
                (string) $entry['handoff']
            );
        }

        if ($table === 'aylik_ozet_satirlari') {
            return self::classifyAylikOzetSatirlari($pdo, $personelId);
        }

        if ($sharedOther !== null && (int) $sharedOther > 0) {
            return self::classification(self::CLASS_SHARED_OR_REAL, 'shared_period_with_other_personel', true);
        }
        if ($table === 'users' || $table === 'user_personel_binding_auditleri') {
            return self::classification(self::CLASS_GENERATED_DEMO, 'user_binding_evaluated_separately', false);
        }
        if (self::isHistoricalTable($table)) {
            return self::classification(self::CLASS_SHARED_OR_REAL, 'historical_ledger_or_snapshot', true);
        }
        if (self::isFixtureOwnedTable($table)) {
            return self::classification(
                $table === 'personel_test_fixture_siniflandirmalari'
                    || $table === 'personel_test_fixture_archive_kayitlari'
                    || $table === 'personel_bordro_kapsamlari'
                    ? self::CLASS_CONFIRMED_DEMO
                    : self::CLASS_GENERATED_DEMO,
                'fixture_owned',
                false
            );
        }

        return self::classification(self::CLASS_UNKNOWN, 'unlisted_relation', false);
    }

    /**
     * Owner-attributed classification for relations whose deletion cannot be decided from FK
     * metadata alone (live dry-run blockers, 2026-09-15).
     *
     * Every entry names the canonical owner of the row lifecycle. Deletion for these relations
     * belongs to that owner's contract, never to the fixture purge:
     * - ArchiveAccessService writes the append-only archive access audit and never deletes.
     * - The PUANTAJ muhur lines are sealed period evidence (retention imha owner).
     * - The payroll personel snapshot is part of an immutable hashed period snapshot
     *   (BORDRO retention owner preserves period snapshots).
     *
     * @return array<string, array<string, mixed>>
     */
    private static function relationOwnerCatalog()
    {
        return [
            'arsiv_erisim_auditleri' => [
                'class' => self::CLASS_AUDIT_APPEND_ONLY,
                'reason' => 'append_only_archive_access_audit',
                'historical' => true,
                'owner' => self::OWNER_ARCHIVE_ACCESS_SERVICE,
                'retention_category' => null,
                'handoff' => self::HANDOFF_ARCHIVE_ACCESS_AUDIT,
            ],
            'puantaj_aylik_muhur_satirlari' => [
                'class' => self::CLASS_SEALED_HISTORICAL,
                'reason' => 'sealed_period_evidence_line',
                'historical' => true,
                'owner' => self::OWNER_PUANTAJ_DESTRUCTION,
                'retention_category' => RetentionCategories::PUANTAJ,
                'handoff' => self::HANDOFF_RETENTION_IMHA,
            ],
            'maas_hesaplama_personel_snapshotlari' => [
                'class' => self::CLASS_SEALED_HISTORICAL,
                'reason' => 'immutable_payroll_snapshot_ledger',
                'historical' => true,
                'owner' => self::OWNER_MAAS_SNAPSHOT_SERVICE,
                'retention_category' => RetentionCategories::BORDRO,
                'handoff' => self::HANDOFF_RETENTION_IMHA,
            ],
        ];
    }

    /**
     * aylik_ozet_satirlari = one row per personel per month, owned by the aylik kapanis flow.
     * That owner protects approved/closed rows (UPDATE ... AND kapanis_durumu <> 'KAPANDI'),
     * so a KAPANDI row is a closed period artifact and is never rewritten by a purge.
     * An open fixture row is derived from the fixture's own data and leaves with the personel
     * (FK ON DELETE CASCADE).
     *
     * @return array{class:string,reason:string,historical:bool,owner:?string,retention_category:?string,handoff:?string}
     */
    private static function classifyAylikOzetSatirlari(PDO $pdo, $personelId)
    {
        if (!self::columnExists($pdo, 'aylik_ozet_satirlari', 'kapanis_durumu')) {
            return self::classification(
                self::CLASS_CLOSED_PERIOD_ARTIFACT,
                'closed_period_state_unverifiable',
                true,
                self::OWNER_AYLIK_KAPANIS,
                null,
                self::HANDOFF_AYLIK_KAPANIS
            );
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM aylik_ozet_satirlari
             WHERE personel_id = :pid AND UPPER(kapanis_durumu) = 'KAPANDI'"
        );
        $stmt->execute(['pid' => (int) $personelId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return self::classification(
                self::CLASS_CLOSED_PERIOD_ARTIFACT,
                'closed_period_summary_artifact',
                true,
                self::OWNER_AYLIK_KAPANIS,
                null,
                self::HANDOFF_AYLIK_KAPANIS
            );
        }

        return self::classification(self::CLASS_GENERATED_DEMO, 'fixture_owned_open_period_summary', false);
    }

    /**
     * @param mixed $owner
     * @param mixed $retentionCategory
     * @param mixed $handoff
     * @return array{class:string,reason:string,historical:bool,owner:?string,retention_category:?string,handoff:?string}
     */
    private static function classification($class, $reason, $historical, $owner = null, $retentionCategory = null, $handoff = null)
    {
        return [
            'class' => (string) $class,
            'reason' => (string) $reason,
            'historical' => (bool) $historical,
            'owner' => $owner !== null ? (string) $owner : null,
            'retention_category' => $retentionCategory !== null ? (string) $retentionCategory : null,
            'handoff' => $handoff !== null ? (string) $handoff : null,
        ];
    }

    private static function isHistoricalTable($table)
    {
        $table = (string) $table;
        // aylik_ozet_satirlari, puantaj_aylik_muhur_satirlari and
        // maas_hesaplama_personel_snapshotlari are owner-classified in
        // relationOwnerCatalog()/classifyAylikOzetSatirlari() instead of this list.
        $exact = [
            'maas_hesaplama_adaylari',
            'personel_bordro_devirleri',
            'personel_bordro_devir_auditleri',
        ];
        if (in_array($table, $exact, true)) {
            return true;
        }

        return (bool) preg_match('/(^sgk_|_snapshot|muhur|yasal_finans)/', $table);
    }

    private static function isFixtureOwnedTable($table)
    {
        $table = (string) $table;
        $owned = [
            'gunluk_puantaj',
            'surecler',
            'gunluk_bildirimler',
            'personel_ucret_gecmisi',
            'personel_ucret_auditleri',
            'personel_bordro_kapsamlari',
            'personel_bordro_kapsam_auditleri',
            'personel_test_fixture_siniflandirmalari',
            'personel_test_fixture_archive_kayitlari',
            'fazla_calisma_odeme_tercihleri',
            'serbest_zaman_events',
            'serbest_zaman_kullanim_tahsisleri',
            'qr_attendance_events',
            'qr_attendance_correction_requests',
            'personel_inbox_notifications',
            'onayli_bildirim_puantaj_etki_adaylari',
            'bildirim_puantaj_etki_cakisma_cozumleri',
            'personel_organizasyon_degisiklik_auditleri',
            'personel_sube_degisiklik_auditleri',
            'user_personel_binding_auditleri',
            'personel_account_onboarding_auditleri',
            'yillik_izin_hak_duzeltmeleri',
            'ek_odeme_kesinti',
            'puantaj_olay_kararlari',
            'disiplin_vakalar',
            'personel_gecici_gorevlendirmeler',
            'haftalik_kapanis_satirlari',
            'haftalik_kapanis_revizyon_talepleri',
            'haftalik_kapanis_revizyon_corrections',
            'arsiv_manifestleri',
            'legal_holdlar',
            'retention_imha_talepleri',
        ];

        return in_array($table, $owned, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function inventoryUsers(PDO $pdo, $personelId)
    {
        if (!self::tableExists($pdo, 'users') || !self::columnExists($pdo, 'users', 'personel_id')) {
            return [];
        }
        $stmt = $pdo->prepare(
            'SELECT id, username, rol, durum, personel_id FROM users WHERE personel_id = :pid ORDER BY id ASC'
        );
        $stmt->execute(['pid' => (int) $personelId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rol = strtoupper(trim((string) ($row['rol'] ?? '')));
            $durum = strtoupper(trim((string) ($row['durum'] ?? '')));
            $eligible = $rol === 'PERSONEL' && $durum === 'PASIF';
            $out[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'rol' => $rol,
                'durum' => $durum,
                'fixture_eligible' => $eligible,
                'class' => $eligible ? self::CLASS_GENERATED_DEMO : self::CLASS_SHARED_OR_REAL,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $dependencies
     * @param list<array<string, mixed>> $users
     * @return list<array<string, mixed>>
     */
    private static function deterministicDeleteOrder(array $dependencies, array $users, $personelId)
    {
        $steps = [];
        foreach ($users as $user) {
            if (($user['fixture_eligible'] ?? false) === true) {
                $steps[] = [
                    'table' => 'user_subeler',
                    'op' => 'DELETE_BY_USER',
                    'user_id' => $user['id'],
                ];
                $steps[] = [
                    'table' => 'users',
                    'op' => 'UNBIND_THEN_DELETE',
                    'user_id' => $user['id'],
                    'personel_id' => (int) $personelId,
                ];
            }
        }

        $childTables = [];
        foreach ($dependencies as $dep) {
            if ((int) ($dep['row_count'] ?? 0) <= 0) {
                continue;
            }
            $table = (string) $dep['table'];
            $rule = strtoupper((string) ($dep['delete_rule'] ?? ''));
            if ($table === 'users') {
                continue;
            }
            if ($rule === 'CASCADE' || $rule === 'SET NULL') {
                continue;
            }
            $childTables[] = [
                'table' => $table,
                'op' => 'DELETE_BY_PERSONEL',
                'column' => (string) $dep['column'],
                'personel_id' => (int) $personelId,
            ];
        }
        usort($childTables, static function (array $a, array $b) {
            return strcmp((string) $a['table'], (string) $b['table']);
        });
        foreach ($childTables as $step) {
            $steps[] = $step;
        }
        $steps[] = [
            'table' => 'personeller',
            'op' => 'DELETE_BY_ID',
            'personel_id' => (int) $personelId,
        ];

        return $steps;
    }

    /**
     * @param array<string, mixed> $step
     */
    private static function executeDeleteStep(PDO $pdo, array $step)
    {
        $table = (string) ($step['table'] ?? '');
        if (!preg_match('/^[a-z0-9_]+$/', $table)) {
            throw new TestFixturePersonelArchiveException('PURGE_PLAN_INVALID', 'Gecersiz delete table.', 500);
        }
        $op = (string) ($step['op'] ?? '');
        if ($op === 'DELETE_BY_USER') {
            if (!self::tableExists($pdo, $table)) {
                return;
            }
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE user_id = :uid");
            $stmt->execute(['uid' => (int) $step['user_id']]);

            return;
        }
        if ($op === 'UNBIND_THEN_DELETE') {
            $unbind = $pdo->prepare('UPDATE users SET personel_id = NULL WHERE id = :uid AND personel_id = :pid');
            $unbind->execute(['uid' => (int) $step['user_id'], 'pid' => (int) $step['personel_id']]);
            $del = $pdo->prepare(
                "DELETE FROM users WHERE id = :uid AND rol = 'PERSONEL' AND UPPER(durum) = 'PASIF'"
            );
            $del->execute(['uid' => (int) $step['user_id']]);
            if ($del->rowCount() !== 1) {
                throw new TestFixturePersonelArchiveException(
                    self::CODE_USER_NOT_FIXTURE,
                    'Fixture user silinemedi; purge fail-closed.',
                    409,
                    'user_id'
                );
            }

            return;
        }
        if ($op === 'DELETE_BY_PERSONEL') {
            $column = (string) ($step['column'] ?? 'personel_id');
            if (!preg_match('/^[a-z0-9_]+$/', $column)) {
                throw new TestFixturePersonelArchiveException('PURGE_PLAN_INVALID', 'Gecersiz kolon.', 500);
            }
            $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` = :pid");
            $stmt->execute(['pid' => (int) $step['personel_id']]);

            return;
        }
        if ($op === 'DELETE_BY_ID') {
            $stmt = $pdo->prepare('DELETE FROM personeller WHERE id = :pid');
            $stmt->execute(['pid' => (int) $step['personel_id']]);
            if ($stmt->rowCount() !== 1) {
                throw new TestFixturePersonelArchiveException('PURGE_RACE', 'Personel silinemedi.', 409);
            }

            return;
        }

        throw new TestFixturePersonelArchiveException('PURGE_PLAN_INVALID', 'Bilinmeyen delete op.', 500);
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    private static function persistAudit(PDO $pdo, array $plan, array $actor, $dryRun)
    {
        $payload = [
            'actor_id' => isset($actor['id']) ? (int) $actor['id'] : null,
            'dry_run' => $dryRun ? true : false,
            'personel_id' => $plan['personel_id'],
            'purge_safe' => $plan['purge_safe'],
            'status' => $plan['status'],
            'evidence_kodu' => $plan['classification']['evidence_kodu'] ?? null,
            'inventory' => $plan['dependencies'],
            'delete_order' => $plan['delete_order'],
        ];
        if (!self::tableExists($pdo, 'personel_test_fixture_purge_auditleri')) {
            return [
                'persisted' => false,
                'reason' => 'audit_table_optional',
                'payload_digest' => hash('sha256', (string) json_encode($payload)),
            ];
        }
        $stmt = $pdo->prepare(
            'INSERT INTO personel_test_fixture_purge_auditleri
                (personel_id_preimage, sicil_no, evidence_kodu, dry_run, status, inventory_json, delete_order_json, actor_id)
             VALUES
                (:pid, :sicil, :evidence, :dry, :status, :inv, :ord, :actor)'
        );
        $stmt->execute([
            'pid' => (int) $plan['personel_id'],
            'sicil' => $plan['sicil_no'],
            'evidence' => $plan['classification']['evidence_kodu'] ?? null,
            'dry' => $dryRun ? 1 : 0,
            'status' => (string) $plan['status'],
            'inv' => json_encode($plan['dependencies']),
            'ord' => json_encode($plan['delete_order']),
            'actor' => isset($actor['id']) ? (int) $actor['id'] : null,
        ]);

        return [
            'persisted' => true,
            'id' => (int) $pdo->lastInsertId(),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function lockPersonel(PDO $pdo, $personelId)
    {
        $stmt = $pdo->prepare(
            'SELECT id, aktif_durum, sicil_no, ad, soyad, sube_id FROM personeller WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => (int) $personelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private static function tableExists(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => (string) $table]);

        return (int) $stmt->fetchColumn() === 1;
    }

    private static function columnExists(PDO $pdo, $table, $column)
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => (string) $table, 'c' => (string) $column]);

        return (int) $stmt->fetchColumn() === 1;
    }

    /** @param mixed $value */
    private static function isTruthy($value, $default)
    {
        if ($value === null) {
            return (bool) $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        $raw = strtolower(trim((string) $value));

        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
