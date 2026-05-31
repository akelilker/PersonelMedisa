# 40. E1 — 270 Saat Aggregate Kapanış Checkpoint

**Sürüm:** E1 kapanış  
**Tarih:** 2026-05-31  
**Commit:** `b907953` — Add yillik fazla calisma aggregate  
**Ön koşul / karar zemini:** `docs/guncel/38-puantaj-mevzuat-faz-e-serbest-zaman-270-saat-karar.md`, `docs/guncel/39-haftalik-kapanis-snapshot-sozlesmesi-karar.md`  
**Önceki zincir:** Faz E karar (38) → Snapshot sözleşmesi (39) → A1 contract → A2 builder → A3 detail store

---

## 1. Kapanış özeti

E1 teknik fazı `b907953` commit’i ile `main` / `origin/main` üzerinde kapanmıştır. CI ve Deploy cPanel **success** doğrulanmıştır.

Bu faz yalnız **yıllık fazla çalışma sayısal özetini** kurmuştur. Serbest zaman, ödeme tercihi, bordro, fazla çalışma onayı ve UI kapsamına girilmemiştir.

**Kapanış cümlesi:** E1 — 270 Saat Aggregate teknik fazı `b907953` commit’i ile main/origin main üzerinde kapanmış, CI ve Deploy success doğrulanmıştır. Bu faz yalnız yıllık fazla çalışma sayısal özetini kurmuştur; serbest zaman, ödeme tercihi, bordro, onay ve UI kapsamına girilmemiştir.

---

## 2. Amaç

Haftalık kapanış snapshot satırlarındaki `fazla_calisma_dakika` değerlerinden **personel + yıl** bazlı yıllık toplam üretmek.

```text
Kapanmış haftalık snapshot fazla_calisma_dakika
  → aggregateYillikFazlaCalisma
  → GET /haftalik-kapanis/yillik-fazla-calisma?personel_id=&yil=
```

Kaynak: `demoState.kapanisById` (mock) / ileride prod persist katmanı. Canlı hook cache veya günlük puantaj ön izlemesi **kaynak kabul edilmez** (doc 39).

---

## 3. Yapılan teknik işler

| Öğe | Dosya / konum |
|-----|----------------|
| `YillikFazlaCalismaOzeti` tipi | `src/types/haftalik-kapanis.ts` |
| `aggregateYillikFazlaCalisma` servisi | `src/services/yillik-fazla-calisma-aggregate.ts` |
| `haftalikKapanis.yillikFazlaCalisma` endpoint | `src/api/endpoints.ts` |
| `fetchYillikFazlaCalismaOzeti` API client | `src/api/haftalik-kapanis.api.ts` |
| mock-demo GET handler | `src/api/mock-demo.ts` |
| aggregate unit testleri | `tests/unit/yillik-fazla-calisma-aggregate.test.ts` |
| haftalık-kapanis API unit test genişlemesi | `tests/unit/haftalik-kapanis.api.test.ts` |

### Commit zinciri (E1 öncesi kapalı zincir)

| Sıra | Commit / belge | Açıklama |
|------|----------------|----------|
| 1 | doc 38 | Faz E karar — 270 saat / serbest zaman bilinçli erteleme |
| 2 | doc 39 | Haftalık kapanış snapshot sözleşmesi |
| 3 | `e51efdf` | A1 — Snapshot contract |
| 4 | `2738e05` | A2 — Snapshot builder |
| 5 | `9f3a51e` | A3 — Snapshot detail store |
| 6 | `b907953` | **E1** — 270 saat aggregate |

---

## 4. Hesap kuralları

| Kural | Değer / davranış |
|-------|------------------|
| Yıllık limit | 270 saat = **16200** dakika (`YILLIK_FAZLA_CALISMA_LIMIT_DAKIKA`) |
| Yaklaşma eşiği | 260 saat = **15600** dakika (`YILLIK_FAZLA_CALISMA_YAKLASMA_ESIK_DAKIKA`) |
| Toplanan alan | Yalnız `fazla_calisma_dakika` |
| Dışarıda | `fazla_surelerle_calisma_dakika` (E1 dışı) |
| State filtresi | Yalnız `state === "KAPANDI"` |
| Tam hafta | Yalnız `tam_hafta_verisi === true` toplanır; `false` → `atlanan_eksik_hafta_sayisi` |
| Yıl filtresi | Önce `satir.yil`; yoksa `hafta_baslangic` tarihinden türetilir |
| Duplicate hafta | Anahtar: `personel_id` + `yil` + `hafta_baslangic`; **en yüksek `kapanis_id`** kazanır; diğerleri `atlanan_duplicate_hafta_sayisi` |
| Boş veri | `kullanilan_dakika = 0`, `kalan_dakika = 16200`, bayraklar `false`, sayaçlar `0` |
| Geçersiz FM | Negatif / NaN `fazla_calisma_dakika` → **0** |
| `limit_asildi_mi` | `kullanilan_dakika > limit` (tam 16200’de `false`) |
| `limit_yaklasiyor_mu` | `kullanilan_dakika >= yaklasma_esik_dakika` |
| `kalan_dakika` | `max(0, limit - kullanilan_dakika)` |

### Response contract (`YillikFazlaCalismaOzeti`)

`personel_id`, `yil`, `yillik_limit_dakika`, `yaklasma_esik_dakika`, `kullanilan_dakika`, `kalan_dakika`, `limit_asildi_mi`, `limit_yaklasiyor_mu`, `kapanan_hafta_sayisi`, `atlanan_duplicate_hafta_sayisi`, `atlanan_eksik_hafta_sayisi`.

---

## 5. Test ve doğrulama

| Kontrol | Sonuç |
|---------|--------|
| aggregate unit (`yillik-fazla-calisma-aggregate.test.ts`) | **11/11** geçti |
| haftalik-kapanis.api unit | **15/15** geçti |
| haftalik-kapanis-snapshot unit | **9/9** geçti |
| `npm run typecheck` | geçti |
| `npm run test` (full) | **446/446** geçti |
| e2e smoke (`tests/e2e/smoke.spec.ts`) | **4/4** geçti |

### CI / Deploy (`b907953`)

| Workflow | Run | Sonuç |
|----------|-----|--------|
| CI | [#26711154611](https://github.com/akelilker/PersonelMedisa/actions/runs/26711154611) | **success** |
| Deploy cPanel | [#26711154605](https://github.com/akelilker/PersonelMedisa/actions/runs/26711154605) | **success** |

---

## 6. Kapsam dışı (bu faz)

- Serbest zaman workflow ve bakiye
- `odeme_tipi`
- Bordro / ücret etkisi
- Fazla çalışma onayı (`fazla_calisma_onayi_var_mi`)
- UI / hook / page / route
- `409 PERIOD_LOCKED` / haftalık düzenleme kilidi
- Compliance kodu üretimi (`YILLIK_FAZLA_CALISMA_*`)
- Yıllık rapor ekranı / personel kartı tüketimi
- `tests/e2e/helpers/mock-api.ts` senkronu

---

## 7. Bilinçli ertelenenler

| Öğe | Not |
|-----|-----|
| `YILLIK_FAZLA_CALISMA_270_SAAT_YAKLASIYOR` / `_ASILDI` compliance | E1b veya Faz E alt fazı |
| Tam limit (16200 dk) sınır assert testi | E1b |
| mock `resolveDemoApiResponse` entegrasyon roundtrip | E1b |
| Rapor / UI tüketimi | E4 veya ürün fazı |
| Serbest zaman event + bakiye modeli | E3 |
| `odeme_tipi` / ücret vs serbest zaman kararı | E2 teşhisi |

---

## 8. Sonraki faz adayları

Önerilen sıra (ürün onayı ile):

1. **E1b** — 270 saat compliance uyarı kodları (`birlestir*` + hook merge) ve ek testler  
2. **E2** — `odeme_tipi` / ücret mi serbest zaman mı karar teşhisi  
3. **E3** — serbest zaman event + bakiye modeli  
4. **E4** — UI / rapor tüketimi (E1 özet + isteğe bağlı E3 bakiye)

---

## 9. Sonuç

**E1 teknik fazı kapanmıştır.**

`docs/guncel/38-puantaj-mevzuat-faz-e-serbest-zaman-270-saat-karar.md` içindeki “güvenilir haftalık snapshot / yıllık toplam yok” blokajı, **A1 → A2 → A3 → E1** zinciriyle teknik olarak kaldırılmıştır: kapanmış hafta `fazla_calisma_dakika` satırları üzerinden personel+yıl aggregate üretilebilir.

Serbest zaman, ödeme tercihi ve bordro etkisi **bilinçli olarak kodlanmamıştır**; Faz E üst kapsamının kalan parçaları E2/E3 ve tüketim katmanlarında devam edecektir.

---

## 10. Değişen dosyalar (`b907953`)

| Dosya | Değişiklik |
|-------|------------|
| `src/services/yillik-fazla-calisma-aggregate.ts` | Yeni |
| `src/types/haftalik-kapanis.ts` | `YillikFazlaCalismaOzeti` |
| `src/api/endpoints.ts` | `yillikFazlaCalisma` |
| `src/api/haftalik-kapanis.api.ts` | `fetchYillikFazlaCalismaOzeti` |
| `src/api/mock-demo.ts` | GET aggregate route |
| `tests/unit/yillik-fazla-calisma-aggregate.test.ts` | Yeni |
| `tests/unit/haftalik-kapanis.api.test.ts` | +5 test |

**Diff hacmi:** 7 dosya, +713 / −4 satır.
