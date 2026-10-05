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
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'qr_attendance_events'");
            if ($stmt) {
                $row = $stmt->fetch(PDO::FETCH_NUM);
                if (is_array($row) && isset($row[0]) && (string) $row[0] === 'qr_attendance_events') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Non-MySQL drivers (focused SQLite runners).
        }
        try {
            $stmt = $pdo->query(
                "SELECT 1 AS ok FROM sqlite_master WHERE type = 'table' AND name = 'qr_attendance_events' LIMIT 1"
            );
            if ($stmt) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return is_array($row);
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    public static function hasLocationAuditColumns(PDO $pdo)
    {
        try {
            $stmt = $pdo->query(
                "SELECT 1 AS ok FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'qr_attendance_events'
                   AND COLUMN_NAME = 'location_verification_status'
                 LIMIT 1"
            );
            if ($stmt) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                return is_array($row);
            }
        } catch (\Throwable $e) {
            // SQLite / focused runners.
        }
        try {
            $stmt = $pdo->query('PRAGMA table_info(qr_attendance_events)');
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (is_array($row) && ($row['name'] ?? '') === 'location_verification_status') {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }
}