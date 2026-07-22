-- S86: Personel belge dosya surumleri + audit (surecler BELGE kayitlari uzerine additive)
-- Soft cancel only; physical hard-delete cascade yok (ON DELETE RESTRICT).
-- Production seed yok. MariaDB 10.6+ / 11.4 uyumlu.
-- FK/CHECK adlari personel_bordro_devirleri (fk_pbd_*) ile cakismasin diye pbds_/pba_ kullanilir.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS personel_belge_dosya_surumleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  surec_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  surum_no INT UNSIGNED NOT NULL,
  aktif_mi TINYINT(1) NOT NULL DEFAULT 0,
  storage_key VARCHAR(128) NOT NULL,
  orijinal_dosya_adi VARCHAR(255) NOT NULL,
  mime_type VARCHAR(120) NOT NULL,
  uzanti VARCHAR(16) NOT NULL,
  byte_boyutu INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  yukleyen_kullanici_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pbds_storage_key (storage_key),
  UNIQUE KEY uq_pbds_surec_surum (surec_id, surum_no),
  KEY idx_pbds_personel (personel_id),
  KEY idx_pbds_surec_aktif (surec_id, aktif_mi),
  KEY idx_pbds_sha256 (sha256),
  CONSTRAINT fk_pbds_surec FOREIGN KEY (surec_id) REFERENCES surecler (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pbds_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pbds_yukleyen FOREIGN KEY (yukleyen_kullanici_id) REFERENCES users (id),
  CONSTRAINT chk_pbds_sha256 CHECK (sha256 REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_pbds_byte CHECK (byte_boyutu > 0),
  CONSTRAINT chk_pbds_surum CHECK (surum_no > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tek aktif surum invariant (NULL'lar UNIQUE disinda kalir).
ALTER TABLE personel_belge_dosya_surumleri
  ADD COLUMN IF NOT EXISTS aktif_surec_key INT UNSIGNED
    AS (CASE WHEN aktif_mi = 1 THEN surec_id ELSE NULL END) VIRTUAL;

-- Index may already exist on re-apply; ignore duplicate-name errors in runner, keep statement additive.
SET @s86_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personel_belge_dosya_surumleri'
    AND INDEX_NAME = 'uq_pbds_tek_aktif'
);
SET @s86_sql := IF(
  @s86_idx = 0,
  'ALTER TABLE personel_belge_dosya_surumleri ADD UNIQUE KEY uq_pbds_tek_aktif (aktif_surec_key)',
  'DO 0'
);
PREPARE s86_stmt FROM @s86_sql;
EXECUTE s86_stmt;
DEALLOCATE PREPARE s86_stmt;

CREATE TABLE IF NOT EXISTS personel_belge_auditleri (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  surec_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  belge_surum_id INT UNSIGNED NULL,
  islem_turu VARCHAR(32) NOT NULL,
  onceki_metadata_json TEXT NULL,
  yeni_metadata_json TEXT NULL,
  yapan_kullanici_id INT UNSIGNED NULL,
  gerekce VARCHAR(1000) NULL,
  dosya_sha256 CHAR(64) NULL,
  dosya_byte INT UNSIGNED NULL,
  dosya_mime VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pba_surec (surec_id),
  KEY idx_pba_personel (personel_id),
  KEY idx_pba_created (created_at),
  CONSTRAINT fk_pbaud_surec FOREIGN KEY (surec_id) REFERENCES surecler (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pbaud_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pbaud_surum FOREIGN KEY (belge_surum_id) REFERENCES personel_belge_dosya_surumleri (id) ON DELETE RESTRICT,
  CONSTRAINT fk_pbaud_user FOREIGN KEY (yapan_kullanici_id) REFERENCES users (id),
  CONSTRAINT chk_pbaud_islem CHECK (
    islem_turu IN ('CREATED', 'METADATA_UPDATED', 'FILE_REPLACED', 'CANCELLED')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
