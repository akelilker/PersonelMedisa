<?php

declare(strict_types=1);

/**
 * S3D: pure QR_INTERVAL_V1 derivation contract tests (no DB).
 * php tests/php/S3DQrIntervalDerivationTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceIntervalDerivationService;

function s3dAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

/**
 * @param list<array<string,mixed>> $events
 * @return array<string,mixed>
 */
function s3dDerive(array $events): array
{
    return QrAttendanceIntervalDerivationService::derive($events);
}

function s3dEvt($id, $type, $utc, $subeId = 1, $userId = 10): array
{
    return [
        'id' => $id,
        'event_type' => $type,
        'occurred_at_utc' => $utc,
        'sube_id' => $subeId,
        'user_id' => $userId,
        'sube_ad' => 'Sube' . $subeId,
    ];
}

s3dAssert(
    QrAttendanceIntervalDerivationService::ALGORITHM_VERSION === 'QR_INTERVAL_V1',
    'algorithm version QR_INTERVAL_V1'
);

// Empty
$empty = s3dDerive([]);
s3dAssert($empty['summary']['complete_interval_count'] === 0, 'empty intervals=0');
s3dAssert($empty['summary']['anomaly_count'] === 0, 'empty anomalies=0');
s3dAssert($empty['source_event_count'] === 0, 'empty source_event_count=0');
s3dAssert($empty['source_max_event_id'] === null, 'empty source_max_event_id=null');

// Single GIRIS
$singleG = s3dDerive([s3dEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000')]);
s3dAssert(count($singleG['intervals']) === 0, 'single GIRIS no interval');
s3dAssert(count($singleG['anomalies']) === 1, 'single GIRIS one anomaly');
s3dAssert($singleG['anomalies'][0]['type'] === 'MISSING_CIKIS', 'single GIRIS MISSING_CIKIS');
s3dAssert($singleG['anomalies'][0]['correction_hint'] === 'GIRIS_CIKIS_DUZELTME', 'correction hint');

// Single CIKIS
$singleC = s3dDerive([s3dEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000')]);
s3dAssert(count($singleC['intervals']) === 0, 'single CIKIS no interval');
s3dAssert($singleC['anomalies'][0]['type'] === 'MISSING_GIRIS', 'single CIKIS MISSING_GIRIS');

// Consecutive GIRIS then CIKIS
$consecG = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
    s3dEvt(2, 'GIRIS', '2026-08-12 05:05:00.000000'),
    s3dEvt(3, 'CIKIS', '2026-08-12 14:00:00.000000'),
]);
s3dAssert(count($consecG['intervals']) === 1, 'consec GIRIS → 1 interval');
s3dAssert($consecG['intervals'][0]['entry_event_id'] === 2, 'pair uses second GIRIS');
s3dAssert($consecG['intervals'][0]['exit_event_id'] === 3, 'pair exit id=3');
s3dAssert(count($consecG['anomalies']) === 1, 'first GIRIS anomaly');
s3dAssert($consecG['anomalies'][0]['event_id'] === 1, 'anomaly event #1');
s3dAssert($consecG['intervals'][0]['duration_seconds'] === 8 * 3600 + 55 * 60, 'duration 8h55m');

// Consecutive CIKIS
$consecC = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
    s3dEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000'),
    s3dEvt(3, 'CIKIS', '2026-08-12 14:05:00.000000'),
]);
s3dAssert(count($consecC['intervals']) === 1, 'consec CIKIS → 1 interval');
s3dAssert($consecC['intervals'][0]['exit_event_id'] === 2, 'first CIKIS closes');
s3dAssert($consecC['anomalies'][0]['type'] === 'MISSING_GIRIS', 'orphan second CIKIS');
s3dAssert($consecC['anomalies'][0]['event_id'] === 3, 'orphan id=3');

// Multiple intervals same day
$multi = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000'),
    s3dEvt(2, 'CIKIS', '2026-08-12 09:00:00.000000'),
    s3dEvt(3, 'GIRIS', '2026-08-12 10:00:00.000000'),
    s3dEvt(4, 'CIKIS', '2026-08-12 14:00:00.000000'),
]);
s3dAssert(count($multi['intervals']) === 2, 'two complete intervals');
s3dAssert($multi['summary']['anomaly_count'] === 0, 'no anomalies');
s3dAssert($multi['summary']['complete_duration_seconds'] === 8 * 3600, 'sum duration 8h');

// Cross-midnight
$xnight = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-08-11 20:00:00.000000'), // 23:00 Istanbul
    s3dEvt(2, 'CIKIS', '2026-08-12 04:00:00.000000'), // 07:00 Istanbul
]);
s3dAssert(count($xnight['intervals']) === 1, 'cross-midnight interval');
s3dAssert($xnight['intervals'][0]['spans_local_midnight'] === true, 'spans_local_midnight YES');
s3dAssert($xnight['intervals'][0]['duration_seconds'] === 8 * 3600, 'cross-midnight 8h');
s3dAssert($xnight['summary']['anomaly_count'] === 0, 'cross-midnight no anomaly');

// Multi-day explicit pair (Fri→Mon style) — no synthetic weekend exit
$long = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-08-07 17:00:00.000000'),
    s3dEvt(2, 'CIKIS', '2026-08-10 05:00:00.000000'),
]);
s3dAssert(count($long['intervals']) === 1, 'long explicit pair still COMPLETE');
s3dAssert($long['intervals'][0]['duration_seconds'] === 60 * 3600, 'long duration 60h real');

// Branch mismatch
$mm = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000', 1),
    s3dEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000', 2),
    s3dEvt(3, 'GIRIS', '2026-08-12 15:00:00.000000', 2),
    s3dEvt(4, 'CIKIS', '2026-08-12 16:00:00.000000', 2),
]);
s3dAssert(count($mm['intervals']) === 1, 'after mismatch later pair works');
s3dAssert($mm['intervals'][0]['entry_event_id'] === 3, 'later pair entry=3');
s3dAssert(count($mm['anomalies']) === 1, 'one BRANCH_MISMATCH');
s3dAssert($mm['anomalies'][0]['type'] === 'BRANCH_MISMATCH', 'mismatch type');
s3dAssert((int) $mm['anomalies'][0]['entry_event_id'] === 1, 'mismatch entry=1');
s3dAssert((int) $mm['anomalies'][0]['exit_event_id'] === 2, 'mismatch exit=2');

// Same timestamp — id ASC tie-break → duration 0
$sameTs = s3dDerive([
    s3dEvt(2, 'CIKIS', '2026-08-12 12:00:00.000000'),
    s3dEvt(1, 'GIRIS', '2026-08-12 12:00:00.000000'),
]);
s3dAssert(count($sameTs['intervals']) === 1, 'same-ts interval');
s3dAssert($sameTs['intervals'][0]['entry_event_id'] === 1, 'id ASC entry first');
s3dAssert($sameTs['intervals'][0]['exit_event_id'] === 2, 'id ASC exit second');
s3dAssert($sameTs['intervals'][0]['duration_seconds'] === 0, 'zero duration allowed');

// Different user_id same personel stream — no pairing block
$userShift = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-08-12 05:00:00.000000', 1, 10),
    s3dEvt(2, 'CIKIS', '2026-08-12 14:00:00.000000', 1, 99),
]);
s3dAssert(count($userShift['intervals']) === 1, 'user_id change still pairs');
s3dAssert($userShift['intervals'][0]['entry_user_id'] === 10, 'entry_user_id provenance');
s3dAssert($userShift['intervals'][0]['exit_user_id'] === 99, 'exit_user_id provenance');

// Range filter: entry outside → COMPLETE excluded; paired CIKIS not orphaned
$derived = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-07-31 20:00:00.000000'), // Aug 1? Jul 31 23:00 TR → check
    s3dEvt(2, 'CIKIS', '2026-08-01 04:00:00.000000'), // Aug 1 07:00 TR
]);
// 2026-07-31 20:00 UTC = 2026-07-31 23:00 Istanbul
s3dAssert($derived['intervals'][0]['entry_local_date'] === '2026-07-31', 'entry local Jul 31');
s3dAssert($derived['intervals'][0]['exit_local_date'] === '2026-08-01', 'exit local Aug 1');
$augOnly = QrAttendanceIntervalDerivationService::filterToBusinessRange($derived, '2026-08-01', '2026-08-31');
s3dAssert(count($augOnly['intervals']) === 0, 'Aug filter excludes Jul-entry interval');
s3dAssert(count($augOnly['anomalies']) === 0, 'Aug filter no false orphan CIKIS');

// Next-context close: entry in Aug, exit Sep → Aug shows COMPLETE
$augEdge = s3dDerive([
    s3dEvt(1, 'GIRIS', '2026-08-31 20:00:00.000000'), // Sep? Aug 31 23:00 TR
    s3dEvt(2, 'CIKIS', '2026-08-31 22:00:00.000000'), // Sep 1 01:00 TR
]);
s3dAssert($augEdge['intervals'][0]['entry_local_date'] === '2026-08-31', 'edge entry Aug 31');
$augFilter = QrAttendanceIntervalDerivationService::filterToBusinessRange($augEdge, '2026-08-01', '2026-08-31');
s3dAssert(count($augFilter['intervals']) === 1, 'Aug includes entry-anchored COMPLETE');
s3dAssert(count($augFilter['anomalies']) === 0, 'no MISSING_CIKIS when next closes');

echo '[OK] S3DQrIntervalDerivationTestRunner' . PHP_EOL;
