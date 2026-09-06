-- 087: branch-owned accounting visibility ACL (sube_muhasebe_yetkilileri).
--
-- Scope (additive, schema only):
--   Relation presence on a branch = "Verileri Sadece İlgili Muhasebe Yetkilileri
--   Görebilsin" is ENABLED for that branch.
--   No rows for a branch = restriction DISABLED (existing MUHASEBE grant model).
--
-- Applies ONLY to role=MUHASEBE. GENEL_YONETICI / İK / other roles are unchanged.
--
-- NO DATA WRITES. No backfill. Idempotent re-run safe.
-- MariaDB 10.6 / 11.4 + PHP 7.4 PDO compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

SET @p087_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'subeler')
);
SET @p087_sql := IF(
  @p087_tables <> 2,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK087_BLOCKER: users/subeler owner tables missing''',
  'DO 0'
);
PREPARE p087_stmt FROM @p087_sql;
EXECUTE p087_stmt;
DEALLOCATE PREPARE p087_stmt;

CREATE TABLE IF NOT EXISTS sube_muhasebe_yetkilileri (
  sube_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (sube_id, user_id),
  KEY idx_sube_muhasebe_user (user_id),
  CONSTRAINT fk_sube_muhasebe_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE CASCADE,
  CONSTRAINT fk_sube_muhasebe_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drift guard: an existing table must match the intended owner shape.
SET @p087_bad := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sube_muhasebe_yetkilileri'
    AND (
      (COLUMN_NAME = 'sube_id' AND NOT (DATA_TYPE = 'int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'user_id' AND NOT (DATA_TYPE = 'int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'NO'))
    )
);
SET @p087_sql := IF(
  @p087_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK087_BLOCKER: sube_muhasebe_yetkilileri column drift''',
  'DO 0'
);
PREPARE p087_stmt FROM @p087_sql;
EXECUTE p087_stmt;
DEALLOCATE PREPARE p087_stmt;

COMMIT;
