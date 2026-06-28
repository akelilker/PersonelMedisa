# 70 — Puantaj / Raporlar Şube Scope Hattı S18 Kapanış Devir Notu

---

## 1. Amaç

Bu not, Puantaj mühürleme ve Raporlar detay şube scope hattında S18A owner-gap analizi ile S18B mock + E2E guard sonuçlarını tek yerde toplar.

Sonraki geliştiricinin puantaj GET/PUT/mühürleme scope contract'ını, detaylı rapor active şube davranışını, Aylık Özet farkını, mock drift risklerini ve bilinçli olarak açık bırakılan production backlog kararlarını görmesi için yazılmıştır.

**Bu not yeni özellik talimatı değildir.** Yeni geliştirme seçilecekse önce §14 açık backlog konularından biri belirlenmeli ve §13 korunacak guard'lar bozulmamalıdır.

Şube / active şube altyapısı `69` numaralı dokümanla kapatılmıştır. Bu doküman, S17 sonrası backlog kalan **Puantaj mühürleme** ve **Raporlar detay** şube scope hattını kapatır.

**Son kilit commit:** `58edc47` — `test: puantaj ve rapor sube scope guardlarini kilitle`  
**Branch:** `main` (`origin/main` ile hizalı; CI run `#424` + Deploy cPanel run `#402` success)

---

## 2. Kapsanan sprintler

| Sprint | Commit / Durum | Kilitlenen veya netleşen davranış |
| ------ | -------------- | --------------------------------- |
| S18A   | analiz-only (`42ed929` üzerinde) | Puantaj mühürleme payload/header scope, detaylı rapor header scope, mock/API drift; **KARAR B** (test + mock, üretim kodu yok) |
| S18B   | `58edc47` | Puantaj GET/PUT/mühürleme mock scope, BIRIM_AMIRI puantaj şube dışı erişim guard, detaylı rapor active şube scope, GENEL_YONETICI no-scope |
| S18C   | bu not | Puantaj / Raporlar şube scope hattı kapanış/devir dokümantasyonu |

---

## 3. Owner haritası

| Alan | Owner |
| ---- | ----- |
| Puantaj sayfa | `src/features/puantaj/pages/GunlukPuantajPage.tsx` |
| Puantaj hook | `src/hooks/usePuantaj.ts` |
| Puantaj API | `src/api/puantaj.api.ts` |
| Puantaj mühürleme | `muhurleAylikPuantaj()` (`puantaj.api.ts`) |
| Puantaj types | `src/types/puantaj.ts` |
| Raporlar sayfa | `src/features/raporlar/pages/RaporlarPage.tsx` |
| Raporlar API | `src/api/raporlar.api.ts` |
| Aylık Özet API | `fetchAylikKapanisOzeti()` (`raporlar.api.ts`) |
| Offline rapor motoru | `src/reports/report-engine.ts` (online akışta kullanılmıyor) |
| API header | `src/api/api-client.ts` → `getActiveSubeIdForApiHeader()` |
| Active şube state | `src/auth/auth-session-sube.ts`, `src/state/auth.store.tsx` |
| Query scope helper | `src/data/data-manager.ts` → `getSubeIdForApiRequest()` |
| 403 global yönlendirme | `src/app/providers.tsx` (`onAuthForbidden` → `/yetkisiz`) |
| Permission | `src/lib/authorization/role-permissions.ts` |
| E2E mock | `tests/e2e/helpers/mock-api.ts` |
| S18B E2E | `tests/e2e/puantaj-rapor-sube-scope.spec.ts` |
| Puantaj regresyon | `tests/e2e/puantaj.spec.ts` |
| Raporlar regresyon | `tests/e2e/raporlar.spec.ts` |
| Personel şube scope regresyon | `tests/e2e/sube-scope.spec.ts` |
| Rol smoke | `tests/e2e/role-smoke.spec.ts` |

İlgili referans dokümanlar:

- Puantaj hattı: `docs/guncel/66-puantaj-hatti-s14-kapanis-devir-notu.md`
- Raporlar hattı: `docs/guncel/65-raporlar-hatti-s8-s11-kapanis-devir-notu.md`
- Şube / active şube: `docs/guncel/69-sube-active-sube-hatti-s17-kapanis-devir-notu.md`

---

## 4. Puantaj data-flow contract

Günlük puantaj **personel listesi üzerinden değil**; kullanıcı **Personel ID + Tarih** girerek kayıt getirir.

### Endpointler

| Metot | Path |
| ----- | ---- |
| GET | `/api/gunluk-puantaj/:personelId/:tarih` |
| PUT | `/api/gunluk-puantaj/:personelId/:tarih` |
| POST | `/api/puantaj/muhurle` |

### Scope taşıma

- `api-client.ts` auth path'lerinde `X-Active-Sube-Id` header'ı otomatik eklenir (`active_sube_id !== null` iken).
- `usePuantaj` cache key'inde active şube segmenti vardır (`dataCacheKeys.puantajDetail`); active şube değişince refetch tetiklenir.
- Puantaj API çağrılarında `getSubeIdForApiRequest()` / query `sube_id` kullanılmaz (personel/süreç/finans hook'larından farklı).

### S18A → S18B

- **S18A:** Mock GET/PUT/mühürleme header/query scope okumuyordu; BIRIM_AMIRI şube dışı personel ID ile puantaj görebiliyordu.
- **S18B:** Mock GET/PUT scope mismatch durumunda **403** + `Bu kayıt aktif şube bağlamında görüntülenemiyor.` döner.

---

## 5. Puantaj mühürleme contract

### Production

| Alan | Değer |
| ---- | ----- |
| Endpoint | `POST /api/puantaj/muhurle` |
| Payload | `{ yil, ay }` — explicit `sube_id` **yok** |
| Scope | `X-Active-Sube-Id` header üzerinden taşınır |
| Owner | `GunlukPuantajPage.tsx` → `muhurleAylikPuantaj()` |

### S18B mock contract

- `getRequestSubeScope` ile header/query okunur.
- Scope **varsa:** yalnız ilgili şubedeki personellerin ilgili ay (`yil-ay`) kayıtları `MUHURLENDI` olur.
- Scope **yoksa:** eski no-scope/global davranış korunur (dönemdeki tüm kayıtlar mühürlenir).

### S18B E2E

Test: `puantaj muhurleme active sube scope ile yalniz ilgili subeyi etkiler`

- Rol: **BOLUM_YONETICISI** (fixture `sube_ids: [2]` → active şube 2 garanti)
- `POST /api/puantaj/muhurle` request'inde `x-active-sube-id: 2` doğrulandı
- Sonuç metni: **"1 kayıt mühürlendi"** (global 3 kayıt yerine scoped mühürleme)
- Personel 2 (`2026-04-09`) kaydı mühür sonrası kilitli (`muhur-uyari`, `puantaj-kaydet` disabled)

**Not:** GENEL_YONETICI + session helper ile active şube 2 set etmek güvenilir değildir; `finalizeAuthSessionSube` boş `sube_ids` için `active_sube_id`'yi `null`'a çeker.

### Production backlog

Payload'a explicit `sube_id` eklenip eklenmeyeceği ayrı ürün/kod kararıdır.

---

## 6. BIRIM_AMIRI Puantaj şube dışı erişim contract

BIRIM_AMIRI kendi şubesi dışındaki personelin puantajını açamamalıdır.

### S18B mock

- Personel fixture `sube_id` ile scope karşılaştırılır.
- Mismatch → **403** + `Bu kayıt aktif şube bağlamında görüntülenemiyor.`

### S18B E2E

Test: `birim amiri sube disi personel puantajini acamaz`

| Adım | Beklenen |
| ---- | -------- |
| Personel 1 / şube 1 (`2026-04-09`) | `puantaj-ana-detay` görünür |
| Personel 2 / şube 2 (aynı tarih) | Engellenir |
| Global auth | 403 → `emitAuthForbidden` → `/yetkisiz` |
| UI | `puantaj-ana-detay` yok; pageerror yok |

**Not:** Üretimde puantaj 403'ü inline `ErrorState` yerine global forbidden akışına düşer (`api-client.ts`).

---

## 7. Raporlar detay scope contract

### Production

- Detaylı raporlar `RaporlarPage.tsx` → `fetchRapor()` üzerinden çalışır.
- Query: `personel_id`, `departman_id`, tarih aralığı, `aktiflik`, `page`, `limit`.
- `RaporFiltreleri` tipinde explicit `sube_id` **yoktur**.
- Active şube scope `X-Active-Sube-Id` header üzerinden taşınır.
- Detaylı raporda UI şube seçici yoktur; scope = session active şube.

### S18A → S18B mock

- **S18A:** Detay rapor mock'ları statik item döndürüyordu; header scope okunmuyordu.
- **S18B:**
  - `personel-ozet`: `personelOzetPaginatedBody` scope filtresi + pagination
  - Diğer detay tipler: `RAPOR_MOCK_ITEMS` → `filterRaporItemsBySubeScope` (`personel_id` → `PERSONEL_SUBE_BY_ID` map)
  - Scope varsa yalnız ilgili şube satırları; scope yoksa tüm fixture
  - Filtre pagination'dan önce uygulanır

### S18B E2E

Test: `detayli rapor active sube scope ile satirlari daraltir`

- Rol: **MUHASEBE** (`sube_ids: [1, 2]`)
- Rapor tipi: `personel-ozet`
- Active şube 1 (varsayılan): Ayşe Yılmaz görünür, Mehmet Kaya görünmez
- `switchActiveSubeViaSession(2)` sonrası: Mehmet görünür, Ayşe görünmez
- Request'te `x-active-sube-id: 2` doğrulandı

Test: `genel yonetici detayli raporda sube scope olmadan tum veriyi gorur`

- Rol: **GENEL_YONETICI** (`active_sube_id: null` → header yok)
- `personel-ozet` sayfa 1: Ayşe; sayfa 2: Mehmet (pagination ile iki şube verisi erişilebilir)
- pageerror yok

---

## 8. Aylık Özet scope farkı

| Konu | Detaylı rapor | Aylık Özet |
| ---- | ------------- | ---------- |
| Scope taşıma | `X-Active-Sube-Id` header (implicit) | Query `sube_id` (explicit) |
| UI filtresi | Yok | Şube dropdown |
| Mock S18B | Scope filtresi eklendi | **Dokunulmadı** (zaten `sube_id` destekli) |
| E2E | `puantaj-rapor-sube-scope.spec.ts` | `raporlar.spec.ts` (Merkez filtresi testi) |

- CSV export smoke testi vardır; **CSV içerik scope assert** hâlâ backlog'dur.

---

## 9. Role / permission matrisi

| Rol | Puantaj view | Puantaj update | Mühürleme | Amir kontrol | Detaylı rapor | Scope davranışı |
| --- | ------------ | -------------- | --------- | ------------ | ------------- | --------------- |
| GENEL_YONETICI | Evet | Evet | Evet | Hayır | Evet | No-scope / tüm şubeler |
| BOLUM_YONETICISI | Evet | Evet | Evet | Hayır | Evet | Rol `sube_ids` + active şube |
| MUHASEBE | Evet | Evet | Hayır | Hayır | Evet | Active şube |
| BIRIM_AMIRI | Evet | Hayır (read-only) | Hayır | Evet | Evet | Kendi şubesi (`sube_ids: [1]`) |

Kaynak: `role-permissions.ts`, `puantaj.spec.ts`, `raporlar.spec.ts`

---

## 10. S18B mock contract

S17B'den gelen `getRequestSubeScope` helper reuse edildi; yeni duplicate helper yazılmadı.

**Öncelik kuralı:** query `sube_id` → `x-active-sube-id` header → boş/geçersiz ise scope yok.

| Handler | Scope davranışı |
| ------- | --------------- |
| Puantaj GET | scope mismatch → 403 |
| Puantaj PUT | scope mismatch → 403; kayıt oluşturulmaz/güncellenmez |
| Puantaj mühürleme | scope varsa yalnız ilgili şube personelleri; scope yoksa global |
| Rapor detay | `personel_id` → `PERSONEL_SUBE_BY_ID` map; scope yoksa tüm veri |
| Aylık Özet | Dokunulmadı |
| Personel list/detail (S17B) | Mevcut contract korundu |

Fixture personel → şube map (mock):

| personel_id | Ad | sube_id | Şube |
| ----------- | -- | ------- | ---- |
| 1 | Ayşe Yılmaz | 1 | Merkez |
| 2 | Mehmet Kaya | 2 | Depolama |

---

## 11. S18B E2E ile kilitlenen davranışlar

### `puantaj muhurleme active sube scope ile yalniz ilgili subeyi etkiler`

- Rol: BOLUM_YONETICISI
- Active şube: 2
- `POST /api/puantaj/muhurle`
- Header: `x-active-sube-id: 2`
- Sonuç: `"1 kayıt mühürlendi"`
- Personel 2 kaydı kilitli
- pageerror yok

### `birim amiri sube disi personel puantajini acamaz`

- Rol: BIRIM_AMIRI
- Personel 1 / şube 1 açılır
- Personel 2 / şube 2 engellenir
- `/yetkisiz`
- `puantaj-ana-detay` yok
- pageerror yok

### `detayli rapor active sube scope ile satirlari daraltir`

- Rol: MUHASEBE
- Active şube 1 → Ayşe görünür, Mehmet görünmez
- Active şube 2 → Mehmet görünür, Ayşe görünmez
- Header scope doğrulandı
- pageerror yok

### `genel yonetici detayli raporda sube scope olmadan tum veriyi gorur`

- Rol: GENEL_YONETICI
- Scope yok
- Ayşe (sayfa 1) + Mehmet (sayfa 2) erişilebilir
- pageerror yok

---

## 12. Mevcut test kapsamı

| Test | Kapsam |
| ---- | ------ |
| `puantaj-rapor-sube-scope.spec.ts` | S18B puantaj/rapor şube scope (4 test) |
| `puantaj.spec.ts` | Puantaj mühürleme / read-only / rol regresyonları |
| `raporlar.spec.ts` | 8 tip rapor, filtre, sayfalama, CSV, Aylık Özet şube filtresi |
| `sube-scope.spec.ts` | Personel Kartı/listesi active şube scope (S17B) |
| `role-smoke.spec.ts` | Rol bazlı menü/erişim |
| `api-client.test.ts` | `X-Active-Sube-Id` header |
| `role-permissions.test.ts` | Role/permission matrix |

---

## 13. Korunacak guardlar

1. Puantaj GET/PUT scope mismatch durumunda güvenli şekilde engellenmelidir.
2. BIRIM_AMIRI kendi şubesi dışındaki personel puantajını açamamalıdır.
3. Puantaj mühürleme active şube scope dışında kayıt mühürlememelidir (mock contract).
4. Production payload `{ yil, ay }` olduğundan header scope kırılırsa S18B mühürleme testi kırılmalıdır.
5. Detaylı raporlar active şube scope ile daralmalıdır.
6. GENEL_YONETICI no-scope / tüm veri davranışı korunmalıdır.
7. Aylık Özet explicit `sube_id` filtresi korunmalıdır.
8. Aylık Özet handler S18B kapsamındaki detay rapor değişikliklerinden etkilenmemelidir.
9. Mock helper `getRequestSubeScope` ortak contract olarak kullanılmalıdır (personel + puantaj + rapor).
10. Full E2E (`npm run e2e`) çalışmadan mock scope değişikliği merge edilmemelidir.

---

## 14. Açık backlog / ürün kararları

| Konu | Durum |
| ---- | ----- |
| Puantaj mühürleme payload'a explicit `sube_id` eklenmesi | Production backlog |
| Detaylı rapor `RaporFiltreleri` içine explicit `sube_id` eklenmesi | Production backlog |
| CSV/export içerik scope assert | Test backlog |
| Aylık Özet CSV scope detay testi | Test backlog |
| Puantaj UI personel seçimi role/şube guard iyileştirmesi | Production/test backlog |
| Header/query mismatch backend contract | Ürün/backend contract backlog |
| Offline `report-engine.ts` ile online API scope parity | Teknik backlog |

---

## 15. Doğrulama komutları

Puantaj / Raporlar şube scope hattında değişiklik sonrası minimum gate:

```bash
npm run typecheck
npm run test
npm run build
npx playwright test tests/e2e/puantaj-rapor-sube-scope.spec.ts
npx playwright test tests/e2e/puantaj.spec.ts
npx playwright test tests/e2e/raporlar.spec.ts
npx playwright test tests/e2e/sube-scope.spec.ts
npx playwright test tests/e2e/role-smoke.spec.ts
npm run e2e
```

Doküman-only sprint (S18C) için minimum gate:

```bash
npm run typecheck
npm run test
npm run build
```

---

## 16. Sonuç

S18 hattı MVP için yeterli kapanış noktasına gelmiştir.

- Production kodunda active şube header altyapısı zaten mevcuttur (`69`).
- S18B ile mock/API drift'in kritik kısmı (puantaj GET/PUT/mühürleme + detay rapor) azaltılmıştır.
- Puantaj mühürleme scoped mock contract, BIRIM_AMIRI şube dışı puantaj engeli ve detaylı rapor active şube scope E2E ile kilitlenmiştir.
- Kalan konular §14 backlog'dur; yeni geliştirme seçilecekse önce hangi backlog konusunun ele alınacağı belirlenmelidir.
