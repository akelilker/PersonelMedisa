-- I13-B: Persist users.varsayilan_sube_id (nullable default branch preference).
-- Additive only. No existing-row backfill (EXISTING_USER_DEFAULT_BACKFILL = NONE).
-- FK ON DELETE SET NULL preserves branch-delete semantics with user_subeler CASCADE.
-- Membership in user_subeler is enforced by YonetimController transactional writes (not DB FK).
-- MariaDB 10.6 / 11.4 uyumlu. PHP 7.4 runtime ile uyumlu schema.
-- PREPARE/EXECUTE/DEALLOCATE tek satir (PDO apply uyumu).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'varsayilan_sube_id'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN varsayilan_sube_id INT UNSIGNED NULL AFTER durum',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'idx_users_varsayilan_sube_id'
);

SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE users ADD KEY idx_users_varsayilan_sube_id (varsayilan_sube_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'fk_users_varsayilan_sube'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @subeler_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'subeler'
);

SET @sql := IF(
  @fk_exists = 0 AND @subeler_exists > 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_varsayilan_sube FOREIGN KEY (varsayilan_sube_id) REFERENCES subeler (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
