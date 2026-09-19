-- 090: canonical SGK bildirim donemi owner is the SGK employer (sgk_isveren), NOT sube.
--
-- Legal/domain truth (locked, not a management choice):
--   AY_1_SON_GUN         = 1st of month -> real calendar month end (28/29/30/31)
--   AY_15_SONRAKI_AY_14  = 15th of month -> 14th of the following month
-- Both values stay supported. Which one applies is a real employer/workplace
-- reporting fact, so it can never be derived from branch, company or physical
-- location, and it is never guessed/hardcoded.
--
-- Owner correction (root cause):
--   036 introduced sgk_sirket_politika_surumleri with owner = sube_id and kept
--   bildirim_donem_tipi there. 064+ then established the canonical SGK employer
--   axis (sgk_isverenler, personeller.sgk_isveren_id, subeler.sgk_isveren_id) and
--   payroll freezes personeller.sgk_isveren_id as employer identity. The reporting
--   period was never moved to that axis, so every branch of the same employer
--   (e.g. Medisa branches 12/13 with sgk_isveren_id = 1) looked like it needed its
--   own period policy. This table is the single versioned employer-scoped owner.
--
-- Overlap/conflict is fail-closed at read time: the canonical reader only accepts
-- exactly one effective ONAYLANDI row per employer interval, otherwise CONFLICT.
--
-- Additive only. NO DATA WRITES. NO seed. NO backfill. NO destructive change to
-- sgk_sirket_politika_surumleri (legacy management policy owner stays for
-- SGK_ODENEK_MAHSUP_MODU and friends). Existing payroll snapshots are untouched.
-- Idempotent re-run safe. MariaDB 10.6 / 11.4 + PHP 7.4 PDO compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

SET @p090_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'sgk_isverenler')
);
SET @p090_sql := IF(
  @p090_tables <> 2,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK090_BLOCKER: users/sgk_isverenler owner tables missing''',
  'DO 0'
);
PREPARE p090_stmt FROM @p090_sql;
EXECUTE p090_stmt;
DEALLOCATE PREPARE p090_stmt;

CREATE TABLE IF NOT EXISTS sgk_isveren_bildirim_donemi_surumleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sgk_isveren_id INT UNSIGNED NOT NULL,
  surum_kodu VARCHAR(80) NOT NULL,
  bildirim_donem_tipi ENUM('AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14') NOT NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
  dogrulama_kaynagi VARCHAR(64) NOT NULL DEFAULT 'EXPLICIT_EMPLOYER_PERIOD',
  aciklama VARCHAR(1000) NOT NULL,
  hazirlayan_id INT UNSIGNED NULL,
  onaylayan_id INT UNSIGNED NULL,
  onay_zamani DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_ibds_isveren_surum (sgk_isveren_id, surum_kodu),
  KEY idx_sgk_ibds_gecerlilik (sgk_isveren_id, gecerlilik_baslangic, gecerlilik_bitis, state),
  CONSTRAINT fk_sgk_ibds_isveren FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id),
  CONSTRAINT fk_sgk_ibds_hazirlayan FOREIGN KEY (hazirlayan_id) REFERENCES users (id),
  CONSTRAINT fk_sgk_ibds_onaylayan FOREIGN KEY (onaylayan_id) REFERENCES users (id),
  CONSTRAINT chk_sgk_ibds_dogrulama CHECK (dogrulama_kaynagi <> ''),
  CONSTRAINT chk_sgk_ibds_tarih CHECK (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic),
  CONSTRAINT chk_sgk_ibds_onay CHECK (state <> 'ONAYLANDI' OR (onaylayan_id IS NOT NULL AND onay_zamani IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fail-closed canonical shape guard.
--
-- CREATE TABLE IF NOT EXISTS is a no-op when a table already exists, so a
-- previously half-created / drifted table would silently survive. The canonical
-- employer-period shape is re-verified from information_schema after the create:
-- missing required columns, incompatible column shapes or a missing employer FK
-- raise PACK090_BLOCKER and abort the migration. This guard never ALTERs, guesses,
-- repairs, rewrites or seeds anything.
SET @p090_required_column_count := 13;
SET @p090_present_column_count := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME IN (
      'id', 'sgk_isveren_id', 'surum_kodu', 'bildirim_donem_tipi',
      'gecerlilik_baslangic', 'gecerlilik_bitis', 'state', 'dogrulama_kaynagi',
      'aciklama', 'hazirlayan_id', 'onaylayan_id', 'onay_zamani', 'created_at'
    )
);

SET @p090_bad_columns := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME IN (
      'id', 'sgk_isveren_id', 'surum_kodu', 'bildirim_donem_tipi',
      'gecerlilik_baslangic', 'gecerlilik_bitis', 'state', 'dogrulama_kaynagi', 'aciklama'
    )
    AND (
      (COLUMN_NAME = 'id' AND NOT (DATA_TYPE = 'int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'sgk_isveren_id' AND NOT (DATA_TYPE = 'int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'surum_kodu' AND NOT (DATA_TYPE = 'varchar' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'bildirim_donem_tipi' AND NOT (COLUMN_TYPE LIKE '%AY_1_SON_GUN%' AND COLUMN_TYPE LIKE '%AY_15_SONRAKI_AY_14%' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'gecerlilik_baslangic' AND NOT (DATA_TYPE = 'date' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'gecerlilik_bitis' AND NOT (DATA_TYPE = 'date' AND IS_NULLABLE = 'YES'))
      OR (COLUMN_NAME = 'state' AND NOT (COLUMN_TYPE LIKE '%ONAYLANDI%' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'dogrulama_kaynagi' AND NOT (DATA_TYPE = 'varchar' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'aciklama' AND NOT (DATA_TYPE = 'varchar' AND IS_NULLABLE = 'NO'))
    )
);

SET @p090_employer_fk := (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isveren_bildirim_donemi_surumleri'
    AND COLUMN_NAME = 'sgk_isveren_id'
    AND REFERENCED_TABLE_NAME = 'sgk_isverenler'
    AND REFERENCED_COLUMN_NAME = 'id'
);

SET @p090_bad := (IF(@p090_present_column_count = @p090_required_column_count, 0, 1))
               + @p090_bad_columns
               + (IF(@p090_employer_fk > 0, 0, 1));
SET @p090_sql := IF(
  @p090_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK090_BLOCKER: sgk_isveren_bildirim_donemi_surumleri canonical shape/column/FK drift''',
  'DO 0'
);
PREPARE p090_stmt FROM @p090_sql;
EXECUTE p090_stmt;
DEALLOCATE PREPARE p090_stmt;

COMMIT;
