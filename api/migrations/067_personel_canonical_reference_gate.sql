-- 067: Canonical Personel org reference correction — Güvenlik parent only.
-- PREPARE ONLY / production apply requires a separate operational gate.
--
-- Scope:
--   birimler.id=10, ad=Güvenlik: bolum_id 5 -> 3
--   bolumler.id=5, ad=Üretim Genel: AKTIF -> PASIF only when unused
--   No INSERT, DELETE, personnel update, or reference ID replacement.
--
-- Fail-closed on unexpected catalog drift, duplicate active Güvenlik, active
-- children, personnel usage, or an already divergent partial state.
-- Idempotent: the canonical target state is a successful no-op.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

SET @p067_tables_ok := (
  SELECT COUNT(*) = 4
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('departmanlar', 'bolumler', 'birimler', 'personeller')
);
SET @p067_tables_sql := IF(
  @p067_tables_ok = 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK067_BLOCKER: required tables missing''',
  'DO 0'
);
PREPARE p067_stmt FROM @p067_tables_sql;
EXECUTE p067_stmt;
DEALLOCATE PREPARE p067_stmt;

SET @p067_current_birim := (
  SELECT COUNT(*)
  FROM birimler
  WHERE id = 10
    AND ad = 'Güvenlik'
    AND bolum_id = 5
    AND durum = 'AKTIF'
);
SET @p067_target_birim := (
  SELECT COUNT(*)
  FROM birimler
  WHERE id = 10
    AND ad = 'Güvenlik'
    AND bolum_id = 3
    AND durum = 'AKTIF'
);
SET @p067_legacy_bolum := (
  SELECT COUNT(*)
  FROM bolumler
  WHERE id = 5
    AND departman_id = 1
    AND ad = 'Üretim Genel'
);
SET @p067_target_bolum := (
  SELECT COUNT(*)
  FROM bolumler
  WHERE id = 3
    AND departman_id = 1
    AND ad = 'Üretim'
    AND durum = 'AKTIF'
);
SET @p067_state_sql := IF(
  NOT (
    (
      @p067_current_birim = 1
      AND @p067_legacy_bolum = 1
      AND @p067_target_bolum = 1
    )
    OR (
      @p067_target_birim = 1
      AND @p067_target_bolum = 1
    )
  ),
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK067_BLOCKER: unexpected reference state''',
  'DO 0'
);
PREPARE p067_stmt FROM @p067_state_sql;
EXECUTE p067_stmt;
DEALLOCATE PREPARE p067_stmt;

SET @p067_duplicate_g_security := (
  SELECT COUNT(*)
  FROM birimler
  WHERE ad = 'Güvenlik'
    AND durum = 'AKTIF'
    AND id <> 10
);
SET @p067_duplicate_sql := IF(
  @p067_duplicate_g_security > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK067_BLOCKER: duplicate active Güvenlik reference''',
  'DO 0'
);
PREPARE p067_stmt FROM @p067_duplicate_sql;
EXECUTE p067_stmt;
DEALLOCATE PREPARE p067_stmt;

SET @p067_legacy_active_children := (
  SELECT COUNT(*)
  FROM birimler
  WHERE bolum_id = 5
    AND durum = 'AKTIF'
    AND id <> 10
);
SET @p067_usage_legacy_bolum := (
  SELECT COUNT(*)
  FROM personeller
  WHERE bolum_id = 5
);
SET @p067_usage_g_security := (
  SELECT COUNT(*)
  FROM personeller
  WHERE birim_id = 10
);
SET @p067_dependency_sql := IF(
  @p067_legacy_active_children > 0
    OR @p067_usage_legacy_bolum > 0
    OR @p067_usage_g_security > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK067_BLOCKER: legacy reference has active children or personnel usage''',
  'DO 0'
);
PREPARE p067_stmt FROM @p067_dependency_sql;
EXECUTE p067_stmt;
DEALLOCATE PREPARE p067_stmt;

SET @p067_move_sql := IF(
  @p067_current_birim = 1,
  'UPDATE birimler SET bolum_id = 3 WHERE id = 10 AND ad = ''Güvenlik'' AND bolum_id = 5 AND durum = ''AKTIF''',
  'DO 0'
);
PREPARE p067_stmt FROM @p067_move_sql;
EXECUTE p067_stmt;
DEALLOCATE PREPARE p067_stmt;

SET @p067_passive_sql := IF(
  @p067_current_birim = 1,
  'UPDATE bolumler SET durum = ''PASIF'' WHERE id = 5 AND departman_id = 1 AND ad = ''Üretim Genel'' AND durum = ''AKTIF''',
  'DO 0'
);
PREPARE p067_stmt FROM @p067_passive_sql;
EXECUTE p067_stmt;
DEALLOCATE PREPARE p067_stmt;

SET @p067_readback_birim := (
  SELECT COUNT(*)
  FROM birimler
  WHERE id = 10
    AND ad = 'Güvenlik'
    AND bolum_id = 3
    AND durum = 'AKTIF'
);
SET @p067_readback_legacy := (
  SELECT COUNT(*)
  FROM bolumler
  WHERE id = 5
    AND ad = 'Üretim Genel'
    AND durum = 'PASIF'
);
SET @p067_readback_sql := IF(
  @p067_readback_birim <> 1 OR @p067_readback_legacy <> 1,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK067_BLOCKER: canonical readback failed''',
  'DO 0'
);
PREPARE p067_stmt FROM @p067_readback_sql;
EXECUTE p067_stmt;
DEALLOCATE PREPARE p067_stmt;

COMMIT;
