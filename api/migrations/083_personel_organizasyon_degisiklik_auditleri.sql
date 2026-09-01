-- 083: append-only audit owner for personnel organisation field changes
-- (gorev/unvan, departman, bolum, birim, pozisyon, sgk_isveren, calisma_lokasyonu).
--
-- Scope (additive, schema only):
--   personel_organizasyon_degisiklik_auditleri
--
-- Permanent branch moves remain on personel_sube_degisiklik_auditleri (080).
-- User role/access changes remain on user_erisim_degisiklik_auditleri (082).
--
-- NO DATA WRITES. Append-only triggers on UPDATE/DELETE.
-- Fail-closed when personeller table missing. Idempotent re-run safe.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @p083_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'personeller')
);
SET @p083_sql := IF(
  @p083_tables <> 2,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK083_BLOCKER: audit owner tables missing''',
  'DO 0'
);
PREPARE p083_stmt FROM @p083_sql; EXECUTE p083_stmt; DEALLOCATE PREPARE p083_stmt;

CREATE TABLE IF NOT EXISTS personel_organizasyon_degisiklik_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  olay_tipi VARCHAR(64) NOT NULL,
  degisen_alanlar JSON NOT NULL,
  eski_degerler JSON NOT NULL,
  yeni_degerler JSON NOT NULL,
  gerekce VARCHAR(500) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  request_hash CHAR(64) NOT NULL,
  idempotency_key VARCHAR(128) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_poda_personel_created (personel_id, created_at),
  KEY idx_poda_actor_created (actor_user_id, created_at),
  KEY idx_poda_request_hash (request_hash),
  CONSTRAINT fk_poda_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_poda_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT chk_poda_request_hash CHECK (request_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_poda_no_update;
DROP TRIGGER IF EXISTS trg_poda_no_delete;

CREATE TRIGGER trg_poda_no_update
BEFORE UPDATE ON personel_organizasyon_degisiklik_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'personel_organizasyon_degisiklik_auditleri is append-only';

CREATE TRIGGER trg_poda_no_delete
BEFORE DELETE ON personel_organizasyon_degisiklik_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'personel_organizasyon_degisiklik_auditleri is append-only';
