-- S98: Least-privilege SGK dual-control roles + actor_identities registry.
-- Additive ENUM widen + actor_identities + users.actor_identity_id.
-- Formal actor identity is independent of incomplete personel master.
-- Optional future bridge: actor_identities.personel_id (nullable).
-- Existing users / seed / parola / production actor rows YOK.
-- Production apply bu PR fazinda yapilmaz.
-- MariaDB 10.6 / 11.4 uyumlu. PHP 7.4 runtime ile uyumlu schema.
-- PREPARE/EXECUTE/DEALLOCATE tek satir (PDO apply uyumu — 044/046 pattern).

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

CREATE TABLE IF NOT EXISTS actor_identities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identity_code VARCHAR(64) NOT NULL,
  display_name VARCHAR(191) NOT NULL,
  normalized_name VARCHAR(191) NOT NULL,
  status ENUM('PENDING', 'VERIFIED', 'REVOKED') NOT NULL DEFAULT 'PENDING',
  verification_source ENUM('HUMAN_CONFIRMED', 'PERSONEL_LINKED', 'MIGRATED') NOT NULL DEFAULT 'HUMAN_CONFIRMED',
  personel_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_actor_identities_code (identity_code),
  UNIQUE KEY uq_actor_identities_personel_id (personel_id),
  KEY idx_actor_identities_status (status),
  KEY idx_actor_identities_normalized_name (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional future personel bridge (not required for formal SGK actors).
SET @ai_fk_personel_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'actor_identities'
    AND CONSTRAINT_NAME = 'fk_actor_identities_personel'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @personeller_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
);

SET @sql := IF(
  @ai_fk_personel_exists = 0 AND @personeller_exists > 0,
  'ALTER TABLE actor_identities ADD CONSTRAINT fk_actor_identities_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'actor_identity_id'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN actor_identity_id INT UNSIGNED NULL AFTER durum',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @uq_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_actor_identity_id'
);

SET @sql := IF(
  @uq_exists = 0,
  'ALTER TABLE users ADD UNIQUE KEY uq_users_actor_identity_id (actor_identity_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND CONSTRAINT_NAME = 'fk_users_actor_identity'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql := IF(
  @fk_exists = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_users_actor_identity FOREIGN KEY (actor_identity_id) REFERENCES actor_identities (id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
