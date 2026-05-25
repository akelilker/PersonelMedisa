# PersonelMedisa Canli Kullanim Mini Checklist - Talimat ve Gelistirici Raporu

Tarih: 2026-05-25
Durum: Puantaj V2 kontrollu hydrate fazi kapandi. Canli kullanim oncesi mini kontrol fazina gecilecek.

## 1. Guncel Durum

Son tamamlanan ana faz:

Puantaj V2 Eksik Gun / SGK Prim Gunu etkisi icin dar karar cekirdegi, aylik ozet, readonly yuzey, veri kapsami sinyali ve kontrollu hydrate akisi tamamlandi.

Son dogrulanan commitler:

- 17faa68 Add puantaj eksik gun classification core
- 07e660d Add puantaj eksik gun monthly summary core
- 7414634 Add puantaj eksik gun readonly summary
- 4dc50e1 Create 24-puantaj-v2-eksik-gun-readonly-summary-kapanis.md
- 3346fb9 Add puantaj eksik gun data scope design
- 2b6592b Expose puantaj eksik gun data scope
- a7af095 Fix personel detail tab panel visibility
- 65c762d Create 26-gorsel-saglik-kontrolu-checkpoint.md
- b6fb7e3 Add controlled puantaj eksik gun hydrate
- 5f1156b Connect puantaj eksik gun hydrate action
- 453a5b2 Add puantaj controlled hydrate checkpoint

CI ve Deploy cPanel son görülen durumda yeşil göründü.

## 2. Yapilan Islerin Ozeti

### 2.1 Eksik Gun Karar Cekirdegi

Owner dosya:

- src/services/puantaj-hesap-motoru.ts

Eklenen mantik:

- Gunluk puantaj satirini eksik gun / SGK prim gunu etkisi acisindan siniflandiran saf servis fonksiyonu eklendi.
- Gec_Geldi ve Erken_Cikti, SGK eksik gun mantigina sokulmadi.
- Raporlu_Hastalik ve Raporlu_Is_Kazasi otomatik SGK dusumu uretmedi; manuel/politika inceleme sinyali olarak tutuldu.
- durumu_bildirdi_mi tek basina ucretli/ucretsiz veya SGK karari uretmedi.

### 2.2 Aylik Ozet Cekirdegi

Owner dosya:

- src/services/puantaj-hesap-motoru.ts

Eklenen mantik:

- Aylik eksik gun ozetini hesaplayan servis cekirdegi olusturuldu.
- Hesap sorumlulugu UI veya hook katmanina tasinmadi.
- SGK resmi kodu, bordro, net maas ve finans kalemi bilincli olarak kapsam disinda tutuldu.

### 2.3 Readonly Hook ve Yuzey

Owner dosyalar:

- src/hooks/usePuantajEksikGunOzeti.ts
- src/features/personeller/pages/PersonelDetayPage.tsx

Eklenen alanlar:

- eksikTarihSayisi
- eksikTarihListesi
- veriKapsamiTamMi

Davranis:

- Personel Detay sayfasinda Aylik Puantaj Ozeti readonly olarak gosterildi.
- UI hesap yapmadi; sadece hook'tan gelen sonucu render etti.

### 2.4 Kontrollu Hydrate

Owner dosya:

- src/hooks/usePuantajEksikGunOzeti.ts

Eklenen hook alanlari:

- hydrateEksikPuantajTarihleri
- hydrateDurumu
- hydrateEdilenTarihSayisi
- hydrateHataMesaji
- hydrateMumkunMu

Kararlar:

- Otomatik fetch yapilmadi.
- Hydrate yalniz explicit aksiyonla calisacak sekilde tasarlandi.
- Tek cagri en fazla 7 eksik tarihi dolduracak sekilde sinirlandi.
- Null kayit cache'e yazilip veri kapsami acisindan sorulmus kabul edildi.
- Null kayit hesap motoruna gercek puantaj kaydi gibi verilmedi.
- Sube/personel izolasyonu cache key ve testlerle korundu.

### 2.5 UI Aksiyonu

Owner dosya:

- src/features/personeller/pages/PersonelDetayPage.tsx

Eklenen davranis:

- Aylik Puantaj Ozeti altina Kapsami Tamamla readonly aksiyonu eklendi.
- Aksiyon sadece hydrateMumkunMu true ve veriKapsamiTamMi false iken gorunur.
- Loading/success/error durum metni eklendi.
- UI yine hesap yapmaz; sadece hook fonksiyonunu tetikler.

### 2.6 Gorsel Saglik Kontrolu

Yapilan gozlemler:

- Login ekrani temel olarak uygun gorundu.
- Ana ekran omurgasi uygun gorundu.
- Personel liste/detay akisi uygun gorundu.
- Gunluk Puantaj formu desktop/mobile temel olarak uygun gorundu.
- Durumu Bildirdi mi? alani ve Evet secilince aciklama input'u temel olarak uygun gorundu.

Bulunan ve kapatilan hata:

- Personel Detay sayfasinda Izin / Devamsizlik paneli Puantaj sekmesi altinda da gorunuyordu.
- Panel visibility duzeltildi.
- Personel dosya E2E testi 7 passed olarak dogrulandi.

## 3. Bilincli Kapsam Disilar

Bu fazlarda asagidakiler bilincli olarak yapilmadi:

- Maas hesaplama
- Net maas
- Bordro
- Finans kalemi
- SGK resmi eksik gun kodu uretimi
- Dashboard metrigi
- Yeni SGK resmi kod sozlugu
- Otomatik tum ay fetch
- UI icinde hesap

Bu alanlar daha sonra ayri karar matrisi ve ayri owner fazi ile ele alinacak.

## 4. Mevcut Calisma Prensibi

Devam edecek calisma modeli:

1. Once teshis
2. Sonra owner dosya/layer tespiti
3. Sonra dar tasarim
4. Sonra minimum diff
5. Sonra targeted test
6. Sonra full regression
7. Sonra checkpoint dokumani

Kritik kural:

UI hesap yapmaz. Hook veri tasir/toplar. Hesap motoru karar verir.

## 5. Siradaki Faz

Siradaki faz:

Canli Kullanim Mini Checklist

Amac:

Kod yazmadan once uygulamanin IK kullanicisi gozuyle hangi ekranlardan, hangi sira ile, hangi risk basliklariyla kontrol edilecegini netlestirmek.

Bu faz bir dokuman fazidir.

## 6. Cursor Icin Tek Seferlik Talimat

Asagidaki talimati tek is olarak uygula.

### Gorev

PersonelMedisa icin canli kullanim oncesi mini kontrol checklist'i hazirla.

Yeni dosya olustur:

docs/guncel/29-canli-kullanim-mini-checklist.md

### Dosya Icerigi

Markdown dokuman su basliklari icermeli:

1. Mevcut durum
2. Kontrol edilecek ana ekranlar
3. Login ve yetki kontrol listesi
4. Ana sayfa / navigasyon kontrol listesi
5. Personel liste kontrol listesi
6. Personel Detay kontrol listesi
7. Puantaj / Eksik Gun kontrol listesi
8. Kontrollu hydrate kontrol listesi
9. Gunluk Puantaj kontrol listesi
10. Surec / bildirim kontrol listesi
11. Raporlar kontrol listesi
12. Mobil gorunum kontrol listesi
13. Deploy / GitHub Actions kontrol listesi
14. Bilincli kapsam disilar
15. Sonraki uygulamali kontrol sirasi

### Checklist Detaylari

Her baslik altinda kisa, uygulanabilir maddeler olsun.

Ozellikle su kontroller yer alsin:

- Kullanici login olabiliyor mu?
- Yetkisiz/korumali rota davranisi bozulmamis mi?
- Personel listesi aciliyor mu?
- Personel detay sayfasi aciliyor mu?
- Sekmeler dogru paneli gosteriyor mu?
- Puantaj sekmesinde Izin / Devamsizlik paneli tekrar gorunmuyor mu?
- Aylik Puantaj Ozeti gorunuyor mu?
- Veri kapsami eksikse Kapsami Tamamla aksiyonu mantikli gorunuyor mu?
- Kapsami Tamamla butonu otomatik fetch yerine kullanici aksiyonuyla calisacak sekilde tariflenmis mi?
- Gunluk Puantaj formunda Durumu Bildirdi mi? sorusu gorunuyor mu?
- Evet secilince aciklama input'u geliyor mu?
- Hayir secilince gereksiz aciklama zorunlulugu dogmuyor mu?
- Gec_Geldi / Erken_Cikti SGK eksik gun gibi sunulmuyor mu?
- Raporlu_Hastalik / Raporlu_Is_Kazasi otomatik SGK dusumu gibi sunulmuyor mu?
- Mobilde temel ekranlarda tasma/ust uste binme var mi?
- Actions ve Deploy cPanel yesil mi?

### Kapsam Disi Yaz

Dokumanda asagidakilerin bu kontrol fazinin kapsami disinda oldugunu net yaz:

- Maas hesaplama
- Net maas
- Bordro
- Finans kalemi
- SGK resmi eksik gun kodu uretimi
- Dashboard metrigi
- Gemini tarafindan gelecek maas kural setinin entegrasyonu

### Sert Sinirlar

Kod degistirme.
Mevcut dosyalara dokunma.
Test degistirme.
UI degistirme.
API degistirme.
Hook degistirme.
Service degistirme.
Sadece docs/guncel/29-canli-kullanim-mini-checklist.md dosyasini olustur.

### Son Cikti

Is bitince sadece sunlari raporla:

git status --short --branch
git diff --stat
Olusturulan dosya yolu
Degisiklik yaptin mi?

Commit atma.
Push yapma.

## 7. Cursor Ciktisi Gelince Review Sirasi

Cursor dokumani olusturduktan sonra su sira izlenecek:

1. Sadece yeni dokuman diff'i incelenecek.
2. Kapsam disi maddeler dogru mu kontrol edilecek.
3. Kod dosyasi degismis mi kontrol edilecek.
4. Uygunsa commit/push asamasina gecilecek.
5. GitHub Actions ve Deploy cPanel yesil gorulunce bu checkpoint kapanacak.

## 8. Bu Fazdan Sonraki Muhtemel Faz

Checklist kapandiktan sonra uygulamali kontrol sirasi:

1. Login ve ana navigasyon
2. Personel liste
3. Personel detay sekmeleri
4. Puantaj readonly ozet
5. Kapsami Tamamla aksiyonu
6. Gunluk Puantaj formu
7. Surec / bildirim akisi
8. Raporlar
9. Mobil temel kontrol

Her bozukluk icin ayni kural gecerlidir:

Once teshis, sonra owner dosya, sonra dar onay, sonra minimum diff.
