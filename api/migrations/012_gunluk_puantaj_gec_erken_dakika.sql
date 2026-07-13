-- S74-C3-B1 Geç kalma / erken çıkış dakika kolonları
-- Additive migration; do not drop or rewrite existing data.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE gunluk_puantaj
  ADD COLUMN gec_kalma_dakika INT UNSIGNED NULL AFTER cikis_saati,
  ADD COLUMN erken_cikis_dakika INT UNSIGNED NULL AFTER gec_kalma_dakika;

ALTER TABLE puantaj_aylik_muhur_satirlari
  ADD COLUMN gec_kalma_dakika INT UNSIGNED NULL AFTER cikis_saati,
  ADD COLUMN erken_cikis_dakika INT UNSIGNED NULL AFTER gec_kalma_dakika;
