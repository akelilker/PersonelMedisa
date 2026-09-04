-- 084: Daily attendance completion becomes the header notification owner.
-- Additive columns on gunluk_bildirim_tamamlamalari for header read state and
-- roster snapshot. NO DATA WRITES. No data backfill. Idempotent re-run safe.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @p084_table_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_bildirim_tamamlamalari'
);
SET @p084_guard_sql := IF(
  @p084_table_exists = 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK084_BLOCKER: gunluk_bildirim_tamamlamalari missing (apply 032 first)''',
  'DO 0'
);
PREPARE p084_guard_stmt FROM @p084_guard_sql;
EXECUTE p084_guard_stmt;
DEALLOCATE PREPARE p084_guard_stmt;

SET @p084_okundu_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_bildirim_tamamlamalari'
    AND COLUMN_NAME = 'okundu_mi'
);
SET @p084_okundu_sql := IF(
  @p084_okundu_exists = 0,
  'ALTER TABLE gunluk_bildirim_tamamlamalari
     ADD COLUMN okundu_mi TINYINT(1) NOT NULL DEFAULT 0
       AFTER not_metni',
  'SELECT 1'
);
PREPARE p084_okundu_stmt FROM @p084_okundu_sql;
EXECUTE p084_okundu_stmt;
DEALLOCATE PREPARE p084_okundu_stmt;

SET @p084_toplam_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_bildirim_tamamlamalari'
    AND COLUMN_NAME = 'toplam_personel'
);
SET @p084_toplam_sql := IF(
  @p084_toplam_exists = 0,
  'ALTER TABLE gunluk_bildirim_tamamlamalari
     ADD COLUMN toplam_personel INT UNSIGNED NULL
       AFTER okundu_mi',
  'SELECT 1'
);
PREPARE p084_toplam_stmt FROM @p084_toplam_sql;
EXECUTE p084_toplam_stmt;
DEALLOCATE PREPARE p084_toplam_stmt;
