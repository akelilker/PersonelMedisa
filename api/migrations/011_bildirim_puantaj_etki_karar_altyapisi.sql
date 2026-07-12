-- S74-C1 Bildirim puantaj etki adayi karar altyapisi
-- Additive migration; existing operational tables are not modified.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE onayli_bildirim_puantaj_etki_adaylari
  ADD COLUMN karar_veren_user_id INT UNSIGNED NULL AFTER created_by,
  ADD COLUMN karar_zamani DATETIME NULL AFTER karar_veren_user_id,
  ADD COLUMN karar_gerekcesi TEXT NULL AFTER karar_zamani,
  ADD COLUMN uygulanan_puantaj_id INT UNSIGNED NULL AFTER mevcut_puantaj_id,
  ADD COLUMN onceki_puantaj_snapshot JSON NULL AFTER uygulanan_puantaj_id,
  ADD COLUMN sonraki_puantaj_snapshot JSON NULL AFTER onceki_puantaj_snapshot,
  ADD COLUMN uygulama_hash CHAR(64) NULL AFTER sonraki_puantaj_snapshot,
  ADD KEY idx_obpea_karar_veren (karar_veren_user_id),
  ADD KEY idx_obpea_karar_zamani (karar_zamani),
  ADD KEY idx_obpea_uygulanan_puantaj (uygulanan_puantaj_id),
  ADD CONSTRAINT fk_obpea_karar_veren FOREIGN KEY (karar_veren_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  ADD CONSTRAINT fk_obpea_uygulanan_puantaj FOREIGN KEY (uygulanan_puantaj_id) REFERENCES gunluk_puantaj (id) ON DELETE RESTRICT ON UPDATE RESTRICT;
