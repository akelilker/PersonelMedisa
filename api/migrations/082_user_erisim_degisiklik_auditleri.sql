-- 082: append-only audit owner for security-impacting user access changes.
--
-- Scope (additive, schema only):
--   user_erisim_degisiklik_auditleri — status, role, username and personnel
--   binding changes performed by PUT /yonetim/kullanicilar/{id}.
--
-- Why this table exists. Migration 080 audits the three organisation write
-- paths and 081 audits login revocation, but the update owner could still flip
-- durum, rol, username and personel_id with no attributable record. Revocation
-- was auditable while restoration was not, so an account could be brought back
-- to life — with a new role and a new username — and leave nothing behind.
--
-- This is the sibling of user_erisim_kaldirma_auditleri, not a replacement.
-- 081 keeps owning revocation and its rows are never read, rewritten or
-- migrated by this file; the two tables are read together to reconstruct an
-- account's access history.
--
-- Event model. One request produces at most one row. When several axes move in
-- the same request the row is COMBINED_ACCESS_CHANGE and carries every
-- before/after pair, so a reader never has to correlate sibling rows or guess
-- whether two rows were one action. A single-axis change is typed by its axis,
-- and a lone PASIF -> AKTIF transition is ACCESS_RESTORE because reactivation
-- is the event a reviewer actually looks for.
--
-- NO DATA WRITES. This migration inserts, updates and deletes no business row:
-- no user, no role, no binding, no backfill of historical changes.
--
-- No credential material is stored. Password hashes, invitation tokens and
-- session values are deliberately absent; a row carries ids, the role and
-- status labels, the username already stored in users, and a request hash.
--
-- Append-only is enforced at the database, not only in the application: BEFORE
-- UPDATE / BEFORE DELETE triggers SIGNAL, matching 080 and 081.
--
-- Fail-closed: aborts when the users table is missing. Idempotent: CREATE TABLE
-- IF NOT EXISTS plus DROP TRIGGER IF EXISTS, so a re-run and a resume after an
-- interrupted run are both no-ops.
-- MariaDB 10.6 / 11.4 + PHP 7.4 PDO compatible (no routines, single-statement
-- trigger bodies).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Structural guard — the actor and target both resolve through users.
-- ---------------------------------------------------------------------------
SET @p082_tables := (
  SELECT COUNT(*)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('users')
);
SET @p082_sql := IF(
  @p082_tables <> 1,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK082_BLOCKER: users table missing''',
  'DO 0'
);
PREPARE p082_stmt FROM @p082_sql; EXECUTE p082_stmt; DEALLOCATE PREPARE p082_stmt;

-- ---------------------------------------------------------------------------
-- Security-impacting access change.
--
-- Every before/after column is nullable because an axis that did not move is
-- recorded as NULL/NULL rather than as a copy of itself: that keeps "this
-- request changed the role" readable straight off the row. chk_ueda_changed
-- then refuses a row where nothing moved at all, so a no-op update can never
-- manufacture audit noise.
--
-- eski_personel_id / yeni_personel_id carry no foreign key on purpose. The
-- history has to survive a personnel record being archived or removed; an FK
-- here would either block that or, with a cascade, silently rewrite history.
-- actor and target are different: users rows are never deleted, so RESTRICT
-- keeps both attributions permanently resolvable.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_erisim_degisiklik_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(32) NOT NULL,
  eski_durum VARCHAR(20) NULL,
  yeni_durum VARCHAR(20) NULL,
  eski_rol VARCHAR(40) NULL,
  yeni_rol VARCHAR(40) NULL,
  eski_username VARCHAR(190) NULL,
  yeni_username VARCHAR(190) NULL,
  eski_personel_id INT UNSIGNED NULL,
  yeni_personel_id INT UNSIGNED NULL,
  gerekce VARCHAR(500) NULL,
  request_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ueda_target_created (target_user_id, created_at),
  KEY idx_ueda_actor_created (actor_user_id, created_at),
  KEY idx_ueda_event_created (event_type, created_at),
  KEY idx_ueda_request_hash (request_hash),
  CONSTRAINT fk_ueda_target_user FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_ueda_actor_user FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_ueda_event_type CHECK (event_type IN (
    'ACCESS_RESTORE',
    'STATUS_CHANGE',
    'ROLE_CHANGE',
    'USERNAME_CHANGE',
    'PERSONEL_BINDING_CHANGE',
    'COMBINED_ACCESS_CHANGE'
  )),
  CONSTRAINT chk_ueda_request_hash CHECK (CHAR_LENGTH(request_hash) = 64),
  CONSTRAINT chk_ueda_changed CHECK (
    NOT (
      eski_durum <=> yeni_durum
      AND eski_rol <=> yeni_rol
      AND eski_username <=> yeni_username
      AND eski_personel_id <=> yeni_personel_id
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Append-only enforcement. No application path may update or delete these
-- rows, and neither may an operator with a direct connection.
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_ueda_no_update;
CREATE TRIGGER trg_ueda_no_update
BEFORE UPDATE ON user_erisim_degisiklik_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: user erisim degisiklik audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_ueda_no_delete;
CREATE TRIGGER trg_ueda_no_delete
BEFORE DELETE ON user_erisim_degisiklik_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'ORG_AUDIT_IMMUTABLE: user erisim degisiklik audit satiri silinemez';
