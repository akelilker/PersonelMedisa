-- S74-D1/D3R Puantaj donem transaction kilidi
-- Additive guard table; operational puantaj and bildirim rows are not modified.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS puantaj_donem_kilitleri (
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (sube_id, yil, ay),
  CONSTRAINT chk_pdk_yil CHECK (yil BETWEEN 2000 AND 2100),
  CONSTRAINT chk_pdk_ay CHECK (ay BETWEEN 1 AND 12),
  CONSTRAINT fk_pdk_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
