-- 085: append-only audit owner for gunluk_bildirim business corrections.
--
-- Scope (additive, schema only):
--   gunluk_bildirim_duzeltme_auditleri
--
-- Captures old→new business field transitions on content update / iptal.
-- request-correction (state-only) is not an audit transition.
--
-- NO DATA WRITES. No backfill. Append-only triggers on UPDATE/DELETE.
-- Fail-closed when gunluk_bildirimler missing. Idempotent re-run safe.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @p085_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users', 'personeller', 'gunluk_bildirimler')
);
SET @p085_sql := IF(
  @p085_tables <> 3,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK085_BLOCKER: audit owner tables missing''',
  'DO 0'
);
PREPARE p085_stmt FROM @p085_sql; EXECUTE p085_stmt; DEALLOCATE PREPARE p085_stmt;

CREATE TABLE IF NOT EXISTS gunluk_bildirim_duzeltme_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  gunluk_bildirim_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  olay_tipi VARCHAR(32) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  correction_reason TEXT NULL,
  eski_bildirim_turu VARCHAR(32) NOT NULL,
  yeni_bildirim_turu VARCHAR(32) NULL,
  eski_alanlar JSON NOT NULL,
  yeni_alanlar JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gbda_bildirim_created (gunluk_bildirim_id, created_at),
  KEY idx_gbda_personel_tarih (personel_id, tarih, created_at),
  KEY idx_gbda_actor_created (actor_user_id, created_at),
  CONSTRAINT fk_gbda_bildirim FOREIGN KEY (gunluk_bildirim_id) REFERENCES gunluk_bildirimler (id),
  CONSTRAINT fk_gbda_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_gbda_actor FOREIGN KEY (actor_user_id) REFERENCES users (id),
  CONSTRAINT chk_gbda_olay_tipi CHECK (olay_tipi IN ('DUZELTME', 'IPTAL'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_gbda_no_update;
DROP TRIGGER IF EXISTS trg_gbda_no_delete;

CREATE TRIGGER trg_gbda_no_update
BEFORE UPDATE ON gunluk_bildirim_duzeltme_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gunluk_bildirim_duzeltme_auditleri is append-only';

CREATE TRIGGER trg_gbda_no_delete
BEFORE DELETE ON gunluk_bildirim_duzeltme_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gunluk_bildirim_duzeltme_auditleri is append-only';
