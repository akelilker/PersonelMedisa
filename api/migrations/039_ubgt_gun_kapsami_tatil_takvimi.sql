-- S88: UBGT gun kapsami + resmi tatil takvimi owner
-- Additive only. Production seed / gercek tatil tarihi YOK.
-- MariaDB 10.6 / 11.4 uyumlu. 038 dokunulmaz.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS resmi_tatil_takvimi (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tarih DATE NOT NULL,
  tatil_kodu VARCHAR(64) NOT NULL,
  tatil_adi VARCHAR(255) NOT NULL,
  tatil_turu ENUM('UBGT', 'DIGER') NOT NULL DEFAULT 'UBGT',
  gun_kapsami ENUM('TAM_GUN', 'YARIM_GUN') NOT NULL,
  tatil_interval_baslangic TIME NULL,
  tatil_interval_bitis TIME NULL,
  durum ENUM('TASLAK', 'AKTIF', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
  kaynak_turu VARCHAR(64) NOT NULL,
  kaynak_referansi VARCHAR(255) NOT NULL,
  kaynak_tarihi DATE NULL,
  aciklama VARCHAR(1000) NULL,
  revizyon_no INT UNSIGNED NOT NULL DEFAULT 1,
  onceki_kayit_id INT UNSIGNED NULL,
  yapan_kullanici_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  iptal_edildi_at DATETIME NULL,
  iptal_eden_kullanici_id INT UNSIGNED NULL,
  iptal_gerekcesi VARCHAR(500) NULL,
  -- Ayni tarihte tek aktif UBGT revizyonu (NULL disinda unique).
  aktif_ubgt_tarih DATE AS (
    CASE WHEN durum = 'AKTIF' AND tatil_turu = 'UBGT' THEN tarih ELSE NULL END
  ) STORED,
  PRIMARY KEY (id),
  KEY idx_rtt_tarih (tarih),
  KEY idx_rtt_durum (durum),
  KEY idx_rtt_tur_kapsam (tatil_turu, gun_kapsami),
  KEY idx_rtt_onceki (onceki_kayit_id),
  UNIQUE KEY uq_rtt_aktif_ubgt_tarih (aktif_ubgt_tarih),
  CONSTRAINT fk_rtt_onceki FOREIGN KEY (onceki_kayit_id) REFERENCES resmi_tatil_takvimi (id),
  CONSTRAINT fk_rtt_yapan FOREIGN KEY (yapan_kullanici_id) REFERENCES users (id),
  CONSTRAINT fk_rtt_iptal_eden FOREIGN KEY (iptal_eden_kullanici_id) REFERENCES users (id),
  CONSTRAINT chk_rtt_interval_kapsam CHECK (
    (
      gun_kapsami = 'TAM_GUN'
      AND tatil_interval_baslangic IS NULL
      AND tatil_interval_bitis IS NULL
    )
    OR
    (
      gun_kapsami = 'YARIM_GUN'
      AND tatil_interval_baslangic IS NOT NULL
      AND tatil_interval_bitis IS NOT NULL
      AND tatil_interval_baslangic < tatil_interval_bitis
    )
  ),
  CONSTRAINT chk_rtt_iptal CHECK (
    (durum <> 'IPTAL' AND iptal_edildi_at IS NULL AND iptal_eden_kullanici_id IS NULL AND iptal_gerekcesi IS NULL)
    OR
    (durum = 'IPTAL' AND iptal_edildi_at IS NOT NULL AND iptal_gerekcesi IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resmi_tatil_takvim_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kayit_id INT UNSIGNED NULL,
  aksiyon ENUM('CREATE', 'UPDATE', 'ACTIVATE', 'REVISE', 'CANCEL') NOT NULL,
  onceki_snapshot JSON NULL,
  sonraki_snapshot JSON NULL,
  actor_id INT UNSIGNED NULL,
  actor_rol VARCHAR(40) NULL,
  request_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rtta_kayit (kayit_id, created_at),
  KEY idx_rtta_request_hash (request_hash),
  CONSTRAINT fk_rtta_kayit FOREIGN KEY (kayit_id) REFERENCES resmi_tatil_takvimi (id) ON DELETE SET NULL,
  CONSTRAINT fk_rtta_actor FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gunluk puantaj: takvim projection snapshot alanlari (mutable until seal)
ALTER TABLE gunluk_puantaj
  ADD COLUMN IF NOT EXISTS tatil_takvim_id INT UNSIGNED NULL AFTER gun_tipi,
  ADD COLUMN IF NOT EXISTS tatil_turu VARCHAR(16) NULL AFTER tatil_takvim_id,
  ADD COLUMN IF NOT EXISTS tatil_gun_kapsami VARCHAR(16) NULL AFTER tatil_turu,
  ADD COLUMN IF NOT EXISTS tatil_interval_baslangic TIME NULL AFTER tatil_gun_kapsami,
  ADD COLUMN IF NOT EXISTS tatil_interval_bitis TIME NULL AFTER tatil_interval_baslangic,
  ADD COLUMN IF NOT EXISTS tatil_siniflandirma_durumu VARCHAR(32) NULL AFTER tatil_interval_bitis,
  ADD COLUMN IF NOT EXISTS tatil_snapshot_hash CHAR(64) NULL AFTER tatil_siniflandirma_durumu,
  ADD COLUMN IF NOT EXISTS tatil_kaynak_referansi VARCHAR(255) NULL AFTER tatil_snapshot_hash,
  ADD COLUMN IF NOT EXISTS tatil_donemi_brut_calisma_dakika INT UNSIGNED NULL AFTER tatil_kaynak_referansi,
  ADD COLUMN IF NOT EXISTS tatil_donemi_ara_dinlenme_dakika INT UNSIGNED NULL AFTER tatil_donemi_brut_calisma_dakika,
  ADD COLUMN IF NOT EXISTS tatil_donemi_net_calisma_dakika INT UNSIGNED NULL AFTER tatil_donemi_ara_dinlenme_dakika;

-- Muhur satirlari: payroll immutable snapshot (authoritative for Engine V2)
ALTER TABLE puantaj_aylik_muhur_satirlari
  ADD COLUMN IF NOT EXISTS tatil_takvim_id INT UNSIGNED NULL AFTER gun_tipi,
  ADD COLUMN IF NOT EXISTS tatil_turu VARCHAR(16) NULL AFTER tatil_takvim_id,
  ADD COLUMN IF NOT EXISTS tatil_gun_kapsami VARCHAR(16) NULL AFTER tatil_turu,
  ADD COLUMN IF NOT EXISTS tatil_interval_baslangic TIME NULL AFTER tatil_gun_kapsami,
  ADD COLUMN IF NOT EXISTS tatil_interval_bitis TIME NULL AFTER tatil_interval_baslangic,
  ADD COLUMN IF NOT EXISTS tatil_siniflandirma_durumu VARCHAR(32) NULL AFTER tatil_interval_bitis,
  ADD COLUMN IF NOT EXISTS tatil_snapshot_hash CHAR(64) NULL AFTER tatil_siniflandirma_durumu,
  ADD COLUMN IF NOT EXISTS tatil_kaynak_referansi VARCHAR(255) NULL AFTER tatil_snapshot_hash,
  ADD COLUMN IF NOT EXISTS tatil_donemi_brut_calisma_dakika INT UNSIGNED NULL AFTER tatil_kaynak_referansi,
  ADD COLUMN IF NOT EXISTS tatil_donemi_ara_dinlenme_dakika INT UNSIGNED NULL AFTER tatil_donemi_brut_calisma_dakika,
  ADD COLUMN IF NOT EXISTS tatil_donemi_net_calisma_dakika INT UNSIGNED NULL AFTER tatil_donemi_ara_dinlenme_dakika;

-- FK'ler ayri (IF NOT EXISTS desteklenmez; tekrar apply icin bilgi_schema korumasi)
SET @fk_gp := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_puantaj'
    AND CONSTRAINT_NAME = 'fk_gp_tatil_takvim'
);
SET @sql_gp := IF(
  @fk_gp = 0,
  'ALTER TABLE gunluk_puantaj ADD CONSTRAINT fk_gp_tatil_takvim FOREIGN KEY (tatil_takvim_id) REFERENCES resmi_tatil_takvimi (id)',
  'DO 0'
);
PREPARE stmt_gp FROM @sql_gp;
EXECUTE stmt_gp;
DEALLOCATE PREPARE stmt_gp;

SET @fk_ms := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari'
    AND CONSTRAINT_NAME = 'fk_pams_tatil_takvim'
);
SET @sql_ms := IF(
  @fk_ms = 0,
  'ALTER TABLE puantaj_aylik_muhur_satirlari ADD CONSTRAINT fk_pams_tatil_takvim FOREIGN KEY (tatil_takvim_id) REFERENCES resmi_tatil_takvimi (id)',
  'DO 0'
);
PREPARE stmt_ms FROM @sql_ms;
EXECUTE stmt_ms;
DEALLOCATE PREPARE stmt_ms;

SET @idx_gp := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_puantaj'
    AND INDEX_NAME = 'idx_gp_tatil_sinif'
);
SET @sql_idx_gp := IF(
  @idx_gp = 0,
  'ALTER TABLE gunluk_puantaj ADD KEY idx_gp_tatil_sinif (tatil_siniflandirma_durumu, tarih)',
  'DO 0'
);
PREPARE stmt_idx_gp FROM @sql_idx_gp;
EXECUTE stmt_idx_gp;
DEALLOCATE PREPARE stmt_idx_gp;
