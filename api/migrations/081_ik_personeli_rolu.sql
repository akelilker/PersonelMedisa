-- 081: IK_PERSONELI canonical role.
--
-- Scope:
--   users.rol ENUM: add IK_PERSONELI next to IK_SORUMLUSU.
--   Schema only: no role remap, no scope rows, no personnel/org/business data.
--
-- Model note: IK_PERSONELI reads the whole organisation (role-derived, no
-- assignment rows) and writes only inside the companies granted in
-- user_sirketler. That write grant needs no new table: user_sirketler already is
-- the company-grant owner (migration 079), so this migration only has to make
-- the role itself storable.
--
-- Fail-closed: aborts when users.rol is missing or not an ENUM, or when the
-- post-widen readback does not match the canonical catalog exactly.
-- Idempotent: re-running on an already widened ENUM is a no-op.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) Structural guard — users.rol must exist as an ENUM.
-- ---------------------------------------------------------------------------
SET @p081_col_ok := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'rol'
    AND DATA_TYPE = 'enum'
);
SET @p081_col_sql := IF(
  @p081_col_ok <> 1,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK081_BLOCKER: users.rol enum column missing''',
  'DO 0'
);
PREPARE p081_stmt FROM @p081_col_sql;
EXECUTE p081_stmt;
DEALLOCATE PREPARE p081_stmt;

-- ---------------------------------------------------------------------------
-- 2) Widen users.rol with IK_PERSONELI (skipped when already present).
--    Purely additive: every existing member of the catalog is preserved, so no
--    assigned role value can be truncated.
-- ---------------------------------------------------------------------------
SET @p081_current_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'rol'
);
SET @p081_needs_widen := IF(@p081_current_type LIKE '%IK\_PERSONELI%', 0, 1);
SET @p081_widen_sql := IF(
  @p081_needs_widen = 1,
  'ALTER TABLE users MODIFY COLUMN rol ENUM(''GENEL_YONETICI'', ''SISTEM_YONETICISI'', ''SUBE_YONETICISI'', ''BOLUM_YONETICISI'', ''BIRIM_AMIRI'', ''IK_SORUMLUSU'', ''IK_PERSONELI'', ''MUHASEBE'', ''PERSONEL'', ''AUTH_SMOKE_READONLY'') NOT NULL',
  'DO 0'
);
PREPARE p081_stmt FROM @p081_widen_sql;
EXECUTE p081_stmt;
DEALLOCATE PREPARE p081_stmt;

-- ---------------------------------------------------------------------------
-- 3) Readback assert — canonical catalog exactly, no truncated role value.
-- ---------------------------------------------------------------------------
SET @p081_readback_type := (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'rol'
);
SET @p081_readback_ok := IF(
  @p081_readback_type = "enum('GENEL_YONETICI','SISTEM_YONETICISI','SUBE_YONETICISI','BOLUM_YONETICISI','BIRIM_AMIRI','IK_SORUMLUSU','IK_PERSONELI','MUHASEBE','PERSONEL','AUTH_SMOKE_READONLY')",
  1,
  0
);
SET @p081_readback_sql := IF(
  @p081_readback_ok = 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK081_BLOCKER: canonical role enum readback failed''',
  'DO 0'
);
PREPARE p081_stmt FROM @p081_readback_sql;
EXECUTE p081_stmt;
DEALLOCATE PREPARE p081_stmt;

SET @p081_blank_rol := (
  SELECT COUNT(*) FROM users WHERE rol = '' OR rol IS NULL
);
SET @p081_blank_sql := IF(
  @p081_blank_rol > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK081_BLOCKER: role truncation detected''',
  'DO 0'
);
PREPARE p081_stmt FROM @p081_blank_sql;
EXECUTE p081_stmt;
DEALLOCATE PREPARE p081_stmt;

-- ---------------------------------------------------------------------------
-- 4) Login access revocation audit.
--
-- Removing a login is the one organisation write that migration 080 did not
-- cover, and it is the one where attribution matters most: the users row must
-- survive so every historical audit actor stays resolvable, which means the act
-- of revoking cannot be reconstructed from the row itself afterwards.
--
-- Append-only like the 080 owners: no PII beyond the username already stored in
-- users, and never any credential material.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_erisim_kaldirma_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_user_id INT UNSIGNED NOT NULL,
  target_username VARCHAR(190) NOT NULL,
  onceki_durum VARCHAR(20) NOT NULL,
  yeni_durum VARCHAR(20) NOT NULL,
  korunan_personel_id INT UNSIGNED NULL,
  temizlenen_scope_satiri INT UNSIGNED NOT NULL DEFAULT 0,
  gerekce VARCHAR(500) NULL,
  actor_user_id INT UNSIGNED NOT NULL,
  request_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ueka_target_created (target_user_id, created_at),
  KEY idx_ueka_actor_created (actor_user_id, created_at),
  CONSTRAINT fk_ueka_target_user FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_ueka_actor_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_ueka_request_hash CHECK (CHAR_LENGTH(request_hash) = 64),
  CONSTRAINT chk_ueka_durum CHECK (yeni_durum = 'PASIF')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- ---------------------------------------------------------------------------
-- 5) Append-only enforcement, matching the 080 audit owners: no application
--    path and no direct connection may rewrite a revocation record.
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_ueka_no_update;
CREATE TRIGGER trg_ueka_no_update
BEFORE UPDATE ON user_erisim_kaldirma_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: user erisim kaldirma audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_ueka_no_delete;
CREATE TRIGGER trg_ueka_no_delete
BEFORE DELETE ON user_erisim_kaldirma_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: user erisim kaldirma audit satiri silinemez';
