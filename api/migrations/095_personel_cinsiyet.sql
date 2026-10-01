-- 095: Nullable personel gender for self-service identity and canonical writes.
-- Allowed values: Erkek, Kadın. No seed/backfill.
-- APPLY is a separate gate. This file is not executed by the application.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @p095_cinsiyet := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'cinsiyet'
);
SET @sql := IF(
  @p095_cinsiyet = 0,
  'ALTER TABLE personeller ADD COLUMN cinsiyet ENUM(''Erkek'', ''Kadın'') NULL AFTER kan_grubu',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
