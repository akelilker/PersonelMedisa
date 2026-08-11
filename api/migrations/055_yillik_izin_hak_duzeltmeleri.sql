-- S2B: append-only annual leave entitlement adjustment ledger.
-- Additive only. No seed / production backfill.
-- MariaDB 10.6 / 11.4. PHP 7.4 runtime uyumlu schema.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS yillik_izin_hak_duzeltmeleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  gun_delta INT NOT NULL,
  kategori ENUM('DEVIR', 'EK_HAK', 'DUZELTME', 'TERS_KAYIT') NOT NULL,
  aciklama TEXT NOT NULL,
  effective_date DATE NOT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reverses_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_yihd_reverses_id (reverses_id),
  KEY idx_yihd_personel_effective (personel_id, effective_date, id),
  KEY idx_yihd_personel_created (personel_id, created_at, id),
  CONSTRAINT fk_yihd_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_yihd_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_yihd_reverses FOREIGN KEY (reverses_id) REFERENCES yillik_izin_hak_duzeltmeleri (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_yihd_gun_delta_nonzero CHECK (gun_delta <> 0),
  CONSTRAINT chk_yihd_ters_kayit_requires_reverses CHECK (
    (kategori = 'TERS_KAYIT' AND reverses_id IS NOT NULL)
    OR (kategori <> 'TERS_KAYIT' AND reverses_id IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
