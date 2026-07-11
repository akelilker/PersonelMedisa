-- S71-B Haftalik bildirim mutabakati backend foundation.
-- Additive migration; existing daily notification data is preserved.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS haftalik_bildirim_mutabakatlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  birim_amiri_user_id INT UNSIGNED NOT NULL,
  hafta_baslangic DATE NOT NULL,
  hafta_bitis DATE NOT NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'TAMAMLANDI',
  onaylayan_user_id INT UNSIGNED NOT NULL,
  onaylandi_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_hbm_sube_amir_hafta (sube_id, birim_amiri_user_id, hafta_baslangic),
  KEY idx_hbm_sube_hafta (sube_id, hafta_baslangic),
  KEY idx_hbm_amir_hafta (birim_amiri_user_id, hafta_baslangic),
  KEY idx_hbm_state (state),
  CONSTRAINT fk_hbm_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_hbm_birim_amiri FOREIGN KEY (birim_amiri_user_id) REFERENCES users (id),
  CONSTRAINT fk_hbm_onaylayan FOREIGN KEY (onaylayan_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MariaDB/MySQL versions do not consistently support ADD CONSTRAINT IF NOT EXISTS.
-- Use information_schema so rerunning the migration does not try to add the FK twice.
SET @hbm_fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_bildirimler'
    AND COLUMN_NAME = 'haftalik_mutabakat_id'
    AND REFERENCED_TABLE_NAME = 'haftalik_bildirim_mutabakatlari'
    AND REFERENCED_COLUMN_NAME = 'id'
);
SET @hbm_fk_sql = IF(
  @hbm_fk_exists = 0,
  'ALTER TABLE gunluk_bildirimler ADD CONSTRAINT fk_gb_haftalik_mutabakat FOREIGN KEY (haftalik_mutabakat_id) REFERENCES haftalik_bildirim_mutabakatlari (id)',
  'SELECT 1'
);
PREPARE hbm_fk_statement FROM @hbm_fk_sql;
EXECUTE hbm_fk_statement;
DEALLOCATE PREPARE hbm_fk_statement;
