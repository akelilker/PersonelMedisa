<?php

declare(strict_types=1);

/**
 * Dedicated attendance-anomaly scanner. Web requests receive 404.
 *
 * Primary trigger for planned-exit + 180 minute missing ÇIKIŞ notifications.
 * Does not mutate QR events, puantaj, payroll, or the migration worker.
 *
 * Proposed production cPanel cron (NOT installed by this repository).
 * Do not put MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES on this cron:
 *
 *   */5 * * * * cd /home/karmotor/public_html/personelmedisa && "$(command -v php)" api/bin/attendance-anomaly-scan.php
 *
 * Test-only cPanel command, same env prefix style as the migration worker.
 * Do not install this on the production cron:
 *
 *   cd /home/karmotor/public_html/personelmedisa && MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES=5 "$(command -v php)" api/bin/attendance-anomaly-scan.php
 *
 * Cadence is 5 minutes. Expected delay after the threshold is 0-300 seconds.
 * Opening the PERSONEL app is not required. Today-read notification write is
 * only a backfill of this same owner.
 */

use Medisa\Api\Database\Connection;
use Medisa\Api\Services\Qr\QrAttendanceUnresolvedAnomalyService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $pdo = Connection::get();
} catch (Throwable $e) {
    fwrite(STDERR, "DB_CONNECTION_FAILED\n");
    exit(1);
}

try {
    $result = QrAttendanceUnresolvedAnomalyService::scan($pdo);
} catch (Throwable $e) {
    fwrite(STDERR, "ATTENDANCE_ANOMALY_SCAN_FAILED\n");
    exit(1);
}

if (!empty($result['skipped_schema'])) {
    echo "ANOMALY_DEDUPE_SCHEMA_NOT_READY\n";
    exit(0);
}

echo 'ATTENDANCE_ANOMALY_SCAN personel=' . (int) $result['personel_count']
    . ' created=' . (int) $result['created'] . "\n";
exit(0);
