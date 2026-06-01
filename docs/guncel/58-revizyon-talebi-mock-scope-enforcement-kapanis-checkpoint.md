# 58 — Revizyon Talebi Mock Scope Enforcement Kapanış Checkpoint

---

## 1. Faz Bilgisi

| Alan | Değer |
|------|-------|
| Faz adı | 57 — Revizyon Talebi Mock Scope Enforcement |
| Ön teşhis | 57 ön teşhis / dar plan |
| Karar dokümanı | **56** — Revizyon Talebi Scope / Backend Enforcement Kararı |
| Kod commit | `aed2519` — Add revizyon talebi mock scope enforcement |
| Durum | Kapandı (mock katman) |

---

## 2. Amaç

Bu fazda **56** karar dokümanındaki permission + scope + finance visibility kuralları mock API katmanında uygulanmıştır. Amaç, gerçek backend enforcement veya UI öncesinde demo/mock ortamında rol simülasyonu ve kapsam filtrelerini doğrulamaktır.

---

## 3. Önceki Karar Zinciri

- **50** — Kapalı dönem / audit workflow kararı
- **51** — Haftalık kapanış revizyon talebi kararı
- **52** — Revizyon talebi rol/yetki kararı
- **53** — 09 rol yetki matrisi revizyon permission güncellemesi
- **54** — Revizyon talebi contract kod iskeleti
- **55** — 54 contract kod fazı checkpoint
- **56** — Scope / backend enforcement kararı
- **57** — Mock scope enforcement kod fazı

---

## 4. Kapsam

Bu fazda yapılanlar:

- Pure scope helper modülü (`revizyon-scope.ts`) eklendi.
- Mock actor simülasyonu (`X-Demo-User-Id`, `X-Demo-Role`) eklendi.
- List endpoint: permission + scope filter + finance mask.
- Detail endpoint: scope dışı `REVISION_SCOPE_DENIED`.
- Create: personel scope kontrolü; `talep_eden_kullanici_id` actor'dan set edilir.
- Submit/cancel: ownership + scope guard.
- Approve/reject: yalnız `GENEL_YONETICI`; aksi `UNAUTHORIZED_REVISION_APPROVAL`.
- Unit + mock integration testleri eklendi.

---

## 5. Değişen Dosyalar

**Yeni:**

- `src/lib/revizyon-talebi/revizyon-scope.ts`
- `tests/unit/revizyon-scope.test.ts`

**Güncellenen:**

- `src/api/mock-demo.ts`
- `tests/unit/revizyon-talebi.api.test.ts`

**Bilinçli olarak dokunulmayan:**

- `src/api/revizyon-talebi.api.ts`
- `src/lib/authorization/role-permissions.ts`
- `src/lib/revizyon-talebi/revizyon-state.ts`
- `src/types/revizyon-talebi.ts`
- UI, route, hook, CSS, services motorları

---

## 6. Scope Helper Kararı

`revizyon-scope.ts` pure helper olarak kurulmuştur; `demoState`, API client veya UI bağı yoktur.

**Fonksiyonlar:**

- `canViewRevizyonTalep`
- `canCreateRevizyonForPersonel`
- `canSubmitRevizyon`
- `canCancelRevizyon`
- `canApproveOrRejectRevizyon`
- `maskRevizyonFinanceFields`

**Rol özeti:**

| Rol | View scope | Create scope | Approve/reject | Finance mask |
|-----|------------|--------------|----------------|--------------|
| `GENEL_YONETICI` | Tümü | Tümü | Evet | Tam |
| `BOLUM_YONETICISI` | Kendi departmanı | Kendi departmanı | Hayır | Tam |
| `MUHASEBE` | `bordro_etki_var_mi === true` | Bordro gerekçe gerekli | Hayır | Tam |
| `BIRIM_AMIRI` | `linkedPersonelId` | Kendi personeli | Hayır | `bordro_etki_notu` maskelenir; flag kalır |

Permission kontrolü mock katmanda `hasRolePermission` ile ayrı uygulanır.

---

## 7. Mock Actor Simülasyonu

Demo header'ları (mock-only, production güvenlik değildir):

- `X-Demo-User-Id` — varsayılan `1`
- `X-Demo-Role` — kullanıcı kaydı rolünü override eder

**Varsayılan demo kullanıcıları:**

| user id | Rol | Not |
|---------|-----|-----|
| 1 | `GENEL_YONETICI` | Varsayılan actor |
| 2 | `BOLUM_YONETICISI` | sube 2 → departman 6 |
| 3 | `BIRIM_AMIRI` | linkedPersonelId 1 |

---

## 8. Mock Endpoint Davranışı

| Endpoint | Enforcement |
|----------|-------------|
| List | `revizyon.view` + scope filter + finance mask |
| Detail | `revizyon.view` + scope miss → `REVISION_SCOPE_DENIED` |
| Create | `revizyon.create` + personel scope + period + duplicate |
| Submit | `revizyon.submit` + ownership/scope |
| Cancel | `revizyon.cancel` + ownership/scope (GY geniş iptal) |
| Approve/reject | `revizyon.approve/reject` + yalnız GY scope |

**Korunan 54 davranışları:**

- Snapshot mutate edilmez.
- `buildHaftalikKapanisSnapshot` revizyon handler'larında çağrılmaz.
- `ONAYLANDI` sonrası `correction_event_id` null kalır.
- State transition helper davranışı bozulmaz.

---

## 9. Test ve Doğrulama

- `npm run test` → 531 passed / 31 files
- `npm run typecheck` → success
- Commit öncesi diff review → scope temiz, 4 dosya

**Test kapsamı:**

- `revizyon-scope` — 5 pure helper testi
- `revizyon-talebi.api` — önceki 12 test korundu + 7 mock integration testi

---

## 10. Kapsam Dışı Bırakılanlar

- Gerçek backend/API scope enforcement
- UI, route, hook, CSS
- `revizyon-talebi.api.ts` production client değişikliği
- Correction layer üretimi
- Snapshot / puantaj / serbest zaman motorları
- Audit read model

---

## 11. Bilinen Notlar

- Mock rol simülasyonu yalnız demo ortamı içindir; production güvenlik katmanı değildir.
- List scope dışı kayıtları sessizce filtreler; detail explicit hata döner.
- `MUHASEBE` view yalnızca `bordro_etki_var_mi === true` kayıtları gösterir; create'te not veya flag yeterlidir.
- Production API client bu fazda değiştirilmemiştir.

---

## 12. Sonraki Faz Seçenekleri

| Seçenek | Amaç | Risk | Öneri |
|---------|------|------|-------|
| **A. Gerçek Backend Scope Enforcement** | `revizyon-talebi.api.ts` / server tarafında 56 kurallarını uygulamak. | Auth context ve departman/personel join gerekir. | Güçlü aday. |
| **B. Revizyon Talebi UI Karar Dokümanı** | Liste, detay, aksiyon butonları ve rol görünürlüğü. | Mock enforcement ile uyum gerekir. | Backend sonrası veya paralel karar. |
| **C. Correction Layer Kararı** | `ONAYLANDI` sonrası audit/correction etkisi. | Snapshot ve bordro etkisi doğurur. | Koddan önce karar. |
| **D. UI Contract + Hook Fazı** | Revizyon talebi ekran iskeleti. | Scope hataları UX'te görünür olmalı. | Karar + mock doğrulama sonrası. |

---

## 13. Önerilen Sıradaki Adım

Önerilen sonraki adım **gerçek backend scope enforcement** veya bunun öncesinde kısa bir **UI karar dokümanı** hazırlanmasıdır. Mock katman 56 kararlarını doğrulamıştır; production client ve server tarafı henüz scope enforce etmemektedir.

---

## 14. Kapanış Cümlesi

57 Revizyon Talebi Mock Scope Enforcement fazı, 56 karar dokümanındaki permission + scope + finance visibility kurallarını mock API katmanında uygulayarak demo ortamında rol simülasyonu ve kapsam filtrelerini tamamlamıştır. Production API, UI ve correction layer bilinçli olarak kapsam dışında bırakılmıştır.
