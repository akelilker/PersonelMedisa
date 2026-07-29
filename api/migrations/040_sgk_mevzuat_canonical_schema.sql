-- S98: SGK mevzuat canonical durum semasi + kaynak manifest tarih duzeltmesi.
-- Additive only. Katalog kodu / sirket politikasi seed YOK.
-- Production'da otomatik calistirilmaz. Mevcut kolonlar silinmez.
-- MariaDB 10.6 / 11.4 uyumlu. 036-039 dokunulmaz.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Canonical durum alanlari (legacy boolean/enum kolonlari korunur).
ALTER TABLE sgk_eksik_gun_kodlari
  ADD COLUMN IF NOT EXISTS aktiflik_durumu ENUM(
    'AKTIF',
    'TARIHSEL',
    'BAGLAMA_OZGUN',
    'PORTAL_TEYIT_BEKLIYOR'
  ) NOT NULL DEFAULT 'PORTAL_TEYIT_BEKLIYOR' AFTER aktif_mi,
  ADD COLUMN IF NOT EXISTS sifir_gun_sifir_kazanc_durumu ENUM(
    'IZINLI',
    'YASAK',
    'KOSULLU',
    'TEYITSIZ'
  ) NOT NULL DEFAULT 'TEYITSIZ' AFTER sifir_gun_sifir_kazanc_kullanilabilir_mi,
  ADD COLUMN IF NOT EXISTS belge_saklama_ibraz_durumu ENUM(
    'YOK',
    'ISVERENCE_SAKLA_TALEPTE_IBRAZ',
    'ELEKTRONIK_KAYNAKTAN',
    'KURUMA_GONDER',
    'KOSULLU',
    'TEYITSIZ'
  ) NOT NULL DEFAULT 'TEYITSIZ' AFTER belge_zorunlulugu,
  ADD COLUMN IF NOT EXISTS yabanci_kullanim_durumu ENUM(
    'IZINLI',
    'YASAK',
    'KOSULLU',
    'TEYITSIZ'
  ) NOT NULL DEFAULT 'TEYITSIZ' AFTER kismi_sureli_sozlesme_gerekli_mi,
  ADD COLUMN IF NOT EXISTS portal_teyit_durumu ENUM(
    'TEYIT_EDILDI',
    'TEYIT_BEKLIYOR',
    'TARIHSEL'
  ) NOT NULL DEFAULT 'TEYIT_BEKLIYOR' AFTER aktiflik_durumu,
  ADD COLUMN IF NOT EXISTS mevzuat_kurallari_json JSON NULL AFTER kosullar_json;

-- Kaynak manifest: URL slug tarihi (2022-11-16) ile gercek yayin tarihi (2018-04-17) karistirilmis kaydi
-- destructive guncelleme yerine additive replacement.
-- Ayni gozlem hash'leri korunur; yeni kaynak_id ile dogru tarih semantigi yazilir.
-- Hash uydurulmaz; arsiv_kopyasi_repoda_mi=0. Volatile HTML ebedi kimlik degildir.
-- E-BildirgeV2 HTML/XSD icin dosya hash gozlemi yoksa sahte manifest eklenmez.
-- UNIQUE(kaynak_adresi, indirilen_dosya_sha256) cakismasini acmak icin once eski kaydin
-- adresine supersede fragment eklenir; canonical URL yeni kayitta kalir.

UPDATE sgk_kaynak_manifestleri
SET
  kaynak_adresi = CONCAT(
    kaynak_adresi,
    CASE
      WHEN kaynak_adresi LIKE '%#superseded-by-SGK_EKSIK_GUN_BELGELERI_20180417' THEN ''
      ELSE '#superseded-by-SGK_EKSIK_GUN_BELGELERI_20180417'
    END
  )
WHERE kaynak_id = 'SGK_EKSIK_GUN_BELGELERI_20221116'
  AND kaynak_adresi NOT LIKE '%#superseded-by-SGK_EKSIK_GUN_BELGELERI_20180417';

INSERT INTO sgk_kaynak_manifestleri (
  kaynak_id, kaynak_turu, kurum, belge_basligi, belge_tarihi, yayimlanma_tarihi,
  yururluk_baslangic, yururluk_bitis, kaynak_adresi,
  indirilen_dosya_sha256, icerik_sha256, indirilen_dosya_byte,
  dogrulama_tarihi, observed_at, arsiv_kopyasi_repoda_mi,
  dogrulama_turu, durum, aciklama
) VALUES
  (
    'SGK_EKSIK_GUN_BELGELERI_20180417', 'DUYURU', 'Sosyal Guvenlik Kurumu',
    'Eksik Gun Belgelerinin Verilmesine Iliskin Duyuru',
    '2018-04-17', '2018-04-17', '2018-03-01', NULL,
    'https://www.sgk.gov.tr/Duyuru/Detay/Eksik-Gun-Belgelerinin-Verilmesine-Iliskin-Duyuru-2022-11-16-04-41-26',
    '7f9da0d402489dcf252580c1cbef7f4094a74a6c6181019693831707354a9284',
    '0e74d17b6ac5b709b8ad6ac43a626d2e90d572ff279b4bd865154835f6fdb47f',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    'S98: Resmi yayin tarihi 17.04.2018. URL slugindeki 2022-11-16 indirme/slug tarihini yayin tarihi sayma. 2018/Mart doneminden itibaren belge isverence saklanir, talepte ibraz edilir; normal aylik bildirimde Kuruma verilmez. Ayni OBSERVED_AT hash gozlemi; arsiv kopyasi repoda yok; volatile HTML ebedi kimlik degildir.'
  )
ON DUPLICATE KEY UPDATE
  belge_tarihi = VALUES(belge_tarihi),
  yayimlanma_tarihi = VALUES(yayimlanma_tarihi),
  yururluk_baslangic = VALUES(yururluk_baslangic),
  kaynak_adresi = VALUES(kaynak_adresi),
  durum = 'AKTIF',
  aciklama = VALUES(aciklama);

UPDATE sgk_kaynak_manifestleri AS eski
INNER JOIN sgk_kaynak_manifestleri AS yeni
  ON yeni.kaynak_id = 'SGK_EKSIK_GUN_BELGELERI_20180417'
SET
  eski.durum = 'PASIF',
  eski.yerine_gecen_kaynak_id = yeni.id,
  eski.aciklama = CASE
    WHEN eski.aciklama LIKE '%S98: PASIF%' THEN eski.aciklama
    ELSE CONCAT(
      COALESCE(eski.aciklama, ''),
      ' | S98: PASIF — yayin tarihi URL slug (2022-11-16) ile karistirildi; yerine SGK_EKSIK_GUN_BELGELERI_20180417.'
    )
  END
WHERE eski.kaynak_id = 'SGK_EKSIK_GUN_BELGELERI_20221116'
  AND (eski.durum <> 'PASIF' OR eski.yerine_gecen_kaynak_id IS NULL OR eski.yerine_gecen_kaynak_id <> yeni.id);
