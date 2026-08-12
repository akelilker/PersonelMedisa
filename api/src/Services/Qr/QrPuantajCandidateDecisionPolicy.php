<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Qr;

/**
 * Review capability overlay for QR_PUANTAJ_DECISION_V1 (S3F).
 * Pure policy — no SQL / Auth / HTTP.
 */
class QrPuantajCandidateDecisionPolicy
{
    public const DECISION_ALGORITHM_VERSION = 'QR_PUANTAJ_DECISION_V1';

    public const ACTION_APPLY_EXISTING = 'APPLY_EXISTING';
    public const ACTION_KEEP_CANONICAL = 'KEEP_CANONICAL';
    public const ACTION_REOPEN_REVIEW = 'REOPEN_REVIEW';

    public const REVIEW_UNREVIEWED = 'UNREVIEWED';
    public const REVIEW_CANONICAL_KEPT = 'CANONICAL_KEPT';
    public const REVIEW_REVIEW_REOPENED = 'REVIEW_REOPENED';
    public const REVIEW_NO_ACTION_REQUIRED = 'NO_ACTION_REQUIRED';
    public const REVIEW_BLOCKED = 'BLOCKED';
    public const REVIEW_REVISION_REQUIRED = 'REVISION_REQUIRED';
    public const REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    public const REVIEW_MANUAL_CREATE_REQUIRED = 'MANUAL_PUANTAJ_CREATE_REVIEW_REQUIRED';

    public const BLOCK_STALE = 'QR_CANDIDATE_STALE';
    public const BLOCK_NO_ROW = 'QR_APPLY_REQUIRES_EXISTING_PUANTAJ_ROW';
    public const BLOCK_DEPENDENT_FIELDS = 'QR_APPLY_DEPENDENT_FIELDS_REQUIRE_MANUAL_REVIEW';
    public const BLOCK_APPROVED_CORRECTION = 'APPROVED_CORRECTION_PRESENT';
    public const BLOCK_ACTIVE_SNAPSHOT = 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED';
    public const BLOCK_PERIOD_LOCKED = 'PERIOD_LOCKED';
    public const BLOCK_KEEP_ACTIVE = 'KEEP_CANONICAL_ACTIVE';
    public const BLOCK_IDEMPOTENCY = 'IDEMPOTENCY_CONFLICT';
    public const BLOCK_DECISION_CONFLICT = 'QR_DECISION_CONFLICT';

    /** @var list<string> */
    public static $dependentGuardFields = [
        'gec_kalma_dakika',
        'erken_cikis_dakika',
        'gercek_mola_dakika',
        'hesaplanan_mola_dakika',
        'net_calisma_suresi_dakika',
        'gunluk_brut_sure_dakika',
        'tatil_donemi_brut_calisma_dakika',
        'tatil_donemi_ara_dinlenme_dakika',
        'tatil_donemi_net_calisma_dakika',
    ];

    public static function isKnownAction($action)
    {
        $action = strtoupper(trim((string) $action));

        return $action === self::ACTION_APPLY_EXISTING
            || $action === self::ACTION_KEEP_CANONICAL
            || $action === self::ACTION_REOPEN_REVIEW;
    }

    /**
     * @param array<string,mixed> $item Candidate item (with optional candidate_hash)
     * @param array<string,mixed>|null $latestDecision Same candidate_hash latest ledger row
     * @return array<string,mixed>
     */
    public static function buildReviewOverlay(array $item, $latestDecision = null)
    {
        $comparison = (string) ($item['comparison_status'] ?? '');
        $classification = (string) ($item['classification'] ?? '');
        $period = is_array($item['period'] ?? null) ? $item['period'] : [];
        $canonical = is_array($item['canonical'] ?? null) ? $item['canonical'] : null;
        $proposed = is_array($item['proposed'] ?? null) ? $item['proposed'] : [];
        $writeOpen = !empty($period['canonical_write_open']);
        $blockCode = isset($period['canonical_write_block_code']) && $period['canonical_write_block_code'] !== null
            ? (string) $period['canonical_write_block_code']
            : null;

        $latestType = null;
        $latestId = null;
        $decidedAt = null;
        if (is_array($latestDecision)) {
            $latestType = strtoupper(trim((string) ($latestDecision['decision_type'] ?? '')));
            $latestId = isset($latestDecision['id']) ? (int) $latestDecision['id'] : null;
            $decidedAt = isset($latestDecision['created_at']) ? (string) $latestDecision['created_at'] : null;
        }

        $keptActive = $latestType === self::ACTION_KEEP_CANONICAL;
        $reopened = $latestType === self::ACTION_REOPEN_REVIEW;

        $state = self::REVIEW_UNREVIEWED;
        $actionRequired = true;
        $canApply = false;
        $canKeep = false;
        $canReopen = false;
        $blockingCode = null;

        if ($comparison === QrPuantajCandidateProjectionService::COMPARE_MATCHES_CANONICAL_TIME) {
            $state = self::REVIEW_NO_ACTION_REQUIRED;
            $actionRequired = false;
        } elseif ($comparison === QrPuantajCandidateProjectionService::COMPARE_APPROVED_CORRECTION_PRESENT) {
            $state = self::REVIEW_BLOCKED;
            $actionRequired = false;
            $blockingCode = self::BLOCK_APPROVED_CORRECTION;
        } elseif ($comparison === QrPuantajCandidateProjectionService::COMPARE_NO_CANONICAL_ROW) {
            $state = self::REVIEW_MANUAL_CREATE_REQUIRED;
            $actionRequired = true;
            $blockingCode = self::BLOCK_NO_ROW;
        } elseif (
            $classification === QrPuantajCandidateProjectionService::CLASS_REVIEW_MULTIPLE_INTERVALS
            || $classification === QrPuantajCandidateProjectionService::CLASS_REVIEW_CROSS_MIDNIGHT
            || $classification === QrPuantajCandidateProjectionService::CLASS_REVIEW_ANOMALY
            || $classification === QrPuantajCandidateProjectionService::CLASS_REVIEW_MULTIPLE_BRANCHES
            || $comparison === QrPuantajCandidateProjectionService::COMPARE_NO_SAFE_TIME_PROPOSAL
        ) {
            $state = self::REVIEW_REQUIRED;
            $actionRequired = true;
        } elseif ($comparison === QrPuantajCandidateProjectionService::COMPARE_PERIOD_REQUIRES_REVISION) {
            $state = self::REVIEW_REVISION_REQUIRED;
            $actionRequired = true;
            $blockingCode = $blockCode ?: self::BLOCK_PERIOD_LOCKED;
            $canKeep = self::isKeepStructurallyAllowed($item);
        } elseif ($blockCode === self::BLOCK_ACTIVE_SNAPSHOT) {
            $state = self::REVIEW_BLOCKED;
            $actionRequired = true;
            $blockingCode = self::BLOCK_ACTIVE_SNAPSHOT;
            $canKeep = self::isKeepStructurallyAllowed($item);
        } else {
            $canKeep = self::isKeepStructurallyAllowed($item);
            $canApply = self::isApplyStructurallyAllowed($item);
            $dependentBlocked = false;
            if ($canApply) {
                $guard = self::evaluateDependentFieldGuard(self::canonicalMapAsGuardRow($canonical));
                if (!$guard['ok']) {
                    $canApply = false;
                    $dependentBlocked = true;
                }
            }
            if ($keptActive) {
                $state = self::REVIEW_CANONICAL_KEPT;
                $actionRequired = false;
                $canApply = false;
                $canKeep = false;
                $canReopen = true;
                $blockingCode = self::BLOCK_KEEP_ACTIVE;
            } elseif ($reopened) {
                $state = self::REVIEW_REVIEW_REOPENED;
                $actionRequired = true;
                if ($dependentBlocked) {
                    $blockingCode = self::BLOCK_DEPENDENT_FIELDS;
                }
            } else {
                $state = self::REVIEW_UNREVIEWED;
                $actionRequired = $canApply || $canKeep;
                if ($dependentBlocked) {
                    $blockingCode = self::BLOCK_DEPENDENT_FIELDS;
                }
            }
        }

        if ($keptActive && $state !== self::REVIEW_CANONICAL_KEPT) {
            // KEEP only binds same candidate_hash; caller passes latest for current hash only.
        }

        return [
            'state' => $state,
            'latest_decision_id' => $latestId,
            'latest_decision_type' => $latestType,
            'decided_at' => $decidedAt,
            'can_apply' => $canApply,
            'can_keep_canonical' => $canKeep,
            'can_reopen_review' => $canReopen,
            'action_required' => $actionRequired,
            'blocking_code' => $blockingCode,
            'decision_algorithm_version' => self::DECISION_ALGORITHM_VERSION,
        ];
    }

    /**
     * Structural APPLY gates (excluding dependent-field guard / hash / permission).
     *
     * @param array<string,mixed> $item
     */
    public static function isApplyStructurallyAllowed(array $item)
    {
        $classification = (string) ($item['classification'] ?? '');
        $comparison = (string) ($item['comparison_status'] ?? '');
        $period = is_array($item['period'] ?? null) ? $item['period'] : [];
        $canonical = is_array($item['canonical'] ?? null) ? $item['canonical'] : null;
        $proposed = is_array($item['proposed'] ?? null) ? $item['proposed'] : [];

        if ($classification !== QrPuantajCandidateProjectionService::CLASS_READY_SINGLE_INTERVAL) {
            return false;
        }
        if ($comparison !== QrPuantajCandidateProjectionService::COMPARE_DIFFERS_CANONICAL_TIME) {
            return false;
        }
        if (!self::canonicalRowExists($canonical)) {
            return false;
        }
        if (($proposed['giris_saati'] ?? null) === null || ($proposed['cikis_saati'] ?? null) === null) {
            return false;
        }
        if (empty($period['canonical_write_open'])) {
            return false;
        }
        if (($period['canonical_write_block_code'] ?? null) !== null && $period['canonical_write_block_code'] !== '') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed>|null $canonical
     */
    public static function canonicalRowExists($canonical)
    {
        if (!is_array($canonical)) {
            return false;
        }
        if (array_key_exists('exists', $canonical) && empty($canonical['exists'])) {
            return false;
        }
        $id = $canonical['puantaj_id'] ?? ($canonical['id'] ?? null);

        return $id !== null && (int) $id > 0;
    }

    /**
     * KEEP: existing row + ready single + safe proposed + differs; period may be locked.
     *
     * @param array<string,mixed> $item
     */
    public static function isKeepStructurallyAllowed(array $item)
    {
        $classification = (string) ($item['classification'] ?? '');
        $comparison = (string) ($item['comparison_status'] ?? '');
        $canonical = is_array($item['canonical'] ?? null) ? $item['canonical'] : null;
        $proposed = is_array($item['proposed'] ?? null) ? $item['proposed'] : [];

        if ($classification !== QrPuantajCandidateProjectionService::CLASS_READY_SINGLE_INTERVAL) {
            return false;
        }
        if (!self::canonicalRowExists($canonical)) {
            return false;
        }
        if (($proposed['giris_saati'] ?? null) === null || ($proposed['cikis_saati'] ?? null) === null) {
            return false;
        }
        if ($comparison === QrPuantajCandidateProjectionService::COMPARE_APPROVED_CORRECTION_PRESENT) {
            return false;
        }
        if ($comparison === QrPuantajCandidateProjectionService::COMPARE_MATCHES_CANONICAL_TIME) {
            return false;
        }
        if ($comparison === QrPuantajCandidateProjectionService::COMPARE_NO_CANONICAL_ROW) {
            return false;
        }
        if ($comparison === QrPuantajCandidateProjectionService::COMPARE_NO_SAFE_TIME_PROPOSAL) {
            return false;
        }

        // DIFFERS or PERIOD_REQUIRES_REVISION (sealed/reopen-pending safe diff)
        return $comparison === QrPuantajCandidateProjectionService::COMPARE_DIFFERS_CANONICAL_TIME
            || $comparison === QrPuantajCandidateProjectionService::COMPARE_PERIOD_REQUIRES_REVISION;
    }

    /**
     * Map candidate.canonical projection fields into a row shape for dependent guard.
     *
     * @param array<string,mixed>|null $canonical
     * @return array<string,mixed>
     */
    public static function canonicalMapAsGuardRow($canonical)
    {
        $row = [];
        if (!is_array($canonical)) {
            return $row;
        }
        foreach (self::$dependentGuardFields as $field) {
            if (array_key_exists($field, $canonical)) {
                $row[$field] = $canonical[$field];
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $puantajRow
     * @return array{ok:bool,populated:list<string>}
     */
    public static function evaluateDependentFieldGuard(array $puantajRow)
    {
        $populated = [];
        foreach (self::$dependentGuardFields as $field) {
            if (!array_key_exists($field, $puantajRow)) {
                continue;
            }
            $value = $puantajRow[$field];
            if ($value === null || $value === '') {
                continue;
            }
            $populated[] = $field;
        }

        return [
            'ok' => count($populated) === 0,
            'populated' => $populated,
        ];
    }
}
