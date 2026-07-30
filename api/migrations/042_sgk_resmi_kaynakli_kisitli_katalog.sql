-- S106: RESMI_KAYNAKLI_KISITLI katalog yayini icin additive schema.
-- gecerlilik_baslangic NULL olabilir; tarih durumu ENUM + ilk_resmi_kanit_tarihi.
-- tamlik_durumu: RESMI_KAYNAKLI_KISITLI eklenir.
-- ONAYLANDI check: RESMI_KAYNAKLI_KISITLI veya DOGRULANMIS_TAM.
-- Mevcut satirlar silinmez. Ikinci apply idempotent olmali.
-- Production'da otomatik calistirilmaz. MariaDB 10.6 / 11.4 uyumlu.
-- 036-041 dokunulmaz.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- 1) Gecerlilik baslangic: NULL izinli (bilinmeyen resmi yururluk).
ALTER TABLE sgk_eksik_gun_kodlari
  MODIFY COLUMN gecerlilik_baslangic DATE NULL;

-- 2) Tarih durumu + ilk resmi kanit (hukuki yururluk degil; gozlem tarihi).
ALTER TABLE sgk_eksik_gun_kodlari
  ADD COLUMN IF NOT EXISTS gecerlilik_tarih_durumu ENUM(
    'RESMI_YURURLUK',
    'ILK_RESMI_KANIT',
    'BELIRLENEMEDI'
  ) NOT NULL DEFAULT 'BELIRLENEMEDI' AFTER gecerlilik_baslangic,
  ADD COLUMN IF NOT EXISTS ilk_resmi_kanit_tarihi DATE NULL AFTER gecerlilik_tarih_durumu;

-- 3) Katalog surumu tamlik enum genisletmesi (mevcut degerler korunur).
ALTER TABLE sgk_eksik_gun_katalog_surumleri
  MODIFY COLUMN tamlik_durumu ENUM(
    'TASLAK',
    'RESMI_KAYNAKLI_KISITLI',
    'DOGRULANMIS_TAM'
  ) NOT NULL DEFAULT 'TASLAK';

-- 4) ONAYLANDI check: RESMI_KAYNAKLI_KISITLI veya DOGRULANMIS_TAM.
-- MariaDB: DROP CONSTRAINT + ADD (DROP CHECK bazi surumlerde syntax hatasi verir).
SET @chk_exists := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_eksik_gun_katalog_surumleri'
    AND CONSTRAINT_NAME = 'chk_sgk_egks_onay'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_drop := IF(
  @chk_exists > 0,
  'ALTER TABLE sgk_eksik_gun_katalog_surumleri DROP CONSTRAINT chk_sgk_egks_onay',
  'SELECT 1'
);
PREPARE stmt_drop FROM @sql_drop;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;

SET @chk_exists_after := (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sgk_eksik_gun_katalog_surumleri'
    AND CONSTRAINT_NAME = 'chk_sgk_egks_onay'
    AND CONSTRAINT_TYPE = 'CHECK'
);
SET @sql_add := IF(
  @chk_exists_after = 0,
  'ALTER TABLE sgk_eksik_gun_katalog_surumleri ADD CONSTRAINT chk_sgk_egks_onay CHECK (
    state <> ''ONAYLANDI''
    OR (
      tamlik_durumu IN (''RESMI_KAYNAKLI_KISITLI'', ''DOGRULANMIS_TAM'')
      AND onaylayan_id IS NOT NULL
      AND onay_zamani IS NOT NULL
    )
  )',
  'SELECT 1'
);
PREPARE stmt_add FROM @sql_add;
EXECUTE stmt_add;
DEALLOCATE PREPARE stmt_add;

-- 5) Audit attestation kolonlari (onay attestation; mevcut satirlar NULL kalabilir).
ALTER TABLE sgk_eksik_gun_katalog_surumleri
  ADD COLUMN IF NOT EXISTS resmi_kaynaklar_incelendi_mi TINYINT(1) NULL AFTER onay_zamani,
  ADD COLUMN IF NOT EXISTS belirsiz_tarihler_uydurulmadi_mi TINYINT(1) NULL AFTER resmi_kaynaklar_incelendi_mi,
  ADD COLUMN IF NOT EXISTS kisitli_kullanim_kabul_edildi_mi TINYINT(1) NULL AFTER belirsiz_tarihler_uydurulmadi_mi,
  ADD COLUMN IF NOT EXISTS katalog_payload_hash CHAR(64) NULL AFTER kisitli_kullanim_kabul_edildi_mi;
