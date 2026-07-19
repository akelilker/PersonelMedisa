-- S79-E: haftalik kapanis revizyon talebi + durum gecmisi
-- Additive only. Correction FK deferred to S79-F (correction_event_id nullable, no FK).
-- Open-slot generated unique: at most one TASLAK/ONAY_BEKLIYOR per
-- (personel_id, kaynak_tipi, kaynak_id, etkilenen_tarih).
-- CREATE TABLE without IF NOT EXISTS: unexpected/partial existing table fails loudly.
-- No DROP / TRUNCATE / DELETE / UPDATE / backfill / fixture.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE haftalik_kapanis_revizyon_talepleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  sube_id INT UNSIGNED NOT NULL,
  kapanis_id INT UNSIGNED NOT NULL,
  snapshot_id INT UNSIGNED NOT NULL,
  hafta_baslangic DATE NOT NULL,
  hafta_bitis DATE NOT NULL,
  etkilenen_tarih DATE NOT NULL,
  kaynak_tipi VARCHAR(64) NOT NULL,
  kaynak_id INT UNSIGNED NOT NULL,
  revizyon_tipi VARCHAR(64) NOT NULL,
  onceki_deger JSON NULL,
  talep_edilen_deger JSON NULL,
  gerekce VARCHAR(1000) NOT NULL,
  bordro_etki_var_mi TINYINT(1) NOT NULL DEFAULT 0,
  bordro_etki_notu VARCHAR(1000) NULL,
  durum VARCHAR(32) NOT NULL,
  talep_eden_kullanici_id INT UNSIGNED NOT NULL,
  talep_eden_rol VARCHAR(32) NOT NULL,
  talep_zamani DATETIME NOT NULL,
  karar_veren_kullanici_id INT UNSIGNED NULL,
  karar_zamani DATETIME NULL,
  karar_aciklamasi VARCHAR(1000) NULL,
  correction_event_id INT UNSIGNED NULL,
  acik_talep_slot TINYINT UNSIGNED
    GENERATED ALWAYS AS (
      CASE
        WHEN durum IN ('TASLAK', 'ONAY_BEKLIYOR') THEN 1
        ELSE NULL
      END
    ) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hkrt_acik_kaynak (
    personel_id,
    kaynak_tipi,
    kaynak_id,
    etkilenen_tarih,
    acik_talep_slot
  ),
  KEY idx_hkrt_personel_talep (personel_id, talep_zamani, id),
  KEY idx_hkrt_sube_hafta (sube_id, hafta_baslangic),
  KEY idx_hkrt_durum (durum),
  KEY idx_hkrt_kapanis (kapanis_id),
  KEY idx_hkrt_snapshot (snapshot_id),
  CONSTRAINT fk_hkrt_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrt_sube FOREIGN KEY (sube_id) REFERENCES subeler (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrt_kapanis FOREIGN KEY (kapanis_id) REFERENCES haftalik_kapanislar (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrt_snapshot FOREIGN KEY (snapshot_id) REFERENCES haftalik_kapanis_satirlari (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrt_talep_eden FOREIGN KEY (talep_eden_kullanici_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrt_karar_veren FOREIGN KEY (karar_veren_kullanici_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_hkrt_durum CHECK (
    durum IN ('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'REDDEDILDI', 'IPTAL')
  ),
  CONSTRAINT chk_hkrt_hafta CHECK (hafta_bitis = DATE_ADD(hafta_baslangic, INTERVAL 6 DAY)),
  CONSTRAINT chk_hkrt_etkilenen CHECK (
    etkilenen_tarih >= hafta_baslangic AND etkilenen_tarih <= hafta_bitis
  ),
  CONSTRAINT chk_hkrt_revizyon_tipi CHECK (
    revizyon_tipi IN (
      'PUANTAJ_GIRIS_CIKIS_DUZELTME',
      'MOLA_DUZELTME',
      'DEVAMSIZLIK_DUZELTME',
      'SUREC_GEC_GIRIS',
      'SERBEST_ZAMAN_ETKI_DUZELTME',
      'KAPANIS_HESAP_REVIZYONU',
      'BORDRO_ETKI_NOTU'
    )
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE haftalik_kapanis_revizyon_talebi_gecmisi (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  revizyon_talebi_id INT UNSIGNED NOT NULL,
  onceki_durum VARCHAR(32) NULL,
  yeni_durum VARCHAR(32) NOT NULL,
  aksiyon VARCHAR(32) NOT NULL,
  aciklama VARCHAR(1000) NULL,
  islem_yapan_kullanici_id INT UNSIGNED NOT NULL,
  islem_zamani DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_hkrtg_talep_zaman (revizyon_talebi_id, islem_zamani, id),
  CONSTRAINT fk_hkrtg_talep FOREIGN KEY (revizyon_talebi_id)
    REFERENCES haftalik_kapanis_revizyon_talepleri (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_hkrtg_user FOREIGN KEY (islem_yapan_kullanici_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_hkrtg_aksiyon CHECK (
    aksiyon IN ('OLUSTUR', 'GONDER', 'ONAY', 'RED', 'IPTAL')
  ),
  CONSTRAINT chk_hkrtg_yeni_durum CHECK (
    yeni_durum IN ('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'REDDEDILDI', 'IPTAL')
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
