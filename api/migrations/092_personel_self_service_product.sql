-- 092: PERSONEL self-service product domains (advance request, feedback, announcements, profile photo).
-- Additive / forward-only. No seed. Does not alter ek_odeme, surecler, inbox, or attendance tables.
-- APPLY is a separate gate. This file is not executed by the application.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS personel_avans_talepleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  tutar DECIMAL(12,2) NOT NULL,
  talep_tarihi DATE NOT NULL,
  aciklama VARCHAR(500) NULL,
  durum ENUM('BEKLIYOR', 'ONAYLANDI', 'REDDEDILDI') NOT NULL DEFAULT 'BEKLIYOR',
  sonuc VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_avans_talep_personel (personel_id, talep_tarihi),
  CONSTRAINT fk_avans_talep_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_avans_talep_tutar CHECK (tutar > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Finance AVANS (ek_odeme) is intentionally not referenced. A request row is not a payroll mutation.

CREATE TABLE IF NOT EXISTS personel_geri_bildirimler (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  tur ENUM('ONERI', 'SIKAYET') NOT NULL,
  konu VARCHAR(200) NOT NULL,
  aciklama VARCHAR(2000) NOT NULL,
  durum ENUM('ALINDI', 'SONUCLANDI') NOT NULL DEFAULT 'ALINDI',
  sonuc VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_geri_bildirim_personel (personel_id, created_at),
  CONSTRAINT fk_geri_bildirim_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS duyurular (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  baslik VARCHAR(200) NOT NULL,
  aciklama TEXT NOT NULL,
  yayin_tarihi DATE NOT NULL,
  bitis_tarihi DATE NULL,
  aktif TINYINT(1) NOT NULL DEFAULT 1,
  sube_id INT UNSIGNED NULL,
  bolum_id INT UNSIGNED NULL,
  birim_id INT UNSIGNED NULL,
  created_by_user_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_duyuru_yayin (aktif, yayin_tarihi, bitis_tarihi),
  KEY idx_duyuru_scope (sube_id, bolum_id, birim_id),
  CONSTRAINT fk_duyuru_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_duyuru_bolum FOREIGN KEY (bolum_id) REFERENCES bolumler (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_duyuru_birim FOREIGN KEY (birim_id) REFERENCES birimler (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_duyuru_user FOREIGN KEY (created_by_user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS duyuru_okumalari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  duyuru_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  okundu_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_duyuru_okuma (duyuru_id, personel_id),
  KEY idx_duyuru_okuma_personel (personel_id),
  CONSTRAINT fk_duyuru_okuma_duyuru FOREIGN KEY (duyuru_id) REFERENCES duyurular (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_duyuru_okuma_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personel_profil_fotograflari (
  personel_id INT UNSIGNED NOT NULL,
  storage_key VARCHAR(64) NOT NULL,
  mime_type VARCHAR(32) NOT NULL,
  byte_boyutu INT UNSIGNED NOT NULL,
  sha256 CHAR(64) NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (personel_id),
  CONSTRAINT fk_profil_foto_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
