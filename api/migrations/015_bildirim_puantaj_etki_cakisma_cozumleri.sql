-- S75-B Bildirim puantaj etki cakisma cozumleri
-- Additive migration; existing operational rows are not modified.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS bildirim_puantaj_etki_cakisma_cozumleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  aday_id INT UNSIGNED NOT NULL,
  puantaj_id INT UNSIGNED NULL,
  sube_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  conflict_class VARCHAR(32) NOT NULL,
  karar_turu VARCHAR(64) NOT NULL,
  gerekce TEXT NOT NULL,
  expected_puantaj_hash CHAR(64) NOT NULL,
  request_hash CHAR(64) NOT NULL,
  onceki_snapshot JSON NOT NULL,
  sonraki_snapshot JSON NOT NULL,
  snapshot_schema VARCHAR(32) NOT NULL DEFAULT 'S75_CONFLICT_RESOLUTION_V1',
  sonuc_hash CHAR(64) NOT NULL,
  karar_veren_user_id INT UNSIGNED NOT NULL,
  karar_zamani DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bpecc_aday (aday_id),
  KEY idx_bpecc_sube_tarih (sube_id, tarih),
  KEY idx_bpecc_personel_tarih (personel_id, tarih),
  KEY idx_bpecc_conflict_class (conflict_class),
  KEY idx_bpecc_karar_turu (karar_turu),
  KEY idx_bpecc_karar_veren (karar_veren_user_id),
  KEY idx_bpecc_karar_zamani (karar_zamani),
  CONSTRAINT fk_bpecc_aday FOREIGN KEY (aday_id) REFERENCES onayli_bildirim_puantaj_etki_adaylari (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_bpecc_puantaj FOREIGN KEY (puantaj_id) REFERENCES gunluk_puantaj (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_bpecc_karar_veren FOREIGN KEY (karar_veren_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_bpecc_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_bpecc_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
