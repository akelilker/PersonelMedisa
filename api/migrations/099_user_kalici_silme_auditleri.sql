-- 099: append-only audit owner for the safe Kalıcı Sil (hard delete) path.
--
-- Scope (additive, schema only):
--   user_kalici_silme_auditleri — immutable evidence that a users row was
--   physically deleted via POST /yonetim/kullanicilar/{id}/kalici-sil.
--
-- Why this table exists. Every prior user-scoped audit table (081, 082, 080,
-- 068) pins actor_user_id and target_user_id to users.id with RESTRICT (or SET
-- NULL in 068) precisely because a users row was never meant to be deleted:
-- the audit chain depends on the row surviving. A hard delete needs the
-- opposite guarantee — the evidence must outlive the users row it records —
-- so the target columns here carry no foreign key and are captured as data
-- (id + username + role + status + ad), immune to username reuse.
--
-- actor_user_id stays RESTRICT on purpose: the acting account is always
-- GENEL_YONETICI, which the application refuses to hard-delete, so the actor
-- attribution is permanently resolvable and cannot be silently nulled.
--
-- Append-only is enforced at the database: BEFORE UPDATE / BEFORE DELETE
-- triggers SIGNAL, matching 081 / 082. The table is only ever written by
-- KullaniciKaliciSilService, inside the same transaction as the user DELETE.
--
-- Fail-closed: aborts when the users table is missing. Idempotent:
-- CREATE TABLE IF NOT EXISTS plus DROP TRIGGER IF EXISTS.
-- MariaDB 10.6 / 11.4 + PHP 7.4 PDO compatible.
--
-- NO DATA WRITES and NO backfill: historical deletions were never tracked, so
-- no fabricated audit rows are inserted.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Structural guard — actor resolves through users.
-- ---------------------------------------------------------------------------
SET @p099_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users')
);
SET @p099_sql := IF(
  @p099_tables <> 1,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK099_BLOCKER: users table missing''',
  'DO 0'
);
PREPARE p099_stmt FROM @p099_sql; EXECUTE p099_stmt; DEALLOCATE PREPARE p099_stmt;

-- ---------------------------------------------------------------------------
-- Hard-delete evidence. target_user_id / target_username / target_ad_soyad /
-- target_rol / korunan_personel_id are data (no FK) so the record survives the
-- deleted user; actor_user_id is FK RESTRICT (actors are always protected).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_kalici_silme_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_user_id INT UNSIGNED NOT NULL,
  target_username VARCHAR(190) NOT NULL,
  target_ad_soyad VARCHAR(160) NOT NULL,
  target_rol VARCHAR(40) NOT NULL,
  onceki_durum VARCHAR(20) NOT NULL,
  korunan_personel_id INT UNSIGNED NULL,
  temizlenen_scope_satiri INT UNSIGNED NOT NULL DEFAULT 0,
  gerekce VARCHAR(500) NOT NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  request_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ksa_target_user (target_user_id, created_at),
  KEY idx_ksa_actor_created (actor_user_id, created_at),
  KEY idx_ksa_request_hash (request_hash),
  CONSTRAINT fk_ksa_actor_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_ksa_request_hash CHECK (CHAR_LENGTH(request_hash) = 64),
  CONSTRAINT chk_ksa_gerekce CHECK (CHAR_LENGTH(gerekce) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Append-only enforcement. No application path may update or delete these
-- rows, and neither may an operator with a direct connection.
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_ksa_no_update;
CREATE TRIGGER trg_ksa_no_update
BEFORE UPDATE ON user_kalici_silme_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'KALICI_SIL_AUDIT_IMMUTABLE: kalici silme audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_ksa_no_delete;
CREATE TRIGGER trg_ksa_no_delete
BEFORE DELETE ON user_kalici_silme_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'KALICI_SIL_AUDIT_IMMUTABLE: kalici silme audit satiri silinemez';
