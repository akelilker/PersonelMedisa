-- S85-B: 2026-07-22 tarihinde resmi kurumlardan indirilen ve SHA-256 ile dogrulanan kaynak paketi.
-- Bu migration eksik gun kodu veya sirket politikasi seed ETMEZ.
-- E-Bildirge V2 sayfasi indirme aninda SGK sunucu hatasi verdigi icin manifest paketine alinmamistir.
-- Hash degerleri ebedi resmi belge kimligi degildir; OBSERVED_AT/erisim anina ait noktasal gozlemdir.
-- Ham kopya repoda arsivlenmedigi icin yeniden indirme hash degisimi eski gozlemi gecersiz kilmaz;
-- yeniden uretilebilirlik iddia edilmez.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT INTO sgk_kaynak_manifestleri (
  kaynak_id, kaynak_turu, kurum, belge_basligi, belge_tarihi, yayimlanma_tarihi,
  yururluk_baslangic, yururluk_bitis, kaynak_adresi,
  indirilen_dosya_sha256, icerik_sha256, indirilen_dosya_byte,
  dogrulama_tarihi, observed_at, arsiv_kopyasi_repoda_mi,
  dogrulama_turu, durum, aciklama
) VALUES
  (
    'CSGB_SGK_SSS_20260722', 'KURUM_ACIKLAMASI', 'T.C. Calisma ve Sosyal Guvenlik Bakanligi',
    'Sosyal Guvenlik Kurumu Sikca Sorulan Sorular', NULL, NULL, NULL, NULL,
    'https://www.csgb.gov.tr/sikca-sorulan-sorular/sosyal-guvenlik-kurumu/',
    '51258684b27771abce9c68f0be621e52ea039446be91577454f0804531a91488',
    'be05fb49efdd5001811f115dcaef40fb996588d8ab4424fa244393a4c5af37a7',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    'Tam ay ve eksik calisma prim gunu aciklamasi. HTML metni normalize edilerek icerik hash alindi; canli HTML degisebilir, hash ebedi kimlik degildir.'
  ),
  (
    'SGK_EK9_APHB_20260722', 'RESMI_LISTE', 'Sosyal Guvenlik Kurumu',
    'Aylik Prim ve Hizmet Belgesi Ek-9 Eksik Gun Nedenleri', NULL, NULL, NULL, NULL,
    'https://kocaeli.sgk.gov.tr/Download/DownloadFile?d=5efd9caa-d337-41ec-bf2a-f75441852bb5&f=a5060ca0-512f-4abc-a3bb-1dba5bae14a7.pdf',
    'd0a60bc9182643086cadd9362114c6ef675abb1e50361c53cc0a38fce8eae194',
    'd0a60bc9182643086cadd9362114c6ef675abb1e50361c53cc0a38fce8eae194',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    'Tarihsel baseline OBSERVED_AT kaydi. PDF binary hash ayni zamanda icerik hash olarak kaydedildi; tek basina guncel tam katalog sayilmaz; arsiv kopyasi repoda yok.'
  ),
  (
    'SGK_07_PUANTAJ_20231120', 'DUYURU', 'Sosyal Guvenlik Kurumu',
    '07-Puantaj Kayitlari Eksik Gun Nedeninin 0 Gun ve 0 Kazancli Bildirimler Icin Secilememesi',
    '2023-11-20', '2023-11-20', '2023-11-20', NULL,
    'https://www.sgk.gov.tr/Duyuru/Detay/07-Puantaj-Kayitlari-Eksik-Gun-Nedeninin-0-Gun-ve-0-Kazancli-Bildirimler-Icin-Secilememesi-2023-11-20-04-29-06',
    '07915d25487e81aea6dc36d1344e30f17b6035fd564fad0ee2212e92b6636454',
    '601f03ea3289b64a1ad742ca183f8c6785623465243d0e2d6c57a137da5dfcd0',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    '07 kodunun 0 gun/0 kazanc kisiti ve imzali gun semantigi. Noktasal gozlem; HTML degisebilir.'
  ),
  (
    'SGK_EKSIK_GUN_BELGELERI_20221116', 'DUYURU', 'Sosyal Guvenlik Kurumu',
    'Eksik Gun Belgelerinin Verilmesine Iliskin Duyuru', '2022-11-16', '2022-11-16', '2022-11-16', NULL,
    'https://www.sgk.gov.tr/Duyuru/Detay/Eksik-Gun-Belgelerinin-Verilmesine-Iliskin-Duyuru-2022-11-16-04-41-26',
    '7f9da0d402489dcf252580c1cbef7f4094a74a6c6181019693831707354a9284',
    '0e74d17b6ac5b709b8ad6ac43a626d2e90d572ff279b4bd865154835f6fdb47f',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    'Belge hazirlama, saklama ve talep halinde ibraz dayanagi.'
  ),
  (
    'SGK_HASTALIK_HALI_20250227', 'KURUM_ACIKLAMASI', 'Sosyal Guvenlik Kurumu',
    'Hastalik Hali', '2025-02-27', '2025-02-27', NULL, NULL,
    'https://www.sgk.gov.tr/Content/Post/e33fffdb-16b9-4722-a9c1-2bd0a2a220d1/Hastalik-Hali-2025-02-27-11-27-26',
    '16f369566ed2a0f55ed2d8e6e22741a881e0de39096d99ea3481da57a1203184',
    '5778e239e59da02c5704c647ecc1a2a1c9cf23142b015ccff98eebb405c03808',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    'SGK gecici is goremezlik hakki ile isveren ucret politikasini ayiran kaynak.'
  ),
  (
    'SGK_IS_KAZASI_20250227', 'KURUM_ACIKLAMASI', 'Sosyal Guvenlik Kurumu',
    'Is Kazasi', '2025-02-27', '2025-02-27', NULL, NULL,
    'https://www.sgk.gov.tr/Content/Post/7b0b48c6-ceba-472b-8011-c4a9b3125133/Is-Kazasi-2025-02-27-10-08-15',
    '410e3b9be1da855e07c41ce8cc9f1924ab05dc1f45c460ce6b3d54c47aba559e',
    'b7225784f40aab8390e79491d648daac95857e4e9090563bae21e0c385885ecb',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    'Is kazasi ayri sigorta hali ve bildirim sureleri.'
  ),
  (
    'SGK_MESLEK_HASTALIGI_20250227', 'KURUM_ACIKLAMASI', 'Sosyal Guvenlik Kurumu',
    'Meslek Hastaligi', '2025-02-27', '2025-02-27', NULL, NULL,
    'https://www.sgk.gov.tr/Content/Post/a4b7b555-198f-41e4-a020-fa52276bda37/Meslek-Hastaligi-2025-02-27-10-32-29',
    '986d2899b0c78a6dc470de4df420c290a59e20c2c8c2aa3cef98dec5936dedd9',
    'e33fa46b26bda893e73ca8931032ec1ddcf6b9887530d319f8dff0777c27d0e1',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    'Meslek hastaligi ayri sigorta hali ve bildirim sureleri.'
  ),
  (
    'SGK_PEK_SINIRLARI_2026', 'KURUM_ACIKLAMASI', 'Sosyal Guvenlik Kurumu',
    'Prime Esas Kazanc Miktarlari 2026', '2026-01-14', '2026-01-14', '2026-01-01', '2026-12-31',
    'https://www.sgk.gov.tr/Content/Post/2e0c9e1a-2cfe-4456-af10-49d3de0c58ba/Prime-Esas-Kazanc-Miktarlari-2026-01-14-10-35-39',
    'c8ed413490999d7714ce8f9846af0a92794b2ff6808fee8ef1a5da27bb9931dd',
    '0f0540007adc8e15dbee762b259991121256e65581446c65347334703353414f',
    NULL,
    '2026-07-22 00:00:00', '2026-07-22 00:00:00', 0,
    'SISTEM_KAYNAK_PAKETI', 'AKTIF',
    'Gunluk ve aylik PEK alt/ust sinirlari.'
  )
ON DUPLICATE KEY UPDATE kaynak_id = VALUES(kaynak_id);
