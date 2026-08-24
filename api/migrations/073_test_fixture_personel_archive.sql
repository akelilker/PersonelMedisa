-- 073: Test fixture personel archive lifecycle (non-employment).
-- Additive only. No production personel seed/IDs. No fake termination dates.
-- TEST_FIXTURE_ARCHIVE is NOT ISTEN_AYRILMA / resignation / exit.
-- Production apply requires a separate operational gate.
-- MariaDB 10.6+ / PDO PREPARE one-liners. Idempotent.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- 1) Persisted auditable test-fixture classification (eligibility owner)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personel_test_fixture_siniflandirmalari (
  personel_id INT UNSIGNED NOT NULL,
  sinif ENUM('TEST_FIXTURE') NOT NULL,
  evidence_kodu VARCHAR(64) NOT NULL,
  evidence_ref VARCHAR(191) NULL,
  state ENUM('AKTIF', 'IPTAL') NOT NULL DEFAULT 'AKTIF',
  classified_by INT UNSIGNED NULL,
  classified_at DATETIME(3) NOT NULL,
  iptal_edildi_by INT UNSIGNED NULL,
  iptal_edildi_at DATETIME(3) NULL,
  aciklama VARCHAR(255) NULL,
  PRIMARY KEY (personel_id),
  KEY idx_ptfs_sinif_state (sinif, state),
  KEY idx_ptfs_evidence (evidence_kodu),
  CONSTRAINT fk_ptfs_personel
    FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 2) Archive event audit (canonical lifecycle evidence; not employment exit)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personel_test_fixture_archive_kayitlari (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  lifecycle_type VARCHAR(32) NOT NULL DEFAULT 'TEST_FIXTURE_ARCHIVE',
  archived_at DATETIME(3) NOT NULL,
  archived_by INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NULL,
  bolum_id INT UNSIGNED NULL,
  birim_id INT UNSIGNED NULL,
  classification_evidence_kodu VARCHAR(64) NOT NULL,
  cancelled_bildirim_ids_json JSON NULL,
  cancelled_surec_ids_json JSON NULL,
  cancelled_ucret_ids_json JSON NULL,
  archive_manifest_id INT UNSIGNED NULL,
  request_hash CHAR(64) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ptfa_personel_lifecycle (personel_id, lifecycle_type),
  KEY idx_ptfa_archived_at (archived_at),
  CONSTRAINT fk_ptfa_personel
    FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 3) Widen arsiv_manifestleri.trigger_type for TEST_FIXTURE_ARCHIVE
--    (archive effective date only — never employment termination semantics)
-- ---------------------------------------------------------------------------
SET @p073_trig_has := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'arsiv_manifestleri'
    AND COLUMN_NAME = 'trigger_type'
    AND COLUMN_TYPE LIKE '%TEST_FIXTURE_ARCHIVE%'
);
SET @p073_trig_sql := IF(
  @p073_trig_has = 0
  AND (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'arsiv_manifestleri'
  ) > 0,
  'ALTER TABLE arsiv_manifestleri MODIFY COLUMN trigger_type ENUM(''PERIOD_CLOSURE'', ''TERMINATION_DATE'', ''TEST_FIXTURE_ARCHIVE'') NOT NULL',
  'DO 0'
);
PREPARE p073_stmt FROM @p073_trig_sql;
EXECUTE p073_stmt;
DEALLOCATE PREPARE p073_stmt;
