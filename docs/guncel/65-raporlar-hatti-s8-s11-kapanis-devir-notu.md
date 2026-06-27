# 65 — Raporlar Hattı S8–S11 Kapanış Devir Notu

---

## 1. Amaç

Bu not, Raporlar ekranında S8, S9, S10B, S11B-1 ve S11B-2 sprintleriyle **E2E testlerle kilitlenen** davranışları tek yerde toplar.

Sonraki geliştiricinin Raporlar hattında nereden devam edeceğini, hangi owner dosyalarına dokunacağını ve hangi konuların bilinçli olarak açık bırakıldığını göstermek için yazılmıştır.

**Bu not implementasyon talimatı değildir.** Yeni özellik eklemeden önce §7 açık ürün kararlarından biri seçilmeli ve ilgili E2E guard'ları (§8) korunmalıdır.

**Son kilit commit:** `6e4984d` — `test: raporlar departman filtre smoke akisini kilitle`  
**Branch:** `main` (`origin/main` ile hizalı)

---

## 2. Kapsanan sprintler

| Sprint | Commit | Kilitlenen davranış |
| ------ | ------ | ------------------- |
| S8 | `813f7b8` | 8 rapor tipi smoke + kolon contract |
| S9 | `7ce88d9` | Detaylı Liste pagination |
| S10B | `654f0ec` | Aylık Kapanış Özeti CSV export |
| S11B-1 | `fbf72db` | Rol görünürlüğü + Aylık Özet şube filtresi |
| S11B-2 | `6e4984d` | Detaylı Liste departman filtresi |

---

## 3. Raporlar ekranı mevcut modül ayrımı

`/raporlar` sayfası (`RaporlarPage.tsx`) iki bağımsız alt modül içerir. Owner'ları karıştırılmamalıdır.

### A) Detaylı Liste

- **8 rapor tipi:** `personel-ozet`, `izin`, `devamsizlik`, `tesvik`, `ceza`, `ekstra-prim`, `is-kazasi`, `bildirim`
- **Kolon contract:** `getRaporColumns(type)` — `rapor-column-contract.ts`
- **API:** `fetchRapor(raporTipi, filters)` — `raporlar.api.ts`
- **Filtreler:** tarih aralığı, personel_id, **departman_id** (`[name="rapor-departman"]`, type=number)
- **Pagination:** `page` / `limit` query; UI'da Onceki / Sonraki ve `Sayfa X / Y`
- **Şube filtresi yok** — bilinçli ürün kararı (§7)
- **CSV export yok** — Aylık Özet export ile karıştırılmaz (§7)
- **Permission:** `raporlar.view` — tüm roller erişir

### B) Aylık Kapanış Özeti

- **Ayrı permission:** `aylik-ozet.view`
- **Görünürlük:**
  - `GENEL_YONETICI` — görünür
  - `BOLUM_YONETICISI` — görünür
  - `MUHASEBE` — görünmez
  - `BIRIM_AMIRI` — görünmez
- **Filtreler:** ay, **şube** (`[name="aylik-ozet-sube"]`), bölüm/departman
- **CSV export:** `Excel'e Aktar` → `aylik-kapanis-ozeti-{ay}.csv`
- **Onay akışları:** `aylik-ozet.review` (bölüm), `aylik-ozet.executive_ack` (üst onay)
- **data-testid:** `aylik-kapanis-ozeti-section`
- Detaylı Liste export/print akışlarından **bağımsızdır**

---

## 4. Kilitlenen E2E davranışları

Kaynak test dosyası: `tests/e2e/raporlar.spec.ts`

### S8 — 8 tip + kolon contract

- 8 rapor tipi tek tek `[name="rapor-turu"]` select + `raporlar-submit-run` ile çalıştırılır.
- Her tipte mock fixture **1 satır** döner; satır marker assert edilir:
  - `personel-ozet` … `is-kazasi` → **Ayşe Yılmaz**
  - `bildirim` → **Mehmet Kaya**
- `raporlar-resmi-sonuc` görünür; tbody `tr` count = 1.
- `thead th` metinleri `getRaporColumns(type).map(c => c.label)` ile **birebir** eşleşir.

### S9 — Pagination

- Rapor tipi: `personel-ozet`, filtre yok.
- **Page 1:** Ayşe Yılmaz görünür; `Sayfa 1 / 2`; Sonraki enabled, Onceki disabled.
- **Page 2:** Mehmet Kaya görünür; Ayşe görünmez; `Sayfa 2 / 2`; Onceki enabled, Sonraki disabled.
- **Geri dönüş:** Page 1'e dönünce Ayşe tekrar görünür; Mehmet görünmez; `Sayfa 1 / 2`.
- `pageerror` yok.

### S10B — Aylık CSV export

- `aylik-kapanis-ozeti-section` görünür; tabloda 2 satır (Ayşe + Mehmet).
- `Excel'e Aktar` tıklanınca `download` event tetiklenir.
- Dosya adı: `aylik-kapanis-ozeti` içerir, `.csv` uzantılı.
- CSV içeriği: `Ad Soyad`, `Ayşe Yılmaz`, `Mehmet Kaya`.
- `pageerror` yok.

### S11B-1 — Rol / section görünürlüğü

| Rol | Detaylı Liste | Aylık Özet section |
| --- | ------------- | ------------------ |
| GENEL_YONETICI | görünür | görünür |
| BOLUM_YONETICISI | görünür | görünür |
| MUHASEBE | görünür | **yok** (`toHaveCount(0)`) |
| BIRIM_AMIRI | görünür | **yok** |

Ek test: GENEL_YONETICI şube filtresi **Merkez** seçince tablo 1 satıra iner (Ayşe görünür, Mehmet görünmez).

### S11B-2 — Departman filtresi

- Rapor tipi: `personel-ozet`; `[name="rapor-departman"]` = `3`.
- **Ayşe Yılmaz** görünür; **Mehmet Kaya** görünmez.
- `Sayfa 1 / 1`; Onceki ve Sonraki **disabled**.
- `pageerror` yok.

---

## 5. Test / mock owner haritası

| Alan | Owner |
| ---- | ----- |
| E2E test | `tests/e2e/raporlar.spec.ts` |
| Mock API | `tests/e2e/helpers/mock-api.ts` |
| Rapor sayfası | `src/features/raporlar/pages/RaporlarPage.tsx` |
| Kolon contract | `src/features/raporlar/rapor-column-contract.ts` |
| API client | `src/api/raporlar.api.ts` |
| Rol permission | `src/lib/authorization/role-permissions.ts` |

İlgili doküman referansları:

- `docs/guncel/09-rol-yetki-matrisi.md` — `raporlar.view`, `aylik-ozet.*` izinleri
- `docs/guncel/64-haftalik-kapanis-raporlar-bekleyen-kararlar-devir-notu.md` — haftalık kapanış → raporlar ileri faz kararları (henüz koda taşınmadı)

---

## 6. Mevcut mock veri notu

Kaynak: `tests/e2e/helpers/mock-api.ts` — `PERSONEL_OZET_PAGINATED_ITEMS` ve `personelOzetPaginatedBody()`

**Personel özet mock fixture:**

| Personel | departman_id | Not |
| -------- | ------------ | --- |
| Ayşe Yılmaz | 3 | Döşeme karşılığı |
| Mehmet Kaya | 6 | Depo karşılığı |

**Filtresiz pagination (S9 korunmalı):**

- `page=1` → Ayşe Yılmaz
- `page=2` → Mehmet Kaya
- `total=2`, `total_pages=2`, `has_next_page` / `has_prev_page` sayfa numarasına göre

**departman_id filtresi geldiğinde:**

- Liste `departman_id` ile filtrelenir.
- `departman_id=3` → yalnız Ayşe; `total=1`, `total_pages=1`, `has_next_page=false`, `has_prev_page=false`.
- Diğer 7 rapor tipinin mock handler'larına dokunulmamalıdır; bu sprint yalnızca `personel-ozet` pagination handler'ını genişletir.

---

## 7. Ürün kararları / açık işler

### 1. Detaylı Liste şube filtresi

- UI'da yok.
- Ürün kararı gerekir; eklenmeden E2E yazılmamalı.

### 2. BOLUM_YONETICISI otomatik departman scope

- Detaylı Liste'de bölüm yöneticisi için otomatik departman daraltması yok.
- Ürün kararı gerekir.

### 3. Detaylı Liste 8 tip CSV export

- MVP'de eklenmedi.
- Aylık Özet CSV yeterli kabul edildi.
- Eklenirse: aktif sayfa mı tüm sonuç mu export edilecek — ürün kararı gerekir.

### 4. Print / yazdır

- Print utility olabilir; UI akışı E2E ile kilitlenmedi.
- Ayrı sprint konusu.

### 5. Haftalık kapanış snapshot → rapor bağlantısı

- `docs/guncel/64-...` ve `05-state-flow-api-kontrati.md` §13 kapsamında; S8–S11 E2E kapsamı **dışındadır**.

---

## 8. Değişiklik yapılırken korunacak guardlar

1. Raporlar E2E testleri bozulmadan yeni davranış merge edilmez.
2. `getRaporColumns` / `RAPOR_COLUMN_CONTRACT` değişirse S8 E2E güncellenmeden geçilemez.
3. `personel-ozet` mock pagination **filtresiz** davranışı (S9) korunur.
4. `departman_id` filtresi eklenirken veya değiştirilirken pagination meta uyumu korunur (S11B-2).
5. Aylık Özet ile Detaylı Liste owner'ları karıştırılmaz (ayrı form, ayrı permission, ayrı export).
6. Detaylı Liste'ye şube filtresi eklenmeden önce ürün kararı alınır.
7. Mock değişiklikleri yalnızca ilgili rapor handler'ını hedeflemeli; diğer 7 tipin S8 smoke'unu kırmamalı.

---

## 9. Doğrulama komutları

Raporlar hattında değişiklik sonrası minimum gate:

```bash
npm run typecheck
npm run test
npm run build
npx playwright test tests/e2e/raporlar.spec.ts
npm run e2e
```

Tam suite (`npm run e2e`) regresyon güvencesi için önerilir.

---

## 10. Sonuç

- Raporlar ekranının mevcut MVP davranışı (Detaylı Liste 8 tip + pagination + departman filtresi; Aylık Özet rol görünürlüğü + şube filtresi + CSV) E2E ile kilitlendi.
- Push + CI + Deploy: commit `6e4984d` için doğrulandı.
- Bundan sonra Raporlar tarafında yeni geliştirme yapılacaksa önce §7 açık ürün kararlarından biri seçilmeli.
- Raporlar dışı yeni faza geçmek için güvenli devir noktası oluştu.
