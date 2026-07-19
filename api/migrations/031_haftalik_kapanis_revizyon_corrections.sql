-- S79-F: haftalik kapanis revizyon correction events
-- Additive only. Snapshot remains immutable; corrections are overlay records.
-- CREATE TABLE / ALTER without IF NOT EXISTS: unexpected/partial schema fails loudly.
-- No DROP / TRUNCATE / DELETE / UPDATE / backfill / fixture.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE haftalik_kapanis_revizyon_corrections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  revizyon_talebi_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  kapanis_id INT UNSIGNED NOT NULL,
  snapshot_id INT UNSIGNED NOT NULL,
  hafta_baslangic DATE NOT NULL,
  hafta_bitis DATE NOT NULL,
  etkilenen_tarih DATE NOT NULL,
  kaynak_tipi VARCHAR(64) NOT NULL,
  kaynak_id INT UNSIGNED NOT NULL,
  correction_tipi VARCHAR(64) NOT NULL,
  onceki_deger JSON NULL,
  yeni_deger JSON NULL,
  delta_dakika INT NOT NULL DEFAULT 0,
  delta_gun INT NOT NULL DEFAULT 0,
  bordro_etki_var_mi TINYINT(1) NOT NULL DEFAULT 0,
  bordro_etki_tipi VARCHAR(64) NULL,
  aciklama VARCHAR(1000) NULL,
  olusturan_kullanici_id INT UNSIGNED NOT NULL,
  olusturma_zamani DATETIME NOT NULL,
  iptal_edildi_mi TINYINT(1) NOT NULL DEFAULT 0,
  iptal_zamani DATETIME NULL,
  iptal_eden_kullanici_id INT UNSIGNED NULL,
  iptal_aciklamasi VARCHAR(1000) NULL,
  audit_ref VARCHAR(128) NOT NULL,
  snapshot_ref VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hkrc_revizyon_talebi (revizyon_talebi_id),
  UNIQUE KEY uq_hkrc_audit_ref (audit_ref),
  KEY idx_hkrc_personel (personel_id),
  KEY idx_hkrc_sube (sube_id),
  KEY idx_hkrc_hafta (hafta_baslangic, hafta_bitis),
  KEY idx_hkrc_kaynak (kaynak_tipi, kaynak_id, etkilenen_tarih),
  KEY idx_hkrc_correction_tipi (correction_tipi),
  KEY idx_hkrc_olusturma (olusturma_zamani),
  KEY idx_hkrc_iptal (iptal_edildi_mi),
  CONSTRAINT fk_hkrc_talep FOREIGN KEY (revizyon_talebi_id)
    REFERENCES haftalik_kapanis_revizyon_talepleri (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrc_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrc_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrc_kapanis FOREIGN KEY (kapanis_id) REFERENCES haftalik_kapanislar (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrc_snapshot FOREIGN KEY (snapshot_id) REFERENCES haftalik_kapanis_satirlari (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrc_olusturan FOREIGN KEY (olusturan_kullanici_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrc_iptal_eden FOREIGN KEY (iptal_eden_kullanici_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_hkrc_correction_tipi CHECK (
    correction_tipi IN (
      'GIRIS_CIKIS_DUZELTME',
      'MOLA_DUZELTME',
      'DEVAMSIZLIK_DUZELTME',
      'SERBEST_ZAMAN_ETKI_DUZELTME',
      'KAPANIS_HESAP_REVIZYONU',
      'BORDRO_ETKI_NOTU'
    )
  ),
  CONSTRAINT chk_hkrc_hafta CHECK (hafta_bitis = DATE_ADD(hafta_baslangic, INTERVAL 6 DAY)),
  CONSTRAINT chk_hkrc_etkilenen CHECK (
    etkilenen_tarih >= hafta_baslangic AND etkilenen_tarih <= hafta_bitis
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE haftalik_kapanis_revizyon_talepleri
  ADD CONSTRAINT fk_hkrt_correction_event
  FOREIGN KEY (correction_event_id) REFERENCES haftalik_kapanis_revizyon_corrections (id)
  ON DELETE RESTRICT ON UPDATE RESTRICT;
