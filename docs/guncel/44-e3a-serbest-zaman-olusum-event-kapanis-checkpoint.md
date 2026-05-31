# 44. E3a — Serbest Zaman Oluşum Event Kapanış Checkpoint

**Sürüm:** E3a kod kapanış  
**Tarih:** 2026-05-31  
**Commit:** `9f6fd15` — Add E3a serbest zaman olusum event modeli  
**Ön koşul / karar zemini:** `docs/guncel/41-e2-odeme-tipi-serbest-zaman-karar.md`  
**Önceki zincir:** E2 karar (41) → E2 kapanış (42–43) → E3a oluşum event modeli

---

## 1. Kapanış Özeti

E3a kod fazı `9f6fd15` commit’i ile kapanmıştır.  
CI #332 ve Deploy cPanel #310 **success** doğrulanmıştır.

---

## 2. Amaç

E2’de `SERBEST_ZAMAN` seçilmiş ödeme tercihlerinden idempotent `SERBEST_ZAMAN_OLUSUM` event’i üretmek.  
Oluşum event’leri üzerinden basit serbest zaman bakiye read model sağlamak.

---

## 3. Yapılan Teknik İşler

| Öğe | Dosya / konum |
|-----|----------------|
| Serbest zaman tipleri | `src/types/serbest-zaman.ts` |
| Oluşum event motoru | `src/services/serbest-zaman-event-motoru.ts` |
| GET/POST API client | `src/api/serbest-zaman.api.ts` |
| Endpoint tanımı | `src/api/endpoints.ts` (genişletildi) |
| `serbestZamanEventsById` store + route handler’lar | `src/api/mock-demo.ts` |
| Event motoru unit testleri | `tests/unit/serbest-zaman-event-motoru.test.ts` |
| Serbest zaman API unit testleri | `tests/unit/serbest-zaman.api.test.ts` |

---

## 4. Kilitli Davranışlar

| Kural | Davranış |
|-------|----------|
| Hak oluşumu | Yalnız `SERBEST_ZAMAN` ödeme tercihi için yapılır |
| `UCRET` / `KARAR_BEKLIYOR` | Event üretilmez |
| Persist edilmemiş / sentetik tercih | Event üretilmez |
| Duplicate oluşum | Aynı `kaynak_odeme_tercihi_id` için engellenir |
| Oluşum event’i | Snapshot’ı mutate etmez |
| Oluşum event’i | E2 tercih kaydını mutate etmez |
| Hak dakikası | `hesaplaSerbestZamanDakika` üzerinden hesaplanır |
| FM 60 dk | → 90 dk hak |
| `son_kullanim_tarihi` | Event tarihinden 6 takvim ayı sonrası |
| Ay sonu clamp | Testlenmiştir |
| Bakiye (E3a) | Yalnız `OLUSUM` event’lerinden hesaplanır |
| `kullanilan_dakika` (E3a) | **0**’dır |
| `KULLANIM` / `IPTAL` / `DUZELTME` | Implement edilmemiştir |

---

## 5. API ve Mock Davranışı

| Endpoint / kural | Davranış |
|------------------|----------|
| `GET /serbest-zaman/events?personel_id=` | Personel event listesi |
| `GET /serbest-zaman/bakiye?personel_id=` | Bakiye read model |
| `POST /serbest-zaman/olusum` | Idempotent oluşum tetikleme |
| POST oluşum | Persist edilmiş ödeme tercihi gerekir |
| Duplicate durum | `ALREADY_EXISTS` hata olarak döner |
| Bakiye mock | Store’dan motorla hesaplanır |
| E2 PUT API | Side-effect eklenmemiştir |

---

## 6. Test ve Doğrulama

| Kontrol | Sonuç |
|---------|--------|
| `serbest-zaman-event-motoru` unit | **10/10** geçti |
| `serbest-zaman.api` unit | **7/7** geçti |
| `npm run typecheck` | geçti |
| `npm run test` (full suite) | **474/474** geçti |
| CI | **#332 success** |
| Deploy cPanel | **#310 success** |

---

## 7. Kapsam Dışı

- `SERBEST_ZAMAN_KULLANIM` implementasyonu yok
- `SERBEST_ZAMAN_DUZELTME` implementasyonu yok
- `SERBEST_ZAMAN_IPTAL` implementasyonu yok
- UI / hook / page yok
- Bordro / ücret etkisi yok
- `409 PERIOD_LOCKED` yok
- E2 tercih API değişikliği yok
- Snapshot builder değişikliği yok
- E1 aggregate değişikliği yok
- 270 saat compliance uyarısı yok

---

## 8. Bilinçli Ertelenenler

| Öğe | Not |
|-----|-----|
| E3b kullanım event’i | E3b |
| E3b bakiye düşümü | E3b |
| E3c düzeltme / iptal event’leri | E3c |
| Tercih `SERBEST_ZAMAN` → `UCRET` değişiminde event iptali | E3c |
| 6 ay dolum uyarıları | Sonraki faz |
| UI / rapor tüketimi | Sonraki faz |
| Personel kartı gösterimi | Sonraki faz |
| Append-only audit genişletmesi | E3c+ |

---

## 9. E3b / E3c Bağımlılığı

### E3b için

- `SERBEST_ZAMAN_KULLANIM` event’i
- `kullanilan_dakika` hesabı
- `kalan_dakika` düşümü
- Kullanım tarihi ve açıklama
- Negatif bakiye guard

### E3c için

- `SERBEST_ZAMAN_DUZELTME`
- `SERBEST_ZAMAN_IPTAL`
- Tercih değişimi sonrası hak iptali
- Audit / history genişletmesi

---

## 10. Sonuç

E3a kod fazı kapanmıştır.  
Serbest zaman hak oluşum event hattı kurulmuştur.  
Bakiye modeli E3a’da yalnız oluşum bazlı read modeldir.  
Kullanım, iptal, düzeltme ve UI sonraki fazlara bırakılmıştır.
