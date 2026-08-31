-- 080: append-only audit owners for the three organisation write paths.
--
-- Scope (additive, schema only):
--   1) personel_sube_degisiklik_auditleri — permanent personnel branch moves.
--   2) sube_olusturma_auditleri           — branch creation.
--   3) user_org_scope_auditleri           — user organisation scope replacement.
--
-- These are domain-specific leaves in the existing *_auditleri family, not a
-- general purpose event log. Each table records exactly the organisation values
-- its own writer owns, so a reader never has to guess which producer wrote a row.
--
-- NO DATA WRITES. This migration inserts, updates and deletes no business row:
--   no company, no branch, no personnel move, no user scope copy, no backfill.
--
-- No personnel or credential PII is stored. Names, national ids, phone numbers,
-- IBANs and password material are deliberately absent; a row carries ids, the
-- free-text justification the actor typed, and hashes.
--
-- Append-only is enforced at the database, not only in the application: every
-- table gets BEFORE UPDATE / BEFORE DELETE triggers that SIGNAL, matching the
-- payroll audit precedent in 021/024/061. There is deliberately no retention
-- destroy gate here — organisation history is not part of a personnel imha
-- category, so these rows are unconditionally immutable.
--
-- Fail-closed: aborts when an owner table is missing. Idempotent: CREATE TABLE
-- IF NOT EXISTS plus DROP TRIGGER IF EXISTS, so a re-run and a resume after an
-- interrupted run are both no-ops.
-- MariaDB 10.6 / 11.4 + PHP 7.4 PDO compatible (no routines, single-statement
-- trigger bodies).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Structural guard — every table these audits reference must already exist.
-- ---------------------------------------------------------------------------
SET @p080_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'subeler', 'personeller')
);
SET @p080_sql := IF(
  @p080_tables <> 3,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK080_BLOCKER: audit owner tables missing''',
  'DO 0'
);
PREPARE stmt FROM @p080_sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 1) Permanent personnel branch change.
--
-- korunan_* columns are not redundant copies: they are the proof that the move
-- changed the branch and nothing else. A reader can tell from the audit row
-- alone that the work location and the SGK employer survived the write.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personel_sube_degisiklik_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  onceki_sube_id INT UNSIGNED NULL,
  yeni_sube_id INT UNSIGNED NOT NULL,
  korunan_calisma_lokasyonu_id INT UNSIGNED NULL,
  korunan_sgk_isveren_id INT UNSIGNED NULL,
  gerekce VARCHAR(500) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  request_hash CHAR(64) NOT NULL,
  idempotency_key VARCHAR(128) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_psda_personel_created (personel_id, created_at),
  KEY idx_psda_actor_created (actor_user_id, created_at),
  KEY idx_psda_request_hash (request_hash),
  CONSTRAINT fk_psda_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_psda_onceki_sube FOREIGN KEY (onceki_sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_psda_yeni_sube FOREIGN KEY (yeni_sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_psda_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT chk_psda_sube_changed CHECK (onceki_sube_id IS NULL OR onceki_sube_id <> yeni_sube_id),
  CONSTRAINT chk_psda_request_hash CHECK (request_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) Branch creation.
--
-- departman_ids keeps the canonical sorted id list as text so the audit stays
-- readable without a join, and departman_ids_hash makes tampering detectable
-- even if the list is later truncated by a longer assignment.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sube_olusturma_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  sirket_id INT UNSIGNED NULL,
  kod VARCHAR(32) NOT NULL,
  ad VARCHAR(120) NOT NULL,
  durum VARCHAR(16) NOT NULL,
  sgk_isveren_id INT UNSIGNED NULL,
  departman_ids VARCHAR(512) NOT NULL,
  departman_ids_hash CHAR(64) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  request_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_soa_sube (sube_id),
  KEY idx_soa_actor_created (actor_user_id, created_at),
  KEY idx_soa_request_hash (request_hash),
  CONSTRAINT fk_soa_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_soa_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT chk_soa_durum CHECK (durum IN ('AKTIF', 'PASIF')),
  CONSTRAINT chk_soa_request_hash CHECK (request_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_soa_departman_hash CHECK (departman_ids_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) User organisation scope replacement.
--
-- One row per changed scope axis, so a request that touches only branches does
-- not produce three rows implying the company and SGK sets were rewritten too.
-- The id sets are stored ascending and comma separated; that canonical form is
-- what makes a before/after diff comparable across rows.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_org_scope_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_user_id INT UNSIGNED NOT NULL,
  scope_turu VARCHAR(16) NOT NULL,
  onceki_ids VARCHAR(2048) NOT NULL,
  yeni_ids VARCHAR(2048) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  gerekce VARCHAR(500) NULL,
  request_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_uosa_target_created (target_user_id, created_at),
  KEY idx_uosa_actor_created (actor_user_id, created_at),
  KEY idx_uosa_request_hash (request_hash),
  CONSTRAINT fk_uosa_target FOREIGN KEY (target_user_id) REFERENCES users (id),
  CONSTRAINT fk_uosa_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT chk_uosa_scope_turu CHECK (scope_turu IN ('SUBE', 'SIRKET', 'SGK_ISVEREN')),
  CONSTRAINT chk_uosa_changed CHECK (onceki_ids <> yeni_ids),
  CONSTRAINT chk_uosa_request_hash CHECK (request_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Append-only enforcement. No application path may update or delete these rows,
-- and neither may an operator with a direct connection.
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_psda_no_update;
CREATE TRIGGER trg_psda_no_update
BEFORE UPDATE ON personel_sube_degisiklik_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: personel sube degisiklik audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_psda_no_delete;
CREATE TRIGGER trg_psda_no_delete
BEFORE DELETE ON personel_sube_degisiklik_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: personel sube degisiklik audit satiri silinemez';

DROP TRIGGER IF EXISTS trg_soa_no_update;
CREATE TRIGGER trg_soa_no_update
BEFORE UPDATE ON sube_olusturma_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: sube olusturma audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_soa_no_delete;
CREATE TRIGGER trg_soa_no_delete
BEFORE DELETE ON sube_olusturma_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: sube olusturma audit satiri silinemez';

DROP TRIGGER IF EXISTS trg_uosa_no_update;
CREATE TRIGGER trg_uosa_no_update
BEFORE UPDATE ON user_org_scope_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: user org scope audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_uosa_no_delete;
CREATE TRIGGER trg_uosa_no_delete
BEFORE DELETE ON user_org_scope_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: user org scope audit satiri silinemez';
