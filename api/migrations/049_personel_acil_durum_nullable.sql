-- S98: Allow empty emergency-contact fields on initial personnel master import.
-- Existing non-empty values are preserved. Empty import values store as NULL.

ALTER TABLE personeller
  MODIFY COLUMN acil_durum_kisi VARCHAR(120) NULL,
  MODIFY COLUMN acil_durum_telefon VARCHAR(32) NULL;
