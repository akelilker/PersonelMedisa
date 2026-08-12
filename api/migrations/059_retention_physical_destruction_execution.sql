-- 059: Retention physical destruction execution evidence (additive).
-- Immutable per-request execution proof. No seed, no backfill, no production data mutation.
-- MariaDB 10.6/11.4 compatible. PHP 7.4 compatible consumers.
-- Target source tables intentionally have NO FK here (source may be physically destroyed).

CREATE TABLE IF NOT EXISTS retention_imha_executionlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  imha_talep_id INT UNSIGNED NOT NULL,
  handler_version VARCHAR(64) NOT NULL,
  execution_mode VARCHAR(64) NOT NULL,
  plan_hash CHAR(64) NOT NULL,
  source_version_identity_snapshot VARCHAR(191) NOT NULL,
  source_sha256_snapshot CHAR(64) NULL,
  execution_nonce CHAR(64) NOT NULL,
  result_code VARCHAR(64) NOT NULL,
  result_summary_json LONGTEXT NULL,
  execution_state ENUM('PREPARED', 'EXECUTED', 'FAILED') NOT NULL DEFAULT 'PREPARED',
  executed_by INT UNSIGNED NOT NULL,
  prepared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  executed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_retention_imha_execution_talep (imha_talep_id),
  UNIQUE KEY uq_retention_imha_execution_nonce (execution_nonce),
  KEY idx_retention_imha_execution_state (execution_state),
  KEY idx_retention_imha_execution_plan (plan_hash),
  KEY idx_retention_imha_execution_result (result_code),
  CONSTRAINT chk_retention_imha_execution_plan_hash CHECK (plan_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_retention_imha_execution_nonce CHECK (execution_nonce REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional FKs (idempotent) — only when target tables exist.
SET @users_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
);

SET @talepler_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_talepleri'
);

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_executionlari'
    AND CONSTRAINT_NAME = 'fk_retention_imha_execution_talep'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @talepler_exists > 0,
  'ALTER TABLE retention_imha_executionlari ADD CONSTRAINT fk_retention_imha_execution_talep FOREIGN KEY (imha_talep_id) REFERENCES retention_imha_talepleri (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_imha_executionlari'
    AND CONSTRAINT_NAME = 'fk_retention_imha_execution_user'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @users_exists > 0,
  'ALTER TABLE retention_imha_executionlari ADD CONSTRAINT fk_retention_imha_execution_user FOREIGN KEY (executed_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
