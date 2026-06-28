# 66 — Puantaj Hattı S13A–S14D Kapanış Devir Notu

---

## 1. Amaç

Bu not, Puantaj hattında S13A owner analizi, S14B owner analizi, S14C mühürleme E2E ve S14D BIRIM_AMIRI read-only E2E sonuçlarını tek yerde toplar.

Sonraki geliştiricinin `/puantaj` hattında nereden devam edeceğini, hangi owner dosyalarına dokunacağını, mock/API drift risklerini ve bilinçli olarak açık bırakılan ürün kararlarını görmesi için yazılmıştır.

**Bu not implementasyon talimatı değildir.** Yeni özellik eklemeden önce §12 açık ürün kararlarından biri seçilmeli ve ilgili E2E guard'ları (§11) korunmalıdır.

**Son kilit commit:** `6a54cd6` — `test: birim amiri puantaj read only akisini kilitle`  
**Branch:** `main` (`origin/main` ile hizalı)

---

## 2. Kapsanan sprintler

| Sprint | Commit / Durum | Kilitlenen veya netleşen davranış |
| ------ | -------------- | ----------------------------------- |
| S13A | analiz-only | Puantaj / Aylık Kapanış owner ayrımı |
| S14B | analiz-only | Puantaj route / mühür modalı owner analizi |
| S14C | `1699297` | Puantaj mühürleme modalı + rol görünürlüğü + mühür sonrası kilit |
| S14D | `6a54cd6` | BIRIM_AMIRI read-only + Amir Kontrol Etti |

---

## 3. Puantaj modül haritası

| Alan | Owner |
| ---- | ----- |
| Route | `src/app/routes.tsx` |
| Route guard | `src/router/ProtectedRoute.tsx` |
| Page | `src/features/puantaj/pages/GunlukPuantajPage.tsx` |
| Hook/state | `src/hooks/usePuantaj.ts` |
| API client | `src/api/puantaj.api.ts` |
| Types | `src/types/puantaj.ts` |
| Permission | `src/lib/authorization/role-permissions.ts` |
| E2E test | `tests/e2e/puantaj.spec.ts` |
| E2E mock | `tests/e2e/helpers/mock-api.ts` |
| Unit | `tests/unit/puantaj*.test.ts` |

İlgili ama ayrı owner'lar:

- Hesap motoru: `src/services/puantaj-hesap-motoru.ts`
- Shell modal başlığı: `src/app/AppShell.tsx` (`Günlük Puantaj`)
- E2E smoke (puantaj parçaları): `tests/e2e/smoke.spec.ts`, `tests/e2e/app-smoke.spec.ts`, `tests/e2e/role-smoke.spec.ts`

---

## 4. Route ve permission contract

| Alan | Değer |
| ---- | ----- |
| Route | `/puantaj` |
| Component | `GunlukPuantajPage` |
| Route permission | `puantaj.view` (`ROUTE_PERMISSION.puantajPage`) |
| Guard | `ProtectedRoute` — yetkisiz rol `/yetkisiz` |

Dört rol de route'a erişir:

- GENEL_YONETICI
- BOLUM_YONETICISI
- MUHASEBE
- BIRIM_AMIRI

### Permission matrisi

| Rol | view | update | muhurle | amir_kontrol |
| --- | ---- | ------ | ------- | ------------ |
| GENEL_YONETICI | var | var | var | yok |
| BOLUM_YONETICISI | var | var | var | yok |
| MUHASEBE | var | var | yok | yok |
| BIRIM_AMIRI | var | yok | yok | var |

**Not:** GENEL_YONETICI ve BOLUM_YONETICISI `puantaj.amir_kontrol` taşımaz; ancak `puantaj.update` ile `Amir Kontrol Etti` butonu görünür olabilir (`canMarkAmirKontrol = update \|\| amir_kontrol`).

---

## 5. Günlük puantaj davranışı

| Konu | Detay |
| ---- | ----- |
| Kayıt getirme | `GET /api/gunluk-puantaj/{personelId}/{tarih}` — `fetchGunlukPuantaj` |
| Kaydetme | `PUT /api/gunluk-puantaj/{personelId}/{tarih}` — `upsertGunlukPuantaj` |
| Hook owner | `usePuantaj` — form state, cache, optimistic submit |
| Hesap motoru | `puantaj-hesap-motoru` — frontend `hesapla()` + `hesapSonucuToGunlukPuantaj` |
| UI akışı | Personel ID + Tarih → **Kaydı Getir** → detay kartı + günlük kayıt formu |

Frontend hesaplamalı optimistic UI vardır: kaydetmeden önce motor çalışır, sonra PUT gönderilir.

E2E mock (`tests/e2e/helpers/mock-api.ts`) PUT handler hesap motorunu tam çalıştırmaz; alan merge eder. Bu nedenle:

- Hesap motoru derin doğrulama → **unit test** (`puantaj-hesap-motoru.test.ts`)
- Kullanıcı akışı / rol davranışı → **E2E** (`puantaj.spec.ts`, kısmen `smoke.spec.ts`)

E2E seed kayıtları (`puantajKayitlari`): personel 1 → `2026-04-09`, `2026-04-10`; personel 2 → `2026-04-09`. Bilinmeyen tarih için GET sentetik default döner.

---

## 6. Mühürleme davranışı

| UI öğesi | Selector / alan |
| -------- | ---------------- |
| Mühür butonu | `data-testid="muhur-ay-kapat-btn"` — metin: **Ayı Kapat / Mühürle** |
| Modal | `data-testid="muhur-modal"` |
| Dönem | `[name="muhur-donem"]` (type=month) |
| Onay | `data-testid="muhur-onayla-btn"` |
| Sonuç | `data-testid="muhur-sonuc"` |
| Mühürlü uyarı | `data-testid="muhur-uyari"` |
| Kaydet | `data-testid="puantaj-kaydet"` |

| Konu | Detay |
| ---- | ----- |
| API | `POST /api/puantaj/muhurle` — payload `{ yil, ay }` — `muhurleAylikPuantaj` |
| Yetkili roller | GENEL_YONETICI, BOLUM_YONETICISI (`puantaj.muhurle`) |
| Yetkisiz roller | MUHASEBE, BIRIM_AMIRI — buton render edilmez |
| Başarı sonrası modal | Otomatik kapanmaz; kullanıcı **Vazgeç** veya modal close ile kapatır |
| Mühür sonrası kayıt | `state: "MUHURLENDI"` — `refetchActive()` ile UI güncellenir |
| Mühür sonrası UI | `muhur-uyari` görünür; `puantaj-kaydet` disabled |

---

## 7. BIRIM_AMIRI read-only / Amir Kontrol davranışı

| Konu | Detay |
| ---- | ----- |
| Route erişimi | Var (`puantaj.view`) |
| Kaydet | `puantaj-kaydet` **disabled** (`canEditForm = false`) |
| Mühür | `muhur-ay-kapat-btn` yok |
| Read-only mesaj | **"Bu modülü sadece görüntüleme yetkin var."** |
| Form inputları | Tamamen disabled değil; örn. `[name="puantaj-giris"]` enabled kalabilir |
| Asıl kısıt | Kaydet aksiyonu disabled; submit client-side engellenir |

BIRIM_AMIRI tam pasif değildir:

- `puantaj.amir_kontrol` permission ile **Amir Kontrol Etti** butonu görünür (kayıt yüklüyse, `kontrol_durumu !== AMIR_KONTROL_ETTI`)
- Tıklama → `markAmirKontrolEtti` → `PUT { kontrol_durumu: "AMIR_KONTROL_ETTI" }`

Amir kontrol sonrası:

- Kontrol Durumu: **Amir kontrol etti** (detay kartında)
- Amir Kontrol Etti butonu kaybolur
- Kaydet disabled kalır
- Mühür butonu yok kalır
- Read-only mesajı görünür kalır

---

## 8. E2E ile kilitlenen davranışlar

Kaynak test dosyası: `tests/e2e/puantaj.spec.ts` (7 test)

### S14C — Mühürleme

**`puantaj muhur aksiyonunu role gore gosterir - {role}`**

- GENEL_YONETICI / BOLUM_YONETICISI → `muhur-ay-kapat-btn` görünür
- MUHASEBE / BIRIM_AMIRI → `muhur-ay-kapat-btn` count 0
- Mühür butonu kayıt yüklemeden de görünür (permission gate)

**`genel yonetici puantaj ayini muhurlendikten sonra kaydi kilitli gorur`**

- Seed: personel `1`, tarih `2026-04-09`
- Başlangıç: Kaydet enabled, `muhur-uyari` yok
- Modal aç → dönem `2026-04` → onayla
- `muhur-sonuc` görünür (`2026-04`, `kayıt mühürlendi`); modal açık kalır
- Vazgeç → modal kapanır
- `muhur-uyari` görünür; `puantaj-kaydet` disabled

### S14D — BIRIM_AMIRI

**`birim amiri kayit yuklendikten sonra puantaji read only gorur`**

- Kaydet disabled; mühür yok; read-only mesajı; `puantaj-giris` enabled

**`birim amiri amir kontrol aksiyonunu tamamlar`**

- Kontrol Durumu: Bekliyor → Amir kontrol etti
- Amir Kontrol Etti butonu kaybolur; Kaydet disabled; mühür yok

### İlgili smoke (ayrı owner, refactor edilmedi)

- `smoke.spec.ts`: puantaj detay/kaydet, BIRIM_AMIRI Kaydet disabled (kayıt yüklemeden)
- `app-smoke.spec.ts`: `/puantaj` URL + modal başlık
- `role-smoke.spec.ts`: GENEL_YONETICI `/puantaj` erişimi

---

## 9. Mock API notu

Owner: `tests/e2e/helpers/mock-api.ts`

| Endpoint | Davranış |
| -------- | -------- |
| `GET /api/gunluk-puantaj/*` | `puantajKayitlari` veya sentetik default |
| `PUT /api/gunluk-puantaj/*` | Alan merge; `kontrol_durumu` korunur/güncellenir |
| `POST /api/puantaj/muhurle` | Payload `yil/ay` → `donem` (`YYYY-MM`); eşleşen kayıtlar `MUHURLENDI`; gerçek `muhurlenen_kayit_sayisi` + `donem` response |

**Eklenmedi (bilinçli):**

- PUT için 409/403 mühürlü kayıt lock — ayrı API/ürün kararı
- Mock PUT hesap motoru simülasyonu — unit test sorumluluğu

**Drift uyarısı:** `src/api/mock-demo.ts` mühürleme state'ini farklı şekilde simüle eder; E2E yalnızca `mock-api.ts` kullanır.

---

## 10. Unit test kapsamı

| Dosya | Kapsam |
| ----- | ------ |
| `puantaj-hesap-motoru.test.ts` | Hesap motoru — derin davranış (239 test) |
| `puantaj.api.test.ts` | GET normalize, PUT payload/URL |
| `puantaj-muhur.test.ts` | `puantaj.muhurle` permission matrisi; `MUHURLENDI` → `canEdit` mantığı |

E2E kullanıcı akışını kilitler; hesap motoru doğruluğunun ana sahibi unit testtir.

---

## 11. Korunacak guardlar

1. `tests/e2e/puantaj.spec.ts` bozulmadan puantaj değişikliği merge edilmez.
2. Mühür permission matrisi değişirse S14C testleri güncellenmeden geçilemez.
3. BIRIM_AMIRI read-only davranışı değişirse S14D testleri güncellenmeden geçilemez.
4. `POST /api/puantaj/muhurle` mock'u gerçekçi dönem state'ini (`MUHURLENDI`) korumalıdır.
5. `kontrol_durumu` mock merge davranışı korunmalıdır.
6. Üretim koduna data-testid eklemeden önce mevcut selectorlar tercih edilir.
7. Puantaj mühürleme ile Aylık Özet onay zinciri aynı şey değildir; karıştırılmamalıdır.
8. Haftalık kapanış UI ayrı ürün kararıdır (`/haftalik-kapanis` → `/` redirect).

---

## 12. Açık ürün kararları

| Konu | Durum |
| ---- | ----- |
| Puantaj mühürleme → Aylık Özet otomatik zincir | Ürün kararı bekliyor |
| Haftalık kapanış UI | Ürün kararı bekliyor |
| PUT request-level lock / 409-403 davranışı | API/ürün kararı bekliyor |
| BIRIM_AMIRI form inputlarının tamamen disabled olup olmayacağı | Ürün kararı olabilir |
| Mühür sonrası modalın otomatik kapanıp kapanmayacağı | UX kararı olabilir |
| Ayrı Puantaj raporları veya mühür geçmişi | MVP dışı |

---

## 13. Doğrulama komutları

Puantaj hattında değişiklik sonrası minimum gate:

```bash
npm run typecheck
npm run test
npm run build
npx playwright test tests/e2e/puantaj.spec.ts
npm run e2e
```

Doküman-only sprint için minimum:

```bash
npm run typecheck
npm run test
npm run build
```

---

## 14. Sonuç

Puantaj hattında günlük kayıt, mühürleme modalı, mühür permission, mühür sonrası kilit, BIRIM_AMIRI read-only ve Amir Kontrol davranışı E2E ile kilitlendi.

Puantaj hattı mevcut MVP davranışı açısından güvenli devir noktasına geldi. Bundan sonra yeni geliştirme yapılacaksa önce §12 açık ürün kararlarından biri seçilmeli; Aylık Kapanış Özeti (`/raporlar`) ile owner karıştırılmamalıdır — ayrım için bkz. `docs/guncel/65-raporlar-hatti-s8-s11-kapanis-devir-notu.md`.
