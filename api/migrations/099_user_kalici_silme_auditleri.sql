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

-- ---------------------------------------------------------------------------
-- Stable protected-account flag for the safe Kalıcı Sil eligibility.
-- ---------------------------------------------------------------------------
-- `silinmesi_korunur` is the canonical, stable protection anchor. Username
-- uniqueness is NOT treated as person identity: the two protected accounts
-- must be explicitly identified by the migration operator on the same DB
-- connection before applying this file:
--
--   SET @p099_protected_ilker_user_id = <verified current users.id>;
--   SET @p099_protected_serhan_user_id = <verified current users.id>;
--
-- No numeric value is baked into this migration. On a non-empty users table,
-- absent, equal or unknown IDs abort the migration. On a fresh empty schema,
-- the registry remains empty and Kalıcı Sil stays fail-closed until the same
-- verified registration is completed; missing identity evidence never enables
-- deletion.
SET @p099_koruma_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'silinmesi_korunur'
);
SET @p099_koruma_sql := IF(
  @p099_koruma_col = 0,
  'ALTER TABLE users ADD COLUMN silinmesi_korunur TINYINT(1) NOT NULL DEFAULT 0 AFTER durum',
  'DO 0'
);
PREPARE p099_koruma_stmt FROM @p099_koruma_sql;
EXECUTE p099_koruma_stmt;
DEALLOCATE PREPARE p099_koruma_stmt;

CREATE TABLE IF NOT EXISTS user_kalici_silme_korunan_hesaplar (
  protection_key ENUM('ILKER_A', 'SERHAN_KOSE') NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  verified_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (protection_key),
  UNIQUE KEY uq_kskha_user (user_id),
  CONSTRAINT fk_kskha_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @p099_users_count := (SELECT COUNT(*) FROM users);
SET @p099_protected_ids_valid := (
  SELECT COUNT(*) = 2
  FROM users
  WHERE id IN (@p099_protected_ilker_user_id, @p099_protected_serhan_user_id)
);
SET @p099_protection_precondition_sql := IF(
  @p099_users_count = 0
    OR (
      @p099_protected_ilker_user_id IS NOT NULL
      AND @p099_protected_serhan_user_id IS NOT NULL
      AND @p099_protected_ilker_user_id <> @p099_protected_serhan_user_id
      AND @p099_protected_ids_valid = 1
    ),
  'DO 0',
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK099_BLOCKER: verified protected-account IDs are required'''
);
PREPARE p099_protection_precondition_stmt FROM @p099_protection_precondition_sql;
EXECUTE p099_protection_precondition_stmt;
DEALLOCATE PREPARE p099_protection_precondition_stmt;

-- Explicitly operator-verified IDs only; never resolve people by username.
INSERT INTO user_kalici_silme_korunan_hesaplar (protection_key, user_id)
SELECT 'ILKER_A', @p099_protected_ilker_user_id
WHERE @p099_users_count > 0
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), verified_at = CURRENT_TIMESTAMP;

INSERT INTO user_kalici_silme_korunan_hesaplar (protection_key, user_id)
SELECT 'SERHAN_KOSE', @p099_protected_serhan_user_id
WHERE @p099_users_count > 0
ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), verified_at = CURRENT_TIMESTAMP;

UPDATE users u
INNER JOIN user_kalici_silme_korunan_hesaplar p ON p.user_id = u.id
SET u.silinmesi_korunur = 1;

-- ---------------------------------------------------------------------------
-- FK-less user references are not safe around hard delete. These are the
-- current classified writer columns from the migration/source inventory.
-- RESTRICT makes a late writer and the user DELETE mutually safe at InnoDB
-- level; a future unclassified column is rejected by the application owner.
-- Existing orphan data causes this migration to fail rather than silently
-- permitting a hard delete.
-- ---------------------------------------------------------------------------
ALTER TABLE ek_odeme_kesinti
  ADD CONSTRAINT fk_p099_eok_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_p099_eok_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE gunluk_bildirimler
  ADD CONSTRAINT fk_p099_gb_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_p099_gb_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_p099_gb_correction_requested_by FOREIGN KEY (correction_requested_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE legal_holdlar
  ADD CONSTRAINT fk_p099_lh_released_by FOREIGN KEY (released_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE legal_hold_auditleri
  ADD CONSTRAINT fk_p099_lha_actor_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE offline_mutation_idempotency
  ADD CONSTRAINT fk_p099_omi_actor_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE personel_gecici_gorevlendirmeler
  ADD CONSTRAINT fk_p099_pgg_olusturan_user FOREIGN KEY (olusturan_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_p099_pgg_sonlandiran_user FOREIGN KEY (sonlandiran_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE personel_import_runs
  ADD CONSTRAINT fk_p099_pir_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE personel_test_fixture_archive_kayitlari
  ADD CONSTRAINT fk_p099_ptfak_archived FOREIGN KEY (archived_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE personel_test_fixture_siniflandirmalari
  ADD CONSTRAINT fk_p099_ptfs_classified FOREIGN KEY (classified_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_p099_ptfs_iptal FOREIGN KEY (iptal_edildi_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;

ALTER TABLE retention_imha_auditleri
  ADD CONSTRAINT fk_p099_ria_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT;
