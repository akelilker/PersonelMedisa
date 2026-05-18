# Puantaj V2 Rapor / Istirahat Kesin Politika Tablosu

Surum: `V2 minimum sistem politikasi`

## 1. Amac

Bu dokuman, rapor / istirahat hattinda firma tarafindan netlesen minimum uygulama kararlarini teknik implementasyona girmeden once sade, denetlenebilir ve politika tablosu halinde sabitlemek icin hazirlanmistir.

Bu dokuman kod fazi degildir. Buradaki kararlar hesap motoruna otomatik tasinmis sayilmaz. Netlesen minimum sistem politikasi alanlari ileride ayri teshis ve ayri implementasyon talimatiyla ele alinabilir.

Genel rapor / istirahat hatti tamamen kod fazina hazir degildir.

## 2. Bu dokumanin siniri

Bu dokuman yalnizca karar dokumani ve politika tablosudur.

- Kod yazilmaz.
- Test yazilmaz.
- `src` altinda dosya degistirilmez.
- Hesap motoru kurali eklenmez.
- Dashboard servisi degistirilmez.
- UI / hook / service degisikligi yapilmaz.
- SGK resmi kod sozlugu olusturulmaz.
- Resmi SGK kod numarasi kesinlestirilmez.
- Net bordro / maas hesabi yazilmaz.
- SGK odeme tutari hesaplanmaz.
- Analik / dogum veya is kazasi icin detayli mevzuat algoritmasi yazilmaz.
- Yonetimsel destek odemeleri otomatik bordro kuralina donusturulmez.

Bu fazda resmi SGK kod numarasi uretilmez.

## 3. Kullanilan kaynak cevaplar

Kaynak karar girdisi:

- Firma / urun tarafindan iletilen minimum rapor / istirahat uygulama kararlari
- `17-rapor-istirahat-bordro-cevap-formu.md`
- `15-rapor-istirahat-isveren-odeme-politikasi.md`
- `16-rapor-turleri-isveren-odeme-karar-matrisi.md`

Mevcut durum:

- Hastalik raporu icin minimum sistem politikasi netlesmistir.
- Raporlu personelin calisamayacagi ve rapor + calisma cakismasinin normal calisma sayilmayacagi netlesmistir.
- Saatlik rapor modeli acilmayacagi, kismi ihtiyacin yarim gun mantigiyla ele alinacagi netlesmistir.
- Resmi tatil / hafta tatili / yillik izin cakismasinda ek rapor etkisi uygulanmayacagi netlesmistir.
- 1 gun raporun devam primini tam kesecegi netlesmistir.
- Analik / dogum ve is kazasi icin resmi prosedur + bordro kontrolu yaklasimi korunmustur.
- Yonetimsel destek / yardim / maas tamamlama / ozel odeme kararlari otomatik hesap motoru disinda tutulmustur.

Varsayilan guvenli davranis: aciklayici metin + manuel/bordro kontrolu.

## 4. Mevcut kesin kararlarla iliski

Bu dokuman asagidaki kesin kararlarla uyumlu okunmalidir:

- SGK prim gunu formulu degismez: `sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))`.
- Bu formul yalniz ucret hak edilmeyen ve SGK prim gununu dusuren tam gun eksiklikler icindir.
- Rapor / istirahat icin SGK etkisi ile isveren odeme politikasi ayri degerlendirilir.
- Rapor / istirahat icin kodsuz aciklayici neden yaklasimi korunur.
- `Rapor / istirahat` SGK eksik gun nedeni adayidir.
- `eksik_gun_nedeni_kodu` alani resmi SGK kodu gibi yorumlanmaz.
- Bu fazda resmi SGK kod numarasi uretilmez.
- Rapor otomatik mazeretsiz devamsizlik gibi siniflandirilmaz.
- Belgeli rapor calisma gibi sayilmaz.
- UI hesap yapmaz.
- Hook agir mevzuat hesabi yapmaz.
- Servis / motor is kuralinin sahibidir.

Bu dokuman minimum sistem politikasini netlestirir; UI / hook / service / hesap motoru davranisini bu gorevde degistirmez.

## 5. Politika tablosu kullanim mantigi

Bu tablo uc ayri alan olusturur:

1. Minimum sistem politikasi

Sistemin otomatik ve guvenli sekilde esas alacagi minimum davranistir. Bu alanlar sadece ileride ayri teshisle implementasyona konu olabilir.

2. Yonetimsel inisiyatif / manuel bordro karari

Ozel destek, yardim, maas tamamlama, tekil odeme veya yonetim insiyatifi otomatik hesap motoruna gomulmez. Bu kararlar manuel bordro / yonetim karari olarak ayrica takip edilir.

3. Hala cevap bekleyen alanlar

Netlesmeyen prim turleri, yan haklar, yarim gun rapor prim etkisi, analik / dogum detaylari, is kazasi detaylari ve belirsiz raporlar firma / bordro karari bekler.

Genel rapor / istirahat hatti tamamen kod fazina hazir degildir. Sadece netlesen minimum politika alanlari icin `Kod fazina kismen hazir` ifadesi kullanilir.

## 6. Rapor turu bazli kesin/taslak politika tablosu

| Rapor turu | SGK prim gunu etkisi | Ucret hak edisi etkisi | Isveren odeme politikasi | Ilk gunler ozel uygulamasi | SGK odenegi mahsup / raporlama yaklasimi | Hafta tatili hakki etkisi | Hafta tatili ucreti etkisi | Devam primi etkisi | Performans primi etkisi | Yan hak etkisi | Aciklayici eksik gun nedeni | Resmi SGK kodu durumu | Karar durumu | Kod fazina hazir mi? |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Hastalik raporu | Minimum sistem politikasi: raporlu tam gun SGK eksik gun adayi olarak ele alinabilir; formulle celismeden bordro kontrolu korunur. | Minimum sistem politikasi: hastalik raporunda isveren ayrica ucret odemez. | Kesinlesti: isveren hastalik raporu icin otomatik ucret, maas tamamlama veya fark odemesi yapmaz. | Kesinlesti: ilk 1-2 gun icin isveren odeme yapmaz. 3. gun ve sonrasi SGK gecici is goremezlik odenegi surecidir. | Kesinlesti: SGK rapor parasi isciye odenir; firma bordro motoru otomatik mahsup / tamamlama hesabi yapmaz. | Minimum sistem politikasi: rapor hafta tatiline denk gelirse ek rapor etkisi uygulanmaz; tek baskin statu kullanilir. | Minimum sistem politikasi: rapor hafta tatiline denk gelirse ayrica rapor kaynakli ek ucret etkisi uretilmez. | Kesinlesti: 1 gun rapor devam primini tam keser. Yarım gun rapor prim etkisi firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Rapor / istirahat` kodsuz aciklayici neden yaklasimi korunur. | Bu fazda resmi SGK kod numarasi uretilmez. | Kesinlesti / Minimum sistem politikasi. | Kismen. Sadece netlesen minimum alanlar ayri teshisle ele alinabilir. |
| Is kazasi raporu | Resmi prosedur + bordro kontrolu. Hastalik raporundan ayri statudur. | Resmi prosedur + bordro kontrolu. | Kesinlesti: firma otomatik maas tamamlama / destek odeme zorunlulugu tanimlamaz. Ozel destek olursa manuel yonetim karari olur. | Resmi prosedur + bordro kontrolu. | Resmi prosedur + bordro kontrolu. Otomatik mahsup / tamamlama kurali tanimlanmaz. | Resmi prosedur + bordro kontrolu; tek baskin statu prensibi korunur. | Resmi prosedur + bordro kontrolu. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Rapor / istirahat` veya bordro kontrolu sonrasi kodsuz aciklayici metin. | Bu fazda resmi SGK kod numarasi uretilmez. | Resmi prosedur + bordro kontrolu / Yonetimsel manuel karar ayrimi. | Kod fazina hazir degil. Detay uygulama bordro kontrolu bekler. |
| Analik / dogum raporu | Resmi prosedur + bordro kontrolu. Detay algoritma bu fazda yazilmaz. | Resmi prosedur + bordro kontrolu. | Ek firma politikasi tanimlanmadiysa resmi prosedur + bordro kontrolu. | Resmi prosedur + bordro kontrolu. | Resmi prosedur + bordro kontrolu. | Resmi prosedur + bordro kontrolu; tek baskin statu prensibi korunur. | Resmi prosedur + bordro kontrolu. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Rapor / istirahat` veya bordro kontrolu sonrasi kodsuz aciklayici metin. | Bu fazda resmi SGK kod numarasi uretilmez. | Resmi prosedur + bordro kontrolu. | Kod fazina hazir degil. Detay uygulama bordro kontrolu bekler. |
| Refakat / diger istirahat turleri | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Tek baskin statu prensibi korunur; detay firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Rapor / istirahat` veya `Diger / bordro kontrolu gerekir`. | Bu fazda resmi SGK kod numarasi uretilmez. | Firma/bordro karari bekler. | Kod fazina hazir degil. |
| Belirsiz / bordro kontrolu gereken raporlar | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Tek baskin statu prensibi korunur; detay firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | Firma/bordro karari bekler. | `Diger / bordro kontrolu gerekir`. | Bu fazda resmi SGK kod numarasi uretilmez. | Firma/bordro karari bekler. | Kod fazina hazir degil. |

## 7. Minimum sistem politikasi

Netlesen minimum sistem politikasi:

- Hastalik raporunda isveren odeme yapmaz.
- Ilk 1-2 gun isveren odeme yapmaz.
- 3. gun ve sonrasi SGK gecici is goremezlik odenegi surecidir; firma ayrica odeme yapmaz.
- SGK rapor parasi isciye odenir; firma otomatik mahsup / tamamlama hesabi yapmaz.
- Raporlu isci calisamaz.
- Rapor + calisma kaydi cakismasi normal calisma sayilmaz; kontrol gerektiren cakismadir.
- Saatlik rapor modeli acilmaz; kismi durumlar yarim gun mantigiyla dusunulur.
- Rapor resmi tatil, hafta tatili veya yillik izinle cakisirsa ek rapor etkisi uygulanmaz.
- Ayni gun icin tek baskin statu kullanilir; cift bordro etkisi uretilmez.
- 1 gun rapor devam primini tam keser.
- Resmi SGK kod numarasi bu fazda uretilmez.
- Kodsuz aciklayici neden yaklasimi korunur.

Bu minimum politika alanlari ileride ayri teshisle implementasyona konu olabilir. Bu dokuman tek basina kod talimati degildir.

## 8. Yonetimsel manuel kararlar

Asagidaki alanlar otomatik hesap motoruna gomulmez:

- Ozel destek odemesi.
- Yardim odemesi.
- Maas tamamlama.
- SGK odenegi ustune fark odeme.
- Tekil personel veya olay bazli yonetim insiyatifi.
- Is kazasi icin firma tarafindan yapilabilecek ozel destek.
- Hastalik raporu icin istisnai destek veya yardim.

Bu alanlar olursa manuel bordro / yonetim karari olarak ayrica takip edilir. Sessiz bordro varsayimi uretilmez.

## 9. Cakisma ve statu onceligi

Netlesen cakisma politikasi:

- Raporlu isci calisamaz.
- Rapor + calisma kaydi cakismasi normal calisma sayilmaz.
- Rapor + calisma cakismasi kontrol / hata / bordro kontrolu gerektiren cakismadir.
- Rapor resmi tatil gunune denk gelirse ayrica rapor etkisi uygulanmaz.
- Rapor hafta tatiline denk gelirse ayrica rapor etkisi uygulanmaz.
- Rapor yillik izne denk gelirse ayrica rapor etkisi uygulanmaz.
- Ayni gun icin tek baskin statu kullanilir.
- Cift bordro etkisi uretilmez.

Bu fazda kod yazilmaz; bu yalniz politika notudur.

## 10. Kismi rapor yaklasimi

Netlesen kismi rapor politikasi:

- Mikro saatlik rapor modeli acilmaz.
- Saatlik rapor modeli sistemin ana omurgasina uygun gorulmemistir.
- Kismi rapor ihtiyaci olursa yarim gun mantigiyla ele alinir.
- Ornek: sabah yarim gun / ogleden sonra yarim gun.
- Yarım gun raporun devam primi ve diger primlere etkisi ayrıca netlesmemistir; firma/bordro karari bekler.

## 11. Hala cevap bekleyen kararlar

Asagidaki alanlar hala firma/bordro karari bekler:

- Yarım gun raporun devam primi etkisi.
- Performans primi etkisi.
- Uretim primi etkisi.
- Yemek yardimi etkisi.
- Yol yardimi etkisi.
- Vardiya / gece / diger ek odeme etkileri.
- Refakat / diger istirahat turlerinde ucret ve SGK etkisi.
- Belirsiz rapor turlerinde nihai aciklayici metin.
- Analik / dogum raporu detay uygulamasi.
- Is kazasi resmi prosedur detay uygulamasi.
- Resmi SGK kod sozlugu onay sureci.

Bu alanlar kesinlesmeden hesap motoruna tasinmaz.

## 12. Kod fazina hazirlik durumu

Genel rapor / istirahat hatti tamamen kod fazina hazir degildir.

Kismen netlesen minimum politika alanlari:

- Hastalik raporunda isveren odeme yapmaz.
- Ilk 1-2 gun odeme yoktur.
- 3. gun ve sonrasi SGK odenegi surecidir; firma ayrica odeme yapmaz.
- SGK rapor parasi isciye odenir; firma otomatik mahsup / tamamlama hesabi yapmaz.
- Raporlu isci calisamaz.
- Rapor + calisma cakismasi normal calisma sayilmaz.
- Saatlik rapor modeli acilmaz.
- Tek baskin statu prensibi uygulanir.
- 1 gun rapor devam primini tam keser.

Bu alanlar dahi dogrudan kodlanmaz; once ayri teshis, owner belirleme, dar kapsamli talimat ve review gerekir.

Kod fazina hazir olmayan alanlar:

- Analik / dogum detaylari.
- Is kazasi detaylari.
- Refakat / diger istirahat turleri.
- Yarım gun rapor prim etkisi.
- Performans primi, uretim primi, yan haklar ve diger ek odemeler.
- Resmi SGK kod sozlugu.
- Net bordro / maas etkisi.

## 13. Kod fazina gecis kriterleri

Kod fazina gecmek icin asagidaki kriterler saglanmalidir:

1. Netlesen minimum politika alanlari icin ayri teshis yapilmalidir.
2. Hesap motoru / dashboard / UI etkisi ayrilmalidir.
3. Owner dosya ve katman netlestirilmelidir.
4. Acik kalan firma/bordro kararlari kod kapsamindan dislanmalidir.
5. Yonetimsel manuel kararlar otomatik hesap motoruna sokulmamalidir.
6. Resmi SGK kod numarasi sozlugu ayri onay surecine birakilmalidir.
7. 13 / 14 / 15 / 16 / 17 / 18 numarali dokumanlarla uyum review'u yapilmalidir.

Bu kriterler saglanmadan hesap motoru kurali, dashboard davranisi, UI gosterimi veya net bordro etkisi uretilmemelidir.

## 14. Sonuc ve onerilen sonraki faz

Rapor / istirahat hattinda minimum sistem politikasi kismen netlesmistir. Hastalik raporunda isveren odemesi yapilmamasi, SGK rapor parasinin isciye odenmesi, otomatik mahsup / tamamlama hesabi yapilmamasi, raporlu iscinin calisamamasi, saatlik rapor modelinin acilmamasi, tek baskin statu prensibi ve 1 gun raporun devam primini tam kesmesi karar olarak islenmistir.

Yonetimsel destek, ozel odeme, yardim, maas tamamlama ve insiyatif odemeleri manuel bordro / yonetim karari olarak ayrilmistir.

Genel rapor / istirahat hatti tamamen kod fazina hazir degildir. Sadece netlesen minimum politika alanlari ileride ayri teshisle implementasyona konu olabilir.

Sonraki onerilen faz:

1. Netlesen minimum politika alanlari icin ayri implementasyon teshisi hazirlamak
2. Acik kalan prim / yan hak / yarim gun / is kazasi / analik alanlarini firma-bordro karar listesinde tutmak
3. Teshis sonrasi dar kapsamli Cursor / Codex talimati yazmak
4. Kod fazi oncesi 13 / 14 / 15 / 16 / 17 / 18 dokumanlariyla uyum review'u yapmak

## Belge Gecmisi

| Tarih | Not |
|---|---|
| 2026-05-17 | Gercek bordro cevaplari olmadigi icin taslak politika tablo sablonu eklendi. |
| 2026-05-18 | Minimum sistem politikasi ve manuel yonetim karari ayrimi islendi. |
