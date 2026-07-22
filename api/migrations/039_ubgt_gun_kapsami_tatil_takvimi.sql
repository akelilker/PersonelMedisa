-- S88: UBGT gun kapsami + resmi tatil takvimi owner
-- Additive only. Production seed / gercek tatil tarihi YOK.
-- MariaDB 10.6 / 11.4 uyumlu. 038 dokunulmaz.
-- S88-A: audit RESTRICT + snapshot CHECK hardening.

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
  CONSTRAINT fk_rtt_onceki FOREIGN KEY (onceki_kayit_id) REFERENCES resmi_tatil_takvimi (id) ON DELETE RESTRICT,
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
  ),
  CONSTRAINT chk_rtt_kaynak_dolu CHECK (
    CHAR_LENGTH(TRIM(kaynak_turu)) > 0 AND CHAR_LENGTH(TRIM(kaynak_referansi)) > 0
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resmi_tatil_takvim_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kayit_id INT UNSIGNED NOT NULL,
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
  CONSTRAINT fk_rtta_kayit FOREIGN KEY (kayit_id) REFERENCES resmi_tatil_takvimi (id) ON DELETE RESTRICT,
  CONSTRAINT fk_rtta_actor FOREIGN KEY (actor_id) REFERENCES users (id),
  CONSTRAINT chk_rtta_request_hash CHECK (
    request_hash IS NULL OR request_hash REGEXP '^[0-9a-f]{64}$'
  )
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

-- Snapshot CHECK owners (idempotent). Existing NULL rows uyumlu.
SET @chk_gp_tur := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'gunluk_puantaj' AND CONSTRAINT_NAME = 'chk_gp_tatil_turu'
);
SET @sql_chk_gp_tur := IF(
  @chk_gp_tur = 0,
  'ALTER TABLE gunluk_puantaj ADD CONSTRAINT chk_gp_tatil_turu CHECK (tatil_turu IS NULL OR tatil_turu IN (''UBGT'', ''DIGER''))',
  'DO 0'
);
PREPARE stmt_chk_gp_tur FROM @sql_chk_gp_tur;
EXECUTE stmt_chk_gp_tur;
DEALLOCATE PREPARE stmt_chk_gp_tur;

SET @chk_gp_kapsam := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'gunluk_puantaj' AND CONSTRAINT_NAME = 'chk_gp_tatil_gun_kapsami'
);
SET @sql_chk_gp_kapsam := IF(
  @chk_gp_kapsam = 0,
  'ALTER TABLE gunluk_puantaj ADD CONSTRAINT chk_gp_tatil_gun_kapsami CHECK (tatil_gun_kapsami IS NULL OR tatil_gun_kapsami IN (''TAM_GUN'', ''YARIM_GUN''))',
  'DO 0'
);
PREPARE stmt_chk_gp_kapsam FROM @sql_chk_gp_kapsam;
EXECUTE stmt_chk_gp_kapsam;
DEALLOCATE PREPARE stmt_chk_gp_kapsam;

SET @chk_gp_sinif := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'gunluk_puantaj' AND CONSTRAINT_NAME = 'chk_gp_tatil_sinif'
);
SET @sql_chk_gp_sinif := IF(
  @chk_gp_sinif = 0,
  'ALTER TABLE gunluk_puantaj ADD CONSTRAINT chk_gp_tatil_sinif CHECK (tatil_siniflandirma_durumu IS NULL OR tatil_siniflandirma_durumu IN (''DOGRULANDI'', ''BILINMIYOR'', ''CAKISMA'', ''KAYNAK_EKSIK''))',
  'DO 0'
);
PREPARE stmt_chk_gp_sinif FROM @sql_chk_gp_sinif;
EXECUTE stmt_chk_gp_sinif;
DEALLOCATE PREPARE stmt_chk_gp_sinif;

SET @chk_gp_hash := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'gunluk_puantaj' AND CONSTRAINT_NAME = 'chk_gp_tatil_hash'
);
SET @sql_chk_gp_hash := IF(
  @chk_gp_hash = 0,
  'ALTER TABLE gunluk_puantaj ADD CONSTRAINT chk_gp_tatil_hash CHECK (tatil_snapshot_hash IS NULL OR tatil_snapshot_hash REGEXP ''^[0-9a-f]{64}$'')',
  'DO 0'
);
PREPARE stmt_chk_gp_hash FROM @sql_chk_gp_hash;
EXECUTE stmt_chk_gp_hash;
DEALLOCATE PREPARE stmt_chk_gp_hash;

SET @chk_gp_dk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'gunluk_puantaj' AND CONSTRAINT_NAME = 'chk_gp_tatil_dakika'
);
SET @sql_chk_gp_dk := IF(
  @chk_gp_dk = 0,
  'ALTER TABLE gunluk_puantaj ADD CONSTRAINT chk_gp_tatil_dakika CHECK (
    (tatil_donemi_brut_calisma_dakika IS NULL OR tatil_donemi_brut_calisma_dakika >= 0)
    AND (tatil_donemi_ara_dinlenme_dakika IS NULL OR tatil_donemi_ara_dinlenme_dakika >= 0)
    AND (tatil_donemi_net_calisma_dakika IS NULL OR tatil_donemi_net_calisma_dakika >= 0)
    AND (tatil_donemi_brut_calisma_dakika IS NULL OR tatil_donemi_net_calisma_dakika IS NULL OR tatil_donemi_net_calisma_dakika <= tatil_donemi_brut_calisma_dakika)
    AND (tatil_donemi_brut_calisma_dakika IS NULL OR tatil_donemi_ara_dinlenme_dakika IS NULL OR tatil_donemi_ara_dinlenme_dakika <= tatil_donemi_brut_calisma_dakika)
  )',
  'DO 0'
);
PREPARE stmt_chk_gp_dk FROM @sql_chk_gp_dk;
EXECUTE stmt_chk_gp_dk;
DEALLOCATE PREPARE stmt_chk_gp_dk;

SET @chk_gp_iv := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'gunluk_puantaj' AND CONSTRAINT_NAME = 'chk_gp_tatil_interval'
);
SET @sql_chk_gp_iv := IF(
  @chk_gp_iv = 0,
  'ALTER TABLE gunluk_puantaj ADD CONSTRAINT chk_gp_tatil_interval CHECK (
    (
      tatil_gun_kapsami IS NULL
      OR tatil_gun_kapsami <> ''TAM_GUN''
      OR (tatil_interval_baslangic IS NULL AND tatil_interval_bitis IS NULL)
    )
    AND (
      tatil_siniflandirma_durumu IS NULL
      OR tatil_siniflandirma_durumu <> ''DOGRULANDI''
      OR tatil_gun_kapsami IS NULL
      OR tatil_gun_kapsami <> ''YARIM_GUN''
      OR (
        tatil_interval_baslangic IS NOT NULL
        AND tatil_interval_bitis IS NOT NULL
        AND tatil_interval_baslangic < tatil_interval_bitis
      )
    )
  )',
  'DO 0'
);
PREPARE stmt_chk_gp_iv FROM @sql_chk_gp_iv;
EXECUTE stmt_chk_gp_iv;
DEALLOCATE PREPARE stmt_chk_gp_iv;

SET @chk_ms_tur := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari' AND CONSTRAINT_NAME = 'chk_pams_tatil_turu'
);
SET @sql_chk_ms_tur := IF(
  @chk_ms_tur = 0,
  'ALTER TABLE puantaj_aylik_muhur_satirlari ADD CONSTRAINT chk_pams_tatil_turu CHECK (tatil_turu IS NULL OR tatil_turu IN (''UBGT'', ''DIGER''))',
  'DO 0'
);
PREPARE stmt_chk_ms_tur FROM @sql_chk_ms_tur;
EXECUTE stmt_chk_ms_tur;
DEALLOCATE PREPARE stmt_chk_ms_tur;

SET @chk_ms_kapsam := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari' AND CONSTRAINT_NAME = 'chk_pams_tatil_gun_kapsami'
);
SET @sql_chk_ms_kapsam := IF(
  @chk_ms_kapsam = 0,
  'ALTER TABLE puantaj_aylik_muhur_satirlari ADD CONSTRAINT chk_pams_tatil_gun_kapsami CHECK (tatil_gun_kapsami IS NULL OR tatil_gun_kapsami IN (''TAM_GUN'', ''YARIM_GUN''))',
  'DO 0'
);
PREPARE stmt_chk_ms_kapsam FROM @sql_chk_ms_kapsam;
EXECUTE stmt_chk_ms_kapsam;
DEALLOCATE PREPARE stmt_chk_ms_kapsam;

SET @chk_ms_sinif := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari' AND CONSTRAINT_NAME = 'chk_pams_tatil_sinif'
);
SET @sql_chk_ms_sinif := IF(
  @chk_ms_sinif = 0,
  'ALTER TABLE puantaj_aylik_muhur_satirlari ADD CONSTRAINT chk_pams_tatil_sinif CHECK (tatil_siniflandirma_durumu IS NULL OR tatil_siniflandirma_durumu IN (''DOGRULANDI'', ''BILINMIYOR'', ''CAKISMA'', ''KAYNAK_EKSIK''))',
  'DO 0'
);
PREPARE stmt_chk_ms_sinif FROM @sql_chk_ms_sinif;
EXECUTE stmt_chk_ms_sinif;
DEALLOCATE PREPARE stmt_chk_ms_sinif;

SET @chk_ms_hash := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari' AND CONSTRAINT_NAME = 'chk_pams_tatil_hash'
);
SET @sql_chk_ms_hash := IF(
  @chk_ms_hash = 0,
  'ALTER TABLE puantaj_aylik_muhur_satirlari ADD CONSTRAINT chk_pams_tatil_hash CHECK (tatil_snapshot_hash IS NULL OR tatil_snapshot_hash REGEXP ''^[0-9a-f]{64}$'')',
  'DO 0'
);
PREPARE stmt_chk_ms_hash FROM @sql_chk_ms_hash;
EXECUTE stmt_chk_ms_hash;
DEALLOCATE PREPARE stmt_chk_ms_hash;

SET @chk_ms_dk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari' AND CONSTRAINT_NAME = 'chk_pams_tatil_dakika'
);
SET @sql_chk_ms_dk := IF(
  @chk_ms_dk = 0,
  'ALTER TABLE puantaj_aylik_muhur_satirlari ADD CONSTRAINT chk_pams_tatil_dakika CHECK (
    (tatil_donemi_brut_calisma_dakika IS NULL OR tatil_donemi_brut_calisma_dakika >= 0)
    AND (tatil_donemi_ara_dinlenme_dakika IS NULL OR tatil_donemi_ara_dinlenme_dakika >= 0)
    AND (tatil_donemi_net_calisma_dakika IS NULL OR tatil_donemi_net_calisma_dakika >= 0)
    AND (tatil_donemi_brut_calisma_dakika IS NULL OR tatil_donemi_net_calisma_dakika IS NULL OR tatil_donemi_net_calisma_dakika <= tatil_donemi_brut_calisma_dakika)
    AND (tatil_donemi_brut_calisma_dakika IS NULL OR tatil_donemi_ara_dinlenme_dakika IS NULL OR tatil_donemi_ara_dinlenme_dakika <= tatil_donemi_brut_calisma_dakika)
  )',
  'DO 0'
);
PREPARE stmt_chk_ms_dk FROM @sql_chk_ms_dk;
EXECUTE stmt_chk_ms_dk;
DEALLOCATE PREPARE stmt_chk_ms_dk;

SET @chk_ms_iv := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'puantaj_aylik_muhur_satirlari' AND CONSTRAINT_NAME = 'chk_pams_tatil_interval'
);
SET @sql_chk_ms_iv := IF(
  @chk_ms_iv = 0,
  'ALTER TABLE puantaj_aylik_muhur_satirlari ADD CONSTRAINT chk_pams_tatil_interval CHECK (
    (
      tatil_gun_kapsami IS NULL
      OR tatil_gun_kapsami <> ''TAM_GUN''
      OR (tatil_interval_baslangic IS NULL AND tatil_interval_bitis IS NULL)
    )
    AND (
      tatil_siniflandirma_durumu IS NULL
      OR tatil_siniflandirma_durumu <> ''DOGRULANDI''
      OR tatil_gun_kapsami IS NULL
      OR tatil_gun_kapsami <> ''YARIM_GUN''
      OR (
        tatil_interval_baslangic IS NOT NULL
        AND tatil_interval_bitis IS NOT NULL
        AND tatil_interval_baslangic < tatil_interval_bitis
      )
    )
  )',
  'DO 0'
);
PREPARE stmt_chk_ms_iv FROM @sql_chk_ms_iv;
EXECUTE stmt_chk_ms_iv;
DEALLOCATE PREPARE stmt_chk_ms_iv;
