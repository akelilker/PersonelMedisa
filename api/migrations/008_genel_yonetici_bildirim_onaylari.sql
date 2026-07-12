-- S73-B Genel yonetici bildirim ust onayi migration
-- Additive migration; legacy aylik ozet and bildirim tables are not modified.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS genel_yonetici_bildirim_onaylari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  birim_amiri_user_id INT UNSIGNED NOT NULL,
  ay CHAR(7) NOT NULL,
  aylik_bildirim_onayi_id INT UNSIGNED NOT NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'TAMAMLANDI',
  onaylayan_user_id INT UNSIGNED NOT NULL,
  onaylandi_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aciklama TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_gybo_sube_amir_ay (sube_id, birim_amiri_user_id, ay),
  KEY idx_gybo_sube_ay (sube_id, ay),
  KEY idx_gybo_amir_ay (birim_amiri_user_id, ay),
  KEY idx_gybo_aylik_onay (aylik_bildirim_onayi_id),
  KEY idx_gybo_onaylayan (onaylayan_user_id),
  KEY idx_gybo_state (state),
  CONSTRAINT fk_gybo_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_gybo_birim_amiri FOREIGN KEY (birim_amiri_user_id) REFERENCES users (id),
  CONSTRAINT fk_gybo_aylik_onay FOREIGN KEY (aylik_bildirim_onayi_id) REFERENCES aylik_bildirim_onaylari (id),
  CONSTRAINT fk_gybo_onaylayan FOREIGN KEY (onaylayan_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
