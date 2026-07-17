-- S77-B Personel ucret gecmisi ve audit kayitlari
-- Additive migration; personeller.maas_tutari legacy compatibility icin korunur.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS personel_ucret_gecmisi (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  ucret_tutari DECIMAL(12,2) NOT NULL,
  ucret_turu ENUM('BRUT', 'NET') NOT NULL,
  para_birimi CHAR(3) NOT NULL DEFAULT 'TRY',
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  state ENUM('AKTIF', 'IPTAL') NOT NULL DEFAULT 'AKTIF',
  kaynak ENUM('MANUEL', 'PERSONEL_KAYDI_MIGRASYON', 'SISTEM') NOT NULL DEFAULT 'MANUEL',
  aciklama VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  iptal_edildi_at TIMESTAMP NULL,
  iptal_edildi_by INT UNSIGNED NULL,
  revision_no INT UNSIGNED NOT NULL DEFAULT 1,
  open_ended_aktif TINYINT(1) AS (
    CASE WHEN state = 'AKTIF' AND gecerlilik_bitis IS NULL THEN 1 ELSE NULL END
  ) STORED,
  PRIMARY KEY (id),
  KEY idx_pug_personel_baslangic (personel_id, gecerlilik_baslangic),
  KEY idx_pug_personel_bitis (personel_id, gecerlilik_bitis),
  KEY idx_pug_personel_state (personel_id, state),
  UNIQUE KEY uq_pug_open_ended_aktif (personel_id, open_ended_aktif),
  UNIQUE KEY uq_pug_personel_baslangic_state (personel_id, gecerlilik_baslangic, state),
  CONSTRAINT fk_pug_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_pug_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_pug_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT fk_pug_iptal_by FOREIGN KEY (iptal_edildi_by) REFERENCES users (id),
  CONSTRAINT chk_pug_tutar_pozitif CHECK (ucret_tutari > 0),
  CONSTRAINT chk_pug_tarih_araligi CHECK (
    gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personel_ucret_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  ucret_kaydi_id INT UNSIGNED NULL,
  aksiyon ENUM('CREATE', 'UPDATE', 'CLOSE', 'CANCEL', 'MIGRATE') NOT NULL,
  onceki_snapshot JSON NULL,
  sonraki_snapshot JSON NULL,
  actor_id INT UNSIGNED NULL,
  actor_rol VARCHAR(40) NULL,
  sube_id INT UNSIGNED NULL,
  request_hash CHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pua_personel_created (personel_id, created_at),
  KEY idx_pua_ucret_kaydi (ucret_kaydi_id),
  KEY idx_pua_request_hash (request_hash),
  CONSTRAINT fk_pua_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_pua_ucret_kaydi FOREIGN KEY (ucret_kaydi_id) REFERENCES personel_ucret_gecmisi (id) ON DELETE SET NULL,
  CONSTRAINT fk_pua_actor FOREIGN KEY (actor_id) REFERENCES users (id),
  CONSTRAINT fk_pua_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
