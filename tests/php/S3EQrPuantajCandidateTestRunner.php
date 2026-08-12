<?php

declare(strict_types=1);

/**
 * S3E: pure QR_PUANTAJ_CANDIDATE_V1 projection contract tests (no DB).
 * php tests/php/S3EQrPuantajCandidateTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceIntervalDerivationService;
use Medisa\Api\Services\Qr\QrPuantajCandidateProjectionService;

function s3eAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3eEvt($id, $type, $utc, $subeId = 1): array
{
    return [
        'id' => $id,
        'event_type' => $type,
        'occurred_at_utc' => $utc,
        'sube_id' => $subeId,
        'user_id' => 10,
        'sube_ad' => 'Sube' . $subeId,
    ];
}

/** @return array<string,mixed> */
function s3ePeriod($state, $writeLocked, $writeOpen, $blockCode = null): array
{
    return [
        'state' => $state,
        'period_write_locked' => $writeLocked,
        'canonical_write_open' => $writeOpen,
        'canonical_write_block_code' => $blockCode,
    ];
}

function s3eProject(array $events, $from, $to, array $canonicalByDate = [], array $periodByDate = [], array $corrections = [])
{
    $derived = QrAttendanceIntervalDerivationService::derive($events);

    return QrPuantajCandidateProjectionService::buildDailyItems(
        $derived,
        $from,
        $to,
        $canonicalByDate,
        $periodByDate,
        $corrections
    );
}

function s3eItem(array $items, $date)
{
    foreach ($items as $item) {
        if (($item['candidate_date'] ?? '') === $date) {
            return $item;
        }
    }

    return null;
}

s3eAssert(
    QrPuantajCandidateProjectionService::ALGORITHM_VERSION === 'QR_PUANTAJ_CANDIDATE_V1',
    'algorithm version QR_PUANTAJ_CANDIDATE_V1'
);

// Empty QR history → no synthetic day rows
$empty = s3eProject([], '2026-08-01', '2026-08-31');
s3eAssert(count($empty) === 0, 'no QR evidence → items empty');

// Single clean interval 08:00–17:00 Istanbul (05:00–14:00 UTC)
$single = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12'
);
s3eAssert(count($single) === 1, 'single interval one candidate day');
$singleItem = s3eItem($single, '2026-08-12');
s3eAssert($singleItem !== null, 'single item exists');
s3eAssert(
    $singleItem['classification'] === QrPuantajCandidateProjectionService::CLASS_READY_SINGLE_INTERVAL,
    'READY_SINGLE_INTERVAL'
);
s3eAssert($singleItem['proposed']['giris_saati'] === '08:00', 'proposed giris 08:00');
s3eAssert($singleItem['proposed']['cikis_saati'] === '17:00', 'proposed cikis 17:00');
s3eAssert($singleItem['qr']['matched_seconds'] === 9 * 3600, 'matched 9h interval duration');
s3eAssert($singleItem['auto_applicable'] === true, 'structurally auto applicable');
s3eAssert(
    $singleItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_NO_CANONICAL_ROW,
    'NO_CANONICAL_ROW'
);

// Multiple intervals same day — do not collapse to single canonical span
$multi = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 09:00:00.000000'),
        s3eEvt(3, 'GIRIS', '2026-08-12 10:00:00.000000'),
        s3eEvt(4, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12'
);
$multiItem = s3eItem($multi, '2026-08-12');
s3eAssert(
    $multiItem['classification'] === QrPuantajCandidateProjectionService::CLASS_REVIEW_MULTIPLE_INTERVALS,
    'REVIEW_MULTIPLE_INTERVALS'
);
s3eAssert($multiItem['qr']['interval_count'] === 2, 'interval_count=2');
s3eAssert($multiItem['qr']['matched_seconds'] === 8 * 3600, 'qr_matched 8h');
s3eAssert($multiItem['proposed']['giris_saati'] === null, 'no proposed giris for multi');
s3eAssert($multiItem['proposed']['cikis_saati'] === null, 'no proposed cikis for multi');
s3eAssert($multiItem['auto_applicable'] === false, 'multi not auto applicable');
s3eAssert(
    $multiItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_NO_SAFE_TIME_PROPOSAL,
    'NO_SAFE_TIME_PROPOSAL for multi'
);

// Cross-midnight 23:00–07:00
$xnight = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-11 20:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 04:00:00.000000'),
    ],
    '2026-08-11',
    '2026-08-12'
);
$xItem = s3eItem($xnight, '2026-08-11');
s3eAssert(
    $xItem['classification'] === QrPuantajCandidateProjectionService::CLASS_REVIEW_CROSS_MIDNIGHT,
    'REVIEW_CROSS_MIDNIGHT'
);
s3eAssert($xItem['qr']['matched_seconds'] === 8 * 3600, 'cross-midnight matched 8h');
s3eAssert($xItem['auto_applicable'] === false, 'cross-midnight not auto applicable');

// Anomaly day — single GIRIS
$anomaly = s3eProject(
    [s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000')],
    '2026-08-12',
    '2026-08-12'
);
$aItem = s3eItem($anomaly, '2026-08-12');
s3eAssert(
    $aItem['classification'] === QrPuantajCandidateProjectionService::CLASS_REVIEW_ANOMALY,
    'REVIEW_ANOMALY single GIRIS'
);
s3eAssert($aItem['proposed']['giris_saati'] === null, 'anomaly no proposed giris');
s3eAssert($aItem['auto_applicable'] === false, 'anomaly not auto applicable');

// Canonical match (open period)
$canonicalMatch = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [
        '2026-08-12' => [
            'id' => 99,
            'giris_saati' => '08:00:00',
            'cikis_saati' => '17:00:00',
            'state' => 'ACIK',
            'kontrol_durumu' => 'BEKLIYOR',
        ],
    ],
    ['2026-08-12' => s3ePeriod('ACIK', false, true)]
);
$mItem = s3eItem($canonicalMatch, '2026-08-12');
s3eAssert(
    $mItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_MATCHES_CANONICAL_TIME,
    'MATCHES_CANONICAL_TIME'
);

// Canonical diff (open period)
$canonicalDiff = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:07:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:02:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [
        '2026-08-12' => [
            'id' => 100,
            'giris_saati' => '08:00',
            'cikis_saati' => '17:00',
            'state' => 'ACIK',
            'kontrol_durumu' => 'BEKLIYOR',
        ],
    ],
    ['2026-08-12' => s3ePeriod('ACIK', false, true)]
);
$dItem = s3eItem($canonicalDiff, '2026-08-12');
s3eAssert(
    $dItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_DIFFERS_CANONICAL_TIME,
    'DIFFERS_CANONICAL_TIME open period'
);
s3eAssert($dItem['auto_applicable'] === false, 'diff blocks auto apply');
s3eAssert($dItem['period']['revision_required'] === false, 'open diff revision_required NO');
s3eAssert($dItem['period']['future_action'] === 'DIRECT_PUANTAJ_REVIEW', 'open diff future_action');

// SEALED + diff
$sealedDiff = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:07:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:02:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [
        '2026-08-12' => [
            'id' => 101,
            'giris_saati' => '08:00',
            'cikis_saati' => '17:00',
            'state' => 'MUHURLENDI',
            'kontrol_durumu' => 'BEKLIYOR',
        ],
    ],
    ['2026-08-12' => s3ePeriod('SEALED', true, false, 'PERIOD_LOCKED')]
);
$sDiffItem = s3eItem($sealedDiff, '2026-08-12');
s3eAssert(
    $sDiffItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_PERIOD_REQUIRES_REVISION,
    'SEALED diff PERIOD_REQUIRES_REVISION'
);
s3eAssert($sDiffItem['period']['revision_required'] === true, 'SEALED diff revision_required YES');
s3eAssert($sDiffItem['period']['future_action'] === null, 'SEALED diff future_action null');
s3eAssert($sDiffItem['period']['revision_hint'] !== null, 'SEALED diff revision_hint set');

// SEALED + match — critical regression
$sealedMatch = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [
        '2026-08-12' => [
            'id' => 102,
            'giris_saati' => '08:00',
            'cikis_saati' => '17:00',
            'state' => 'MUHURLENDI',
            'kontrol_durumu' => 'BEKLIYOR',
        ],
    ],
    ['2026-08-12' => s3ePeriod('SEALED', true, false, 'PERIOD_LOCKED')]
);
$sMatchItem = s3eItem($sealedMatch, '2026-08-12');
s3eAssert(
    $sMatchItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_MATCHES_CANONICAL_TIME,
    'SEALED match stays MATCHES_CANONICAL_TIME'
);
s3eAssert($sMatchItem['period']['revision_required'] === false, 'SEALED match revision_required NO');
s3eAssert($sMatchItem['period']['revision_hint'] === null, 'SEALED match no revision hint');
s3eAssert($sMatchItem['period']['future_action'] === null, 'SEALED match future_action null');

// SEALED + no canonical row
$sealedNoRow = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [],
    ['2026-08-12' => s3ePeriod('SEALED', true, false, 'PERIOD_LOCKED')]
);
$sNoRowItem = s3eItem($sealedNoRow, '2026-08-12');
s3eAssert(
    $sNoRowItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_PERIOD_REQUIRES_REVISION,
    'SEALED no row PERIOD_REQUIRES_REVISION'
);
s3eAssert($sNoRowItem['period']['revision_required'] === true, 'SEALED no row revision_required YES');

// SEALED + multiple intervals
$sealedMulti = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 09:00:00.000000'),
        s3eEvt(3, 'GIRIS', '2026-08-12 10:00:00.000000'),
        s3eEvt(4, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [],
    ['2026-08-12' => s3ePeriod('SEALED', true, false, 'PERIOD_LOCKED')]
);
$sMultiItem = s3eItem($sealedMulti, '2026-08-12');
s3eAssert(
    $sMultiItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_NO_SAFE_TIME_PROPOSAL,
    'SEALED multi NO_SAFE_TIME_PROPOSAL'
);
s3eAssert($sMultiItem['period']['revision_required'] === false, 'SEALED multi revision_required NO');
s3eAssert($sMultiItem['period']['revision_hint'] === null, 'SEALED multi no revision hint');

// SEALED + anomaly
$sealedAnomaly = s3eProject(
    [s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000')],
    '2026-08-12',
    '2026-08-12',
    [],
    ['2026-08-12' => s3ePeriod('SEALED', true, false, 'PERIOD_LOCKED')]
);
$sAnomalyItem = s3eItem($sealedAnomaly, '2026-08-12');
s3eAssert($sAnomalyItem['period']['revision_required'] === false, 'SEALED anomaly revision_required NO');

// REOPENED + no snapshot + diff
$reopenedDiff = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:07:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:02:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [
        '2026-08-12' => [
            'id' => 110,
            'giris_saati' => '08:00',
            'cikis_saati' => '17:00',
            'state' => 'ACIK',
            'kontrol_durumu' => 'BEKLIYOR',
        ],
    ],
    ['2026-08-12' => s3ePeriod('REOPENED', false, true)]
);
$rDiffItem = s3eItem($reopenedDiff, '2026-08-12');
s3eAssert(
    $rDiffItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_DIFFERS_CANONICAL_TIME,
    'REOPENED no snapshot DIFFERS_CANONICAL_TIME'
);
s3eAssert($rDiffItem['period']['revision_required'] === false, 'REOPENED no snapshot revision_required NO');
s3eAssert($rDiffItem['period']['future_action'] === 'DIRECT_PUANTAJ_REVIEW', 'REOPENED no snapshot future_action');

// REOPENED + active snapshot + diff
$reopenedSnapDiff = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:07:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:02:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [
        '2026-08-12' => [
            'id' => 111,
            'giris_saati' => '08:00',
            'cikis_saati' => '17:00',
            'state' => 'ACIK',
            'kontrol_durumu' => 'BEKLIYOR',
        ],
    ],
    ['2026-08-12' => s3ePeriod('REOPENED', false, false, 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED')]
);
$rSnapItem = s3eItem($reopenedSnapDiff, '2026-08-12');
s3eAssert(
    $rSnapItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_DIFFERS_CANONICAL_TIME,
    'REOPENED active snapshot DIFFERS_CANONICAL_TIME'
);
s3eAssert(
    $rSnapItem['period']['canonical_write_block_code'] === 'ACTIVE_SNAPSHOT_MUST_BE_CANCELLED',
    'REOPENED active snapshot block code'
);
s3eAssert($rSnapItem['period']['revision_required'] === false, 'REOPENED active snapshot revision_required NO');
s3eAssert($rSnapItem['period']['future_action'] === null, 'REOPENED active snapshot future_action null');

// Approved correction present
$corr = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [],
    ['2026-08-12' => s3ePeriod('SEALED', true, false, 'PERIOD_LOCKED')],
    ['2026-08-12' => true]
);
$cItem = s3eItem($corr, '2026-08-12');
s3eAssert(
    $cItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_APPROVED_CORRECTION_PRESENT,
    'APPROVED_CORRECTION_PRESENT'
);
s3eAssert($cItem['auto_applicable'] === false, 'correction blocks auto apply');
s3eAssert($cItem['period']['revision_required'] === false, 'correction revision_required NO');
s3eAssert($cItem['period']['revision_hint'] === null, 'correction no revision hint');

// Open period + clean + no row → future_action
$open = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [],
    ['2026-08-12' => s3ePeriod('ACIK', false, true)]
);
$oItem = s3eItem($open, '2026-08-12');
s3eAssert($oItem['period']['future_action'] === 'DIRECT_PUANTAJ_REVIEW', 'OPEN_PERIOD future_action');

echo PHP_EOL . 'S3E pure candidate tests OK' . PHP_EOL;
