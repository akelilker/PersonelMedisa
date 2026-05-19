# Medisa Personel ve Puantaj Yönetim Sistemi

## Puantaj Geliştirici Devir Notu

Sürüm: `2026-05-15`

## Belgenin Amacı

Bu belge, puantaj modülündeki güncel teknik durumun, tamamlanan fazların, açık ürün başlıklarının ve çalışma kontratının tek yerde sabitlenmesi için vardır.

Bu belge normatif kural kaynağı değildir.

- Ürün ve operasyon kararlarının ana özeti: `11-puantaj-kural-matrisi.md`
- Hesap motoru ve mevzuat davranışlarının teknik owner'ı: `04-hesap-motoru-kurallari.md`

Bu dosya şu sorulara hızlı cevap verir:

- puantaj hattında şu ana kadar ne tamamlandı?
- hangi dosyalar owner?
- readonly ön izleme ve kontrol hattı nereye kadar geldi?
- hangi konular bug değil, sonraki faz?
- yeni oturum / yeni geliştirici / yeni GPT bu alana nasıl yaklaşmalı?

## Çalışma Kontratı

Bu modülde çalışırken temel yaklaşım şudur:

- önce teşhis, sonra dar kapsamlı ameliyat
- root cause çöz
- kapsam dışına çıkma
- iş kuralını servis / hook hattında tut
- UI içinde hesap yapma
- yanlış kesin tutar göstermektense eksik veya not göster
- büyük refactor yapma
- owner dosyada çöz, karşı-yama açma

Yeni görev geldiğinde izlenecek sıra:

1. sorunun kökünü bul
2. owner dosyayı bul
3. çözüm katmanını netleştir: servis / hook / page / API / test
4. dar kapsamlı ameliyat planı çıkar
5. gerekiyorsa Cursor için hedefi dar bir operasyon talimatına çevir
6. çıkan işi scope, katman doğruluğu ve mevzuat/ürün etkisi açısından review et

## Modül Bağlamı

Puantaj hattı bu dört katman etrafında ele alınır:

1. `Yoklama`
Ham günlük olay. Birim amiri girer.

2. `Süreç`
Resmi ve idari sınıflandırma katmanı. İK veya yönetim bağlar.

3. `Puantaj`
Çalışma süresi, ücret, kesinti ve ek ödeme etkilerinin hesaplandığı katman.

4. `Ön İzleme / Kontrol`
Zorunlu onay kapısı değildir. Ancak amir kontrol etti / etmedi görünürlüğü üretir.

## Kesinleşmiş Ürün Kuralları Özeti

Detay owner belgeleri `11-puantaj-kural-matrisi.md` ve `04-hesap-motoru-kurallari.md` dosyalarıdır. Bu başlık yalnızca devir kolaylığı için özet tutar.

- ücret tipi kullanıcı yüzünde `Aylık` ve `Günlük`
- varsayılan ücret tipi `Aylık`
- kısmi süreli çalışan V1'de yok
- haftalık eşik `45 saat`
- günlük ücret `maaş / 30`
- saatlik ücret `maaş / 225`
- fazla çalışma `saatlik ücret x 1.5`
- hafta tatili günü `Pazar`
- devamsızlıkta çalışılmayan gün etkisi vardır; hak kaybı varsa ek etki eklenir; kör `2 gün kes` modeli yoktur
- geç kalma / erken çıkmada eksik süre kadar saatlik kesinti uygulanır; güvenli süre yoksa para kesin gösterilmez
- UBGT çalışılırsa `+1 günlük` ek yevmiye
- Pazar / hafta tatili çalışmasında hak varsa `+1.5 günlük` ek ödeme
- hak yokken Pazar çalışması otomatik kesin ödeme değil, manuel inceleme adayıdır
- raporda SGK etkisi ile işveren ödeme politikası ayrı değerlendirilir; kesin ayrım için `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md` referans alınır
- 18 yaş altı için fazla mesai ve gece çalışması bloktur
- 18 yaş altı yıllık izin alt sınırı `20 gün`
- serbest zaman yalnızca çalışan talep ederse
- `07 puantaj` ana model değil, opsiyonel / istisnai
- kontrol durumu yüzeyi: `BEKLIYOR`, `AMIR_KONTROL_ETTI`

## Tamamlanan Teknik İşler

### 1. Ücret tipi dili sadeleşti

- kullanıcı yüzünde `Aylık / Günlük`

### 2. 18 yaş altı ve 50+ yıllık izin istisnası kodlandı

- owner: `src/services/izin-hesap-motoru.ts`
- test: `tests/unit/izin-hesap-motoru.test.ts`

### 3. 18 yaş altı puantaj blokları kodlandı

- gece çalışması blok
- mevcut mesai / tatil sinyallerinde blok
- owner: `src/services/puantaj-hesap-motoru.ts`, `src/hooks/usePuantaj.ts`

### 4. Haftalık fazla çalışma omurgası kuruldu

- haftalık net dakika
- normal dakika
- fazla dakika
- `2700 dk` eşik
- owner: `src/services/puantaj-hesap-motoru.ts`

### 5. Hafta aralığı ve haftalık filtre katmanı kuruldu

- `Pazartesi - Pazar` aralığı
- haftaya ait kayıtları süzme
- invalid tarih güvenli davranış

### 6. Haftalık fazla çalışma ücret hesabı kuruldu

- saatlik ücret
- fazla çalışma saat
- fazla çalışma tutarı

### 7. Günlük puantajlardan haftalık ücret özeti adapter'ı kuruldu

- `hesaplaHaftalikPuantajUcretOzeti(...)`

### 8. Haftalık FM readonly ön izleme eklendi

- owner: `src/hooks/usePuantaj.ts`
- owner: `src/features/puantaj/pages/GunlukPuantajPage.tsx`

### 9. Haftalık özet stale cache bug'ı kapatıldı

- `useAppDataRevision` dependency eklendi

### 10. Amir kontrol durumu eklendi

- owner: `src/types/puantaj.ts`
- owner: `src/api/puantaj.api.ts`
- owner: `src/api/mock-demo.ts`
- owner: `src/hooks/usePuantaj.ts`
- owner: `src/features/puantaj/pages/GunlukPuantajPage.tsx`
- owner: `src/lib/authorization/role-permissions.ts`

### 11. Devamsızlık kesinti motoru kuruldu

- günlük ücret
- gün eşdeğeri
- hafta tatili kaybı
- parasal kesinti
- owner: `src/services/puantaj-hesap-motoru.ts`

### 12. Tatil ek ödeme ön izleme kuruldu

- UBGT
- Pazar / hafta tatili
- owner: `src/services/puantaj-hesap-motoru.ts`
- owner: `src/hooks/usePuantaj.ts`
- owner: `src/features/puantaj/pages/GunlukPuantajPage.tsx`

### 13. Pazar hafta tatili hak -> etki karar motoru kuruldu

- `hesaplaHaftaTatiliPazarEtkisi(...)`
- manuel inceleme bayrağı
- `Pazar çalıştı + hak yok` durumunda otomatik kesin ödeme yok

### 14. Parasal Etki Ön İzleme kartı eklendi

- haftalık FM
- tatil ek ödeme
- toplam kesinti
- ön izleme net etki
- güvenli değilse `Kesinleştirilemedi`
- notlarla çalışma

### 15. Geç / Erken Kesinti V1 tamamlandı

- `beklenen_giris_saati`
- `beklenen_cikis_saati`
- eksik dakika serviste türetiliyor
- tolerans sabiti `0`
- gerçek eksik süre korunuyor
- parasal kesinti `kesintiye_esas_dakika` üzerinden hesaplanıyor
- 30 dakikalık yukarı yuvarlama uygulanıyor
- güvenli veri varsa sayısal kesinti
- güvenli veri yoksa not / fren
- readonly görünür özet alanları var
- form inputları submit body'ye taşındı

### 16. SGK prim günü ve eksik gün checkpoint'i tamamlandı

Normatif karar kaynakları:

- `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md`
- `14-sgk-eksik-gun-nedeni-esleme-tablosu.md`

SGK prim günü hesap çekirdeği:

- owner: `src/services/puantaj-hesap-motoru.ts`
- test: `tests/unit/puantaj-hesap-motoru.test.ts`
- `hesaplaSgkPrimGunu` artık puantaj hesap motoru owner dosyasındadır
- formül: `sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))`
- formül yalnız ücret hak edilmeyen ve SGK prim gününü düşüren tam gün eksiklikler için geçerlidir
- SGK kod nedeni üretmez
- süreç tipinden otomatik karar türetmez
- `07-Puantaj Kayıtları` özel motorunu uygulamaz
- geç / erken dakika kesintisine dokunmaz

Kaldırılan yanlış owner dosyaları:

- `src/services/sgk-prim-gunu-hesap.ts` silindi
- `tests/unit/sgk-prim-gunu-hesap.test.ts` silindi
- SGK prim günü çekirdeği ayrı helper dosyasında değil, puantaj hesap motoru owner dosyasında tutulur

Dashboard SGK eksik gün güvenlik düzeltmesi:

- owner: `src/services/dashboard-rapor-servisi.ts`
- test: `tests/unit/dashboard-rapor-servisi.test.ts`
- `dayanak === undefined` artık SGK eksik gün sayılmaz
- yalnız açık sınıflandırılmış adaylar değerlendirilir: `Yok_Izinsiz`, `Raporlu_Hastalik`, `Raporlu_Is_Kazasi`
- `UBGT_Resmi_Tatil` eksik gün hesabından dışlanır
- kesin SGK kod numaraları üretilmez
- `01 - İstirahat`, `15 - Devamsızlık`, `12 - Birden Fazla` kaldırıldı
- kodsuz açıklayıcı neden metinleri kullanılır: `Rapor / istirahat`, `Devamsızlık`, `Birden fazla neden / bordro kontrolü gerekir`

`eksik_gun_nedeni_kodu` alanı:

- alan adı geriye uyumluluk için korunmuştur
- içerik artık resmi SGK kod numarası değil, kodsuz açıklayıcı neden metnidir
- UI veya yeni geliştirme bu alanı resmi kod gibi yorumlamamalıdır

Dashboard SGK tek personel kontratı:

- `hesaplaAylikSgkPuantajOzeti` ve `hesaplaAylikSgkPuantajOzetleri` tek personel kayıt listesi bekler
- çok personelli input guard ile reddedilir
- hata mesajı: `Dashboard SGK aylık özeti tek personel kayıtlarıyla hesaplanmalıdır.`
- kişi bazlı çok personelli SGK özet modeli bu fazda açılmadı
- `personel_id + tarih` anahtar modeline geçilmedi

Personel detay görünüm review sonucu:

- personel detay UI'da SGK nedeni `Eksik Gün Nedeni` label'ı ile gösteriliyor
- kullanıcıya `kod` olarak sunulmuyor
- hook / page tarafı hesap yapmıyor; API'den gelen readonly veriyi render ediyor
- kod değişikliği gerekmedi

### 17. Rapor / istirahat politika doküman hattı checkpoint'i

Oluşturulan karar dokümanları:

- `15-rapor-istirahat-isveren-odeme-politikasi.md`: Rapor / İstirahat İşveren Ödeme Politikası
- `16-rapor-turleri-isveren-odeme-karar-matrisi.md`: Rapor Türleri ve İşveren Ödeme Politikası Dar Karar Matrisi
- `17-rapor-istirahat-bordro-cevap-formu.md`: Rapor / İstirahat Bordro Cevap Formu
- `18-rapor-istirahat-kesin-politika-tablosu.md`: Rapor / İstirahat Kesin Politika Tablosu Taslak Şablonu

Net durum:

- `15/16/17/18` hattı kod fazı değil, karar dokümanı fazıdır.
- `17` numaralı form gerçek muhasebe / bordro / firma cevapları beklemektedir.
- `18` numaralı tablo bu yüzden kesin politika değil, taslak şablondur.
- `18` numaralı tablodaki karar alanları `Cevap bekliyor`, `Firma/bordro kararı bekler` ve `Kod fazına hazır değil` statüsündedir.
- Kod fazına hazır alan yoktur.

Güncel not:

- `18` numaralı tabloya sonradan rapor / istirahat minimum sistem politikası işlenmiştir.
- Hastalık raporunda işveren ödemesi yapılmaması, SGK rapor parasının işçiye ödenmesi, otomatik mahsup / tamamlama yapılmaması, raporlu işçinin çalışamaması, saatlik rapor modelinin açılmaması, tek baskın statü prensibi ve 1 gün raporun devam primini tam kesmesi kararları minimum sistem politikası olarak ayrılmıştır.
- Yönetimsel destek / özel ödeme / maaş tamamlama / yardım kararları manuel bordro / yönetim kararı olarak hesap motoru dışında tutulmuştur.
- Genel rapor / istirahat hattı tamamen kod fazına hazır değildir; yalnız netleşen minimum politika alanları ileride ayrı teşhisle ele alınabilir.

Korunan mevcut kararlar:

- SGK prim günü formülü değişmedi: `sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))`
- Dashboard SGK özet güvenliği değişmedi.
- Kodsuz eksik gün nedeni yaklaşımı korundu.
- Resmi SGK kod numarası üretilmedi.
- Rapor / istirahat SGK etkisi ile işveren ödeme politikası ayrı tutuldu.
- UI / hook / service / hesap motoru davranışı değiştirilmedi.

Kod fazına geçiş kriteri:

- `17` numaralı bordro cevap formu gerçek muhasebe / bordro / firma cevaplarıyla doldurulmalıdır.
- Bu cevaplar `18` numaralı tabloya kesin politika olarak işlenmelidir.
- Ancak bundan sonra hesap motoru / dashboard / UI için ayrı teşhis ve ayrı talimat hazırlanmalıdır.

Bilinçli kapsam dışı:

- kod implementasyonu
- `src` değişikliği
- test değişikliği
- dashboard servis değişikliği
- hesap motoru değişikliği
- UI / hook / service değişikliği
- SGK resmi kod sözlüğü
- net bordro / maaş hesabı

Son görülen doğrulama durumu:

- `npm run typecheck` geçti
- `npx vitest run "tests/unit/puantaj-hesap-motoru.test.ts"` geçti
- `npx vitest run "tests/unit/dashboard-rapor-servisi.test.ts"` geçti
- CI yeşil
- cPanel deploy yeşil

Bilinçli kapsam dışı / sonraki işler:

- gerçek kişi bazlı çok personelli SGK özet modeli
- SGK eksik gün nedeni resmi kod sözlüğü
- rapor / istirahat işveren ödeme politikası
- `07-Puantaj Kayıtları` özel motoru
- resmi tatilde çalışma / UBGT ödeme motoru
- UI'da yeni SGK rapor kartı veya bordro ekranı
- E2E / smoke kapsamının genişletilmesi

### 18. Rapor / istirahat minimum politika ilk implementasyon checkpoint'i

Kapanan teknik fazlar:

- Hesap motoru rapor / çalışma çakışması güvenlik freni.
- Dashboard SGK rapor / hafta tatili çakışması güvenlik düzeltmesi.

Hesap motorunda netleşen davranış:

- `Raporlu_Hastalik` / `Raporlu_Is_Kazasi` otomatik `Tam_Yevmiye_Ver` üretmez.
- `Raporlu_Hastalik` / `Raporlu_Is_Kazasi` otomatik `Mesai_Yaz` üretmez.
- Rapor + çalışma saati çakışması `RAPOR_CALISMA_CAKISMASI` kodlu `KRITIK` compliance uyarısı üretir.
- `deriveHareketDurumu` ve `deriveDayanak` semantiği değiştirilmedi.
- Yeni `PuantajHesapEtkisi` enum değeri eklenmedi.

Dashboard SGK hattında netleşen davranış:

- `UBGT_Resmi_Tatil` eksik gün dışında kalır.
- `Hafta_Tatili_Pazar` eksik gün dışında kalır.
- Rapor + hafta tatili çakışması ek SGK eksik günü üretmez.
- Normal iş günü + `Gelmedi` + `Raporlu_Hastalik` / `Raporlu_Is_Kazasi` SGK eksik gün adayı olmaya devam eder.
- Eksik gün nedeni kodsuz `Rapor / istirahat` olarak korunur.
- Resmi SGK kod numarası üretilmez.

Korunan kapsam dışı alanlar:

- UI değişmedi.
- Hook değişmedi.
- `PersonelDetayPage` değişmedi.
- SGK resmi kod sözlüğü oluşturulmadı.
- Net bordro / maaş hesabı yazılmadı.
- Devam primi motoru eklenmedi.
- Yarım gün rapor modeli implementasyonu yapılmadı.
- Analık / doğum / refakat detayına girilmedi.
- Yönetimsel destek ödemeleri otomatik kurala dönüştürülmedi.

Kod fazında hâlâ açık kalan alanlar:

- 1 gün rapor devam primini tam keser kararı için henüz devam primi motoru yok.
- Yarım gün raporun prim etkisi açık.
- Performans primi, üretim primi ve yan hak etkileri açık.
- İş kazası detay uygulaması resmi prosedür + bordro kontrolü olarak bekliyor.
- Analık / doğum detay uygulaması resmi prosedür + bordro kontrolü olarak bekliyor.
- Refakat / diğer istirahat türleri hâlâ firma / bordro kararı bekliyor.
- Resmi SGK kod sözlüğü ayrı onay sürecine bırakıldı.

Sonraki önerilen faz:

- Önce bu checkpoint review edilmeli.
- Sonra ya devam primi motoru var / yok teşhisi yapılmalı ya da açık kalan bordro kararları ayrı listede tutulmalı.
- Yeni implementasyon için yine ayrı teşhis ve dar Cursor talimatı hazırlanmalı.

### 19. Devam primi motoru var/yok teşhisi checkpoint'i

Teşhis sonucu:

- Repo'da otomatik çalışan devam primi motoru yoktur.
- Devam primi için dedicated owner servis/helper/type/test bulunmamıştır.
- `prim_kurali_id` / `prim_kurali_adi` şu an hesap motoru kuralı değil, personel veri/reference alanıdır.
- Finans tarafındaki `PRIM` / `BONUS` / `EKSTRA_PRIM` manuel finans kalemidir; otomatik devam primi kararı değildir.

İş kuralı durumu:

- `1 gün rapor devam primini tam keser` kararı `18` numaralı dokümanda minimum sistem politikası olarak yer almaktadır.
- Ancak mevcut kodda bu kararı çalıştıracak devam primi owner hattı yoktur.
- Bu nedenle karar şimdilik iş kuralı olarak dokümanda kalır; otomatik implementasyon yapılmaz.

Neden kod yazılmadı?

- Owner servis yok.
- `prim_kurali_id` semantiği net değil.
- Devam primi aylık binary hakediş mi, gün bazlı hakediş mi net değil.
- Tutar kaynağı net değil.
- Rapor dışındaki diğer eksik günlerin devam primine etkisi net değil.
- Manuel override / yönetim inisiyatifi kararı net değil.
- Kod yazmak sessiz bordro varsayımı üretir.

Sonraki faza bırakılan kararlar:

- `prim_kurali_id` gerçekten neyi temsil ediyor?
- Devam primi aylık mı, dönemsel mi, gün bazlı mı?
- `1 gün rapor` tüm ay primini mi keser?
- Kural `Raporlu_Hastalik` ve `Raporlu_Is_Kazasi` için aynı mı?
- Yarım gün rapor devam primini nasıl etkiler?
- Analık / doğum / refakat / diğer istirahat türleri nasıl ele alınır?
- Ücretsiz izin, devamsızlık, disiplin günü gibi diğer eksik günler devam primini nasıl etkiler?
- Performans primi, üretim primi ve yan haklar devam priminden ayrı mı?
- Tutar kaynağı neresi olacak?
- Sonuç finans kalemine mi, readonly rapora mı, aylık kapanış snapshot'ına mı yazılacak?
- Manuel override olacak mı?

Sonraki önerilen faz:

- Devam primi owner ve dar tasarım karar dokümanı hazırlanmalı.
- Bu doküman oluşturulmadan devam primi implementasyonuna geçilmemeli.

### 20. Devam primi owner ve dar tasarım karar dokümanı checkpoint'i

- `docs/guncel/20-devam-primi-owner-ve-dar-tasarim-karari.md` oluşturuldu.
- Devam primi için mevcut repo'da otomatik motor olmadığı teyit edildi.
- `1 gün rapor devam primini tam keser` kararı iş kuralı olarak korunur.
- Kod fazına geçiş için `prim_kurali_id` semantiği, dönem tipi, kesinti olayları, çıktı hedefi ve manuel override kararları beklenir.
- Kod değişikliği yapılmadı.

## Geç / Erken Kesinti V1 Sınırı

Bu fazın bilinçli sınırları:

- ayrı vardiya / çalışma planı modeli yok
- beklenen saatler günlük puantaj kaydında opsiyonel snapshot alanları olarak tutuluyor
- beklenen giriş ve beklenen çıkış ana detay kartında readonly görünür
- bu kural tolerans politikası değildir

Eklenen tip alanları:

- `beklenen_giris_saati?: string`
- `beklenen_cikis_saati?: string`

Servis davranışı:

- `GEC_ERKEN_TOLERANS_DAKIKA = 0`
- `hesaplaGecErkenEksikSure(...)`
- hesaplanamıyorsa neden kodu döner
- `throw` ile akışı kırmaz
- `gercek_eksik_dakika` fiili farkı korur
- `kesintiye_esas_dakika = Math.ceil(gercek_eksik_dakika / 30) * 30`
- `gercek_eksik_dakika = 0` ise kesinti yoktur

30 dk sınırları:

| Gerçek eksik süre | Kesintiye esas süre |
|---|---|
| `0 dk` | `0 dk` |
| `1-30 dk` | `30 dk` |
| `31-60 dk` | `60 dk` |
| `61-90 dk` | `90 dk` |

Hook davranışı:

- `gecErkenKesintiOzeti` yalnızca `hesaplanabilir_mi === true` ve `eksik_dakika > 0` ise üretilir
- hesaplanamıyorsa `gecErkenKesintiOzeti = null`
- bu durumda `gecErkenKesintiNotu` güvenlik notu üretir
- `eksik_dakika === 0` ise özet yok, not yok, kart şişmez
- beklenen giriş yoksa parasal özet üretilmez
- beklenen çıkış yoksa parasal özet üretilmez
- gerçek giriş yoksa parasal özet üretilmez
- gerçek çıkış yoksa parasal özet üretilmez
- geçersiz saat formatında parasal tutar üretilmez

UI davranışı:

- Kesinti Ön İzleme kartında readonly alanlar:
- `Kesinti Türü`
- `Gerçek Eksik Süre (dk)`
- `Kesintiye Esas Süre (dk)`
- `Geç / Erken Kesinti Tutarı`
- hesaplanamayan durumda not korunur

Katman prensibi:

- iş kuralı servis katmanında kalır
- hook servis sonucunu view model'e taşır
- page / UI hesap yapmaz, sadece hook'tan gelen readonly veriyi render eder

Doğrulama kapsamı:

- `tests/unit/puantaj-hesap-motoru.test.ts` içinde güvenli durumlar ve 30 dk sınır değerleri kilitlendi
- `tests/e2e/smoke.spec.ts` içinde 1 dk geç gelme senaryosunda UI seviyesinde `Gerçek Eksik Süre (dk) = 1` ve `Kesintiye Esas Süre (dk) = 30` assert edildi

## Ekranın Şu Anki Görünür Hattı

Günlük puantaj ekranı şu başlıkları gösterebiliyor:

- günlük puantaj detay kartı
- haftalık fazla çalışma özeti
- kesinti ön izleme
- tatil ek ödeme ön izleme
- parasal etki ön izleme
- kontrol durumu
- `Amir Kontrol Etti` aksiyonu
- günlük kayıt girişi formu
- geç / erken için beklenen giriş ve beklenen çıkış inputları

## Önemli Owner Dosyalar

### Servis

- `src/services/izin-hesap-motoru.ts`
- `src/services/puantaj-hesap-motoru.ts`
- `src/services/dashboard-rapor-servisi.ts`

### Hook

- `src/hooks/usePuantaj.ts`

### Sayfa

- `src/features/puantaj/pages/GunlukPuantajPage.tsx`

### Tipler

- `src/types/puantaj.ts`
- `src/types/personel.ts`

### API / Mock

- `src/api/puantaj.api.ts`
- `src/api/personeller.api.ts`
- `src/api/mock-demo.ts`

### Yetki

- `src/lib/authorization/role-permissions.ts`

### Testler

- `tests/unit/izin-hesap-motoru.test.ts`
- `tests/unit/puantaj-hesap-motoru.test.ts`
- `tests/unit/puantaj.api.test.ts`
- `tests/unit/role-permissions.test.ts`
- `tests/e2e/smoke.spec.ts`

## E2E ve CI Notları

- smoke spec tarafında strict locator çakışmaları daha önce düzeltildi
- global `510 / 570` text araması kaldırıldı
- readonly alanlar etiketli scope ile hedefleniyor
- puantaj ana detay kartında `data-testid="puantaj-ana-detay"` kullanılıyor

GitHub Actions tarafında daha önce görülen önemli not:

- bazı beklemeler kod kaynaklı değil, GitHub hosted runner queue kaynaklı olabilir
- `Waiting for a hosted runner to come online.` mesajı görüldüyse önce queue / runner tarafını düşün

## Doğrulama Fotoğrafı

Son raporlanan durum:

- `npm run typecheck` başarılı
- `npx playwright test tests/e2e/smoke.spec.ts` `3/3` geçti
- ilgili unit testler geçti
- son görülen doğrulamada CI yeşildi
- son görülen doğrulamada cPanel deploy yeşildi

Not:

- bu sonuçlar tarihsel durum bilgisidir
- kritik değişiklikten sonra yeni oturum bunu kör kabul etmemeli, yeniden doğrulamalıdır

## Açık Ama V1 Bug'ı Olmayan Fazlar

Bu başlıklar mevcut V1 hattında bug olarak ele alınmamalı; ürün veya sonraki modül fazı olarak görülmelidir.

### 1. Tolerans politikası

- şu an `0`
- ürün / firma kararı gerekir
- sistem / şube / personel / vardiya bazlı mı olacağı net değildir

### 2. Vardiya / çalışma planı modeli

- şu an yok
- uzun vadeli modül konusudur

### 3. Dayanak / mazeret kararları

- mazeretli geç kalma / erken çıkmada kesinti uygulanıp uygulanmayacağı ayrı ürün kararıdır

### 4. Serbest zaman workflow'u

- talep
- bakiye
- son kullanım
- `6 ay` takibi

### 5. `270 saat` yıllık FM limit akışı

- uyarı mı
- onay mı
- blok mu

### 6. Aylık kapanış / mühürleme audit sertleştirmesi

- geri açma
- snapshot
- kim neyi değiştirdi

## Yeni Oturum İçin Öncelikli İş Akışı

1. kullanıcı yeni görev verir
2. önce teşhis çıkar
3. owner dosyayı bul
4. çözüm katmanını netleştir
5. gerekiyorsa dar kapsamlı Cursor talimatı üret
6. kullanıcı Cursor çıktısını getirir
7. review yap:
- scope taşmış mı
- yanlış katmanda çözmüş mü
- mevzuat / ürün kuralı bozulmuş mu
- test / doğrulama eksik mi
- UI içinde hesap açılmış mı
- aynı mantık iki yerde yazılmış mı

## Bu Nottan Sonra En Mantıklı İki Yol

Kod işine hemen dalmadan önce tercih edilmesi mantıklı iki dar başlık vardır:

1. `Tolerans politikası` için teşhis ve ürün karar çerçevesi çıkarmak
2. `Beklenen saatlerin ana detay readonly görünürlüğü` için dar kapsamlı UI fazı tanımlamak

## Kısa Sonuç

`Geç / Erken Kesinti V1` hattı mevcut kapsamıyla tamamlanmıştır.

Kalan işler V1 bug'ı değildir.

Özellikle:

- tolerans politikası
- vardiya modeli
- dayanak / mazeret kararı
- aylık kapanış audit sertleştirmesi

ayrı ürün ve geliştirme fazları olarak ele alınmalıdır.
