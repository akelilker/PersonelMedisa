-- S79-B: persistent haftalik kapanis snapshot entity + satirlar
-- Additive only. Matches TS HaftalikKapanisSonuc / HaftalikKapanisSnapshotSatir.
-- Unique identity: (sube_id, hafta_baslangic, departman_scope_key) where
-- departman_scope_key = IFNULL(departman_id, 0) avoids nullable UNIQUE gaps.
-- ON DELETE RESTRICT: snapshot history must survive parent hard-delete.
-- CREATE TABLE without IF NOT EXISTS: unexpected/partial existing table fails loudly.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE haftalik_kapanislar (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  hafta_baslangic DATE NOT NULL,
  hafta_bitis DATE NOT NULL,
  departman_id INT UNSIGNED NULL,
  departman_scope_key INT UNSIGNED
    AS (IFNULL(departman_id, 0)) STORED,
  state VARCHAR(32) NOT NULL DEFAULT 'KAPANDI',
  personel_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  snapshot_satir_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  kaynak_versiyon VARCHAR(48) NOT NULL DEFAULT 'A2_MOTOR_V1',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_haftalik_kapanis_scope (sube_id, hafta_baslangic, departman_scope_key),
  KEY idx_haftalik_kapanis_sube_hafta (sube_id, hafta_baslangic),
  KEY idx_haftalik_kapanis_state (state),
  CONSTRAINT fk_haftalik_kapanis_sube FOREIGN KEY (sube_id) REFERENCES subeler (id) ON DELETE RESTRICT,
  CONSTRAINT fk_haftalik_kapanis_departman FOREIGN KEY (departman_id) REFERENCES departmanlar (id) ON DELETE RESTRICT,
  CONSTRAINT fk_haftalik_kapanis_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_haftalik_kapanis_state CHECK (state = 'KAPANDI'),
  CONSTRAINT chk_haftalik_kapanis_hafta CHECK (hafta_bitis = DATE_ADD(hafta_baslangic, INTERVAL 6 DAY))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE haftalik_kapanis_satirlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kapanis_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  departman_id INT UNSIGNED NULL,
  hafta_baslangic DATE NOT NULL,
  hafta_bitis DATE NOT NULL,
  yil SMALLINT UNSIGNED NULL,
  hafta_no TINYINT UNSIGNED NULL,
  state VARCHAR(32) NOT NULL DEFAULT 'KAPANDI',
  kaynak_versiyon VARCHAR(48) NOT NULL DEFAULT 'A2_MOTOR_V1',
  toplam_net_dakika INT UNSIGNED NOT NULL DEFAULT 0,
  normal_calisma_dakika INT UNSIGNED NOT NULL DEFAULT 0,
  fazla_calisma_dakika INT UNSIGNED NOT NULL DEFAULT 0,
  fazla_surelerle_calisma_dakika INT UNSIGNED NOT NULL DEFAULT 0,
  tam_hafta_verisi TINYINT(1) NOT NULL DEFAULT 0,
  compliance_uyarilari_json JSON NOT NULL,
  compliance_uyari_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  kritik_uyari_var_mi TINYINT(1) NOT NULL DEFAULT 0,
  hesaplama_zamani DATETIME NOT NULL,
  kaynak_gun_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  notlar_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hks_kapanis_personel (kapanis_id, personel_id),
  KEY idx_hks_personel_yil (personel_id, yil),
  KEY idx_hks_kapanis (kapanis_id),
  KEY idx_hks_tam_hafta (personel_id, yil, tam_hafta_verisi, state),
  CONSTRAINT fk_hks_kapanis FOREIGN KEY (kapanis_id) REFERENCES haftalik_kapanislar (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hks_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT,
  CONSTRAINT fk_hks_departman FOREIGN KEY (departman_id) REFERENCES departmanlar (id) ON DELETE RESTRICT,
  CONSTRAINT chk_hks_state CHECK (state = 'KAPANDI')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
