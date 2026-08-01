-- S87: Aylik muhur revision + dual-control donem reopen owner
-- Additive / idempotent. Production seed yok. Eski muhur satirlari rewrite edilmez.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1) revision_no
SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri' AND COLUMN_NAME = 'revision_no'
);
SET @sql := IF(
  @c = 0,
  'ALTER TABLE puantaj_aylik_muhurleri ADD COLUMN revision_no INT UNSIGNED NOT NULL DEFAULT 1 AFTER ay',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) parent_muhur_id
SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri' AND COLUMN_NAME = 'parent_muhur_id'
);
SET @sql := IF(
  @c = 0,
  'ALTER TABLE puantaj_aylik_muhurleri ADD COLUMN parent_muhur_id INT UNSIGNED NULL AFTER created_by',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) superseded_by_id
SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri' AND COLUMN_NAME = 'superseded_by_id'
);
SET @sql := IF(
  @c = 0,
  'ALTER TABLE puantaj_aylik_muhurleri ADD COLUMN superseded_by_id INT UNSIGNED NULL AFTER parent_muhur_id',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) source_hash
SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri' AND COLUMN_NAME = 'source_hash'
);
SET @sql := IF(
  @c = 0,
  'ALTER TABLE puantaj_aylik_muhurleri ADD COLUMN source_hash CHAR(64) NULL AFTER superseded_by_id',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) reopen_talep_id (reseal baglantisi)
SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri' AND COLUMN_NAME = 'reopen_talep_id'
);
SET @sql := IF(
  @c = 0,
  'ALTER TABLE puantaj_aylik_muhurleri ADD COLUMN reopen_talep_id INT UNSIGNED NULL AFTER source_hash',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: mevcut kayitlar revision 1 (yalniz NULL/0 ise)
UPDATE puantaj_aylik_muhurleri
SET revision_no = 1
WHERE revision_no IS NULL OR revision_no = 0;

-- Generated aktif flag (MUHURLENDI = effective)
SET @c := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhurleri' AND COLUMN_NAME = 'aktif_muhur'
);
SET @sql := IF(
  @c = 0,
  'ALTER TABLE puantaj_aylik_muhurleri
     ADD COLUMN aktif_muhur TINYINT(1)
       GENERATED ALWAYS AS (CASE WHEN durum = ''MUHURLENDI'' THEN 1 ELSE NULL END) STORED',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- UNIQUE (sube, yil, ay, revision_no) — once eklenir; eski unique drop icin sube_id index kapsami kalmali
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'puantaj_aylik_muhurleri'
    AND INDEX_NAME = 'uq_pam_sube_donem_revision'
);
SET @sql := IF(
  @idx = 0,
  'ALTER TABLE puantaj_aylik_muhurleri ADD UNIQUE KEY uq_pam_sube_donem_revision (sube_id, yil, ay, revision_no)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- En fazla bir effective muhur / donem
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'puantaj_aylik_muhurleri'
    AND INDEX_NAME = 'uq_pam_aktif_muhur'
);
SET @sql := IF(
  @idx = 0,
  'ALTER TABLE puantaj_aylik_muhurleri ADD UNIQUE KEY uq_pam_aktif_muhur (sube_id, yil, ay, aktif_muhur)',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Drop eski tek-donem unique (FK sube index kapsami yeni unique ile korunur)
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'puantaj_aylik_muhurleri'
    AND INDEX_NAME = 'uq_puantaj_aylik_muhur_sube_donem'
);
SET @sql := IF(
  @idx > 0,
  'ALTER TABLE puantaj_aylik_muhurleri DROP INDEX uq_puantaj_aylik_muhur_sube_donem',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Self-FK parent / superseded (varsa ekleme)
SET @fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'puantaj_aylik_muhurleri'
    AND CONSTRAINT_NAME = 'fk_pam_parent_muhur'
);
SET @sql := IF(
  @fk = 0,
  'ALTER TABLE puantaj_aylik_muhurleri
     ADD CONSTRAINT fk_pam_parent_muhur FOREIGN KEY (parent_muhur_id) REFERENCES puantaj_aylik_muhurleri (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'puantaj_aylik_muhurleri'
    AND CONSTRAINT_NAME = 'fk_pam_superseded_by'
);
SET @sql := IF(
  @fk = 0,
  'ALTER TABLE puantaj_aylik_muhurleri
     ADD CONSTRAINT fk_pam_superseded_by FOREIGN KEY (superseded_by_id) REFERENCES puantaj_aylik_muhurleri (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Reopen talep owner
CREATE TABLE IF NOT EXISTS puantaj_donem_reopen_talepleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  kaynak_muhur_id INT UNSIGNED NOT NULL,
  talep_durumu VARCHAR(32) NOT NULL,
  gerekce VARCHAR(1000) NOT NULL,
  requested_by INT UNSIGNED NOT NULL,
  requested_at DATETIME NOT NULL,
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  rejected_by INT UNSIGNED NULL,
  rejected_at DATETIME NULL,
  rejection_reason VARCHAR(1000) NULL,
  applied_at DATETIME NULL,
  reseal_muhur_id INT UNSIGNED NULL,
  request_hash CHAR(64) NOT NULL,
  acik_talep_slot TINYINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE
        WHEN talep_durumu IN ('ONAY_BEKLIYOR', 'ONAYLANDI') THEN 1
        ELSE NULL
      END
    ) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pdrt_acik_donem (sube_id, yil, ay, acik_talep_slot),
  UNIQUE KEY uq_pdrt_request_hash (sube_id, yil, ay, request_hash),
  KEY idx_pdrt_donem_durum (sube_id, yil, ay, talep_durumu),
  KEY idx_pdrt_kaynak_muhur (kaynak_muhur_id),
  CONSTRAINT fk_pdrt_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_pdrt_kaynak_muhur FOREIGN KEY (kaynak_muhur_id) REFERENCES puantaj_aylik_muhurleri (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pdrt_reseal_muhur FOREIGN KEY (reseal_muhur_id) REFERENCES puantaj_aylik_muhurleri (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pdrt_requested_by FOREIGN KEY (requested_by) REFERENCES users (id),
  CONSTRAINT fk_pdrt_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
  CONSTRAINT fk_pdrt_rejected_by FOREIGN KEY (rejected_by) REFERENCES users (id),
  CONSTRAINT chk_pdrt_yil CHECK (yil BETWEEN 2000 AND 2100),
  CONSTRAINT chk_pdrt_ay CHECK (ay BETWEEN 1 AND 12),
  CONSTRAINT chk_pdrt_gerekce CHECK (CHAR_LENGTH(TRIM(gerekce)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reopen/reseal audit (additive owner; donem_kapanis ile paralel)
CREATE TABLE IF NOT EXISTS puantaj_donem_reopen_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  aksiyon VARCHAR(40) NOT NULL,
  sonuc VARCHAR(40) NOT NULL,
  reopen_talep_id INT UNSIGNED NULL,
  source_muhur_id INT UNSIGNED NULL,
  source_revision INT UNSIGNED NULL,
  target_muhur_id INT UNSIGNED NULL,
  target_revision INT UNSIGNED NULL,
  request_hash CHAR(64) NOT NULL,
  previous_source_hash CHAR(64) NULL,
  new_source_hash CHAR(64) NULL,
  failure_code VARCHAR(80) NULL,
  payload_json JSON NULL,
  actor_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pdra_idempotency (sube_id, yil, ay, aksiyon, request_hash),
  KEY idx_pdra_donem (sube_id, yil, ay, created_at),
  KEY idx_pdra_talep (reopen_talep_id),
  CONSTRAINT fk_pdra_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_pdra_talep FOREIGN KEY (reopen_talep_id) REFERENCES puantaj_donem_reopen_talepleri (id) ON DELETE SET NULL,
  CONSTRAINT fk_pdra_actor FOREIGN KEY (actor_id) REFERENCES users (id),
  CONSTRAINT chk_pdra_yil CHECK (yil BETWEEN 2000 AND 2100),
  CONSTRAINT chk_pdra_ay CHECK (ay BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- muhur → reopen talep FK (tablo olustuktan sonra)
SET @fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'puantaj_aylik_muhurleri'
    AND CONSTRAINT_NAME = 'fk_pam_reopen_talep'
);
SET @sql := IF(
  @fk = 0,
  'ALTER TABLE puantaj_aylik_muhurleri
     ADD CONSTRAINT fk_pam_reopen_talep FOREIGN KEY (reopen_talep_id) REFERENCES puantaj_donem_reopen_talepleri (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
