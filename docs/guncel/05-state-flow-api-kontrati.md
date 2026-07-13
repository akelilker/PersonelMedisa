# Medisa Personel ve Puantaj Yönetim Sistemi

## State Flow + API Contract

Sürüm: `V2` (Ürün Reset — S70A)

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

## Ürün Reset Kararı (S70A)

Ürün reset sonrası veri yaşam döngüsü çok aşamalı onay zinciri taşır. Personel ve süreç gibi temel kayıtlar hızlı yazılır; puantaj, mutabakat, aylık onay ve bordro katmanları sıralı state guard ile ilerler.

### Eski V1 kararı (tarihsel — arşiv)

Önceki V1 kararında “taslak katmanı yok”, “çok aşamalı onay akışı yok” ve “kayıt validasyondan geçince anında aktif” ifadeleri kullanılmıştı. Ürün reset ile bu yaklaşım **personel/süreç temel kayıtları** için korunur; **günlük bildirim, haftalık mutabakat, aylık onay ve bordro** katmanları için geçerli değildir.

### Kritik state guard kuralları

Backend aşağıdaki geçişleri zorunlu kılar:

| Kural | Davranış |
|-------|----------|
| Haftalık mutabakat | `MUTABAKAT_TAMAMLANDI` olmadan aylık bölüm onayı verilemez → `409 APPROVAL_CHAIN_BLOCKED` |
| Bölüm onayı | Tüm ilgili satırlar `BOLUM_ONAYLANDI` olmadan genel yönetici onayı verilemez → `409 APPROVAL_CHAIN_BLOCKED` |
| Genel yönetici onayı | `GENEL_YONETICI_ONAYLANDI` olmadan nihai bordro oluşmaz → `409 PAYROLL_NOT_APPROVED` |
| Patron ack | `GORULDU` / `NOT_EKLENDI` bordro üretimini bloklamaz |
| Manuel inceleme | Açık `MANUEL_INCELEME` kayıtları çözülmeden bordro kesinleşmez → `409 MANUAL_REVIEW_PENDING` |
| Şirket parametresi | Zorunlu parametre eksikse hesap/bordro kesinleşmez → `409 COMPANY_PARAM_MISSING` |

### Onay zinciri özeti

```text
Günlük Bildirim (BIRIM_AMIRI)
  -> Haftalık Mutabakat (BOLUM_YONETICISI)
  -> Teknik Kapanış (sistem)
  -> Aylık Bölüm Onayı (BOLUM_YONETICISI)
  -> Genel Yönetici Onayı (GENEL_YONETICI)
  -> Bordro Ön İzleme / Kesinleştirme
  -> Patron Ack (sembolik, paralel veya sonrası — bloklamaz)
```

Bu özet hedef ürün zinciridir. Güncel uygulanmış S70C-S73 bildirim zincirinin owner ve endpoint kontratı aşağıda ayrıca tanımlanmıştır.

## Güncel Bildirim State Flow — S70C-S73

Bu bölüm 12.07.2026 tarihindeki çalışan kod kontratını kaydeder. Bildirim onay zinciri; puantaj teknik kapanışı, legacy aylık özet ve bordro kesinleştirme domain'lerinden ayrıdır.

### Günlük bildirim

DB sahibi: `gunluk_bildirimler`; write sahibi: `BIRIM_AMIRI`.

Çalışan state'ler: `TASLAK`, `GONDERILDI`, `DUZELTME_ISTENDI`, `HAFTALIK_MUTABAKATA_ALINDI`, `IPTAL`.

| İşlem | Endpoint | Permission |
|---|---|---|
| Liste | `GET /bildirimler` | `bildirimler.view` ve scope guard |
| Detay | `GET /bildirimler/{id}` | `bildirimler.detail.view` |
| Oluştur | `POST /bildirimler` | `gunluk_bildirim.create` |
| Güncelle | `PUT /bildirimler/{id}` | `gunluk_bildirim.update_own_open` |
| Gönder | `POST /bildirimler/{id}/submit` | `gunluk_bildirim.submit` |
| Düzeltme iste | `POST /bildirimler/{id}/request-correction` | `gunluk_bildirim.request_correction` |
| İptal | `POST /bildirimler/{id}/iptal` | `gunluk_bildirim.update_own_open` |

`BIRIM_AMIRI` yalnız kendi açık kaydını güncelleyebilir. Yönetim rolü uygun `GONDERILDI` kaydı için düzeltme isteyebilir. Geçersiz state geçişleri `409` döner.

### Haftalık bildirim mutabakatı

DB sahibi: `haftalik_bildirim_mutabakatlari`; approve sahibi: `BIRIM_AMIRI`.

| İşlem | Endpoint | Permission |
|---|---|---|
| Özet | `GET /haftalik-bildirim-mutabakatlari/ozet` | `haftalik_mutabakat.view` |
| Approve | `POST /haftalik-bildirim-mutabakatlari` | `haftalik_mutabakat.approve` |
| Detay | `GET /haftalik-bildirim-mutabakatlari/{id}` | `haftalik_mutabakat.view` |

- Başarılı mutabakat kaydı `TAMAMLANDI` state'i taşır.
- Bağlanan günlük kayıtlar `HAFTALIK_MUTABAKATA_ALINDI` state'ine geçer.
- Aynı şube, Birim Amiri ve hafta için tekrar approve `409` döner.
- `BOLUM_YONETICISI`, `GENEL_YONETICI` ve `MUHASEBE` paneli permission üzerinden salt okunur görür; approve sahibi değildir.

### Aylık bildirim onayı

DB sahibi: `aylik_bildirim_onaylari`; approve sahibi: `BIRIM_AMIRI`.

| İşlem | Endpoint | Permission |
|---|---|---|
| Özet | `GET /aylik-bildirim-onaylari/ozet` | `aylik_bildirim_onayi.view` |
| Approve | `POST /aylik-bildirim-onaylari` | `aylik_bildirim_onayi.approve` |
| Detay | `GET /aylik-bildirim-onaylari/{id}` | `aylik_bildirim_onayi.view` |

- Ay formatı `YYYY-MM` biçimindedir.
- Ay tarih aralığı gerçek takvim ayının ilk ve son günüdür. Örnek: `2026-07` için `2026-07-01 / 2026-07-31`.
- Başarılı aylık bildirim onayı `TAMAMLANDI` state'i taşır.
- Eksik veya mutabakata alınmamış bildirim/hafta onayı bloklar.
- Aynı şube, Birim Amiri ve ay için tekrar approve `409` döner.
- Yönetim rolleri paneli salt okunur görür; yeni S72 approve sahibi değildir.

### Genel Yönetici bildirim üst onayı — S73

DB sahibi: `genel_yonetici_bildirim_onaylari`; approve sahibi: `GENEL_YONETICI`.

Granülarite: her üst onay tek şube, tek Birim Amiri ve tek ay bağlamına aittir. Tüm-şube aggregate üst onayı yoktur.

| İşlem | Endpoint | Permission |
|---|---|---|
| Özet | `GET /genel-yonetici-bildirim-onaylari/ozet` | `genel_yonetici_bildirim_onayi.view` |
| Approve | `POST /genel-yonetici-bildirim-onaylari` | `genel_yonetici_bildirim_onayi.approve` |
| Detay | `GET /genel-yonetici-bildirim-onaylari/{id}` | `genel_yonetici_bildirim_onayi.view` |

**State:** İlk sürümde yalnız `TAMAMLANDI` vardır. `BEKLIYOR`, `REDDEDILDI`, `DUZELTME_ISTENDI`, reopen ve iptal akışları yoktur.

**Migration:** `api/migrations/008_genel_yonetici_bildirim_onaylari.sql` — tablo `genel_yonetici_bildirim_onaylari`. Temel alanlar: `id`, `sube_id`, `birim_amiri_user_id`, `ay`, `aylik_bildirim_onayi_id`, `state`, `onaylayan_user_id`, `onaylandi_at`, `aciklama`, `created_at`, `updated_at`. Foreign key: `aylik_bildirim_onayi_id` → `aylik_bildirim_onaylari.id`. Unique: `(sube_id, birim_amiri_user_id, ay)`. cPanel deploy migration çalıştırmaz; 008 canlıya manuel uygulanmıştır ve tekrar çalıştırılmamalıdır.

**Önkoşullar (approve):**

- ay geçerli (`YYYY-MM`)
- şube seçilmiş
- Birim Amiri seçilmiş
- Birim Amiri seçilen şubeye bağlı ve `AKTIF`
- S72 `aylik_bildirim_onaylari` kaydı bulunmalı
- S72 state `TAMAMLANDI` olmalı
- `eksik_hafta` 0 olmalı
- aynı `(sube_id, birim_amiri_user_id, ay)` için daha önce üst onay bulunmamalı

**HTTP davranışları:**

- `403` — permission yok veya Birim Amiri şube kapsamı dışında
- `409` — duplicate üst onay (`GENEL_YONETICI_BILDIRIM_ONAYI_MEVCUT`)
- `422` — önkoşul/validasyon ihlali

**Özet `blok_nedeni` kodları:**

| Kod | Anlam |
|---|---|
| `AYLIK_BILDIRIM_ONAYI_GEREKLI` | S72 aylık onay kaydı yok |
| `AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI` | S72 tamamlanmamış veya ay bütünlüğü sağlanmıyor |
| `EKSIK_HAFTA_VAR` | Ayda eksik haftalık mutabakat var |
| `ZATEN_ONAYLANDI` | Üst onay zaten mevcut (özet; approve duplicate `409`) |

**Hesap sınırı:** S73 üst onayı yalnız operasyonel audit/onay kaydıdır. Puantaj, puantaj mührü, finans adayı, bordro girdisi, legacy aylık kapanış ve günlük/haftalık/S72 state'lerini otomatik değiştirmez. Bildirim zincirinin hesap motoru ve bordroya bağlanması ayrı gelecek fazdır.

**Legacy ayrımı:** `genel_yonetici_bildirim_onaylari`, legacy `aylik_ozet_satirlari`, `aylik_kapanis_state`, `/yonetim/aylik-ozet/*` ve `genel_yonetici_onayi.*` domain'lerinden bağımsızdır. Legacy aylık kapanış hattı S73 ile değiştirilmemiştir.

### Domain sınırı

- `aylik_bildirim_onaylari`, legacy `aylik_ozet_satirlari` / `aylik-ozet` domain'i değildir.
- `genel_yonetici_bildirim_onaylari`, legacy `genel_yonetici_onayi.*` ve `/yonetim/aylik-ozet/*` domain'i değildir.
- Legacy aylık özet üzerindeki Genel Yönetici onayı ile S73 üst onayı otomatik bağlı değildir.
- Günlük puantaj, haftalık kapanış/snapshot ve puantaj aylık mühürleri ayrı teknik state flow'dur.
- Bildirim onayının tamamlanması bordro girdisinin veya bordro kesinliğinin oluştuğu anlamına gelmez.

### Onaylı bildirim puantaj etki adayları — S74-B

DB sahibi: `onayli_bildirim_puantaj_etki_adaylari`; generate sahibi: `MUHASEBE`.

| İşlem | Endpoint | Permission |
|-------|----------|------------|
| Özet | `GET /puantaj/bildirim-etki-adaylari/ozet?genel_yonetici_bildirim_onayi_id=` | `puantaj.bildirim_etki.view` |
| Liste | `GET /puantaj/bildirim-etki-adaylari` | `puantaj.bildirim_etki.view` |
| Detay | `GET /puantaj/bildirim-etki-adaylari/{id}` | `puantaj.bildirim_etki.view` |
| Generate | `POST /puantaj/bildirim-etki-adaylari/hazirla` | `puantaj.bildirim_etki.generate` |
| Yok Say | `POST /puantaj/bildirim-etki-adaylari/{id}/yok-say` | `puantaj.bildirim_etki.dismiss` |
| Uygula | `POST /puantaj/bildirim-etki-adaylari/{id}/uygula` | `puantaj.bildirim_etki.apply` |

**Migration:** `009_onayli_bildirim_puantaj_etki_adaylari.sql`, `010_bildirim_puantaj_etki_snapshot_zamanlarini_duzelt.sql`.

**State modeli (generate çıktısı):** `HAZIR`, `INCELEME_GEREKLI`. Terminal state'ler: `UYGULANDI` (apply), `YOK_SAYILDI` (yok-say).

**Idempotency:** Aynı `(genel_yonetici_bildirim_onayi_id, gunluk_bildirim_id)` için tekrar generate → HTTP `200`, `created_count=0`.

**Hesap sınırı:** S74-B yalnız aday üretir; `gunluk_puantaj`, finans, bordro veya bildirim zinciri state'lerini değiştirmez.

### Puantaj etki adayı karar altyapısı — S74-C1

DB genişlemesi: `011_bildirim_puantaj_etki_karar_altyapisi.sql` — yedi karar audit kolonu (`karar_veren_user_id`, `karar_zamani`, `karar_gerekcesi`, `uygulanan_puantaj_id`, `onceki_puantaj_snapshot`, `sonraki_puantaj_snapshot`, `uygulama_hash`).

Policy sahibi: `BildirimPuantajEtkiDecisionPolicy` — yalnız state/action kararı (endpoint yok).

| State | UYGULA | YOK_SAY |
|-------|:------:|:-------:|
| HAZIR | Evet | Evet |
| INCELEME_GEREKLI | Hayır | Evet |
| UYGULANDI | Hayır | Hayır |
| YOK_SAYILDI | Hayır | Hayır |

| Permission | MUHASEBE | GENEL_YONETICI | BOLUM_YONETICISI | BIRIM_AMIRI | PATRON |
|------------|:--------:|:--------------:|:----------------:|:-----------:|:------:|
| `puantaj.bildirim_etki.apply` | Evet | Hayır | Hayır | Hayır | Hayır |
| `puantaj.bildirim_etki.dismiss` | Evet | Hayır | Hayır | Hayır | Hayır |

**List kontratı:** `karar_veren_user_id`, `karar_zamani`, `uygulanan_puantaj_id`.

**Detail kontratı:** `karar_veren_user_id`, `karar_zamani`, `karar_gerekcesi`, `uygulanan_puantaj_id`, `onceki_puantaj_snapshot`, `sonraki_puantaj_snapshot`, `uygulama_hash`.

**Ürün kararları:** Mevcut puantaj otomatik overwrite edilmez. YOK_SAY gerekçesi zorunludur; minimum karakter sayısı henüz kararlaştırılmamıştır (doğrulama endpoint fazında). Migration canlıya otomatik uygulanmaz.

**Sınır:** S74-C1 endpoint'leri karar audit altyapısını taşır. Uygula POST S74-C3-B2 ile gelmiştir; yok-say S74-C2A ile gelmiştir. Frontend Uygula butonu bu fazda yoktur.

### S74-C3-B1 — Dakika altyapısı ve projection kilidi

**Kapsam:** Geç/erken dakika kolonları, mühür snapshot parity, GÖREVDE canonical dayanak, ücretsiz izin projection kilidi.

**Dakika kolonları:** `gec_kalma_dakika`, `erken_cikis_dakika` — `INT UNSIGNED NULL` (`gunluk_puantaj`, `puantaj_aylik_muhur_satirlari`). Migration `012` canlıda mevcuttur.

**GÖREVDE canonical apply hedefi:**

```text
hareket_durumu = Geldi
dayanak = Gorevde_Calisma
hesap_etkisi = Tam_Yevmiye_Ver
```

**Ücretsiz izin projection (otomatik apply dışı):**

| Alan | Değer |
|------|-------|
| `state` | `INCELEME_GEREKLI` |
| `etki_turu` | `IZIN_GUNU` |
| `conflict_code` | `UCRETSIZ_IZIN_MANUEL_INCELEME` |

`HAZIR` üretilmez.

**Apply gün tipi:** Pazar → `Hafta_Tatili_Pazar`; diğer günler → `null`; UBGT tahmini yok.

### Puantaj etki adayı Uygula — S74-C3-B2

Endpoint: `POST /puantaj/bildirim-etki-adaylari/{id}/uygula` — yalnız `puantaj.bildirim_etki.apply` (MUHASEBE).

**Kapsam:** Apply backend paketi. Frontend Uygula butonu yoktur.

**Request body:**

```json
{
  "expected_state": "HAZIR"
}
```

| Alan | Kural |
|------|-------|
| `expected_state` | Zorunlu; yalnız `HAZIR`; DB state ile aynı olmalı |

**State matrisi:**

| Mevcut state | UYGULA |
|--------------|:------:|
| HAZIR | İzinli |
| INCELEME_GEREKLI | Yasak (409 `STATE_CONFLICT`) |
| UYGULANDI | Exact bütünlüklü tekrar → idempotent 200; bozuk bütünlük → 409 `APPLY_INTEGRITY_CONFLICT` |
| YOK_SAYILDI | Yasak (409 `STATE_CONFLICT`) |

**Diğer 409 kodları:** `PERIOD_LOCKED` (mühürlü ay), `PUANTAJ_OLUSTU` (duplicate personel+tarih), `APPLY_UNSUPPORTED` (ücretsiz izin / desteklenmeyen etki), `STATE_STALE`.

**Kurallar:** Yalnız INSERT; mevcut `gunluk_puantaj` UPDATE edilmez. Dakika alanları aday `etki_miktari`'nden yazılır; saatten yeniden hesap yok. Canonical servis: `BildirimPuantajEtkiApplyService`.

**Audit alanları:** `karar_veren_user_id`, `karar_zamani`, `uygulanan_puantaj_id`, `onceki_puantaj_snapshot`, `sonraki_puantaj_snapshot`, `uygulama_hash`.

### Puantaj etki adayı Yok Say — S74-C2A

Endpoint: `POST /puantaj/bildirim-etki-adaylari/{id}/yok-say` — yalnız `puantaj.bildirim_etki.dismiss` (MUHASEBE).

**Kapsam:** S74-C2A yalnız Yok Say endpointidir. Frontend karar ekranı henüz yoktur.

**Request body:**

```json
{
  "expected_state": "INCELEME_GEREKLI",
  "gerekce": "Mevcut puantaj kaydıyla çakıştığı için yok sayıldı."
}
```

| Alan | Kural |
|------|-------|
| `expected_state` | Zorunlu; `HAZIR` veya `INCELEME_GEREKLI`; ilk kararda DB state ile aynı olmalı |
| `gerekce` | Zorunlu; trim sonrası 5–500 karakter; yalnız boşluk kabul edilmez |

**State matrisi:**

| Mevcut state | YOK_SAY |
|--------------|:-------:|
| HAZIR | İzinli |
| INCELEME_GEREKLI | İzinli |
| UYGULANDI | Yasak (409 `STATE_CONFLICT`) |
| YOK_SAYILDI | Terminal; exact tekrar idempotent 200, farklı gerekçe 409 |

**Stale:** `expected_state` DB state ile uyuşmazsa → HTTP 409 `STATE_STALE`.

**Idempotency:** Aday zaten `YOK_SAYILDI` ve trim edilmiş gerekçe DB'deki `karar_gerekcesi` ile aynıysa → HTTP 200, `idempotent: true`, yeni UPDATE yok.

**Yazılan alanlar:** `state=YOK_SAYILDI`, `karar_veren_user_id`, `karar_zamani` (backend UTC), `karar_gerekcesi` (trim edilmiş).

**Yazılmayan alanlar:** `uygulanan_puantaj_id`, `onceki_puantaj_snapshot`, `sonraki_puantaj_snapshot`, `uygulama_hash` (NULL kalır).

**Mutation sınırı:** `gunluk_puantaj`, finans/bordro, süreç ve bildirim zinciri tablolarına yazım yoktur.

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

## 8. Günlük Amir Bildirimi State Flow

### 8.1 Ürün Kararı

Günlük amir bildirimi modülü, süreç modülünden ve sistem notification katmanından ayrıdır.

Kararlar:

- operasyonel günlük kayıt, `BIRIM_AMIRI` tarafından girilir
- kayıt otomatik olarak `süreç` kaydına dönüşmek zorunda değildir
- onaylı günlük bildirim, puantaj/bordro hesap zincirinin ham verisi olabilir
- header bildirim paneli ve takvim hatırlatmaları bu kayıt tipinden ayrıdır

### 8.2 State Modeli — Günlük Bildirim

| State | Açıklama |
|-------|----------|
| `TASLAK` | Amir kaydı oluşturdu; henüz gönderilmedi |
| `GONDERILDI` | Normal sürede gönderildi |
| `GEC_GONDERILDI` | Cut-off sonrası gönderildi; audit izi bırakır |
| `DUZELTME_ISTENDI` | `BOLUM_YONETICISI` düzeltme talep etti |
| `HAFTALIK_MUTABAKATA_ALINDI` | Haftalık mutabakat paketine dahil edildi; doğrudan düzenleme kısıtlanır |

İptal edilen kayıtlar fiziksel silinmez; `IPTAL` state veya eşdeğer audit kaydı tutulur (geriye uyumluluk).

### 8.2.1 Tarihsel Başlangıç Kontratı — Haftalık Mutabakat

Bu state seti S70A hedef modelidir. Güncel çalışan S71 kontratı üstteki “Güncel Bildirim State Flow — S70C-S73” bölümündedir; haftalık kaydın state'i `TAMAMLANDI`, approve sahibi `BIRIM_AMIRI` rolüdür.

| State | Açıklama |
|-------|----------|
| `HAFTA_ACIK` | Hafta henüz toplanmadı |
| `BILDIRIMLER_TOPLANIYOR` | Günlük bildirimler haftaya bağlanıyor |
| `A4_MUTABAKAT_BEKLIYOR` | A4 çıktı/imza mutabakatı bekleniyor |
| `CELISKI_VAR` | Bildirim, süreç veya puantaj çelişkisi tespit edildi |
| `MANUEL_INCELEME` | Çelişki veya eksik veri manuel incelemeye alındı |
| `MUTABAKAT_TAMAMLANDI` | Amir mutabakatı tamamlandı; aylık onay önkoşulu sağlandı |
| `TEKNIK_KAPANIS_YAPILDI` | Snapshot/mühür üretildi; hafta teknik olarak kilitlendi |

### 8.2.2 Hedef Ürün Kontratı — Aylık Bölüm/Genel Yönetici Onayı

Bu hedef state seti yeni S72 `aylik_bildirim_onaylari` domain'i değildir. Yeni aylık bildirim onayının çalışan kontratı üst bölümde ayrıca tanımlanmıştır.

| State | Açıklama |
|-------|----------|
| `AY_ACIK` | Ay operasyonel olarak açık |
| `BOLUM_ONAYI_BEKLIYOR` | Haftalık mutabakatlar tamamlanmadı veya bölüm onayı bekleniyor |
| `BOLUM_ONAYLANDI` | `BOLUM_YONETICISI` bölüm onayını verdi |
| `GENEL_YONETICI_ONAYI_BEKLIYOR` | Bölüm onayı tamam; üst onay bekleniyor |
| `GENEL_YONETICI_ONAYLANDI` | `GENEL_YONETICI` bordro öncesi onayı verdi |
| `BORDRO_ON_IZLEME_HAZIR` | Hesap motoru ön izleme üretebilir |
| `BORDRO_KESINLESTI` | Nihai bordro kesinleşti |

### 8.2.3 State Modeli — Patron Ack

| State | Açıklama |
|-------|----------|
| `GORULMEDI` | Patron henüz görmedi |
| `GORULDU` | Patron gördü olarak işaretledi |
| `NOT_EKLENDI` | Patron not ekledi |

Patron ack state'i bordro üretimini teknik olarak engellemez.

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

## 10. Tarihsel Hedef — Haftalık Mutabakat ve Teknik Kapanış State Flow

### 10.1 Amaç

Haftalık süreç iki katmandan oluşur:

1. **Haftalık mutabakat (tarihsel S70A hedefi):** Bu alt bölümde `BOLUM_YONETICISI` sahibiyle tasarlanan model uygulanmış S71 değildir. Güncel S71'de approve sahibi `BIRIM_AMIRI` rolüdür.
2. **Teknik kapanış (sistem):** Onaylı hafta için snapshot üretilir ve hafta mühürlenir.

Teknik kapanış, operasyonel mutabakatın yerine geçmez. Mutabakat tamamlanmadan teknik kapanış tetiklenemez.

### 10.2 Endpoint'ler (uygulanmamış eski hedef)

Operasyonel mutabakat için aşağıdaki adlar uygulanmamış eski hedef kontrattır; güncel çalışan endpointler üst bölümdeki `/haftalik-bildirim-mutabakatlari` ailesidir:

- `GET /api/haftalik-mutabakat` — hafta özeti listesi
- `POST /api/haftalik-mutabakat/{haftaId}/onay` — `BOLUM_YONETICISI` mutabakat onayı
- `POST /api/haftalik-mutabakat/{haftaId}/duzeltme-iste` — düzeltme talebi

Teknik kapanış (mevcut):

- `POST /api/haftalik-kapanis`

İstek örneği:

```json
{
  "hafta_baslangic": "2026-04-06",
  "hafta_bitis": "2026-04-12",
  "departman_id": 3
}
```

Önkoşul: ilgili hafta `MUTABAKAT_TAMAMLANDI` state'inde olmalıdır.

### 10.3 Backend Akışı — Mutabakat

1. ilgili haftanın günlük amir bildirimlerini toplar
2. süreç ve puantaj kayıtlarıyla çapraz kontrol yapar
3. çelişki varsa `CELISKI_VAR` veya `MANUEL_INCELEME` üretir
4. `BOLUM_YONETICISI` onayı ile `MUTABAKAT_TAMAMLANDI` yazar
5. A4 çıktı ve imza metadata'sını audit'e bağlar

### 10.4 Backend Akışı — Teknik Kapanış

1. mutabakat state doğrulanır
2. ilgili haftanın puantaj ve süreç verisini toplar
3. hesap motorunu tetikler
4. personel × hafta snapshot üretir
5. haftayı `TEKNIK_KAPANIS_YAPILDI` olarak işaretler

### 10.5 Kilit Kuralları

- `MUTABAKAT_TAMAMLANDI` olmadan teknik kapanış yapılamaz
- teknik kapanış sonrası hafta normal kullanıcı için yeniden açılmaz
- değişiklik ihtiyacı revizyon talebi akışı ile yürür (`51-haftalik-kapanis-revizyon-talebi-karar.md`)

## 11. Legacy/Hedef Aylık Özet, Bordro ve Patron Ack State Flow

Bu bölüm legacy `aylik_ozet_satirlari` / `aylik-ozet` ve hedef bordro zincirini anlatır. Yeni S72 `aylik_bildirim_onaylari` domain'iyle otomatik bağlantısı yoktur.

### 11.1 Aylık Bölüm Onayı

Endpoint (mevcut — revize edilecek):

- `GET /api/yonetim/aylik-ozet`
- `POST /api/yonetim/aylik-ozet/bolum-onay`

Yetki: `BOLUM_YONETICISI` (`aylik_bolum_onayi.approve`)

Önkoşul: ilgili ay için tüm haftalar `MUTABAKAT_TAMAMLANDI` (ve tercihen `TEKNIK_KAPANIS_YAPILDI`) olmalıdır.

Başarı sonucu: satırlar `BOLUM_ONAYLANDI` state'ine geçer.

### 11.2 Genel Yönetici Bordro Öncesi Onayı

Endpoint (mevcut — revize edilecek):

- `POST /api/yonetim/aylik-ozet/ay-kapat`

Yetki: `GENEL_YONETICI` (`genel_yonetici_onayi.approve`)

Önkoşul: ilgili kapsamda tüm satırlar `BOLUM_ONAYLANDI` olmalıdır; aksi halde `409 APPROVAL_CHAIN_BLOCKED`.

Başarı sonucu: `GENEL_YONETICI_ONAYLANDI`; bordro ön izleme üretilebilir.

### 11.3 Bordro Ön İzleme ve Kesinleştirme

Hedef endpoint'ler (kod fazında):

- `GET /api/bordro/on-izleme` — `MUHASEBE`, `GENEL_YONETICI`
- `POST /api/bordro/kesinlestir` — yalnız `GENEL_YONETICI`; açık manuel inceleme ve eksik parametre yok

Önkoşullar:

- `GENEL_YONETICI_ONAYLANDI`
- zorunlu şirket parametreleri tanımlı
- açık `MANUEL_INCELEME` kaydı yok

### 11.4 Patron Ack

Hedef endpoint'ler (kod fazında):

- `GET /api/patron-ozet` — `PATRON`, `GENEL_YONETICI`
- `POST /api/patron-ozet/ack` — `PATRON` (`patron_ack.mark_seen`)

Davranış:

- `GORULDU` veya `NOT_EKLENDI` yazar
- bordro state'ini değiştirmez; üretimi bloklamaz
- audit izi bırakır

## 12. Ek Ödeme / Kesinti Contract'ı

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

## 13. Referans Veri Contract'ı

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

## 14. Rapor Ekranı Contract'ı

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

## 15. Ekran -> Endpoint Haritası

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

## 16. Kilit ve Düzenleme Kuralları

Onay zinciri ve kilit kuralları:

- haftalık mutabakat tamamlanmadan aylık bölüm onayı verilemez
- bölüm onayı tamamlanmadan genel yönetici onayı verilemez
- genel yönetici onayı tamamlanmadan nihai bordro oluşmaz
- patron ack bordroyu bloklamaz
- açık manuel inceleme varken bordro kesinleşmez
- zorunlu şirket parametresi eksikse hesap kesinleşmez
- mutabakat tamamlanmadan teknik haftalık kapanış yapılamaz
- teknik kapanış sonrası kapanmış hafta içindeki puantaj düzenlenemez
- kapanmış haftayı etkileyen süreç düzenlemesi `409` döner
- iptal işlemi fiziksel silme yerine state değişimi ile yapılır
- işten ayrılma sonrası personel aktif listeden düşer

## 17. Backend'in Zorunlu Olarak Yaptığı Şeyler

Backend sadece veriyi kaydetmez; ayrıca:

- referans id doğrulaması yapar
- role göre yetki kontrolü yapar
- state geçişini uygular
- hesap motorunu tetikler
- read model veya snapshot üretir
- audit izi bırakır

## 18. Frontend'in Yapmaması Gereken Şeyler

Frontend aşağıdakileri kendi başına yapmaz:

- personeli pasife düştü varsayımıyla sadece local state güncellemek
- fazla mesaiyi client tarafında hesaplamak
- hafta kapanışını görsel buton state'i ile “kapandı” saymak
- referans verileri hardcode etmek
- state geçişini yalnızca buton rengine bakarak tahmin etmek

## 19. Ürün Reset Sonrası Netleştirilen Kararlar

Bu belge ile aşağıdaki kritik kararlar sabitlenmiştir:

- günlük amir bildirimi operasyonel veri katmanıdır; sürece otomatik dönüşmek zorunda değildir
- haftalık mutabakat, teknik kapanıştan önce gelir
- aylık onay zinciri zorunludur; atlama backend tarafından reddedilir
- patron ack semboliktir; bordroyu bloklamaz
- manuel inceleme ve eksik şirket parametresi bordro kesinleşmesini engeller
- `işten ayrılma` kaydı personeli anında `PASIF` yapar
- `iptal` fiziksel silme değildir
- referans veriler API üzerinden gelir
- frontend nihai hesap yapmaz

### 19.1 Tarihsel V1 kararları (arşiv)

Aşağıdaki ifadeler ürün reset öncesi V1 için geçerliydi; artık **onay/bordro katmanları** için geçerli değildir:

- “V1'de taslak/onay akışı yoktur”
- “kayıt validasyondan geçince anında aktif” (yalnızca personel/süreç temel kayıtları için korunur)

## 20. Kod Fazlarında Açılacak Konular

- haftalık mutabakat API ve UI
- patron rolü ve ack endpoint'leri
- şirket parametreleri API
- bordro ön izleme ve kesinleştirme endpoint'leri
- günlük bildirim → puantaj ham veri bağlantısı
- mevcut `ay-kapat` endpoint'inin onay zinciri guard revizyonu
- detaylı sunucu tarafı audit ekranı
- webhook / event bus yapısı (opsiyonel)

## 21. Sonuç

Bu belge, sistemin veri damar haritasıdır.

Şu işi yapar:

- hangi ekranın ne çağırdığını netleştirir
- hangi kaydın hangi state'ten geçtiğini tanımlar
- frontend ile backend arasında yanlış varsayımı azaltır

Bu belge sonrası istersek iki yoldan ilerleyebiliriz:

- gerçek proje klasör yapısı ve teknik scaffold
- ya da ekran bazlı görev kırılımı
