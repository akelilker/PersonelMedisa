-- Phase C: Retention / legal hold / archive (Medisa saklama politikası).
-- Additive only. No seed, no backfill, no personel master mutation.
-- Company policy: Medisa saklama politikası — minimum 10 calendar years (NOT statutory wording).
-- MariaDB 10.6 / 11.4 uyumlu. PHP 7.4 runtime ile uyumlu schema.
-- PREPARE/EXECUTE/DEALLOCATE tek satir (PDO apply uyumu — 048/051 pattern).
-- Production apply bu PR fazinda yapilmaz.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Widen users.rol ENUM: restatement of 048 roles + IDARI_ISLER + SISTEM_YONETICISI.
ALTER TABLE users
  MODIFY COLUMN rol ENUM(
    'GENEL_YONETICI',
    'MUHASEBE',
    'BIRIM_AMIRI',
    'BOLUM_YONETICISI',
    'PATRON',
    'AUTH_SMOKE_READONLY',
    'IK_BORDRO',
    'SGK_KARAR_ONAY_YETKILISI',
    'IDARI_ISLER',
    'SISTEM_YONETICISI'
  ) NOT NULL;

CREATE TABLE IF NOT EXISTS arsiv_manifestleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(64) NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NULL,
  record_category VARCHAR(64) NOT NULL,
  source_version_identity VARCHAR(191) NOT NULL,
  trigger_type ENUM('PERIOD_CLOSURE', 'TERMINATION_DATE') NOT NULL,
  trigger_date DATE NOT NULL,
  retention_until DATE NOT NULL,
  source_sha256 CHAR(64) NULL,
  integrity_status ENUM('OK', 'CHANGED', 'UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_arsiv_manifest_entity_cat (entity_type, record_id, record_category),
  KEY idx_arsiv_manifest_personel (personel_id),
  KEY idx_arsiv_manifest_retention (retention_until),
  KEY idx_arsiv_manifest_category (record_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legal_holdlar (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_domain VARCHAR(64) NOT NULL,
  target_category VARCHAR(64) NULL,
  target_record_id INT UNSIGNED NULL,
  personel_id INT UNSIGNED NULL,
  reason TEXT NOT NULL,
  hold_state ENUM('ACTIVE', 'RELEASED') NOT NULL DEFAULT 'ACTIVE',
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  released_by INT UNSIGNED NULL,
  released_at TIMESTAMP NULL DEFAULT NULL,
  release_reason TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_legal_hold_state (hold_state),
  KEY idx_legal_hold_personel (personel_id),
  KEY idx_legal_hold_target (target_domain, target_category, target_record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS legal_hold_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legal_hold_id INT UNSIGNED NOT NULL,
  action VARCHAR(64) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  reason TEXT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_legal_hold_audit_hold (legal_hold_id),
  KEY idx_legal_hold_audit_actor (actor_user_id),
  CONSTRAINT fk_legal_hold_audit_hold FOREIGN KEY (legal_hold_id) REFERENCES legal_holdlar (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS arsiv_erisim_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NULL,
  action ENUM('VIEW', 'DOWNLOAD', 'LIST') NOT NULL,
  route_source VARCHAR(191) NOT NULL,
  metadata_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_arsiv_erisim_actor (actor_user_id),
  KEY idx_arsiv_erisim_personel (personel_id),
  KEY idx_arsiv_erisim_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS retention_imha_talepleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category VARCHAR(64) NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NULL,
  reason TEXT NOT NULL,
  status ENUM('REQUESTED', 'APPROVED', 'REJECTED', 'BLOCKED') NOT NULL DEFAULT 'REQUESTED',
  requested_by INT UNSIGNED NOT NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_by INT UNSIGNED NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  approval_reason TEXT NULL,
  retention_until_snapshot DATE NULL,
  source_identity_snapshot VARCHAR(191) NULL,
  trigger_type_snapshot VARCHAR(32) NULL,
  trigger_date_snapshot DATE NULL,
  source_version_identity_snapshot VARCHAR(191) NULL,
  source_sha256_snapshot CHAR(64) NULL,
  canonical_sube_id INT UNSIGNED NULL,
  period_yil SMALLINT UNSIGNED NULL,
  period_ay TINYINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_retention_imha_status (status),
  KEY idx_retention_imha_record (entity_type, record_id, category),
  KEY idx_retention_imha_personel (personel_id),
  KEY idx_retention_imha_trigger_date (trigger_date_snapshot),
  KEY idx_retention_imha_canonical_sube (canonical_sube_id),
  KEY idx_retention_imha_period (period_yil, period_ay),
  KEY idx_retention_imha_source_sha (source_sha256_snapshot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Disposable DBs that already applied the older CREATE without snapshot cols.
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND COLUMN_NAME = 'trigger_type_snapshot'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE retention_imha_talepleri ADD COLUMN trigger_type_snapshot VARCHAR(32) NULL AFTER source_identity_snapshot',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND COLUMN_NAME = 'trigger_date_snapshot'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE retention_imha_talepleri ADD COLUMN trigger_date_snapshot DATE NULL AFTER trigger_type_snapshot',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND COLUMN_NAME = 'source_version_identity_snapshot'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE retention_imha_talepleri ADD COLUMN source_version_identity_snapshot VARCHAR(191) NULL AFTER trigger_date_snapshot',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND COLUMN_NAME = 'source_sha256_snapshot'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE retention_imha_talepleri ADD COLUMN source_sha256_snapshot CHAR(64) NULL AFTER source_version_identity_snapshot',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND COLUMN_NAME = 'canonical_sube_id'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE retention_imha_talepleri ADD COLUMN canonical_sube_id INT UNSIGNED NULL AFTER source_sha256_snapshot',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND COLUMN_NAME = 'period_yil'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE retention_imha_talepleri ADD COLUMN period_yil SMALLINT UNSIGNED NULL AFTER canonical_sube_id',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND COLUMN_NAME = 'period_ay'
);
SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE retention_imha_talepleri ADD COLUMN period_ay TINYINT UNSIGNED NULL AFTER period_yil',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS retention_imha_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  imha_talep_id INT UNSIGNED NULL,
  category VARCHAR(64) NOT NULL,
  entity_type VARCHAR(64) NOT NULL,
  record_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NULL,
  action VARCHAR(64) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  reason TEXT NULL,
  result_code VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_retention_imha_audit_talep (imha_talep_id),
  KEY idx_retention_imha_audit_record (entity_type, record_id),
  KEY idx_retention_imha_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional FKs (idempotent) — only when target tables exist.
SET @personeller_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
);

SET @users_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
);

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'arsiv_manifestleri'
    AND CONSTRAINT_NAME = 'fk_arsiv_manifest_personel'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @personeller_exists > 0,
  'ALTER TABLE arsiv_manifestleri ADD CONSTRAINT fk_arsiv_manifest_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'arsiv_manifestleri'
    AND CONSTRAINT_NAME = 'fk_arsiv_manifest_created_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @users_exists > 0,
  'ALTER TABLE arsiv_manifestleri ADD CONSTRAINT fk_arsiv_manifest_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'legal_holdlar'
    AND CONSTRAINT_NAME = 'fk_legal_hold_personel'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @personeller_exists > 0,
  'ALTER TABLE legal_holdlar ADD CONSTRAINT fk_legal_hold_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'legal_holdlar'
    AND CONSTRAINT_NAME = 'fk_legal_hold_created_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @users_exists > 0,
  'ALTER TABLE legal_holdlar ADD CONSTRAINT fk_legal_hold_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'arsiv_erisim_auditleri'
    AND CONSTRAINT_NAME = 'fk_arsiv_erisim_actor'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @users_exists > 0,
  'ALTER TABLE arsiv_erisim_auditleri ADD CONSTRAINT fk_arsiv_erisim_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND CONSTRAINT_NAME = 'fk_retention_imha_personel'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @personeller_exists > 0,
  'ALTER TABLE retention_imha_talepleri ADD CONSTRAINT fk_retention_imha_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND CONSTRAINT_NAME = 'fk_retention_imha_requested_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @users_exists > 0,
  'ALTER TABLE retention_imha_talepleri ADD CONSTRAINT fk_retention_imha_requested_by FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
    AND CONSTRAINT_NAME = 'fk_retention_imha_approved_by'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @users_exists > 0,
  'ALTER TABLE retention_imha_talepleri ADD CONSTRAINT fk_retention_imha_approved_by FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
