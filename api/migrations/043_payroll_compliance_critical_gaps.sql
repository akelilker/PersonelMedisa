-- S87: Kritik bordro uyum bosluklari (additive)
-- - Serbest zaman imzali talep kaniti alanlari (mevcut belge ID referansi)
-- - Audit belge referansi
-- - Sirket politika katalog genisletmesi kod tarafinda (seed yok)
-- Mevcut kayit silinmez; muhurlu bordro mutate edilmez.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Serbest zaman kanit alanlari (NULL izinli: KARAR_BEKLIYOR / UCRET icin)
SET @s87_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fazla_calisma_odeme_tercihleri'
    AND COLUMN_NAME = 'talep_tarihi'
);
SET @s87_sql := IF(
  @s87_col = 0,
  'ALTER TABLE fazla_calisma_odeme_tercihleri
     ADD COLUMN talep_tarihi DATE NULL AFTER gerekce,
     ADD COLUMN imzali_talep_belge_id INT UNSIGNED NULL AFTER talep_tarihi,
     ADD COLUMN sisteme_giren_kullanici_id INT UNSIGNED NULL AFTER imzali_talep_belge_id,
     ADD COLUMN sisteme_giris_zamani DATETIME NULL AFTER sisteme_giren_kullanici_id',
  'DO 0'
);
PREPARE s87_stmt FROM @s87_sql;
EXECUTE s87_stmt;
DEALLOCATE PREPARE s87_stmt;

SET @s87_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fazla_calisma_odeme_tercihleri'
    AND CONSTRAINT_NAME = 'fk_fcot_imzali_belge'
);
SET @s87_sql := IF(
  @s87_fk = 0,
  'ALTER TABLE fazla_calisma_odeme_tercihleri
     ADD CONSTRAINT fk_fcot_imzali_belge
       FOREIGN KEY (imzali_talep_belge_id) REFERENCES surecler (id) ON DELETE RESTRICT,
     ADD CONSTRAINT fk_fcot_sisteme_giren
       FOREIGN KEY (sisteme_giren_kullanici_id) REFERENCES users (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE s87_stmt FROM @s87_sql;
EXECUTE s87_stmt;
DEALLOCATE PREPARE s87_stmt;

SET @s87_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fazla_calisma_odeme_tercihleri'
    AND INDEX_NAME = 'idx_fcot_imzali_belge'
);
SET @s87_sql := IF(
  @s87_idx = 0,
  'ALTER TABLE fazla_calisma_odeme_tercihleri ADD KEY idx_fcot_imzali_belge (imzali_talep_belge_id)',
  'DO 0'
);
PREPARE s87_stmt FROM @s87_sql;
EXECUTE s87_stmt;
DEALLOCATE PREPARE s87_stmt;

-- Audit: belge referansi
SET @s87_audit_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fazla_calisma_odeme_tercihi_audit'
    AND COLUMN_NAME = 'imzali_talep_belge_id'
);
SET @s87_sql := IF(
  @s87_audit_col = 0,
  'ALTER TABLE fazla_calisma_odeme_tercihi_audit
     ADD COLUMN imzali_talep_belge_id INT UNSIGNED NULL AFTER gerekce,
     ADD COLUMN talep_tarihi DATE NULL AFTER imzali_talep_belge_id',
  'DO 0'
);
PREPARE s87_stmt FROM @s87_sql;
EXECUTE s87_stmt;
DEALLOCATE PREPARE s87_stmt;

SET @s87_audit_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'fazla_calisma_odeme_tercihi_audit'
    AND CONSTRAINT_NAME = 'fk_fcota_imzali_belge'
);
SET @s87_sql := IF(
  @s87_audit_fk = 0,
  'ALTER TABLE fazla_calisma_odeme_tercihi_audit
     ADD CONSTRAINT fk_fcota_imzali_belge
       FOREIGN KEY (imzali_talep_belge_id) REFERENCES surecler (id) ON DELETE RESTRICT',
  'DO 0'
);
PREPARE s87_stmt FROM @s87_sql;
EXECUTE s87_stmt;
DEALLOCATE PREPARE s87_stmt;

-- Yillik 270 saat kapanis kilidi (es zamanli cift sayim onleme)
CREATE TABLE IF NOT EXISTS yillik_fazla_calisma_kilitleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  locked_at DATETIME NOT NULL,
  locked_by INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_yfck_personel_yil (personel_id, yil),
  CONSTRAINT fk_yfck_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT,
  CONSTRAINT fk_yfck_locked_by FOREIGN KEY (locked_by) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
