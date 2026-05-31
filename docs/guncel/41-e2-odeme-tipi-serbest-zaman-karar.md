# 41. E2 — Fazla Çalışma Ödeme Tipi ve Serbest Zaman Ön Kararı

**Sürüm:** E2 karar  
**Tarih:** 2026-05-31  
**Ön koşul / karar zemini:** `docs/guncel/38-puantaj-mevzuat-faz-e-serbest-zaman-270-saat-karar.md`, `docs/guncel/39-haftalik-kapanis-snapshot-sozlesmesi-karar.md`, `docs/guncel/40-e1-270-saat-aggregate-kapanis-checkpoint.md`, `docs/guncel/04-hesap-motoru-kurallari.md`  
**Önceki zincir:** Faz E karar (38) → Snapshot sözleşmesi (39) → A1 contract → A2 builder → A3 detail store → E1 yıllık aggregate

---

## 1. Amaç

Fazla çalışma sonrası karşılığın **ücret mi serbest zaman mı** olacağının hangi veri modeli ve süreç hattı ile tutulacağını netleştirmek.

Bu belge:

- `odeme_tipi` alanının **snapshot dışında** kalacağını çiviler,
- haftalık kapanış snapshot'ının **read-only mühürlü hesap kaynağı** olarak korunacağını teyit eder,
- ödeme tercihinin **ayrı tercih/event hattında** yaşayacağını tanımlar,
- serbest zaman **bakiye, kullanım ve 6 ay** modelini bilinçli olarak **E3** fazına bırakır.

Bu belge implementasyon dokümanı değildir. Kod, test, UI ve API değişikliği bu karar kapsamında açılmaz.

---

## 2. Mevcut Durum

| Alan | Durum |
|------|--------|
| Haftalık kapanış snapshot hattı (A1 → A2 → A3) | **Kuruldu** — personel × hafta satırı, süre ve compliance taşır |
| Yıllık 270 saat aggregate (E1) | **Kuruldu** — kapanmış snapshot `fazla_calisma_dakika` toplamı |
| Snapshot satırı içeriği | Yalnız **süre + compliance + denetim** alanları |
| `odeme_tipi` | **Yok** — tip, API, mock ve snapshot sözleşmesinde tanımlı değil |
| Serbest zaman hak event'i | **Yok** |
| Serbest zaman bakiye modeli | **Yok** |
| `fazla_surelerle_calisma_dakika` (V1) | Sözleşmede alan var; builder **sabit 0** yazar |

Detay:

- `HaftalikKapanisSnapshotSatir` (`src/types/haftalik-kapanis.ts`) `fazla_calisma_dakika` ve `fazla_surelerle_calisma_dakika` taşır; `odeme_tipi` taşımaz.
- `docs/guncel/39-haftalik-kapanis-snapshot-sozlesmesi-karar.md` §5, `odeme_tipi`'ni bilinçli olarak snapshot dışında bırakmıştır.
- `docs/guncel/40-e1-270-saat-aggregate-kapanis-checkpoint.md` E1'i kapatmış; ödeme tercihi ve serbest zaman bilinçli kapsam dışı bırakılmıştır.
- Motor katmanında haftalık FM ücret **ön izlemesi** ayrı helper ile üretilebilir; bu çıktı snapshot'a yazılmaz ve ödeme tercihi kaydı değildir.

---

## 3. Snapshot ve Event Ayrımı

### 3.1 Karar

`HaftalikKapanisSnapshotSatir` **read-only mühürlü hesap kaynağıdır**.

- Kapanış anında hesap motoru kurallarıyla üretilir ve persist edilir.
- Canlı hook cache veya günlük puantaj ön izlemesi bu kaynağın yerine geçmez (`docs/guncel/39-*` §6).
- Sonradan doğrudan alan ezme yerine düzeltme event'i veya yeniden hesap tercih edilir (`docs/guncel/04-hesap-motoru-kurallari.md` §15.2).

`odeme_tipi` **mutable / karar bilgisidir**; işçi veya yetkili tarafından kapanış sonrası seçilebilir, değiştirilebilir ve audit gerektirir. Bu nedenle snapshot satırına **yazılmayacaktır**.

### 3.2 Tercih kaydı — snapshot referansı

Ödeme tercihi, snapshot satırına **referans veren ayrı bir kayıt** olarak modellenir. Asgari referans alanları:

| Alan | Açıklama |
|------|----------|
| `snapshot_id` | İlgili mühürlü snapshot satırı |
| `kapanis_id` | Üst kapanış işlemi |
| `personel_id` | Personel referansı |
| `hafta_baslangic` | Hafta başlangıcı (YYYY-MM-DD) |
| `hafta_bitis` | Hafta bitişi (YYYY-MM-DD) |
| `fazla_calisma_dakika` | Tercih anında snapshot'tan kopyalanan FM süresi (denormalize; denetim için) |

Tercih kaydı snapshot'ı **değiştirmez**; yalnızca ona bağlanır.

### 3.3 Akış

```text
Günlük puantaj
  → hesap motoru
  → haftalık kapanış snapshot (read-only, fazla_calisma_dakika mühürlenir)
  → [kapanış sonrası] fazla çalışma ödeme tercihi kaydı (odeme_tipi)
  → [E3] SERBEST_ZAMAN seçilirse hak oluşum event'i
```

---

## 4. Ödeme Tipi Kararı

### 4.1 Enum

| Değer | Anlam |
|-------|--------|
| `KARAR_BEKLIYOR` | Tercih henüz yapılmadı |
| `UCRET` | FM karşılığı zamlı ücret yolu |
| `SERBEST_ZAMAN` | FM karşılığı serbest zaman yolu |

### 4.2 Default

**Default:** `KARAR_BEKLIYOR`

Haftalık kapanış tamamlandığında sistem otomatik olarak `UCRET` veya `SERBEST_ZAMAN` seçmez.

### 4.3 Kurallar

| Koşul | Davranış |
|-------|----------|
| `KARAR_BEKLIYOR` | Serbest zaman hakkı **üretilmez** |
| `UCRET` | Serbest zaman hakkı **üretilmez** |
| `SERBEST_ZAMAN` | E3 fazında, tercih kaydı onaylandıktan sonra **hak oluşum event'i üretilebilir** |

**Kritik:** Tercih (`odeme_tipi`) kaydı olmadan hak oluşum event'i üretilmemelidir (`docs/guncel/38-*` §7).

---

## 5. Kim / Ne Zaman Seçer?

### 5.1 Karar

| Konu | Karar |
|------|--------|
| Zamanlama | Seçim **haftalık kapanıştan sonra** yapılır |
| Kapanış anı | Kapanış sırasında otomatik seçim **yapılmaz** |
| Yetki | Yetkili kullanıcı / İK / amir tarafından seçilir (`docs/guncel/04-hesap-motoru-kurallari.md` §7.3) |
| UI | Bu dokümanda tasarlanmaz; E4 veya ayrı ürün fazına bırakılır |

### 5.2 Gerekçe

- Serbest zaman kullanımı yalnızca **çalışan talebi** ile başlar (`docs/guncel/11-puantaj-kural-matrisi.md` §13).
- Kapanış snapshot'ı hesap sonucunu mühürler; ürün/operasyonel tercih aynı anda zorunlu kılınmaz.
- Bordro ve İK süreçleri ile uyum için tercih, mühürlü FM dakikası üzerinde ayrı adım olarak yürür.

---

## 6. Değiştirilebilirlik ve Audit

### 6.1 Karar

- Tercih, **bordro / puantaj dönem kilidi öncesi** değiştirilebilir.
- Dönem kilidi sonrası değişiklik bu dokümanda tanımlanmaz; ayrı yetki ve düzeltme politikası gerektirir.
- Her değişiklik **audit izine** sahip olmalıdır.

### 6.2 Audit alanları

| Alan | Açıklama |
|------|----------|
| `secim_zamani` | ISO datetime — işlem zamanı |
| `secen_kullanici_id` | Tercihi yapan kullanıcı |
| `onceki_odeme_tipi` | Değişiklik öncesi değer (`KARAR_BEKLIYOR` dahil) |
| `yeni_odeme_tipi` | Değişiklik sonrası değer |
| `gerekce` | İsteğe bağlı veya zorunlu — ürün kararı E3 öncesi netleştirilebilir |
| `kaynak_snapshot_id` | Bağlı snapshot satırı |

Audit kaydı, tercih kaydının yerine geçmez; **append-only değişim geçmişi** olarak tutulur.

---

## 7. Serbest Zaman Dönüşüm Kuralları

Mevzuat ve hesap motoru hedefi (`docs/guncel/04-hesap-motoru-kurallari.md` §7.2):

| Kaynak | Dönüşüm |
|--------|---------|
| Fazla çalışma | 1 saat = **1 saat 30 dakika** serbest zaman |
| Fazla sürelerle çalışma | 1 saat = **1 saat 15 dakika** serbest zaman |

### 7.1 V1 daraltması

- V1'de `fazla_surelerle_calisma_dakika` snapshot builder tarafından **0** yazılır; motor sözleşme bazlı FSC ayrımı yapmaz.
- **E2 dar kod fazında** yalnız `fazla_calisma_dakika` → serbest zaman dakika **dönüşüm helper'ı** ele alınabilir (saf motor fonksiyonu; test amaçlı).
- FSC dönüşüm kuralı bu belgede **hedef olarak tanımlı** kalır; FSC aktif hesaplanmadığı sürece kodda uygulanmaz.

### 7.2 Hak / bakiye sınırı

Dönüşüm helper'ı **hak üretmez**. Üretilen dakika değeri yalnızca E3'te `SERBEST_ZAMAN_OLUSUM` event'ine girdi olabilir.

---

## 8. Kapsam Dışı

Bu karar belgesi ve bağlı E2 kod fazı aşağıdakileri **açmaz**:

| Konu | Gerekçe |
|------|---------|
| Snapshot'a `odeme_tipi` eklenmesi | Snapshot read-only ayrımı (§3) |
| Serbest zaman bakiye modeli | E3 kapsamı |
| 6 ay son kullanım tarihi | E3 kapsamı |
| Bordro / ücret ödeme sonucu | Finans katmanı |
| UI / hook / page / route | E4 veya ayrı ürün fazı |
| 270 saat aggregate değişikliği | E1 kapalı; FM toplamı ayrı kaygı |
| `fazla_surelerle_calisma` aktif hesaplanması | V1 tam süreli varsayımı; ayrı faz |
| Hak oluşum / kullanım event'inin kodlanması | E3 ön koşulu: bu karar + event modeli |
| Haftalık snapshot builder değişikliği | A2 davranışı korunur |
| Compliance kodu (`YILLIK_FAZLA_CALISMA_*`) | E1b adayı |

---

## 9. E3 Bağımlılığı

E3, bu kararın onaylanması ve E2 dar kod fazının (tercih tipi + dönüşüm helper + mock tercih kaydı) tamamlanması sonrası açılır.

### 9.1 Event türleri

| Event | Amaç |
|-------|------|
| `SERBEST_ZAMAN_OLUSUM` | FM dönüşümü sonucu hak oluşumu |
| `SERBEST_ZAMAN_KULLANIM` | Hak tüketimi (izin benzeri) |
| `SERBEST_ZAMAN_DUZELTME` | Manuel düzeltme / operasyonel müdahale |
| `SERBEST_ZAMAN_IPTAL` | Hatalı oluşum veya tercih geri alımı |

### 9.2 Bakiye modeli (E3 hedef alanları)

| Alan | Amaç |
|------|------|
| `hak_olusan_dakika` | Toplam oluşan serbest zaman |
| `kullanilan_dakika` | Kullanılan süre |
| `kalan_dakika` | Bakiye |
| `son_kullanim_tarihi` | 6 ay mevzuat süresi takibi |
| `kaynak_snapshot_id` | Dayanak FM snapshot satırı |
| `kaynak_odeme_tercihi_id` | Dayanak tercih kaydı |

**Kritik:** `SERBEST_ZAMAN` tercihi tek başına bakiye oluşturmaz; E3 event zinciri ile bakiye güncellenir.

---

## 10. Kod Fazına Geçiş Koşulları

E2 kod fazına geçiş için aşağıdaki kararlar **kilitli** kabul edilir:

| # | Kilitli karar |
|---|---------------|
| 1 | `odeme_tipi` snapshot dışında olacak |
| 2 | Default `KARAR_BEKLIYOR` |
| 3 | Seçim haftalık kapanış **sonrasında** yapılacak |
| 4 | Serbest zaman **hak üretimi E3'e** kalacak |
| 5 | E2 kod fazı yalnız **tercih tipi + dönüşüm helper + mock tercih kaydı** ile sınırlı olacak |

### 10.1 E2 kod fazı hedef dosyalar (plan — bu belgede yazılmaz)

Kod fazı açıldığında aday dosyalar:

- `src/types/fazla-calisma-odeme-tercihi.ts` — enum ve tercih kaydı tipi
- `src/services/serbest-zaman-donusum.ts` — FM → serbest zaman dakika helper
- `src/api/fazla-calisma-odeme-tercihi.api.ts` — tercih okuma/yazma
- `src/api/mock-demo.ts` — ayrı tercih store
- `tests/unit/serbest-zaman-donusum.test.ts` — dönüşüm testleri

**Değiştirilmeyecek:** `src/types/haftalik-kapanis.ts` içine `odeme_tipi` eklenmez.

---

## 11. Sonuç

| Karar | Özet |
|-------|------|
| E2 önceliği | **Önce karar dokümanı** (bu belge); kod bu belge onayı sonrası |
| Snapshot | **Read-only** kalır; mühürlü FM/compliance kaynağı |
| `odeme_tipi` | **Ayrı tercih/event hattında** tutulur; snapshot alanı değildir |
| Default | `KARAR_BEKLIYOR` — hak üretilmez |
| Serbest zaman bakiye / 6 ay | **E3'e** bırakılır |
| V1 daraltma | E2 kod fazında yalnız `fazla_calisma_dakika` dönüşümü |

**Belge durumu:** E2 karar — kod bekliyor (bu belge onayı + §10 kilitli kararlar).

**Sonraki adım:** E2 dar kod fazı planı ve owner ataması; ardından E3 serbest zaman event + bakiye karar dokümanı.
