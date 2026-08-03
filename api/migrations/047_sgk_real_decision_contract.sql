-- S98-R1: Real SGK decision contract additive schema.
-- Adds canonical surec turleri MAZERET_IZNI + KISMI_SURE_DEVAMSIZLIK.
-- Adds optional puantaj eksik gun neden tipi (dynamic code derivation).
-- Adds manual SGK code override audit owner.
-- eksik_gun_kodu already nullable since 036 — unchanged.
-- kosullar_json already exists since 036 — unchanged (app-layer enum contract).
-- Idempotent / MariaDB 10.6+ 11.4 compatible.
-- Production apply NOT in this phase (code artifact only).
-- Down: reverse ALTER ENUM to prior list; DROP new column/table if unused.
-- 036-046 untouched.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1) Canonical ENUM expansion (existing rows preserved).
ALTER TABLE sgk_surec_neden_eslemeleri
  MODIFY COLUMN canonical_surec_turu ENUM(
    'HASTALIK',
    'IS_KAZASI',
    'MESLEK_HASTALIGI',
    'ANALIK',
    'UCRETSIZ_IZIN',
    'YILLIK_IZIN',
    'MAZERET_IZNI',
    'MAZERETSIZ_DEVAMSIZLIK',
    'KISMI_SURELI_CALISMA',
    'KISMI_SURE_DEVAMSIZLIK',
    'PUANTAJ_EKSIK_GUN',
    'DIGER_MANUEL_INCELEME'
  ) NOT NULL;

-- 2) Puantaj: optional typed reason for dynamic SGK code (OLAYDAN_TURET).
-- NULL = unresolved / not applicable. No silent default.
SET @col_exists := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gunluk_puantaj'
    AND COLUMN_NAME = 'sgk_eksik_gun_neden_tipi'
);
SET @sql_add_col := IF(
  @col_exists = 0,
  'ALTER TABLE gunluk_puantaj ADD COLUMN sgk_eksik_gun_neden_tipi ENUM(
      ''ISTIRAHAT'',
      ''KISMI_ISTIHDAM'',
      ''TAM_GUN_DEVAMSIZLIK'',
      ''GENEL_UCRETSIZ_IZIN'',
      ''BILINMIYOR''
    ) NULL AFTER hesap_etkisi',
  'SELECT 1'
);
PREPARE stmt_add_col FROM @sql_add_col;
EXECUTE stmt_add_col;
DEALLOCATE PREPARE stmt_add_col;

-- 3) Manual SGK code override audit (authorized + justified + documented).
CREATE TABLE IF NOT EXISTS sgk_manuel_kod_override_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  surec_id INT UNSIGNED NULL,
  tarih DATE NOT NULL,
  onceki_eksik_gun_kodu VARCHAR(8) NULL,
  yeni_eksik_gun_kodu VARCHAR(8) NOT NULL,
  gerekce VARCHAR(1000) NOT NULL,
  belge_id INT UNSIGNED NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sgk_mkoa_personel_tarih (personel_id, tarih),
  KEY idx_sgk_mkoa_surec (surec_id),
  CONSTRAINT fk_sgk_mkoa_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_sgk_mkoa_surec FOREIGN KEY (surec_id) REFERENCES surecler (id),
  CONSTRAINT fk_sgk_mkoa_actor FOREIGN KEY (actor_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
