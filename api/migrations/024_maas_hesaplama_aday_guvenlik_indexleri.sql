-- S77-D Maas hesaplama aday immutability trigger ve query indexleri
-- Child tablolarda UPDATE/DELETE yasak; root yalniz HESAPLANDI -> IPTAL.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE maas_hesaplama_calistirmalari
  ADD KEY idx_mhc_engine_state (engine_version, state);

ALTER TABLE maas_hesaplama_adaylari
  ADD KEY idx_mha_calistirma_state (calistirma_id, state);

ALTER TABLE maas_hesaplama_aday_kalemleri
  ADD KEY idx_mhak_aday_grup (aday_id, kalem_grubu);

DROP TRIGGER IF EXISTS trg_mha_no_update;
CREATE TRIGGER trg_mha_no_update
BEFORE UPDATE ON maas_hesaplama_adaylari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: aday satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_mha_no_delete;
CREATE TRIGGER trg_mha_no_delete
BEFORE DELETE ON maas_hesaplama_adaylari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: aday satiri silinemez';

DROP TRIGGER IF EXISTS trg_mhak_no_update;
CREATE TRIGGER trg_mhak_no_update
BEFORE UPDATE ON maas_hesaplama_aday_kalemleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: kalem satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_mhak_no_delete;
CREATE TRIGGER trg_mhak_no_delete
BEFORE DELETE ON maas_hesaplama_aday_kalemleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: kalem satiri silinemez';

DROP TRIGGER IF EXISTS trg_mhc_no_delete;
CREATE TRIGGER trg_mhc_no_delete
BEFORE DELETE ON maas_hesaplama_calistirmalari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: calistirma satiri silinemez';

DROP TRIGGER IF EXISTS trg_mhc_guarded_update;
CREATE TRIGGER trg_mhc_guarded_update
BEFORE UPDATE ON maas_hesaplama_calistirmalari
FOR EACH ROW
IF NOT (OLD.state = 'HESAPLANDI' AND NEW.state = 'IPTAL')
   OR NOT (NEW.snapshot_id <=> OLD.snapshot_id)
   OR NOT (NEW.sube_id <=> OLD.sube_id)
   OR NOT (NEW.yil <=> OLD.yil)
   OR NOT (NEW.ay <=> OLD.ay)
   OR NOT (NEW.revision_no <=> OLD.revision_no)
   OR NOT (NEW.parent_calistirma_id <=> OLD.parent_calistirma_id)
   OR NOT (NEW.engine_version <=> OLD.engine_version)
   OR NOT (NEW.contract_version <=> OLD.contract_version)
   OR NOT (NEW.snapshot_hash <=> OLD.snapshot_hash)
   OR NOT (NEW.parameter_set_hash <=> OLD.parameter_set_hash)
   OR NOT (NEW.carryover_set_hash <=> OLD.carryover_set_hash)
   OR NOT (NEW.request_hash <=> OLD.request_hash)
   OR NOT (NEW.source_hash <=> OLD.source_hash)
   OR NOT (NEW.result_hash <=> OLD.result_hash)
   OR NOT (NEW.calculation_input_hash <=> OLD.calculation_input_hash)
   OR NOT (NEW.personel_sayisi <=> OLD.personel_sayisi)
   OR NOT (NEW.basarili_aday_sayisi <=> OLD.basarili_aday_sayisi)
   OR NOT (NEW.hatali_aday_sayisi <=> OLD.hatali_aday_sayisi)
   OR NOT (NEW.blocker_count <=> OLD.blocker_count)
   OR NOT (NEW.warning_count <=> OLD.warning_count)
   OR NOT (NEW.created_by <=> OLD.created_by)
   OR NOT (NEW.created_at <=> OLD.created_at)
THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: yalniz HESAPLANDI -> IPTAL gecisi yapilabilir';
END IF;

DROP TRIGGER IF EXISTS trg_mhaud_no_update;
CREATE TRIGGER trg_mhaud_no_update
BEFORE UPDATE ON maas_hesaplama_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: hesap audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_mhaud_no_delete;
CREATE TRIGGER trg_mhaud_no_delete
BEFORE DELETE ON maas_hesaplama_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_CALCULATION_IMMUTABLE: hesap audit satiri silinemez';
