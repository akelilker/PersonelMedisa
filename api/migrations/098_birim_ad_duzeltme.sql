-- 098: Birim display name correction (birimler id 27 / 28), owner-approved data fix.
-- Data-only: birimler.ad only. bolumler (incl. id 17) and kisa_kod are NOT changed.
-- Fail-closed UPDATE by (id + exact current ad); skipped if the target name already
-- exists in the same bolum (uq_birimler_bolum_ad). Drift or re-run -> no write (idempotent).
-- APPLY is a separate gate. This file is not executed by the application.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @p098_c27 := (
  SELECT COUNT(*)
  FROM birimler
  WHERE ad = 'Beyaz Eşya Satış'
    AND bolum_id = (SELECT b.bolum_id FROM birimler b WHERE b.id = 27)
);
UPDATE birimler SET ad = 'Beyaz Eşya Satış' WHERE id = 27 AND ad = 'Beyaz Eşya Bayi Satış' AND @p098_c27 = 0;

SET @p098_c28 := (
  SELECT COUNT(*)
  FROM birimler
  WHERE ad = 'Müşteri Satış'
    AND bolum_id = (SELECT b.bolum_id FROM birimler b WHERE b.id = 28)
);
UPDATE birimler SET ad = 'Müşteri Satış' WHERE id = 28 AND ad = 'Büyük Müşteri Satış' AND @p098_c28 = 0;
