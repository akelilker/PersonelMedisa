-- S85-B: SGK prim gunu, eksik gun, belge ve immutable snapshot owner semasi
-- Additive only. Mevcut veri degistirilmez; varsayilan sirket politikasi veya tahmini kod seed edilmez.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS sgk_kaynak_manifestleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kaynak_id VARCHAR(80) NOT NULL,
  kaynak_turu ENUM('KANUN', 'YONETMELIK', 'GENELGE', 'DUYURU', 'RESMI_LISTE', 'KURUM_ACIKLAMASI') NOT NULL,
  kurum VARCHAR(160) NOT NULL,
  belge_basligi VARCHAR(500) NOT NULL,
  belge_tarihi DATE NULL,
  yayimlanma_tarihi DATE NULL,
  yururluk_baslangic DATE NULL,
  yururluk_bitis DATE NULL,
  kaynak_adresi VARCHAR(1000) NOT NULL,
  indirilen_dosya_sha256 CHAR(64) NOT NULL,
  icerik_sha256 CHAR(64) NOT NULL,
  indirilen_dosya_byte BIGINT UNSIGNED NULL,
  dogrulama_tarihi DATETIME NOT NULL,
  observed_at DATETIME NULL,
  arsiv_kopyasi_repoda_mi TINYINT(1) NOT NULL DEFAULT 0,
  dogrulayan_kullanici_id INT UNSIGNED NULL,
  dogrulama_turu ENUM('KULLANICI', 'SISTEM_KAYNAK_PAKETI') NOT NULL DEFAULT 'KULLANICI',
  durum ENUM('AKTIF', 'PASIF') NOT NULL DEFAULT 'AKTIF',
  yerine_gecen_kaynak_id INT UNSIGNED NULL,
  aciklama VARCHAR(1000) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_km_kaynak_id (kaynak_id),
  UNIQUE KEY uq_sgk_km_url_file_hash (kaynak_adresi(255), indirilen_dosya_sha256),
  KEY idx_sgk_km_yururluk (yururluk_baslangic, yururluk_bitis, durum),
  CONSTRAINT fk_sgk_km_dogrulayan FOREIGN KEY (dogrulayan_kullanici_id) REFERENCES users (id),
  CONSTRAINT fk_sgk_km_yerine_gecen FOREIGN KEY (yerine_gecen_kaynak_id) REFERENCES sgk_kaynak_manifestleri (id),
  CONSTRAINT fk_sgk_km_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_sgk_km_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT chk_sgk_km_hash CHECK (
    indirilen_dosya_sha256 REGEXP '^[0-9a-f]{64}$' AND icerik_sha256 REGEXP '^[0-9a-f]{64}$'
  ),
  CONSTRAINT chk_sgk_km_yururluk CHECK (yururluk_bitis IS NULL OR yururluk_baslangic IS NULL OR yururluk_bitis >= yururluk_baslangic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Onceki lokal 036 apply'lari icin additive kolon tamiri (CREATE IF NOT EXISTS kolon eklemez).
ALTER TABLE sgk_kaynak_manifestleri
  ADD COLUMN IF NOT EXISTS indirilen_dosya_byte BIGINT UNSIGNED NULL AFTER icerik_sha256,
  ADD COLUMN IF NOT EXISTS observed_at DATETIME NULL AFTER dogrulama_tarihi,
  ADD COLUMN IF NOT EXISTS arsiv_kopyasi_repoda_mi TINYINT(1) NOT NULL DEFAULT 0 AFTER observed_at;

CREATE TABLE IF NOT EXISTS sgk_eksik_gun_katalog_surumleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  surum_kodu VARCHAR(80) NOT NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  tamlik_durumu ENUM('TASLAK', 'DOGRULANMIS_TAM') NOT NULL DEFAULT 'TASLAK',
  state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
  manifest_set_hash CHAR(64) NOT NULL,
  aciklama VARCHAR(1000) NOT NULL,
  hazirlayan_id INT UNSIGNED NULL,
  onaylayan_id INT UNSIGNED NULL,
  onay_zamani DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_egks_surum (surum_kodu),
  KEY idx_sgk_egks_gecerlilik (gecerlilik_baslangic, gecerlilik_bitis, state),
  CONSTRAINT fk_sgk_egks_hazirlayan FOREIGN KEY (hazirlayan_id) REFERENCES users (id),
  CONSTRAINT fk_sgk_egks_onaylayan FOREIGN KEY (onaylayan_id) REFERENCES users (id),
  CONSTRAINT chk_sgk_egks_hash CHECK (manifest_set_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_sgk_egks_tarih CHECK (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic),
  CONSTRAINT chk_sgk_egks_onay CHECK (state <> 'ONAYLANDI' OR (tamlik_durumu = 'DOGRULANMIS_TAM' AND onaylayan_id IS NOT NULL AND onay_zamani IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_eksik_gun_kodlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  katalog_surum_id INT UNSIGNED NOT NULL,
  eksik_gun_kodu VARCHAR(8) NOT NULL,
  resmi_aciklama VARCHAR(500) NOT NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  kaynak_manifest_id INT UNSIGNED NOT NULL,
  belge_zorunlulugu ENUM('YOK', 'KOSULLU', 'ZORUNLU') NOT NULL,
  sifir_gun_sifir_kazanc_kullanilabilir_mi TINYINT(1) NOT NULL,
  kismi_sureli_sozlesme_gerekli_mi TINYINT(1) NOT NULL,
  tek_basina_kullanilabilir_mi TINYINT(1) NOT NULL,
  diger_nedenlerle_birlikte_kullanim ENUM('YASAK', 'KOSULLU', 'SERBEST') NOT NULL,
  aktif_mi TINYINT(1) NOT NULL DEFAULT 1,
  kosullar_json JSON NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_egk_surum_kod (katalog_surum_id, eksik_gun_kodu),
  KEY idx_sgk_egk_gecerlilik (eksik_gun_kodu, gecerlilik_baslangic, gecerlilik_bitis, aktif_mi),
  CONSTRAINT fk_sgk_egk_surum FOREIGN KEY (katalog_surum_id) REFERENCES sgk_eksik_gun_katalog_surumleri (id),
  CONSTRAINT fk_sgk_egk_kaynak FOREIGN KEY (kaynak_manifest_id) REFERENCES sgk_kaynak_manifestleri (id),
  CONSTRAINT fk_sgk_egk_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_sgk_egk_updated_by FOREIGN KEY (updated_by) REFERENCES users (id),
  CONSTRAINT chk_sgk_egk_tarih CHECK (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_eksik_gun_kod_cakismalari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  katalog_surum_id INT UNSIGNED NOT NULL,
  kaynak_kod_set_hash CHAR(64) NOT NULL,
  kaynak_kodlar_json JSON NOT NULL,
  sonuc_eksik_gun_kodu VARCHAR(8) NOT NULL,
  kosullar_json JSON NULL,
  kaynak_manifest_id INT UNSIGNED NOT NULL,
  aktif_mi TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_egkc_set (katalog_surum_id, kaynak_kod_set_hash),
  CONSTRAINT fk_sgk_egkc_surum FOREIGN KEY (katalog_surum_id) REFERENCES sgk_eksik_gun_katalog_surumleri (id),
  CONSTRAINT fk_sgk_egkc_kaynak FOREIGN KEY (kaynak_manifest_id) REFERENCES sgk_kaynak_manifestleri (id),
  CONSTRAINT fk_sgk_egkc_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT chk_sgk_egkc_hash CHECK (kaynak_kod_set_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_surec_neden_eslemeleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  katalog_surum_id INT UNSIGNED NOT NULL,
  surec_turu VARCHAR(64) NOT NULL,
  alt_tur VARCHAR(64) NOT NULL DEFAULT '*',
  canonical_surec_turu ENUM(
    'HASTALIK', 'IS_KAZASI', 'MESLEK_HASTALIGI', 'ANALIK', 'UCRETSIZ_IZIN',
    'YILLIK_IZIN', 'MAZERETSIZ_DEVAMSIZLIK', 'KISMI_SURELI_CALISMA',
    'PUANTAJ_EKSIK_GUN', 'DIGER_MANUEL_INCELEME'
  ) NOT NULL,
  eksik_gun_kodu VARCHAR(8) NULL,
  prim_gunu_etkisi ENUM('DAHIL', 'DUSUR', 'KOSULLU', 'MANUEL') NOT NULL,
  kosullar_json JSON NULL,
  kaynak_manifest_id INT UNSIGNED NOT NULL,
  aktif_mi TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_sne_raw (katalog_surum_id, surec_turu, alt_tur),
  KEY idx_sgk_sne_canonical (canonical_surec_turu, aktif_mi),
  CONSTRAINT fk_sgk_sne_surum FOREIGN KEY (katalog_surum_id) REFERENCES sgk_eksik_gun_katalog_surumleri (id),
  CONSTRAINT fk_sgk_sne_kaynak FOREIGN KEY (kaynak_manifest_id) REFERENCES sgk_kaynak_manifestleri (id),
  CONSTRAINT fk_sgk_sne_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_sirket_politika_surumleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  surum_kodu VARCHAR(80) NOT NULL,
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  bildirim_donem_tipi ENUM('AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14') NOT NULL,
  state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
  politika_hash CHAR(64) NOT NULL,
  aciklama VARCHAR(1000) NOT NULL,
  hazirlayan_id INT UNSIGNED NULL,
  onaylayan_id INT UNSIGNED NULL,
  onay_zamani DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_sps_sube_surum (sube_id, surum_kodu),
  KEY idx_sgk_sps_gecerlilik (sube_id, gecerlilik_baslangic, gecerlilik_bitis, state),
  CONSTRAINT fk_sgk_sps_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_sgk_sps_hazirlayan FOREIGN KEY (hazirlayan_id) REFERENCES users (id),
  CONSTRAINT fk_sgk_sps_onaylayan FOREIGN KEY (onaylayan_id) REFERENCES users (id),
  CONSTRAINT chk_sgk_sps_hash CHECK (politika_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_sgk_sps_tarih CHECK (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic),
  CONSTRAINT chk_sgk_sps_onay CHECK (state <> 'ONAYLANDI' OR (onaylayan_id IS NOT NULL AND onay_zamani IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_sirket_politika_degerleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  politika_surum_id INT UNSIGNED NOT NULL,
  politika_kodu VARCHAR(80) NOT NULL,
  deger_turu ENUM('BOOLEAN', 'ENUM', 'METIN', 'SAYI') NOT NULL,
  deger VARCHAR(500) NOT NULL,
  aciklama VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_spd_kod (politika_surum_id, politika_kodu),
  CONSTRAINT fk_sgk_spd_surum FOREIGN KEY (politika_surum_id) REFERENCES sgk_sirket_politika_surumleri (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_eksik_gun_belgeleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  donem CHAR(7) NOT NULL,
  eksik_gun_kodu VARCHAR(8) NULL,
  belge_turu VARCHAR(80) NOT NULL,
  belge_tarihi DATE NOT NULL,
  belge_numarasi VARCHAR(120) NULL,
  dosya_hash CHAR(64) NOT NULL,
  dogrulama_durumu ENUM('BEKLIYOR', 'DOGRULANDI', 'REDDEDILDI', 'IPTAL') NOT NULL DEFAULT 'BEKLIYOR',
  dogrulayan_id INT UNSIGNED NULL,
  dogrulama_tarihi DATETIME NULL,
  yerine_gecen_belge_id INT UNSIGNED NULL,
  iptal_eden_id INT UNSIGNED NULL,
  iptal_tarihi DATETIME NULL,
  iptal_nedeni VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sgk_egb_personel_donem (personel_id, donem, dogrulama_durumu),
  KEY idx_sgk_egb_hash (dosya_hash),
  CONSTRAINT fk_sgk_egb_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_sgk_egb_dogrulayan FOREIGN KEY (dogrulayan_id) REFERENCES users (id),
  CONSTRAINT fk_sgk_egb_yerine_gecen FOREIGN KEY (yerine_gecen_belge_id) REFERENCES sgk_eksik_gun_belgeleri (id),
  CONSTRAINT fk_sgk_egb_iptal_eden FOREIGN KEY (iptal_eden_id) REFERENCES users (id),
  CONSTRAINT fk_sgk_egb_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT chk_sgk_egb_hash CHECK (dosya_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_belge_surec_baglantilari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  belge_id INT UNSIGNED NOT NULL,
  surec_id INT UNSIGNED NOT NULL,
  baglanti_turu ENUM('ANA_KANIT', 'DESTEKLEYICI') NOT NULL DEFAULT 'ANA_KANIT',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_bsb_belge_surec (belge_id, surec_id),
  KEY idx_sgk_bsb_surec (surec_id),
  CONSTRAINT fk_sgk_bsb_belge FOREIGN KEY (belge_id) REFERENCES sgk_eksik_gun_belgeleri (id),
  CONSTRAINT fk_sgk_bsb_surec FOREIGN KEY (surec_id) REFERENCES surecler (id),
  CONSTRAINT fk_sgk_bsb_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_personel_sigortalilik_surumleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  sigortalilik_statusu VARCHAR(40) NOT NULL,
  sozlesme_turu ENUM('TAM_SURELI', 'KISMI_SURELI', 'DIGER') NOT NULL,
  bildirim_donem_tipi ENUM('SIRKET_POLITIKASINDAN', 'AY_1_SON_GUN', 'AY_15_SONRAKI_AY_14') NOT NULL DEFAULT 'SIRKET_POLITIKASINDAN',
  gecerlilik_baslangic DATE NOT NULL,
  gecerlilik_bitis DATE NULL,
  state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
  kaynak_belge_id INT UNSIGNED NULL,
  aciklama VARCHAR(1000) NOT NULL,
  hazirlayan_id INT UNSIGNED NULL,
  onaylayan_id INT UNSIGNED NULL,
  onay_zamani DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sgk_pss_personel_gecerlilik (personel_id, gecerlilik_baslangic, gecerlilik_bitis, state),
  CONSTRAINT fk_sgk_pss_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_sgk_pss_kaynak_belge FOREIGN KEY (kaynak_belge_id) REFERENCES sgk_eksik_gun_belgeleri (id),
  CONSTRAINT fk_sgk_pss_hazirlayan FOREIGN KEY (hazirlayan_id) REFERENCES users (id),
  CONSTRAINT fk_sgk_pss_onaylayan FOREIGN KEY (onaylayan_id) REFERENCES users (id),
  CONSTRAINT chk_sgk_pss_tarih CHECK (gecerlilik_bitis IS NULL OR gecerlilik_bitis >= gecerlilik_baslangic),
  CONSTRAINT chk_sgk_pss_onay CHECK (state <> 'ONAYLANDI' OR (onaylayan_id IS NOT NULL AND onay_zamani IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_is_goremezlik_finans_kayitlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  donem CHAR(7) NOT NULL,
  surec_id INT UNSIGNED NOT NULL,
  sgk_hak_durumu ENUM('UYGULANMAZ', 'HAK_KAZANABILIR', 'HAK_KAZANDI', 'REDDEDILDI', 'BELIRSIZ') NOT NULL,
  sgk_fiili_odenen_tutar DECIMAL(14,2) NULL,
  sgk_tahmini_odenen_tutar DECIMAL(14,2) NULL,
  tahmin_durumu ENUM('UYGULANMAZ', 'TAHMINI', 'KESINLESMEMIS', 'MANUEL_DOGRULAMA', 'KESIN') NOT NULL,
  isveren_ucret_koruma_tutari DECIMAL(14,2) NULL,
  isveren_tamamlayici_odeme_tutari DECIMAL(14,2) NULL,
  mahsup_iade_tutari DECIMAL(14,2) NULL,
  bordro_kesinti_tutari DECIMAL(14,2) NULL,
  para_birimi CHAR(3) NOT NULL DEFAULT 'TRY',
  state ENUM('TASLAK', 'ONAY_BEKLIYOR', 'ONAYLANDI', 'IPTAL') NOT NULL DEFAULT 'TASLAK',
  source_hash CHAR(64) NOT NULL,
  kaynak_belge_id INT UNSIGNED NULL,
  revision_no INT UNSIGNED NOT NULL DEFAULT 1,
  parent_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sgk_igfk_surec_revision (surec_id, revision_no),
  KEY idx_sgk_igfk_personel_donem (personel_id, donem, state),
  CONSTRAINT fk_sgk_igfk_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_sgk_igfk_surec FOREIGN KEY (surec_id) REFERENCES surecler (id),
  CONSTRAINT fk_sgk_igfk_belge FOREIGN KEY (kaynak_belge_id) REFERENCES sgk_eksik_gun_belgeleri (id),
  CONSTRAINT fk_sgk_igfk_parent FOREIGN KEY (parent_id) REFERENCES sgk_is_goremezlik_finans_kayitlari (id),
  CONSTRAINT fk_sgk_igfk_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT chk_sgk_igfk_hash CHECK (source_hash REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT chk_sgk_igfk_tutarlar CHECK (
    (sgk_fiili_odenen_tutar IS NULL OR sgk_fiili_odenen_tutar >= 0) AND
    (sgk_tahmini_odenen_tutar IS NULL OR sgk_tahmini_odenen_tutar >= 0) AND
    (isveren_ucret_koruma_tutari IS NULL OR isveren_ucret_koruma_tutari >= 0) AND
    (isveren_tamamlayici_odeme_tutari IS NULL OR isveren_tamamlayici_odeme_tutari >= 0)
  ),
  CONSTRAINT chk_sgk_igfk_fiili CHECK (
    sgk_fiili_odenen_tutar IS NULL OR tahmin_durumu = 'KESIN'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maas_hesaplama_sgk_snapshotlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  donem_snapshot_id INT UNSIGNED NOT NULL,
  personel_snapshot_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  hesaplanan_prim_gunu TINYINT UNSIGNED NULL,
  eksik_gun_sayisi TINYINT UNSIGNED NULL,
  eksik_gun_kodu VARCHAR(8) NULL,
  eksik_gun_aciklamasi VARCHAR(500) NULL,
  kaynak_surec_idleri_json JSON NOT NULL,
  kaynak_puantaj_idleri_json JSON NOT NULL,
  kaynak_belge_idleri_json JSON NOT NULL,
  katalog_surum_id INT UNSIGNED NULL,
  katalog_surumu VARCHAR(80) NULL,
  kaynak_manifest_hash CHAR(64) NULL,
  sgk_hesap_hash CHAR(64) NOT NULL,
  gunluk_karar_dokumu_hash CHAR(64) NOT NULL,
  gunluk_karar_dokumu_json JSON NOT NULL,
  manuel_inceleme_gerekli_mi TINYINT(1) NOT NULL,
  blocker_kodlari_json JSON NOT NULL,
  blocker_detaylari_json JSON NOT NULL,
  ucret_modeli ENUM('MAKTU_AYLIK', 'GUNLUK', 'SAATLIK', 'DIGER', 'BELIRSIZ') NOT NULL,
  ilk_iki_gun_politika_ozeti_json JSON NOT NULL,
  sirket_politika_surum_id INT UNSIGNED NULL,
  sirket_politika_hash CHAR(64) NULL,
  sgk_odenek_durumu ENUM('UYGULANMAZ', 'TAHMINI', 'KESINLESMEMIS', 'MANUEL_DOGRULAMA', 'FIILI_TUTAR') NOT NULL DEFAULT 'UYGULANMAZ',
  is_goremezlik_finans_ozeti_json JSON NOT NULL,
  gunluk_alt_sinir DECIMAL(14,2) NULL,
  gunluk_ust_sinir DECIMAL(14,2) NULL,
  donem_alt_sinir DECIMAL(14,2) NULL,
  donem_ust_sinir DECIMAL(14,2) NULL,
  sinir_mevzuat_surumu VARCHAR(80) NULL,
  source_hash CHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mhss_personel_snapshot (personel_snapshot_id),
  UNIQUE KEY uq_mhss_donem_personel (donem_snapshot_id, personel_id),
  KEY idx_mhss_personel_donem (personel_id, donem_snapshot_id),
  KEY idx_mhss_hash (sgk_hesap_hash),
  CONSTRAINT fk_mhss_donem_snapshot FOREIGN KEY (donem_snapshot_id) REFERENCES maas_hesaplama_donem_snapshotlari (id),
  CONSTRAINT fk_mhss_personel_snapshot FOREIGN KEY (personel_snapshot_id) REFERENCES maas_hesaplama_personel_snapshotlari (id),
  CONSTRAINT fk_mhss_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_mhss_katalog FOREIGN KEY (katalog_surum_id) REFERENCES sgk_eksik_gun_katalog_surumleri (id),
  CONSTRAINT fk_mhss_sirket_politika FOREIGN KEY (sirket_politika_surum_id) REFERENCES sgk_sirket_politika_surumleri (id),
  CONSTRAINT chk_mhss_prim CHECK (hesaplanan_prim_gunu IS NULL OR hesaplanan_prim_gunu <= 30),
  CONSTRAINT chk_mhss_hash CHECK (
    sgk_hesap_hash REGEXP '^[0-9a-f]{64}$' AND gunluk_karar_dokumu_hash REGEXP '^[0-9a-f]{64}$' AND source_hash REGEXP '^[0-9a-f]{64}$'
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sgk_hesap_auditleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  donem_snapshot_id INT UNSIGNED NULL,
  personel_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  aksiyon ENUM('PREFLIGHT', 'SNAPSHOT_CREATE', 'SOURCE_CHANGED', 'EXPORT') NOT NULL,
  sonuc ENUM('READY', 'BLOCKED', 'CREATED', 'READ_ONLY') NOT NULL,
  request_hash CHAR(64) NOT NULL,
  source_hash CHAR(64) NOT NULL,
  result_hash CHAR(64) NOT NULL,
  blocker_kodlari_json JSON NOT NULL,
  actor_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sgk_ha_personel_donem (personel_id, yil, ay, created_at),
  KEY idx_sgk_ha_snapshot (donem_snapshot_id),
  CONSTRAINT fk_sgk_ha_snapshot FOREIGN KEY (donem_snapshot_id) REFERENCES maas_hesaplama_donem_snapshotlari (id),
  CONSTRAINT fk_sgk_ha_personel FOREIGN KEY (personel_id) REFERENCES personeller (id),
  CONSTRAINT fk_sgk_ha_actor FOREIGN KEY (actor_id) REFERENCES users (id),
  CONSTRAINT chk_sgk_ha_hash CHECK (request_hash REGEXP '^[0-9a-f]{64}$' AND source_hash REGEXP '^[0-9a-f]{64}$' AND result_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TRIGGER IF EXISTS trg_mhss_no_update;
CREATE TRIGGER trg_mhss_no_update
BEFORE UPDATE ON maas_hesaplama_sgk_snapshotlari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SGK_SNAPSHOT_IMMUTABLE: SGK snapshot satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_mhss_no_delete;
CREATE TRIGGER trg_mhss_no_delete
BEFORE DELETE ON maas_hesaplama_sgk_snapshotlari
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SGK_SNAPSHOT_IMMUTABLE: SGK snapshot satiri silinemez';

DROP TRIGGER IF EXISTS trg_sgk_ha_no_update;
CREATE TRIGGER trg_sgk_ha_no_update
BEFORE UPDATE ON sgk_hesap_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SGK_AUDIT_IMMUTABLE: audit satiri guncellenemez';

DROP TRIGGER IF EXISTS trg_sgk_ha_no_delete;
CREATE TRIGGER trg_sgk_ha_no_delete
BEFORE DELETE ON sgk_hesap_auditleri
FOR EACH ROW
SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'PAYROLL_SGK_AUDIT_IMMUTABLE: audit satiri silinemez';
