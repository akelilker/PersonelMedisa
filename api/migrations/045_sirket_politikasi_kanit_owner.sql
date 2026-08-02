-- S87: Sirket calisma politikasi Form 91 / karar belgesi kanit owner alanlari.
-- Additive only. Backfill yok. Mevcut ONAYLANDI satirlari NULL kalir (LEGACY_MISSING).
-- policy_version_hash dokunulmaz. Ikinci apply idempotent olmali.
-- Production'da otomatik calistirilmaz. MariaDB 10.6 uyumlu.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE sirket_calisma_politikalari
  ADD COLUMN IF NOT EXISTS belge_id VARCHAR(160) NULL AFTER aciklama,
  ADD COLUMN IF NOT EXISTS belge_sha256 CHAR(64) NULL AFTER belge_id;

-- Index (idempotent) — PREPARE/EXECUTE/DEALLOCATE tek satir (PDO apply uyumu)
SET @idx_exists := (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sirket_calisma_politikalari'
    AND INDEX_NAME = 'idx_scp_belge_id'
);
SET @sql := IF(
  @idx_exists = 0,
  'ALTER TABLE sirket_calisma_politikalari ADD KEY idx_scp_belge_id (belge_id)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Pair invariant: both NULL or both non-NULL
SET @chk_pair := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sirket_calisma_politikalari'
    AND CONSTRAINT_NAME = 'chk_scp_belge_pair'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql := IF(
  @chk_pair = 0,
  'ALTER TABLE sirket_calisma_politikalari ADD CONSTRAINT chk_scp_belge_pair CHECK ((belge_id IS NULL AND belge_sha256 IS NULL) OR (belge_id IS NOT NULL AND belge_sha256 IS NOT NULL))',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- SHA format: NULL or exact 64 lowercase hex
SET @chk_sha := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sirket_calisma_politikalari'
    AND CONSTRAINT_NAME = 'chk_scp_belge_sha256'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql := IF(
  @chk_sha = 0,
  'ALTER TABLE sirket_calisma_politikalari ADD CONSTRAINT chk_scp_belge_sha256 CHECK (belge_sha256 IS NULL OR belge_sha256 REGEXP ''^[0-9a-f]{64}$'')',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
