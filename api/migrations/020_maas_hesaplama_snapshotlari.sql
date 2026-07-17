-- S77-C Maas hesaplama donem/personel/girdi snapshotlari ve audit kayitlari
-- Additive migration; snapshot payloadlari olusturulduktan sonra degistirilemez.
-- Bu tablolar hesap sonucu degil, hesaplamaya girecek ham kaynak kopyalarini tutar.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS maas_hesaplama_donem_snapshotlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  donem CHAR(7) NOT NULL,
  donem_baslangic DATE NOT NULL,
  donem_bitis DATE NOT NULL,
  muhur_id INT UNSIGNED NOT NULL,
  revision_no INT UNSIGNED NOT NULL DEFAULT 1,
  parent_snapshot_id INT UNSIGNED NULL,
  state ENUM('OLUSTURULDU', 'IPTAL') NOT NULL DEFAULT 'OLUSTURULDU',
  contract_version VARCHAR(48) NOT NULL DEFAULT 'S77C_PAYROLL_INPUT_SNAPSHOT_V1',
  cutoff_at DATETIME NOT NULL,
  preflight_hash CHAR(64) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  snapshot_hash CHAR(64) NOT NULL,
  personel_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  girdi_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  blocker_count INT UNSIGNED NOT NULL DEFAULT 0,
  warning_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  iptal_edildi_by INT UNSIGNED NULL,
  iptal_edildi_at TIMESTAMP NULL,
  iptal_nedeni VARCHAR(500) NULL,
  aktif_snapshot TINYINT(1) AS (
    CASE WHEN state = 'OLUSTURULDU' THEN 1 ELSE NULL END
  ) STORED,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mhds_sube_donem_revision (sube_id, yil, ay, revision_no),
  UNIQUE KEY uq_mhds_muhur_revision (muhur_id, revision_no),
  UNIQUE KEY uq_mhds_aktif_snapshot (sube_id, yil, ay, aktif_snapshot),
  KEY idx_mhds_donem (donem),
  KEY idx_mhds_source_hash (source_hash),
  KEY idx_mhds_state (state),
  CONSTRAINT fk_mhds_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_mhds_muhur FOREIGN KEY (muhur_id) REFERENCES puantaj_aylik_muhurleri (id),
  CONSTRAINT fk_mhds_parent FOREIGN KEY (parent_snapshot_id) REFERENCES maas_hesaplama_donem_snapshotlari (id),
  CONSTRAINT fk_mhds_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_mhds_iptal_by FOREIGN KEY (iptal_edildi_by) REFERENCES users (id),
  CONSTRAINT chk_mhds_yil CHECK (yil BETWEEN 2000 AND 2100),
  CONSTRAINT chk_mhds_ay CHECK (ay BETWEEN 1 AND 12),
  CONSTRAINT chk_mhds_donem_araligi CHECK (donem_bitis >= donem_baslangic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maas_hesaplama_personel_snapshotlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  donem_snapshot_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  personel_snapshot_json JSON NOT NULL,
  personel_snapshot_hash CHAR(64) NOT NULL,
  istihdam_baslangic DATE NOT NULL,
  istihdam_bitis DATE NULL,
  ucret_segment_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  puantaj_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  finans_kalem_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mhps_snapshot_personel (donem_snapshot_id, personel_id),
  KEY idx_mhps_personel (personel_id),
  KEY idx_mhps_hash (personel_snapshot_hash),
  CONSTRAINT fk_mhps_donem_snapshot FOREIGN KEY (donem_snapshot_id) REFERENCES maas_hesaplama_donem_snapshotlari (id),
  CONSTRAINT fk_mhps_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT chk_mhps_istihdam CHECK (istihdam_bitis IS NULL OR istihdam_bitis >= istihdam_baslangic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maas_hesaplama_girdi_snapshotlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  donem_snapshot_id INT UNSIGNED NOT NULL,
  personel_snapshot_id INT UNSIGNED NULL,
  kaynak_turu ENUM('PERSONEL', 'UCRET', 'PUANTAJ', 'IZIN', 'ETKI_ADAYI', 'FINANS', 'MEVZUAT', 'MUHUR') NOT NULL,
  kaynak_tablo VARCHAR(80) NOT NULL,
  kaynak_id INT UNSIGNED NULL,
  kaynak_revision INT UNSIGNED NULL,
  etki_baslangic DATE NULL,
  etki_bitis DATE NULL,
  sira_no INT UNSIGNED NOT NULL,
  payload_json JSON NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mhgs_snapshot_tur_sira (donem_snapshot_id, kaynak_turu, sira_no),
  KEY idx_mhgs_personel_snapshot (personel_snapshot_id),
  KEY idx_mhgs_kaynak (kaynak_tablo, kaynak_id),
  KEY idx_mhgs_payload_hash (payload_hash),
  CONSTRAINT fk_mhgs_donem_snapshot FOREIGN KEY (donem_snapshot_id) REFERENCES maas_hesaplama_donem_snapshotlari (id),
  CONSTRAINT fk_mhgs_personel_snapshot FOREIGN KEY (personel_snapshot_id) REFERENCES maas_hesaplama_personel_snapshotlari (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maas_hesaplama_snapshot_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  donem_snapshot_id INT UNSIGNED NULL,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  muhur_id INT UNSIGNED NULL,
  aksiyon ENUM(
    'PREFLIGHT_BLOCKED',
    'SNAPSHOT_CREATE',
    'SNAPSHOT_CREATE_IDEMPOTENT',
    'SNAPSHOT_CANCEL',
    'REVISION_REQUEST_BLOCKED'
  ) NOT NULL,
  sonuc ENUM('BLOCKED', 'CREATED', 'EXISTING', 'CANCELLED', 'CONFLICT') NOT NULL,
  actor_id INT UNSIGNED NOT NULL,
  actor_rol VARCHAR(40) NULL,
  request_hash CHAR(64) NOT NULL,
  preflight_hash CHAR(64) NOT NULL,
  source_hash CHAR(64) NULL,
  result_hash CHAR(64) NOT NULL,
  blocker_count INT UNSIGNED NOT NULL DEFAULT 0,
  warning_count INT UNSIGNED NOT NULL DEFAULT 0,
  snapshot_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mhsa_idempotency (sube_id, yil, ay, aksiyon, request_hash),
  KEY idx_mhsa_snapshot (donem_snapshot_id),
  KEY idx_mhsa_sube_donem_created (sube_id, yil, ay, created_at),
  CONSTRAINT fk_mhsa_donem_snapshot FOREIGN KEY (donem_snapshot_id) REFERENCES maas_hesaplama_donem_snapshotlari (id),
  CONSTRAINT fk_mhsa_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_mhsa_muhur FOREIGN KEY (muhur_id) REFERENCES puantaj_aylik_muhurleri (id),
  CONSTRAINT fk_mhsa_actor FOREIGN KEY (actor_id) REFERENCES users (id),
  CONSTRAINT chk_mhsa_yil CHECK (yil BETWEEN 2000 AND 2100),
  CONSTRAINT chk_mhsa_ay CHECK (ay BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
