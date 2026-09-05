-- 086: append-only audit owner for HISTORICAL_EXIT_DATE_CORRECTION.
--
-- Scope (additive, schema only):
--   personel_historical_exit_date_correction_auditleri
--
-- Captures durable old→new exit-date correction provenance (surec identity preserved).
-- NO DATA WRITES. No backfill. Append-only triggers on UPDATE/DELETE.
-- Fail-closed when personeller/surecler/users missing. Idempotent re-run safe.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @p086_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'personeller', 'surecler')
);
SET @p086_sql := IF(
  @p086_tables <> 3,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK086_BLOCKER: audit owner tables missing''',
  'DO 0'
);
PREPARE p086_stmt FROM @p086_sql; EXECUTE p086_stmt; DEALLOCATE PREPARE p086_stmt;

CREATE TABLE IF NOT EXISTS personel_historical_exit_date_correction_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operation_type VARCHAR(64) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  mutation_id VARCHAR(191) NULL,
  personel_id INT UNSIGNED NOT NULL,
  surec_id INT UNSIGNED NOT NULL,
  old_baslangic_tarihi DATE NOT NULL,
  old_bitis_tarihi DATE NOT NULL,
  new_baslangic_tarihi DATE NOT NULL,
  new_bitis_tarihi DATE NOT NULL,
  old_aciklama TEXT NULL,
  reason TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_phedca_personel_created (personel_id, created_at),
  KEY idx_phedca_surec_created (surec_id, created_at),
  KEY idx_phedca_mutation (mutation_id),
  KEY idx_phedca_actor_created (actor_user_id, created_at),
  CONSTRAINT fk_phedca_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_phedca_surec FOREIGN KEY (surec_id) REFERENCES surecler (id),
  CONSTRAINT fk_phedca_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT chk_phedca_operation_type CHECK (operation_type = 'HISTORICAL_EXIT_DATE_CORRECTION')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_phedca_no_update;
DROP TRIGGER IF EXISTS trg_phedca_no_delete;

CREATE TRIGGER trg_phedca_no_update
BEFORE UPDATE ON personel_historical_exit_date_correction_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'personel_historical_exit_date_correction_auditleri is append-only';

CREATE TRIGGER trg_phedca_no_delete
BEFORE DELETE ON personel_historical_exit_date_correction_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'personel_historical_exit_date_correction_auditleri is append-only';
