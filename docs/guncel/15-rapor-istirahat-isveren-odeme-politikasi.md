# Puantaj V2 Rapor / Istirahat Isveren Odeme Politikasi

Surum: `V2 karar dokumani`

## 1. Amac

Bu dokuman, Puantaj V2 kapsaminda rapor / istirahat gunlerinin SGK prim gunu, eksik gun, ucret hak edisi, isveren odeme politikasi, hafta tatili ve prim / ek odeme etkilerini kod yazmadan once urun karari seviyesinde netlestirmek icin hazirlanmistir.

Ana hedef, raporlu gunlerin tek bir teknik varsayima indirgenmesini engellemektir. Rapor / istirahat durumunda SGK etkisi, isverenin ucret odeyip odememesi, SGK gecici is goremezlik odenegi ve firma prim politikasi ayri karar basliklari olarak ele alinmalidir.

Bu dokuman uygulama kodu degildir. Kod implementasyonu oncesi muhasebe / bordro onayi gerekir.

## 2. Bu dokumanin siniri

Bu dokuman yalnizca rapor / istirahat icin karar cercevesi tanimlar.

- Kod yazilmaz.
- Test yazilmaz.
- Hook, page, servis veya hesap motoru davranisi degistirilmez.
- UI icinde hesap yapilacak gibi bir yonlendirme yapilmaz.
- SGK resmi kod numarasi uretilmez.
- `eksik_gun_nedeni_kodu` alani resmi kod gibi yorumlanmaz.
- Net bordro veya maas hesabi yazilmaz.
- UBGT odeme motoruna girilmez.
- `07-Puantaj Kayitlari` ozel motoru tasarlanmaz.
- Cok personelli SGK rapor modeli acilmaz.

Bu fazda resmi SGK kod numarasi uretilmez. Belge yalnizca urun karari, risk ayrimi ve kod fazina gecmeden once cevaplanmasi gereken sorulari tutar.

## 3. Mevcut kesin kararlarla iliski

Bu dokuman su kararlarla celismeyecek sekilde okunmalidir:

- SGK prim gunu formulu korunur: `sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))`.
- Bu formul yalnizca ucret hak edilmeyen ve SGK prim gununu dusuren tam gun eksiklikler icindir.
- Tam calisma halinde ay 28, 29, 30 veya 31 cekse de SGK prim gunu `30` kabul edilir.
- Yillik izin, resmi tatil, ucretli mazeret ve calisilmis sayilan gunler otomatik eksik gun formulune sokulmaz.
- `dayanak === undefined` eksik gun sayilmaz.
- `UBGT_Resmi_Tatil` eksik gun disidir.
- SGK eksik gun nedeni alaninda resmi kod numarasi degil, kodsuz aciklayici metin kullanilir.
- `Rapor / istirahat` SGK eksik gun nedeni adayidir; isveren odeme politikasi ayri karardir.
- UI hesap yapmaz; is kurali servis / motor owner disiplininde kalir.

`13-eksik-gun-sgk-prim-gunu-kural-matrisi.md` rapor / istirahat satirini bilincli olarak firma / urun karari bekleyen alan olarak birakmistir. Bu dokuman o acik karari detaylandirir; SGK prim gunu matrisini veya eksik gun nedeni esleme tablosunu degistirmez.

## 4. Rapor / istirahat turleri

### Hastalik raporu

Hastalik raporu, SGK gecici is goremezlik odenegi acisindan is kazasi raporundan ayridir. Mevcut hesap motoru kural dokumaninda hastalik halinde gecici is goremezlik odeneginin raporun `3. gununden` itibaren basladigi ve hak edis icin son 1 yil icinde en az `90 gun` kisa vadeli sigorta primi gerektigi belirtilmistir.

Bu bilgi urun icin karar girdisidir; bordro uygulamasi ve isverenin ilk gunler icin ucret odeyip odemeyecegi firma / bordro karari bekler.

### Is kazasi raporu

Is kazasi raporu, standart hastalik raporuyla ayni sekilde ele alinmamalidir. Mevcut hesap motoru kural dokumaninda is kazasi halinde gecici is goremezlik odeneginin her gun icin odendigi ve belirli bir prim gun sartina baglanmadigi belirtilmistir.

Bu ayrim SGK odenegi acisindan onemlidir. Ancak isverenin ayrica ucret, tamamlayici odeme, prim veya ek odeme yapip yapmayacagi bu dokumanda kesinlestirilmez; firma / bordro karari bekler.

### Analik / dogum raporu

Analik / dogum raporu varsa hastalik ve is kazasi raporundan ayri siniflandirilmalidir.

Bu fazda analik / dogum raporu icin otomatik ucret, SGK prim gunu, eksik gun nedeni veya isveren odeme kurali kesinlestirilmez. Mevzuat / bordro kontrolu sonrasi sertlestirilecektir.

### Diger istirahat turleri

Diger istirahat turleri icin firma / bordro kontrolu gerekir.

Rapor turu net degilse sistem kesin SGK, ucret veya prim etkisi uretmemelidir. Urun varsayimi olarak manuel inceleme onerilir; kesin karar degildir.

## 5. SGK prim gunu etkisi

Raporlu tam gunler SGK eksik gun adayidir. Ancak kesin prim gunu etkisi rapor tipi, rapor suresi, bordro degerlendirmesi ve isveren uygulamasiyla ayrisir.

Kesinlesen cerceve:

- Rapor / istirahat, SGK eksik gun nedeni adayi olarak izlenir.
- Rapor gunleri ucret hak edilmeyen ve SGK prim gununu dusuren tam gun eksiklik olarak siniflandirilirsa prim gunu formulu uygulanabilir.
- Isverenin raporlu gun icin ucret odemesi, tek basina SGK prim gunu etkisini otomatik belirleyen kural olarak kabul edilmez.
- Raporlu gunler yillik izin, resmi tatil veya ucretli mazeret gibi otomatik eksik gun disi siniflara sokulmaz.

Bu alanda resmi SGK kod numarasi uretilmez. Kod implementasyonu oncesi muhasebe / bordro onayi gerekir.

## 6. Ucret hak edis etkisi

Rapor / istirahat gunlerinde ucret hak edisi bu dokumanda kesinlestirilmez.

Guvenli urun ayrimi:

- Hastalik raporunda isverenin ilk gunler veya tum rapor suresi icin ucret odeyip odemeyecegi firma / bordro karari bekler.
- Is kazasi raporunda isverenin tam ucret, tamamlayici odeme veya hic ek odeme yapmama politikasi firma / bordro karari bekler.
- Analik / dogum raporunda ucret hak edisi ve isveren katkisi ayri mevzuat / bordro kontrolu gerektirir.
- Raporlu gunun ucretli sayilmasi, ucretsiz sayilmasi veya SGK odenegiyle mahsuplasilmasi netlestirilmeden net bordro etkisi uretilmemelidir.

Urun varsayimi olarak, rapor gunu kaydi ucret etkisi acisindan `manuel inceleme / bordro kontrolu gerekir` sonucuna dusmelidir. Bu kesin karar degildir; firma politikasi onayi ile sertlestirilecektir.

## 7. Isveren odeme politikasi

Isveren odeme politikasi SGK prim gunu kararindan ayri tutulur.

Kod fazina gecmeden once firma su politikalardan hangisini uygulayacagini netlestirmelidir:

- Raporlu gunlerde isveren hic ucret odemez.
- Hastalik raporunun ilk gunleri icin isveren ucret oder.
- Rapor suresince tam ucret odenir, SGK gecici is goremezlik odenegi ayrica takip edilir.
- Isveren yalniz SGK odenegi ile ucret arasindaki farki tamamlar.
- Is kazasi raporunda hastalik raporundan farkli bir odeme politikasi uygulanir.
- Analik / dogum raporunda ayri bir tamamlayici odeme politikasi uygulanir.

Bu secenekler urun varsayimi olarak listelenmistir; kesin karar degildir. Kod implementasyonu oncesi muhasebe / bordro onayi gerekir.

## 8. SGK gecici is goremezlik odenegi ile isveren odemesinin ayrimi

SGK gecici is goremezlik odenegi ile isveren odemesi ayni sey degildir.

- SGK gecici is goremezlik odenegi, SGK tarafindaki hak edis ve odeme basligidir.
- Isveren odemesi, firmanin raporlu gunlerde ucret veya tamamlayici odeme yapip yapmama politikasidir.
- SGK odenegi hak ediliyor olabilir; bu, isverenin ayrica tam ucret odeyecegi anlamina gelmez.
- Isveren tam ucret oduyor olabilir; bu, SGK eksik gun nedeni veya prim gunu etkisinin otomatik yok sayilacagi anlamina gelmez.

Bu ayrim korunmadan kod yazilirsa sistem sessiz bordro / SGK varsayimi uretir. Bu nedenle rapor / istirahat kayitlari SGK etkisi, ucret etkisi ve isveren odeme politikasi acisindan ayri alanlarda degerlendirilmelidir.

## 9. Hafta tatili etkisi

Mevcut hesap motoru kural dokumaninda hekim raporuyla belgelenmis hastalik / dinlenme izinlerinin hafta tatili hesabinda calisilmis gun gibi dikkate alinacagi belirtilmistir.

Bu nedenle raporlu gunler otomatik olarak mazeretsiz devamsizlik gibi ele alinmaz.

Kesinlesen cerceve:

- Belgeli rapor / istirahat, hafta tatili hakki acisindan mazeretsiz devamsizlikla ayni sinifa dusurulmemelidir.
- Hafta tatili hak kaybi, rapor var diye otomatik uretilmemelidir.
- Rapor kaydi ile hafta tatili hakki arasinda tarih, belge veya sure celiskisi varsa manuel inceleme gerekir.

Acik alan:

- Raporun hafta tatili gunune denk gelmesi halinde ucret / ek odeme etkisi firma / bordro karari bekler.
- Raporlu haftada prim veya donemsel ek odeme hak edisinin korunup korunmayacagi ayri karardir.

## 10. Prim / ek odeme etkisi

Rapor / istirahat gunlerinin prim, bonus, devam primi, vardiya primi veya diger ek odemelere etkisi bu dokumanda kesinlestirilmez.

Firma / bordro karari bekleyen alt basliklar:

- Raporlu gunler devam primi hesabinda kesinti nedeni sayilacak mi?
- Hastalik raporu ile is kazasi raporu prim etkisi acisindan ayrilacak mi?
- Analik / dogum raporu doneminde prim / ek odeme korunacak mi?
- Tam ay calisma primi varsa raporlu gun bunu bozacak mi?
- Performans primi fiili calisma gunune mi, bordro gunune mi, yonetici onayina mi baglanacak?
- Vardiya, gece, yemek, yol veya benzeri yan haklarda raporlu gun etkisi nasil olacak?

Urun varsayimi olarak prim / ek odeme etkisi otomatik kesinti degil, bordro kontrolu gerektiren ayri karar basligi olmalidir.

## 11. Eksik gun nedeni gosterim karari

Rapor / istirahat icin gosterim karari `14-sgk-eksik-gun-nedeni-esleme-tablosu.md` ile uyumludur.

Kesinlesen kararlar:

- Rapor / istirahat icin aciklayici neden metni `Rapor / istirahat` olarak kullanilabilir.
- Bu fazda resmi SGK kod numarasi uretilmez.
- `eksik_gun_nedeni_kodu` alan adi geriye uyumluluk nedeniyle korunabilir, ancak icerigi resmi kod numarasi gibi yorumlanmaz.
- Birden fazla neden ayni ayda birlesirse tek nedene otomatik indirgenmez; `Birden fazla neden / bordro kontrolu gerekir` yaklasimi korunur.

Gosterim, kullaniciya resmi SGK kodu verilmis izlenimi yaratmamalidir.

## 12. Kesinlesen urun kararlari

Bu dokumanla kesinlesen kararlar sunlardir:

- Rapor / istirahat tek bir teknik karar degildir; SGK, ucret, isveren odemesi, hafta tatili ve prim etkileri ayri degerlendirilir.
- SGK prim gunu formulu degismez.
- Raporlu tam gunler SGK eksik gun adayidir; kesin etki rapor tipi, sure ve bordro degerlendirmesiyle ayrisir.
- Isveren odeme politikasi SGK prim gunu kararindan ayri tutulur.
- SGK gecici is goremezlik odenegi ile isveren odemesi karistirilmaz.
- Hastalik raporu, is kazasi raporu ve analik / dogum raporu ayni alt tur gibi ele alinmaz.
- Belgeli rapor / istirahat hafta tatili hesabinda mazeretsiz devamsizlikla ayni sinifa otomatik dusurulmez.
- Eksik gun nedeni gosteriminde resmi SGK kod numarasi uretilmez.
- `Rapor / istirahat` kodsuz aciklayici neden metni olarak korunur.
- UI hesap yapmaz; hesap ve karar kurali servis / motor owner disiplininde kalir.

## 13. Firma / bordro karari bekleyen alanlar

Asagidaki alanlar kod fazina gecmeden once firma / bordro karari bekler:

- Hastalik raporunda isveren ilk gunler icin ucret odeyecek mi?
- Hastalik raporunda isveren tam ucret mi, fark odemesi mi, hic odeme mi uygulayacak?
- Is kazasi raporunda hastalik raporundan farkli odeme politikasi olacak mi?
- Analik / dogum raporu icin ucret ve tamamlayici odeme politikasi ne olacak?
- SGK gecici is goremezlik odenegi isveren odemesiyle nasil mahsuplasilacak veya raporlanacak?
- Raporlu gunler devam primi, performans primi veya diger ek odemeleri kesecek mi?
- Raporun hafta tatili gunune denk gelmesi ucret veya ek odeme etkisi uretecek mi?
- Birden fazla eksik gun nedeni ayni ayda birlesirse bordro bildirimi nasil ayrisacak?
- Resmi SGK kod numarasi sozlugu hangi mevzuat / bordro kontrolunden sonra eklenecek?

## 14. Kod fazina gecmeden once cevaplanacak soru listesi

Kod implementasyonu oncesi asagidaki sorular yanitlanmalidir:

1. Rapor turleri sistemde hangi sabit alt turlerle tutulacak?
2. Hastalik raporunda ilk iki gun icin firma odeme politikasi nedir?
3. Hastalik raporunda ucuncu gun ve sonrasi icin isveren odemesi nasil davranir?
4. Is kazasi raporunda firma tam ucret, fark odemesi veya odeme yapmama seceneklerinden hangisini uygular?
5. Analik / dogum raporu bu fazda kapsama alinacak mi, yoksa sonraki bordro fazina mi birakilacak?
6. SGK gecici is goremezlik odenegi sistemde sadece not / kontrol alani mi olacak, yoksa bordro mahsup girdisi mi olacak?
7. Raporlu gun SGK prim gununu dusurdugunde eksik gun nedeni gosterimi yalniz `Rapor / istirahat` olarak mi kalacak?
8. Raporlu gunlerde devam primi ve diger ek odemeler korunacak mi?
9. Raporun hafta tatili gunune denk gelmesi halinde ek bir karar gerekecek mi?
10. Bir ayda rapor, ucretsiz izin ve devamsizlik birlikte varsa gosterim ve bordro kontrolu nasil yapilacak?
11. Rapor belgesi eksik, tarih araligi hatali veya rapor turu belirsizse sistem hangi manuel inceleme mesajini gosterecek?
12. Firma politikalari sistem geneli mi, sube / personel grubu / sozlesme bazli parametre mi olacak?

## 15. Sonuc ve onerilen sonraki faz

Rapor / istirahat icin ana urun karari, SGK etkisi ile isveren odeme politikasinin ayrilmasidir. Raporlu gunler SGK eksik gun adayi olabilir; ancak isverenin ucret veya tamamlayici odeme yapip yapmayacagi ayri firma / bordro karari olarak kalir.

Bu dokuman kod fazi degildir. Mevzuat / bordro kontrolu sonrasi sertlestirilecek alanlar netlestirilmeden hesap motoruna, UI'a veya dashboard servislerine yeni kural eklenmemelidir.

Onerilen sonraki faz:

1. Firma / bordro politikasi cevaplarini toplama
2. Rapor turleri ve odeme politikasi icin dar karar matrisi cikarma
3. Ardindan sadece owner hesap motoru / servis hattini hedefleyen Cursor veya Codex implementasyon talimati hazirlama
4. Implementasyon sonrasi 12 / 13 / 14 numarali dokumanlarla uyum review'u yapma

## Belge Gecmisi

| Tarih | Not |
|---|---|
| 2026-05-17 | Rapor / istirahat icin isveren odeme politikasi karar cercevesi eklendi. |
