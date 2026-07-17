-- S77-C Maas hesaplama snapshot guvenlik indexleri ve immutability trigger korumasi
-- Additive migration; snapshot payload tablolarinda UPDATE/DELETE DB seviyesinde engellenir.
-- Root tabloda yalniz OLUSTURULDU -> IPTAL state gecisine izin verilir.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE maas_hesaplama_donem_snapshotlari
  ADD KEY idx_mhds_sube_donem_state (sube_id, yil, ay, state),
  ADD KEY idx_mhds_muhur_source (muhur_id, source_hash);

ALTER TABLE maas_hesaplama_girdi_snapshotlari
  ADD KEY idx_mhgs_snapshot_personel_tur (donem_snapshot_id, personel_snapshot_id, kaynak_turu);

DROP TRIGGER IF EXISTS trg_mhps_no_update;

CREATE TRIGGER trg_mhps_no_update
BEFORE UPDATE ON maas_hesaplama_personel_snapshotlari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SNAPSHOT_IMMUTABLE: personel snapshot satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_mhps_no_delete;

CREATE TRIGGER trg_mhps_no_delete
BEFORE DELETE ON maas_hesaplama_personel_snapshotlari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SNAPSHOT_IMMUTABLE: personel snapshot satiri silinemez';

DROP TRIGGER IF EXISTS trg_mhgs_no_update;

CREATE TRIGGER trg_mhgs_no_update
BEFORE UPDATE ON maas_hesaplama_girdi_snapshotlari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SNAPSHOT_IMMUTABLE: girdi snapshot satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_mhgs_no_delete;

CREATE TRIGGER trg_mhgs_no_delete
BEFORE DELETE ON maas_hesaplama_girdi_snapshotlari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SNAPSHOT_IMMUTABLE: girdi snapshot satiri silinemez';

DROP TRIGGER IF EXISTS trg_mhds_no_delete;

CREATE TRIGGER trg_mhds_no_delete
BEFORE DELETE ON maas_hesaplama_donem_snapshotlari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SNAPSHOT_IMMUTABLE: donem snapshot satiri silinemez';

DROP TRIGGER IF EXISTS trg_mhds_guarded_update;

CREATE TRIGGER trg_mhds_guarded_update
BEFORE UPDATE ON maas_hesaplama_donem_snapshotlari
FOR EACH ROW
IF NOT (OLD.state = 'OLUSTURULDU' AND NEW.state = 'IPTAL')
   OR NOT (NEW.sube_id <=> OLD.sube_id)
   OR NOT (NEW.yil <=> OLD.yil)
   OR NOT (NEW.ay <=> OLD.ay)
   OR NOT (NEW.donem <=> OLD.donem)
   OR NOT (NEW.donem_baslangic <=> OLD.donem_baslangic)
   OR NOT (NEW.donem_bitis <=> OLD.donem_bitis)
   OR NOT (NEW.muhur_id <=> OLD.muhur_id)
   OR NOT (NEW.revision_no <=> OLD.revision_no)
   OR NOT (NEW.parent_snapshot_id <=> OLD.parent_snapshot_id)
   OR NOT (NEW.contract_version <=> OLD.contract_version)
   OR NOT (NEW.cutoff_at <=> OLD.cutoff_at)
   OR NOT (NEW.preflight_hash <=> OLD.preflight_hash)
   OR NOT (NEW.source_hash <=> OLD.source_hash)
   OR NOT (NEW.snapshot_hash <=> OLD.snapshot_hash)
   OR NOT (NEW.personel_sayisi <=> OLD.personel_sayisi)
   OR NOT (NEW.girdi_sayisi <=> OLD.girdi_sayisi)
   OR NOT (NEW.blocker_count <=> OLD.blocker_count)
   OR NOT (NEW.warning_count <=> OLD.warning_count)
   OR NOT (NEW.created_by <=> OLD.created_by)
   OR NOT (NEW.created_at <=> OLD.created_at)
THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SNAPSHOT_IMMUTABLE: yalniz OLUSTURULDU -> IPTAL gecisi yapilabilir';
END IF;

DROP TRIGGER IF EXISTS trg_mhsa_no_update;

CREATE TRIGGER trg_mhsa_no_update
BEFORE UPDATE ON maas_hesaplama_snapshot_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SNAPSHOT_IMMUTABLE: snapshot audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_mhsa_no_delete;

CREATE TRIGGER trg_mhsa_no_delete
BEFORE DELETE ON maas_hesaplama_snapshot_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SNAPSHOT_IMMUTABLE: snapshot audit satiri silinemez';
