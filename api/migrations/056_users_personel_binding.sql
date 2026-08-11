-- S3B: users ↔ personel canonical identity binding + append-only binding audit.
-- Additive only. No production backfill / seed / UPDATE existing users.
-- MariaDB 10.6 / 11.4. PHP 7.4 runtime uyumlu schema.
-- PREPARE/EXECUTE/DEALLOCATE tek satir (PDO apply uyumu).
-- UNIQUE(personel_id): multiple NULL allowed; one non-null personel per user row.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'personel_id'
);

SET @has_varsayilan := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'varsayilan_sube_id'
);

SET @sql := IF(
  @col_exists = 0 AND @has_varsayilan > 0,
  'ALTER TABLE users ADD COLUMN personel_id INT UNSIGNED NULL AFTER varsayilan_sube_id',
  IF(
    @col_exists = 0,
    'ALTER TABLE users ADD COLUMN personel_id INT UNSIGNED NULL AFTER durum',
    'SELECT 1'
  )
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
  'ALTER TABLE users ADD CONSTRAINT fk_users_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS user_personel_binding_audit (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  old_personel_id INT UNSIGNED NULL,
  new_personel_id INT UNSIGNED NULL,
  action ENUM('SET', 'CLEAR', 'REPLACE') NOT NULL,
  changed_by INT UNSIGNED NOT NULL,
  changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_upba_user_changed (user_id, changed_at, id),
  KEY idx_upba_changed_by (changed_by, changed_at, id),
  CONSTRAINT fk_upba_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_upba_changed_by FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_upba_old_personel FOREIGN KEY (old_personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_upba_new_personel FOREIGN KEY (new_personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
