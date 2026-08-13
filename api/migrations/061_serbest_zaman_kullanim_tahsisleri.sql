-- 061: Serbest zaman KULLANIM→OLUSUM entitlement allocation ledger (Pack 4A).
-- Additive. NO DATA BACKFILL. NO production seed. NO mutation of serbest_zaman_events.
-- Append-only delta ledger; normal UPDATE/DELETE forbidden (triggers).
-- Retention-gated DELETE for Pack 4B: category SERBEST_ZAMAN is added to
-- retention_physical_destroy_gates CHECK; Pack 4B will replace DELETE trigger
-- with gated variant. Pack 4A keeps hard DELETE block (safe).
-- MariaDB 10.6 / 11.4 compatible. PHP 7.4 compatible.
-- Event FK types match 029 (INT UNSIGNED), not BIGINT.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE serbest_zaman_kullanim_tahsisleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  kullanim_event_id INT UNSIGNED NOT NULL,
  olusum_event_id INT UNSIGNED NOT NULL,
  kaynak_event_id INT UNSIGNED NOT NULL,
  tahsis_delta_dakika INT NOT NULL,
  politika_kodu VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_szkt_kaynak_olusum (kaynak_event_id, olusum_event_id),
  KEY idx_szkt_kullanim (kullanim_event_id),
  KEY idx_szkt_olusum (olusum_event_id),
  KEY idx_szkt_personel (personel_id),
  KEY idx_szkt_kaynak (kaynak_event_id),
  CONSTRAINT fk_szkt_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT,
  CONSTRAINT fk_szkt_kullanim FOREIGN KEY (kullanim_event_id) REFERENCES serbest_zaman_events (id) ON DELETE RESTRICT,
  CONSTRAINT fk_szkt_olusum FOREIGN KEY (olusum_event_id) REFERENCES serbest_zaman_events (id) ON DELETE RESTRICT,
  CONSTRAINT fk_szkt_kaynak FOREIGN KEY (kaynak_event_id) REFERENCES serbest_zaman_events (id) ON DELETE RESTRICT,
  CONSTRAINT chk_szkt_delta_nonzero CHECK (tahsis_delta_dakika <> 0),
  CONSTRAINT chk_szkt_politika CHECK (
    politika_kodu IN ('EARLIEST_EXPIRY_FIRST_V1', 'REVERSE_EARLIEST_EXPIRY_FIRST_V1')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extend retention destroy gate category CHECK for Pack 4B SERBEST_ZAMAN (additive).
SET @gate_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retention_physical_destroy_gates'
);
SET @chk_exists := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'retention_physical_destroy_gates'
    AND CONSTRAINT_NAME = 'chk_rpdg_category'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql := IF(
  @gate_exists > 0 AND @chk_exists > 0,
  'ALTER TABLE retention_physical_destroy_gates DROP CONSTRAINT chk_rpdg_category',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
  @gate_exists > 0,
  'ALTER TABLE retention_physical_destroy_gates ADD CONSTRAINT chk_rpdg_category CHECK (category IN (''BORDRO'', ''SGK_EKSIK_GUN'', ''SERBEST_ZAMAN''))',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Append-only: UPDATE always forbidden.
DROP TRIGGER IF EXISTS trg_szkt_no_update;
CREATE TRIGGER trg_szkt_no_update
BEFORE UPDATE ON serbest_zaman_kullanim_tahsisleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE: tahsis satiri guncellenemez';

-- Append-only: DELETE forbidden in Pack 4A (Pack 4B replaces with retention-gated trigger).
DROP TRIGGER IF EXISTS trg_szkt_no_delete;
CREATE TRIGGER trg_szkt_no_delete
BEFORE DELETE ON serbest_zaman_kullanim_tahsisleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE: tahsis satiri silinemez';
