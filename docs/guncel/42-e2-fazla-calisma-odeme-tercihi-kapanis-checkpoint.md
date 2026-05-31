# 42. E2 — Fazla Çalışma Ödeme Tercihi Kapanış Checkpoint

**Sürüm:** E2 kod kapanış  
**Tarih:** 2026-05-31  
**Commit:** `ce55567` — Add E2 fazla calisma odeme tercihi  
**Ön koşul / karar zemini:** `docs/guncel/41-e2-odeme-tipi-serbest-zaman-karar.md`  
**Önceki zincir:** Faz E karar (38) → Snapshot sözleşmesi (39) → A1 contract → A2 builder → A3 detail store → E1 yıllık aggregate → E2 karar (41)

---

## 1. Kapanış Özeti

E2 dar kod fazı `ce55567` commit’i ile kapanmıştır.  
CI #329 ve Deploy cPanel #307 **success** doğrulanmıştır.

---

## 2. Amaç

Doc 41 kararına uygun şekilde `odeme_tipi` bilgisini snapshot dışında ayrı tercih kaydı olarak modellemek.  
Serbest zaman dönüşüm helper’ını eklemek.  
Mock-demo üzerinde GET/PUT tercih hattını kurmak.

---

## 3. Yapılan Teknik İşler

| Öğe | Dosya / konum |
|-----|----------------|
| `FazlaCalismaOdemeTercihi` tipi | `src/types/fazla-calisma-odeme-tercihi.ts` |
| `hesaplaSerbestZamanDakika` helper | `src/services/serbest-zaman-donusum.ts` |
| GET/PUT API client | `src/api/fazla-calisma-odeme-tercihi.api.ts` |
| Endpoint tanımı | `src/api/endpoints.ts` (genişletildi) |
| `odemeTercihiBySnapshotId` store + GET/PUT handler | `src/api/mock-demo.ts` |
| Serbest zaman dönüşüm unit testleri | `tests/unit/serbest-zaman-donusum.test.ts` |
| Ödeme tercihi API unit testleri | `tests/unit/fazla-calisma-odeme-tercihi.api.test.ts` |

---

## 4. Kilitli Davranışlar

| Kural | Davranış |
|-------|----------|
| `odeme_tipi` snapshot alanı | **Değildir** |
| `HaftalikKapanisSnapshotSatir` | Değiştirilmemiştir |
| Snapshot builder | Değiştirilmemiştir |
| E1 aggregate | Değiştirilmemiştir |
| Default `odeme_tipi` | `KARAR_BEKLIYOR` |
| GET store boşsa | Sentetik `KARAR_BEKLIYOR` döner |
| Sentetik GET sonucu | Persist edilmez |
| PUT snapshot satırı | Mutate etmez |
| PUT tercih kaydı | `snapshot_id` bazlı ayrı store’a yazar |
| `onceki_odeme_tipi` | Son değişiklik bilgisi olarak tutulur |
| `secim_zamani` | ISO string olarak yazılır |
| Helper | `odeme_tipi` bilmez |
| Helper | Hak / event / bakiye üretmez |

---

## 5. Dönüşüm Kuralları

| Girdi | Katsayı |
|-------|---------|
| `fazla_calisma_dakika` | × 1.5 |
| `fazla_surelerle_calisma_dakika` | × 1.25 |

| Kenar durum | Davranış |
|-------------|----------|
| Negatif / NaN / null / undefined | **0** kabul edilir |
| Sonuç | `Math.round` ile tam dakikaya çevrilir |

---

## 6. Test ve Doğrulama

| Kontrol | Sonuç |
|---------|--------|
| `serbest-zaman-donusum` unit | **5/5** geçti |
| `fazla-calisma-odeme-tercihi.api` unit | **6/6** geçti |
| `npm run typecheck` | geçti |
| `npm run test` (full suite) | **457/457** geçti |
| CI | **#329 success** |
| Deploy cPanel | **#307 success** |

---

## 7. Kapsam Dışı

- Serbest zaman hak event’i yok
- Serbest zaman bakiye yok
- 6 ay son kullanım tarihi yok
- Bordro / ücret sonucu yok
- UI / hook / page yok
- `409 PERIOD_LOCKED` yok
- Snapshot tipi değişikliği yok
- Snapshot builder değişikliği yok
- E1 aggregate değişikliği yok
- 270 saat compliance uyarısı yok

---

## 8. Bilinçli Ertelenenler

| Öğe | Not |
|-----|-----|
| E3 `SERBEST_ZAMAN_OLUSUM` event modeli | E3 |
| E3 `SERBEST_ZAMAN_KULLANIM` event modeli | E3 |
| Bakiye ve 6 ay son kullanım tarihi | E3 |
| Append-only audit geçmişi | E3+ |
| PUT `UCRET` ayrı test | Sonraki test turu |
| Unknown-field explicit assert | Sonraki test turu |
| Mock integration ile snapshot immutability testi | Sonraki test turu |
| UI / rapor tüketimi | E4 veya ürün fazı |

---

## 9. E3 Bağımlılığı

E3 ancak şu kaynakları kullanarak açılmalı:

- `FazlaCalismaOdemeTercihi`
- `OdemeTipi === SERBEST_ZAMAN`
- `hesaplaSerbestZamanDakika`
- `kaynak_snapshot_id`
- `kaynak_odeme_tercihi_id`

E3 açılmadan hak / bakiye oluşmuş kabul edilmeyecek.

---

## 10. Sonuç

E2 dar kod fazı kapanmıştır.  
Doc 41 kararı teknik olarak uygulanmıştır.  
Snapshot read-only kalmıştır.  
Serbest zaman hak / bakiye / event modeli hâlâ E3 kapsamındadır.
