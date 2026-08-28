-- 077: users.rol legacy ENUM shrink to the canonical authorization catalog.
--
-- Scope:
--   users.rol ENUM: drop PATRON, IK_BORDRO, SGK_KARAR_ONAY_YETKILISI, IDARI_ISLER
--   Keeps the 8 canonical human roles + AUTH_SMOKE_READONLY technical actor.
--   Schema only: no user role remap, no row writes, no personnel/org/business data.
--
-- Fail-closed: aborts when the users table/column is missing, when any user row
-- still carries a legacy role value, or when the post-shrink readback does not
-- match the canonical catalog exactly.
-- Idempotent: re-running on an already shrunk ENUM is a no-op.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) Structural guard — users.rol must exist as an ENUM.
-- ---------------------------------------------------------------------------
SET @p077_col_ok := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'rol'
    AND DATA_TYPE = 'enum'
);
SET @p077_col_sql := IF(
  @p077_col_ok <> 1,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK077_BLOCKER: users.rol enum column missing''',
  'DO 0'
);
PREPARE p077_stmt FROM @p077_col_sql;
EXECUTE p077_stmt;
DEALLOCATE PREPARE p077_stmt;

-- ---------------------------------------------------------------------------
-- 2) Data compatibility guard — no user may hold a legacy role value.
--    Covers active and passive rows alike; ENUM shrink would silently truncate.
-- ---------------------------------------------------------------------------
SET @p077_legacy_assigned := (
  SELECT COUNT(*)
  FROM users
  WHERE rol IN ('PATRON', 'IK_BORDRO', 'SGK_KARAR_ONAY_YETKILISI', 'IDARI_ISLER')
);
SET @p077_legacy_sql := IF(
  @p077_legacy_assigned > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK077_BLOCKER: legacy role still assigned to users''',
  'DO 0'
);
PREPARE p077_stmt FROM @p077_legacy_sql;
EXECUTE p077_stmt;
DEALLOCATE PREPARE p077_stmt;

-- ---------------------------------------------------------------------------
-- 3) Shrink users.rol to the canonical catalog (skipped when already canonical).
-- ---------------------------------------------------------------------------
SET @p077_current_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'rol'
);
SET @p077_needs_shrink := IF(
  @p077_current_type LIKE '%PATRON%'
    OR @p077_current_type LIKE '%IK\_BORDRO%'
    OR @p077_current_type LIKE '%SGK\_KARAR\_ONAY\_YETKILISI%'
    OR @p077_current_type LIKE '%IDARI\_ISLER%',
  1,
  0
);
SET @p077_shrink_sql := IF(
  @p077_needs_shrink = 1,
  'ALTER TABLE users MODIFY COLUMN rol ENUM(''GENEL_YONETICI'', ''SISTEM_YONETICISI'', ''SUBE_YONETICISI'', ''BOLUM_YONETICISI'', ''BIRIM_AMIRI'', ''IK_SORUMLUSU'', ''MUHASEBE'', ''PERSONEL'', ''AUTH_SMOKE_READONLY'') NOT NULL',
  'DO 0'
);
PREPARE p077_stmt FROM @p077_shrink_sql;
EXECUTE p077_stmt;
DEALLOCATE PREPARE p077_stmt;

-- ---------------------------------------------------------------------------
-- 4) Readback assert — canonical catalog only, no legacy remnant, no data loss.
-- ---------------------------------------------------------------------------
SET @p077_readback_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'rol'
);
SET @p077_readback_ok := IF(
  @p077_readback_type = "enum('GENEL_YONETICI','SISTEM_YONETICISI','SUBE_YONETICISI','BOLUM_YONETICISI','BIRIM_AMIRI','IK_SORUMLUSU','MUHASEBE','PERSONEL','AUTH_SMOKE_READONLY')",
  1,
  0
);
SET @p077_readback_sql := IF(
  @p077_readback_ok = 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK077_BLOCKER: canonical role enum readback failed''',
  'DO 0'
);
PREPARE p077_stmt FROM @p077_readback_sql;
EXECUTE p077_stmt;
DEALLOCATE PREPARE p077_stmt;

SET @p077_blank_rol := (
  SELECT COUNT(*) FROM users WHERE rol = '' OR rol IS NULL
);
SET @p077_blank_sql := IF(
  @p077_blank_rol > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK077_BLOCKER: role truncation detected''',
  'DO 0'
);
PREPARE p077_stmt FROM @p077_blank_sql;
EXECUTE p077_stmt;
DEALLOCATE PREPARE p077_stmt;

COMMIT;
