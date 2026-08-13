-- 065: Pack6 — Native Bölüm / Birim / Pozisyon + system branch SGK employer owner.
-- Canonical personnel org attributes (MG-ORG-ATTR-001 CLOSED):
--   Departman = departman_id (existing)
--   Bölüm     = bolum_id (NEW)
--   Birim     = birim_id (NEW)
--   Unvan     = gorev_id (existing owner — do NOT invent unvan_id)
--   Pozisyon  = pozisyon_id (NEW, independent of Birim unless business proves otherwise)
--   Personel Tipi = personel_tipi_id (existing)
-- subeler.sgk_isveren_id = branch COMPANY / SGK employer owner (NOT auth; auth remains personeller.sube_id).
-- NO SEED. NO PERSONNEL BACKFILL. NO BRANCH RENAME. NO PRODUCTION DATA WRITE.
-- Idempotent / partial-state convergent. MariaDB 10.6 / 11.4. PHP 7.4 compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- 3A. bolumler
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bolumler (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  departman_id INT UNSIGNED NOT NULL,
  ad VARCHAR(120) NOT NULL,
  durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bolumler_departman_ad (departman_id, ad),
  KEY idx_bolumler_departman (departman_id),
  KEY idx_bolumler_durum (durum),
  CONSTRAINT chk_bolumler_durum CHECK (durum IN ('AKTIF', 'PASIF'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @p6_fk_bolum_dep := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND CONSTRAINT_NAME = 'fk_bolumler_departman'
);
SET @p6_fk_bolum_dep_sql := IF(
  @p6_fk_bolum_dep = 0,
  'ALTER TABLE bolumler
     ADD CONSTRAINT fk_bolumler_departman
       FOREIGN KEY (departman_id) REFERENCES departmanlar (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_fk_bolum_dep_stmt FROM @p6_fk_bolum_dep_sql;
EXECUTE p6_fk_bolum_dep_stmt;
DEALLOCATE PREPARE p6_fk_bolum_dep_stmt;

SET @p6_uq_bolum := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND INDEX_NAME = 'uq_bolumler_departman_ad'
);
SET @p6_uq_bolum_sql := IF(
  @p6_uq_bolum = 0,
  'ALTER TABLE bolumler ADD UNIQUE KEY uq_bolumler_departman_ad (departman_id, ad)',
  'DO 0'
);
PREPARE p6_uq_bolum_stmt FROM @p6_uq_bolum_sql;
EXECUTE p6_uq_bolum_stmt;
DEALLOCATE PREPARE p6_uq_bolum_stmt;

SET @p6_idx_bolum_dep := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND INDEX_NAME = 'idx_bolumler_departman'
);
SET @p6_idx_bolum_dep_sql := IF(
  @p6_idx_bolum_dep = 0,
  'ALTER TABLE bolumler ADD KEY idx_bolumler_departman (departman_id)',
  'DO 0'
);
PREPARE p6_idx_bolum_dep_stmt FROM @p6_idx_bolum_dep_sql;
EXECUTE p6_idx_bolum_dep_stmt;
DEALLOCATE PREPARE p6_idx_bolum_dep_stmt;

-- ---------------------------------------------------------------------------
-- 3B. birimler
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS birimler (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  bolum_id INT UNSIGNED NOT NULL,
  ad VARCHAR(120) NOT NULL,
  durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_birimler_bolum_ad (bolum_id, ad),
  KEY idx_birimler_bolum (bolum_id),
  KEY idx_birimler_durum (durum),
  CONSTRAINT chk_birimler_durum CHECK (durum IN ('AKTIF', 'PASIF'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @p6_fk_birim_bolum := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND CONSTRAINT_NAME = 'fk_birimler_bolum'
);
SET @p6_fk_birim_bolum_sql := IF(
  @p6_fk_birim_bolum = 0,
  'ALTER TABLE birimler
     ADD CONSTRAINT fk_birimler_bolum
       FOREIGN KEY (bolum_id) REFERENCES bolumler (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_fk_birim_bolum_stmt FROM @p6_fk_birim_bolum_sql;
EXECUTE p6_fk_birim_bolum_stmt;
DEALLOCATE PREPARE p6_fk_birim_bolum_stmt;

SET @p6_uq_birim := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND INDEX_NAME = 'uq_birimler_bolum_ad'
);
SET @p6_uq_birim_sql := IF(
  @p6_uq_birim = 0,
  'ALTER TABLE birimler ADD UNIQUE KEY uq_birimler_bolum_ad (bolum_id, ad)',
  'DO 0'
);
PREPARE p6_uq_birim_stmt FROM @p6_uq_birim_sql;
EXECUTE p6_uq_birim_stmt;
DEALLOCATE PREPARE p6_uq_birim_stmt;

SET @p6_idx_birim_bolum := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND INDEX_NAME = 'idx_birimler_bolum'
);
SET @p6_idx_birim_bolum_sql := IF(
  @p6_idx_birim_bolum = 0,
  'ALTER TABLE birimler ADD KEY idx_birimler_bolum (bolum_id)',
  'DO 0'
);
PREPARE p6_idx_birim_bolum_stmt FROM @p6_idx_birim_bolum_sql;
EXECUTE p6_idx_birim_bolum_stmt;
DEALLOCATE PREPARE p6_idx_birim_bolum_stmt;

-- ---------------------------------------------------------------------------
-- 3C. pozisyonlar (flat — not forced under Birim)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pozisyonlar (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ad VARCHAR(120) NOT NULL,
  durum VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pozisyonlar_ad (ad),
  KEY idx_pozisyonlar_durum (durum),
  CONSTRAINT chk_pozisyonlar_durum CHECK (durum IN ('AKTIF', 'PASIF'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @p6_uq_poz := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pozisyonlar'
    AND INDEX_NAME = 'uq_pozisyonlar_ad'
);
SET @p6_uq_poz_sql := IF(
  @p6_uq_poz = 0,
  'ALTER TABLE pozisyonlar ADD UNIQUE KEY uq_pozisyonlar_ad (ad)',
  'DO 0'
);
PREPARE p6_uq_poz_stmt FROM @p6_uq_poz_sql;
EXECUTE p6_uq_poz_stmt;
DEALLOCATE PREPARE p6_uq_poz_stmt;

-- ---------------------------------------------------------------------------
-- 3D. personeller FKs (nullable; no backfill)
-- ---------------------------------------------------------------------------
SET @p6_col_bolum := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'bolum_id'
);
SET @p6_col_bolum_sql := IF(
  @p6_col_bolum = 0,
  'ALTER TABLE personeller
     ADD COLUMN bolum_id INT UNSIGNED NULL AFTER departman_id',
  'DO 0'
);
PREPARE p6_col_bolum_stmt FROM @p6_col_bolum_sql;
EXECUTE p6_col_bolum_stmt;
DEALLOCATE PREPARE p6_col_bolum_stmt;

SET @p6_col_birim := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'birim_id'
);
SET @p6_col_bolum_now := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'bolum_id'
);
SET @p6_col_birim_sql := IF(
  @p6_col_birim = 0,
  IF(
    @p6_col_bolum_now > 0,
    'ALTER TABLE personeller
       ADD COLUMN birim_id INT UNSIGNED NULL AFTER bolum_id',
    'ALTER TABLE personeller
       ADD COLUMN birim_id INT UNSIGNED NULL AFTER departman_id'
  ),
  'DO 0'
);
PREPARE p6_col_birim_stmt FROM @p6_col_birim_sql;
EXECUTE p6_col_birim_stmt;
DEALLOCATE PREPARE p6_col_birim_stmt;

SET @p6_col_poz := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'pozisyon_id'
);
SET @p6_col_gorev_now := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'gorev_id'
);
SET @p6_col_poz_sql := IF(
  @p6_col_poz = 0,
  IF(
    @p6_col_gorev_now > 0,
    'ALTER TABLE personeller
       ADD COLUMN pozisyon_id INT UNSIGNED NULL AFTER gorev_id',
    'ALTER TABLE personeller
       ADD COLUMN pozisyon_id INT UNSIGNED NULL AFTER birim_id'
  ),
  'DO 0'
);
PREPARE p6_col_poz_stmt FROM @p6_col_poz_sql;
EXECUTE p6_col_poz_stmt;
DEALLOCATE PREPARE p6_col_poz_stmt;

SET @p6_fk_p_bolum := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND CONSTRAINT_NAME = 'fk_personeller_bolum'
);
SET @p6_col_bolum2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'bolum_id'
);
SET @p6_fk_p_bolum_sql := IF(
  @p6_fk_p_bolum = 0 AND @p6_col_bolum2 > 0,
  'ALTER TABLE personeller
     ADD CONSTRAINT fk_personeller_bolum
       FOREIGN KEY (bolum_id) REFERENCES bolumler (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_fk_p_bolum_stmt FROM @p6_fk_p_bolum_sql;
EXECUTE p6_fk_p_bolum_stmt;
DEALLOCATE PREPARE p6_fk_p_bolum_stmt;

SET @p6_fk_p_birim := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND CONSTRAINT_NAME = 'fk_personeller_birim'
);
SET @p6_col_birim2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'birim_id'
);
SET @p6_fk_p_birim_sql := IF(
  @p6_fk_p_birim = 0 AND @p6_col_birim2 > 0,
  'ALTER TABLE personeller
     ADD CONSTRAINT fk_personeller_birim
       FOREIGN KEY (birim_id) REFERENCES birimler (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_fk_p_birim_stmt FROM @p6_fk_p_birim_sql;
EXECUTE p6_fk_p_birim_stmt;
DEALLOCATE PREPARE p6_fk_p_birim_stmt;

SET @p6_fk_p_poz := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND CONSTRAINT_NAME = 'fk_personeller_pozisyon'
);
SET @p6_col_poz2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'pozisyon_id'
);
SET @p6_fk_p_poz_sql := IF(
  @p6_fk_p_poz = 0 AND @p6_col_poz2 > 0,
  'ALTER TABLE personeller
     ADD CONSTRAINT fk_personeller_pozisyon
       FOREIGN KEY (pozisyon_id) REFERENCES pozisyonlar (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_fk_p_poz_stmt FROM @p6_fk_p_poz_sql;
EXECUTE p6_fk_p_poz_stmt;
DEALLOCATE PREPARE p6_fk_p_poz_stmt;

SET @p6_idx_p_bolum := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND INDEX_NAME = 'idx_personeller_bolum'
);
SET @p6_idx_p_bolum_sql := IF(
  @p6_idx_p_bolum = 0 AND @p6_col_bolum2 > 0,
  'ALTER TABLE personeller ADD KEY idx_personeller_bolum (bolum_id)',
  'DO 0'
);
PREPARE p6_idx_p_bolum_stmt FROM @p6_idx_p_bolum_sql;
EXECUTE p6_idx_p_bolum_stmt;
DEALLOCATE PREPARE p6_idx_p_bolum_stmt;

SET @p6_idx_p_birim := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND INDEX_NAME = 'idx_personeller_birim'
);
SET @p6_idx_p_birim_sql := IF(
  @p6_idx_p_birim = 0 AND @p6_col_birim2 > 0,
  'ALTER TABLE personeller ADD KEY idx_personeller_birim (birim_id)',
  'DO 0'
);
PREPARE p6_idx_p_birim_stmt FROM @p6_idx_p_birim_sql;
EXECUTE p6_idx_p_birim_stmt;
DEALLOCATE PREPARE p6_idx_p_birim_stmt;

SET @p6_idx_p_poz := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND INDEX_NAME = 'idx_personeller_pozisyon'
);
SET @p6_idx_p_poz_sql := IF(
  @p6_idx_p_poz = 0 AND @p6_col_poz2 > 0,
  'ALTER TABLE personeller ADD KEY idx_personeller_pozisyon (pozisyon_id)',
  'DO 0'
);
PREPARE p6_idx_p_poz_stmt FROM @p6_idx_p_poz_sql;
EXECUTE p6_idx_p_poz_stmt;
DEALLOCATE PREPARE p6_idx_p_poz_stmt;

-- ---------------------------------------------------------------------------
-- 3E. subeler.sgk_isveren_id (branch company owner; NOT authorization)
-- ---------------------------------------------------------------------------
SET @p6_sube_sgk := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'subeler'
    AND COLUMN_NAME = 'sgk_isveren_id'
);
SET @p6_sube_sgk_sql := IF(
  @p6_sube_sgk = 0,
  'ALTER TABLE subeler
     ADD COLUMN sgk_isveren_id INT UNSIGNED NULL AFTER ad',
  'DO 0'
);
PREPARE p6_sube_sgk_stmt FROM @p6_sube_sgk_sql;
EXECUTE p6_sube_sgk_stmt;
DEALLOCATE PREPARE p6_sube_sgk_stmt;

SET @p6_fk_sube_sgk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'subeler'
    AND CONSTRAINT_NAME = 'fk_subeler_sgk_isveren'
);
SET @p6_sube_sgk_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'subeler'
    AND COLUMN_NAME = 'sgk_isveren_id'
);
SET @p6_sgk_tbl := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_isverenler'
);
SET @p6_fk_sube_sgk_sql := IF(
  @p6_fk_sube_sgk = 0 AND @p6_sube_sgk_col > 0 AND @p6_sgk_tbl > 0,
  'ALTER TABLE subeler
     ADD CONSTRAINT fk_subeler_sgk_isveren
       FOREIGN KEY (sgk_isveren_id) REFERENCES sgk_isverenler (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_fk_sube_sgk_stmt FROM @p6_fk_sube_sgk_sql;
EXECUTE p6_fk_sube_sgk_stmt;
DEALLOCATE PREPARE p6_fk_sube_sgk_stmt;

SET @p6_idx_sube_sgk := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'subeler'
    AND INDEX_NAME = 'idx_subeler_sgk_isveren'
);
SET @p6_idx_sube_sgk_sql := IF(
  @p6_idx_sube_sgk = 0 AND @p6_sube_sgk_col > 0,
  'ALTER TABLE subeler ADD KEY idx_subeler_sgk_isveren (sgk_isveren_id)',
  'DO 0'
);
PREPARE p6_idx_sube_sgk_stmt FROM @p6_idx_sube_sgk_sql;
EXECUTE p6_idx_sube_sgk_stmt;
DEALLOCATE PREPARE p6_idx_sube_sgk_stmt;
