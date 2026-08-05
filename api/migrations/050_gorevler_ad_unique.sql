-- Global gorev catalog names must be unique (same contract as departmanlar).
-- Duplicate names are product-forbidden; UNIQUE enforces that.

ALTER TABLE gorevler
  ADD UNIQUE KEY uq_gorevler_ad (ad);
