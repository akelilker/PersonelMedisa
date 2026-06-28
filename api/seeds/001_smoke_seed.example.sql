-- PersonelMedisa smoke seed template
-- Target DB: karmotor_medisa
-- ONEMLI: Gercek sifre/hash bu dosyada yok. Canlida PHP password_hash() ile bcrypt uretilecek.
-- WordPress DB karmotor_wp73 tablolarina DOKUNMA.

SET NAMES utf8mb4;

-- Referans
INSERT INTO subeler (id, kod, ad, durum) VALUES
  (1, 'MRK', 'Merkez', 'AKTIF'),
  (2, 'GRS', 'Giresun', 'AKTIF');

INSERT INTO departmanlar (id, ad, durum) VALUES
  (1, 'Uretim', 'AKTIF'),
  (2, 'Depolama', 'AKTIF');

INSERT INTO gorevler (id, ad, durum) VALUES
  (1, 'Operator', 'AKTIF'),
  (2, 'Depo Sorumlusu', 'AKTIF');

INSERT INTO personel_tipleri (id, ad, durum) VALUES
  (1, 'Tam Zamanli', 'AKTIF'),
  (2, 'Sozlesmeli', 'AKTIF');

INSERT INTO sube_departmanlar (sube_id, departman_id) VALUES
  (1, 1),
  (2, 2);

-- Kullanicilar (password_hash placeholder — canlida degistirilecek)
-- Ornek uretim: php -r "echo password_hash('YOUR_PASSWORD', PASSWORD_BCRYPT), PHP_EOL;"
INSERT INTO users (id, username, password_hash, ad_soyad, rol, durum) VALUES
  (1, 'genel_yonetici', '$2y$10$REPLACE_WITH_BCRYPT_HASH_FROM_PHP_PASSWORD_HASH', 'Genel Yonetici', 'GENEL_YONETICI', 'AKTIF'),
  (2, 'muhasebe', '$2y$10$REPLACE_WITH_BCRYPT_HASH_FROM_PHP_PASSWORD_HASH', 'Muhasebe Kullanicisi', 'MUHASEBE', 'AKTIF'),
  (3, 'birim_amiri', '$2y$10$REPLACE_WITH_BCRYPT_HASH_FROM_PHP_PASSWORD_HASH', 'Birim Amiri', 'BIRIM_AMIRI', 'AKTIF'),
  (4, 'bolum_yoneticisi', '$2y$10$REPLACE_WITH_BCRYPT_HASH_FROM_PHP_PASSWORD_HASH', 'Bolum Yoneticisi', 'BOLUM_YONETICISI', 'AKTIF');

INSERT INTO user_subeler (user_id, sube_id) VALUES
  (2, 1),
  (2, 2),
  (3, 1),
  (4, 2);

-- Personeller
INSERT INTO personeller (
  id, tc_kimlik_no, ad, soyad, dogum_tarihi, telefon, acil_durum_kisi, acil_durum_telefon,
  sicil_no, ise_giris_tarihi, sube_id, departman_id, gorev_id, personel_tipi_id, aktif_durum
) VALUES
  (1, '11111111111', 'Ayse', 'Yilmaz', '1990-03-10', '05551111111', 'Ali Yilmaz', '05552222222',
   'P-0001', '2020-01-15', 1, 1, 1, 1, 'AKTIF'),
  (2, '22222222222', 'Mehmet', 'Demir', '1988-07-21', '05553333333', 'Zeynep Demir', '05554444444',
   'P-0002', '2019-06-01', 2, 2, 2, 1, 'AKTIF');

-- Puantaj read-only smoke (tarih smoke-notes ile hizalanacak)
INSERT INTO gunluk_puantaj (personel_id, tarih, gun_tipi, hareket_durumu, dayanak, hesap_etkisi, giris_saati, cikis_saati, kontrol_durumu)
VALUES
  (1, '2026-06-15', 'Normal_Is_Gunu', 'Geldi', NULL, 'Tam_Yevmiye_Ver', '08:30', '17:30', 'BEKLIYOR'),
  (2, '2026-06-15', 'Normal_Is_Gunu', 'Geldi', NULL, 'Tam_Yevmiye_Ver', '08:00', '17:00', 'BEKLIYOR');

-- Aylik ozet smoke donemi
INSERT INTO aylik_kapanis_state (ay, state) VALUES ('2026-06', 'BOLUM_ONAYINDA');

INSERT INTO aylik_ozet_satirlari (
  ay, personel_id, ad_soyad, sicil_no, sube_id, sube, departman_id, bolum, bagli_amir_adi,
  devamsizlik_gun, gec_kalma_adet, izinli_gelmedi, izinsiz_gelmedi, raporlu,
  tesvik_tutari, ceza_kesinti_tutari, bolum_onay_durumu, revize_var_mi, son_islem, kapanis_durumu
) VALUES
  ('2026-06', 1, 'Ayse Yilmaz', 'P-0001', 1, 'Merkez', 1, 'Uretim', '-', 0, 0, 0, 0, 0, 0, 0, 'BOLUM_ONAYINDA', 0, '-', 'ACIK'),
  ('2026-06', 2, 'Mehmet Demir', 'P-0002', 2, 'Giresun', 2, 'Depolama', '-', 0, 0, 0, 0, 0, 0, 0, 'BOLUM_ONAYINDA', 0, '-', 'ACIK');
