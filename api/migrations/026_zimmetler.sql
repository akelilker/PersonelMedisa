-- S78-C2: persistent zimmetler table for POST/GET /zimmetler owners
-- Additive only. Matches active TS CreateZimmetPayload / Zimmet contract.
-- Scope is derived via personeller.sube_id (no denormalized sube_id column).
-- Serial numbers live in free-text aciklama; no unique serial constraint (product allows duplicates).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS zimmetler (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  urun_turu VARCHAR(32) NOT NULL,
  teslim_tarihi DATE NOT NULL,
  teslim_eden VARCHAR(120) NOT NULL,
  aciklama TEXT NULL,
  teslim_durumu VARCHAR(32) NOT NULL,
  zimmet_durumu VARCHAR(32) NOT NULL DEFAULT 'AKTIF',
  iade_tarihi DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_zimmetler_personel (personel_id),
  KEY idx_zimmetler_personel_durum (personel_id, zimmet_durumu),
  CONSTRAINT fk_zimmetler_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
