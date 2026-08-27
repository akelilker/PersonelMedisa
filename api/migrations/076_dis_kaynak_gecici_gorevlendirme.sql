-- 076: DIS_KAYNAK operasyonel/non-financial model foundations.
-- 1) personeller.sube_id nullable (bağlantısız DIS için; placeholder şube YOK)
-- 2) personel_gecici_gorevlendirmeler — tarihli geçici görevlendirme (additive)
-- Schema only. NO personnel data mutation. NO assignment backfill. NO role changes.
-- Idempotent / MariaDB 10.6+ / PHP 7.4 PDO-compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- 1) personeller.sube_id → NULL allowed (DIS unassigned pool)
-- ---------------------------------------------------------------------------
SET @p76_sube_nullable := (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personeller'
    AND COLUMN_NAME = 'sube_id'
  LIMIT 1
);
SET @p76_sube_sql := IF(
  @p76_sube_nullable = 'NO',
  'ALTER TABLE personeller MODIFY COLUMN sube_id INT UNSIGNED NULL',
  'DO 0'
);
PREPARE p76_sube_stmt FROM @p76_sube_sql;
EXECUTE p76_sube_stmt;
DEALLOCATE PREPARE p76_sube_stmt;

-- ---------------------------------------------------------------------------
-- 2) Geçici görevlendirme tablosu
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS personel_gecici_gorevlendirmeler (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  hedef_sube_id INT UNSIGNED NOT NULL,
  hedef_departman_id INT UNSIGNED NOT NULL,
  hedef_bolum_id INT UNSIGNED NOT NULL,
  hedef_birim_id INT UNSIGNED NULL,
  baslangic_at DATETIME NOT NULL,
  bitis_at DATETIME NULL,
  durum ENUM('AKTIF', 'SONLANDIRILDI', 'IPTAL') NOT NULL DEFAULT 'AKTIF',
  olusturan_user_id INT UNSIGNED NULL,
  sonlandiran_user_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_pgg_personel_durum (personel_id, durum),
  KEY idx_pgg_bolum (hedef_bolum_id),
  KEY idx_pgg_baslangic (baslangic_at),
  CONSTRAINT fk_pgg_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pgg_sube FOREIGN KEY (hedef_sube_id) REFERENCES subeler (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pgg_bolum FOREIGN KEY (hedef_bolum_id) REFERENCES bolumler (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Aktif çakışmayı uygulama katmanı fail-closed korur; tek aktif için yardımcı index:
SET @p76_aktif_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'personel_gecici_gorevlendirmeler'
    AND INDEX_NAME = 'idx_pgg_personel_aktif'
);
SET @p76_aktif_sql := IF(
  @p76_aktif_idx = 0,
  'ALTER TABLE personel_gecici_gorevlendirmeler ADD KEY idx_pgg_personel_aktif (personel_id, durum, baslangic_at, bitis_at)',
  'DO 0'
);
PREPARE p76_aktif_stmt FROM @p76_aktif_sql;
EXECUTE p76_aktif_stmt;
DEALLOCATE PREPARE p76_aktif_stmt;
