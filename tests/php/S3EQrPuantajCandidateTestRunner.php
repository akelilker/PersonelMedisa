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
s3eAssert($singleItem['qr']['matched_seconds'] === 9 * 3600, 'matched 9h not span');
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

// Canonical match
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
    [
        '2026-08-12' => [
            'state' => 'ACIK',
            'canonical_write_open' => true,
            'revision_required' => false,
        ],
    ]
);
$mItem = s3eItem($canonicalMatch, '2026-08-12');
s3eAssert(
    $mItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_MATCHES_CANONICAL_TIME,
    'MATCHES_CANONICAL_TIME'
);

// Canonical diff
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
    [
        '2026-08-12' => [
            'state' => 'ACIK',
            'canonical_write_open' => true,
            'revision_required' => false,
        ],
    ]
);
$dItem = s3eItem($canonicalDiff, '2026-08-12');
s3eAssert(
    $dItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_DIFFERS_CANONICAL_TIME,
    'DIFFERS_CANONICAL_TIME'
);
s3eAssert($dItem['auto_applicable'] === false, 'diff blocks auto apply');

// Sealed period + diff
$sealed = s3eProject(
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
    [
        '2026-08-12' => [
            'state' => 'SEALED',
            'canonical_write_open' => false,
            'revision_required' => true,
        ],
    ]
);
$sItem = s3eItem($sealed, '2026-08-12');
s3eAssert(
    $sItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_PERIOD_REQUIRES_REVISION,
    'PERIOD_REQUIRES_REVISION when sealed and differs'
);
s3eAssert($sItem['period']['revision_required'] === true, 'revision_required YES');
s3eAssert(
    $sItem['period']['revision_hint'] === QrPuantajCandidateProjectionService::REVISION_HINT,
    'revision hint PUANTAJ_GIRIS_CIKIS_DUZELTME'
);

// Approved correction present
$corr = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [],
    [
        '2026-08-12' => [
            'state' => 'ACIK',
            'canonical_write_open' => true,
            'revision_required' => false,
        ],
    ],
    ['2026-08-12' => true]
);
$cItem = s3eItem($corr, '2026-08-12');
s3eAssert(
    $cItem['comparison_status'] === QrPuantajCandidateProjectionService::COMPARE_APPROVED_CORRECTION_PRESENT,
    'APPROVED_CORRECTION_PRESENT'
);
s3eAssert($cItem['auto_applicable'] === false, 'correction blocks auto apply');

// Open period + clean + no row → future_action
$open = s3eProject(
    [
        s3eEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
        s3eEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    ],
    '2026-08-12',
    '2026-08-12',
    [],
    [
        '2026-08-12' => [
            'state' => 'ACIK',
            'canonical_write_open' => true,
            'revision_required' => false,
        ],
    ]
);
$oItem = s3eItem($open, '2026-08-12');
s3eAssert($oItem['period']['future_action'] === 'DIRECT_PUANTAJ_REVIEW', 'OPEN_PERIOD future_action');

echo PHP_EOL . 'S3E pure candidate tests OK' . PHP_EOL;
