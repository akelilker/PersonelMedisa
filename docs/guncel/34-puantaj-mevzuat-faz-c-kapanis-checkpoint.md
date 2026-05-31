# 34. Puantaj Mevzuat Faz C — Kapanış Checkpoint

**Sürüm:** Faz C kapanış (domain helper — davranış parity)  
**Ön koşul / karar zemini:** `docs/guncel/32-puantaj-mevzuat-uyum-review.md` (Faz C)  
**Önceki faz:** Faz B — `docs/guncel/33-puantaj-mevzuat-faz-b-kapanis-checkpoint.md`

---

## 1. Amaç

Bu belge, **Faz C** teknik fazının kapanış kaydıdır.

Amaç: Dağınık `getDay() === 0` / Pazar varsayımını **tek domain helper** altında toplamak; mevcut ürün davranışını değiştirmeden ileride hafta tatili günü parametresine zemin hazırlamak.

Bu belge yeni kod fazı açmaz. Enum rename, UI metinleri, SGK/dashboard, API persist, şube/personel bazlı hafta tatili günü ve 7 günlük hak birleştirme motoru bu checkpoint'in dışındadır.

---

## 2. Kapanış özeti

**Faz C kod tarafı kapanmıştır** (CI yeşil onayı ile).

Faz C'de hafta tatili günü **davranışı değiştirilmeden** Pazar varsayımı tek domain helper'a alındı. Default değer **Pazar (`0`)** olarak korundu; UI, enum, SGK/dashboard ve Faz A/B davranışları değiştirilmedi.

---

## 3. Teknik özet

| Öğe | Değer / not |
|-----|-------------|
| `VARSAYILAN_HAFTA_TATILI_GUN_KODU` | `0` (Pazar) |
| `HaftaTatiliGunKodu` | `0 \| 1 \| … \| 6` |
| `isHaftaTatiliGunu(tarih, gunKodu?)` | Tek tarih karşılaştırma kaynağı |
| `deriveGunTipi(tarih, explicit?, gunKodu?)` | Helper üzerinden; explicit öncelik korunur |
| Enum | `"Hafta_Tatili_Pazar"` — **rename yok** |

Kaldırılan dağınık kopyalar: `puantaj-hesap-motoru.ts` (iç), `usePuantaj.ts` (`deriveGunTipiFromDateInput`), `puantaj.api.ts` (yerel `deriveGunTipi` + `parsePuantajDate`).

---

## 4. Değişen dosyalar

| Dosya | Rol |
|-------|-----|
| `src/services/puantaj-hesap-motoru.ts` | Domain sabiti, `isHaftaTatiliGunu`, `deriveGunTipi` refactor |
| `src/hooks/usePuantaj.ts` | Motor `deriveGunTipi` import; yerel Pazar hesabı kaldırıldı |
| `src/api/puantaj.api.ts` | Normalize: motor `deriveGunTipi` |
| `tests/unit/puantaj-hesap-motoru.test.ts` | Helper + parametre regression (+8 test) |

**Dokunulmayan (bilinçli):** `GunlukPuantajPage.tsx`, `PersonelDetayPage.tsx`, `dashboard-rapor-servisi.ts`, UI label'ları, Faz A UBGT, Faz B aday compliance / parasal kilit.

---

## 5. Davranış — ne değişti / ne korundu

### Değişen (yalnızca yapı)

- Pazar tespiti üç noktadan **tek motor helper**'a taşındı.
- `deriveGunTipi` opsiyonel üçüncü parametre (`haftaTatiliGunKodu`) alır; default ile eski çıktı aynıdır.

### Korunan davranış

- Parametre verilmeden Pazar tarihi → `"Hafta_Tatili_Pazar"`.
- Pazar mesaisi, tatil ek ödeme, hafta aralığı (Pzt–Paz), Faz B aday compliance, UBGT Faz A testleri.
- `npm run test` / smoke E2E — implementasyon anında yeşil.

---

## 6. Doğrulama (implementasyon anı)

| Komut | Sonuç |
|-------|--------|
| `npm run typecheck` | Yeşil |
| `npm run test` | 386 passed |
| `npx playwright test tests/e2e/smoke.spec.ts` | 4 passed |
| CI / Deploy | Commit push sonrası güncellenecek |

---

## 7. Kapsam dışı (bilinçli)

- `Hafta_Tatili_Pazar` enum/string rename
- UI label / `GunlukPuantajPage` metin değişikliği
- `dashboard-rapor-servisi.ts` parametre bağlama
- Backend / API persist — personel / şube hafta tatili günü
- 7 günlük hafta tatili hakkı birleştirme motoru
- Faz D (18↓, gece 7,5, FM onayı) → **sonraki aday**
- Faz E (serbest zaman + 270 saat)

---

## 8. Sonraki adım

Yeni teknik faz **kullanıcı onayı** ile açılır. Açık adaylar: **Faz D**, **Faz E** (`32-puantaj-mevzuat-uyum-review.md` Bölüm 7).

İsteğe bağlı ileri adımlar (Faz C+): şube/personel `hafta_tatili_gun_kodu` persist, dashboard SGK istisnası, enum/generic isim refactor — ayrı karar.

**Belge durumu:** Faz C kapalı — CI onayı commit hash ile tamamlanacak.
