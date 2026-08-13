-- 063: Pack5 Track A — fazla calisma actual-date provenance on weekly snapshot rows.
-- POLICY: ROLLING_12_MONTH_ACTUAL_DATE_V1
-- Additive only. Does NOT rewrite legacy closed snapshots.
-- Does NOT change weekly FM amount calculation (provenance only).
-- Retention / archive chain for haftalik_kapanis_satirlari unchanged.
-- NO production seed / backfill.
-- MariaDB 10.6 / 11.4 compatible. PHP 7.4 compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @p5_ot_json := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'haftalik_kapanis_satirlari'
    AND COLUMN_NAME = 'fazla_calisma_tarih_dagilimi_json'
);
SET @p5_ot_json_sql := IF(
  @p5_ot_json = 0,
  'ALTER TABLE haftalik_kapanis_satirlari
     ADD COLUMN fazla_calisma_tarih_dagilimi_json JSON NULL
       AFTER fazla_calisma_dakika',
  'DO 0'
);
PREPARE p5_ot_json_stmt FROM @p5_ot_json_sql;
EXECUTE p5_ot_json_stmt;
DEALLOCATE PREPARE p5_ot_json_stmt;

SET @p5_ot_policy := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'haftalik_kapanis_satirlari'
    AND COLUMN_NAME = 'fazla_calisma_tarih_dagilim_policy'
);
SET @p5_ot_policy_sql := IF(
  @p5_ot_policy = 0,
  'ALTER TABLE haftalik_kapanis_satirlari
     ADD COLUMN fazla_calisma_tarih_dagilim_policy VARCHAR(64) NULL
       AFTER fazla_calisma_tarih_dagilimi_json',
  'DO 0'
);
PREPARE p5_ot_policy_stmt FROM @p5_ot_policy_sql;
EXECUTE p5_ot_policy_stmt;
DEALLOCATE PREPARE p5_ot_policy_stmt;

SET @p5_ot_policy_now := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'haftalik_kapanis_satirlari'
    AND COLUMN_NAME = 'fazla_calisma_tarih_dagilim_policy'
);
SET @p5_ot_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'haftalik_kapanis_satirlari'
    AND INDEX_NAME = 'idx_hks_fm_dagilim_policy'
);
SET @p5_ot_idx_sql := IF(
  @p5_ot_idx = 0 AND @p5_ot_policy_now > 0,
  'ALTER TABLE haftalik_kapanis_satirlari
     ADD KEY idx_hks_fm_dagilim_policy (fazla_calisma_tarih_dagilim_policy)',
  'DO 0'
);
PREPARE p5_ot_idx_stmt FROM @p5_ot_idx_sql;
EXECUTE p5_ot_idx_stmt;
DEALLOCATE PREPARE p5_ot_idx_stmt;
