<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Auth;

use Medisa\Api\Http\JsonResponse;
use Medisa\Api\Http\ResponseCaptured;
use PDO;
use Throwable;

/**
 * Read-only production preflight for the PERSONEL first-login credential rollout.
 *
 * Why this exists as its own owner: the rollout decision belongs to
 * PersonelAccountOnboardingService, and the canonical CLI worker
 * (api/bin/personel-first-login-credentials.php) can only run on the cPanel host
 * because it needs the server-local database. The migration control plane owns
 * the only production database credential reachable from CI, so the same
 * decision has to be reachable through it — but only in a mode that can never
 * write. This owner is that mode's report: it asks the canonical owner for the
 * dry-run plan and reduces it to publishable evidence.
 *
 * The decision owner is never reimplemented here. THIS CLASS MUST NOT DECIDE
 * ANYTHING ABOUT CREDENTIALS: it calls
 * PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials with a
 * literal `apply = false, actor = null`, and the canonical owner returns before
 * any transaction or write on that path. `apply` is never read from the request,
 * so the request carries no field that could ask for a mutation, and this mode
 * has no apply counterpart at all.
 *
 * Read-only is structural, not a promise: the only statement in this file is one
 * COUNT(*) over `users`, plus whatever SELECTs the canonical owner already runs
 * on its own dry-run path. No INSERT, UPDATE, DELETE or DDL exists here.
 *
 * Capture is mandatory, not cosmetic. On the schema gate the canonical owner
 * answers through JsonResponse::error, which calls exit() unless capture is
 * armed — an uncaptured call would kill the cron worker mid-request, leave
 * `request.processing.*` claimed and strand `status.json` on RUNNING. The
 * capture scope therefore mirrors the CLI worker exactly.
 *
 * Output contract: aggregate counts, bounded plan rows and reason codes only.
 * Names never leave this file — the canonical owner returns the correction
 * preimage (ad/soyad) inside its plan, and this reduction deliberately drops it,
 * publishing only whether a correction exists and whether its preimage matched.
 * No plaintext password, password_hash, token, DSN or DB credential is ever
 * placed in the report, so CI can read it back without a leak.
 */
final class PersonelFirstLoginCredentialsPreflightReport
{
    public const SCHEMA_VERSION = '1';

    public const MODE = 'PERSONEL_FIRST_LOGIN_CREDENTIALS_PREFLIGHT';

    /** The canonical owner refused the dry-run; nothing about the cohort is known. */
    public const BLOCKER_DECISION_REFUSED = 'PERSONEL_FIRST_LOGIN_DECISION_REFUSED';
    /** The canonical owner answered with neither a usable plan nor a bounded refusal. */
    public const BLOCKER_DECISION_UNREADABLE = 'PERSONEL_FIRST_LOGIN_DECISION_UNREADABLE';
    /** The cohort cannot be reconciled, so the numbers describe an unknown set. */
    public const BLOCKER_COHORT_RECONCILIATION = 'PERSONEL_FIRST_LOGIN_COHORT_RECONCILIATION_FAILED';
    /** Canonical username collision: the canonical owner refuses every write. */
    public const BLOCKER_CANONICAL_USERNAME_COLLISION = 'PERSONEL_FIRST_LOGIN_CANONICAL_USERNAME_COLLISION';
    /** Name correction preimage drifted on production: the canonical owner refuses every write. */
    public const BLOCKER_NAME_CORRECTION_PREIMAGE = 'PERSONEL_FIRST_LOGIN_NAME_CORRECTION_PREIMAGE_MISMATCH';
    /** Defence in depth: a planned correction whose preimage is not proven to match. */
    public const BLOCKER_NAME_CORRECTION_PREIMAGE_UNVERIFIED = 'PERSONEL_FIRST_LOGIN_NAME_CORRECTION_PREIMAGE_UNVERIFIED';
    /** A reserved account appeared in the mutation plan, which must never happen. */
    public const BLOCKER_PROTECTED_USERNAME_IN_PLAN = 'PERSONEL_FIRST_LOGIN_PROTECTED_USERNAME_IN_PLAN';
    /**
     * The dry-run claimed it wrote, or reported a plan after refusing. Either way
     * the read-only guarantee of this mode is broken and the run is void.
     */
    public const BLOCKER_READ_ONLY_VIOLATION = 'PERSONEL_FIRST_LOGIN_READ_ONLY_VIOLATION';

    /**
     * The exclusion buckets the canonical owner owns, in report order. Kept as a
     * literal so a bucket added to the canonical owner without being added here
     * fails the read instead of silently disappearing from the totals.
     */
    public const EXCLUDED_BUCKETS = [
        'protected_username',
        'user_not_active',
        'binding_missing',
        'bound_personel_missing',
        'bound_personel_not_active',
        'name_unresolved',
    ];

    /**
     * Collect the read-only rollout preflight.
     *
     * @param string $deployedSha exact deploy SHA the collecting worker is pinned to
     * @return array<string, mixed>
     */
    public static function collect(PDO $pdo, string $deployedSha): array
    {
        $captured = null;
        $result = null;
        JsonResponse::beginCapture();
        try {
            // apply = false and actor = null are literals: no request field can widen this.
            $result = PersonelAccountOnboardingService::migrateCanonicalFirstLoginCredentials($pdo, null, false);
            $captured = JsonResponse::capturedResponse();
        } catch (ResponseCaptured $refusal) {
            $captured = JsonResponse::capturedResponse();
        } finally {
            JsonResponse::endCapture();
        }

        // Read independently of the plan so the totals are verified against the
        // database instead of being inferred from the numbers they are meant to check.
        $personelTotal = self::personelRoleTotal($pdo);

        // A refusal is data, not an exception: the schema gate is a legitimate
        // outcome the operator has to see, and only a genuinely broken read (a PDO
        // failure) should fail the worker stage.
        if (is_array($captured)) {
            return self::refusedReport($deployedSha, $personelTotal, $captured);
        }
        if (!is_array($result)) {
            return self::refusedReport($deployedSha, $personelTotal, null);
        }

        return self::reduce($deployedSha, $personelTotal, $result);
    }

    /**
     * The canonical owner refused, or answered with nothing readable. The cohort is
     * unknown, so no plan and no per-bucket count is published: an empty
     * `excluded_counts` would state "nobody was excluded", which is a different
     * claim from "the population was never read".
     *
     * @param array<string, mixed>|null $captured
     * @return array<string, mixed>
     */
    private static function refusedReport(string $deployedSha, int $personelTotal, ?array $captured): array
    {
        // Bounded refusal code only; the owner's human sentence is never echoed.
        $code = $captured['errors'][0]['code'] ?? null;
        $code = is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{2,100}$/D', $code) === 1 ? $code : null;

        // No target and no excluded rows are published, so the reconciliation below
        // only holds for a database that has no PERSONEL role rows at all.
        $blockers = [$code === null ? self::BLOCKER_DECISION_UNREADABLE : self::BLOCKER_DECISION_REFUSED];

        return self::envelope($deployedSha, $personelTotal, 0, 0, $blockers) + [
            'excluded_counts' => null,
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
            'decision_apply' => false,
            'decision_applied_count' => 0,
            'plan_fingerprint' => null,
            'owner_blocked' => false,
            'owner_blocker' => null,
            'refusal_code' => $code,
            'plan' => [],
        ];
    }

    /**
     * @param array<string, mixed> $result the canonical owner's dry-run result
     * @return array<string, mixed>
     */
    private static function reduce(string $deployedSha, int $personelTotal, array $result): array
    {
        $blockers = [];

        $decisionApply = ($result['apply'] ?? null) === true;
        $appliedCount = (int) ($result['applied_count'] ?? 0);
        // A dry-run that claims to have applied is a broken read-only guarantee.
        if ($decisionApply || $appliedCount !== 0) {
            $blockers[] = self::BLOCKER_READ_ONLY_VIOLATION;
        }

        $planRows = is_array($result['plan'] ?? null) ? $result['plan'] : [];
        $excluded = is_array($result['excluded'] ?? null) ? $result['excluded'] : [];

        $excludedCounts = [];
        foreach (self::EXCLUDED_BUCKETS as $bucket) {
            if (!array_key_exists($bucket, $excluded)) {
                // The canonical owner no longer reports a bucket this report owns.
                $blockers[] = self::BLOCKER_DECISION_UNREADABLE;
                $excludedCounts[$bucket] = 0;
                continue;
            }
            $excludedCounts[$bucket] = is_array($excluded[$bucket]) ? count($excluded[$bucket]) : 0;
        }

        $protected = [];
        foreach (PersonelAccountOnboardingService::PROTECTED_USERNAMES as $reserved) {
            $protected[strtolower((string) $reserved)] = true;
        }

        $plan = [];
        $nameCorrectionCount = 0;
        $nameCorrectionMismatchCount = 0;
        $businessOverrideCount = 0;
        $changedUsernameCount = 0;
        $protectedUsernameInPlanCount = 0;
        $ilkeraTouched = false;

        foreach ($planRows as $row) {
            if (!is_array($row)) {
                $blockers[] = self::BLOCKER_DECISION_UNREADABLE;
                continue;
            }
            $oldUsername = (string) ($row['old_username'] ?? '');
            $newUsername = (string) ($row['new_username'] ?? '');
            $correction = is_array($row['name_correction'] ?? null) ? $row['name_correction'] : null;
            $preimageMatch = $correction === null ? null : (($correction['preimage_match'] ?? false) === true);

            $usernameChanged = ($row['username_changed'] ?? false) === true;
            $businessOverride = ($row['business_override'] ?? false) === true;

            if (isset($protected[strtolower($oldUsername)]) || isset($protected[strtolower($newUsername)])) {
                ++$protectedUsernameInPlanCount;
                $ilkeraTouched = true;
            }
            if ($correction !== null) {
                ++$nameCorrectionCount;
                if ($preimageMatch !== true) {
                    ++$nameCorrectionMismatchCount;
                }
            }
            if ($businessOverride) {
                ++$businessOverrideCount;
            }
            if ($usernameChanged) {
                ++$changedUsernameCount;
            }

            // Bounded row: the correction's identity preimage (ad/soyad) is dropped here.
            $plan[] = [
                'user_id' => (int) ($row['user_id'] ?? 0),
                'personel_id' => (int) ($row['personel_id'] ?? 0),
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

        // Only the canonical owner may declare the cohort blocked, and it reports
        // its own reason code; this reduction may not invent a different one.
        $ownerBlocked = ($result['blocked'] ?? false) === true;
        $ownerBlocker = is_string($result['blocker'] ?? null) ? $result['blocker'] : null;
        if ($ownerBlocked) {
            if ($ownerBlocker === PersonelAccountOnboardingService::ERR_CANONICAL_USERNAME_COLLISION) {
                $blockers[] = self::BLOCKER_CANONICAL_USERNAME_COLLISION;
            } elseif ($ownerBlocker === PersonelAccountOnboardingService::ERR_NAME_CORRECTION_PREIMAGE) {
                $blockers[] = self::BLOCKER_NAME_CORRECTION_PREIMAGE;
            } else {
                $blockers[] = self::BLOCKER_DECISION_REFUSED;
            }
        }

        if ($nameCorrectionMismatchCount > 0) {
            $blockers[] = self::BLOCKER_NAME_CORRECTION_PREIMAGE_UNVERIFIED;
        }
        if ($protectedUsernameInPlanCount > 0) {
            $blockers[] = self::BLOCKER_PROTECTED_USERNAME_IN_PLAN;
        }

        $excludedTotal = array_sum($excludedCounts);

        return self::envelope($deployedSha, $personelTotal, count($plan), $excludedTotal, $blockers) + [
            'excluded_counts' => $excludedCounts,
            'collision_count' => count($collisions),
            'collisions' => $collisions,
            'name_unresolved_count' => (int) ($excludedCounts['name_unresolved'] ?? 0),
            'name_correction_count' => $nameCorrectionCount,
            'name_correction_preimage_mismatch_count' => $nameCorrectionMismatchCount,
            'business_override_count' => $businessOverrideCount,
            'username_changed_count' => $changedUsernameCount,
            'protected_username_excluded_count' => (int) ($excludedCounts['protected_username'] ?? 0),
            'protected_username_in_plan_count' => $protectedUsernameInPlanCount,
            'ilkera_touched' => $ilkeraTouched,
            'decision_apply' => $decisionApply,
            'decision_applied_count' => $appliedCount,
            // Apply yolu bu exact plana pinlenir; deger canonical sahipten gelir ve
            // secret-free'dir (yalniz sha256 hex).
            'plan_fingerprint' => is_string($result['plan_fingerprint'] ?? null)
                ? (string) $result['plan_fingerprint']
                : null,
            'owner_blocked' => $ownerBlocked,
            'owner_blocker' => $ownerBlocker,
            'refusal_code' => null,
            'plan' => $plan,
        ];
    }

    /**
     * The fields every report carries, whatever the outcome.
     *
     * Every rol = 'PERSONEL' row lands in exactly one exclusion bucket or in the
     * plan, so the two sides must add up to the population the independent
     * COUNT(*) read. An unevaluable or disagreeing total means the numbers
     * describe an unknown cohort, which is a blocker rather than a smaller cohort.
     *
     * @param list<string> $blockers
     * @return array<string, mixed>
     */
    private static function envelope(
        string $deployedSha,
        int $personelTotal,
        int $targetCount,
        int $excludedTotal,
        array $blockers
    ): array {
        $reconciled = $personelTotal >= 0 && ($targetCount + $excludedTotal) === $personelTotal;
        if (!$reconciled) {
            $blockers[] = self::BLOCKER_COHORT_RECONCILIATION;
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'deployed_sha' => strtolower($deployedSha),
            'mode' => self::MODE,
            'production_mutation_count' => 0,
            'personel_total' => $personelTotal,
            'target_count' => $targetCount,
            'excluded_total' => $excludedTotal,
            'cohort_reconciled' => $reconciled,
            'protected_usernames' => array_values(PersonelAccountOnboardingService::PROTECTED_USERNAMES),
            'blockers' => $blockers,
            'result' => $blockers === [] ? 'PASS' : 'BLOCKED',
        ];
    }

    /**
     * The cohort population the canonical owner walks: `users.rol = 'PERSONEL'`.
     *
     * An unevaluable count returns -1 so a broken probe fails reconciliation
     * instead of looking like a legitimately empty cohort.
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
