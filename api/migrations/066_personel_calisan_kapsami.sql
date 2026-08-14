-- Pack7F: first-class personnel ownership / directory-only status.
-- Additive. Existing rows default IC_PERSONEL.
-- tc_kimlik_no / soyad / dogum_tarihi / telefon become NULL-able for DIS_KAYNAK storage.
-- UNIQUE non-null TC is preserved. Multiple NULL TC values are allowed (InnoDB UNIQUE).
-- Application validation still requires soyad/dogum/telefon for IC_PERSONEL.
-- No dummy identity values.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @p7f_kapsam := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'calisan_kapsami'
);
SET @p7f_kapsam_sql := IF(
  @p7f_kapsam = 0,
  'ALTER TABLE personeller
     ADD COLUMN calisan_kapsami ENUM(''IC_PERSONEL'', ''DIS_KAYNAK'') NOT NULL DEFAULT ''IC_PERSONEL'' AFTER aktif_durum',
  'DO 0'
);
PREPARE p7f_kapsam_stmt FROM @p7f_kapsam_sql;
EXECUTE p7f_kapsam_stmt;
DEALLOCATE PREPARE p7f_kapsam_stmt;

SET @p7f_kapsam_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND INDEX_NAME = 'idx_personeller_calisan_kapsami'
);
SET @p7f_kapsam_idx_sql := IF(
  @p7f_kapsam_idx = 0,
  'ALTER TABLE personeller ADD KEY idx_personeller_calisan_kapsami (calisan_kapsami)',
  'DO 0'
);
PREPARE p7f_kapsam_idx_stmt FROM @p7f_kapsam_idx_sql;
EXECUTE p7f_kapsam_idx_stmt;
DEALLOCATE PREPARE p7f_kapsam_idx_stmt;

SET @p7f_tc_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'tc_kimlik_no'
  LIMIT 1
);
SET @p7f_tc_sql := IF(
  @p7f_tc_nullable = 'NO',
  'ALTER TABLE personeller MODIFY COLUMN tc_kimlik_no CHAR(11) NULL',
  'DO 0'
);
PREPARE p7f_tc_stmt FROM @p7f_tc_sql;
EXECUTE p7f_tc_stmt;
DEALLOCATE PREPARE p7f_tc_stmt;

SET @p7f_soyad_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'soyad'
  LIMIT 1
);
SET @p7f_soyad_sql := IF(
  @p7f_soyad_nullable = 'NO',
  'ALTER TABLE personeller MODIFY COLUMN soyad VARCHAR(80) NULL',
  'DO 0'
);
PREPARE p7f_soyad_stmt FROM @p7f_soyad_sql;
EXECUTE p7f_soyad_stmt;
DEALLOCATE PREPARE p7f_soyad_stmt;

SET @p7f_dogum_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'dogum_tarihi'
  LIMIT 1
);
SET @p7f_dogum_sql := IF(
  @p7f_dogum_nullable = 'NO',
  'ALTER TABLE personeller MODIFY COLUMN dogum_tarihi DATE NULL',
  'DO 0'
);
PREPARE p7f_dogum_stmt FROM @p7f_dogum_sql;
EXECUTE p7f_dogum_stmt;
DEALLOCATE PREPARE p7f_dogum_stmt;

SET @p7f_telefon_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'telefon'
  LIMIT 1
);
SET @p7f_telefon_sql := IF(
  @p7f_telefon_nullable = 'NO',
  'ALTER TABLE personeller MODIFY COLUMN telefon VARCHAR(32) NULL',
  'DO 0'
);
PREPARE p7f_telefon_stmt FROM @p7f_telefon_sql;
EXECUTE p7f_telefon_stmt;
DEALLOCATE PREPARE p7f_telefon_stmt;
