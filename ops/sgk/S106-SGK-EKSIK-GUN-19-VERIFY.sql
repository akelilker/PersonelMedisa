-- S106 VERIFY — salt okunur. Yazma ifadesi yok (yalniz SELECT).
-- Beklenen: 19 kod, tamlik=RESMI_KAYNAKLI_KISITLI, state=ONAYLANDI, DOGRULANMIS_TAM yok.

SET NAMES utf8mb4;

-- 1) Katalog surumu durumu
SELECT
  surum_kodu,
  state,
  tamlik_durumu,
  katalog_payload_hash,
  manifest_set_hash,
  resmi_kaynaklar_incelendi_mi,
  belirsiz_tarihler_uydurulmadi_mi,
  kisitli_kullanim_kabul_edildi_mi,
  onaylayan_id,
  onay_zamani
FROM sgk_eksik_gun_katalog_surumleri
WHERE surum_kodu = 'SGK-EKSIK-GUN-RESMI-2026-07';

-- 2) Kod sayisi ve exact set
SELECT
  COUNT(*) AS kod_sayisi,
  COUNT(DISTINCT eksik_gun_kodu) AS unique_kod,
  SUM(CASE WHEN gecerlilik_baslangic IS NULL THEN 1 ELSE 0 END) AS null_baslangic,
  SUM(CASE WHEN gecerlilik_tarih_durumu = 'BELIRLENEMEDI' THEN 1 ELSE 0 END) AS belirlenemedi_sayisi
FROM sgk_eksik_gun_kodlari k
INNER JOIN sgk_eksik_gun_katalog_surumleri s ON s.id = k.katalog_surum_id
WHERE s.surum_kodu = 'SGK-EKSIK-GUN-RESMI-2026-07';

-- 3) Exact 19 kod listesi
SELECT k.eksik_gun_kodu, k.resmi_aciklama, k.gecerlilik_baslangic, k.gecerlilik_tarih_durumu,
       k.sifir_gun_sifir_kazanc_durumu, k.portal_teyit_durumu, k.aktiflik_durumu
FROM sgk_eksik_gun_kodlari k
INNER JOIN sgk_eksik_gun_katalog_surumleri s ON s.id = k.katalog_surum_id
WHERE s.surum_kodu = 'SGK-EKSIK-GUN-RESMI-2026-07'
ORDER BY k.eksik_gun_kodu;

-- 4) Yasak kodlar (26/27/28/29) bu surumde olmamali
SELECT k.eksik_gun_kodu
FROM sgk_eksik_gun_kodlari k
INNER JOIN sgk_eksik_gun_katalog_surumleri s ON s.id = k.katalog_surum_id
WHERE s.surum_kodu = 'SGK-EKSIK-GUN-RESMI-2026-07'
  AND k.eksik_gun_kodu IN ('26', '27', '28', '29');

-- 5) Kod 07 sifir gun yasagi
SELECT k.eksik_gun_kodu, k.sifir_gun_sifir_kazanc_durumu, k.sifir_gun_sifir_kazanc_kullanilabilir_mi
FROM sgk_eksik_gun_kodlari k
INNER JOIN sgk_eksik_gun_katalog_surumleri s ON s.id = k.katalog_surum_id
WHERE s.surum_kodu = 'SGK-EKSIK-GUN-RESMI-2026-07'
  AND k.eksik_gun_kodu = '07';

-- 6) DOGRULANMIS_TAM olmamali; RESMI_KAYNAKLI_KISITLI + ONAYLANDI olmali
SELECT
  CASE
    WHEN tamlik_durumu = 'RESMI_KAYNAKLI_KISITLI' AND state = 'ONAYLANDI' THEN 'PASS'
    ELSE 'FAIL'
  END AS s106_katalog_state_check,
  CASE
    WHEN tamlik_durumu <> 'DOGRULANMIS_TAM' THEN 'PASS'
    ELSE 'FAIL'
  END AS dogrulanmis_tam_yok_check
FROM sgk_eksik_gun_katalog_surumleri
WHERE surum_kodu = 'SGK-EKSIK-GUN-RESMI-2026-07';
