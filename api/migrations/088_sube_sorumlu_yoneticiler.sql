-- 088: durable branch manager responsibility assignment (sube_sorumlu_yoneticiler).
--
-- Scope (additive, schema only):
--   Durable business fact: which users are responsible managers of a branch.
--   Independent of user_subeler (access/visibility/SGK formal scope).
--   Independent of personel.sube_id / calisma_lokasyonu_id.
--   Independent of users.rol (no multi-role; no forced SUBE_YONETICISI demotion).
--
-- Cardinality:
--   A branch MAY have zero managers (no rows).
--   A branch MAY have one or many managers.
--   A user MAY manage multiple branches.
--
-- NO DATA WRITES. No backfill. Idempotent re-run safe.
-- MariaDB 10.6 / 11.4 + PHP 7.4 PDO compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

SET @p088_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'subeler')
);
SET @p088_sql := IF(
  @p088_tables <> 2,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK088_BLOCKER: users/subeler owner tables missing''',
  'DO 0'
);
PREPARE p088_stmt FROM @p088_sql;
EXECUTE p088_stmt;
DEALLOCATE PREPARE p088_stmt;

CREATE TABLE IF NOT EXISTS sube_sorumlu_yoneticiler (
  sube_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (sube_id, user_id),
  KEY idx_sube_sorumlu_yonetici_user (user_id),
  CONSTRAINT fk_sube_sorumlu_yonetici_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE CASCADE,
  CONSTRAINT fk_sube_sorumlu_yonetici_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drift guard: an existing table must match the intended owner shape.
SET @p088_bad := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sube_sorumlu_yoneticiler'
    AND (
      (COLUMN_NAME = 'sube_id' AND NOT (DATA_TYPE = 'int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'NO'))
      OR (COLUMN_NAME = 'user_id' AND NOT (DATA_TYPE = 'int' AND COLUMN_TYPE LIKE '%unsigned%' AND IS_NULLABLE = 'NO'))
    )
);
SET @p088_sql := IF(
  @p088_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK088_BLOCKER: sube_sorumlu_yoneticiler column drift''',
  'DO 0'
);
PREPARE p088_stmt FROM @p088_sql;
EXECUTE p088_stmt;
DEALLOCATE PREPARE p088_stmt;

COMMIT;
