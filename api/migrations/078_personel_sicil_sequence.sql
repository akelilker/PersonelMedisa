-- 078: canonical persistent counter for automatic personel sicil allocation.
--
-- Scope:
--   Creates personel_sicil_sequence (singleton row id = 1) and seeds next_value
--   from the highest existing numeric sicil_no.
--   Schema + counter seed only: no personel row is inserted, updated or deleted,
--   and no existing sicil value (numeric or legacy non-numeric) is rewritten.
--
-- Fail-closed: aborts when the personeller table or sicil_no column is missing,
-- or when the post-seed readback is not a single row with a positive next_value.
-- Idempotent: re-running keeps the row and can only move next_value forward
-- (GREATEST), so a re-apply never hands a used sicil back out.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) Structural guard — personeller.sicil_no must exist.
-- ---------------------------------------------------------------------------
SET @p078_col_ok := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'sicil_no'
);
SET @p078_col_sql := IF(
  @p078_col_ok <> 1,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK078_BLOCKER: personeller.sicil_no missing''',
  'DO 0'
);
PREPARE p078_stmt FROM @p078_col_sql;
EXECUTE p078_stmt;
DEALLOCATE PREPARE p078_stmt;

-- ---------------------------------------------------------------------------
-- 2) Canonical sequence owner (singleton).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personel_sicil_sequence (
  id TINYINT UNSIGNED NOT NULL,
  next_value INT UNSIGNED NOT NULL,
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) Seed / forward-only refresh from existing numeric sicils.
--    Non-numeric legacy sicils are ignored and left untouched.
-- ---------------------------------------------------------------------------
SET @p078_seed := (
  SELECT COALESCE(MAX(CAST(sicil_no AS UNSIGNED)), 0) + 1
  FROM personeller
  WHERE sicil_no REGEXP '^[0-9]+$'
);

INSERT INTO personel_sicil_sequence (id, next_value)
VALUES (1, @p078_seed)
ON DUPLICATE KEY UPDATE next_value = GREATEST(next_value, @p078_seed);

-- ---------------------------------------------------------------------------
-- 4) Readback assert — exactly one usable counter row.
-- ---------------------------------------------------------------------------
SET @p078_rows := (SELECT COUNT(*) FROM personel_sicil_sequence);
SET @p078_next := (SELECT next_value FROM personel_sicil_sequence WHERE id = 1);
SET @p078_readback_sql := IF(
  @p078_rows <> 1 OR @p078_next IS NULL OR @p078_next < 1 OR @p078_next < @p078_seed,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK078_BLOCKER: sicil sequence readback failed''',
  'DO 0'
);
PREPARE p078_stmt FROM @p078_readback_sql;
EXECUTE p078_stmt;
DEALLOCATE PREPARE p078_stmt;

COMMIT;
