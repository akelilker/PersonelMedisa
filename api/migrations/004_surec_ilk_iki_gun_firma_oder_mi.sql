-- S62B-2A Hastalik raporu ilk 2 gun firma odeme politikasi alani
-- Additive migration; do not drop or rewrite existing data.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE surecler
  ADD COLUMN ilk_iki_gun_firma_oder_mi TINYINT(1) NULL DEFAULT NULL
  AFTER ucretli_mi;
