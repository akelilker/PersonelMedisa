-- 075: Secure personnel account onboarding + one-time activation invitations.
-- Additive only. No production seeds, no credential generation, no username/binding/role changes.
-- Existing users: activation_required DEFAULT 0, username_source DEFAULT 'MANUAL' (no backfill).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- users.activation_required
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'activation_required'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN activation_required TINYINT(1) NOT NULL DEFAULT 0 AFTER must_change_password',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.activated_at_utc
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'activated_at_utc'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN activated_at_utc DATETIME NULL DEFAULT NULL AFTER activation_required',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- users.username_source — explicit ownership; existing rows stay MANUAL (no reinterpretation).
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'username_source'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN username_source ENUM(''SICIL_CANONICAL'', ''MANUAL'', ''SYSTEM'') NOT NULL DEFAULT ''MANUAL'' AFTER activated_at_utc',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS personel_account_activation_invitations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at_utc DATETIME NOT NULL,
  expires_at_utc DATETIME NOT NULL,
  consumed_at_utc DATETIME NULL DEFAULT NULL,
  revoked_at_utc DATETIME NULL DEFAULT NULL,
  issued_by_user_id INT UNSIGNED NOT NULL,
  reissue_of_invitation_id INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_paai_token_hash (token_hash),
  KEY idx_paai_user_live (user_id, revoked_at_utc, consumed_at_utc, expires_at_utc),
  KEY idx_paai_issued_by (issued_by_user_id, created_at_utc, id),
  CONSTRAINT fk_paai_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_paai_issued_by FOREIGN KEY (issued_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_paai_reissue_of FOREIGN KEY (reissue_of_invitation_id) REFERENCES personel_account_activation_invitations (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personel_account_onboarding_audit (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  personel_id INT UNSIGNED NULL DEFAULT NULL,
  actor_user_id INT UNSIGNED NULL DEFAULT NULL,
  invitation_id INT UNSIGNED NULL DEFAULT NULL,
  detail_json TEXT NULL,
  created_at_utc DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_paoa_event_created (event_type, created_at_utc, id),
  KEY idx_paoa_user_created (user_id, created_at_utc, id),
  KEY idx_paoa_personel_created (personel_id, created_at_utc, id),
  CONSTRAINT fk_paoa_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_paoa_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_paoa_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
