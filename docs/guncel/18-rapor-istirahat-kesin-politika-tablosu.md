# Puantaj V2 Rapor / Istirahat Kesin Politika Tablosu

Surum: `V2 taslak politika tablo sablonu`

## 1. Amac

Bu dokuman, `17-rapor-istirahat-bordro-cevap-formu.md` muhasebe / bordro / firma tarafindan cevaplandiktan sonra, bu cevaplari teknik implementasyona girmeden once sade, denetlenebilir ve kesinlesmis politika tablosuna donusturmek icin hazirlanmistir.

Bu dokumanin mevcut hali kesin politika tablosu degildir. 17 numarali formda gercek firma / bordro cevaplari bulunmadigi icin bu dosya taslak politika tablo sablonu olarak olusturulmustur.

Gercek cevap yokken kesin karar yazilmaz. Kesinlesmeden hesap motoruna tasinmaz.

## 2. Bu dokumanin siniri

Bu dokuman yalnizca rapor / istirahat bordro cevaplarini politika tablosuna donusturme sablonudur.

- Kod yazilmaz.
- Test yazilmaz.
- `src` altinda dosya degistirilmez.
- Hesap motoru kurali eklenmez.
- Dashboard servisi degistirilmez.
- UI / hook / service onerisi eklenmez.
- SGK resmi kod sozlugu olusturulmaz.
- Resmi SGK kod numarasi kesinlestirilmez.
- Net bordro / maas hesabi yazilmaz.
- Mevzuat kesin hukmu verilmez.
- Firma cevabi yokken kesin politika uretilmez.

Bu fazda resmi SGK kod numarasi uretilmez.

## 3. Kullanilan kaynak cevaplar

Kaynak cevap formu:

- `17-rapor-istirahat-bordro-cevap-formu.md`

Mevcut durum:

- 17 numarali form henuz gercek muhasebe / bordro / firma cevaplariyla doldurulmamistir.
- Bu nedenle bu dokumanda kesin rapor politikasi uretilmemistir.
- Tum rapor turu bazli politika alanlari `Cevap bekliyor`, `Firma/bordro karari bekler` ve `Kod fazina hazir degil` olarak isaretlenmistir.

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
- Belgeli rapor otomatik mazeretsiz devamsizlik gibi siniflandirilmaz.
- Belgeli rapor otomatik ucretli veya ucretsiz diye kesinlestirilmez.
- UI hesap yapmaz.
- Hook agir mevzuat hesabi yapmaz.
- Servis / motor is kuralinin sahibidir.

Bu dokuman `13`, `14`, `15`, `16` ve `17` numarali dokumanlarin uzerine kesinlesmis cevap gelmeden yeni bordro kuralı eklemez.

## 5. Politika tablosu kullanim mantigi

Bu tablo, 17 numarali form cevaplandiktan sonra su amaclarla kullanilir:

1. Hangi rapor turunde hangi karar kesinlesti?
2. Hangi alan hala bordro kontrolu gerektiriyor?
3. Hangi alan kod fazina hazir?
4. Hangi alan kapsam disi veya sonraki faz?
5. Hangi kararlar hesap motoruna tasinabilir?

Mevcut taslak durumda hicbir rapor turu kod fazina hazir degildir. Cevap bekliyor.

Bir alan ancak su kosullarda `Kod fazina hazir` kabul edilebilir:

- 17 numarali formda cevap vardir.
- Karar sahibi bellidir.
- Karar `Evet / Hayir / net politika` seviyesinde yazilidir.
- `Duruma gore` cevabinin kosullari aciktir.
- `Bordro kontrolu gerekir` cevabi varsa alan kod fazina hazir sayilmaz.
- Karar 13 / 14 / 15 / 16 numarali dokumanlarla celismez.

## 6. Rapor turu bazli kesin/taslak politika tablosu

Mevcut durumda bu tablo taslaktir. Gercek bordro cevaplari olmadigi icin tum rapor turleri cevap bekliyor olarak isaretlenmistir.

| Rapor turu | SGK prim gunu etkisi | Ucret hak edisi etkisi | Isveren odeme politikasi | Ilk gunler ozel uygulamasi | SGK odenegi mahsup / raporlama yaklasimi | Hafta tatili hakki etkisi | Hafta tatili ucreti etkisi | Devam primi etkisi | Performans primi etkisi | Yan hak etkisi | Aciklayici eksik gun nedeni | Resmi SGK kodu durumu | Karar durumu | Kod fazina hazir mi? |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Hastalik raporu | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Ilk gunler icin politika net degil. | Cevap bekliyor. Mahsup / raporlama yaklasimi net degil. | Belgeli rapor otomatik mazeretsiz devamsizlik sayilmaz; bordro cevabi bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Taslak aciklayici metin: `Rapor / istirahat`; kesinlesme bekler. | Bu fazda resmi SGK kod numarasi uretilmez. | Cevap bekliyor. | Hayir. Kod fazina hazir degil. |
| Is kazasi raporu | Cevap bekliyor. Hastalik raporundan ayri karar gerekir. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Hastalik raporundan farkli olabilir. | Cevap bekliyor. Mahsup / raporlama yaklasimi net degil. | Belgeli rapor otomatik mazeretsiz devamsizlik sayilmaz; bordro cevabi bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Taslak aciklayici metin: `Rapor / istirahat`; kesinlesme bekler. | Bu fazda resmi SGK kod numarasi uretilmez. | Cevap bekliyor. | Hayir. Kod fazina hazir degil. |
| Analik / dogum raporu | Cevap bekliyor. Firma/bordro karari ve mevzuat kontrolu bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Mahsup / raporlama yaklasimi net degil. | Cevap bekliyor. Firma/bordro karari ve mevzuat kontrolu bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Taslak aciklayici metin: `Rapor / istirahat`; ayri metin icin bordro cevabi bekler. | Bu fazda resmi SGK kod numarasi uretilmez. | Cevap bekliyor. | Hayir. Kod fazina hazir degil. |
| Refakat / diger istirahat turleri | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Mahsup / raporlama yaklasimi net degil. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Cevap bekliyor. Firma/bordro karari bekler. | Taslak aciklayici metin: `Rapor / istirahat` veya `Diger / bordro kontrolu gerekir`; kesinlesme bekler. | Bu fazda resmi SGK kod numarasi uretilmez. | Cevap bekliyor. | Hayir. Kod fazina hazir degil. |
| Belirsiz / bordro kontrolu gereken raporlar | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Kesinlestirilmez. Bordro kontrolu gerekir. | Taslak aciklayici metin: `Diger / bordro kontrolu gerekir`; kesinlesme bekler. | Bu fazda resmi SGK kod numarasi uretilmez. | Bordro kontrolu gerekir. | Hayir. Kod fazina hazir degil. |

## 7. Kolon aciklamalari

Tablodaki kolonlar 16 numarali karar matrisi ve 17 numarali cevap formu ile uyumludur.

- `Rapor turu`: Politikanin uygulanacagi rapor sinifi.
- `SGK prim gunu etkisi`: Raporun SGK prim gununu dusurup dusurmedigi.
- `Ucret hak edisi etkisi`: Raporlu gun icin ucret hak edilip edilmedigi.
- `Isveren odeme politikasi`: Isverenin tam ucret, fark odemesi veya odeme yapmama yaklasimi.
- `Ilk gunler ozel uygulamasi`: Ozellikle hastalik raporunda ilk gunler icin farkli uygulama olup olmadigi.
- `SGK odenegi mahsup / raporlama yaklasimi`: SGK gecici is goremezlik odeneginin bordroda nasil ele alinacagi.
- `Hafta tatili hakki etkisi`: Raporun hafta tatili hakkini etkileyip etkilemedigi.
- `Hafta tatili ucreti etkisi`: Raporun hafta tatili ucretine etkisi.
- `Devam primi etkisi`: Devam priminin raporlu gunlerden etkilenip etkilenmedigi.
- `Performans primi etkisi`: Performans priminin raporlu gunlerden etkilenip etkilenmedigi.
- `Yan hak etkisi`: Yemek, yol, vardiya, gece veya benzeri yan hak etkisi.
- `Aciklayici eksik gun nedeni`: Kodsuz neden metni.
- `Resmi SGK kodu durumu`: Bu fazda resmi kod numarasi uretilmez.
- `Karar durumu`: Kesin, cevap bekliyor veya bordro kontrolu gerekir.
- `Kod fazina hazir mi?`: Kod fazina gecilip gecilemeyecegi.

## 8. Kesinlesen kararlar

17 numarali form henuz gercek cevaplarla doldurulmadigi icin bu dokumanla yeni bir bordro / firma politikasi kesinlesmemistir.

Korunan mevcut kesin cerceve:

- SGK prim gunu formulu degismez.
- Formul yalniz ucret hak edilmeyen ve SGK prim gununu dusuren tam gun eksiklikler icindir.
- Rapor / istirahat icin SGK etkisi ile isveren odeme politikasi ayri degerlendirilir.
- Rapor / istirahat icin kodsuz aciklayici neden yaklasimi korunur.
- Bu fazda resmi SGK kod numarasi uretilmez.
- Belgeli rapor otomatik mazeretsiz devamsizlik gibi siniflandirilmaz.
- Belgeli rapor otomatik ucretli veya ucretsiz diye kesinlestirilmez.

## 9. Cevap bekleyen kararlar

Asagidaki kararlar cevap bekliyor:

- Hastalik raporunda ilk gunler icin isveren odemesi.
- Hastalik raporunda ucuncu gun ve sonrasi icin isveren odemesi.
- Hastalik raporunda SGK prim gunu etkisi.
- Is kazasi raporunun hastalik raporundan farkli uygulanip uygulanmayacagi.
- Is kazasi raporunda isveren odemesi.
- Is kazasi raporunda SGK prim gunu etkisi.
- Analik / dogum raporunun bu faza dahil edilip edilmeyecegi.
- Analik / dogum raporunda ucret, SGK prim gunu ve aciklayici neden yaklasimi.
- Refakat / diger istirahat turlerinde ucret ve SGK etkisi.
- SGK gecici is goremezlik odeneginin mahsup mu, raporlama mi olacagi.
- Hafta tatili hakki ve hafta tatili ucreti etkisi.
- Devam primi, performans primi ve yan hak etkileri.
- Belirsiz raporlar icin aciklayici metin.
- Resmi SGK kod sozlugunun hangi onay sureciyle ele alinacagi.

## 10. Kod fazina hazir olmayan alanlar

Gercek bordro cevaplari olmadigi icin asagidaki alanlar kod fazina hazir degildir:

- Hastalik raporu politikasi.
- Is kazasi raporu politikasi.
- Analik / dogum raporu politikasi.
- Refakat / diger istirahat turleri politikasi.
- Belirsiz raporlarin kontrol mesaji ve davranisi.
- SGK odenegi mahsup / raporlama yaklasimi.
- Hafta tatili ucreti etkisi.
- Prim / devam primi / performans primi etkisi.
- Yemek, yol, vardiya, gece ve benzeri yan hak etkisi.
- Resmi SGK kod sozlugu.
- Net bordro / maas etkisi.

Mevcut durumda kod fazina hazir alan yoktur.

## 11. Kod fazina gecis kriterleri

Kod fazina gecmek icin asagidaki kriterler saglanmalidir:

1. 17 numarali form gercek muhasebe / bordro / firma cevaplariyla doldurulmus olmalidir.
2. Her rapor turu icin SGK prim gunu etkisi netlesmelidir.
3. Her rapor turu icin ucret hak edisi etkisi netlesmelidir.
4. Isveren odeme politikasi netlesmelidir.
5. Ilk gunler icin ozel uygulama olup olmadigi yazilmalidir.
6. SGK gecici is goremezlik odenegi icin mahsup / raporlama yaklasimi belirlenmelidir.
7. Hafta tatili hakki ve hafta tatili ucreti etkisi netlesmelidir.
8. Devam primi, performans primi ve yan hak etkileri netlesmelidir.
9. Aciklayici eksik gun nedeni metinleri kesinlesmelidir.
10. Resmi SGK kodu bu fazda uretilmeyecekse bu karar korunmalidir; kod sozlugu ayrica onay surecine birakilmalidir.
11. `Duruma gore` cevaplari varsa kosullari yazilmalidir.
12. `Bordro kontrolu gerekir` kalan alanlar kod fazi disinda tutulmalidir.

Bu kriterler saglanmadan hesap motoru kurali, dashboard davranisi, UI gosterimi veya net bordro etkisi uretilmemelidir.

## 12. Sonuc ve onerilen sonraki faz

Bu dokuman, 17 numarali form cevaplandiktan sonra kullanilacak kesin politika tablosu sablonudur. Mevcut durumda gercek firma / bordro cevaplari bulunmadigi icin tum rapor turleri cevap bekliyor ve kod fazina hazir degil olarak isaretlenmistir.

Sonraki onerilen faz:

1. `17-rapor-istirahat-bordro-cevap-formu.md` dosyasinin muhasebe / bordro / firma yetkilileri tarafindan doldurulmasi
2. Doldurulan cevaplarin bu tabloya islenmesi
3. `Cevap bekliyor` ve `Bordro kontrolu gerekir` alanlarin ayrilmasi
4. Sadece kesinlesen alanlar icin dar kapsamli Cursor / Codex implementasyon talimati hazirlanmasi
5. Kod fazi oncesi `13`, `14`, `15`, `16` ve `17` numarali dokumanlarla uyum review'u yapilmasi

## Belge Gecmisi

| Tarih | Not |
|---|---|
| 2026-05-17 | Gercek bordro cevaplari olmadigi icin taslak politika tablo sablonu eklendi. |
