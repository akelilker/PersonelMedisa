<?php

declare(strict_types=1);

namespace Medisa\Api\Database;

use PDO;

/**
 * Schema gate for S3C qr_attendance_events (migration 057).
 * No process-level cache — safe under rolling deploys.
 */
class QrAttendanceSchema
{
    public static function hasTable(PDO $pdo)
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'qr_attendance_events'");
        if (!$stmt) {
            return false;
        }
        $row = $stmt->fetch(PDO::FETCH_NUM);

        return is_array($row) && isset($row[0]) && (string) $row[0] === 'qr_attendance_events';
    }
}
