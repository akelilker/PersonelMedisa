-- S72-B Aylik bildirim onayi migration
-- Additive migration; existing puantaj/rapor tables are not modified.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS aylik_bildirim_onaylari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  birim_amiri_user_id INT UNSIGNED NOT NULL,
  ay CHAR(7) NOT NULL,
  ay_baslangic DATE NOT NULL,
  ay_bitis DATE NOT NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'TAMAMLANDI',
  onaylayan_user_id INT UNSIGNED NOT NULL,
  onaylandi_at TIMESTAMP NULL DEFAULT NULL,
  aciklama TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_abo_sube_amir_ay (sube_id, birim_amiri_user_id, ay),
  KEY idx_abo_sube_ay (sube_id, ay),
  KEY idx_abo_amir_ay (birim_amiri_user_id, ay),
  KEY idx_abo_state (state),
  CONSTRAINT fk_abo_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_abo_birim_amiri FOREIGN KEY (birim_amiri_user_id) REFERENCES users (id),
  CONSTRAINT fk_abo_onaylayan FOREIGN KEY (onaylayan_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
