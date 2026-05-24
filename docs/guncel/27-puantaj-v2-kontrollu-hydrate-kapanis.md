# Puantaj V2 Eksik Gün Kontrollü Hydrate

## Tarih

2026-05-25

## Kapanan işler

- Eksik tarih modeli hook çıktısına eklendi.
- Kontrollü hydrate fonksiyonu eklendi.
- Tek çağrıda 7 tarih sınırı getirildi.
- Null kayıt cache'e yazılarak kapsam bilgisi tamamlanabilir hale geldi.
- Null kayıtların hesap motoruna gerçek kayıt olarak gitmesi engellendi.
- Personel Detay > Puantaj sekmesine "Kapsamı Tamamla" aksiyonu bağlandı.
- UI hesap yapmadan sadece hook fonksiyonunu tetikliyor.

## Eklenen/bağlanan alanlar

- hydrateEksikPuantajTarihleri
- hydrateDurumu
- hydrateEdilenTarihSayisi
- hydrateHataMesaji
- hydrateMumkunMu
- eksikTarihSayisi
- eksikTarihListesi
- veriKapsamiTamMi

## Değişen dosyalar

- src/hooks/usePuantajEksikGunOzeti.ts
- tests/unit/usePuantajEksikGunOzeti.test.ts
- src/features/personeller/pages/PersonelDetayPage.tsx

## Korunan sınırlar

- Otomatik fetch yok.
- UI hesap yapmıyor.
- API değişmedi.
- Service hesap motoru değişmedi.
- Finans/bordro/maaş yok.
- SGK resmi kod yok.
- Dashboard yok.

## Doğrulama

- npm run test -- tests/unit/usePuantajEksikGunOzeti.test.ts: 10 passed
- npm run test: 361 passed
- npm run typecheck: OK
- npm run build: OK
- npx playwright test tests/e2e/personel-dosya.spec.ts: 7 passed
- GitHub Actions CI: yeşil
- Deploy cPanel: yeşil

## Son durum

Eksik gün readonly özeti artık kullanıcı aksiyonuyla kontrollü şekilde veri kapsamını genişletebilir. Bu hâlâ bordro/maaş hesabı değildir; kapsam ve sınıflandırma desteğidir.

## Sonraki önerilen iş

Canlı Kullanım Mini Checklist ve İK kullanım akışlarının kısa kontrol dokümanı.
