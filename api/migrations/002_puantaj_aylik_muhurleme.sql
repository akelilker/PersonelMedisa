-- S36C Puantaj aylik muhurleme migration
-- Additive migration; do not drop or rewrite existing data.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS puantaj_aylik_muhurleri (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sube_id INT UNSIGNED NOT NULL,
  yil SMALLINT UNSIGNED NOT NULL,
  ay TINYINT UNSIGNED NOT NULL,
  donem CHAR(7) NOT NULL,
  durum VARCHAR(32) NOT NULL DEFAULT 'MUHURLENDI',
  muhurlenen_kayit_sayisi INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_puantaj_aylik_muhur_sube_donem (sube_id, yil, ay),
  KEY idx_puantaj_aylik_muhur_donem (donem),
  KEY idx_puantaj_aylik_muhur_created_by (created_by),
  CONSTRAINT fk_puantaj_aylik_muhur_sube FOREIGN KEY (sube_id) REFERENCES subeler (id),
  CONSTRAINT fk_puantaj_aylik_muhur_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE gunluk_puantaj
  ADD COLUMN state VARCHAR(32) NOT NULL DEFAULT 'ACIK' AFTER tarih,
  ADD COLUMN durumu_bildirdi_mi TINYINT(1) NULL AFTER dayanak,
  ADD COLUMN durum_bildirim_aciklamasi TEXT NULL AFTER durumu_bildirdi_mi,
  ADD COLUMN beklenen_giris_saati VARCHAR(8) NULL AFTER hesap_etkisi,
  ADD COLUMN beklenen_cikis_saati VARCHAR(8) NULL AFTER beklenen_giris_saati,
  ADD COLUMN gercek_mola_dakika INT UNSIGNED NULL AFTER cikis_saati,
  ADD COLUMN hesaplanan_mola_dakika INT UNSIGNED NULL AFTER gercek_mola_dakika,
  ADD COLUMN net_calisma_suresi_dakika INT UNSIGNED NULL AFTER hesaplanan_mola_dakika,
  ADD COLUMN gunluk_brut_sure_dakika INT UNSIGNED NULL AFTER net_calisma_suresi_dakika,
  ADD COLUMN hafta_tatili_hak_kazandi_mi TINYINT(1) NULL AFTER gunluk_brut_sure_dakika,
  ADD COLUMN kaynak VARCHAR(32) NULL AFTER kontrol_durumu,
  ADD COLUMN aciklama TEXT NULL AFTER kaynak,
  ADD COLUMN muhur_id INT UNSIGNED NULL AFTER aciklama,
  ADD KEY idx_gunluk_puantaj_muhur (muhur_id),
  ADD CONSTRAINT fk_gunluk_puantaj_muhur FOREIGN KEY (muhur_id) REFERENCES puantaj_aylik_muhurleri (id);

CREATE TABLE IF NOT EXISTS puantaj_aylik_muhur_satirlari (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  muhur_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NOT NULL,
  tarih DATE NOT NULL,
  gun_tipi VARCHAR(40) NULL,
  hareket_durumu VARCHAR(40) NULL,
  dayanak VARCHAR(40) NULL,
  durumu_bildirdi_mi TINYINT(1) NULL,
  durum_bildirim_aciklamasi TEXT NULL,
  hesap_etkisi VARCHAR(40) NULL,
  beklenen_giris_saati VARCHAR(8) NULL,
  beklenen_cikis_saati VARCHAR(8) NULL,
  giris_saati VARCHAR(8) NULL,
  cikis_saati VARCHAR(8) NULL,
  gercek_mola_dakika INT UNSIGNED NULL,
  hesaplanan_mola_dakika INT UNSIGNED NULL,
  net_calisma_suresi_dakika INT UNSIGNED NULL,
  gunluk_brut_sure_dakika INT UNSIGNED NULL,
  hafta_tatili_hak_kazandi_mi TINYINT(1) NULL,
  kontrol_durumu VARCHAR(32) NOT NULL DEFAULT 'BEKLIYOR',
  kaynak VARCHAR(32) NULL,
  aciklama TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_puantaj_muhur_satir_gun (muhur_id, personel_id, tarih),
  KEY idx_puantaj_muhur_satir_personel_tarih (personel_id, tarih),
  CONSTRAINT fk_puantaj_muhur_satir_muhur FOREIGN KEY (muhur_id) REFERENCES puantaj_aylik_muhurleri (id) ON DELETE CASCADE,
  CONSTRAINT fk_puantaj_muhur_satir_personel FOREIGN KEY (personel_id) REFERENCES personeller (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
