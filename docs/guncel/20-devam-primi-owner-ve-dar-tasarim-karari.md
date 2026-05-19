# 20. Devam Primi Owner ve Dar Tasarim Karari

Surum: `V2 karar dokumani`

## 1. Amac

Bu dokuman, `1 gun rapor devam primini tam keser` is kararini dogrudan koda tasimadan once devam primi alaninin is modeli, teknik owner siniri ve dar V1 tasarim cercevesini netlestirmek icin hazirlanmistir.

Temel amac, devam primi kararinin sessiz bordro varsayimiyla veya mevcut puantaj hesap motoruna daginik sekilde gomulmesini engellemektir.

Bu dokuman kod fazi degildir. Once owner, veri girdileri, karar ciktilari, kapsam disi alanlar ve implementasyona gecis kriterleri netlesmelidir.

## 2. Mevcut repo teshisi

Mevcut repo durumunda su tablo vardir:

- Otomatik devam primi motoru yoktur.
- `prim_kurali_id` / `prim_kurali_adi` alanlari su an personel veri/reference alani olarak durur; aktif hesap motoru kurali owner'i degildir.
- Finans tarafindaki `PRIM` / `BONUS` / `EKSTRA_PRIM` kalemleri manuel finans kalemidir; otomatik devam primi karari degildir.
- Puantaj hesap motoru devam primi hak edisi veya kesintisi uretmez.
- Dashboard SGK servisi prim hak edisi yapmaz.
- Rapor servisleri devam primi kararini otomatik finansal ciktiya cevirmez.

Sonuc olarak repo'da `1 gun rapor devam primini tam keser` kararini otomatik uygulayacak dedicated owner servis, helper, type veya test hatti yoktur.

## 3. Kesin is karari

Bu fazda korunan net is karari sudur:

- `1 gun rapor devam primini tam keser.`

Ancak bu karar su ayrimlarla okunmalidir:

- Bu karar bir is kuralidir.
- Mevcut kodda bu kararin otomatik uygulamasi yoktur.
- Kod fazina gecmeden once owner, veri modeli ve cikti hedefi netlesmelidir.

Dolayisiyla karar bugun icin dokuman seviyesinde kabul edilmis is kuralidir; heniz otomatik sistem davranisi degildir.

## 4. Devam primi ile diger primlerin ayrimi

Bu dokuman asagidaki alanlari birbirinden ayirir:

- Devam primi
- Performans primi
- Uretim primi
- Ekstra prim
- Bonus / ikramiye
- Yan haklar

Karar siniri:

- Bu dokuman yalniz devam primi icin owner ve dar tasarim belirler.
- Performans primi, uretim primi ve yan haklar bu dokumanin kapsami disindadir.
- `PRIM` / `BONUS` / `EKSTRA_PRIM` finans kalemleri otomatik devam primi owner'i gibi yorumlanmamalidir.

## 5. Onerilen en dar V1 tasarim

Onerilen ilk model, para hesabi yapmayan dar bir `hak edis / eligibility` motorudur.

Bu motorun ilk sorusu su olmalidir:

- Personel ilgili donemde devam primine hak kazandi mi?

Ilk surumde cikti para degil, karar olmalidir.

V1 prensipleri:

- Net bordro veya maas hesabi yapmaz.
- Finans kalemi otomatik olusturmaz.
- Yalniz hak kazandi / kesildi / manuel inceleme gerekir seviyesinde karar uretir.
- Acik olmayan alanlarda sessiz varsayim yerine inceleme ihtiyaci isaretler.

Onerilen ornek cikti alanlari:

- `personel_id`
- `donem`
- `prim_kurali_id`
- `hak_kazandi_mi`
- `kesildi_mi`
- `kesinti_nedeni`
- `uygulanan_kural`
- `manuel_inceleme_gerekli_mi`
- `aciklama`

Bu dar model, devam primi kararini para hesaplayan tam bordro motoruna cevirmeden once karar omurgasini sabitler.

## 6. Onerilen teknik owner

Kod yazmadan mimari owner onerisi asagidaki gibidir:

Onerilen owner:

- `src/services/devam-primi-hesap-motoru.ts`

Alternatif:

- `src/services/prim-hakedis-motoru.ts`

Dar V1 icin tercih:

- `src/services/devam-primi-hesap-motoru.ts`

Gerekce:

- Tam bordro motoru degildir.
- Sadece devam primi kararini uretir.
- Puantaj motorunu sisirmez.
- Finans CRUD hattina otomatik karar mantigi gommez.
- Dashboard SGK servisini prim hesabiyla kirletmez.

## 7. Girdi modeli

Ilk surum icin onerilen girdi seti:

- `personel_id`
- `donem`
- `prim_kurali_id`
- gunluk puantaj kayitlari
- rapor / istirahat kayitlari
- devamsizlik kayitlari
- ucretsiz izin kayitlari
- varsa manuel override karari

Ancak kod fazina gecmeden once asagidaki alanlarin kesinlestirilmesi gerekir:

- `prim_kurali_id`'nin gercek semantigi
- Donem tipinin aylik mi, farkli bir kapanis periyodu mu oldugu
- Gunluk kayitlarin owner kaynagi ve hangi alanlarla normalize edilecegi
- Rapor / istirahat alt turlerinin hangi veri modelinden okunacagi
- Devamsizlik ve ucretsiz izin olaylarinin hangi siniflandirmayla devam primi motoruna girecegi
- Manuel override'in readonly not mu, zorlayici karar mi olacagi
- Override gerekcesi, override kullanicisi ve audit izi ihtiyaci

## 8. Karar bekleyen is sorulari

Kod fazi oncesi acik kalan sorular:

- `prim_kurali_id` gercekten neyi temsil ediyor?
- Devam primi aylik binary hak edis mi?
- `1 gun rapor` tum ay primini mi keser?
- Is kazasi raporu da ayni sekilde devam primini keser mi?
- Yarım gun rapor devam primini nasil etkiler?
- Analik / dogum raporu devam primini nasil etkiler?
- Refakat / diger istirahat turleri nasil ele alinir?
- Ucretsiz izin devam primini keser mi?
- Mazeretsiz devamsizlik devam primini keser mi?
- Mazeretli devamsizlik devam primini keser mi?
- Gec gelme / erken cikma devam primini etkiler mi?
- Disiplin / ceza kaydi devam primini etkiler mi?
- Performans primi ve uretim primi devam priminden tamamen ayri mi?
- Devam primi tutari nereden gelecek?
- Sonuc finans kalemine otomatik yazilacak mi?
- Yoksa readonly rapor / kapanis snapshot sonucu olarak mi kalacak?
- Manuel override olacak mi?
- Override yetkisi hangi rolde olacak?

## 9. Kod fazina gecis kriterleri

Kod fazina gecmek icin en az asagidaki kararlar tamamlanmis olmalidir:

- `prim_kurali_id` semantigi netlesti.
- Devam primi donem tipi netlesti.
- Kesinti olusturan olaylar netlesti.
- Rapor turleri etkisi netlesti.
- Yarım gun etkisi netlesti.
- Tutar kaynagi netlesti veya ilk surumde tutar disi karar motoru olacagi kesinlesti.
- Cikti hedefi netlesti.
- Manuel override politikasi netlesti.
- Test senaryolari yazilabilir hale geldi.

Bu kriterler tamamlanmadan yeni hesap kurali, servis owner'i veya otomatik finans cikti davranisi acilmamalidir.

## 10. Bilincli kapsam disi

Bu dokumanin bilincli olarak kapsami disinda kalan alanlar:

- Kod implementasyonu
- Yeni servis acilmasi
- Type eklenmesi
- Test yazilmasi
- UI / hook / dashboard degisikligi
- Finans kalemi otomatik olusturma
- Net bordro / maas hesabi
- Performans primi
- Uretim primi
- Yan haklar
- SGK resmi kod sozlugu

## 11. Sonuc

Bu dokumanin vardigi sonuc sudur:

- `1 gun rapor devam primini tam keser` karari is kurali olarak kabul edilmistir.
- Ancak repo'da devam primi motoru olmadigi icin otomatik implementasyon yapilmayacaktir.
- Bu dokuman, sonraki fazda acilacak dar devam primi eligibility motoru icin karar zemini olusturur.
- Kod fazi ancak `9.` bolumdeki gecis kriterleri tamamlandiktan sonra acilmalidir.

## Belge Gecmisi

| Tarih | Not |
|---|---|
| 2026-05-20 | Devam primi owner, dar V1 eligibility modeli, acik is sorulari ve kod fazina gecis kriterleri sabitlendi. |
