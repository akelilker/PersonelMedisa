-- S98: Least-privilege SGK dual-control roles + optional personel link.
-- Additive ENUM widen + nullable personel_id. Existing users / seed / parola YOK.
-- Production apply bu PR fazinda yapilmaz.
-- MariaDB 10.6 / 11.4 uyumlu. PHP 7.4 runtime ile uyumlu schema.
-- PREPARE/EXECUTE/DEALLOCATE tek satir (PDO apply uyumu — 044/045 pattern).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE users
  MODIFY COLUMN rol ENUM(
    'GENEL_YONETICI',
    'MUHASEBE',
    'BIRIM_AMIRI',
    'BOLUM_YONETICISI',
    'PATRON',
    'AUTH_SMOKE_READONLY',
    'IK_BORDRO',
    'SGK_KARAR_ONAY_YETKILISI'
  ) NOT NULL;

-- Optional canonical user↔personel link for same-person dual-control denial.
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'personel_id'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN personel_id INT UNSIGNED NULL AFTER durum',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @uq_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_personel_id'
);

SET @sql := IF(
  @uq_exists = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_personel_id (personel_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'fk_users_personel'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @personeller_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
);

SET @sql := IF(
  @fk_exists = 0 AND @personeller_exists > 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
