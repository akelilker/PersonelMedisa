# 36. Puantaj Mevzuat Faz D2 — 18 Yaş Altı Fazla Çalışma Kapanış Checkpoint

**Sürüm:** Faz D2 kapanış (18↓ haftalık FM compliance — davranış parity)  
**Ön koşul / karar zemini:** Faz D2 teşhis raporu, `docs/guncel/35-puantaj-mevzuat-faz-d3-gece-75-kapanis-checkpoint.md`, `docs/guncel/32-puantaj-mevzuat-uyum-review.md` (Faz D)  
**Önceki faz:** Faz D3 — `GECE_CALISMASI_7_5_SAAT_ASIMI` (`3acec85`)

---

## 1. Ön koşul

- Faz D2 teşhis raporu tamamlanmıştır (18↓ haftalık FM, hook merge, `dogum_tarihi` erişimi).
- Faz D3 (gece 7,5 saat compliance) kapanmıştır — `docs/guncel/35-puantaj-mevzuat-faz-d3-gece-75-kapanis-checkpoint.md`.
- `docs/guncel/32-puantaj-mevzuat-uyum-review.md` Faz D karar zemini geçerlidir.
- Faz A / B / C / D3 davranışları korunmuştur.

---

## 2. Kapanış özeti

**Faz D2 kapanmıştır.** 18 yaş altı personelde haftalık fazla çalışma oluştuğunda compliance uyarısı üretildi.

Mevcut **submit blok** davranışı (`hesaplaYasKuraliBlokMesaji` — gece çalışması, `Mesai_Yaz`) **korunmuştur**. Uyarı yalnızca client-side haftalık/personel bağlamında merge edilir; bordro, API persist, UI ve types değiştirilmedi.

---

## 3. Commit zinciri

| Sıra | Commit | Açıklama |
|------|--------|----------|
| 1 | `05c9012` | Add Faz D2 onsekiz yas alti fazla calisma compliance |

---

## 4. CI / Deploy

| Workflow | Run | Sonuç |
|----------|-----|--------|
| CI | #318 | success |
| Deploy cPanel | #296 | success |

---

## 5. Eklenen compliance

| Öğe | Değer |
|-----|--------|
| Kod | `ONSEKIZ_YAS_ALTI_FAZLA_CALISMA` |
| Seviye | `UYARI` |
| Mesaj | 18 yaş altı personel için haftalık fazla çalışma tespit edildi; mevzuat uyumu manuel doğrulanmalıdır. |

### Tetik koşulları

| Koşul | Açıklama |
|-------|----------|
| `tam_hafta_verisi === true` | Faz A ile aynı; eksik haftada false positive üretilmez |
| `yas <= 18` | `hesaplaYas` + `isOnsekizYasAltiPersonel` (blok eşiği ile hizalı) |
| `fazla_calisma_dakika > 0` | Haftalık özet (`hesaplaHaftalikCalismaOzeti`) |
| Duplicate yok | `complianceUyariKoduVar` dedup |

### Teknik notlar

- Motor: `birlestirOnsekizYasAltiFazlaCalismaUyari` — saf, testlenebilir helper (Faz A/B kalıbı).
- Hook: `personelDogumTarihi` state + `puantajGoruntuleme` / optimistic submit merge zinciri.
- `hesapla()` doğum tarihi almaz; haftalık FM + yaş bağlamı hook’ta birleştirilir.

---

## 6. Korunan davranışlar

- Mevcut yaş **blok** kuralları korundu (gece, Pazar/UBGT `Mesai_Yaz`).
- Eksik `dogum_tarihi` → görüntülemede uyarı **üretilmez**; submit hard fail aynen devam eder.
- UI değişmedi (`GunlukPuantajPage` generic compliance listesi).
- API / types değişmedi.
- Bordro / SGK hesapları değişmedi.
- Faz A (`UBGT_FAZLA_MESAI_CAKISMASI`), Faz B (aday devamsızlık), Faz C (hafta tatili helper), Faz D3 (`GECE_CALISMASI_7_5_SAAT_ASIMI`) değişmedi.

---

## 7. Değişen dosyalar

| Dosya | Rol |
|-------|-----|
| `src/services/puantaj-hesap-motoru.ts` | D2 sabitleri, `isOnsekizYasAltiPersonel`, `birlestirOnsekizYasAltiFazlaCalismaUyari` |
| `src/hooks/usePuantaj.ts` | `personelDogumTarihi` yükleme, merge zinciri (görüntüleme + submit) |
| `tests/unit/puantaj-hesap-motoru.test.ts` | D2 helper + birleştirme regression (+11 test) |

---

## 8. Doğrulama (kapanış anı)

| Komut | Sonuç |
|-------|--------|
| `npm run typecheck` | Yeşil |
| `npm run test` | 412 passed |
| `npx playwright test tests/e2e/smoke.spec.ts` | 4 passed |
| CI #318 | success |
| Deploy cPanel #296 | success |

---

## 9. Kapsam dışı (bilinçli)

- Fazla çalışma onayı / **Faz D1**
- Personel formu / API persist (`fazla_calisma_onayi_var_mi`)
- Bordro / SGK
- Serbest zaman / 270 saat (Faz E)
- Vardiya modeli
- Yaş blok davranışını kaldırma veya gevşetme

---

## 10. Sonraki adım

**Faz D1** için karar dokümanı gerekir: fazla çalışma onayı alanı / workflow olmadan **kodlanmayacak**.

Faz D alt başlıkları (D3 + D2) kapandı; kalan Faz D kalemi yalnızca D1 (onay eksikliği uyarısı).

**Belge durumu:** Faz D2 kapalı — Faz D1 karar bekliyor.
