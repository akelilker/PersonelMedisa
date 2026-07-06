# Medisa Personel ve Puantaj Yönetim Sistemi

## State Flow + API Contract

Sürüm: `V1`

## Belgenin Amacı

Bu doküman, sistemdeki kayıtların yaşam döngüsünü ve ekranlar ile backend arasındaki veri sözleşmesini tanımlar.

Bu belge şu sorulara cevap verir:

- hangi kayıt hangi state'lerden geçer?
- hangi ekran hangi endpoint'i çağırır?
- create / update / cancel / close işlemleri nasıl çalışır?
- hangi veri ne zaman kilitlenir?
- frontend hangi alanları gönderir, backend hangi alanları döner?

## Temel İlke

Bu projede veri akışı şu prensiple kurulur:

- frontend görünüm ve kullanıcı etkileşimini yönetir
- backend iş kuralını, validasyonu ve state geçişini yönetir

Kural:

- frontend hiçbir kaydı “gerçekten aktif oldu” varsayımıyla kendi kendine karar vermez
- state'in tek sahibi backend'dir

## V1 Kararı

İlk sürümde varsayılan veri yaşam döngüsü sade tutulur:

- `taslak` katmanı yok
- `çok aşamalı onay` akışı yok
- kayıt başarıyla validasyondan geçtiği anda aktif veri olarak sisteme yazılır

İstisnalar:

- `haftalik_kapanis` mühür mantığı taşır
- gelecekte eklenecek bordro kapanışı daha sert kilit mantığı taşıyabilir

## 1. Ortak API Sözleşmesi

### 1.1 Format

- API `JSON` konuşur
- tarih alanları `YYYY-MM-DD`
- saat alanları `HH:mm`
- datetime alanları `ISO 8601`
- para ve süre hesaplarında backend sayısal ham değeri döner, frontend sadece gösterim formatı uygular

### 1.2 Başarı Yanıtı

Önerilen temel yanıt yapısı:

```json
{
  "data": {},
  "meta": {},
  "errors": []
}
```

### 1.3 Hata Yanıtı

Önerilen hata yapısı:

```json
{
  "data": null,
  "meta": {},
  "errors": [
    {
      "code": "VALIDATION_ERROR",
      "field": "tc_kimlik_no",
      "message": "T.C. Kimlik No 11 hane olmalıdır."
    }
  ]
}
```

### 1.4 HTTP Durum Kodları

- `200` başarılı okuma veya güncelleme
- `201` yeni kayıt oluşturma
- `400` bozuk istek
- `401` oturum yok
- `403` yetki yok
- `404` kayıt yok
- `409` iş kuralı çakışması / kilitli dönem
- `422` validasyon hatası
- `500` beklenmeyen sistem hatası

## 2. Kimlik ve Yetki Katmanı

### 2.1 Login

Temel giriş endpoint'i:

- `POST /api/auth/login`

İstek:

```json
{
  "username": "ornek",
  "password": "******"
}
```

Yanıt:

```json
{
  "data": {
    "token": "jwt-veya-oturum-anahtari",
    "user": {
      "id": 12,
      "ad_soyad": "Örnek Kullanıcı",
      "rol": "GENEL_YONETICI"
    },
    "ui_profile": "yonetim"
  },
  "meta": {},
  "errors": []
}
```

### 2.2 Rol Davranışı

Backend her istek için:

- kullanıcının rolünü
- ilgili kaynağa erişim yetkisini
- bölüm kısıtını

kontrol eder.

Örnek:

- bölüm yöneticisi başka bölüm personelini göremez
- `BIRIM_AMIRI` rolündeki kullanıcı tam personel kartı API'sini sınırsız kullanamaz

## 3. Mantıksal Kaynaklar

Bu belgede API düzeyinde aşağıdaki kaynaklar kullanılır:

- `personeller`
- `surecler`
- `bildirimler`
- `gunluk-puantaj`
- `haftalik-kapanis`
- `ek-odeme-kesinti`
- `referans-veriler`
- `raporlar`

Not:

- fiziksel veritabanında bir kısmı farklı tablo veya görünüm üzerinden çözülebilir
- bu belge mantıksal API sözleşmesini tarif eder

## 4. Personel Kaydı State Flow

### 4.1 State Modeli

Personel ana kartı için V1 state'leri:

- `AKTIF`
- `PASIF`

İlk sürümde `TASLAK` yoktur.

### 4.2 Oluşturma Akışı

Ekran:

- `Yeni Personel Ekle` modalı

Endpoint:

- `POST /api/personeller`

İstek örneği:

```json
{
  "tc_kimlik_no": "12345678901",
  "ad": "Ahmet",
  "soyad": "Yılmaz",
  "dogum_tarihi": "1990-01-15",
  "telefon": "05551234567",
  "acil_durum_kisi": "Ayşe Yılmaz",
  "acil_durum_telefon": "05557654321",
  "sicil_no": "P-00124",
  "ise_giris_tarihi": "2026-04-01",
  "departman_id": 3,
  "gorev_id": 8,
  "personel_tipi_id": 2,
  "aktif_durum": "AKTIF",
  "dogum_yeri": "İstanbul",
  "kan_grubu": "A Rh+",
  "bagli_amir_id": 44,
  "net_maas_tutari": 35000
}
```

Notlar (S62A maaş kontratı):

- Kullanıcı maaşı **net** olarak girer; `net_maas_tutari` canonical alandır.
- `brut_maas_tutari`, `brut_hesaplama_modeli`, `brut_hesaplama_donemi` (veya `model_versiyonu`) yanıtta salt okunur döner; create/update isteğinde gönderilmez.
- Mevcut `maas_tutari` semantik olarak belirsizdir; yeni bordro altyapısında tek başına ana alan olmamalıdır.
- Netten brüte hesap motoru bu sprintte implemente edilmez.

Başarılı sonuç:

- personel kartı oluşturulur
- state `AKTIF` veya istekten gelen aktiflik durumuna göre yazılır
- backend kayıt `id` ve normalize edilmiş alanları döner

### 4.3 Validasyonlar

Backend minimum olarak şunları kontrol eder:

- `tc_kimlik_no` 11 hane
- `ad` zorunlu
- `soyad` zorunlu
- `dogum_tarihi` zorunlu
- `telefon` zorunlu
- `acil_durum_kisi` zorunlu
- `acil_durum_telefon` zorunlu
- `ise_giris_tarihi` zorunlu
- `departman_id` geçerli
- `gorev_id` geçerli
- `personel_tipi_id` geçerli

### 4.4 Güncelleme Akışı

Endpoint:

- `PUT /api/personeller/{personelId}`

Kural:

- personel ana kartı düzenlenebilir
- ancak hesaplanan alanlar bu endpoint ile güncellenemez
- `brut_maas_tutari` ve türev iz alanları backend tarafından üretilir; istemci yazamaz
- `aktif_durum` doğrudan elle değiştirilebilir olsa bile, işten ayrılma senaryosunda ana yol `süreç` tarafıdır

### 4.5 Listeleme

Endpoint:

- `GET /api/personeller`

Temel query parametreleri:

- `search`
- `departman_id`
- `aktiflik=aktif|pasif|tum`
- `personel_tipi_id`
- `page`
- `limit`

## 5. Personel Kartı Okuma Contract'ı

### 5.1 Detay Ekranı

Endpoint:

- `GET /api/personeller/{personelId}`

Yanıt en az şu blokları içermelidir:

- `ana_kart`
- `sistem_ozeti`
- `pasiflik_durumu`
- `referans_adlari`

Örnek yanıt:

```json
{
  "data": {
    "ana_kart": {},
    "sistem_ozeti": {
      "hizmet_suresi": "2 yıl 3 ay",
      "toplam_izin_hakki": 14,
      "kullanilan_izin": 5,
      "kalan_izin": 9
    },
    "pasiflik_durumu": {
      "aktif_durum": "AKTIF",
      "etiket": null
    }
  },
  "meta": {},
  "errors": []
}
```

### 5.2 Kart Sekmeleri

Personel kartı ekranı, veriyi tek endpoint'ten veya sekme bazlı endpoint'lerden alabilir.
V1 için önerilen sekme bazlı okuma:

- `GET /api/personeller/{id}`
- `GET /api/personeller/{id}/surecler`
- `GET /api/personeller/{id}/izin-ozeti`
- `GET /api/personeller/{id}/notlar`

## 6. Süreç Kaydı State Flow

### 6.1 State Modeli

V1 süreç state'leri:

- `AKTIF`
- `IPTAL`

İlk sürümde `TASLAK`, `ONAY_BEKLIYOR`, `REDDEDILDI` yoktur.

### 6.2 Oluşturma

Endpoint:

- `POST /api/surecler`

İstek örneği:

```json
{
  "personel_id": 120,
  "surec_turu": "IZIN",
  "alt_tur": "YILLIK_IZIN",
  "baslangic_tarihi": "2026-04-10",
  "bitis_tarihi": "2026-04-12",
  "ucretli_mi": true,
  "aciklama": "Yıllık izin"
}
```

Hastalık raporu örneği (S62A):

```json
{
  "personel_id": 120,
  "surec_turu": "RAPOR",
  "alt_tur": "Raporlu_Hastalik",
  "baslangic_tarihi": "2026-04-10",
  "bitis_tarihi": "2026-04-14",
  "ilk_iki_gun_firma_oder_mi": false,
  "aciklama": "Hastalık raporu"
}
```

- `ilk_iki_gun_firma_oder_mi` yalnızca `Raporlu_Hastalik` için geçerlidir; varsayılan `false`.
- `Raporlu_Is_Kazasi` bu alanı taşımaz; ayrı değerlendirilir.
- Alan rapor event / periyot kaydında tutulur; günlük puantaj satırına boolean olarak dağıtılmaz.

Backend davranışı:

- veri validasyonu yapılır
- iş kuralı hesapları tetiklenir
- kayıt `AKTIF` state ile oluşur

### 6.3 Güncelleme

Endpoint:

- `PUT /api/surecler/{surecId}`

Kurallar:

- `IPTAL` olmuş süreç doğrudan düzenlenmez
- haftalık kapanışla mühürlenmiş döneme ait süreç düzenlenemez
- düzenleme mümkünse backend yeniden hesap tetikler

### 6.4 İptal

Endpoint:

- `POST /api/surecler/{surecId}/iptal`

Davranış:

- kayıt fiziksel olarak silinmez
- state `IPTAL` olur
- hesap motoru etkisi geri alınır
- audit trail korunur

### 6.5 Listeleme

Endpoint:

- `GET /api/surecler`

Temel query parametreleri:

- `personel_id`
- `surec_turu`
- `baslangic_tarihi`
- `bitis_tarihi`
- `state`
- `departman_id`

## 7. İşten Ayrılma Süreci Contract'ı

### 7.1 Kural

`İşten Ayrılma` doğrudan personel kartı alanı değildir.
Bu işlem süreç kaydı olarak çalışır.

### 7.2 Endpoint

- `POST /api/surecler`

`surec_turu = ISTEN_AYRILMA`

### 7.3 State Etkisi

V1 kararı:

- işten ayrılma süreci başarıyla kaydedildiği anda personel ana kartı `PASIF` olur
- bu davranış ayrıca onay beklemez

Backend aynı transaction içinde şunları yapar:

1. süreç kaydını oluşturur
2. personel kartını `PASIF` yapar
3. pasiflik etiketini üretir

### 7.4 Dönüş

Yanıt içinde şu bilgi açıkça dönmelidir:

```json
{
  "data": {
    "surec_id": 881,
    "personel_id": 120,
    "personel_yeni_durum": "PASIF",
    "pasiflik_etiketi": "İŞTEN AYRILDI"
  }
}
```

## 8. Bildirim Kaydı State Flow

### 8.1 V1 Kararı

Bildirim modülü, süreç modülünden ayrıdır.

İlk sürüm kararı:

- `bildirim` kaydı otomatik olarak `süreç` kaydına dönüşmez
- bildirim günlük veri toplama alanı olarak çalışır

İleride manuel dönüştürme veya otomatik eşleme eklenebilir.

### 8.2 State Modeli

V1 bildirim state'leri:

- `AKTIF`
- `IPTAL`

### 8.3 Oluşturma

Endpoint:

- `POST /api/bildirimler`

İstek örneği:

```json
{
  "tarih": "2026-04-05",
  "departman_id": 3,
  "personel_id": 120,
  "bildirim_turu": "GEC_GELDI",
  "aciklama": "09:20 giriş yaptı"
}
```

### 8.4 Listeleme

Endpoint:

- `GET /api/bildirimler`

Query:

- `tarih`
- `departman_id`
- `personel_id`
- `bildirim_turu`

### 8.5 Güncelleme ve İptal

Endpoint'ler:

- `PUT /api/bildirimler/{bildirimId}`
- `POST /api/bildirimler/{bildirimId}/iptal`

Kural:

- iptal edilen bildirim fiziksel silinmez
- rapor ve audit katmanında iz bırakır

### Okunma (`okundu_mi`)

Kayıt modeli V1’de kullanıcı bazlı okunma bilgisini `okundu_mi` (boolean) ile taşır; varsayılan `false`, iptal kayıtlarında liste/üst bildirim politikasına göre hariç tutulabilir.

Okundu işaretlemek için aynı güncelleme uç noktası kullanılır:

- `PUT /api/bildirimler/{bildirimId}` gövde örneği: `{ "okundu_mi": true }` (kısmi güncelleme; yalnızca okunma alanını yazmak yeterlidir)

Header bildirim paneli ve liste görünümleri bu alana göre okunmamış / okunmuş ayrımı yapar. Takvim hatırlatmaları (maaş / SGK) gerçek `bildirim` kaydı değildir; yalnızca istemci tarafında geçici olarak okundu kabul edilir.

## 9. Günlük Puantaj State Flow

### 9.1 State Modeli

Günlük puantaj kaydı için önerilen state'ler:

- `ACIK`
- `HESAPLANDI`
- `MUHURLENDI`

### 9.2 Oluşturma / Güncelleme

Endpoint:

- `PUT /api/gunluk-puantaj/{personelId}/{tarih}`

Bu endpoint:

- aynı gün için `upsert` mantığında çalışır
- giriş/çıkış verisini yazar
- günlük hesapları backend'de tetikler

İstek örneği:

```json
{
  "giris_saati": "08:05",
  "cikis_saati": "18:10",
  "gercek_mola_dakika": 60
}
```

### 9.3 Okuma

Endpoint:

- `GET /api/gunluk-puantaj/{personelId}/{tarih}`

Yanıt:

- ham kayıt
- hesaplanan mola
- net çalışma süresi
- compliance uyarıları

### 9.4 Kilit Kuralı

- haftası kapanmış puantaj `MUHURLENDI` sayılır
- mühürlü güne doğrudan yazılamaz
- backend `409 PERIOD_LOCKED` döner

## 10. Haftalık Kapanış State Flow

### 10.1 Amaç

Haftalık kapanış, haftanın özetini mühürleyen işlemdir.
Bu işlem sadece rapor üretmek için değil, veri bütünlüğü için vardır.

### 10.2 Endpoint

- `POST /api/haftalik-kapanis`

İstek:

```json
{
  "hafta_baslangic": "2026-04-06",
  "hafta_bitis": "2026-04-12",
  "departman_id": 3
}
```

### 10.3 Backend Akışı

Backend bu işlemde:

1. ilgili haftanın günlük puantajlarını toplar
2. süreç kayıtlarını bindirir
3. hafta tatili hakkını hesaplar
4. fazla çalışma / fazla sürelerle çalışma değerlerini çıkarır
5. snapshot üretir
6. haftayı `KAPANDI` olarak işaretler

### 10.4 State Modeli

Önerilen state'ler:

- `ACIK`
- `KAPANDI`

V1 kararı:

- kapanan hafta normal kullanıcı için yeniden açılmaz
- yeniden açma gerekiyorsa sonraki fazda admin akışı tasarlanır

## 11. Ek Ödeme / Kesinti Contract'ı

### 11.1 Kaynak

Prim, ceza, avans ve benzeri kalemler ana kartın değil dönemsel finans katmanının parçasıdır.

### 11.2 Endpoint'ler

- `POST /api/ek-odeme-kesinti`
- `PUT /api/ek-odeme-kesinti/{id}`
- `GET /api/ek-odeme-kesinti`
- `POST /api/ek-odeme-kesinti/{id}/iptal`

### 11.3 Minimum İstek

```json
{
  "personel_id": 120,
  "donem": "2026-04",
  "kalem_turu": "AVANS",
  "tutar": 2500,
  "aciklama": "Nakit avans"
}
```

## 12. Referans Veri Contract'ı

İlk sürümde aşağıdaki referans veriler ayrı endpoint ile okunmalıdır:

- `GET /api/referans/departmanlar`
- `GET /api/referans/gorevler`
- `GET /api/referans/personel-tipleri`
- `GET /api/referans/bagli-amirler`
- `GET /api/referans/bildirim-turleri`
- `GET /api/referans/surec-turleri`

Kural:

- form açıldığında dropdown'lar hardcode ile doldurulmaz
- referans veri API'den gelir

## 13. Rapor Ekranı Contract'ı

### 13.1 Temel Endpoint'ler

- `GET /api/raporlar/personel-ozet`
- `GET /api/raporlar/izin`
- `GET /api/raporlar/devamsizlik`
- `GET /api/raporlar/tesvik`
- `GET /api/raporlar/ceza`
- `GET /api/raporlar/ekstra-prim`
- `GET /api/raporlar/is-kazasi`
- `GET /api/raporlar/bildirim`

### 13.2 Sorgu Parametreleri

Rapor endpoint'leri en az şu filtreleri taşımalıdır:

- `personel_id`
- `departman_id`
- `baslangic_tarihi`
- `bitis_tarihi`
- `aktiflik`
- `page`
- `limit`

Haftalık kapanış snapshot ilişkisi için ek sorgu parametreleri §13.2.1–§13.2.3 altında tanımlanır. Bu alt maddeler **§13.5 owner karar matrisi (2026-06-25) ile kilitlenmiştir** ve uygulama kontratı sayılır.

#### 13.2.1 Haftalık kapanış batch filtresi — `kapanis_id`

**[KARAR ALINDI — 1]** Evet. Liste raporları, haftalık kapanış batch'i için `kapanis_id` query param ile filtrelenecektir.

- `kapanis_id`, `POST /api/haftalik-kapanis` yanıtındaki kapanış işlemi kimliğini temsil eder.
- Yanıtta yalnızca `id` dönerse, istemci tarafında `kapanis_id` ile eş anlamlı kabul edilebilir (bkz. `docs/guncel/39-haftalik-kapanis-snapshot-sozlesmesi-karar.md`).
- Bir `kapanis_id`, ilgili hafta ve kapsam için N adet personel snapshot satırını (`snapshot_id`) kapsar.

| Alan | Tip | Zorunluluk | Açıklama |
|------|-----|------------|----------|
| `kapanis_id` | positive integer | opsiyonel | Haftalık kapanış batch kimliği |

**Not:** Mevcut frontend bu param'ı henüz göndermemektedir; kod fazı ayrı talimatla açılabilir (bkz. `docs/guncel/64-haftalik-kapanis-raporlar-bekleyen-kararlar-devir-notu.md`).

#### 13.2.2 Satır referansı — `snapshot_id`

**[KARAR ALINDI — 2]** Hayır. `snapshot_id` genel liste raporu filtresi olmayacak; yalnızca satır/detay referansı olarak kalacaktır.

| Kullanım | `snapshot_id` rolü |
|----------|-------------------|
| Liste raporu (`GET /api/raporlar/*`) | Genel liste filtresi değildir |
| Tek personel daraltma | `kapanis_id` + `personel_id` yeterlidir |
| Satır/detay referansı | FM ödeme tercihi, serbest zaman, audit gibi diğer domain endpoint'lerinde |

Rapor endpoint'lerinde opsiyonel `snapshot_id` filtresi tanımlanmayacaktır. İleride owner aksini onaylarsa whitelist §13.4'te açıkça listelenmelidir.

#### 13.2.3 `kapanis_id` ile tarih filtrelerinin birlikte kullanımı

**[KARAR ALINDI — 4]** `kapanis_id` gönderildiğinde kapanış kaydındaki `hafta_baslangic` ve `hafta_bitis` değerleri **authoritative** kabul edilir.

1. Backend, kapanış haftasını tek kaynak olarak uygular.
2. İstemci tarih filtrelerini bilgilendirme amaçlı gönderebilir; backend bunları kapanış haftası ile hizalayabilir veya yok sayabilir.
3. İstemci tarafından gönderilen tarih aralığı kapanış haftası ile çakışmıyorsa backend `400 INVALID_QUERY` döner.

| Senaryo | Backend davranışı |
|---------|-------------------|
| Yalnız `kapanis_id` | Kapanış haftası kapsamında rapor döner |
| `kapanis_id` + uyumlu tarih | Kapanış haftası uygulanır; tarih redundant kabul edilir |
| `kapanis_id` + uyumsuz tarih | `400 INVALID_QUERY` |
| Tarih var, `kapanis_id` yok | Mevcut canlı/read-model filtre davranışı (§13.3) |

### 13.3 Veri Kaynağı

Rapor ekranı tek tablodan beslenmek zorunda değildir.
Backend gerektiğinde:

- personeller
- surecler
- gunluk puantaj
- haftalik kapanis
- ek odeme kesinti

katmanlarını birleştirerek rapor DTO'su döner.

#### 13.3.1 `kapanis_id` varken veri kaynağı

**[KARAR ALINDI — 5]** `kapanis_id` filtresi aktifken rapor **mühürlü snapshot** döner; canlı read-model kullanılmaz.

| Koşul | Veri kaynağı | Açıklama |
|-------|--------------|----------|
| `kapanis_id` var | Mühürlü snapshot | Kapanış anında üretilen personel × hafta satırları |
| `kapanis_id` yok | Canlı read-model | Mevcut puantaj/süreç/finans birleşimi; geriye dönük uyumluluk |

Zorunlu `meta` izi:

```json
{
  "meta": {
    "kaynak": "SNAPSHOT",
    "kapanis_id": 99,
    "hafta_baslangic": "2026-04-06",
    "hafta_bitis": "2026-04-12"
  }
}
```

`kapanis_id` yokken zorunlu değer: `"kaynak": "LIVE"`.

#### 13.3.2 Correction overlay

**[KARAR ALINDI — 6]** Evet, correction overlay bu fazda **kapsam dışıdır**.

- Bu fazda `kapanis_id` ile dönen raporlar ham mühürlü snapshot verisini yansıtır.
- ONAYLANDI revizyon correction overlay'i (`docs/guncel/60-revizyon-talebi-correction-layer-karar.md` §7–8) sonraki fazda ele alınır.
- Düzeltilmiş operasyon görünümü için ileride aday endpoint: `GET /api/raporlar/haftalik-kapanis-duzeltilmis` (`docs/guncel/50-kapali-donem-audit-workflow-karar.md` §14). Bu kontrat fazında implementasyon hedefi değildir.

### 13.4 Endpoint whitelist karar tablosu

**[KARAR ALINDI — 3]** Aşağıdaki `/api/raporlar/*` endpoint whitelist'i uygulama kontratıdır.

| Endpoint | `kapanis_id` | Gerekçe |
|----------|--------------|---------|
| `GET /api/raporlar/personel-ozet` | Evet | Haftalık puantaj özeti |
| `GET /api/raporlar/devamsizlik` | Evet | Kapanış snapshot devamsızlık türevi |
| `GET /api/raporlar/izin` | Evet | Süreç + kapanış birleşimi |
| `GET /api/raporlar/bildirim` | Evet | Günlük kayıt türevi |
| `GET /api/raporlar/is-kazasi` | Hayır | Haftalık kapanış snapshot'ında iş kazası türevi alan yok |
| `GET /api/raporlar/tesvik` | Hayır | Dönemsel finans katmanı |
| `GET /api/raporlar/ceza` | Hayır | Dönemsel finans katmanı |
| `GET /api/raporlar/ekstra-prim` | Hayır | Dönemsel finans katmanı |

**[KARAR ALINDI — 7]** Whitelist dışı endpoint'e `kapanis_id` gönderilirse backend **strict** modda `400 UNSUPPORTED_FILTER` döner. Param yok sayılmaz; canlı read-model'e düşülmez.

Hata kodları:

| Kod | HTTP | Koşul |
|-----|------|-------|
| `INVALID_QUERY` | 400 | `kapanis_id` geçersiz format |
| `KAPANIS_NOT_FOUND` | 404 | `kapanis_id` bulunamadı |
| `KAPANIS_OUT_OF_SCOPE` | 400 | `personel_id` veya `departman_id` kapanış kapsamı dışında |
| `UNSUPPORTED_FILTER` | 400 | Endpoint whitelist dışında `kapanis_id` |

### 13.5 Owner karar matrisi

**Karar tarihi:** 2026-06-25
**Durum:** 8/8 madde kilitlendi. Aşağıdaki kararlar uygulama kontratıdır; kod/mock/type fazı bu matrise göre açılabilir.

| # | Karar sorusu | Karar | Onay |
|---|--------------|-------|------|
| 1 | Batch filtresi `kapanis_id`? | Evet; liste raporları için birincil batch filtresi | ☑ |
| 2 | `snapshot_id` liste filtresi? | Hayır; satır/detay referansı | ☑ |
| 3 | Destekleyen endpoint'ler? | §13.4 whitelist (puantaj-türevi alt küme) | ☑ |
| 4 | `kapanis_id` + tarih çakışması? | Kapanış authoritative; uyumsuz tarih → `400 INVALID_QUERY` | ☑ |
| 5 | `kapanis_id` varken veri kaynağı? | Mühürlü snapshot; `meta.kaynak = SNAPSHOT` | ☑ |
| 6 | Correction overlay bu fazda? | Kapsam dışı; ham snapshot yeterli | ☑ |
| 7 | Whitelist dışı `kapanis_id`? | Strict → `400 UNSUPPORTED_FILTER` | ☑ |
| 8 | Query param vs dedicated endpoint? | Faz 1: query param; dedicated endpoint sonraki faz | ☑ |

İlişkili dokümanlar:

- `docs/guncel/39-haftalik-kapanis-snapshot-sozlesmesi-karar.md` — ID modeli, snapshot alanları
- `docs/guncel/50-kapali-donem-audit-workflow-karar.md` — düzeltilmiş görünüm adayı endpoint
- `docs/guncel/60-revizyon-talebi-correction-layer-karar.md` — overlay modeli (sonraki faz)
- `docs/guncel/64-haftalik-kapanis-raporlar-bekleyen-kararlar-devir-notu.md` — koda taşınmayı bekleyen kararlar ve kod fazı kırmızı çizgisi

### 13.6 Kapsam dışı

Bu kontrat fazında hedeflenmeyenler:

- Correction overlay uygulanmış rapor görünümü (§13.5 madde 6 — sonraki faz)
- `GET /api/raporlar/haftalik-kapanis-duzeltilmis` implementasyonu
- Haftalık kapanış UI ekranı ve kapanış → rapor navigasyon akışı (ayrı frontend fazı)
- `snapshot_id` genel liste filtresi (§13.5 madde 2 — Hayır)

**Not:** `kapanis_id` query param desteği mock/type/API kod fazında §13.5 kararlarına göre eklenebilir; bu maddeler kapsam dışı değildir.

## 14. Ekran -> Endpoint Haritası

### 14.1 Yeni Personel Ekle

- form açılışında: referans veri endpoint'leri
- kaydette: `POST /api/personeller`

### 14.2 Personel Liste

- `GET /api/personeller`

### 14.3 Personel Kartı

- `GET /api/personeller/{id}`
- `GET /api/personeller/{id}/surecler`

### 14.4 Süreç Takibi

- liste için: `GET /api/surecler`
- ekle için: `POST /api/surecler`
- düzenle için: `PUT /api/surecler/{id}`
- iptal için: `POST /api/surecler/{id}/iptal`

### 14.5 Bildirimler

- liste için: `GET /api/bildirimler`
- ekle için: `POST /api/bildirimler`
- düzenle için: `PUT /api/bildirimler/{id}`
- iptal için: `POST /api/bildirimler/{id}/iptal`

### 14.6 Günlük Puantaj

- gün kaydı için: `PUT /api/gunluk-puantaj/{personelId}/{tarih}`
- gün okuma için: `GET /api/gunluk-puantaj/{personelId}/{tarih}`

### 14.7 Haftalık Kapanış

- `POST /api/haftalik-kapanis`

### 14.8 Raporlar

- ilgili `GET /api/raporlar/*` endpoint'leri

## 15. Kilit ve Düzenleme Kuralları

V1 kilit kuralları:

- kapanmış hafta içindeki puantaj düzenlenemez
- kapanmış haftayı etkileyen süreç düzenlemesi `409` döner
- iptal işlemi fiziksel silme yerine state değişimi ile yapılır
- işten ayrılma sonrası personel aktif listeden düşer

## 16. Backend'in Zorunlu Olarak Yaptığı Şeyler

Backend sadece veriyi kaydetmez; ayrıca:

- referans id doğrulaması yapar
- role göre yetki kontrolü yapar
- state geçişini uygular
- hesap motorunu tetikler
- read model veya snapshot üretir
- audit izi bırakır

## 17. Frontend'in Yapmaması Gereken Şeyler

Frontend aşağıdakileri kendi başına yapmaz:

- personeli pasife düştü varsayımıyla sadece local state güncellemek
- fazla mesaiyi client tarafında hesaplamak
- hafta kapanışını görsel buton state'i ile “kapandı” saymak
- referans verileri hardcode etmek
- state geçişini yalnızca buton rengine bakarak tahmin etmek

## 18. V1'de Özellikle Netleştirilen Kararlar

Bu belge ile aşağıdaki kritik kararlar sabitlenmiştir:

- V1'de `taslak/onay` akışı yoktur
- `bildirim`, `süreç`e otomatik dönüşmez
- `işten ayrılma` kaydı personeli anında `PASIF` yapar
- `iptal` fiziksel silme değildir
- `haftalik_kapanis` haftayı mühürler
- referans veriler API üzerinden gelir

## 19. V1 Dışı Ama Sonraki Faz İçin Açık Bırakılan Konular

- süreç onay akışı
- bildirimden süreç üretme
- haftalık kapanışı geri açma
- bordro dönemi kapanışı
- detaylı audit ekranı
- webhook / event bus yapısı

## 20. Sonuç

Bu belge, sistemin veri damar haritasıdır.

Şu işi yapar:

- hangi ekranın ne çağırdığını netleştirir
- hangi kaydın hangi state'ten geçtiğini tanımlar
- frontend ile backend arasında yanlış varsayımı azaltır

Bu belge sonrası istersek iki yoldan ilerleyebiliriz:

- gerçek proje klasör yapısı ve teknik scaffold
- ya da ekran bazlı görev kırılımı
