# Puantaj V2 SGK Eksik Gün Nedeni Eşleme Tablosu

Sürüm: `V2 karar matrisi uzantısı`

## Belgenin Amacı

Bu doküman, eksik gün doğuran süreçlerin SGK eksik gün nedeni adıyla nasıl eşleşeceğini kod yazmadan önce karar tablosu olarak netleştirir.

Bu belge uygulama kodu değildir. `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md` dosyasının SGK nedeni eşleme uzantısıdır. Ay sonu prim günü formülü, ücret hesabı veya bordro motoru sahibi değildir.

İlişkili belgeler:

- Eksik gün ve SGK prim günü ana matrisi: `13-eksik-gun-sgk-prim-gunu-kural-matrisi.md`
- Ürün ve operasyon ana özeti: `11-puantaj-kural-matrisi.md`
- Hesap motoru teknik owner'ı: `04-hesap-motoru-kurallari.md`
- Kullanıcı akışları: `07-is-akislari-ve-senaryolar.md`

## Karar Etiketleri

| Etiket | Anlam |
|---|---|
| `Kesinleşmiş kural` | Ürün içinde uygulanacak davranış nettir. |
| `Mevzuat dayanaklı yorum` | Mevzuat ve SGK pratiğine göre teknik yorum yapılmıştır; uygulamada resmi danışmanlık veya bordro kontrolü gerekebilir. |
| `Firma / ürün kararı bekliyor` | Ücret, prim, ek ödeme, onay, süreç sınıfı veya SGK nedeni eşlemesi için firma politikası ya da ürün kararı eksiktir. |

## Kapsam Sınırı

**Durum etiketi:** `Kesinleşmiş kural`

Bu doküman yalnızca SGK eksik gün nedeni eşleme karar çerçevesini tarif eder.

- SGK eksik gün nedeni kod numarası kesinleştirilmez.
- Kod numarası bilinse bile bordro / mevzuat kontrolü yapılmadan ürün kuralı kabul edilmez.
- Ay sonu prim günü formülü değiştirilmez: `sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))`.
- Bu formül yalnızca ücret hak edilmeyen ve SGK prim gününü düşüren tam gün eksiklikler için geçerlidir.
- Geç / erken dakika kesintisi bu tablonun kapsamı değildir.
- `07-Puantaj Kayıtları` özel durumu bu genel eşleme tablosuyla birebir aynı motor kuralı gibi okunmaz.

## SGK Eksik Gün Nedeni Eşleme Tablosu

| Süreç / durum | Eksik gün oluşur mu? | Ücret hak edilir mi? | SGK prim günü etkilenir mi? | Önerilen SGK eksik gün nedeni adı | Karar durumu | Manuel inceleme gerekir mi? | Not / açıklama |
|---|---|---|---|---|---|---|---|
| Rapor / istirahat | Evet, raporlu tam günler eksik gün adayıdır. | Firma / ürün kararı bekliyor; işveren ödeme politikası ayrı karardır. | Mevzuat dayanaklı yorum; rapor günleri prim gününü düşürebilir. | Rapor / istirahat | `Firma / ürün kararı bekliyor` | Evet. | SGK nedeni etkisi ile işverenin ücret ödeyip ödememesi karıştırılmaz. Rapor tipi, rapor süresi ve işveren uygulaması alt karara ayrılır. Kod numarası yazılmaz; mevzuat / bordro kontrolü gerekir. |
| Ücretsiz izin | Evet, ücret hak edilmeyen tam gün oluşur. | Hayır. | Evet, tam gün eksik sayısına göre prim günü düşer. | Ücretsiz izin | `Mevzuat dayanaklı yorum` | Onay veya belge eksikse evet. | Sürecin onaylı ve belgeli olması gerekir. Kesin kod numarası bu tabloda verilmez; eşleme bordro kontrolüyle netleşir. |
| Haber vermeden işe gelmedi | Evet, tam gün çalışılmadıysa eksik gün oluşur. | Hayır, tam gün çalışılmadıysa ücret hak edilmez. | Mevzuat dayanaklı yorum; ücret hak edilmeyen tam gün varsa prim günü düşer. | Devamsızlık | `Mevzuat dayanaklı yorum` | Evet. | Eksik gün ve ücret hak edilmeyen gün etkisi nettir. Disiplin sonucu, hafta tatili bağlantısı, prim / ek ödeme ve kesin SGK kod eşlemesi firma / ürün kararı bekler. |
| Haber vererek işe gelmedi | Firma / ürün kararı bekliyor. | Firma / ürün kararı bekliyor. | Firma / ürün kararı bekliyor. | Sınıflandırmaya göre değişir; diğer / bordro kontrolü gerekir | `Firma / ürün kararı bekliyor` | Evet. | Haberli gelmeme otomatik ücretsiz izin, otomatik devamsızlık veya otomatik ücret kesintisi sayılmaz. Ücretli mazeret, ücretsiz izin veya devamsızlık sınıfı netleşmeden SGK nedeni üretilemez. |
| Mazeretli devamsızlık | Duruma bağlı. Ücretli mazeret ise oluşmaz; ücretsiz mazeret ise oluşabilir. | Duruma bağlı. | Duruma bağlı. | Mazeret türüne göre değişir; diğer / bordro kontrolü gerekir | `Firma / ürün kararı bekliyor` | Evet. | Hangi mazeretlerin ücretli, hangilerinin ücretsiz sayılacağı firma politikasıyla netleşmelidir. Kesin olmayan durumda SGK nedeni otomatik atanmaz. |
| Yıllık izin | Hayır. | Evet, ücretli izin olduğu için ücret korunur. | Hayır, prim günü korunur. | SGK eksik gün nedeni yok | `Mevzuat dayanaklı yorum` | Genelde hayır; bakiye veya tarih çelişkisi varsa evet. | Yıllık izin normalde eksik gün nedeni üretmez. Prim / ek ödeme etkisi varsa firma kuralına bağlı ayrı değerlendirilir. |
| Resmi tatil | Hayır, çalışılmayan resmi tatilde eksik gün oluşmaz. | Evet, çalışılmayan resmi tatilde normal ücret korunur. | Hayır, prim günü düşmez. | SGK eksik gün nedeni yok | `Kesinleşmiş kural` | Genelde hayır; çalışma / çalışmama kaydı çelişkiliyse evet. | Resmi tatilde çalışma, UBGT veya ek ödeme etkisi bu tablonun kapsamı dışındadır. |
| Disiplin cezası / ücret hak edilmeyen gün | Evet, tam gün ücret hak edilmiyorsa eksik gün oluşur. | Hayır, ücret hak edilmeyen gün olarak onaylandıysa. | Evet, ücret hak edilmeyen tam gün prim gününü düşürür. | Diğer / bordro kontrolü gerekir | `Firma / ürün kararı bekliyor` | Evet. | Disiplin kaydı otomatik bordro kesintisi veya otomatik SGK nedeni değildir. Süreç, onay, belge, mevzuata uygunluk ve neden eşlemesi netleşmelidir. |
| Yarım gün / kısmi gün eksiklik | Duruma bağlı; bu tablo tam gün eksik gün omurgasını temel alır. | Duruma bağlı. | Firma / ürün kararı bekliyor. | Diğer / bordro kontrolü gerekir | `Firma / ürün kararı bekliyor` | Evet. | Geç / erken dakika kesintisiyle karıştırılmaz. Kısmi günün SGK eksik gün nedeni üretip üretmeyeceği çalışma modeli, bordro yöntemi ve mevzuat kontrolüyle ayrıca netleşir. |
| Birden fazla eksik gün nedeni aynı ayda birleşirse | Evet, nedenlere göre birden fazla eksik gün oluşabilir. | Nedenlere göre değişir. | Evet, ücret hak edilmeyen tam günlerin toplamı prim gününü etkileyebilir. | Birden çok neden / bordro kontrolü gerekir | `Firma / ürün kararı bekliyor` | Evet. | Tek nedene otomatik indirgenmez. Rapor, ücretsiz izin, devamsızlık gibi nedenler ayrı ayrı izlenmeli; aylık bildirime nasıl taşınacağı bordro kontrolüyle netleşmelidir. |

## 07-Puantaj Kayıtları Uyarısı

**Durum etiketi:** `Mevzuat dayanaklı yorum`

SGK'nın özel `07-Puantaj Kayıtları` / puantaj usulü bildirim yaklaşımı, bu genel SGK eksik gün nedeni eşleme tablosuyla birebir aynı motor kuralı gibi okunmamalıdır.

- Bu doküman `07-Puantaj Kayıtları` özel motorunu tasarlamaz.
- Bu özel durum ayrıca mevzuat / bordro kontrolü gerektirir.
- Genel `takvim_gunu - eksik_gun` omurgası bu özel durum için otomatik uygulanmaz.

## Karar Sınıflandırması Özeti

### Kesinleşmiş kural

- Resmi tatilde çalışılmadıysa SGK eksik gün nedeni oluşmaz.
- Geç / erken dakika kesintisi SGK eksik gün nedeni eşleme tablosunun kapsamı değildir.
- Ay sonu prim günü formülü değiştirilmez ve yanlış alanlara genişletilmez.

### Mevzuat dayanaklı yorum

- Rapor / istirahat SGK eksik gün nedeni adayıdır; işveren ödeme politikası ayrı karardır.
- Ücretsiz izin, onaylı süreç kaydıyla SGK eksik gün nedeni adayıdır.
- Haber vermeden işe gelmeme tam gün çalışılmadıysa devamsızlık nedeni adayıdır.
- Yıllık izin ücretli izin olduğu için normalde SGK eksik gün nedeni üretmez.
- `07-Puantaj Kayıtları` özel durumu ayrıca mevzuat / bordro kontrolü gerektirir.

### Firma / ürün kararı bekliyor

- Haber vererek işe gelmeme hangi süreç sınıfına bağlanacak?
- Mazeretli devamsızlık hangi hallerde ücretli, hangi hallerde ücretsiz sayılacak?
- Disiplin cezası / ücret hak edilmeyen gün hangi SGK nedeniyle bildirilecek?
- Yarım gün / kısmi gün eksiklik SGK nedeni üretecek mi?
- Aynı ayda birden fazla neden birleşirse aylık bildirimde nasıl ayrıştırılacak?
- SGK eksik gün nedeni kod numaraları hangi bordro / mevzuat kontrolünden sonra sözlüğe alınacak?

## Açık Ürün Kararları

- SGK eksik gün nedeni kod sözlüğü hangi kaynak ve kontrol süreciyle onaylanacak?
- Haberli gelmeme süreci ücretli mazeret, ücretsiz izin veya devamsızlık olarak nasıl sınıflandırılacak?
- Rapor tipi, rapor süresi ve işveren ödeme uygulaması hangi alt kararlarla ayrıştırılacak?
- Mazeretli devamsızlık için ücretli / ücretsiz ayrımı hangi firma politikasına bağlanacak?
- Disiplin cezası veya ücret hak edilmeyen gün için onay ve belge matrisi nasıl kurulacak?
- Birden fazla eksik gün nedeni aynı ayda birleştiğinde raporlama ve bildirim çıktısı nasıl üretilecek?
- `07-Puantaj Kayıtları` özel durumu ayrı motor / karar gerektiriyor mu?

## Uygulama Sınırı

Bu faz yalnızca karar dokümanı fazıdır.

- Kod yazılmaz.
- Test yazılmaz.
- Hook / page davranışı değiştirilmez.
- Bu fazda SGK eksik gün nedeni kod numarası kesinleştirilmez.
- Belge yalnızca açıklayıcı neden adını ve karar durumunu tutar.
- Kod numarası eşlemesi bordro / mevzuat kontrolünden sonra netleşir.
- Bordro muhasebe motoru implementasyonu yapılmaz.
- UI ekranı tasarlanmaz.
- Vardiya / çalışma planı modeli açılmaz.
- Geç / erken dakika kesintisi kuralı değiştirilmez.
- `07-Puantaj Kayıtları` özel motoru tasarlanmaz.

## Belge Geçmişi

| Tarih | Not |
|---|---|
| 2026-05-16 | Puantaj V2 için SGK eksik gün nedeni eşleme karar tablosu eklendi. |
