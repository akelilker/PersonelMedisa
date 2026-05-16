# Puantaj V2 Eksik Gün ve SGK Prim Günü Kural Matrisi

Sürüm: `V2 karar matrisi`

## Belgenin Amacı

Bu doküman, tam gün devamsızlık, rapor, ücretsiz izin, yıllık izin, resmi tatil ve ücret hak edilmeyen günlerin ücret, SGK prim günü, prim / ek ödeme ve manuel inceleme etkilerini kod yazmadan önce sabitler.

Bu belge uygulama kodu değildir. `04-hesap-motoru-kurallari.md` motor owner'ı olarak kalır; bu dosya tam gün eksik gün ve SGK prim günü kararlarının detay matrisidir.

İlişkili belgeler:

- Ürün ve operasyon ana özeti: `11-puantaj-kural-matrisi.md`
- Hesap motoru teknik owner'ı: `04-hesap-motoru-kurallari.md`
- Kullanıcı akışları: `07-is-akislari-ve-senaryolar.md`
- Puantaj geliştirici devir notu: `12-puantaj-gelistirici-devir-notu.md`
- SGK eksik gün nedeni eşleme uzantısı: `14-sgk-eksik-gun-nedeni-esleme-tablosu.md`

## Karar Etiketleri

Bu dokümanda her önemli karar aşağıdaki etiketlerden biriyle işaretlenir.

| Etiket | Anlam |
|---|---|
| `Kesinleşmiş kural` | Ürün içinde uygulanacak davranış nettir. |
| `Mevzuat dayanaklı yorum` | Mevzuat ve SGK pratiğine göre teknik yorum yapılmıştır; uygulamada resmi danışmanlık veya bordro kontrolü gerekebilir. |
| `Firma / ürün kararı bekliyor` | Ücret, prim, ek ödeme, onay veya kod eşlemesi için firma politikası ya da ürün kararı eksiktir. |

## Geç / Erken Kesinti ile Tam Gün Eksik Gün Ayrımı

**Durum etiketi:** `Kesinleşmiş kural`

Geç / erken kesinti ile tam gün devamsızlık / eksik gün hesabı aynı alan değildir.

- Geç / erken kesinti dakika bazlıdır.
- Geç / erken kesintide `gercek_eksik_dakika` korunur, parasal tutar `kesintiye_esas_dakika` üzerinden hesaplanır.
- 30 dakikalık yukarı yuvarlama kuralı yalnızca geç / erken dakika kesintisi içindir.
- Tam gün devamsızlık / eksik gün hesabı gün bazlıdır.
- SGK prim günü hesabı tam gün eksik gün üzerinden yürür.
- `sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))` formülü geç / erken dakika kesintisi için kullanılmaz.

## 07-Puantaj Kayıtları Uyarısı

**Durum etiketi:** `Mevzuat dayanaklı yorum`

`07-is-akislari-ve-senaryolar.md` içindeki günlük puantaj kayıt akışı ile SGK'nın özel `07-Puantaj Kayıtları` / puantaj usulü bildirim yaklaşımı aynı motor kuralı gibi okunmamalıdır.

- Bu doküman `07-Puantaj Kayıtları` özel motorunu uygulamaz.
- `07-Puantaj Kayıtları` özel durumu ayrıca mevzuat / bordro kontrolü gerektirir.
- Bu doküman yalnızca genel eksik gün / SGK prim günü karar çerçevesini tarif eder.

## Senaryo Karar Matrisi

| Senaryo | Eksik gün oluşur mu? | Ücret kesilir mi? | SGK prim günü etkilenir mi? | Prim / ek ödeme etkilenir mi? | Manuel inceleme gerekir mi? | Durum etiketi | Not / mevzuat açıklaması |
|---|---|---|---|---|---|---|---|
| Haber vermeden işe gelmedi | Evet, tam gün çalışılmadıysa eksik gün oluşur. | Evet, ücret hak edilmez. | Evet, ücret hak edilmeyen tam gün oluşursa prim günü tam gün eksik sayısına göre düşer. | Firma / ürün kararı bekliyor; prim ve ek ödeme varsayılanı bu matrisle kesinleşmez. | Evet. Devamsızlık süreç kaydı gerekir; disiplin süreci, yaptırım sonucu ve hafta tatili bağlantısı firma / ürün kararı bekliyor. | `Mevzuat dayanaklı yorum` | Eksik gün ve ücret hak edilmeyen tam gün etkisi nettir. SGK prim günü etkisi mevzuat dayanaklı yorumdur. SGK eksik gün nedeni kodu kesinleşmedi; eşleme tablosu ayrıca netleşecektir. |
| Haber vererek işe gelmedi | Firma / ürün kararı bekliyor. Haberli gelmeme otomatik eksik gün değildir; sınıflandırma gerekir. | Firma / ürün kararı bekliyor. Haberli gelmeme doğrudan otomatik ücret kesintisi değildir. | Firma / ürün kararı bekliyor. Ücretli mazeret, ücretsiz izin veya devamsızlık sınıfı netleşmeden SGK prim günü etkisi kesinleşmez. | Firma / ürün kararı bekliyor. | Evet. Haber vermek tek başına ücretli gün veya ücretsiz gün anlamına gelmez. | `Firma / ürün kararı bekliyor` | Haberli yokluk, süreçte mazeret, ücretli izin, ücretsiz izin veya devamsızlık olarak sınıflandırılmadan kesin ücret / SGK / SGK eksik gün nedeni etkisi üretilmemelidir. |
| Yıllık izin | Hayır. Ücretli izin olduğu için eksik gün sayılmaz. | Hayır. Ücret korunur. | Hayır. Prim günü korunur. | Prim / ek ödeme politikası firma kararına bağlıdır. | Genelde hayır; izin bölünme kuralı veya bakiye çelişkisi varsa evet. | `Mevzuat dayanaklı yorum` | Yıllık izin ücretli izin türüdür; yıllık izin bakiyesi ayrıca izin motorunda takip edilir. |
| Rapor / istirahat | Evet, raporlu tam günler SGK eksik gün adayıdır; kesin etki rapor tipi, süre ve bordro değerlendirmesiyle ayrıştırılır. | Firma / ürün kararı bekliyor. İşverenin raporlu gün için ücret ödeyip ödememesi firma / bordro politikasıdır. | Mevzuat dayanaklı yorum. Raporun SGK prim günü / eksik gün nedeni etkisi ücret ödeme politikasından ayrı değerlendirilir. | Firma / ürün kararı bekliyor; prim / ek ödeme devam edip etmeyeceği netleşmelidir. | Evet. Belge, rapor türü, tarih aralığı, rapor süresi, SGK karşılığı ve işveren uygulaması kontrol edilir. | `Firma / ürün kararı bekliyor` | Rapor / istirahat tekil motor kuralı değildir. SGK prim günü ve eksik gün nedeni etkisi ayrı; işveren ödeme politikası ayrı alt karardır. SGK eksik gün nedeni kodu bu satırda kesinleştirilmez. |
| Ücretsiz izin | Evet. Ücret hak edilmeyen tam gün olarak eksik gün oluşur. | Evet. | Evet. Prim günü tam gün eksik sayısına göre düşer. | Firma kuralına bağlı; çoğunlukla prim / ek ödeme hak edişi kesinti adayıdır. | Onay veya belge eksikse evet. | `Mevzuat dayanaklı yorum` | Ücretsiz izin, onaylı ve belgeli süreç kaydı olmalıdır; SGK eksik gün nedeni eşlemesi ayrıca netleşecektir. |
| Resmi tatil | Hayır. Çalışılmadıysa eksik gün oluşmaz. | Hayır, çalışılmayan resmi tatilde normal ücret korunur. | Hayır, çalışılmayan resmi tatil tek başına prim gününü düşürmez. | Bu matris kapsam dışı; resmi tatilde çalışma, UBGT ve ek ödeme etkisi ayrı puantaj / ürün kararıdır. | Genelde hayır; çalışma / çalışmama kaydı çelişkiliyse evet. | `Kesinleşmiş kural` | Kesin kapsam yalnızca çalışılmayan resmi tatilin eksik gün oluşturmaması ve normal ücretin korunmasıdır. Resmi tatilde çalışma, UBGT veya ek ödeme hesabı bu eksik gün / SGK prim günü matrisinde kesinleştirilmez. |
| Mazeretli devamsızlık | Duruma bağlı. Ücretli mazeret ise oluşmaz; ücretsiz mazeret ise oluşur. | Duruma bağlı. | Duruma bağlı. Ücret hak edilmeyen tam gün varsa etkilenir. | Firma kuralına bağlı. | Evet. Mazeretin ücretli / ücretsiz sınıfı netleşmelidir. | `Firma / ürün kararı bekliyor` | Düğün, cenaze, doğum gibi ücretli mazeret izinleri ayrı ele alınır; hangi hallerin ücretli sayılacağı firma politikasıyla netleşmelidir. |
| Disiplin cezası / ücret hak edilmeyen gün | Evet, tam gün ücret hak edilmiyorsa eksik gün oluşur. | Evet. | Evet, ücret hak edilmeyen tam gün prim gününü düşürür. | Firma kuralına bağlı; prim / ek ödeme kesintisi ayrıca belirlenmelidir. | Evet. Süreç, onay, belge ve mevzuata uygunluk kontrolü gerekir. | `Firma / ürün kararı bekliyor` | Disiplin kaydı otomatik bordro kesintisi değildir; ücret hak edilmeyen gün olarak işlenmesi için süreç ve onay matrisi net olmalıdır. |

## Ay Sonu SGK Prim Günü Hesabı

### Tam çalışma

**Durum etiketi:** `Mevzuat dayanaklı yorum`

Tam çalışma halinde ay 28, 29, 30 veya 31 çekse de SGK prim günü `30` kabul edilir.

### Eksik gün oluştuğunda güvenli formül

**Durum etiketi:** `Mevzuat dayanaklı yorum`

Bu bölümdeki formül yalnızca ücret hak edilmeyen ve SGK prim gününü düşüren tam gün eksiklikler için uygulanır.

Yıllık izin, resmi tatil, ücretli mazeret veya çalışılmış sayılan günler bu formüle otomatik sokulmaz.

Ücret hak edilmeyen tam gün eksik gün oluştuğunda prim günü hesabı `30` sabitinden değil, fiili takvim gününden türetilir.

```text
sgk_gunu = max(0, min(30, takvim_gunu - eksik_gun))
```

Bu formül yalnızca tam gün eksik gün / SGK prim günü hesabı içindir. Geç / erken dakika kesintisi için kullanılmaz.

Formül negatif sonuç üretmemelidir. `eksik_gun` bozuk, aşırı veya takvim günüyle uyumsuz gelirse motor aşamasında ayrıca güvenli validasyon gerekir.

### Örnekler

| Ay takvim günü | Ücret hak edilmeyen eksik gün | Hesap | SGK prim günü |
|---|---:|---|---:|
| `31` | `1` | `max(0, min(30, 31 - 1))` | `30` |
| `31` | `2` | `max(0, min(30, 31 - 2))` | `29` |
| `30` | `1` | `max(0, min(30, 30 - 1))` | `29` |
| `28` | `1` | `max(0, min(30, 28 - 1))` | `27` |
| `28` | `2` | `max(0, min(30, 28 - 2))` | `26` |
| `29` | `1` | `max(0, min(30, 29 - 1))` | `28` |

> **Şubat / 28 gün uyarısı:** 28 çeken Şubat'ta 1 ücret hak edilmeyen eksik gün `29` prim günü üretmez; `27` prim günü üretir. Çünkü ücret hak edilmeyen eksik gün oluştuğunda hesap `30` sabitinden değil, fiili takvim gününden yürür.

> **31 gün çeken ay uyarısı:** 31 gün çeken ayda 1 ücret hak edilmeyen eksik gün prim gününü `30` altına düşürmez. 2 ücret hak edilmeyen eksik gün olduğunda `29` prim günü oluşur.

## Karar Sınıflandırması Özeti

### Kesinleşmiş kural

- Geç / erken dakika kesintisi ile tam gün devamsızlık / eksik gün hesabı ayrı tutulur.
- Haber vermeden işe gelmeme tam gün çalışılmadıysa eksik gün ve ücret hak edilmeyen gün üretir; prim / ek ödeme, disiplin sonucu ve SGK eksik gün nedeni kodu bu kesin kapsamın dışındadır.
- Resmi tatilde çalışılmadıysa eksik gün oluşmaz ve normal ücret korunur; resmi tatilde çalışma, UBGT ve ek ödeme etkisi bu matrisin kapsamı dışındadır.

### Mevzuat dayanaklı yorum

- Tam çalışma halinde ay kaç gün çekerse çeksin SGK prim günü `30` kabul edilir.
- Eksik gün oluştuğunda SGK prim günü fiili takvim günü üzerinden `max(0, min(30, takvim_gunu - eksik_gun))` formülüyle hesaplanır.
- Haber vermeden işe gelmeme sonucu ücret hak edilmeyen tam gün oluşursa SGK prim günü tam gün eksik sayısına göre düşer.
- Yıllık izin ücretli izin olduğu için eksik gün sayılmaz ve prim günü korunur.
- Ücretsiz izin, onaylı süreç kaydıyla ücret hak edilmeyen eksik gün etkisi üretir.
- `07-Puantaj Kayıtları` özel durumu genel eksik gün / SGK prim günü motoruyla birebir aynı kural gibi okunmaz; ayrıca mevzuat / bordro kontrolü gerektirir.

### Firma / ürün kararı bekliyor

- Haber vererek işe gelmeme ücretli mazeret mi, ücretsiz izin mi, mazeretsiz devamsızlık mı sayılacak?
- Rapor durumunda işveren ödeme politikası hastalık, iş kazası, ilk günler ve tamamlayıcı ödeme senaryolarında nasıl ayrışacak?
- Prim / ek ödeme hangi senaryoda kesilecek veya korunacak?
- Haber vermeden işe gelmeme için disiplin süreci, yaptırım sonucu ve hafta tatili bağlantısı nasıl değerlendirilecek?
- Resmi tatilde çalışma, UBGT ve ek ödeme etkisi hangi puantaj / ürün kararına göre ele alınacak?
- SGK eksik gün nedeni eşleme tablosu `14-sgk-eksik-gun-nedeni-esleme-tablosu.md` üzerinden hangi ad ve kodlarla kesinleşecek?
- Firma uygulaması ile mevzuat yorumu ayrımı UI ve raporda nasıl işaretlenecek?
- Mazeretli devamsızlık hangi hallerde ücretli, hangi hallerde ücretsiz sayılacak?
- Disiplin cezası / ücret hak edilmeyen gün süreçte hangi onayla kesinleşecek?
- `07-Puantaj Kayıtları` özel durumu ayrı motor / karar gerektiriyor mu?

## Açık Ürün Kararları

- Haber vererek işe gelmeme ücrette nasıl ele alınacak?
- Rapor durumunda işveren ödeme politikası hangi senaryoda nasıl ayrışacak?
- Prim / ek ödeme hangi senaryoda kesilecek?
- SGK eksik gün nedeni eşleme tablosu `14-sgk-eksik-gun-nedeni-esleme-tablosu.md` üzerinden nasıl netleşecek?
- Firma uygulaması ile mevzuat yorumu ayrımı nasıl işaretlenecek?
- Mazeretli devamsızlık hangi hallerde ücretli, hangi hallerde ücretsiz sayılacak?
- Disiplin cezası / ücret hak edilmeyen gün süreçte nasıl onaylanacak?
- `07-Puantaj Kayıtları` özel durumu ayrı motor / karar gerektiriyor mu?

## Uygulama Sınırı

Bu faz yalnızca karar dokümanı fazıdır.

- Kod yazılmaz.
- Hook / page davranışı değiştirilmez.
- Geç / erken 30 dakika yukarı yuvarlama kuralı değiştirilmez.
- Vardiya / çalışma planı modeli açılmaz.
- Muhasebe motoru implementasyonu yapılmaz.
- Test yazılmaz.
- `07-Puantaj Kayıtları` özel motoru tasarlanmaz.

## Belge Geçmişi

| Tarih | Not |
|---|---|
| 2026-05-15 | Puantaj V2 için eksik gün, SGK prim günü ve senaryo karar matrisi eklendi. |
