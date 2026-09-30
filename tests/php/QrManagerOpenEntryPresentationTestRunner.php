<?php

declare(strict_types=1);

require_once __DIR__ . '/../../api/src/bootstrap.php';

use Medisa\Api\Services\Qr\QrAttendanceIntervalReadService;

function openEntryPresentationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('[FAIL] ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
}

function openEntryPresentationPdo(): PDO
{
    return new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** @param array<string,mixed> $person @param list<array<string,mixed>> $events */
function openEntryPresentationMap(PDO $pdo, array $person, array $events, string $businessDate, string $today): ?array
{
    $method = new ReflectionMethod(QrAttendanceIntervalReadService::class, 'mapManagerBusinessDateRow');
    $method->setAccessible(true);

    /** @var array<string,mixed>|null $row */
    $row = $method->invoke($pdo, $person, $events, $businessDate, $today, false);

    return $row;
}

$pdo = openEntryPresentationPdo();
$person = [
    'personel_id' => 42,
    'ad_soyad' => 'Test Personel',
    'sicil_no' => 'T-001',
    'sube_id' => 1,
    'sube' => 'Merkez',
];
$events = [
    [
        'id' => 100,
        'event_type' => 'GIRIS',
        'occurred_at_utc' => '2026-08-15 02:03:00.000000',
        'sube_id' => 1,
        'sube_ad' => 'Merkez',
        'user_id' => 1,
    ],
];

$todayRow = openEntryPresentationMap($pdo, $person, $events, '2026-08-15', '2026-08-15');
openEntryPresentationAssert(is_array($todayRow), 'today row is materialized');
openEntryPresentationAssert($todayRow['inside'] === true, 'open GIRIS is inside on today');
openEntryPresentationAssert($todayRow['first_entry'] !== null, 'first_entry comes from open GIRIS when no interval');
openEntryPresentationAssert($todayRow['last_exit'] === null, 'last_exit stays empty while open');
openEntryPresentationAssert($todayRow['missing_exit'] === false, 'pre-threshold open GIRIS is not missing_exit on today');
openEntryPresentationAssert($todayRow['anomalies'] === [], 'pre-threshold open GIRIS has no user-facing anomalies');

$pastRow = openEntryPresentationMap($pdo, $person, $events, '2026-08-15', '2026-08-16');
openEntryPresentationAssert(is_array($pastRow), 'historical row is materialized');
openEntryPresentationAssert($pastRow['missing_exit'] === true, 'past unresolved open GIRIS keeps missing_exit');
openEntryPresentationAssert(in_array('MISSING_CIKIS', $pastRow['anomalies'], true), 'past unresolved open GIRIS keeps MISSING_CIKIS');

echo '[OK] QrManagerOpenEntryPresentationTestRunner' . PHP_EOL;
