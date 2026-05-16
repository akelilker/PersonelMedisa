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
- raporda işveren ödemez
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
