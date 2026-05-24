# 23. Puantaj V2 Eksik Gun Dar Tasarim Karari

Surum: `V2 dar tasarim karari`

Tarih: `2026-05-24`

## 1. Amac

Bu dokuman, Puantaj V2 Eksik Gun / Ay Sonu SGK / Ucret Etkisi fazi icin kod yazmadan once uygulanacak dar karar cercevesini sabitler.

Ana amac, uc kavramin birbirine karismasini engellemektir:

- eksik gun
- SGK prim gunu etkisi
- ucret etkisi

Bu belge resmi SGK kod numarasi, net bordro, maas hesabi veya finans kalemi uretmez. Ilk kod fazi yalnizca netlesmis tam gun eksik gun ve SGK prim gunu karar cekirdegine hazirlik icindir.

## 2. Mevcut Karar Evreni

Mevcut puantaj hareket durumlari:

- `Geldi`
- `Gelmedi`
- `Gec_Geldi`
- `Erken_Cikti`

Karari etkileyen mevcut dayanaklar:

- `Yok_Izinsiz`
- `Ucretli_Izinli`
- `Raporlu_Hastalik`
- `Raporlu_Is_Kazasi`
- `Yillik_Izin`
- `Telafi_Calismasi`

Mevcut gun tipleri:

- `Normal_Is_Gunu`
- `Hafta_Tatili_Pazar`
- `UBGT_Resmi_Tatil`

Mevcut sistemde `Gec_Geldi` ve `Erken_Cikti` dakika bazli ucret etkisi uretir. Bu davranis tam gun eksik gun ve SGK prim gunu hesabindan ayri tutulur.

## 3. Kapsam Ici

Bu dar tasarim kararinin kapsaminda sunlar vardir:

- Tam gun eksik gun adayi olan gunluk puantaj durumlarini siniflandirma
- SGK prim gununu dusurebilecek tam gun eksiklikleri ayirma
- Ucret etkisini SGK prim gunu etkisinden ayri isaretleme
- `Durumu Bildirdi mi?` alaninin yalniz karar girdisi olarak sinirini belirleme
- Ilk kod fazi icin owner dosya ve dar cekirdek onerisi yapma
- Mevcut `hesaplaSgkPrimGunu` formuluyle uyumlu kalma

## 4. Kapsam Disi

Bu fazda asagidakiler yapilmaz:

- Kod yazilmaz.
- Test yazilmaz.
- UI / hook / page davranisi degistirilmez.
- Resmi SGK kod numarasi uretilmez.
- SGK eksik gun nedeni resmi kod sozlugu olusturulmaz.
- Bordro, net maas veya maas mahsup hesabi yapilmaz.
- Finans kalemi veya otomatik kesinti/odeme kaydi uretilmez.
- Geç kalma / erken cikma dakika kesintisi davranisi degistirilmez.
- `07-Puantaj Kayitlari` ozel motoru tasarlanmaz.
- Vardiya, kismi sureli calisma veya saatlik rapor modeli acilmaz.
- Toplu ay sonu onay ekrani veya yeni rapor/export tasarlanmaz.

## 5. Eksik Gun / SGK Prim Gunu / Ucret Etkisi Ayrimi

Eksik gun, SGK prim gunu ve ucret etkisi ayni sey degildir.

Eksik gun:

- Tam gun calisilmayan ve uygun dayanakla siniflandirilan gun icin karar adayidir.
- Her yokluk otomatik eksik gun degildir.
- Haberli gelmeme, mazeretli yokluk veya rapor gibi durumlar ek siniflandirma gerektirir.

SGK prim gunu etkisi:

- Yalniz ucret hak edilmeyen ve SGK prim gununu dusuren tam gun eksiklikler icin uygulanir.
- Geç / erken dakika kesintisi SGK prim gununu dusurmez.
- Yillik izin, ucretli izin ve calisilmayan resmi tatil SGK prim gununu dusuren eksik gun gibi ele alinmaz.

Ucret etkisi:

- Tam gun devamsizlikta gunluk kesinti adayi olabilir.
- `Gec_Geldi` ve `Erken_Cikti` icin dakika bazli kesinti adayi olabilir.
- Rapor / istirahat gibi durumlarda isveren odeme politikasi ayrica belirlenmeden kesin bordro sonucu uretilmez.

Mevcut guvenli SGK prim gunu formulu korunur:

```text
sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))
```

Bu formul yalniz tam gun eksik gun / SGK prim gunu hesabinda kullanilir. Geç / erken dakika kesintisine uygulanmaz.

## 6. Karar Matrisi

| Durum | Eksik gun adayi mi? | SGK prim gunu etkisi | Ucret etkisi | Not |
|---|---|---|---|---|
| `Geldi` | Hayir | Dusurmez | Normal calisma / tam yevmiye veya sure bazli hesap | Saat ve sure kurallari mevcut puantaj motorunda kalir. |
| `Gec_Geldi` | Hayir | Dusurmez | Dakika bazli geç kalma kesintisi adayi | 30 dakika yukari yuvarlama sadece parasal dakika kesintisi icindir. |
| `Erken_Cikti` | Hayir | Dusurmez | Dakika bazli erken cikma kesintisi adayi | Tam gun eksik gun hesabina sokulmaz. |
| `Gelmedi + Yok_Izinsiz` | Evet, tam gun calisilmadiysa | Ucret hak edilmeyen tam gun ise dusurebilir | Gunluk kesinti adayi | Haber verip vermeme disiplin / surec siniflandirmasini etkileyebilir. |
| `Gelmedi + Ucretli_Izinli` | Hayir | Dusurmez | Ucret korunur | Ucretli mazeret / izin olarak ayrica surec owner'i netlestirmelidir. |
| `Gelmedi + Yillik_Izin` | Hayir | Dusurmez | Ucret korunur | Yillik izin bakiyesi izin motorunun konusudur. |
| `Gelmedi + Raporlu_Hastalik` | SGK prim gunu inceleme adayi | Otomatik dusum anlamina gelmez; rapor tipi, sure ve isletme / bordro politikasi gerekir | Isveren odeme politikasi ayridir | Hastalik raporu icin minimum politika kismen netlesmistir; resmi SGK kodu uretilmez. |
| `Gelmedi + Raporlu_Is_Kazasi` | SGK prim gunu inceleme adayi | Otomatik dusum anlamina gelmez; resmi prosedur, manuel kontrol ve bordro degerlendirmesi gerekir | Isveren odeme politikasi ayridir | Hastalik raporundan ayri ele alinmalidir. |
| Ucretsiz izin | Gelecek karar / model alani | Mevcut aktif model davranisi degildir; temsil karari olmadan otomatik dusum uretilmez | Gelecek politika karari | Mevcut `PuantajDayanak` enum'unda dogrudan temsil yoktur; bugunku modelde aktif davranis degil, ileride karar/model alani olarak ele alinmalidir. |
| Mazeretli devamsizlik | Duruma bagli | Ucretli ise dusurmez, ucretsiz ise dusurebilir | Duruma bagli | Hangi mazeretin ucretli oldugu firma / urun karari bekler. |
| Disiplin / ucret hak edilmeyen gun | Evet | Tam gun ucret hak edilmiyorsa dusurebilir | Gunluk ucret etkisi adayi | Surec, onay ve belge matrisi netlesmeden otomatik kural olmamalidir. |
| `UBGT_Resmi_Tatil` calisilmadi | Hayir | Dusurmez | Normal ucret korunur | UBGT calisma ek odeme konusu bu matrisin disindadir. |
| `Hafta_Tatili_Pazar` | Tek basina hayir | Tek basina dusurmez | Hafta tatili hakki ve Pazar etkisi ayridir | Mazeretsiz devamsizlik varsa hafta tatili hak kaybi ayrica degerlendirilir. |

## 7. Durumu Bildirdi mi? Alaninin Siniri

`Durumu Bildirdi mi?` alani yalniz `Gelmedi` hareket durumunda karar girdisi olarak kullanilir.

Bu alanin mevcut siniri:

- Tek basina SGK prim gunu karari vermez.
- Tek basina ucretli veya ucretsiz yokluk karari vermez.
- Tek basina finans, bordro veya disiplin sonucu uretmez.
- Haberli / habersiz ayrimini gorunur hale getirir.
- Surec siniflandirmasi, manuel inceleme ve disiplin degerlendirmesi icin isaret olarak kullanilabilir.

Guvenli yorum:

- `durumu_bildirdi_mi = false` ise haber vermeden gelmeme / izinsiz devamsizlik adayi guclenir.
- `durumu_bildirdi_mi = true` ise haberli yokluk vardir, ancak bu otomatik ucretli mazeret veya ucretsiz izin anlamina gelmez.
- Aciklama alani resmi surec kaydinin yerine gecmez.

## 8. Owner Dosya Karari

Ilk owner karari:

- Birincil hesap owner'i: `src/services/puantaj-hesap-motoru.ts`
- Tip sozlesmesi owner'i: `src/types/puantaj.ts`
- View-model / veri toplama owner'i: `src/hooks/usePuantaj.ts`
- UI owner'i: `src/features/puantaj/pages/GunlukPuantajPage.tsx`
- Unit test owner'i: `tests/unit/puantaj-hesap-motoru.test.ts`
- Smoke regression owner'i: `tests/e2e/smoke.spec.ts`

Katman siniri:

- Hesap cekirdegi karar verir.
- Hook veri toplar ve view model'e map eder.
- Page hesap yapmaz, yalniz girdi ve readonly gosterim yapar.
- API sadece veri tasir; state ve is kurali sahibi backend / servis hattidir.

Bu fazda yeni owner dosya acmak zorunlu degildir. Ilk dar cekirdek mevcut puantaj hesap motorunda tutulabilir. Ancak ileride ay sonu kapanis ve coklu gun toplama buyurse ayri bir servis owner'i tekrar degerlendirilebilir.

## 9. Ilk Kod Fazi Icin Onerilen Dar Cekirdek

Ilk kod fazi acilacaksa onerilen cekirdek yalniz saf karar fonksiyonlari seviyesinde kalmalidir.

Onerilen dar hedefler:

- Gunluk puantaj satirini tam gun eksik gun adayi olarak siniflandirma
- SGK prim gununu dusurur / dusurmez kararini uretme
- Ucret etkisi adayini SGK kararindan ayri dondurme
- Manuel inceleme gerektiren durumlari isaretleme
- Resmi SGK kodu yerine kodsuz aciklayici karar metni kullanma
- Mevcut `hesaplaSgkPrimGunu` fonksiyonunu sadece toplam eksik gun sayisi netlestikten sonra kullanma

Ilk cekirdek para hesabi yapmamalidir.

Onerilen cikti semantigi:

- `eksik_gun_adayi_mi`
- `eksik_gun_sayisi`
- `sgk_prim_gununu_dusurur_mu`
- `ucret_etkisi_turu`
- `manuel_inceleme_gerekli_mi`
- `aciklama`

`sgk_prim_gununu_dusurur_mu` semantigi her durumda otomatik boolean karar gibi yorumlanmamalidir. Rapor, is kazasi, haberli yokluk veya mevcut modelde temsil edilmeyen durumlar manuel inceleme ya da isletme / bordro politikasi gerektirebilir.

Bu alan adlari kod talimati degil, karar semantigi onerileridir.

## 10. Acik Sorular

Kod fazindan once su sorular netlestirilmelidir:

1. Haberli gelmeme hangi surec sinifina donusur: ucretli mazeret, ucretsiz izin veya devamsizlik?
2. Ucretsiz izin mevcut puantaj modelinde hangi `dayanak` veya yeni alanla temsil edilecek?
3. Hastalik raporu SGK prim gununu hangi kosullarda dusurecek?
4. Is kazasi raporu bu fazda otomatik karara girecek mi, yoksa manuel / bordro kontrolunde mi kalacak?
5. Analik / dogum, refakat ve diger rapor turleri bu fazin disinda mi kalacak?
6. Yarım gun veya kismi gun eksiklik SGK prim gunu hesabina konu olacak mi?
7. Ayni ayda birden fazla eksik gun nedeni varsa gosterim nasil ayrisacak?
8. Hafta tatili hak kaybi SGK eksik gun sayisina mi, yalniz ucret etkisine mi yansiyacak?
9. `Durumu Bildirdi mi?` alaninin disiplin / surec siniflandirmasindaki resmi etkisi ne olacak?
10. Ay sonu hesaplamasi canli gunluk kayitlardan mi, muhurlu snapshot'tan mi, yoksa ikisinin oncelik sirasiyla mi beslenecek?
11. Resmi SGK kod sozlugu hangi onay surecinden sonra sisteme alinabilir?
12. Ilk kod fazinin ciktisi yalniz servis sonucu mu olacak, yoksa rapor/personel detayinda readonly gosterim de istenecek mi?

## 11. Kod Fazina Gecis Kriteri

Kod fazina gecmek icin asagidaki kriterler saglanmalidir:

- Kapsam yalniz tam gun eksik gun ve SGK prim gunu karar cekirdegiyle sinirlanmis olmali.
- Geç / erken dakika kesintisi davranisina dokunulmayacagi teyit edilmeli.
- Resmi SGK kod numarasi uretilmeyecegi teyit edilmeli.
- Bordro, net maas ve finans kalemi uretilmeyecegi teyit edilmeli.
- Ucretsiz izin ve haberli gelmeme temsil karari netlesmeli veya kapsam disi birakilmali.
- Rapor / istirahat icin hangi alt durumlarin otomatik, hangilerinin manuel inceleme kalacagi netlesmeli.
- Owner dosya seti dar tutulmali.
- Unit test senaryolari karar matrisiyle birebir yazilabilir hale gelmeli.
- UI hesap yapmayacak, hook sadece servis sonucunu tasiyacak prensibi korunmali.
- Kod fazi baslamadan once `13`, `14`, `18`, `21` ve bu `23` numarali dokumanlarla uyum review'u yapilmali.

Bu kriterler saglanmadan hesap motoruna yeni eksik gun, SGK prim gunu veya ucret etkisi kurali eklenmemelidir.

## Belge Gecmisi

| Tarih | Not |
|---|---|
| 2026-05-24 | Puantaj V2 eksik gun / SGK prim gunu / ucret etkisi icin dar tasarim karari eklendi. |
