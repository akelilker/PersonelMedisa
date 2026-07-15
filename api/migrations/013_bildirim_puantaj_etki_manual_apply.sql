-- S74-D1 Manuel inceleme aday uygulama modu kolonlari
-- Additive migration; existing operational tables are not modified.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE onayli_bildirim_puantaj_etki_adaylari
  ADD COLUMN uygulama_modu VARCHAR(16) NOT NULL DEFAULT 'OTOMATIK' AFTER uygulama_hash,
  ADD COLUMN manuel_karar_turu VARCHAR(64) NULL DEFAULT NULL AFTER uygulama_modu,
  ADD COLUMN manuel_karar_miktari INT UNSIGNED NULL DEFAULT NULL AFTER manuel_karar_turu;
