<?php

declare(strict_types=1);

/**
 * S3F: pure (no DB) QR puantaj candidate decision — hash + policy + dependent guard.
 * php tests/php/S3FQrPuantajDecisionPureTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionLedgerService;
use Medisa\Api\Services\Qr\QrPuantajCandidateDecisionPolicy;
use Medisa\Api\Services\Qr\QrPuantajCandidateHashService;
use Medisa\Api\Services\Qr\QrPuantajCandidateProjectionService;

function s3fPureAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function s3fPureBaseItem(array $overrides = [])
{
    $item = [
        'candidate_date' => '2026-08-12',
        'classification' => QrPuantajCandidateProjectionService::CLASS_READY_SINGLE_INTERVAL,
        'comparison_status' => QrPuantajCandidateProjectionService::COMPARE_DIFFERS_CANONICAL_TIME,
        'proposed' => [
            'giris_saati' => '08:00',
            'cikis_saati' => '17:00',
        ],
        'canonical' => [
            'exists' => true,
            'puantaj_id' => 42,
            'giris_saati' => '09:00',
            'cikis_saati' => '18:00',
            'state' => 'ACIK',
            'kontrol_durumu' => 'BEKLIYOR',
            'updated_at' => '2026-08-12 10:00:00',
        ],
        'period' => [
            'state' => 'ACIK',
            'canonical_write_open' => true,
            'canonical_write_block_code' => null,
        ],
        'provenance' => [
            'algorithm_version' => QrPuantajCandidateProjectionService::ALGORITHM_VERSION,
            'interval_algorithm_version' => QrPuantajCandidateProjectionService::INTERVAL_ALGORITHM_VERSION,
            'source_event_ids' => [10, 11],
            'source_max_event_id' => 11,
            'source_interval_count' => 1,
            'source_anomaly_count' => 0,
            'qr_matched_seconds' => 32400,
        ],
        'ui_label' => 'cosmetic-A',
        'display_hint' => 'ignore-me',
    ];

    foreach ($overrides as $key => $value) {
        if (is_array($value) && isset($item[$key]) && is_array($item[$key])) {
            $item[$key] = array_merge($item[$key], $value);
        } else {
            $item[$key] = $value;
        }
    }

    return $item;
}

$personelId = 7;
$base = s3fPureBaseItem();
$hash1 = QrPuantajCandidateHashService::compute($personelId, $base);
$hash1b = QrPuantajCandidateHashService::compute($personelId, $base);
s3fPureAssert($hash1 === $hash1b && strlen($hash1) === 64, 'candidate hash stable for same material');

$srcChanged = s3fPureBaseItem([
    'provenance' => [
        'source_event_ids' => [10, 12],
        'source_max_event_id' => 12,
    ],
]);
s3fPureAssert(
    QrPuantajCandidateHashService::compute($personelId, $srcChanged) !== $hash1,
    'source event change → different hash'
);

$updatedAtChanged = s3fPureBaseItem([
    'canonical' => ['updated_at' => '2026-08-12 11:00:00'],
]);
s3fPureAssert(
    QrPuantajCandidateHashService::compute($personelId, $updatedAtChanged) !== $hash1,
    'canonical updated_at change → different hash'
);

$periodChanged = s3fPureBaseItem([
    'period' => [
        'state' => 'SEALED',
        'canonical_write_open' => false,
        'canonical_write_block_code' => 'PERIOD_LOCKED',
    ],
]);
s3fPureAssert(
    QrPuantajCandidateHashService::compute($personelId, $periodChanged) !== $hash1,
    'period block change → different hash'
);

$cosmetic = s3fPureBaseItem([
    'ui_label' => 'cosmetic-B',
    'display_hint' => 'still-ignored',
]);
s3fPureAssert(
    QrPuantajCandidateHashService::compute($personelId, $cosmetic) === $hash1,
    'cosmetic label change → same hash'
);

$ledgerRow = [
    'candidate_hash' => $hash1,
    'decision_type' => QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL,
    'decision_reason' => 'Mevcut saatler korunacak.',
    'decided_by_user_id' => 10,
    'personel_id' => $personelId,
    'sube_id' => 1,
    'candidate_date' => '2026-08-12',
    'puantaj_id' => 42,
    'algorithm_version' => QrPuantajCandidateProjectionService::ALGORITHM_VERSION,
    'interval_algorithm_version' => QrPuantajCandidateProjectionService::INTERVAL_ALGORITHM_VERSION,
    'decision_algorithm_version' => QrPuantajCandidateDecisionPolicy::DECISION_ALGORITHM_VERSION,
    'candidate_snapshot' => ['candidate_hash' => $hash1, 'candidate_date' => '2026-08-12'],
    'before_puantaj_snapshot' => ['id' => 42, 'giris_saati' => '09:00'],
    'after_puantaj_snapshot' => null,
    'request_nonce' => 'a0000000-0000-4000-8000-000000000001',
    'supersedes_decision_id' => null,
    'previous_decision_hash' => null,
    'created_at' => '2026-08-12 12:00:00.000000',
];
$computedDecisionHash = QrPuantajCandidateDecisionLedgerService::computeDecisionHash($ledgerRow);
$ledgerRow['decision_hash'] = $computedDecisionHash;
s3fPureAssert(strlen($computedDecisionHash) === 64, 'decision hash length 64');
s3fPureAssert(
    QrPuantajCandidateDecisionLedgerService::verifyDecisionHash($ledgerRow),
    'decision hash recompute helper verify OK'
);
$tampered = $ledgerRow;
$tampered['decision_reason'] = 'tampered';
s3fPureAssert(
    !QrPuantajCandidateDecisionLedgerService::verifyDecisionHash($tampered),
    'tampered decision fails verify'
);

$applyEligible = s3fPureBaseItem();
$overlayOpen = QrPuantajCandidateDecisionPolicy::buildReviewOverlay($applyEligible, null);
s3fPureAssert($overlayOpen['state'] === QrPuantajCandidateDecisionPolicy::REVIEW_UNREVIEWED, 'OPEN overlay UNREVIEWED');
s3fPureAssert(!empty($overlayOpen['can_apply']), 'OPEN can_apply');
s3fPureAssert(!empty($overlayOpen['can_keep_canonical']), 'OPEN can_keep');
s3fPureAssert(empty($overlayOpen['can_reopen_review']), 'OPEN cannot reopen');

$keepDecision = [
    'id' => 1,
    'decision_type' => QrPuantajCandidateDecisionPolicy::ACTION_KEEP_CANONICAL,
    'created_at' => '2026-08-12 12:00:00.000000',
];
$overlayKept = QrPuantajCandidateDecisionPolicy::buildReviewOverlay($applyEligible, $keepDecision);
s3fPureAssert(
    $overlayKept['state'] === QrPuantajCandidateDecisionPolicy::REVIEW_CANONICAL_KEPT,
    'KEEP overlay CANONICAL_KEPT'
);
s3fPureAssert(empty($overlayKept['can_apply']), 'KEEP blocks apply');
s3fPureAssert(empty($overlayKept['can_keep_canonical']), 'KEEP blocks re-keep');
s3fPureAssert(!empty($overlayKept['can_reopen_review']), 'KEEP allows reopen');
s3fPureAssert(
    ($overlayKept['blocking_code'] ?? '') === QrPuantajCandidateDecisionPolicy::BLOCK_KEEP_ACTIVE,
    'KEEP blocking KEEP_CANONICAL_ACTIVE'
);

$reopenDecision = [
    'id' => 2,
    'decision_type' => QrPuantajCandidateDecisionPolicy::ACTION_REOPEN_REVIEW,
    'created_at' => '2026-08-12 12:05:00.000000',
];
$overlayReopened = QrPuantajCandidateDecisionPolicy::buildReviewOverlay($applyEligible, $reopenDecision);
s3fPureAssert(
    $overlayReopened['state'] === QrPuantajCandidateDecisionPolicy::REVIEW_REVIEW_REOPENED,
    'REOPEN overlay REVIEW_REOPENED'
);
s3fPureAssert(!empty($overlayReopened['can_apply']), 'REOPEN restores can_apply');
s3fPureAssert(!empty($overlayReopened['can_keep_canonical']), 'REOPEN restores can_keep');

$sealedItem = s3fPureBaseItem([
    'comparison_status' => QrPuantajCandidateProjectionService::COMPARE_PERIOD_REQUIRES_REVISION,
    'period' => [
        'state' => 'SEALED',
        'canonical_write_open' => false,
        'canonical_write_block_code' => 'PERIOD_LOCKED',
    ],
]);
$overlaySealed = QrPuantajCandidateDecisionPolicy::buildReviewOverlay($sealedItem, null);
s3fPureAssert(
    $overlaySealed['state'] === QrPuantajCandidateDecisionPolicy::REVIEW_REVISION_REQUIRED,
    'SEALED overlay REVISION_REQUIRED'
);
s3fPureAssert(empty($overlaySealed['can_apply']), 'SEALED blocks apply');
s3fPureAssert(!empty($overlaySealed['can_keep_canonical']), 'SEALED allows KEEP');

$guardOk = QrPuantajCandidateDecisionPolicy::evaluateDependentFieldGuard([
    'gec_kalma_dakika' => null,
    'erken_cikis_dakika' => '',
    'net_calisma_suresi_dakika' => null,
]);
s3fPureAssert($guardOk['ok'] === true && count($guardOk['populated']) === 0, 'dependent guard empty OK');

$guardBlocked = QrPuantajCandidateDecisionPolicy::evaluateDependentFieldGuard([
    'gec_kalma_dakika' => 5,
    'erken_cikis_dakika' => null,
    'net_calisma_suresi_dakika' => 480,
]);
s3fPureAssert($guardBlocked['ok'] === false, 'dependent guard populated not OK');
s3fPureAssert(
    in_array('gec_kalma_dakika', $guardBlocked['populated'], true)
        && in_array('net_calisma_suresi_dakika', $guardBlocked['populated'], true),
    'dependent guard lists populated fields'
);

echo 'S3F pure decision tests OK' . PHP_EOL;
