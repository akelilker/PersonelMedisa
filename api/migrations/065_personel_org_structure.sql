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
-- Idempotent / partial-state convergent / fail-closed on unsafe drift.
-- MariaDB 10.6 / 11.4. PHP 7.4 compatible.
--
-- REQUIRED SCHEMA CONTRACT (A1):
-- bolumler: id PK; departman_id; ad; durum; created_at; updated_at;
--   uq_bolumler_departman_ad (departman_id, ad);
--   fk_bolumler_departman -> departmanlar(id) ON DELETE RESTRICT;
--   idx_bolumler_departman; idx_bolumler_durum; chk_bolumler_durum.
-- birimler: id PK; bolum_id; ad; durum; created_at; updated_at;
--   uq_birimler_bolum_ad; fk_birimler_bolum -> bolumler(id) ON DELETE RESTRICT;
--   idx_birimler_bolum; idx_birimler_durum; chk_birimler_durum.
-- pozisyonlar: id PK; ad; durum; created_at; updated_at;
--   uq_pozisyonlar_ad; idx_pozisyonlar_durum; chk_pozisyonlar_durum.
-- personeller: bolum_id/birim_id/pozisyon_id INT UNSIGNED NULL + FKs + indexes.
-- subeler: sgk_isveren_id INT UNSIGNED NULL + FK + index.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- 3A. bolumler — create canonical or converge partial
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

SET @p6_bolum_rows := (SELECT COUNT(*) FROM bolumler);

-- incompatible departman_id shape
SET @p6_bolum_dep_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND COLUMN_NAME = 'departman_id'
    AND NOT (
      DATA_TYPE = 'int'
      AND COLUMN_TYPE LIKE '%unsigned%'
      AND IS_NULLABLE = 'NO'
    )
);
SET @p6_bolum_dep_bad_sql := IF(
  @p6_bolum_dep_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: bolumler.departman_id incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_dep_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_bolum_dep_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND COLUMN_NAME = 'departman_id'
);
SET @p6_bolum_dep_fail_sql := IF(
  @p6_bolum_dep_miss = 0 AND @p6_bolum_rows > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: bolumler.departman_id missing with rows''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_dep_fail_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
SET @p6_bolum_dep_add_sql := IF(
  @p6_bolum_dep_miss = 0 AND @p6_bolum_rows = 0,
  'ALTER TABLE bolumler ADD COLUMN departman_id INT UNSIGNED NOT NULL AFTER id',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_dep_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

-- incompatible / missing ad
SET @p6_bolum_ad_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND COLUMN_NAME = 'ad'
    AND NOT (DATA_TYPE = 'varchar' AND IS_NULLABLE = 'NO')
);
SET @p6_bolum_ad_bad_sql := IF(
  @p6_bolum_ad_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: bolumler.ad incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_ad_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_bolum_ad_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND COLUMN_NAME = 'ad'
);
SET @p6_bolum_ad_fail_sql := IF(
  @p6_bolum_ad_miss = 0 AND @p6_bolum_rows > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: bolumler.ad missing with rows''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_ad_fail_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
SET @p6_bolum_ad_add_sql := IF(
  @p6_bolum_ad_miss = 0 AND @p6_bolum_rows = 0,
  'ALTER TABLE bolumler ADD COLUMN ad VARCHAR(120) NOT NULL AFTER departman_id',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_ad_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

-- durum (DEFAULT safe even with rows)
SET @p6_bolum_durum_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND COLUMN_NAME = 'durum'
);
SET @p6_bolum_durum_add_sql := IF(
  @p6_bolum_durum_miss = 0,
  'ALTER TABLE bolumler ADD COLUMN durum VARCHAR(16) NOT NULL DEFAULT ''AKTIF'' AFTER ad',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_durum_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_bolum_ca_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND COLUMN_NAME = 'created_at'
);
SET @p6_bolum_ca_add_sql := IF(
  @p6_bolum_ca_miss = 0,
  'ALTER TABLE bolumler ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_ca_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_bolum_ua_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND COLUMN_NAME = 'updated_at'
);
SET @p6_bolum_ua_add_sql := IF(
  @p6_bolum_ua_miss = 0,
  'ALTER TABLE bolumler ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_bolum_ua_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_uq_bolum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_idx_bolum_dep_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_idx_bolum_durum := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND INDEX_NAME = 'idx_bolumler_durum'
);
SET @p6_idx_bolum_durum_sql := IF(
  @p6_idx_bolum_durum = 0,
  'ALTER TABLE bolumler ADD KEY idx_bolumler_durum (durum)',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_idx_bolum_durum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_chk_bolum := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND CONSTRAINT_NAME = 'chk_bolumler_durum'
);
SET @p6_chk_bolum_sql := IF(
  @p6_chk_bolum = 0,
  'ALTER TABLE bolumler ADD CONSTRAINT chk_bolumler_durum CHECK (durum IN (''AKTIF'', ''PASIF''))',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_chk_bolum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_fk_bolum_dep_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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

SET @p6_birim_rows := (SELECT COUNT(*) FROM birimler);

SET @p6_birim_parent_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND COLUMN_NAME = 'bolum_id'
    AND NOT (
      DATA_TYPE = 'int'
      AND COLUMN_TYPE LIKE '%unsigned%'
      AND IS_NULLABLE = 'NO'
    )
);
SET @p6_birim_parent_bad_sql := IF(
  @p6_birim_parent_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: birimler.bolum_id incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_parent_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_birim_parent_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND COLUMN_NAME = 'bolum_id'
);
SET @p6_birim_parent_fail_sql := IF(
  @p6_birim_parent_miss = 0 AND @p6_birim_rows > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: birimler.bolum_id missing with rows''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_parent_fail_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
SET @p6_birim_parent_add_sql := IF(
  @p6_birim_parent_miss = 0 AND @p6_birim_rows = 0,
  'ALTER TABLE birimler ADD COLUMN bolum_id INT UNSIGNED NOT NULL AFTER id',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_parent_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_birim_ad_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND COLUMN_NAME = 'ad'
    AND NOT (DATA_TYPE = 'varchar' AND IS_NULLABLE = 'NO')
);
SET @p6_birim_ad_bad_sql := IF(
  @p6_birim_ad_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: birimler.ad incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_ad_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_birim_ad_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND COLUMN_NAME = 'ad'
);
SET @p6_birim_ad_fail_sql := IF(
  @p6_birim_ad_miss = 0 AND @p6_birim_rows > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: birimler.ad missing with rows''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_ad_fail_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
SET @p6_birim_ad_add_sql := IF(
  @p6_birim_ad_miss = 0 AND @p6_birim_rows = 0,
  'ALTER TABLE birimler ADD COLUMN ad VARCHAR(120) NOT NULL AFTER bolum_id',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_ad_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_birim_durum_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND COLUMN_NAME = 'durum'
);
SET @p6_birim_durum_add_sql := IF(
  @p6_birim_durum_miss = 0,
  'ALTER TABLE birimler ADD COLUMN durum VARCHAR(16) NOT NULL DEFAULT ''AKTIF'' AFTER ad',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_durum_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_birim_ca_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND COLUMN_NAME = 'created_at'
);
SET @p6_birim_ca_add_sql := IF(
  @p6_birim_ca_miss = 0,
  'ALTER TABLE birimler ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_ca_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_birim_ua_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND COLUMN_NAME = 'updated_at'
);
SET @p6_birim_ua_add_sql := IF(
  @p6_birim_ua_miss = 0,
  'ALTER TABLE birimler ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_birim_ua_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_uq_birim_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_idx_birim_bolum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_idx_birim_durum := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND INDEX_NAME = 'idx_birimler_durum'
);
SET @p6_idx_birim_durum_sql := IF(
  @p6_idx_birim_durum = 0,
  'ALTER TABLE birimler ADD KEY idx_birimler_durum (durum)',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_idx_birim_durum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_chk_birim := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND CONSTRAINT_NAME = 'chk_birimler_durum'
);
SET @p6_chk_birim_sql := IF(
  @p6_chk_birim = 0,
  'ALTER TABLE birimler ADD CONSTRAINT chk_birimler_durum CHECK (durum IN (''AKTIF'', ''PASIF''))',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_chk_birim_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_fk_birim_bolum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

-- ---------------------------------------------------------------------------
-- 3C. pozisyonlar
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

SET @p6_poz_rows := (SELECT COUNT(*) FROM pozisyonlar);

SET @p6_poz_ad_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pozisyonlar'
    AND COLUMN_NAME = 'ad'
    AND NOT (DATA_TYPE = 'varchar' AND IS_NULLABLE = 'NO')
);
SET @p6_poz_ad_bad_sql := IF(
  @p6_poz_ad_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: pozisyonlar.ad incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_poz_ad_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_poz_ad_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pozisyonlar'
    AND COLUMN_NAME = 'ad'
);
SET @p6_poz_ad_fail_sql := IF(
  @p6_poz_ad_miss = 0 AND @p6_poz_rows > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: pozisyonlar.ad missing with rows''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_poz_ad_fail_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
SET @p6_poz_ad_add_sql := IF(
  @p6_poz_ad_miss = 0 AND @p6_poz_rows = 0,
  'ALTER TABLE pozisyonlar ADD COLUMN ad VARCHAR(120) NOT NULL AFTER id',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_poz_ad_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_poz_durum_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pozisyonlar'
    AND COLUMN_NAME = 'durum'
);
SET @p6_poz_durum_add_sql := IF(
  @p6_poz_durum_miss = 0,
  'ALTER TABLE pozisyonlar ADD COLUMN durum VARCHAR(16) NOT NULL DEFAULT ''AKTIF'' AFTER ad',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_poz_durum_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_poz_ca_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pozisyonlar'
    AND COLUMN_NAME = 'created_at'
);
SET @p6_poz_ca_add_sql := IF(
  @p6_poz_ca_miss = 0,
  'ALTER TABLE pozisyonlar ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_poz_ca_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_poz_ua_miss := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pozisyonlar'
    AND COLUMN_NAME = 'updated_at'
);
SET @p6_poz_ua_add_sql := IF(
  @p6_poz_ua_miss = 0,
  'ALTER TABLE pozisyonlar ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_poz_ua_add_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_uq_poz_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_idx_poz_durum := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pozisyonlar'
    AND INDEX_NAME = 'idx_pozisyonlar_durum'
);
SET @p6_idx_poz_durum_sql := IF(
  @p6_idx_poz_durum = 0,
  'ALTER TABLE pozisyonlar ADD KEY idx_pozisyonlar_durum (durum)',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_idx_poz_durum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_chk_poz := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pozisyonlar'
    AND CONSTRAINT_NAME = 'chk_pozisyonlar_durum'
);
SET @p6_chk_poz_sql := IF(
  @p6_chk_poz = 0,
  'ALTER TABLE pozisyonlar ADD CONSTRAINT chk_pozisyonlar_durum CHECK (durum IN (''AKTIF'', ''PASIF''))',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_chk_poz_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

-- ---------------------------------------------------------------------------
-- 3D. personeller FKs (nullable; no backfill)
-- ---------------------------------------------------------------------------
SET @p6_col_bolum := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'bolum_id'
);
SET @p6_col_bolum_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'bolum_id'
    AND NOT (
      DATA_TYPE = 'int'
      AND COLUMN_TYPE LIKE '%unsigned%'
      AND IS_NULLABLE = 'YES'
    )
);
SET @p6_col_bolum_bad_sql := IF(
  @p6_col_bolum_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: personeller.bolum_id incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_col_bolum_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
SET @p6_col_bolum_sql := IF(
  @p6_col_bolum = 0,
  'ALTER TABLE personeller
     ADD COLUMN bolum_id INT UNSIGNED NULL AFTER departman_id',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_col_bolum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_col_birim := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'birim_id'
);
SET @p6_col_birim_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'birim_id'
    AND NOT (
      DATA_TYPE = 'int'
      AND COLUMN_TYPE LIKE '%unsigned%'
      AND IS_NULLABLE = 'YES'
    )
);
SET @p6_col_birim_bad_sql := IF(
  @p6_col_birim_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: personeller.birim_id incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_col_birim_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
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
PREPARE p6_stmt FROM @p6_col_birim_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_col_poz := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'pozisyon_id'
);
SET @p6_col_poz_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'pozisyon_id'
    AND NOT (
      DATA_TYPE = 'int'
      AND COLUMN_TYPE LIKE '%unsigned%'
      AND IS_NULLABLE = 'YES'
    )
);
SET @p6_col_poz_bad_sql := IF(
  @p6_col_poz_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: personeller.pozisyon_id incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_col_poz_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
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
PREPARE p6_stmt FROM @p6_col_poz_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_col_bolum2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'bolum_id'
);
SET @p6_col_birim2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'birim_id'
);
SET @p6_col_poz2 := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'pozisyon_id'
);

SET @p6_fk_p_bolum := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND CONSTRAINT_NAME = 'fk_personeller_bolum'
);
SET @p6_fk_p_bolum_sql := IF(
  @p6_fk_p_bolum = 0 AND @p6_col_bolum2 > 0,
  'ALTER TABLE personeller
     ADD CONSTRAINT fk_personeller_bolum
       FOREIGN KEY (bolum_id) REFERENCES bolumler (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_fk_p_bolum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_fk_p_birim := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND CONSTRAINT_NAME = 'fk_personeller_birim'
);
SET @p6_fk_p_birim_sql := IF(
  @p6_fk_p_birim = 0 AND @p6_col_birim2 > 0,
  'ALTER TABLE personeller
     ADD CONSTRAINT fk_personeller_birim
       FOREIGN KEY (birim_id) REFERENCES birimler (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_fk_p_birim_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

SET @p6_fk_p_poz := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND CONSTRAINT_NAME = 'fk_personeller_pozisyon'
);
SET @p6_fk_p_poz_sql := IF(
  @p6_fk_p_poz = 0 AND @p6_col_poz2 > 0,
  'ALTER TABLE personeller
     ADD CONSTRAINT fk_personeller_pozisyon
       FOREIGN KEY (pozisyon_id) REFERENCES pozisyonlar (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_fk_p_poz_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_idx_p_bolum_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_idx_p_birim_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_idx_p_poz_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

-- ---------------------------------------------------------------------------
-- 3E. subeler.sgk_isveren_id (branch company owner; NOT authorization)
-- ---------------------------------------------------------------------------
SET @p6_sube_sgk := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'subeler'
    AND COLUMN_NAME = 'sgk_isveren_id'
);
SET @p6_sube_sgk_bad := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'subeler'
    AND COLUMN_NAME = 'sgk_isveren_id'
    AND NOT (
      DATA_TYPE = 'int'
      AND COLUMN_TYPE LIKE '%unsigned%'
      AND IS_NULLABLE = 'YES'
    )
);
SET @p6_sube_sgk_bad_sql := IF(
  @p6_sube_sgk_bad > 0,
  'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''PACK6_065_BLOCKER: subeler.sgk_isveren_id incompatible''',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_sube_sgk_bad_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
SET @p6_sube_sgk_sql := IF(
  @p6_sube_sgk = 0,
  'ALTER TABLE subeler
     ADD COLUMN sgk_isveren_id INT UNSIGNED NULL AFTER ad',
  'DO 0'
);
PREPARE p6_stmt FROM @p6_sube_sgk_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_fk_sube_sgk_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;

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
PREPARE p6_stmt FROM @p6_idx_sube_sgk_sql;
EXECUTE p6_stmt;
DEALLOCATE PREPARE p6_stmt;
