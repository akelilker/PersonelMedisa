-- 064: Pack5 Track B — SGK isveren / sistem subesi / calisma lokasyonu ayrimi.
-- Canonical model (MG-ORG-MODEL-001):
--   1) sgk_isverenler
--   2) subeler (existing SYSTEM BRANCH owner — unchanged)
--   3) calisma_lokasyonlari
-- personeller.sube_id preserved. New FKs nullable. NO AUTO INFERENCE / BACKFILL / REAL SEED.
-- Real org seed = MG-OPS-ORG-001 USER_GATED.
-- MariaDB 10.6 / 11.4 compatible. PHP 7.4 compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS sgk_isverenler (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kod VARCHAR(64) NULL,
  ad VARCHAR(191) NOT NULL,
  durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_isverenler_kod (kod),
  UNIQUE KEY uq_sgk_isverenler_ad (ad),
  KEY idx_sgk_isverenler_durum (durum),
  CONSTRAINT chk_sgk_isverenler_durum CHECK (durum IN ('AKTIF', 'PASIF'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calisma_lokasyonlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kod VARCHAR(64) NULL,
  ad VARCHAR(191) NOT NULL,
  durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_calisma_lokasyonlari_kod (kod),
  UNIQUE KEY uq_calisma_lokasyonlari_ad (ad),
  KEY idx_calisma_lokasyonlari_durum (durum),
  CONSTRAINT chk_calisma_lokasyonlari_durum CHECK (durum IN ('AKTIF', 'PASIF'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @p5_org_sgk := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'sgk_isveren_id'
);
SET @p5_org_sgk_sql := IF(
  @p5_org_sgk = 0,
  'ALTER TABLE personeller
     ADD COLUMN sgk_isveren_id INT UNSIGNED NULL AFTER sube_id,
     ADD COLUMN calisma_lokasyonu_id INT UNSIGNED NULL AFTER sgk_isveren_id',
  'DO 0'
);
PREPARE p5_org_sgk_stmt FROM @p5_org_sgk_sql;
EXECUTE p5_org_sgk_stmt;
DEALLOCATE PREPARE p5_org_sgk_stmt;

SET @p5_org_fk_sgk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND CONSTRAINT_NAME = 'fk_personeller_sgk_isveren'
);
SET @p5_org_fk_sgk_sql := IF(
  @p5_org_fk_sgk = 0,
  'ALTER TABLE personeller
     ADD CONSTRAINT fk_personeller_sgk_isveren
       FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id) ON DELETE RESTRICT,
     ADD CONSTRAINT fk_personeller_calisma_lokasyonu
       FOREIGN KEY (calisma_lokasyonu_id) REFERENCES calisma_lokasyonlari (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p5_org_fk_sgk_stmt FROM @p5_org_fk_sgk_sql;
EXECUTE p5_org_fk_sgk_stmt;
DEALLOCATE PREPARE p5_org_fk_sgk_stmt;

SET @p5_org_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND INDEX_NAME = 'idx_personeller_sgk_isveren'
);
SET @p5_org_idx_sql := IF(
  @p5_org_idx = 0,
  'ALTER TABLE personeller
     ADD KEY idx_personeller_sgk_isveren (sgk_isveren_id),
     ADD KEY idx_personeller_calisma_lokasyonu (calisma_lokasyonu_id)',
  'DO 0'
);
PREPARE p5_org_idx_stmt FROM @p5_org_idx_sql;
EXECUTE p5_org_idx_stmt;
DEALLOCATE PREPARE p5_org_idx_stmt;
