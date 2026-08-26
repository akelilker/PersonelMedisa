-- 072: Organization reference short codes (bolumler / birimler.kisa_kod).
-- PREPARE ONLY / production apply requires a separate operational gate.
--
-- Schema: nullable VARCHAR(16) display codes. No UNIQUE (same code may appear in
-- different org branches). Numeric bolum_id / birim_id PK/FK semantics unchanged.
--
-- Seed: fail-closed UPDATE by (id + exact current ad). Drift → no write.
-- Idempotent column add via information_schema. MariaDB 10.6+ / PDO PREPARE one-liners.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- 1) bolumler.kisa_kod
-- ---------------------------------------------------------------------------
SET @p072_bolum_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bolumler'
    AND COLUMN_NAME = 'kisa_kod'
);
SET @p072_bolum_sql := IF(
  @p072_bolum_col = 0,
  'ALTER TABLE bolumler ADD COLUMN kisa_kod VARCHAR(16) NULL DEFAULT NULL AFTER ad',
  'DO 0'
);
PREPARE p072_stmt FROM @p072_bolum_sql;
EXECUTE p072_stmt;
DEALLOCATE PREPARE p072_stmt;

-- ---------------------------------------------------------------------------
-- 2) birimler.kisa_kod
-- ---------------------------------------------------------------------------
SET @p072_birim_col := (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'birimler'
    AND COLUMN_NAME = 'kisa_kod'
);
SET @p072_birim_sql := IF(
  @p072_birim_col = 0,
  'ALTER TABLE birimler ADD COLUMN kisa_kod VARCHAR(16) NULL DEFAULT NULL AFTER ad',
  'DO 0'
);
PREPARE p072_stmt FROM @p072_birim_sql;
EXECUTE p072_stmt;
DEALLOCATE PREPARE p072_stmt;

-- ---------------------------------------------------------------------------
-- 3) Authoritative production bolumler map (id + exact ad)
-- Inventory source: production GET /referans/bolumler (+ known PASIF id=5).
-- ---------------------------------------------------------------------------
UPDATE bolumler SET kisa_kod = 'DÖŞ' WHERE id = 1 AND ad = 'Döşeme Atölyesi';
UPDATE bolumler SET kisa_kod = 'PAT' WHERE id = 2 AND ad = 'Panel Atölyesi';
UPDATE bolumler SET kisa_kod = 'ÜRT' WHERE id = 3 AND ad = 'Üretim';
UPDATE bolumler SET kisa_kod = 'ÜRY' WHERE id = 4 AND ad = 'Üretim Yönetimi';
UPDATE bolumler SET kisa_kod = 'ÜRG' WHERE id = 5 AND ad = 'Üretim Genel';
UPDATE bolumler SET kisa_kod = 'FAS' WHERE id = 6 AND ad = 'Fason Takip';
UPDATE bolumler SET kisa_kod = 'DİĞ' WHERE id = 7 AND ad = 'Diğer';
UPDATE bolumler SET kisa_kod = 'FRİ' WHERE id = 8 AND ad = 'Finans ve Risk Yönetimi';
UPDATE bolumler SET kisa_kod = 'GDEP' WHERE id = 9 AND ad = 'Giresun Depo';
UPDATE bolumler SET kisa_kod = 'İHR' WHERE id = 10 AND ad = 'İhracat';
UPDATE bolumler SET kisa_kod = 'KDEP' WHERE id = 11 AND ad = 'Karabük Depo';
UPDATE bolumler SET kisa_kod = 'İİŞ' WHERE id = 12 AND ad = 'İdari İşler';
UPDATE bolumler SET kisa_kod = 'Mİİ' WHERE id = 13 AND ad = 'Mali Ve İdari İşler';
UPDATE bolumler SET kisa_kod = 'MUH' WHERE id = 14 AND ad = 'Muhasebe';
UPDATE bolumler SET kisa_kod = 'SD' WHERE id = 15 AND ad = 'Satış Destek';
UPDATE bolumler SET kisa_kod = 'SSH' WHERE id = 16 AND ad = 'Satış Sonrası Hizmetler';
UPDATE bolumler SET kisa_kod = 'BEBS' WHERE id = 17 AND ad = 'Beyaz Eşya Bayi Satış';
UPDATE bolumler SET kisa_kod = 'BEP' WHERE id = 18 AND ad = 'Beyaz Eşya Pazarlama';
UPDATE bolumler SET kisa_kod = 'İZS' WHERE id = 19 AND ad = 'İzmir Showroom';
UPDATE bolumler SET kisa_kod = 'KYS' WHERE id = 20 AND ad = 'Kayseri Showroom';
UPDATE bolumler SET kisa_kod = 'MS' WHERE id = 21 AND ad = 'Mobilya Satış';
UPDATE bolumler SET kisa_kod = 'YNT' WHERE id = 22 AND ad = 'Yönetim';

-- ---------------------------------------------------------------------------
-- 4) Authoritative production birimler map (id + exact ad)
-- Inventory source: production GET /referans/birimler.
-- Duplicate short codes across branches are intentional (no UNIQUE).
-- ---------------------------------------------------------------------------
UPDATE birimler SET kisa_kod = 'ÇAK' WHERE id = 1 AND ad = 'Çakım';
UPDATE birimler SET kisa_kod = 'DEM' WHERE id = 2 AND ad = 'Demir';
UPDATE birimler SET kisa_kod = 'İSK' WHERE id = 3 AND ad = 'İskelet';
UPDATE birimler SET kisa_kod = 'MAK' WHERE id = 4 AND ad = 'Makine';
UPDATE birimler SET kisa_kod = 'ÜRT' WHERE id = 5 AND ad = 'Üretim';
UPDATE birimler SET kisa_kod = 'DİK' WHERE id = 6 AND ad = 'Dikim';
UPDATE birimler SET kisa_kod = 'ÜRY' WHERE id = 7 AND ad = 'Üretim Yönetimi';
UPDATE birimler SET kisa_kod = 'DÖP' WHERE id = 8 AND ad = 'Döşeme Paketleme';
UPDATE birimler SET kisa_kod = 'PAP' WHERE id = 9 AND ad = 'Panel Paketleme';
UPDATE birimler SET kisa_kod = 'GÜV' WHERE id = 10 AND ad = 'Güvenlik';
UPDATE birimler SET kisa_kod = 'ÖÖİ' WHERE id = 11 AND ad = 'Özel Ölçülü İşler';
UPDATE birimler SET kisa_kod = 'FAS' WHERE id = 12 AND ad = 'Fason Takip';
UPDATE birimler SET kisa_kod = 'DÖŞ' WHERE id = 13 AND ad = 'Döşeme Atölyesi';
UPDATE birimler SET kisa_kod = 'PAT' WHERE id = 14 AND ad = 'Panel Atölyesi';
UPDATE birimler SET kisa_kod = 'DİĞ' WHERE id = 15 AND ad = 'Diğer';
UPDATE birimler SET kisa_kod = 'FRİ' WHERE id = 16 AND ad = 'Finans ve Risk Yönetimi';
UPDATE birimler SET kisa_kod = 'DEP' WHERE id = 17 AND ad = 'Depo';
UPDATE birimler SET kisa_kod = 'SVK' WHERE id = 18 AND ad = 'Sevkiyat';
UPDATE birimler SET kisa_kod = 'İHR' WHERE id = 19 AND ad = 'İhracat';
UPDATE birimler SET kisa_kod = 'DEP' WHERE id = 20 AND ad = 'Depo';
UPDATE birimler SET kisa_kod = 'SVK' WHERE id = 21 AND ad = 'Sevkiyat';
UPDATE birimler SET kisa_kod = 'İİŞ' WHERE id = 22 AND ad = 'İdari İşler';
UPDATE birimler SET kisa_kod = 'Mİİ' WHERE id = 23 AND ad = 'Mali Ve İdari İşler';
UPDATE birimler SET kisa_kod = 'MUH' WHERE id = 24 AND ad = 'Muhasebe';
UPDATE birimler SET kisa_kod = 'SD' WHERE id = 25 AND ad = 'Satış Destek';
UPDATE birimler SET kisa_kod = 'SSH' WHERE id = 26 AND ad = 'Satış Sonrası Hizmetler';
UPDATE birimler SET kisa_kod = 'BEBS' WHERE id = 27 AND ad = 'Beyaz Eşya Bayi Satış';
UPDATE birimler SET kisa_kod = 'BMS' WHERE id = 28 AND ad = 'Büyük Müşteri Satış';
UPDATE birimler SET kisa_kod = 'İZS' WHERE id = 29 AND ad = 'İzmir Showroom';
UPDATE birimler SET kisa_kod = 'KYS' WHERE id = 30 AND ad = 'Kayseri Showroom';
UPDATE birimler SET kisa_kod = 'MS' WHERE id = 31 AND ad = 'Mobilya Satış';
UPDATE birimler SET kisa_kod = 'YNT' WHERE id = 32 AND ad = 'Yönetim';
