-- S79-C: fazla calisma odeme tercihi (snapshot_id keyed) + append-only audit
-- Additive only. Matches TS FazlaCalismaOdemeTercihi; odeme_tipi stays outside haftalik snapshot.
-- ON DELETE RESTRICT: preference history must survive parent hard-delete.
-- CREATE TABLE without IF NOT EXISTS: unexpected/partial existing table fails loudly.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE fazla_calisma_odeme_tercihleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  snapshot_id INT UNSIGNED NOT NULL,
  kapanis_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  hafta_baslangic DATE NOT NULL,
  hafta_bitis DATE NOT NULL,
  fazla_calisma_dakika INT UNSIGNED NOT NULL DEFAULT 0,
  odeme_tipi VARCHAR(32) NOT NULL DEFAULT 'KARAR_BEKLIYOR',
  secim_zamani DATETIME NULL,
  secen_kullanici_id INT UNSIGNED NULL,
  onceki_odeme_tipi VARCHAR(32) NULL,
  gerekce VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fcot_snapshot (snapshot_id),
  KEY idx_fcot_personel (personel_id),
  KEY idx_fcot_kapanis (kapanis_id),
  KEY idx_fcot_odeme_tipi (odeme_tipi),
  CONSTRAINT fk_fcot_snapshot FOREIGN KEY (snapshot_id) REFERENCES haftalik_kapanis_satirlari (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fcot_kapanis FOREIGN KEY (kapanis_id) REFERENCES haftalik_kapanislar (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fcot_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fcot_secen FOREIGN KEY (secen_kullanici_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_fcot_odeme_tipi CHECK (odeme_tipi IN ('KARAR_BEKLIYOR', 'UCRET', 'SERBEST_ZAMAN')),
  CONSTRAINT chk_fcot_onceki_odeme_tipi CHECK (
    onceki_odeme_tipi IS NULL
    OR onceki_odeme_tipi IN ('KARAR_BEKLIYOR', 'UCRET', 'SERBEST_ZAMAN')
  ),
  CONSTRAINT chk_fcot_hafta CHECK (hafta_bitis = DATE_ADD(hafta_baslangic, INTERVAL 6 DAY))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fazla_calisma_odeme_tercihi_audit (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tercih_id INT UNSIGNED NOT NULL,
  snapshot_id INT UNSIGNED NOT NULL,
  onceki_odeme_tipi VARCHAR(32) NOT NULL,
  yeni_odeme_tipi VARCHAR(32) NOT NULL,
  secen_kullanici_id INT UNSIGNED NOT NULL,
  secim_zamani DATETIME NOT NULL,
  gerekce VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fcota_tercih (tercih_id),
  KEY idx_fcota_snapshot (snapshot_id),
  CONSTRAINT fk_fcota_tercih FOREIGN KEY (tercih_id) REFERENCES fazla_calisma_odeme_tercihleri (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fcota_snapshot FOREIGN KEY (snapshot_id) REFERENCES haftalik_kapanis_satirlari (id) ON DELETE RESTRICT,
  CONSTRAINT fk_fcota_secen FOREIGN KEY (secen_kullanici_id) REFERENCES users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_fcota_onceki CHECK (onceki_odeme_tipi IN ('KARAR_BEKLIYOR', 'UCRET', 'SERBEST_ZAMAN')),
  CONSTRAINT chk_fcota_yeni CHECK (yeni_odeme_tipi IN ('KARAR_BEKLIYOR', 'UCRET', 'SERBEST_ZAMAN'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
