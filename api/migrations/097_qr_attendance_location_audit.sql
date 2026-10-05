-- 097: GPS location audit columns on qr_attendance_events (QR scan moment only).
-- Informational / audit — never blocks QR capture. No raw lat/lng persisted.
-- APPLY is a separate gate. This file is not executed by the application.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @has_status := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'qr_attendance_events'
    AND COLUMN_NAME = 'location_verification_status'
);
SET @sql := IF(
  @has_status = 0,
  'ALTER TABLE qr_attendance_events
     ADD COLUMN location_verification_status ENUM(''VERIFIED'',''UNCERTAIN'',''OUTSIDE'',''UNAVAILABLE'') NULL AFTER request_nonce,
     ADD COLUMN location_accuracy_meters DECIMAL(8,2) NULL AFTER location_verification_status,
     ADD COLUMN location_distance_meters DECIMAL(9,2) NULL AFTER location_accuracy_meters,
     ADD COLUMN location_geofence_key VARCHAR(64) NULL AFTER location_distance_meters',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
