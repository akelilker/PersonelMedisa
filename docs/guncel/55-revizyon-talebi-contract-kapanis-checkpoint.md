# 55 — Revizyon Talebi Contract Kapanış Checkpoint

---

## 1. Faz Bilgisi

| Alan | Değer |
|------|-------|
| Faz adı | 54 — Revizyon Talebi Contract Kod Fazı |
| Ön teşhis | 54 ön teşhis / dar plan |
| Kod commit | `d082434` — Add revizyon talebi contract iskeleti (faz 54). |
| CI | #346 / success |
| Deploy cPanel | #324 / success |
| Durum | Kapandı |

---

## 2. Amaç

Bu fazda haftalık kapanış revizyon talebi için ilk contract iskeleti oluşturulmuştur. Amaç, kapalı dönem / audit kararları doğrultusunda UI veya motor katmanına girmeden önce type, permission, API, endpoint, mock ve state transition temelini kurmaktır.

---

## 3. Önceki Karar Zinciri

- **50** — Kapalı dönem / audit workflow kararı
- **51** — Haftalık kapanış revizyon talebi kararı
- **52** — Revizyon talebi rol/yetki kararı
- **53** — 09 rol yetki matrisi revizyon permission güncellemesi
- **54** — Revizyon talebi contract kod iskeleti

---

## 4. Kapsam

Bu fazda yapılanlar:

- Revizyon talebi type contract oluşturuldu.
- Revizyon talebi state transition helper oluşturuldu.
- Revizyon talebi API client oluşturuldu.
- Revizyon endpoint anahtarları eklendi.
- Mock store ve handler'lar eklendi.
- `role-permissions` içine `revizyon.*` anahtarları eklendi.
- Unit testler eklendi/güncellendi.

---

## 5. Değişen Dosyalar

**Yeni:**

- `src/types/revizyon-talebi.ts`
- `src/lib/revizyon-talebi/revizyon-state.ts`
- `src/api/revizyon-talebi.api.ts`
- `tests/unit/revizyon-state.test.ts`
- `tests/unit/revizyon-talebi.api.test.ts`

**Güncellenen:**

- `src/lib/authorization/role-permissions.ts`
- `src/api/endpoints.ts`
- `src/api/mock-demo.ts`
- `tests/unit/role-permissions.test.ts`

---

## 6. Permission Kararı

Revizyon permission anahtarları kod tarafında **09** ve **52** dokümanlarıyla uyumlu hale getirilmiştir.

**Permission anahtarları:**

- `revizyon.view`
- `revizyon.create`
- `revizyon.submit`
- `revizyon.cancel`
- `revizyon.approve`
- `revizyon.reject`
- `revizyon.view_finance_effect`
- `revizyon.view_audit_history`

**Rol özeti:**

| Rol | Kapsam |
|-----|--------|
| `GENEL_YONETICI` | 8/8 permission |
| `BOLUM_YONETICISI` | approve/reject hariç |
| `MUHASEBE` | approve/reject hariç |
| `BIRIM_AMIRI` | approve/reject/view_finance_effect hariç |

**Not:** Bölüm/personel/bordro scope enforcement bu fazda permission helper'a taşınmamıştır. Bu kontrol ileride API/backend enforcement fazında ele alınacaktır.

---

## 7. State Transition Kararı

Revizyon talebi state helper pure olarak kurulmuştur.

**İzinli geçişler:**

- `TASLAK` → `ONAY_BEKLIYOR`
- `TASLAK` → `IPTAL`
- `ONAY_BEKLIYOR` → `ONAYLANDI`
- `ONAY_BEKLIYOR` → `REDDEDILDI`
- `ONAY_BEKLIYOR` → `IPTAL`

**Terminal durumlar:**

- `ONAYLANDI`
- `REDDEDILDI`
- `IPTAL`

Terminal durumlardan yeni geçiş yoktur. Geçersiz geçişler `INVALID_STATE_TRANSITION` üretir.

---

## 8. API Contract Kararı

Aşağıdaki API client fonksiyonları oluşturulmuştur:

- `fetchRevizyonTalepleri`
- `fetchRevizyonTalebiDetail`
- `createRevizyonTalebi`
- `submitRevizyonTalebi`
- `approveRevizyonTalebi`
- `rejectRevizyonTalebi`
- `cancelRevizyonTalebi`

**Endpoint ailesi:**

- `GET /haftalik-kapanis/revizyon-talepleri`
- `POST /haftalik-kapanis/revizyon-talepleri`
- `GET /haftalik-kapanis/revizyon-talepleri/:id`
- `POST /haftalik-kapanis/revizyon-talepleri/:id/gonder`
- `POST /haftalik-kapanis/revizyon-talepleri/:id/onay`
- `POST /haftalik-kapanis/revizyon-talepleri/:id/red`
- `POST /haftalik-kapanis/revizyon-talepleri/:id/iptal`

---

## 9. Mock Davranışı

Mock katmanda `revizyonTalebiById` store ve `revizyonTalebi` nextId eklenmiştir.

**Davranış:**

- Create sırasında kapalı dönem kontrolü yapılır.
- Kapalı dönem yoksa `PERIOD_NOT_CLOSED` döner.
- Aynı `kaynak_tipi` + `kaynak_id` + `etkilenen_tarih` için `ONAY_BEKLIYOR` talep varsa `REVISION_ALREADY_EXISTS` döner.
- Başarılı create `TASLAK` durumuyla kayıt oluşturur.
- Submit / approve / reject / cancel state helper üzerinden yürür.
- `ONAYLANDI` sonrası `correction_event_id` null kalır.
- Snapshot mutate edilmez.
- `buildHaftalikKapanisSnapshot` çağrılmaz.

---

## 10. Hata Kodları

- `PERIOD_NOT_CLOSED`
- `PERIOD_LOCKED`
- `REVISION_ALREADY_EXISTS`
- `INVALID_STATE_TRANSITION`
- `UNAUTHORIZED_REVISION_REQUEST`
- `UNAUTHORIZED_REVISION_APPROVAL`
- `REVISION_SCOPE_DENIED`
- `FINANCE_EFFECT_ACCESS_DENIED`
- `TARGET_NOT_FOUND`
- `SNAPSHOT_IMMUTABLE`
- `INVALID_BODY`
- `NOT_FOUND`

---

## 11. Test ve Doğrulama

- `npm run test` → 519 passed / 30 files
- `npm run typecheck` → success
- CI #346 → success
- Deploy cPanel #324 → success

**Test kapsamı:**

- `revizyon-state` transition testleri
- `revizyon-talebi.api` normalize / endpoint / error testleri
- `role-permissions` revizyon permission testleri
- Önceki 500 test korunmuştur

---

## 12. Kapsam Dışı Bırakılanlar

- UI
- route
- hook
- CSS
- snapshot builder değişikliği
- puantaj motoru
- serbest zaman event motoru
- yıllık fazla çalışma aggregate
- correction layer üretimi
- audit/correction read model
- bordro/finans hesaplama
- backend gerçek persist enforcement
- bölüm/personel/bordro scope enforcement

---

## 13. Bilinen Notlar

- Mock'ta rol simülasyonu yoktur; yetki enforcement sonraki fazda ele alınacaktır.
- `ONAYLANDI` sonrası `correction_event_id` bilinçli olarak null bırakılmıştır.
- Revizyon talebi contract hazırdır, ancak UI veya route entegrasyonu yapılmamıştır.
- Snapshot immutable kararı korunmuştur.
- Permission flat aksiyon yetkisini taşır; kapsam bazlı kontrol ileride ayrı fazdır.

---

## 14. Sonraki Faz Seçenekleri

| Seçenek | Amaç | Risk | Öneri |
|---------|------|------|-------|
| **A. Revizyon Talebi Scope / Backend Enforcement Kararı** | Bölüm/personel/bordro kapsam kontrollerini netleştirmek. | Backend/API güvenlik kararı gerektirir. | Güçlü aday. |
| **B. Revizyon Talebi Correction Layer Kararı** | `ONAYLANDI` sonrası hangi audit/correction etkisinin üretileceğini netleştirmek. | Snapshot, rapor ve bordro etkisi doğurur. | Koddan önce karar dokümanı gerekir. |
| **C. Revizyon Talebi UI Karar Dokümanı** | Liste, detay, aksiyon butonları ve rol görünürlüğünü tanımlamak. | Backend enforcement netleşmeden UI erken olabilir. | Bekleyebilir. |
| **D. Contract Hardening / Mock Enforcement Kod Fazı** | Mock role/scope enforcement ve ekstra API testleri. | Yetki kararları daha netleşmeden şişebilir. | Karar sonrası. |

---

## 15. Önerilen Sıradaki Adım

Önerilen sonraki adım doğrudan UI değildir. Önce **「Revizyon Talebi Scope / Backend Enforcement Kararı」** hazırlanmalıdır. Çünkü 54 fazında permission anahtarları ve contract kurulmuş olsa da bölüm/personel/bordro kapsam kontrolleri henüz yalnız karar seviyesinde kalmıştır.

**Önerilen dosya:**

`docs/guncel/56-revizyon-talebi-scope-backend-enforcement-karar.md`

---

## 16. Kapanış Cümlesi

54 Revizyon Talebi Contract Kod Fazı, kapalı dönem revizyon workflow'u için type, permission, API, endpoint, mock ve state transition contract iskeletini tamamlamıştır. Snapshot, puantaj motoru, serbest zaman motoru ve UI katmanları kapsam dışında bırakılmış; faz CI ve Deploy doğrulamasıyla kapatılmıştır.
