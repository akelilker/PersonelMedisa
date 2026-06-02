# 63 — Revizyon Talebi Zinciri Genel Devir Notu

---

## 1. Amaç

Bu doküman, 50–62 arasında tamamlanan revizyon talebi zincirini tek yerde toparlar. Amaç, sonraki oturumda veya sonraki geliştirici devrinde bağlam kaybını önlemek ve hangi konuların kapandığını net göstermektir.

Bu doküman yeni karar veya yeni kod fazı açmaz.

---

## 2. Repo Durumu

- **Branch:** main
- **Son kapanış commit'i:** `9c68f66` — Add revizyon talebi correction layer contract checkpoint
- **Son doğrulama:**
  - CI #354 success
  - Deploy cPanel #332 success
- **Working tree beklentisi:** temiz
- **HEAD = origin/main beklentisi:** eşit

---

## 3. Kapanan Fazlar

| Faz | Ad | Tür | Durum |
|-----|-----|-----|-------|
| 50 | Kapalı dönem / audit workflow kararı | Karar | Kapalı |
| 51 | Haftalık kapanış revizyon talebi kararı | Karar | Kapalı |
| 52 | Revizyon talebi rol/yetki kararı | Karar | Kapalı |
| 53 | Rol yetki matrisi revizyon permission güncellemesi | Doküman/matris | Kapalı |
| 54 | Revizyon talebi contract kod iskeleti | Kod | Kapalı |
| 55 | Revizyon talebi contract kapanış checkpoint | Checkpoint | Kapalı |
| 56 | Revizyon talebi scope/backend enforcement kararı | Karar | Kapalı |
| 57 | Revizyon talebi mock scope enforcement kod fazı | Kod | Kapalı |
| 58 | Mock scope enforcement checkpoint | Checkpoint | Kapalı |
| 59 | Gerçek backend scope enforcement kararı | Karar | Kapalı |
| 60 | Correction layer kararı | Karar | Kapalı |
| 61 | Correction layer contract kod fazı | Kod | Kapalı |
| 62 | Correction layer contract checkpoint | Checkpoint | Kapalı |

---

## 4. Zincirin Net Özeti

Revizyon talebi zinciri, kapalı haftalık dönemlerde snapshot'ı bozmadan düzeltme talebi açma, talebi state üzerinden yürütme, rol/scope kontrollerini uygulama ve ONAYLANDI durumda correction event üretme altyapısını kurmuştur.

Bu zincirde amaç, kapanmış snapshot'ın immutable kalması ve tüm sonradan gelen düzeltmelerin audit edilebilir correction layer üzerinden yürütülmesidir.

---

## 5. Şu An Sistemde Olan Yetenekler

- Revizyon talebi type contract var.
- Revizyon talebi state transition helper var.
- Revizyon talebi API client var.
- Revizyon talebi mock endpointleri var.
- `revizyon.*` permission anahtarları rol matrisiyle uyumlu.
- Mock ortamda role/scope enforcement var.
- Demo actor header simülasyonu var.
- Finance visibility mask var.
- Gerçek backend scope enforcement kararı var.
- Correction event type contract var.
- Correction API client var.
- Correction endpoint grubu var.
- Mock correction store var.
- ONAYLANDI revizyon approve sonrası correction üretiyor.
- `correction_event_id` bağlantısı kuruluyor.
- Aynı talep için ikinci correction engelleniyor.
- Correction cancel soft delete davranışı var.
- Read model overlay helper var.
- Snapshot mutate edilmiyor.
- Puantaj motoru ve serbest zaman motoru correction flow'a bağlanmadı.
- Test ve typecheck yeşil.

---

## 6. Özellikle Korunan Sınırlar

- Snapshot overwrite yok.
- Kapanmış dönem ham verisi bozulmaz.
- Puantaj motoru yeniden çalıştırılmaz.
- Serbest zaman motoru doğrudan correction'a bağlanmaz.
- Bordro hesabı üretilmez.
- UI açılmadı.
- Route guard açılmadı.
- Hook/page/CSS değişmedi.
- Gerçek backend auth/persist enforcement henüz kodlanmadı.
- Correction layer yalnız contract/mock/read model seviyesinde kaldı.

---

## 7. Kritik Mimari Kararlar

### Snapshot Immutable

Kapanış snapshot'ı mühürlü referanstır. Revizyon ve correction süreçleri snapshot'ı değiştirmez.

### Revizyon Talebi Ayrı Workflow

Revizyon talebi TASLAK, ONAY_BEKLIYOR, ONAYLANDI, REDDEDILDI, IPTAL state modeliyle yürür.

### Scope Backend Sorumluluğudur

UI görünürlük sağlayabilir, ancak güvenlik backend/API enforcement ile sağlanır.

### Correction Layer Overlay Mantığıdır

ONAYLANDI revizyonlar correction event üretir. Rapor/read model, ham snapshot üzerine aktif correction overlay uygulayabilir.

### Bordro Hesabı Bu Zincirde Yoktur

Bordro etkisi flag/not/audit seviyesindedir. Net maaş, SGK, vergi veya finans hesabı bu zincirde yapılmamıştır.

---

## 8. Kod Tarafında Eklenen Ana Dosya Aileleri

**Revizyon contract:**

- `src/types/revizyon-talebi.ts`
- `src/api/revizyon-talebi.api.ts`
- `src/lib/revizyon-talebi/revizyon-state.ts`

**Scope enforcement:**

- `src/lib/revizyon-talebi/revizyon-scope.ts`
- `src/api/mock-demo.ts`

**Correction contract:**

- `src/types/revizyon-correction.ts`
- `src/api/revizyon-correction.api.ts`
- `src/lib/revizyon-talebi/revizyon-correction-map.ts`
- `src/lib/revizyon-talebi/revizyon-correction-state.ts`
- `src/lib/revizyon-talebi/revizyon-correction-overlay.ts`

**Permission:**

- `src/lib/authorization/role-permissions.ts`
- `docs/guncel/09-rol-yetki-matrisi.md`

---

## 9. Test Durumu

Son kod fazı 61 sonrasında:

- `npm run test` → 551 passed
- `npm run typecheck` → success
- CI #353 → success
- Deploy cPanel #331 → success

Son checkpoint 62:

- CI #354 → success
- Deploy cPanel #332 → success

**Test kapsamı:**

- Revizyon state
- Revizyon API contract
- Role permissions
- Mock scope enforcement
- Correction state/idempotency
- Correction overlay
- Correction API/mock integration
- Snapshot unchanged

---

## 10. Bilinen Sınırlar / Eksikler

- Gerçek backend auth/session/JWT entegrasyonu yok.
- Gerçek backend persist katmanı yok.
- Gerçek backend audit log yok.
- UI ekranı yok.
- Rota yok.
- Correction badge/audit görünümü yok.
- Ham snapshot / düzeltilmiş görünüm UI ayrımı yok.
- Bordro matematiği yok.
- Serbest zaman correction adapter yok.
- Süre parse/recompute yok.
- SUREC_GEC_GIRIS correction üretmez.
- String saat delta hesabı V1'de 0 kalır.
- CORRECTION_RECOMPUTE_REQUIRED tanımlıdır ama tetiklenmez.

---

## 11. Bundan Sonra Açılabilecek Yol Ayrımları

| Seçenek | Amaç | Risk | Öneri |
|---------|------|------|-------|
| **A. Revizyon / Correction UI Karar Dokümanı** | Liste, detay, correction badge, audit görünümü, ham/düzeltilmiş görünüm ayrımı. | UI erken şişebilir. | UI'a geçilecekse önce karar dokümanı. |
| **B. Gerçek Backend Enforcement Kod Fazı** | auth context, gerçek scope, persist, audit log. | Backend/auth hazır değilse erken. | Backend altyapısı netleşmeden girilmemeli. |
| **C. Correction Recompute / Adapter Kararı** | süre parse, yeniden hesaplama, serbest zaman adapter. | motor sınırına girer. | Ayrı karar dokümanı gerekir. |
| **D. Zinciri burada dondurma** | Revizyon altyapısını şimdilik contract/mock seviyesinde bırakmak. | UI ve backend kullanılabilirlik bekler. | Yorulduysak en güvenli seçenek. |

---

## 12. Önerilen Yakın Adım

Kısa vadede en güvenli karar, bu zinciri burada dondurmak ve yeni kod fazına geçmemektir. UI veya gerçek backend tarafına geçilecekse önce ayrı karar dokümanı açılmalıdır.

**Önerilen:**

- Eğer devam edilecekse: **64 — Revizyon / Correction UI Karar Dokümanı**
- Eğer ara verilecekse: Bu 63 devir notu yeterli kapanış kabul edilir.

---

## 13. Yeni Sayfa / Devir Cümlesi

Yeni oturumda devam edilecekse başlangıç notu:

> PersonelMedisa projesinde revizyon talebi zinciri 50–62 arası kapandı. Son commit 9c68f66, CI #354 ve Deploy #332 success. Revizyon talebi contract, mock scope enforcement ve correction layer contract hazır. Snapshot immutable, motorlar untouched, UI henüz yok. Sıradaki karar: UI'a mı geçilecek, gerçek backend enforcement'a mı, yoksa zincir dondurulacak mı?

---

## 14. Kapanış Cümlesi

50–62 revizyon talebi zinciri, kapalı dönem revizyon workflow'unu karar, contract, mock enforcement ve correction layer seviyesinde tamamlamıştır. Snapshot, puantaj motoru, serbest zaman motoru ve UI katmanları bilinçli olarak korunmuş; zincir test, CI ve Deploy doğrulamasıyla güvenli devir seviyesine getirilmiştir.
