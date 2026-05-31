# 46 — E3c Serbest Zaman Düzeltme / İptal Event Kararı

---

## 1. Faz Bilgisi

| Alan | Değer |
|------|-------|
| Faz adı | E3c — Serbest zaman düzeltme / iptal event modeli karar dokümanı |
| Önceki kapanış | E3b |
| Önceki commitler | `68dfa9b` — Add E3b serbest zaman kullanim event and bakiye dusumu |
| | `9a83753` — Add E3b serbest zaman kullanim checkpoint |
| Durum | Karar dokümanı |

---

## 2. Amaç

E3a ve E3b ile oluşan serbest zaman event store hattında, hatalı oluşum veya hatalı kullanım kayıtlarının fiziksel silinmeden nasıl düzeltileceğini ve nasıl iptal edileceğini karara bağlamak.

---

## 3. Mevcut Durum

- **E3a:** `SERBEST_ZAMAN_OLUSUM` event'i üretir.
- **E3b:** `SERBEST_ZAMAN_KULLANIM` event'i üretir.
- **Bakiye read model:**
  - `toplam_hak_dakika = Σ OLUSUM`
  - `kullanilan_dakika = Σ KULLANIM`
  - `kalan_dakika = max(toplam - süresi_dolan - kullanilan, 0)`
- Başarısız kullanım persist edilmez.
- Düzeltme / iptal event implementasyonu henüz yoktur.

---

## 4. Problem Tanımı

Şu durumlarda mevcut sistemin yeni event türüne ihtiyacı vardır:

- Yanlış dakika ile oluşum event'i üretildi.
- Yanlış personel için oluşum event'i üretildi.
- Yanlış kullanım dakikası girildi.
- Kullanım event'i yanlış personel veya tarih için girildi.
- Geçmişteki bir event operasyonel olarak iptal edilmeli.
- Event fiziksel silinmeden audit izi korunmalı.

---

## 5. Ana Karar

Fiziksel silme yapılmayacak. Düzeltme ve iptal, yeni event olarak tutulacak.

**Karar:**

- `SERBEST_ZAMAN_DUZELTME` → önceki event'in etkisini düzeltir.
- `SERBEST_ZAMAN_IPTAL` → önceki event'in etkisini sıfırlar veya geri alır.

---

## 6. Event Tipleri

**Mevcut event tipleri:**

- `SERBEST_ZAMAN_OLUSUM`
- `SERBEST_ZAMAN_KULLANIM`

**E3c ile karar altına alınacak event tipleri:**

- `SERBEST_ZAMAN_DUZELTME`
- `SERBEST_ZAMAN_IPTAL`

> **Not:** Bu doküman yalnız karar dokümanıdır. Bu turda type/motor/API implementasyonu yapılmayacaktır.

---

## 7. SERBEST_ZAMAN_IPTAL Kararı

### Amaç

Var olan bir event'in etkisini fiziksel silme olmadan geçersiz kılmak.

### Önerilen alanlar

| Alan | Tip | Zorunlu |
|------|-----|---------|
| `id` | `number` | Hayır |
| `personel_id` | `number` | Evet |
| `event_tipi` | `"SERBEST_ZAMAN_IPTAL"` | Evet |
| `hedef_event_id` | `number` | Evet |
| `hedef_event_tipi` | `"SERBEST_ZAMAN_OLUSUM" \| "SERBEST_ZAMAN_KULLANIM"` | Evet |
| `event_tarihi` | `string` | Evet |
| `aciklama` | `string` | Hayır |

### Davranış

- Hedef event var olmalıdır.
- Hedef event aynı `personel_id`'ye ait olmalıdır.
- Aynı hedef event için ikinci iptal event'i üretilemez.
- İptal edilmiş event bakiye hesabında etkisiz sayılır.
- İptal event'i kendisi negatif/pozitif hak üretmez; sadece hedef event etkisini kaldırır.

### Bakiye etkisi

- **OLUSUM iptal edilirse** `toplam_hak_dakika` azalır.
- **KULLANIM iptal edilirse** `kullanilan_dakika` azalır, bakiye iade edilir.

---

## 8. SERBEST_ZAMAN_DUZELTME Kararı

### Amaç

Hatalı bir event'i silmeden yeni doğru değerle düzeltmek.

### Önerilen alanlar

| Alan | Tip | Zorunlu |
|------|-----|---------|
| `id` | `number` | Hayır |
| `personel_id` | `number` | Evet |
| `event_tipi` | `"SERBEST_ZAMAN_DUZELTME"` | Evet |
| `hedef_event_id` | `number` | Evet |
| `hedef_event_tipi` | `"SERBEST_ZAMAN_OLUSUM" \| "SERBEST_ZAMAN_KULLANIM"` | Evet |
| `yeni_dakika` | `number` | Evet |
| `event_tarihi` | `string` | Evet |
| `aciklama` | `string` | Hayır |

### Davranış

- Hedef event var olmalıdır.
- Hedef event aynı `personel_id`'ye ait olmalıdır.
- `yeni_dakika > 0` olmalıdır.
- Düzeltme, hedef event'in dakika etkisini `yeni_dakika` olarak kabul ettirir.
- Aynı hedef event için birden fazla düzeltme olacaksa son düzeltme geçerli kabul edilir.
- Düzeltme event'i fiziksel olarak önceki event'i değiştirmez; read modelde hedef event etkisini override eder.

### Bakiye etkisi

- **OLUSUM düzeltmesi** `toplam_hak_dakika` değerini değiştirir.
- **KULLANIM düzeltmesi** `kullanilan_dakika` değerini değiştirir.
- Düzeltme sonrası `kalan_dakika` yine `Math.max(..., 0)` ile negatif olmayacak şekilde hesaplanır.
- Kullanım düzeltmesi mevcut bakiyeyi aşacaksa validation gerekir.

---

## 9. Read Model Sırası

Bakiye hesaplanırken event'ler şu sırayla yorumlanır:

1. Ham `OLUSUM` ve `KULLANIM` event'leri okunur.
2. İptal event'leri hedef event'leri etkisizleştirir.
3. Düzeltme event'leri hedef event'in dakika etkisini override eder.
4. Süresi dolan oluşum hakları E3a `son_kullanim_tarihi` kuralıyla hesaplanır.
5. Kullanılan dakika düşülür.
6. `kalan_dakika = max(toplam_hak - suresi_dolan - kullanilan, 0)`

> **Not:** DÜZELTME ve İPTAL sırası çakışırsa iptal baskın kabul edilir. İptal edilmiş event üzerinde düzeltme etkisizdir.

---

## 10. Validation Kararları

| Durum | Hata kodu |
|-------|-----------|
| `hedef_event_id` yok | `TARGET_NOT_FOUND` |
| Hedef event başka `personel_id`'ye ait | `TARGET_PERSONEL_MISMATCH` |
| Hedef event zaten iptal edilmiş | `TARGET_ALREADY_CANCELLED` |
| Aynı hedef için ikinci iptal | `ALREADY_CANCELLED` |
| `yeni_dakika <= 0` | `ZERO_DAKIKA` |
| Kullanım düzeltmesi bakiyeyi aşarsa | `INSUFFICIENT_BALANCE` |
| Hedef event tipi desteklenmiyorsa | `UNSUPPORTED_TARGET_EVENT` |

---

## 11. Snapshot Kararı

Haftalık kapanış snapshot builder'a E3c'de dokunulmayacak.

Serbest zaman düzeltme/iptal event'leri serbest zaman event store read modelinde çözülecek.

Haftalık snapshot immutable kalır.

Eğer ileride kapalı dönem etkisi gerekiyorsa ayrı "kapalı dönem düzeltme" fazı açılır.

---

## 12. API Kararı

E3c kod fazında önerilecek endpointler:

- `POST /serbest-zaman/iptal`
- `POST /serbest-zaman/duzeltme`

**`GET /serbest-zaman/events`:**

- `OLUSUM`
- `KULLANIM`
- `DUZELTME`
- `IPTAL`

**`GET /serbest-zaman/bakiye`:**

- İptal ve düzeltme uygulanmış read model sonucunu döner.

> Bu karar dokümanı endpoint implementasyonu yapmaz.

---

## 13. Test Kararı

E3c kod fazında minimum testler:

- OLUSUM iptali `toplam_hak_dakika` azaltır.
- KULLANIM iptali `kullanilan_dakika` azaltır ve bakiye iade eder.
- OLUSUM düzeltmesi `toplam_hak_dakika` değiştirir.
- KULLANIM düzeltmesi `kullanilan_dakika` değiştirir.
- İptal edilmiş event düzeltilemez veya düzeltme etkisiz kalır.
- Aynı hedef ikinci kez iptal edilemez.
- Kullanım düzeltmesi bakiyeyi aşarsa reddedilir.
- `kalan_dakika` negatif olmaz.
- E3a/E3b regresyonları korunur.

---

## 14. Kapsam Dışı

- Bu turda kod yok.
- Type değişikliği yok.
- Motor değişikliği yok.
- API/mock değişikliği yok.
- UI/hook/page/route/CSS yok.
- Haftalık snapshot builder yok.
- 270 saat aggregate yok.
- Puantaj motoru yok.
- Bordro/finans yok.

---

## 15. Sonraki Faz Notu

Bu dokümandan sonra iki seçenek vardır:

- **E3c kod fazı:** düzeltme/iptal eventlerinin types + motor + API + mock + test implementasyonu
- veya **serbest zaman zinciri genel kapanış/devir dokümanı**

---

## 16. Kapanış Cümlesi

E3c karar dokümanı, serbest zaman event store hattında düzeltme ve iptal işlemlerinin fiziksel silme yerine audit izli event modeliyle ele alınacağını karara bağlar. Kod implementasyonu ayrı fazda yapılacaktır.
