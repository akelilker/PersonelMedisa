-- S78-C3-R1: departman ad unique constraint (concurrency-safe create)
-- Additive only. Column collation remains utf8mb4_unicode_ci (table default).
-- Duplicate global catalog names are product-forbidden; UNIQUE enforces that.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE departmanlar
  ADD UNIQUE KEY uq_departmanlar_ad (ad);
