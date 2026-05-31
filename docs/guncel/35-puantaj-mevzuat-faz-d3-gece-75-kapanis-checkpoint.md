# 35. Puantaj Mevzuat Faz D3 — Gece Çalışması 7,5 Saat Kapanış Checkpoint

**Sürüm:** Faz D3 kapanış (gece bandı compliance — davranış parity)  
**Ön koşul / karar zemini:** Faz D teşhis raporu, `docs/guncel/32-puantaj-mevzuat-uyum-review.md` (Faz D)  
**Önceki faz:** Faz C — `docs/guncel/34-puantaj-mevzuat-faz-c-kapanis-checkpoint.md`

---

## 1. Ön koşul

- Faz D teşhis raporu tamamlanmıştır (18↓ FM, gece 7,5, FM onayı ayrıştırması).
- `docs/guncel/32-puantaj-mevzuat-uyum-review.md` Faz D karar zemini geçerlidir.
- Faz A (`UBGT_FAZLA_MESAI_CAKISMASI`), Faz B (mazeretsiz devamsızlık aday uyarıları / parasal kilit) ve Faz C (hafta tatili günü domain helper) davranışları korunmuştur.

---

## 2. Kapanış özeti

**Faz D3 kapanmıştır.** Günlük puantajda gece çalışma süresi **7,5 saat** aşımı için compliance uyarısı eklendi.

Mevcut hesap, mola, net süre, UI, API, hook, bordro ve Faz A/B/C davranışları **değiştirilmedi**. Uyarı yalnızca motor içinde `compliance_uyarilari` hattına bağlandı; tutar veya kayıt kabul/blok davranışı etkilenmedi.

---

## 3. Commit zinciri

| Sıra | Commit | Açıklama |
|------|--------|----------|
| 1 | `3acec85` | Add Faz D3 gece calismasi compliance |

---

## 4. CI / Deploy

| Workflow | Run | Sonuç |
|----------|-----|--------|
| CI | #315 | success |
| Deploy cPanel | #293 | success |

---

## 5. Eklenen compliance

| Öğe | Değer |
|-----|--------|
| Kod | `GECE_CALISMASI_7_5_SAAT_ASIMI` |
| Seviye | `UYARI` |
| Mesaj | Gece çalışma süresi günlük 7,5 saat sınırını aşıyor. |
| Eşik | Gece bandı **brüt** çalışma dakikası **> 450** |
| Tam sınır | 450 dakika → uyarı **üretilmez** |

### Teknik notlar

- Gece bandı V1: **20:00–24:00** ve **00:00–06:00** (sabit).
- `hesaplaGeceCalismaDakika(giris, cikis)` — aynı gün `giriş < çıkış` kayıtları; mola gece bandına dağıtılmaz.
- `uretComplianceUyarilari` içinde tetiklenir; `hesapla()` çıktısına otomatik yansır.

---

## 6. Korunan davranışlar

- `GECE_MESAI` bilgi uyarısı (`BILGI`) korundu.
- `MAX_DAILY_LIMIT` (11 saat / kritik eşik) korundu.
- Net çalışma süresi değişmedi.
- Mola hesabı değişmedi.
- Faz A / Faz B / Faz C compliance davranışları değişmedi.
- UI değişmedi.
- Hook değişmedi.
- API değişmedi.

---

## 7. Değişen dosyalar

| Dosya | Rol |
|-------|-----|
| `src/services/puantaj-hesap-motoru.ts` | `hesaplaGeceCalismaDakika`, D3 compliance sabitleri, `uretComplianceUyarilari` genişlemesi |
| `tests/unit/puantaj-hesap-motoru.test.ts` | Helper + compliance regression testleri |

---

## 8. Doğrulama (kapanış anı)

| Komut | Sonuç |
|-------|--------|
| `npm run typecheck` | Yeşil |
| `npm run test` | 401 passed |
| `npx playwright test tests/e2e/smoke.spec.ts` | 4 passed |
| CI #315 | success |
| Deploy cPanel #293 | success |

---

## 9. Kapsam dışı (bilinçli)

- Gece yarısını geçen vardiya desteği
- Mola dakikasını gece bandına dağıtmak
- 18 yaş altı fazla çalışma compliance (Faz D2)
- Fazla çalışma onayı eksikliği (Faz D1)
- Personel formu / API persist
- Bordro / SGK
- Serbest zaman / 270 saat (Faz E)

---

## 10. Sonraki adım

Yeni teknik faz **kullanıcı onayı** ile açılır.

| Aday | İçerik |
|------|--------|
| **Faz D2** | 18 yaş altı haftalık fazla çalışma compliance |
| **Faz D1** | Fazla çalışma onayı eksikliği — onay alanı / workflow gelmeden **bekletilecek** |

**Belge durumu:** Faz D3 kapalı — Faz D2 / D1 bekleniyor.
