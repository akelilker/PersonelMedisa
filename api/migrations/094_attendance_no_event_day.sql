-- 094: Day-key identity for NO_EVENT_DAY attendance anomalies and corrections.
-- Recovery shape: safe on a clean 093 schema and on the partial schema left when
-- the previous 094 apply failed inside the pending_day_guard ALTER (MariaDB 1901).
-- DATE_FORMAT is not used. A DATE value inside CONCAT is the canonical YYYY-MM-DD
-- text and is accepted in STORED generated columns on MariaDB 10.6 and 11.4.
-- Event dedupe is added back only when missing. It is not dropped on a clean 093
-- schema, because source_event_id can be made nullable while that guard remains.
-- No QR insert. No puantaj mutation. No seed data.
-- APPLY is a separate gate. This file is not executed by the application.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE qr_attendance_correction_requests
  MODIFY source_event_id INT UNSIGNED NULL,
  MODIFY original_occurred_at_utc DATETIME(6) NULL;

SET @qacr_has_anomaly_type := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'qr_attendance_correction_requests'
    AND COLUMN_NAME = 'anomaly_type'
);
SET @sql := IF(
  @qacr_has_anomaly_type = 0,
  'ALTER TABLE qr_attendance_correction_requests ADD COLUMN anomaly_type VARCHAR(32) NULL AFTER event_type',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @qacr_has_event_guard := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'qr_attendance_correction_requests'
    AND COLUMN_NAME = 'pending_event_guard'
);
SET @sql := IF(
  @qacr_has_event_guard = 0,
  'ALTER TABLE qr_attendance_correction_requests ADD COLUMN pending_event_guard INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = ''BEKLIYOR'' THEN source_event_id ELSE NULL END) STORED',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @qacr_has_event_index := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'qr_attendance_correction_requests'
    AND INDEX_NAME = 'uq_qacr_pending_event'
);
SET @sql := IF(
  @qacr_has_event_index = 0,
  'ALTER TABLE qr_attendance_correction_requests ADD UNIQUE KEY uq_qacr_pending_event (pending_event_guard)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @qacr_day_expr := (
  SELECT GENERATION_EXPRESSION
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'qr_attendance_correction_requests'
    AND COLUMN_NAME = 'pending_day_guard'
);
SET @qacr_day_rebuild := IF(
  @qacr_day_expr IS NOT NULL AND @qacr_day_expr LIKE '%date_format%',
  1,
  0
);
SET @sql := IF(
  @qacr_day_rebuild = 1,
  'ALTER TABLE qr_attendance_correction_requests DROP INDEX IF EXISTS uq_qacr_pending_day',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
  @qacr_day_rebuild = 1,
  'ALTER TABLE qr_attendance_correction_requests DROP COLUMN IF EXISTS pending_day_guard',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @qacr_has_day_guard := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'qr_attendance_correction_requests'
    AND COLUMN_NAME = 'pending_day_guard'
);
SET @sql := IF(
  @qacr_has_day_guard = 0,
  'ALTER TABLE qr_attendance_correction_requests ADD COLUMN pending_day_guard VARCHAR(96) GENERATED ALWAYS AS (CASE WHEN status = ''BEKLIYOR'' AND source_event_id IS NULL AND anomaly_type IS NOT NULL AND anomaly_type <> '''' THEN CONCAT(personel_id, ''#'', business_date, ''#'', anomaly_type) ELSE NULL END) STORED',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @qacr_has_day_index := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'qr_attendance_correction_requests'
    AND INDEX_NAME = 'uq_qacr_pending_day'
);
SET @sql := IF(
  @qacr_has_day_index = 0,
  'ALTER TABLE qr_attendance_correction_requests ADD UNIQUE KEY uq_qacr_pending_day (pending_day_guard)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @pin_expr := (
  SELECT GENERATION_EXPRESSION
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personel_inbox_notifications'
    AND COLUMN_NAME = 'anomaly_dedupe_key'
);
SET @pin_rebuild := IF(
  @pin_expr IS NULL
    OR @pin_expr LIKE '%date_format%'
    OR @pin_expr NOT LIKE '%anomaly_business_date%',
  1,
  0
);
SET @sql := IF(
  @pin_rebuild = 1,
  'ALTER TABLE personel_inbox_notifications DROP INDEX IF EXISTS uq_pin_anomaly_dedupe',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(
  @pin_rebuild = 1,
  'ALTER TABLE personel_inbox_notifications DROP COLUMN IF EXISTS anomaly_dedupe_key',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @pin_has_type := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personel_inbox_notifications'
    AND COLUMN_NAME = 'anomaly_type'
);
SET @sql := IF(
  @pin_has_type = 0,
  'ALTER TABLE personel_inbox_notifications ADD COLUMN anomaly_type VARCHAR(32) NULL AFTER anomaly_audience',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @pin_has_date := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personel_inbox_notifications'
    AND COLUMN_NAME = 'anomaly_business_date'
);
SET @sql := IF(
  @pin_has_date = 0,
  'ALTER TABLE personel_inbox_notifications ADD COLUMN anomaly_business_date DATE NULL AFTER anomaly_type',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @pin_has_key := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personel_inbox_notifications'
    AND COLUMN_NAME = 'anomaly_dedupe_key'
);
SET @sql := IF(
  @pin_has_key = 0,
  'ALTER TABLE personel_inbox_notifications ADD COLUMN anomaly_dedupe_key VARCHAR(160) GENERATED ALWAYS AS (CASE WHEN anomaly_audience IS NULL OR anomaly_audience = '''' THEN NULL WHEN anomaly_source_event_id IS NOT NULL THEN CONCAT(kind, ''#'', anomaly_source_event_id, ''#'', anomaly_audience) WHEN anomaly_type IS NOT NULL AND anomaly_type <> '''' AND anomaly_business_date IS NOT NULL AND personel_id IS NOT NULL THEN CONCAT(kind, ''#'', anomaly_type, ''#'', personel_id, ''#'', anomaly_business_date, ''#'', anomaly_audience) ELSE NULL END) STORED',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @pin_has_index := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personel_inbox_notifications'
    AND INDEX_NAME = 'uq_pin_anomaly_dedupe'
);
SET @sql := IF(
  @pin_has_index = 0,
  'ALTER TABLE personel_inbox_notifications ADD UNIQUE KEY uq_pin_anomaly_dedupe (anomaly_dedupe_key)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
