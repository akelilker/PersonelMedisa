-- 079: branch-scoped monthly closing state + persistent approval actor.
--
-- Scope:
--   1) aylik_kapanis_state gains sube_id so the aggregated closing state is per
--      (ay, sube_id) instead of one global row per month. Legacy rows collapse to
--      the sentinel sube_id = 0 ("scope not resolved"), which keeps every existing
--      row readable and keeps the UNIQUE key usable (NULL would not dedupe).
--   2) aylik_ozet_satirlari gains the approving actor for both approval steps, so
--      separation of duties on the bolum-onay -> ay-kapat chain becomes provable
--      and queryable instead of being inferred from a hardcoded son_islem string.
--
--   Schema only: no aylik_ozet_satirlari status value, no state value and no
--   personel row is inserted, updated or deleted.
--
-- No FK on the actor columns on purpose: rows approved before actor capture
-- existed stay NULL, and removing a user must never make closing history
-- unwritable or block a month from closing.
--
-- Key transition order is load bearing. The composite UNIQUE key is created and
-- asserted BEFORE the legacy month-only key is dropped, so aylik_kapanis_state is
-- never uniqueness-free: an abort between the two steps leaves both keys in place,
-- which is redundant but still duplicate-proof. Adding the composite key while the
-- legacy key still exists is safe precisely because UNIQUE(ay) guarantees at most
-- one row per month, so (ay, 0) cannot collide during the rewrite.
--
-- Fail-closed: aborts when either target table is missing, when the composite key
-- cannot be created, or when the post-DDL readback does not show every new column
-- and exactly the intended key shape.
-- Idempotent: every step is guarded by information_schema, so re-running after a
-- partial run resumes instead of failing and never drops or rewrites data.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) Structural guard — both owner tables must exist.
-- ---------------------------------------------------------------------------
SET @p079_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('aylik_kapanis_state', 'aylik_ozet_satirlari')
);
SET @p079_sql := IF(
  @p079_tables <> 2,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK079_BLOCKER: aylik closing tables missing''',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 2) aylik_kapanis_state.sube_id — canonical branch of the aggregated state.
-- ---------------------------------------------------------------------------
SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aylik_kapanis_state'
     AND COLUMN_NAME = 'sube_id') = 0,
  'ALTER TABLE aylik_kapanis_state
     ADD COLUMN sube_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER ay',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 3) Add the composite UNIQUE key FIRST, while the legacy key still protects the
--    table. Both keys coexist from here until step 5 succeeds.
-- ---------------------------------------------------------------------------
SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aylik_kapanis_state'
     AND INDEX_NAME = 'uq_aylik_kapanis_state_ay_sube') = 0,
  'ALTER TABLE aylik_kapanis_state
     ADD UNIQUE KEY uq_aylik_kapanis_state_ay_sube (ay, sube_id)',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 4) Assert the composite key exists before anything is dropped. Without this
--    gate a failed ADD followed by a successful DROP would leave the table with
--    no uniqueness at all, which is the one state that cannot be repaired by a
--    rerun because duplicates can already have been written.
-- ---------------------------------------------------------------------------
-- The name alone is not the guarantee: a non-unique index or an index on the
-- wrong columns would pass a name-only check and leave the table effectively
-- unprotected once the legacy key is dropped.
SET @p079_composite_cols := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'aylik_kapanis_state'
    AND INDEX_NAME = 'uq_aylik_kapanis_state_ay_sube'
    AND NON_UNIQUE = 0
    AND COLUMN_NAME IN ('ay', 'sube_id')
);
SET @p079_sql := IF(
  @p079_composite_cols <> 2,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK079_BLOCKER: composite closing state key missing before legacy drop''',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 5) Only now the month-only key may go: it rejects a second branch for the same
--    month at insert time.
-- ---------------------------------------------------------------------------
SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aylik_kapanis_state'
     AND INDEX_NAME = 'uq_aylik_kapanis_state_ay') > 0,
  'ALTER TABLE aylik_kapanis_state DROP INDEX uq_aylik_kapanis_state_ay',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 6) aylik_ozet_satirlari — approving actor for both steps of the chain.
-- ---------------------------------------------------------------------------
SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aylik_ozet_satirlari'
     AND COLUMN_NAME = 'bolum_onay_actor_user_id') = 0,
  'ALTER TABLE aylik_ozet_satirlari
     ADD COLUMN bolum_onay_actor_user_id INT UNSIGNED NULL AFTER son_islem,
     ADD COLUMN bolum_onay_actor_identity_id INT UNSIGNED NULL AFTER bolum_onay_actor_user_id,
     ADD COLUMN bolum_onay_at DATETIME(3) NULL AFTER bolum_onay_actor_identity_id',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aylik_ozet_satirlari'
     AND COLUMN_NAME = 'kapanis_actor_user_id') = 0,
  'ALTER TABLE aylik_ozet_satirlari
     ADD COLUMN kapanis_actor_user_id INT UNSIGNED NULL AFTER kapanis_durumu,
     ADD COLUMN kapanis_actor_identity_id INT UNSIGNED NULL AFTER kapanis_actor_user_id,
     ADD COLUMN kapanis_at DATETIME(3) NULL AFTER kapanis_actor_identity_id',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

SET @p079_sql := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'aylik_ozet_satirlari'
     AND INDEX_NAME = 'idx_aylik_ozet_bolum_onay_actor') = 0,
  'ALTER TABLE aylik_ozet_satirlari
     ADD KEY idx_aylik_ozet_bolum_onay_actor (ay, sube_id, bolum_onay_actor_user_id)',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

-- ---------------------------------------------------------------------------
-- 7) Readback assert — every new column exists, the composite key exists and the
--    legacy month-only key is gone.
-- ---------------------------------------------------------------------------
SET @p079_cols := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND (
      (TABLE_NAME = 'aylik_kapanis_state' AND COLUMN_NAME = 'sube_id')
      OR (TABLE_NAME = 'aylik_ozet_satirlari' AND COLUMN_NAME IN (
        'bolum_onay_actor_user_id', 'bolum_onay_actor_identity_id', 'bolum_onay_at',
        'kapanis_actor_user_id', 'kapanis_actor_identity_id', 'kapanis_at'
      ))
    )
);
SET @p079_uq := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'aylik_kapanis_state'
    AND INDEX_NAME = 'uq_aylik_kapanis_state_ay_sube'
    AND NON_UNIQUE = 0
    AND COLUMN_NAME IN ('ay', 'sube_id')
);
SET @p079_legacy_uq := (
  SELECT COUNT(DISTINCT INDEX_NAME)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'aylik_kapanis_state'
    AND INDEX_NAME = 'uq_aylik_kapanis_state_ay'
);
SET @p079_sql := IF(
  @p079_cols <> 7 OR @p079_uq <> 2 OR @p079_legacy_uq <> 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK079_BLOCKER: aylik closing scope/actor readback failed''',
  'DO 0'
);
PREPARE p079_stmt FROM @p079_sql;
EXECUTE p079_stmt;
DEALLOCATE PREPARE p079_stmt;

COMMIT;
