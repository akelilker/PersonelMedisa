CODE_MIGRATION_TIP: 096
PRODUCTION_MIGRATION_TIP: 096
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 096
FRESH_PRODUCTION_MIGRATION_READBACK: MIGRATION_096_APPLIED
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: 939c5f87ec80fb5f742d81f23f0523ce8399c313
CODE_MAIN_SHA: 939c5f87ec80fb5f742d81f23f0523ce8399c313
LAST_MERGED_PR: 475
ACTIVE_BACKLOG_OWNER: docs/guncel/146-post-pr402-canonical-backlog.md
PIN_SHA_BASELINE: LAST_PRODUCT_MERGE_DEPLOY
DOCS_ONLY_CLOSURE_THIS_PIN: YES

# PersonelMedisa — Canonical State Pin (POST_PR475 + migration 096 closure)

**Aktif residual backlog otoritesi:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](docs/guncel/146-post-pr402-canonical-backlog.md)
**110:** SUPERSEDED for active backlog (historical archive only).
**Do not reopen** PRs #395–#402 without new concrete contradiction.

## Live / code pin (2026-10-03 POST_PR475)

| Alan | Değer | Kanıt |
| --- | --- | --- |
| CODE / PROD migration tip | **096 / 096** | Migration **096** (`096_personel_bordro_okumalari.sql`) **APPLIED / SUCCESS** (canonical apply run `37021807281`); production tip **096**. **PENDING: 0** |
| Migration 094 | **APPLIED** | `094_attendance_no_event_day.sql` production'da geçmiş |
| Migration 095 | **APPLIED** | `095_personel_cinsiyet.sql` production APPLIED |
| Migration 096 | **APPLIED / SUCCESS** | `096_personel_bordro_okumalari.sql` — additive bordro "Okudum" audit (seed/backfill yok); canonical apply run `37021807281` SUCCESS; worker SUCCEEDED; backup `medisa-pre-096-370***807281-1-20261002-144504.sql` readback VERIFIED; post-apply readback run `37022115475` PROD_TIP **096** |
| LAST_VERIFIED production tip | **096** | Post-apply readback run `37022115475` + worker diagnostics run `37023928949` (PROCESSING_COUNT=0, WORKER_BUSY=NO) |
| PRODUCTION_DEPLOY_SHA | `939c5f87…` | Last **product** deploy marker — Deploy cPanel **#1206** run **`37069634704`** SUCCESS (merge **#475**); ops `deployed_sha` gate |
| CODE_MAIN_SHA | `939c5f87…` | Same as **PRODUCTION_DEPLOY_SHA** — last **product** merge baseline (**#475**); docs-only state PRs do **not** advance `LAST_MERGED_PR` / SHA pins |
| PR #470 | **MERGED / DEPLOYED** | UI correction batch (Level 2+ back nav, Personel Kartı missing-info gateway, duplicate CTA cleanup, Vazgeç fix, /self gateway polish) |
| PR #472 | **MERGED / DEPLOYED** | Self-service closure (PR470 follow-up) — `6b7dbba6…`; Deploy cPanel **#1203** run `37018359986` SUCCESS |
| PR #475 | **MERGED / DEPLOYED** | `fix(ui): bugun personel durumu sube secimi grid + sade sube detayi` — `939c5f87…`; Deploy cPanel **#1206** run `37069634704` SUCCESS |
| Attendance / QR phase | **CLOSED** | QR attendance + saha rollout + puantaj doğrulaması + personel go-live tamamlandı |

PRODUCTION_MUTATION_THIS_PIN: 0 (docs-only closure; migration **096** apply ayrı canonical run `37021807281`). Merge **#475** may retrigger Deploy cPanel on `main` without advancing SHA pins (POST_PR471 precedent).

## Hard-closed (do not reopen without new contradiction)

- PR #271 / #272 / #274 / #299 / #300 / #301: CLOSED (historical deploy pins kept in older archive notes only)
- PR #326: MERGED + migrations 090/091 APPLIED — do not treat as OPEN/pending
- PR #395–#402: MERGED / CI PASS / DEPLOYED as applicable — visual/QR pilot/self-service sweep CLOSED
- PR #439–#441: MERGED / DEPLOYED — attendance anomaly/correction UX, çalışma geçmişi toplamları, anomaly threshold env override CLOSED
- PR #470: MERGED / DEPLOYED (Deploy #1201) — UI correction batch CLOSED
- PR #472: MERGED / DEPLOYED (Deploy #1203) — self-service closure CLOSED
- PR #475: MERGED / DEPLOYED (Deploy #1206) — "bugun personel durumu" şube seçimi grid + sade şube detayı CLOSED
- A1 12/13 branch-specific SGK period: **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION**
- REPORTING_PERIOD_CANONICAL_AXIS: SGK_ISVEREN
- BRANCH_SPECIFIC_PERIOD_REQUIRED: NO
- 12/13 branch-specific period rows are NOT the target fix.
- Loc5 / 160/211 / personnel master remediation APPLIED sets: CLOSED
- Branch-manager product model: LOCKED (`USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY`; `BRANCH_MANAGER_OWNER: sube_sorumlu_yoneticiler`)
- QR core S3C–S3F + pilot readiness doc: CLOSED
- BL-QR-ANOMALY core (unresolved anomaly read + amir correction + cron scan + migration 093 dedupe): **CLOSED** (residuals → 146 only)
- BL-NO-EVENT-DAY: **CLOSED / LIVE** — migration 094 APPLIED (production'da geçmiş)
- BL-QR-PILOT-OPS: **PASS / CLOSED** — fiziksel pilot + saha rollout tamam; Attendance/QR phase CLOSED

## Residual registry (owner: 146 only)

Do **not** track open work in this file. Residual statuses are owned by 146:

- D) `BL-FORM-HINT` → **active backlog'dan çıkarıldı** — ayrı yapılacak iş değil; kullanıcı görsel düzenleme sırasında ilgili ekranı gördüğünde isterse yardımcı yazıyı kaldırır.
- D) Device binding / Offline QR / GPS-geofence / NFC → **CLOSED NON-GOAL** — bekleyen/deferred iş değil; next gate'e çıkmaz.
- Karyapı / Şenay → **bizim bekleyen rollout işimiz DEĞİL** — program teslim edildiğinde firma sahipleri kendi kullanıcı/personel/şube kayıtlarını kendileri girecek.
- E) `BL-CROSS-COMPANY` (120 / 158 / 219) → **CLOSED** — güncel canlı/business truth ile hizalandı (aşağı bakınız); next gate üretmez.

## Business truth lock (canlı readback 2026-10-03)

| Personel / kişi | Canlı sonuç | Karar |
| --- | --- | --- |
| 120 İsmail Özcan | Harici personel; şube **11 Şenay Mobilya** + SGK işveren **3 Şenay Mobilya** + çalışma lokasyonu **5 Fabrika/Karabük** | **NO ACTION** — business truth korunuyor, Medisa personeline dönüştürülmez |
| 158 Salih Efe | Harici personel; şube **11 Şenay Mobilya** + SGK işveren **3 Şenay Mobilya** + çalışma lokasyonu **5 Fabrika/Karabük** | **NO ACTION** — business truth korunuyor, Medisa personeline dönüştürülmez |
| 219 Doğu Berkan Atmaca | Medisa personeli; şube **6 Medisa İstanbul** + SGK işveren **1 Medisa** + çalışma lokasyonu **3 İstanbul** | **NO ACTION** — Karyapı'dan Medisa'ya transfer zaten yansımış; Karyapı state'i yok |
| `serhan.kose` (user 9) | `rol: GENEL_YONETICI`, `durum: AKTIF` — `sinemH` (user 110) ile aynı yetki modeli | **NO ACTION** — hedef rol/seviye zaten canlıda; tek tek şube/company grant üretilmedi |

Bu dört kalem "cross-company problem" / "eksik erişim" olarak bekleyen iş DEĞİLDİR; canlı doğrulama business truth'u zaten karşılıyor. Production mutation (personel/yetki) = **0**.

## Historical note (2026-10-02 POST_PR472 pin — SUPERSEDED)

Previous pin claimed: PRODUCTION tip **096**, PENDING **0**, DEPLOY `6b7dbba6…` (#472 / Deploy #1203), LAST_MERGED_PR **472**.
Obsolete after #475 merge (SHA `939c5f87…`), Deploy cPanel **#1206** run `37069634704` SUCCESS; migration **096** APPLIED (production tip **096**); migration **095** APPLIED; migration **094** APPLIED (NO_EVENT_DAY live).

Archive identity strings (not current gates):
PREPARER_HISTORICAL: sedanurB
APPROVER_HISTORICAL: Sinem Hamaloğlu
TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE _(historical BM gap label; code owner shipped — not an open tip)_

NEXT_GATE: no open gate. D/E residual'lar CLOSED / NON-GOAL (146). 120/158/219 + `serhan.kose` business truth canlı readback ile hizalandı. No merge/deploy/migration apply in this turn.
