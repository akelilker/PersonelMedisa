-- S1: Canonical role consolidation (staged, rollout-safe).
-- Additive ENUM widen + safe auto-map for PATRON / IK_BORDRO only.
-- Does NOT shrink ENUM. Does NOT remap SGK_KARAR_ONAY_YETKILISI / IDARI_ISLER.
-- Does NOT modify 052 or 053. Production apply is out of this PR scope.
-- MariaDB 10.6 / 11.4 uyumlu. PHP 7.4 runtime ile uyumlu schema.
-- PREPARE/EXECUTE/DEALLOCATE tek satir (PDO apply uyumu — 048/053 pattern).

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Stage 1: widen ENUM to accept canonical + keep unresolved legacy for inventory.
ALTER TABLE users
  MODIFY COLUMN rol ENUM(
    'GENEL_YONETICI',
    'MUHASEBE',
    'BIRIM_AMIRI',
    'BOLUM_YONETICISI',
    'PATRON',
    'AUTH_SMOKE_READONLY',
    'IK_BORDRO',
    'SGK_KARAR_ONAY_YETKILISI',
    'IDARI_ISLER',
    'SISTEM_YONETICISI',
    'PERSONEL',
    'IK_SORUMLUSU'
  ) NOT NULL;

-- Stage 2: safe reversible aliases only (production inventory not required).
UPDATE users SET rol = 'GENEL_YONETICI' WHERE rol = 'PATRON';
UPDATE users SET rol = 'IK_SORUMLUSU' WHERE rol = 'IK_BORDRO';

-- Intentionally NOT updated (manual human decision after inventory):
--   SGK_KARAR_ONAY_YETKILISI
--   IDARI_ISLER
-- Final ENUM shrink of unused legacy values is deferred to a later migration.
