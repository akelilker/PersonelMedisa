# Puantaj V2 Rapor Turleri ve Isveren Odeme Politikasi Dar Karar Matrisi

Surum: `V2 dar karar matrisi`

## 1. Amac

Bu dokuman, `15-rapor-istirahat-isveren-odeme-politikasi.md` dosyasinda acik birakilan firma / bordro kararlarini muhasebe ve bordro tarafina sorulabilecek net bir karar matrisine cevirmek icin hazirlanmistir.

Amac, rapor / istirahat kayitlari icin hesap motoruna kural eklemeden once hangi rapor turunde hangi bordro politikasinin uygulanacagini yazili hale getirmektir.

Bu dokuman implementasyon dokumani degildir. Kod implementasyonu oncesi onay gerekir.

## 2. Bu dokumanin siniri

Bu dokuman yalnizca karar toplama ve dar karar matrisi olusturma amaciyla kullanilir.

- Kod yazilmaz.
- Test yazilmaz.
- `src` altinda dosya degistirilmez.
- SGK resmi kod sozlugu olusturulmaz.
- Rapor turlerinden resmi SGK kodu turetilmez.
- Hesap motoru kurali eklenmez.
- Dashboard servisi degistirilmez.
- UI label veya render degisikligi onerilmez.
- Net maas veya bordro matematigi yazilmaz.
- Yeni helper, service veya teknik katman onerilmez.

Bu fazda resmi SGK kod numarasi uretilmez. Kesinlesmeden hesap motoruna tasinmaz.

## 3. Mevcut kesin kararlarla iliski

Bu dokuman asagidaki mevcut kararlarla uyumlu okunmalidir:

- SGK prim gunu formulu degismez: `sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))`.
- Bu formul yalnizca ucret hak edilmeyen ve SGK prim gununu dusuren tam gun eksiklikler icin gecerlidir.
- Rapor / istirahat tekil motor kurali degildir; SGK etkisi, ucret etkisi, isveren odemesi ve prim / ek odeme etkisi ayridir.
- `Rapor / istirahat` SGK eksik gun nedeni adayidir.
- `eksik_gun_nedeni_kodu` alani resmi SGK kodu gibi yorumlanmaz.
- Eksik gun nedeni gosteriminde kodsuz aciklayici metin yaklasimi korunur.
- Belgeli rapor otomatik mazeretsiz devamsizlik gibi siniflandirilmaz.
- Belgeli hastalik / dinlenme izinleri hafta tatili hesabi acisindan calisilmis gun gibi dikkate alinabilir; bu alan tarih, belge ve sure celiskisinde manuel inceleme gerektirir.
- UI hesap yapmaz; bu dokuman UI, hook veya service onerisi uretmez.

Bu dokuman `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md`, `14-sgk-eksik-gun-nedeni-esleme-tablosu.md` ve `15-rapor-istirahat-isveren-odeme-politikasi.md` dosyalarindaki karar ayrimini daraltmak icindir; onlarin yerine gecmez.

## 4. Karar matrisi nasil kullanilacak?

Bu matris, muhasebe / bordro tarafina sorulacak cevap formu gibi kullanilmalidir.

Her rapor turu icin su alanlar ayrica cevaplanmalidir:

1. SGK prim gunu duser mi?
2. Ucret hak edisi duser mi?
3. Isveren odeme yapar mi?
4. Ilk gunler icin ozel uygulama var mi?
5. SGK odenegi mahsup edilir mi?
6. Hafta tatili hakkini etkiler mi?
7. Hafta tatili ucretini etkiler mi?
8. Devam primi / performans primi / yan haklari etkiler mi?
9. Aciklayici eksik gun nedeni ne olur?
10. Resmi SGK kodu bu fazda kesin mi, degil mi?

Karar verilmemis alanlar hesap motoruna tasinmaz. Varsayilan guvenli davranis: aciklayici metin + manuel/bordro kontrolu.

## 5. Rapor turleri karar tablosu

| Rapor turu | SGK prim gunu etkisi | Ucret hak edisi | Isveren odemesi | Ilk gunler ozel uygulama | SGK odenegi mahsup | Hafta tatili hakki | Hafta tatili ucreti | Prim / yan hak etkisi | Aciklayici eksik gun nedeni | Resmi SGK kodu | Karar durumu |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Hastalik raporu | Firma/bordro karari bekler; raporlu tam gunler SGK eksik gun adayidir. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler; ilk gunler icin ozel uygulama olup olmadigi sorulmalidir. | Firma/bordro karari bekler. | Belgeli rapor otomatik mazeretsiz devamsizlik sayilmaz. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Rapor / istirahat` | Bu fazda resmi SGK kod numarasi uretilmez. | Acik |
| Is kazasi raporu | Firma/bordro karari bekler; hastalik raporundan ayri ele alinmalidir. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler; hastalik raporundan farkli olabilir. | Firma/bordro karari bekler. | Belgeli rapor otomatik mazeretsiz devamsizlik sayilmaz. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Rapor / istirahat` | Bu fazda resmi SGK kod numarasi uretilmez. | Acik |
| Analik / dogum raporu | Firma/bordro karari ve mevzuat kontrolu bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari ve mevzuat kontrolu bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Rapor / istirahat` veya bordro kontrolu sonrasi ayri aciklayici metin | Bu fazda resmi SGK kod numarasi uretilmez. | Acik |
| Refakat / diger istirahat turleri | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Rapor / istirahat` veya `Diger / bordro kontrolu gerekir` | Bu fazda resmi SGK kod numarasi uretilmez. | Acik |
| Belirsiz / bordro kontrolu gereken raporlar | Kesinlestirilmez. | Kesinlestirilmez. | Kesinlestirilmez. | Kesinlestirilmez. | Kesinlestirilmez. | Kesinlestirilmez. | Kesinlestirilmez. | Kesinlestirilmez. | `Diger / bordro kontrolu gerekir` | Bu fazda resmi SGK kod numarasi uretilmez. | Manuel / bordro kontrolu |

## 6. Her rapor turu icin sorulacak alanlar

Asagidaki alanlar her rapor turu icin tek tek cevaplanmalidir. Cevaplar kesinlesmeden hesap motoruna tasinmaz.

### Hastalik raporu

- SGK prim gunu duser mi? Firma/bordro karari bekler; raporlu tam gunler SGK eksik gun adayidir.
- Ucret hak edisi duser mi? Firma/bordro karari bekler.
- Isveren odeme yapar mi? Firma/bordro karari bekler.
- Ilk gunler icin ozel uygulama var mi? Kod implementasyonu oncesi onay gerekir.
- SGK odenegi mahsup edilir mi? Firma/bordro karari bekler.
- Hafta tatili hakkini etkiler mi? Belgeli rapor otomatik mazeretsiz devamsizlik sayilmaz.
- Hafta tatili ucretini etkiler mi? Firma/bordro karari bekler.
- Devam primi / performans primi / yan haklari etkiler mi? Firma/bordro karari bekler.
- Aciklayici eksik gun nedeni ne olur? Varsayilan guvenli davranis: `Rapor / istirahat`.
- Resmi SGK kodu bu fazda kesin mi? Hayir. Bu fazda resmi SGK kod numarasi uretilmez.

### Is kazasi raporu

- SGK prim gunu duser mi? Firma/bordro karari bekler; hastalik raporundan ayri degerlendirilmelidir.
- Ucret hak edisi duser mi? Firma/bordro karari bekler.
- Isveren odeme yapar mi? Firma/bordro karari bekler.
- Ilk gunler icin ozel uygulama var mi? Firma/bordro karari bekler.
- SGK odenegi mahsup edilir mi? Firma/bordro karari bekler.
- Hafta tatili hakkini etkiler mi? Belgeli rapor otomatik mazeretsiz devamsizlik sayilmaz.
- Hafta tatili ucretini etkiler mi? Firma/bordro karari bekler.
- Devam primi / performans primi / yan haklari etkiler mi? Firma/bordro karari bekler.
- Aciklayici eksik gun nedeni ne olur? Varsayilan guvenli davranis: `Rapor / istirahat`.
- Resmi SGK kodu bu fazda kesin mi? Hayir. Bu fazda resmi SGK kod numarasi uretilmez.

### Analik / dogum raporu

- SGK prim gunu duser mi? Firma/bordro karari ve mevzuat kontrolu bekler.
- Ucret hak edisi duser mi? Firma/bordro karari bekler.
- Isveren odeme yapar mi? Firma/bordro karari bekler.
- Ilk gunler icin ozel uygulama var mi? Firma/bordro karari bekler.
- SGK odenegi mahsup edilir mi? Firma/bordro karari bekler.
- Hafta tatili hakkini etkiler mi? Firma/bordro karari ve mevzuat kontrolu bekler.
- Hafta tatili ucretini etkiler mi? Firma/bordro karari bekler.
- Devam primi / performans primi / yan haklari etkiler mi? Firma/bordro karari bekler.
- Aciklayici eksik gun nedeni ne olur? Varsayilan guvenli davranis: `Rapor / istirahat`; ayri metin icin bordro kontrolu gerekir.
- Resmi SGK kodu bu fazda kesin mi? Hayir. Bu fazda resmi SGK kod numarasi uretilmez.

### Refakat / diger istirahat turleri

- SGK prim gunu duser mi? Firma/bordro karari bekler.
- Ucret hak edisi duser mi? Firma/bordro karari bekler.
- Isveren odeme yapar mi? Firma/bordro karari bekler.
- Ilk gunler icin ozel uygulama var mi? Firma/bordro karari bekler.
- SGK odenegi mahsup edilir mi? Firma/bordro karari bekler.
- Hafta tatili hakkini etkiler mi? Firma/bordro karari bekler.
- Hafta tatili ucretini etkiler mi? Firma/bordro karari bekler.
- Devam primi / performans primi / yan haklari etkiler mi? Firma/bordro karari bekler.
- Aciklayici eksik gun nedeni ne olur? Varsayilan guvenli davranis: `Rapor / istirahat` veya `Diger / bordro kontrolu gerekir`.
- Resmi SGK kodu bu fazda kesin mi? Hayir. Bu fazda resmi SGK kod numarasi uretilmez.

### Belirsiz / bordro kontrolu gereken raporlar

- SGK prim gunu duser mi? Kesinlestirilmez.
- Ucret hak edisi duser mi? Kesinlestirilmez.
- Isveren odeme yapar mi? Kesinlestirilmez.
- Ilk gunler icin ozel uygulama var mi? Kesinlestirilmez.
- SGK odenegi mahsup edilir mi? Kesinlestirilmez.
- Hafta tatili hakkini etkiler mi? Kesinlestirilmez.
- Hafta tatili ucretini etkiler mi? Kesinlestirilmez.
- Devam primi / performans primi / yan haklari etkiler mi? Kesinlestirilmez.
- Aciklayici eksik gun nedeni ne olur? Varsayilan guvenli davranis: `Diger / bordro kontrolu gerekir`.
- Resmi SGK kodu bu fazda kesin mi? Hayir. Bu fazda resmi SGK kod numarasi uretilmez.

## 7. Firma/bordro tarafina sorulacak net soru listesi

1. Firma hastalik raporunda ilk iki gun icin ucret oduyor mu?
2. Hastalik raporunda ucuncu gun ve sonrasi icin isveren hic odemez mi, tam ucret mi oder, fark odemesi mi yapar?
3. Hastalik raporunda SGK gecici is goremezlik odenegi bordroda mahsup edilir mi, yalniz bilgilendirme olarak mi tutulur?
4. Is kazasi raporu hastalik raporundan farkli odeme politikasina tabi mi?
5. Is kazasi raporunda isveren tam ucret, fark odemesi veya hic odeme yapmama seceneklerinden hangisini uygular?
6. Analik / dogum raporu bu fazda rapor turleri matrisine dahil edilecek mi?
7. Analik / dogum raporunda isveren tamamlayici odeme yapar mi?
8. Refakat veya diger istirahat turlerinde firma hangi ucret politikasini uygular?
9. Raporlu gunler SGK prim gununu hangi kosullarda dusurur?
10. Raporlu gun ucretli kabul edilirse SGK prim gunu ve eksik gun nedeni nasil ele alinacak?
11. Raporlu gun ucretsiz kabul edilirse eksik gun nedeni gosterimi `Rapor / istirahat` olarak kalacak mi?
12. Hafta tatili gunune denk gelen rapor ucret etkisi yaratir mi?
13. Raporlu hafta, hafta tatili hakkini hangi durumlarda etkiler?
14. Devam primi raporlu gunlerde kesilir mi?
15. Performans primi raporlu gunlerden etkilenir mi?
16. Yemek, yol, vardiya, gece veya benzeri yan haklarda raporlu gun etkisi nasil olacak?
17. Bir ayda rapor, ucretsiz izin ve devamsizlik birlikte varsa bordro bildirimi nasil ayrisacak?
18. Belirsiz rapor turlerinde varsayilan manuel / bordro kontrolu mesaji ne olacak?
19. Resmi SGK kod numarasi sozlugu hangi kaynak, onay ve kontrol sureciyle sisteme alinacak?
20. Bu kararlar sirket geneli mi, sube / personel grubu / sozlesme bazli mi uygulanacak?

## 8. Cevaplanana kadar sistemin guvenli davranisi

Cevaplar kesinlesene kadar varsayilan guvenli davranis sudur:

- Rapor / istirahat icin kesin ucret veya net bordro etkisi uretilmez.
- Resmi SGK kod numarasi uretilmez.
- Aciklayici neden metni kullanilir.
- Rapor turu netse `Rapor / istirahat` metni korunur.
- Rapor turu belirsizse `Diger / bordro kontrolu gerekir` veya benzer kodsuz aciklayici metin kullanilir.
- SGK prim gunu etkisi, ucret hak edisi ve isveren odemesi ayri kararlar olarak tutulur.
- Belgeli rapor otomatik mazeretsiz devamsizlik gibi siniflandirilmaz.
- Prim / ek odeme ve yan haklar otomatik kesilmez.
- Varsayilan guvenli davranis: aciklayici metin + manuel/bordro kontrolu.

Kesinlesmeden hesap motoruna tasinmaz.

## 9. Kod fazina gecis kriterleri

Kod fazina gecmek icin asagidaki cevaplar yazili olarak netlesmelidir:

- Her rapor turu icin SGK prim gunu etkisi.
- Her rapor turu icin ucret hak edisi etkisi.
- Isverenin odeme yapip yapmayacagi.
- Hastalik raporunda ilk gunler icin ozel uygulama olup olmadigi.
- Is kazasi raporunun hastalik raporundan farkli uygulanip uygulanmayacagi.
- Analik / dogum raporunun bu faza dahil edilip edilmeyecegi.
- SGK gecici is goremezlik odeneginin bordroda mahsup edilip edilmeyecegi.
- Hafta tatili hakki ve hafta tatili ucreti etkisi.
- Devam primi, performans primi ve yan hak etkisi.
- Kodsuz aciklayici eksik gun nedeni metinleri.
- Belirsiz raporlar icin manuel / bordro kontrolu mesaji.
- Resmi SGK kod sozlugu icin ayri onay sureci.

Bu cevaplar alinmadan hesap motoru kurali, dashboard davranisi veya UI gosterimi degistirilmemelidir.

## 10. Sonuc ve onerilen sonraki faz

Bu dokuman, rapor / istirahat alanindaki teknik ayrimi firma / bordro tarafina sorulacak dar karar matrisine cevirmistir. Henuz bordro politikasi kesinlesmedigi icin ucret, isveren odemesi, SGK odenegi mahsup yaklasimi, hafta tatili ucreti ve prim / yan hak etkileri acik alan olarak kalir.

Sonraki onerilen faz:

1. Bu matrisin muhasebe / bordro tarafindan cevaplanmasi
2. Cevaplara gore kesinlesmis rapor turu politika tablosu hazirlanmasi
3. Sonrasinda dar kapsamli kod fazi talimatinin yazilmasi
4. Kod fazi oncesi `13`, `14` ve `15` numarali dokumanlarla uyum review'u yapilmasi

## Belge Gecmisi

| Tarih | Not |
|---|---|
| 2026-05-17 | Rapor turleri ve isveren odeme politikasi icin dar karar matrisi eklendi. |
