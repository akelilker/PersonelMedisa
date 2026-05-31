# 33. Puantaj Mevzuat Faz B — Kapanış Checkpoint

**Sürüm:** Faz B kapanış (implementasyon + E2E hizalama)  
**Ön koşul / karar zemini:** `docs/guncel/32-puantaj-mevzuat-uyum-review.md` (Faz B)  
**Önceki faz:** Faz A — `UBGT_FAZLA_MESAI_CAKISMASI` (`80ab02d`)

---

## 1. Amaç

Bu belge, **Faz B** teknik fazının kapanış kaydıdır.

Amaç: Mazeretsiz devamsızlık (`Gelmedi` + `Yok_Izinsiz`) ve hafta tatili hakkı kaybının günlük puantaj hattında **kesin bordro kesintisi** gibi görünmesini engellemek; **aday / ön izleme** dili, compliance uyarıları ve parasal net etki kilidi ile hizalamak.

Bu belge yeni kod fazı açmaz. Finans, bordro kapanış motoru, API persist veya hafta tatili günü parametreleşmesi (Faz C) bu checkpoint’in dışındadır.

---

## 2. Kapanış özeti

**Faz B kapanmıştır.** Mazeretsiz devamsızlık ve hafta tatili hakkı kaybı günlük puantajda kesin bordro kesintisi olarak değil, aday/ön izleme etkisi olarak gösterilmektedir. Parasal net etki hafta/ay kapanışında kesinleşecek şekilde kilitlenmiştir. E2E smoke testi yeni **“Kesinti Adayı Ön İzleme”** başlığına hizalanmış; **CI #311** ve **Deploy cPanel #289** yeşil tamamlanmıştır.

---

## 3. Commit zinciri

| Sıra | Commit | Açıklama |
|------|--------|----------|
| 1 | `5735885` | Faz B implementasyonu (mazeretsiz devamsızlık aday uyarıları, parasal kilidi, UI etiketleri, unit testler) |
| 2 | `2e99e02` | `tests/e2e/smoke.spec.ts` — heading beklentisi **Kesinti Adayı Ön İzleme** |

**CI kırılma nedeni (ara):** Smoke test eski **“Kesinti Ön İzleme”** başlığını arıyordu; uygulama hatası değil, test beklentisi gecikmesi.

---

## 4. GitHub Actions

| Workflow | Run | Sonuç |
|----------|-----|--------|
| CI | #311 | success |
| Deploy cPanel | #289 | success |

---

## 5. Üretilen compliance kodları

| Kod | Seviye | Tetik |
|-----|--------|--------|
| `DEVAMSIZLIK_UCRET_ETKISI_ADAYI` | `UYARI` | `Gelmedi` + `Yok_Izinsiz` |
| `HAFTA_TATILI_HAK_KAYBI_ADAYI` | `UYARI` | Aynı kayıt + `hafta_tatili_hak_kazandi_mi === false` |

Uyarılar `compliance_uyarilari` üzerinden gösterilir; API upsert body’ye yazılmaz (client-side birleştirme, Faz A ile aynı persist sınırı).

---

## 6. Davranış — ne değişti / ne korundu

### Değişen davranış

- Günlük kayıtta mazeretsiz devamsızlık için **aday** compliance uyarıları.
- **Parasal Etki Ön İzleme:** `net_etki_hesaplanabilir_mi = false` + not: *Bordro etkisi hafta/ay kapanışında kesinleşir.*
- Net hesapta mazeretsiz devamsızlık kesintisi **düşülmez**; referans tutar ayrı alanlarda kalır.
- UI: **Kesinti Adayı Ön İzleme**, **Referans Kesinti Adayı**.

### Korunan davranış

- `hesaplaDevamsizlikKesintiOzeti` matematiği (ör. 1 gün + 1 gün HT kaybı → 2 gün eşdeğeri, tutar = 2 × günlük ücret).
- `siniflandirPuantajEksikGunEtkisi` aylık eksik gün hattı.
- Faz A: `UBGT_FAZLA_MESAI_CAKISMASI`.
- UBGT +1 gün, fazla mesai tutar formülleri, `puantaj.api.ts`, CI/deploy workflow dosyaları.

---

## 7. Değişen dosyalar (Faz B + smoke)

| Dosya | Rol |
|-------|-----|
| `src/services/puantaj-hesap-motoru.ts` | Aday uyarı üretimi, parasal net yardımcıları |
| `src/hooks/usePuantaj.ts` | Compliance birleştirme, parasal kilidi |
| `src/features/puantaj/pages/GunlukPuantajPage.tsx` | Etiket düzeltmesi |
| `tests/unit/puantaj-hesap-motoru.test.ts` | Faz B regression |
| `tests/e2e/smoke.spec.ts` | Heading hizalama (`2e99e02`) |

---

## 8. Doğrulama (kapanış anı)

| Komut | Sonuç |
|-------|--------|
| `npm run typecheck` | Yeşil |
| `npm run test` | 378 passed |
| `npx playwright test tests/e2e/smoke.spec.ts` | 4 passed |
| CI #311 | success |
| Deploy cPanel #289 | success |

---

## 9. Kapsam dışı (bilinçli)

- Hafta tatili günü Pazar parametreleşmesi → **Faz C**
- 18 yaş altı / gece 7,5 / fazla çalışma onayı compliance → **Faz D**
- Serbest zaman workflow + 270 saat → **Faz E**
- Kısmi süreli çalışma guard
- Bordro/SGK kesin kapanış motoru
- Compliance uyarılarının backend’e kalıcı yazılması

---

## 10. Sonraki adım

Yeni teknik faz **kullanıcı onayı** ile açılır. Açık adaylar: **Faz C**, **Faz D**, **Faz E** (`32-puantaj-mevzuat-uyum-review.md` Bölüm 7).

**Belge durumu:** Faz B kapalı — yeni faz bekleniyor.
