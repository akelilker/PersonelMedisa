<?php

declare(strict_types=1);

/**
 * S3C-R1: Europe/Istanbul business-date → UTC half-open bounds (pure PHP).
 * php tests/php/S3CQrBusinessDateRangeTestRunner.php
 */

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceEventService;
use Medisa\Api\Services\Qr\QrAttendanceException;

function s3cBizAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

function s3cBizCatchCode(callable $fn): ?string
{
    try {
        $fn();
    } catch (QrAttendanceException $e) {
        return $e->getErrorCode();
    }

    return null;
}

// 2026-08-13 Istanbul midnight → UTC (DST/offset via DateTimeZone, not hardcoded +03)
$range = QrAttendanceEventService::businessDateRangeToUtc('2026-08-13', '2026-08-13');
s3cBizAssert($range['from'] === '2026-08-13' && $range['to'] === '2026-08-13', 'business YMD preserved');
s3cBizAssert($range['days'] === 1, 'single-day window days=1');

$tz = new DateTimeZone('Europe/Istanbul');
$utc = new DateTimeZone('UTC');
$expectedFrom = (new DateTimeImmutable('2026-08-13 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s.000000');
$expectedToEx = (new DateTimeImmutable('2026-08-14 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s.000000');
s3cBizAssert($range['from_utc'] === $expectedFrom, 'from_utc = Istanbul midnight in UTC');
s3cBizAssert($range['to_exclusive_utc'] === $expectedToEx, 'to_exclusive_utc = next Istanbul midnight in UTC');

// Boundary semantics relative to converted bounds
$inEvent = '2026-08-12 21:30:00.000000'; // 2026-08-13 00:30 Istanbul
$outEvent = '2026-08-12 20:59:59.000000'; // 2026-08-12 23:59:59 Istanbul
s3cBizAssert($inEvent >= $range['from_utc'] && $inEvent < $range['to_exclusive_utc'], '00:30 Istanbul IN');
s3cBizAssert(!($outEvent >= $range['from_utc'] && $outEvent < $range['to_exclusive_utc']), '23:59:59 prev day OUT');

$localSamples = ['00:00:00', '00:30:00', '02:59:59', '03:00:00', '23:59:59'];
foreach ($localSamples as $hm) {
    $local = new DateTimeImmutable('2026-08-13 ' . $hm, $tz);
    $asUtc = $local->setTimezone($utc)->format('Y-m-d H:i:s.000000');
    $inside = $asUtc >= $range['from_utc'] && $asUtc < $range['to_exclusive_utc'];
    s3cBizAssert($inside, 'local ' . $hm . ' maps inside Aug 13 window');
}

// Month boundary: August 2026
$month = QrAttendanceEventService::businessDateRangeToUtc('2026-08-01', '2026-08-31');
$augStart = (new DateTimeImmutable('2026-08-01 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s.000000');
$sepStart = (new DateTimeImmutable('2026-09-01 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s.000000');
s3cBizAssert($month['from_utc'] === $augStart, 'month from_utc');
s3cBizAssert($month['to_exclusive_utc'] === $sepStart, 'month to_exclusive_utc');
s3cBizAssert($month['days'] === 31, 'August days=31');

// 366-day inclusive OK; 367 deny
$ok366 = QrAttendanceEventService::businessDateRangeToUtc('2024-01-01', '2024-12-31');
s3cBizAssert($ok366['days'] === 366, '2024 leap window 366');
s3cBizAssert(s3cBizCatchCode(static function () {
    QrAttendanceEventService::businessDateRangeToUtc('2024-01-01', '2025-01-01');
}) === 'VALIDATION_ERROR', '367-day window DENY');

// defaultMonthRange stays business calendar (YMD only)
$defaults = QrAttendanceEventService::defaultMonthRange();
s3cBizAssert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $defaults['from']) === 1, 'defaultMonth from YMD');
s3cBizAssert(preg_match('/^\d{4}-\d{2}-\d{2}$/', $defaults['to']) === 1, 'defaultMonth to YMD');
s3cBizAssert($defaults['from'] <= $defaults['to'], 'defaultMonth ordered');

echo "S3C QR business date range runner OK\n";
