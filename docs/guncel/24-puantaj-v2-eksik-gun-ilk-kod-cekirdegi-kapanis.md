# Puantaj V2 Eksik Gun Ilk Kod Cekirdegi Kapanis

## 1. Faz Ozeti

Eksik gun / SGK prim gunu / ucret etkisi ayriminin ilk dar cekirdegi tamamlandi.

Saf karar siniflandirma fonksiyonu eklendi.

UI, hook ve API bu fazin disinda tutuldu.

## 2. Degisen Dosyalar

- `src/services/puantaj-hesap-motoru.ts`
- `tests/unit/puantaj-hesap-motoru.test.ts`
- `docs/guncel/23-puantaj-v2-eksik-gun-dar-tasarim-karari.md`

## 3. Eklenen Fonksiyon

`siniflandirPuantajEksikGunEtkisi`

Bu fonksiyon gunluk puantaj satirini su acilardan siniflandirir:

- eksik gun
- SGK prim gunu etkisi
- ucret etkisi
- manuel inceleme ihtiyaci

## 4. Kapsam Icinde Tamamlananlar

- `Gec_Geldi` / `Erken_Cikti` SGK eksik gun hesabindan ayrildi.
- `Gelmedi + Yok_Izinsiz` tam gun eksik gun adayi olarak siniflandirildi.
- `Ucretli_Izinli` / `Yillik_Izin` / `Telafi_Calismasi` SGK prim gununu dusurmez.
- `Raporlu_Hastalik` / `Raporlu_Is_Kazasi` otomatik SGK dusumu uretmez.
- `durumu_bildirdi_mi` yalniz sinyal alani olarak tutuldu.

## 5. Bilincli Olarak Disarida Birakilanlar

- resmi SGK kodlari
- bordro/net maas
- finans kalemi
- ucretsiz izin modeli
- yarim gun/kismi gun
- dashboard
- API contract genislemesi
- UI readonly gosterim genislemesi

## 6. Owner Karari

Birincil owner:

`src/services/puantaj-hesap-motoru.ts`

Test owner:

`tests/unit/puantaj-hesap-motoru.test.ts`

UI:

yalniz render/girdi katmani

## 7. Test Durumu

- `npm run test` -> 348 passed
- `npm run typecheck` -> OK

## 8. Mimari Koruma Kararlari

- mevcut `hesaplaSgkPrimGunu` davranisi korunmustur.
- gec/erken dakika kesintisi SGK hattina baglanmamistir.
- UI icinde hesap yapilmamistir.
- raporlu gunlerde otomatik SGK dusumu yapilmamistir.

## 9. Acik Kalan Alanlar

- ucretsiz izin modeli
- rapor alt turleri
- analik/refakat
- snapshot vs canli hesap
- resmi SGK kod sozlugu
- bordro politikasi

## 10. Sonraki Faz

Olasi sonraki teknik hedef:

- ay bazli toplu eksik gun aggregation katmani
- readonly rapor/personel detay gorunumu
