-- 096: PERSONEL self-service bordro "Okudum" audit (published KESINLESTI rows only).
-- Additive / forward-only. No seed. Does not mutate payroll calculation.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS personel_bordro_okumalari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  calistirma_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  okundu_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  okundu_by_user_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pbo_personel_calistirma (personel_id, calistirma_id),
  KEY idx_pbo_personel_donem (personel_id, yil, ay),
  CONSTRAINT fk_pbo_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_pbo_calistirma FOREIGN KEY (calistirma_id) REFERENCES maas_hesaplama_calistirmalari (id),
  CONSTRAINT fk_pbo_user FOREIGN KEY (okundu_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
