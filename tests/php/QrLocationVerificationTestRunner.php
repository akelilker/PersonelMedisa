<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceLocationVerificationService;

function qrLocAssert(bool $ok, string $name): void
{
    if (!$ok) {
        throw new RuntimeException('[FAIL] ' . $name);
    }
    echo '[PASS] ' . $name . PHP_EOL;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$centerLat = QrAttendanceLocationVerificationService::CENTER_LAT;
$centerLng = QrAttendanceLocationVerificationService::CENTER_LNG;

$verified = QrAttendanceLocationVerificationService::evaluateForScan(
    $pdo,
    1,
    '2026-10-05 10:00:00.000000',
    [
        'available' => true,
        'latitude' => $centerLat,
        'longitude' => $centerLng,
        'accuracy_meters' => 20,
    ]
);
qrLocAssert(
    $verified['location_verification_status'] === QrAttendanceLocationVerificationService::STATUS_VERIFIED,
    'center point with good accuracy → VERIFIED'
);

$unavailable = QrAttendanceLocationVerificationService::evaluateForScan(
    $pdo,
    1,
    '2026-10-05 10:00:00.000000',
    ['available' => false]
);
qrLocAssert(
    $unavailable['location_verification_status'] === QrAttendanceLocationVerificationService::STATUS_UNAVAILABLE,
    'missing capture → UNAVAILABLE'
);

$outside = QrAttendanceLocationVerificationService::evaluateForScan(
    $pdo,
    1,
    '2026-10-05 10:00:00.000000',
    [
        'available' => true,
        'latitude' => 41.18,
        'longitude' => 32.65,
        'accuracy_meters' => 15,
    ]
);
qrLocAssert(
    $outside['location_verification_status'] === QrAttendanceLocationVerificationService::STATUS_OUTSIDE,
    'far coordinate → OUTSIDE'
);

$weak = QrAttendanceLocationVerificationService::evaluateForScan(
    $pdo,
    1,
    '2026-10-05 10:00:00.000000',
    [
        'available' => true,
        'latitude' => $centerLat,
        'longitude' => $centerLng,
        'accuracy_meters' => 120,
    ]
);
qrLocAssert(
    $weak['location_verification_status'] === QrAttendanceLocationVerificationService::STATUS_UNCERTAIN,
    'weak accuracy → UNCERTAIN'
);

qrLocAssert(
    QrAttendanceLocationVerificationService::managerLabel(QrAttendanceLocationVerificationService::STATUS_OUTSIDE)
        === 'İşyeri Dışından İşlem',
    'manager label OUTSIDE'
);

echo '[OK] QrLocationVerificationTestRunner' . PHP_EOL;
