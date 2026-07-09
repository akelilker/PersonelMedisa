-- S70C-1A Gunluk bildirimler persist migration
-- Additive migration; do not drop or rewrite existing data.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS gunluk_bildirimler (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  departman_id INT UNSIGNED NULL,
  bildirim_turu VARCHAR(32) NOT NULL,
  alt_tur VARCHAR(64) NULL,
  baslangic_saati VARCHAR(8) NULL,
  bitis_saati VARCHAR(8) NULL,
  dakika INT UNSIGNED NULL,
  aciklama TEXT NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'TASLAK',
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  correction_requested_by INT UNSIGNED NULL,
  correction_reason TEXT NULL,
  haftalik_mutabakat_id INT UNSIGNED NULL,
  okundu_mi TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gb_personel_tarih (personel_id, tarih),
  KEY idx_gb_sube_tarih (sube_id, tarih),
  KEY idx_gb_state (state),
  KEY idx_gb_created_by (created_by),
  KEY idx_gb_haftalik_mutabakat (haftalik_mutabakat_id),
  CONSTRAINT fk_gb_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
