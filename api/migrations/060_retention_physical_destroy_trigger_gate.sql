-- 060: Retention-only, transaction-scoped physical DELETE gate for immutable payroll/SGK tables.
-- Additive. Does NOT weaken normal runtime DELETE (still SIGNAL without open gate).
-- Does NOT touch migration 024/036 trigger files; replaces DELETE triggers with gated variants.
-- Feature flag / approval / legal-hold / plan gates remain application-enforced in PhysicalDestructionService.
-- MariaDB 10.6/11.4 compatible. No production seed / backfill.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS retention_physical_destroy_gates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  connection_id BIGINT UNSIGNED NOT NULL,
  execution_id INT UNSIGNED NOT NULL,
  imha_talep_id INT UNSIGNED NOT NULL,
  category VARCHAR(64) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  opened_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_rpdg_connection (connection_id),
  KEY idx_rpdg_execution (execution_id),
  KEY idx_rpdg_category (category),
  CONSTRAINT chk_rpdg_token_hash CHECK (token_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_rpdg_category CHECK (category IN ('BORDRO', 'SGK_EKSIK_GUN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional FKs when Pack 2 evidence table exists.
SET @exec_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retention_imha_executionlari'
);
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_physical_destroy_gates'
    AND CONSTRAINT_NAME = 'fk_rpdg_execution'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @exec_exists > 0,
  'ALTER TABLE retention_physical_destroy_gates
     ADD CONSTRAINT fk_rpdg_execution
     FOREIGN KEY (execution_id) REFERENCES retention_imha_executionlari (id)
     ON DELETE CASCADE ON UPDATE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @talep_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retention_imha_talepleri'
);
SET @fk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_physical_destroy_gates'
    AND CONSTRAINT_NAME = 'fk_rpdg_talep'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql := IF(
  @fk_exists = 0 AND @talep_exists > 0,
  'ALTER TABLE retention_physical_destroy_gates
     ADD CONSTRAINT fk_rpdg_talep
     FOREIGN KEY (imha_talep_id) REFERENCES retention_imha_talepleri (id)
     ON DELETE RESTRICT ON UPDATE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- --- BORDRO run-leaf DELETE triggers (gated) ---
DROP TRIGGER IF EXISTS trg_mha_no_delete;
CREATE TRIGGER trg_mha_no_delete
BEFORE DELETE ON maas_hesaplama_adaylari
FOR EACH ROW
IF NOT EXISTS (
  SELECT 1
  FROM retention_physical_destroy_gates g
  INNER JOIN retention_imha_executionlari e ON e.id = g.execution_id
  WHERE g.connection_id = CONNECTION_ID()
    AND g.category = 'BORDRO'
    AND e.execution_state = 'PREPARED'
) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: aday satiri silinemez';
END IF;

DROP TRIGGER IF EXISTS trg_mhak_no_delete;
CREATE TRIGGER trg_mhak_no_delete
BEFORE DELETE ON maas_hesaplama_aday_kalemleri
FOR EACH ROW
IF NOT EXISTS (
  SELECT 1
  FROM retention_physical_destroy_gates g
  INNER JOIN retention_imha_executionlari e ON e.id = g.execution_id
  WHERE g.connection_id = CONNECTION_ID()
    AND g.category = 'BORDRO'
    AND e.execution_state = 'PREPARED'
) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: kalem satiri silinemez';
END IF;

DROP TRIGGER IF EXISTS trg_mhc_no_delete;
CREATE TRIGGER trg_mhc_no_delete
BEFORE DELETE ON maas_hesaplama_calistirmalari
FOR EACH ROW
IF NOT EXISTS (
  SELECT 1
  FROM retention_physical_destroy_gates g
  INNER JOIN retention_imha_executionlari e ON e.id = g.execution_id
  WHERE g.connection_id = CONNECTION_ID()
    AND g.category = 'BORDRO'
    AND e.execution_state = 'PREPARED'
) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: calistirma satiri silinemez';
END IF;

DROP TRIGGER IF EXISTS trg_mhaud_no_delete;
CREATE TRIGGER trg_mhaud_no_delete
BEFORE DELETE ON maas_hesaplama_auditleri
FOR EACH ROW
IF NOT EXISTS (
  SELECT 1
  FROM retention_physical_destroy_gates g
  INNER JOIN retention_imha_executionlari e ON e.id = g.execution_id
  WHERE g.connection_id = CONNECTION_ID()
    AND g.category = 'BORDRO'
    AND e.execution_state = 'PREPARED'
) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: hesap audit satiri silinemez';
END IF;

-- --- SGK nested DELETE triggers (gated) ---
DROP TRIGGER IF EXISTS trg_mhss_no_delete;
CREATE TRIGGER trg_mhss_no_delete
BEFORE DELETE ON maas_hesaplama_sgk_snapshotlari
FOR EACH ROW
IF NOT EXISTS (
  SELECT 1
  FROM retention_physical_destroy_gates g
  INNER JOIN retention_imha_executionlari e ON e.id = g.execution_id
  WHERE g.connection_id = CONNECTION_ID()
    AND g.category = 'SGK_EKSIK_GUN'
    AND e.execution_state = 'PREPARED'
) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SGK_SNAPSHOT_IMMUTABLE: SGK snapshot satiri silinemez';
END IF;

DROP TRIGGER IF EXISTS trg_sgk_ha_no_delete;
CREATE TRIGGER trg_sgk_ha_no_delete
BEFORE DELETE ON sgk_hesap_auditleri
FOR EACH ROW
IF NOT EXISTS (
  SELECT 1
  FROM retention_physical_destroy_gates g
  INNER JOIN retention_imha_executionlari e ON e.id = g.execution_id
  WHERE g.connection_id = CONNECTION_ID()
    AND g.category = 'SGK_EKSIK_GUN'
    AND e.execution_state = 'PREPARED'
) THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SGK_AUDIT_IMMUTABLE: audit satiri silinemez';
END IF;
