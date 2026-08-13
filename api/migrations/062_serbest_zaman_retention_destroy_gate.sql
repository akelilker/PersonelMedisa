-- 062: Pack 4B — retention-gated DELETE for serbest_zaman_kullanim_tahsisleri.
-- Additive. Does NOT mutate migration 061 history.
-- UPDATE remains always forbidden (append-only).
-- DELETE remains fail-closed unless CONNECTION_ID() has an open
-- retention_physical_destroy_gates row with category=SERBEST_ZAMAN and
-- linked retention_imha_executionlari.execution_state=PREPARED.
-- BORDRO / SGK_EKSIK_GUN gate behavior unchanged.
-- NO production seed / backfill. 061 NO_AUTO_BACKFILL contract preserved.
-- MariaDB 10.6 / 11.4 compatible. PHP 7.4 compatible.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Keep UPDATE hard-blocked (retention must never open UPDATE).
DROP TRIGGER IF EXISTS trg_szkt_no_update;
CREATE TRIGGER trg_szkt_no_update
BEFORE UPDATE ON serbest_zaman_kullanim_tahsisleri
FOR EACH ROW
SIGNAL SQLSTATE '45000'
  SET MESSAGE_TEXT = 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE: tahsis satiri guncellenemez';

-- Replace Pack 4A hard DELETE block with retention-gated DELETE (SERBEST_ZAMAN only).
DROP TRIGGER IF EXISTS trg_szkt_no_delete;
CREATE TRIGGER trg_szkt_no_delete
BEFORE DELETE ON serbest_zaman_kullanim_tahsisleri
FOR EACH ROW
IF NOT EXISTS (
  SELECT 1
  FROM retention_physical_destroy_gates g
  INNER JOIN retention_imha_executionlari e ON e.id = g.execution_id
  WHERE g.connection_id = CONNECTION_ID()
    AND g.category = 'SERBEST_ZAMAN'
    AND e.execution_state = 'PREPARED'
) THEN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'SERBEST_ZAMAN_ALLOCATION_IMMUTABLE: tahsis satiri silinemez';
END IF;
