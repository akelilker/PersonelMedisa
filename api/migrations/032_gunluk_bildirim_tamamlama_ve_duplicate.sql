-- S81 Gunluk amir bildirim tamamlama + acik duplicate korumasi.
-- Additive; mevcut gunluk_bildirimler verisi korunur.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS gunluk_bildirim_tamamlamalari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  birim_amiri_user_id INT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'TAMAMLANDI',
  tamamlayan_user_id INT UNSIGNED NOT NULL,
  tamamlandi_at TIMESTAMP NULL DEFAULT NULL,
  not_metni TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_gbt_sube_amir_tarih (sube_id, birim_amiri_user_id, tarih),
  KEY idx_gbt_sube_tarih (sube_id, tarih),
  KEY idx_gbt_amir_tarih (birim_amiri_user_id, tarih),
  CONSTRAINT fk_gbt_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_gbt_birim_amiri FOREIGN KEY (birim_amiri_user_id) REFERENCES users (id),
  CONSTRAINT fk_gbt_tamamlayan FOREIGN KEY (tamamlayan_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acik (IPTAL olmayan) ayni personel/tarih/tur kayitlarini engelle.
-- IPTAL kayitlari icin NULL uretilir; UNIQUE birden fazla NULL'a izin verir.
SET @open_key_col_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_bildirimler'
    AND COLUMN_NAME = 'open_duplicate_key'
);
SET @open_key_col_sql = IF(
  @open_key_col_exists = 0,
  'ALTER TABLE gunluk_bildirimler
     ADD COLUMN open_duplicate_key VARCHAR(96)
       GENERATED ALWAYS AS (
         CASE
           WHEN state = ''IPTAL'' THEN NULL
           ELSE CONCAT(personel_id, '':'', tarih, '':'', bildirim_turu)
         END
       ) STORED',
  'SELECT 1'
);
PREPARE open_key_col_statement FROM @open_key_col_sql;
EXECUTE open_key_col_statement;
DEALLOCATE PREPARE open_key_col_statement;

SET @open_key_idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_bildirimler'
    AND INDEX_NAME = 'uniq_gb_open_duplicate'
);
SET @open_key_idx_sql = IF(
  @open_key_idx_exists = 0,
  'ALTER TABLE gunluk_bildirimler ADD UNIQUE KEY uniq_gb_open_duplicate (open_duplicate_key)',
  'SELECT 1'
);
PREPARE open_key_idx_statement FROM @open_key_idx_sql;
EXECUTE open_key_idx_statement;
DEALLOCATE PREPARE open_key_idx_statement;
