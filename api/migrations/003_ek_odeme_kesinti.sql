-- S40B Ek odeme / kesinti finans kalemleri migration
-- Additive migration; do not drop or rewrite existing data.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS ek_odeme_kesinti (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  donem CHAR(7) NOT NULL,
  kalem_turu VARCHAR(32) NOT NULL,
  tutar DECIMAL(12,2) NOT NULL,
  gun_sayisi INT UNSIGNED NULL,
  aciklama TEXT NULL,
  state VARCHAR(16) NOT NULL DEFAULT 'AKTIF',
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_eok_personel_donem (personel_id, donem),
  KEY idx_eok_donem_kalem (donem, kalem_turu),
  KEY idx_eok_state (state),
  CONSTRAINT fk_eok_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
