-- S74-B-R3 Bildirim snapshot zaman alanlari duzeltmesi
-- MariaDB implicit TIMESTAMP default/on-update davranisini kaldirir.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE onayli_bildirim_puantaj_etki_adaylari
  MODIFY COLUMN bildirim_created_at DATETIME NOT NULL,
  MODIFY COLUMN bildirim_updated_at DATETIME NOT NULL;
