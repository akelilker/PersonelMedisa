# PersonelMedisa Canli Kullanim Mini Checklist

## 1. Mevcut durum

- Puantaj V2 eksik gun readonly ozet ve kontrollu hydrate fazi kapanmis durumda.
- Personel Detay > Puantaj sekmesinde Aylik Puantaj Ozeti readonly olarak izleniyor.
- Kapsam eksigi varsa veri kapsami kullanici aksiyonuyla genisletilebiliyor.
- Bu checklist kod yazmadan once IK kullanicisi gozuyle temel canli kullanim kontrol sirasini tarif eder.

## 2. Kontrol edilecek ana ekranlar

- Login ekrani.
- Ana sayfa ve navigasyon.
- Personel liste ekrani.
- Personel Detay sekmeleri.
- Puantaj / Eksik Gun readonly ozet yuzeyi.
- Gunluk Puantaj formu.
- Surec / bildirim akislari.
- Raporlar.
- Mobil gorunum.
- GitHub Actions ve Deploy cPanel durumu.

## 3. Login ve yetki kontrol listesi

- Kullanici login olabiliyor mu?
- Hatali kullanici bilgisi icin anlasilir hata mesaji gorunuyor mu?
- Login sonrasi kullanici yetkisine uygun ana ekrana ulasiyor mu?
- Yetkisiz veya korumali rota davranisi bozulmamis mi?
- Cikis yapildiktan sonra korumali ekranlara dogrudan erisim engelleniyor mu?

## 4. Ana sayfa / navigasyon kontrol listesi

- Ana sayfa temel kartlar ve navigasyonla aciliyor mu?
- Ana menuden personel, puantaj, surec ve rapor alanlarina beklenen sekilde gidilebiliyor mu?
- Geri donus ve sayfa yenileme sonrasinda ekran kullanilabilir kaliyor mu?
- Yetkiye gore gorunmemesi gereken aksiyonlar gizli kaliyor mu?

## 5. Personel liste kontrol listesi

- Personel listesi aciliyor mu?
- Liste bos degilse satirlar okunabilir ve tiklanabilir gorunuyor mu?
- Arama, filtre veya sayfalama varsa temel kullanimda bozulma yok mu?
- Personel detay sayfasina gecis calisiyor mu?
- Liste ekraninda gereksiz maas, bordro veya finans bilgisi sunulmuyor mu?

## 6. Personel Detay kontrol listesi

- Personel detay sayfasi aciliyor mu?
- Genel Bilgiler, Puantaj, Izin / Devamsizlik, Zimmet & Envanter ve Surec Gecmisi sekmeleri gorunuyor mu?
- Sekmeler dogru paneli gosteriyor mu?
- Puantaj sekmesinde Izin / Devamsizlik paneli tekrar gorunmuyor mu?
- Personel temel bilgileri readonly durumda okunabilir mi?
- Yetkiye bagli aksiyonlar beklenen sekilde gorunuyor veya gizleniyor mu?

## 7. Puantaj / Eksik Gun kontrol listesi

- Puantaj sekmesinde Aylik Puantaj Ozeti gorunuyor mu?
- Donem, SGK Prim Gunu, Eksik Gun, Eksik Gun Nedeni, Takvim Gun Sayisi ve Hesaplama Modu alanlari okunabilir mi?
- Eksik gun readonly ozeti varsa cekirdek durum ve onbellek kapsami gorunuyor mu?
- Gec_Geldi / Erken_Cikti SGK eksik gun gibi sunulmuyor mu?
- Raporlu_Hastalik / Raporlu_Is_Kazasi otomatik SGK dusumu gibi sunulmuyor mu?
- Ekran kullaniciya kesin SGK resmi kodu veya bordro sonucu vaat etmiyor mu?

## 8. Kontrollu hydrate kontrol listesi

- Veri kapsami eksikse Kapsami Tamamla aksiyonu mantikli gorunuyor mu?
- Veri kapsami tam ise Kapsami Tamamla butonu gizli kaliyor mu?
- Kapsami Tamamla butonu otomatik fetch yerine kullanici aksiyonuyla calisacak sekilde tariflenmis mi?
- Loading durumunda buton pasif hale geliyor ve eksik puantaj tarihleri yukleniyor mesaji gorunuyor mu?
- Basarili durumda kontrol edilen tarih sayisi sade sekilde gorunuyor mu?
- Hata durumunda kullaniciya hook'tan gelen hata mesaji gosteriliyor mu?
- UI hesap yapmadan sadece hook fonksiyonunu tetikliyor mu?

## 9. Gunluk Puantaj kontrol listesi

- Gunluk Puantaj formu aciliyor mu?
- Personel ve tarih secimi temel kullanimda calisiyor mu?
- Durumu Bildirdi mi? sorusu gorunuyor mu?
- Evet secilince aciklama input'u geliyor mu?
- Hayir secilince gereksiz aciklama zorunlulugu dogmuyor mu?
- Kayit durumlari kullaniciya anlasilir sekilde sunuluyor mu?
- Form masaustu ve mobil gorunumde temel olarak kullanilabilir mi?

## 10. Surec / bildirim kontrol listesi

- Surec ekleme veya surec gecmisi yetkiye gore aciliyor mu?
- Personel detayinda surec gecmisi dogru sekmede listeleniyor mu?
- Izin, rapor, isten ayrilma ve organizasyon degisikligi gibi kayitlar okunabilir ozetle gorunuyor mu?
- Bildirim veya surec aksiyonlari kullaniciya yanlis finans/maas sonucu uretmiyor mu?
- Surec akisi tamamlandiktan sonra personel detay ekrani kullanilabilir kaliyor mu?

## 11. Raporlar kontrol listesi

- Raporlar ekrani yetkili kullanici icin aciliyor mu?
- Temel filtreler veya tarih araliklari varsa bozulmadan calisiyor mu?
- Rapor ekranlari SGK resmi kodu, bordro veya net maas sonucu uretiyormus gibi sunulmuyor mu?
- Bos veri durumunda anlasilir bos durum mesaji gorunuyor mu?
- Rapor ciktilari okunabilir ve sayfa duzeni tutarli mi?

## 12. Mobil gorunum kontrol listesi

- Login, ana sayfa, personel liste ve personel detay ekranlarinda tasma var mi?
- Sekmeler mobilde ust uste binmeden kullanilabiliyor mu?
- Puantaj readonly ozet alanlari mobilde okunabilir mi?
- Kapsami Tamamla aksiyonu mobilde tiklanabilir ve durum metni okunabilir mi?
- Gunluk Puantaj formunda alanlar dar ekranda ust uste binmeden ilerliyor mu?

## 13. Deploy / GitHub Actions kontrol listesi

- GitHub Actions son gorulen durumda yesil mi?
- Deploy cPanel son gorulen durumda yesil mi?
- Son deploy sonrasi uygulama ana rotasi aciliyor mu?
- Deploy sonrasi login ve personel detay temel akisi tekrar kontrol edildi mi?
- Canli kontrol sirasinda bulunan sorunlar ayri teshis notu olarak kaydedildi mi?

## 14. Bilincli kapsam disilar

- Maas hesaplama bu kontrol fazinin kapsami disindadir.
- Net maas bu kontrol fazinin kapsami disindadir.
- Bordro bu kontrol fazinin kapsami disindadir.
- Finans kalemi bu kontrol fazinin kapsami disindadir.
- SGK resmi eksik gun kodu uretimi bu kontrol fazinin kapsami disindadir.
- Dashboard metrigi bu kontrol fazinin kapsami disindadir.
- Gemini tarafindan gelecek maas kural setinin entegrasyonu bu kontrol fazinin kapsami disindadir.

## 15. Sonraki uygulamali kontrol sirasi

1. Login ve ana navigasyon kontrol edilir.
2. Personel liste ekrani acilir ve detay gecisi denenir.
3. Personel detay sekmeleri tek tek kontrol edilir.
4. Puantaj readonly ozet ve eksik gun sinyalleri incelenir.
5. Kapsami Tamamla aksiyonu yalniz uygun senaryoda denenir.
6. Gunluk Puantaj formunda Durumu Bildirdi mi? akisi kontrol edilir.
7. Surec / bildirim akisi yetkiye gore kontrol edilir.
8. Raporlar temel acilis ve bos veri davranisi kontrol edilir.
9. Mobil temel ekranlarda tasma ve ust uste binme kontrol edilir.
10. Actions ve Deploy cPanel son gorulen durumlari not edilir.
