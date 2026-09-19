<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Auth\PasswordHasher;
use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\ResponseCaptured;
use PDO;
use Throwable;

/**
 * Controlled APPLY path for the PERSONEL canonical first-login credential rollout.
 *
 * Why this exists as its own owner: the credential DECISION belongs to
 * PersonelAccountOnboardingService (canonical owner), and the write itself is still
 * performed by that owner with `apply = true`. This class adds the operational
 * envelope the production rollout needs and nothing else:
 *
 *   1. SAME-COHORT GATE  — re-derives the canonical dry-run plan and refuses unless its
 *                          secret-free plan fingerprint exactly matches the pinned
 *                          fingerprint the operator read from the preflight artifact.
 *   2. SCOPE/INVARIANT   — empty cohort, username collisions, name-unresolved,
 *                          protected username in plan and rollout-excluded personel
 *                          (219 / doguA) all fail closed before any write.
 *   3. ONE-SHOT/REPLAY   — a completed rollout or an already-applied target refuses the
 *                          whole operation, so a rerun can never restore a template
 *                          password over a password a user already changed.
 *   4. PREIMAGE          — reads the exact affected rows BEFORE the mutation and hands a
 *                          recovery preimage to a callback that persists it. The preimage
 *                          contains the old password_hash (recovery needs it) but never a
 *                          plaintext password, is persisted outside the write path and is
 *                          NEVER part of the published report.
 *   5. POSTCHECK         — reads the cohort back and proves the target state.
 *
 * The report this class returns is SECRET-FREE: counts, bounded plan rows, reason codes and
 * the plan fingerprint only. No plaintext password, no password_hash, no recovery preimage
 * value ever enters it, so it can be published as CI evidence.
 *
 * The credential rules are never reimplemented here: every decision is delegated to
 * PersonelAccountOnboardingService.
 */
final class PersonelFirstLoginCredentialsApplyReport
{
    public const SCHEMA_VERSION = '1';

    public const MODE = 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY';

    /** Purpose marker of the secure, non-published recovery preimage. */
    public const PREIMAGE_PURPOSE = 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLY_PREIMAGE';

    /** The pinned fingerprint is not a sha256 hex string; nothing was read, nothing written. */
    public const BLOCKER_FINGERPRINT_INVALID = 'PERSONEL_FIRST_LOGIN_APPLY_FINGERPRINT_INVALID';
    /** The canonical owner refused the dry-run; the cohort is unknown. */
    public const BLOCKER_DECISION_REFUSED = 'PERSONEL_FIRST_LOGIN_APPLY_DECISION_REFUSED';
    /** The canonical owner answered with neither a usable plan nor a bounded refusal. */
    public const BLOCKER_DECISION_UNREADABLE = 'PERSONEL_FIRST_LOGIN_APPLY_DECISION_UNREADABLE';
    /** The canonical owner refused the write with a code this report does not map. */
    public const BLOCKER_OWNER_BLOCKED = 'PERSONEL_FIRST_LOGIN_APPLY_OWNER_BLOCKED';
    /** A rollout of zero targets must never be reported as a success. */
    public const BLOCKER_EMPTY_COHORT = 'PERSONEL_FIRST_LOGIN_APPLY_EMPTY_COHORT';
    /** The plan and the excluded buckets do not add up to the independent cohort count. */
    public const BLOCKER_COHORT_RECONCILIATION = 'PERSONEL_FIRST_LOGIN_APPLY_COHORT_RECONCILIATION_FAILED';
    /** Two plan rows would produce the same canonical username. */
    public const BLOCKER_PLAN_USERNAME_NOT_UNIQUE = 'PERSONEL_FIRST_LOGIN_APPLY_PLAN_USERNAME_NOT_UNIQUE';
    /** A reserved account appeared in the mutation plan, which must never happen. */
    public const BLOCKER_PROTECTED_USERNAME_IN_PLAN = 'PERSONEL_FIRST_LOGIN_APPLY_PROTECTED_USERNAME_IN_PLAN';
    /** The recovery preimage could not be persisted; the write must not start. */
    public const BLOCKER_PREIMAGE_PERSIST_FAILED = 'PERSONEL_FIRST_LOGIN_APPLY_PREIMAGE_PERSIST_FAILED';
    /** The canonical apply path raised instead of returning a bounded decision. */
    public const BLOCKER_OWNER_FAILED = 'PERSONEL_FIRST_LOGIN_APPLY_OWNER_FAILED';
    /** The write was attempted but the read-back does not match the expected state. */
    public const BLOCKER_POSTCHECK_FAILED = 'PERSONEL_FIRST_LOGIN_APPLY_POSTCHECK_FAILED';

    /**
     * Bounded, PII-free plan row published in the report (same shape the read-only
     * preflight publishes, so both artifacts are gated the same way).
     */
    public const PLAN_ROW_FIELDS = [
        'user_id',
        'personel_id',
        'old_username',
        'new_username',
        'username_changed',
        'business_override',
        'name_correction_present',
        'name_correction_preimage_match',
    ];

    /**
     * Run the controlled apply.
     *
     * @param string $deployedSha exact deploy SHA the collecting worker is pinned to
     * @param string $expectedPlanFingerprint the plan fingerprint read from the preflight artifact
     * @param callable $persistPreimage function(array $preimage): string — persists the recovery
     *        preimage and returns its sha256. It is called AFTER every gate and BEFORE the
     *        mutation. If it throws, the write never starts.
     * @return array<string, mixed> secret-free report, safe to publish
     */
    public static function run(
        PDO $pdo,
        string $deployedSha,
        string $expectedPlanFingerprint,
        callable $persistPreimage
    ): array {
        $expected = strtolower(trim($expectedPlanFingerprint));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            return self::blocked(self::envelope($deployedSha, $expected), self::BLOCKER_FINGERPRINT_INVALID);
        }

        // The cohort is always re-derived from the canonical owner, the same call the
        // read-only preflight makes. No request field can widen it.
        $captured = null;
        $dry = null;
        JsonResponse::beginCapture();
        try {
            $dry = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials($pdo, null, false);
            $captured = JsonResponse::capturedResponse();
        } catch (ResponseCaptured $refusal) {
            $captured = JsonResponse::capturedResponse();
        } finally {
            JsonResponse::endCapture();
        }

        $personelTotal = self::personelRoleTotal($pdo);
        $report = array_merge(self::envelope($deployedSha, $expected), self::cohortFields($personelTotal));

        if (is_array($captured)) {
            $code = self::boundedCode($captured['errors'][0]['code'] ?? null);

            return self::blocked(
                $report,
                $code === null ? self::BLOCKER_DECISION_UNREADABLE : self::BLOCKER_DECISION_REFUSED,
                ['refusal_code' => $code]
            );
        }
        if (!is_array($dry)) {
            return self::blocked($report, self::BLOCKER_DECISION_UNREADABLE);
        }

        $report = self::withCohort($report, $dry, $personelTotal);

        if (($dry['blocked'] ?? false) === true) {
            return self::blocked($report, self::mapOwnerBlocker($dry['blocker'] ?? null));
        }

        $planFingerprint = self::fingerprintOf($dry);
        if ($planFingerprint === '' || $planFingerprint !== $expected) {
            return self::blocked($report, PersonelAccountOnboardingService::ERR_PLAN_FINGERPRINT_MISMATCH);
        }

        $invariantBlockers = self::cohortInvariantBlockers($report);
        if ($invariantBlockers !== []) {
            return self::blocked($report, $invariantBlockers[0], ['invariant_blockers' => $invariantBlockers]);
        }

        // Cohort-level one-shot: a completed rollout is never entered again.
        if (PersonelAccountOnboardingService::firstLoginRolloutApplied($pdo)) {
            return self::blocked($report, PersonelAccountOnboardingService::ERR_ROLLOUT_ALREADY_APPLIED);
        }

        // Recovery evidence is read and persisted BEFORE the mutation. The preimage is
        // deliberately NOT part of this report: it carries the old password_hash.
        $preimage = self::buildPreimage($pdo, $deployedSha, $dry);
        try {
            $preimageSha = $persistPreimage($preimage);
        } catch (Throwable $persistenceFailure) {
            return self::blocked(
                array_merge($report, self::preimageFields($preimage, null)),
                self::BLOCKER_PREIMAGE_PERSIST_FAILED
            );
        }
        $preimageSha = is_string($preimageSha) ? $preimageSha : '';
        $report = array_merge($report, self::preimageFields($preimage, $preimageSha));

        // The write: the canonical owner re-derives the plan, re-gates on the pinned
        // fingerprint and applies the whole cohort in one transaction.
        $applyCaptured = null;
        $applied = null;
        JsonResponse::beginCapture();
        try {
            $applied = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials(
                $pdo,
                null,
                true,
                $expected
            );
            $applyCaptured = JsonResponse::capturedResponse();
        } catch (ResponseCaptured $refusal) {
            $applyCaptured = JsonResponse::capturedResponse();
        } finally {
            JsonResponse::endCapture();
        }

        if (is_array($applyCaptured)) {
            $code = self::boundedCode($applyCaptured['errors'][0]['code'] ?? null);

            return self::blocked($report, $code === null ? self::BLOCKER_OWNER_FAILED : $code);
        }
        if (!is_array($applied)) {
            return self::blocked($report, self::BLOCKER_OWNER_FAILED);
        }
        if (($applied['blocked'] ?? false) === true || ($applied['apply'] ?? false) !== true) {
            return self::blocked($report, self::mapOwnerBlocker($applied['blocker'] ?? null));
        }

        $report['decision_apply'] = true;
        $report['applied_count'] = (int) ($applied['applied_count'] ?? 0);
        $report['user_mutation_count'] = (int) ($applied['applied_count'] ?? 0);
        $report['name_correction_mutation_count'] = (int) ($applied['name_correction_count'] ?? 0);
        $report['production_mutation_count'] = $report['user_mutation_count'] + $report['name_correction_mutation_count'];
        $report['rollout_ledger_recorded'] = true;
        $report['rollout_ledger_event'] = PersonelAccountOnboardingService::EVENT_FIRST_LOGIN_ROLLOUT_APPLIED;

        $postcheck = self::postcheck($pdo, $applied['plan'] ?? [], $preimage);
        $report = array_merge($report, $postcheck);

        if ($report['applied_count'] !== $report['target_count']
            || $report['credential_targets_reconciled'] !== true
            || $report['excluded_unchanged'] !== true
            || $report['ilkera_unchanged'] !== true
        ) {
            return self::blocked($report, self::BLOCKER_POSTCHECK_FAILED, ['stage' => 'POSTCHECK']);
        }

        $report['result'] = 'PASS';

        return $report;
    }

    /**
     * The fields every report carries, whatever the outcome.
     *
     * @return array<string, mixed>
     */
    private static function envelope(string $deployedSha, string $expectedPlanFingerprint): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'deployed_sha' => strtolower($deployedSha),
            'mode' => self::MODE,
            'result' => 'BLOCKED',
            'blocker' => null,
            'blockers' => [],
            'stage' => 'PREFLIGHT',
            'expected_plan_fingerprint' => $expectedPlanFingerprint,
            'plan_fingerprint' => null,
            'decision_apply' => false,
            'applied_count' => 0,
            'user_mutation_count' => 0,
            'name_correction_mutation_count' => 0,
            'production_mutation_count' => 0,
            'rollout_ledger_recorded' => false,
            'rollout_ledger_event' => null,
            'protected_usernames' => array_values(PersonelAccountOnboardingService::PROTECTED_USERNAMES),
            'rollout_excluded_personel_ids' => array_values(PersonelAccountOnboardingService::ROLLOUT_EXCLUDED_PERSONEL_IDS),
            'rollout_excluded_in_plan_count' => 0,
            'refusal_code' => null,
            'owner_blocked' => false,
            'owner_blocker' => null,
            'invariant_blockers' => [],
            'replayed_user_ids' => [],
            'plan' => [],
        ];
    }

    /**
     * Cohort fields derived from the independent COUNT(*), before the plan is known.
     *
     * @return array<string, mixed>
     */
    private static function cohortFields(int $personelTotal): array
    {
        return [
            'personel_total' => $personelTotal,
            'target_count' => 0,
            'excluded_total' => 0,
            'excluded_counts' => [],
            'cohort_reconciled' => false,
            'collision_count' => 0,
            'collisions' => [],
            'name_unresolved_count' => 0,
            'name_correction_count' => 0,
            'name_correction_preimage_mismatch_count' => 0,
            'business_override_count' => 0,
            'username_changed_count' => 0,
            'protected_username_excluded_count' => 0,
            'protected_username_in_plan_count' => 0,
            'ilkera_touched' => false,
            'credential_targets_reconciled' => false,
            'credential_target_mismatch_count' => 0,
            'name_corrections_reconciled' => false,
            'name_correction_mismatch_count' => 0,
            'excluded_unchanged' => false,
            'excluded_changed_count' => 0,
            'ilkera_unchanged' => false,
        ];
    }

    /**
     * Reduce the canonical dry-run plan to bounded, PII-free report fields.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function withCohort(array $report, array $result, int $personelTotal): array
    {
        $excludedCounts = [];
        foreach (PersonelFirstLoginCredentialsPreflightReport::EXCLUDED_BUCKETS as $bucket) {
            $rows = is_array($result['excluded'][$bucket] ?? null) ? $result['excluded'][$bucket] : [];
            $excludedCounts[$bucket] = count($rows);
        }
        $excludedTotal = array_sum($excludedCounts);

        $protected = [];
        foreach (PersonelAccountOnboardingService::PROTECTED_USERNAMES as $reserved) {
            $protected[strtolower((string) $reserved)] = true;
        }
        $excludedPersonelIds = PersonelAccountOnboardingService::ROLLOUT_EXCLUDED_PERSONEL_IDS;

        $plan = [];
        $nameCorrectionCount = 0;
        $businessOverrideCount = 0;
        $usernameChangedCount = 0;
        $protectedInPlanCount = 0;
        $excludedInPlanCount = 0;
        $ilkeraTouched = false;
        $newUsernames = [];
        foreach (is_array($result['plan'] ?? null) ? $result['plan'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $oldUsername = (string) ($row['old_username'] ?? '');
            $newUsername = (string) ($row['new_username'] ?? '');
            $correction = is_array($row['name_correction'] ?? null) ? $row['name_correction'] : null;
            $preimageMatch = $correction === null ? null : (($correction['preimage_match'] ?? false) === true);
            $usernameChanged = ($row['username_changed'] ?? false) === true;
            $businessOverride = ($row['business_override'] ?? false) === true;
            $personelId = (int) ($row['personel_id'] ?? 0);

            if (isset($protected[strtolower($oldUsername)]) || isset($protected[strtolower($newUsername)])) {
                ++$protectedInPlanCount;
                $ilkeraTouched = true;
            }
            if (in_array($personelId, $excludedPersonelIds, true)) {
                ++$excludedInPlanCount;
            }
            if ($correction !== null) {
                ++$nameCorrectionCount;
            }
            if ($businessOverride) {
                ++$businessOverrideCount;
            }
            if ($usernameChanged) {
                ++$usernameChangedCount;
            }
            if ($newUsername !== '') {
                $newUsernames[$newUsername] = ($newUsernames[$newUsername] ?? 0) + 1;
            }

            $plan[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'personel_id' => $personelId,
                'old_username' => $oldUsername,
                'new_username' => $newUsername,
                'username_changed' => $usernameChanged,
                'business_override' => $businessOverride,
                'name_correction_present' => $correction !== null,
                'name_correction_preimage_match' => $preimageMatch,
            ];
        }

        $collisions = [];
        foreach (is_array($result['collisions'] ?? null) ? $result['collisions'] : [] as $collision) {
            if (!is_array($collision)) {
                continue;
            }
            $collisions[] = [
                'canonical_username' => (string) ($collision['canonical_username'] ?? ''),
                'scope' => (string) ($collision['scope'] ?? ''),
                'user_ids' => array_values(array_map(
                    static function ($id): int {
                        return (int) $id;
                    },
                    is_array($collision['user_ids'] ?? null) ? $collision['user_ids'] : []
                )),
            ];
        }

        $ownerBlocked = ($result['blocked'] ?? false) === true;

        return array_merge($report, [
            'plan_fingerprint' => self::fingerprintOf($result),
            'personel_total' => $personelTotal,
            'target_count' => count($plan),
            'excluded_total' => $excludedTotal,
            'excluded_counts' => $excludedCounts,
            'cohort_reconciled' => $personelTotal >= 0 && (count($plan) + $excludedTotal) === $personelTotal,
            'collision_count' => count($collisions),
            'collisions' => $collisions,
            'name_unresolved_count' => (int) ($excludedCounts['name_unresolved'] ?? 0),
            'name_correction_count' => $nameCorrectionCount,
            'name_correction_preimage_mismatch_count' => count(
                array_filter($plan, static function (array $row): bool {
                    return $row['name_correction_present'] === true
                        && $row['name_correction_preimage_match'] !== true;
                })
            ),
            'business_override_count' => $businessOverrideCount,
            'username_changed_count' => $usernameChangedCount,
            'protected_username_excluded_count' => (int) ($excludedCounts['protected_username'] ?? 0),
            'protected_username_in_plan_count' => $protectedInPlanCount,
            'rollout_excluded_in_plan_count' => $excludedInPlanCount,
            'ilkera_touched' => $ilkeraTouched,
            'owner_blocked' => $ownerBlocked,
            'owner_blocker' => is_string($result['blocker'] ?? null) ? (string) $result['blocker'] : null,
            'plan_username_unique' => self::usernamesAreUnique($newUsernames),
            'plan' => $plan,
        ]);
    }

    /**
     * Cohort invariants the apply refuses on, in fixed priority order.
     *
     * @param array<string, mixed> $report
     * @return list<string>
     */
    private static function cohortInvariantBlockers(array $report): array
    {
        $blockers = [];
        if ((int) $report['target_count'] <= 0) {
            $blockers[] = self::BLOCKER_EMPTY_COHORT;
        }
        if (($report['cohort_reconciled'] ?? false) !== true) {
            $blockers[] = self::BLOCKER_COHORT_RECONCILIATION;
        }
        if (($report['plan_username_unique'] ?? false) !== true) {
            $blockers[] = self::BLOCKER_PLAN_USERNAME_NOT_UNIQUE;
        }
        if ((int) $report['protected_username_in_plan_count'] > 0) {
            $blockers[] = self::BLOCKER_PROTECTED_USERNAME_IN_PLAN;
        }
        if ((int) $report['rollout_excluded_in_plan_count'] > 0) {
            $blockers[] = PersonelAccountOnboardingService::ERR_ROLLOUT_SCOPE_VIOLATION;
        }
        if ((int) $report['collision_count'] > 0) {
            $blockers[] = PersonelAccountOnboardingService::ERR_CANONICAL_USERNAME_COLLISION;
        }
        if ((int) $report['name_unresolved_count'] > 0) {
            $blockers[] = self::BLOCKER_COHORT_RECONCILIATION;
        }
        if ((int) $report['name_correction_preimage_mismatch_count'] > 0) {
            $blockers[] = PersonelAccountOnboardingService::ERR_NAME_CORRECTION_PREIMAGE;
        }

        return array_values(array_unique($blockers));
    }

    /**
     * Read the exact affected rows for recovery. Contains the OLD password_hash (recovery
     * cannot be reconstructed without it) and NO plaintext password. This array is handed to
     * the persistence callback and is never merged into the published report.
     *
     * @param array<string, mixed> $result canonical dry-run result
     * @return array<string, mixed>
     */
    private static function buildPreimage(PDO $pdo, string $deployedSha, array $result): array
    {
        $plan = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        $targetIds = [];
        $correctionIds = [];
        foreach ($plan as $row) {
            if (!is_array($row)) {
                continue;
            }
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $targetIds[$userId] = true;
            }
            if (is_array($row['name_correction'] ?? null)) {
                $personelId = (int) ($row['personel_id'] ?? 0);
                if ($personelId > 0) {
                    $correctionIds[$personelId] = true;
                }
            }
        }

        $excludedIds = [];
        foreach (PersonelFirstLoginCredentialsPreflightReport::EXCLUDED_BUCKETS as $bucket) {
            $rows = is_array($result['excluded'][$bucket] ?? null) ? $result['excluded'][$bucket] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $userId = (int) ($row['user_id'] ?? 0);
                if ($userId > 0) {
                    $excludedIds[$userId] = true;
                }
            }
        }

        $userRows = self::selectByIds(
            $pdo,
            'SELECT id, username, password_hash, rol, durum, personel_id, activation_required, must_change_password
               FROM users WHERE id IN (%s) ORDER BY id ASC',
            array_merge(array_keys($targetIds), array_keys($excludedIds))
        );
        $personelRows = self::selectByIds(
            $pdo,
            'SELECT id, ad, soyad FROM personeller WHERE id IN (%s) ORDER BY id ASC',
            array_keys($correctionIds)
        );

        $targets = [];
        foreach (array_keys($targetIds) as $userId) {
            $row = $userRows[$userId] ?? null;
            if ($row === null) {
                continue;
            }
            $targets[] = [
                'user_id' => (int) $row['id'],
                'personel_id' => $row['personel_id'] === null ? null : (int) $row['personel_id'],
                'username' => (string) $row['username'],
                'password_hash' => (string) $row['password_hash'],
                'rol' => (string) $row['rol'],
                'durum' => (string) $row['durum'],
                'activation_required' => (int) $row['activation_required'],
                'must_change_password' => (int) $row['must_change_password'],
            ];
        }
        usort($targets, static function (array $a, array $b): int {
            return $a['user_id'] <=> $b['user_id'];
        });

        $nameCorrections = [];
        foreach (array_keys($correctionIds) as $personelId) {
            $row = $personelRows[$personelId] ?? null;
            if ($row === null) {
                continue;
            }
            $nameCorrections[] = [
                'personel_id' => (int) $row['id'],
                'ad' => $row['ad'],
                'soyad' => $row['soyad'],
            ];
        }
        usort($nameCorrections, static function (array $a, array $b): int {
            return $a['personel_id'] <=> $b['personel_id'];
        });

        $excluded = [];
        foreach (array_keys($excludedIds) as $userId) {
            $row = $userRows[$userId] ?? null;
            if ($row === null) {
                continue;
            }
            $excluded[] = [
                'user_id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'activation_required' => (int) $row['activation_required'],
                'must_change_password' => (int) $row['must_change_password'],
            ];
        }
        usort($excluded, static function (array $a, array $b): int {
            return $a['user_id'] <=> $b['user_id'];
        });

        return [
            'schema_version' => '1',
            'purpose' => self::PREIMAGE_PURPOSE,
            'deployed_sha' => strtolower($deployedSha),
            'plan_fingerprint' => self::fingerprintOf($result),
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'recovery_note' => 'users.password_hash satirlari geri yukleme icin zorunludur; '
                . 'plaintext hicbir yerde saklanmaz. Bu artifact yayinlanmaz ve secret kabul edilir.',
            'targets' => $targets,
            'name_corrections' => $nameCorrections,
            'excluded' => $excluded,
        ];
    }

    /**
     * @param array<string, mixed> $preimage
     * @return array<string, mixed>
     */
    private static function preimageFields(array $preimage, ?string $sha256): array
    {
        return [
            'preimage_written' => $sha256 !== null && $sha256 !== '',
            'preimage_sha256' => $sha256,
            'preimage_target_count' => is_array($preimage['targets'] ?? null) ? count($preimage['targets']) : 0,
            'preimage_name_correction_count' => is_array($preimage['name_corrections'] ?? null)
                ? count($preimage['name_corrections'])
                : 0,
            'preimage_excluded_count' => is_array($preimage['excluded'] ?? null) ? count($preimage['excluded']) : 0,
            // Explicitly documented so the artifact contract is auditable from the report.
            'preimage_contains_password_hash' => true,
            'preimage_published' => false,
        ];
    }

    /**
     * Read the cohort back and prove the expected end state.
     *
     * @param array<int, array<string, mixed>> $plan the plan the canonical owner applied
     * @param array<string, mixed> $preimage the recovery preimage taken before the write
     * @return array<string, mixed>
     */
    private static function postcheck(PDO $pdo, array $plan, array $preimage): array
    {
        $targets = is_array($preimage['targets'] ?? null) ? $preimage['targets'] : [];
        $preimageByUser = [];
        foreach ($targets as $target) {
            if (is_array($target)) {
                $preimageByUser[(int) $target['user_id']] = $target;
            }
        }

        $appliedRows = [];
        foreach ($plan as $row) {
            if (is_array($row)) {
                $appliedRows[(int) ($row['user_id'] ?? 0)] = $row;
            }
        }

        $targetPersonelIds = [];
        foreach ($targets as $target) {
            if (is_array($target) && (int) ($target['personel_id'] ?? 0) > 0) {
                $targetPersonelIds[(int) $target['personel_id']] = true;
            }
        }
        $personelRows = self::selectByIds(
            $pdo,
            'SELECT id, ad, soyad FROM personeller WHERE id IN (%s) ORDER BY id ASC',
            array_keys($targetPersonelIds)
        );

        $live = self::selectByIds(
            $pdo,
            'SELECT id, username, password_hash, rol, personel_id, activation_required, must_change_password
               FROM users WHERE id IN (%s) ORDER BY id ASC',
            array_keys($preimageByUser)
        );

        $mismatchCount = 0;
        foreach ($preimageByUser as $userId => $before) {
            $appliedRow = $appliedRows[$userId] ?? null;
            $row = $live[$userId] ?? null;
            if ($row === null || $appliedRow === null) {
                ++$mismatchCount;
                continue;
            }
            $checks = [
                (string) $row['username'] === (string) ($appliedRow['new_username'] ?? ''),
                (int) $row['activation_required'] === 0,
                (int) $row['must_change_password'] === 1,
                (string) $row['rol'] === 'PERSONEL',
                (int) ($row['personel_id'] ?? 0) === (int) ($before['personel_id'] ?? 0),
            ];
            $personelId = (int) ($before['personel_id'] ?? 0);
            $personelRow = $personelRows[$personelId] ?? null;
            $material = $personelRow === null
                ? null
                : PersonelAccountOnboardingService::resolvePersonelInitialPasswordMaterial(
                    $personelId,
                    $personelRow['ad'] ?? null,
                    $personelRow['soyad'] ?? null
                );
            $checks[] = $material !== null && PasswordHasher::verify($material, (string) $row['password_hash']);

            foreach ($checks as $ok) {
                if ($ok !== true) {
                    ++$mismatchCount;
                    break;
                }
            }
        }

        $correctionMismatchCount = 0;
        foreach (is_array($preimage['name_corrections'] ?? null) ? $preimage['name_corrections'] : [] as $correction) {
            if (!is_array($correction)) {
                continue;
            }
            $personelId = (int) ($correction['personel_id'] ?? 0);
            $appliedCorrection = null;
            foreach ($plan as $row) {
                if (is_array($row)
                    && (int) ($row['personel_id'] ?? 0) === $personelId
                    && is_array($row['name_correction'] ?? null)
                ) {
                    $appliedCorrection = $row['name_correction'];
                    break;
                }
            }
            if ($appliedCorrection === null) {
                continue;
            }
            $row = $personelRows[$personelId] ?? null;
            $ok = $row !== null
                && (string) ($row['ad'] ?? '') === (string) ($appliedCorrection['to']['ad'] ?? '')
                && (string) ($row['soyad'] ?? '') === (string) ($appliedCorrection['to']['soyad'] ?? '');
            if ($ok !== true) {
                ++$correctionMismatchCount;
            }
        }

        $excludedRows = is_array($preimage['excluded'] ?? null) ? $preimage['excluded'] : [];
        $excludedIds = [];
        foreach ($excludedRows as $excludedRow) {
            if (is_array($excludedRow) && (int) ($excludedRow['user_id'] ?? 0) > 0) {
                $excludedIds[(int) $excludedRow['user_id']] = true;
            }
        }
        $liveExcluded = self::selectByIds(
            $pdo,
            'SELECT id, username, activation_required, must_change_password
               FROM users WHERE id IN (%s) ORDER BY id ASC',
            array_keys($excludedIds)
        );

        $protected = [];
        foreach (PersonelAccountOnboardingService::PROTECTED_USERNAMES as $reserved) {
            $protected[strtolower((string) $reserved)] = true;
        }

        $excludedChangedCount = 0;
        $ilkeraUnchanged = true;
        foreach ($excludedRows as $excludedRow) {
            if (!is_array($excludedRow)) {
                continue;
            }
            $userId = (int) ($excludedRow['user_id'] ?? 0);
            $row = $liveExcluded[$userId] ?? null;
            $unchanged = $row !== null
                && (string) $row['username'] === (string) ($excludedRow['username'] ?? '')
                && (int) $row['activation_required'] === (int) ($excludedRow['activation_required'] ?? -1)
                && (int) $row['must_change_password'] === (int) ($excludedRow['must_change_password'] ?? -1);
            if (!$unchanged) {
                ++$excludedChangedCount;
            }
            if (isset($protected[strtolower((string) ($excludedRow['username'] ?? ''))]) && !$unchanged) {
                $ilkeraUnchanged = false;
            }
        }

        return [
            'credential_target_mismatch_count' => $mismatchCount,
            'credential_targets_reconciled' => $mismatchCount === 0 && count($preimageByUser) > 0,
            'name_correction_mismatch_count' => $correctionMismatchCount,
            'name_corrections_reconciled' => $correctionMismatchCount === 0,
            'excluded_changed_count' => $excludedChangedCount,
            'excluded_unchanged' => $excludedChangedCount === 0,
            'ilkera_unchanged' => $ilkeraUnchanged,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function blocked(array $report, string $blocker, array $extra = []): array
    {
        $report['result'] = 'BLOCKED';
        $report['blocker'] = $blocker;
        $report['blockers'] = array_values(array_unique(array_merge($report['blockers'] ?? [], [$blocker])));
        if (array_key_exists('stage', $extra)) {
            $report['stage'] = (string) $extra['stage'];
            unset($extra['stage']);
        }

        return array_merge($report, $extra);
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function fingerprintOf(array $result): string
    {
        $fingerprint = $result['plan_fingerprint'] ?? null;

        return is_string($fingerprint) && preg_match('/^[a-f0-9]{64}$/', $fingerprint) === 1
            ? $fingerprint
            : '';
    }

    private static function mapOwnerBlocker($blocker): string
    {
        $code = is_string($blocker) ? $blocker : '';

        if (in_array($code, [
            PersonelAccountOnboardingService::ERR_CANONICAL_USERNAME_COLLISION,
            PersonelAccountOnboardingService::ERR_NAME_CORRECTION_PREIMAGE,
            PersonelAccountOnboardingService::ERR_ROLLOUT_SCOPE_VIOLATION,
            PersonelAccountOnboardingService::ERR_PLAN_FINGERPRINT_MISMATCH,
            PersonelAccountOnboardingService::ERR_ROLLOUT_ALREADY_APPLIED,
        ], true)) {
            return $code;
        }

        return self::BLOCKER_OWNER_BLOCKED;
    }

    private static function boundedCode($value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $value) === 1 ? $value : null;
    }

    /**
     * @param array<string, int> $counts
     */
    private static function usernamesAreUnique(array $counts): bool
    {
        foreach ($counts as $count) {
            if ($count > 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function selectByIds(PDO $pdo, string $sqlTemplate, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(sprintf($sqlTemplate, $placeholders));
        $stmt->execute($ids);

        $rows = [];
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * The cohort population the canonical owner walks: `users.rol = 'PERSONEL'`.
     * An unevaluable count returns -1 so a broken probe fails reconciliation instead of
     * looking like a legitimately empty cohort.
     */
    private static function personelRoleTotal(PDO $pdo): int
    {
        try {
            $statement = $pdo->query("SELECT COUNT(*) FROM users WHERE rol = 'PERSONEL'");
            if ($statement === false) {
                return -1;
            }
            $value = $statement->fetchColumn();
            $statement->closeCursor();

            return $value === false ? -1 : (int) $value;
        } catch (Throwable $exception) {
            return -1;
        }
    }
}
