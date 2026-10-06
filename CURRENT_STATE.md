CODE_MIGRATION_TIP: 097
PRODUCTION_MIGRATION_TIP: 097
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 097
FRESH_PRODUCTION_MIGRATION_READBACK: MIGRATION_097_APPLIED
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: 83a517011fcf32967adb87d4f44c1bcc833a45a8
CODE_MAIN_SHA: 83a517011fcf32967adb87d4f44c1bcc833a45a8
LAST_MERGED_PR: 507
ACTIVE_BACKLOG_OWNER: docs/guncel/146-post-pr402-canonical-backlog.md
PIN_SHA_BASELINE: LAST_PRODUCT_MERGE_DEPLOY
DOCS_ONLY_CLOSURE_THIS_PIN: NO

# PersonelMedisa — Canonical State Pin (POST_PR507 + migration 097 closure)

**Aktif residual backlog otoritesi:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](docs/guncel/146-post-pr402-canonical-backlog.md)
**110:** SUPERSEDED for active backlog (historical archive only).
**Do not reopen** PRs #395–#402 without new concrete contradiction.

## Live / code pin (2026-10-06 POST_PR507)

| Alan | Değer | Kanıt |
| --- | --- | --- |
| CODE / PROD migration tip | **097 / 097** | Migration **097** (`097_qr_attendance_location_audit.sql`) **APPLIED / SUCCESS** (Apply cPanel migrations **#56** run `37391130248`); post-apply readback Ops migration worker diagnostics **#90** run `37391796990`: `CODE_TIP=097`, `PROD_TIP=097`, `PENDING_MIGRATIONS=NONE`. **PENDING: 0** |
| Migration 094 | **APPLIED** | `094_attendance_no_event_day.sql` production'da geçmiş |
| Migration 095 | **APPLIED** | `095_personel_cinsiyet.sql` production APPLIED |
| Migration 096 | **APPLIED / SUCCESS** | `096_personel_bordro_okumalari.sql` — additive bordro "Okudum" audit (seed/backfill yok); canonical apply run `37021807281` SUCCESS; worker SUCCEEDED; backup `medisa-pre-096-370***807281-1-20261002-144504.sql` readback VERIFIED; post-apply readback run `37022115475` PROD_TIP **096** |
| Migration 097 | **APPLIED / SUCCESS** | `097_qr_attendance_location_audit.sql` — `qr_attendance_events` konum denetim kolonları (#496); pre-apply preflight Ops migration worker diagnostics **#89** run `37389148523` PASS (PROD_TIP 096, pending 097); Apply cPanel migrations **#56** run `37391130248` SUCCESS; worker completed; backup `medisa-pre-097-37391130248-1-20261006-000005.sql` readback VERIFIED; post-apply readback Ops migration worker diagnostics **#90** run `37391796990` PROD_TIP **097**, `PREFLIGHT_BLOCKERS=NO_PENDING_MIGRATIONS` (beklenen: uygulanacak migration kalmadı) |
| LAST_VERIFIED production tip | **097** | Post-apply readback Ops migration worker diagnostics **#90** run `37391796990` (PROD_TIP=097, PENDING_COUNT=0, PROCESSING_COUNT=0, WORKER_BUSY=NO, REMOTE_DEPLOY_SHA=`83a51701…`) |
| PRODUCTION_DEPLOY_SHA | `83a51701…` | Last **product** deploy marker — Deploy cPanel **#1240** **SUCCESS** (merge **#507**; `FINAL_SHA_GET=SUCCESS`, smoke OK); ops `deployed_sha` gate |
| CODE_MAIN_SHA | `83a51701…` | Same as **PRODUCTION_DEPLOY_SHA** — last **product** merge baseline (**#507**); docs-only state PRs do **not** advance `LAST_MERGED_PR` / SHA pins |
| PR #470 | **MERGED / DEPLOYED** | UI correction batch (Level 2+ back nav, Personel Kartı missing-info gateway, duplicate CTA cleanup, Vazgeç fix, /self gateway polish) |
| PR #472 | **MERGED / DEPLOYED** | Self-service closure (PR470 follow-up) — `6b7dbba6…`; Deploy cPanel **#1203** run `37018359986` SUCCESS |
| PR #475 | **MERGED / DEPLOYED** | `fix(ui): bugun personel durumu sube secimi grid + sade sube detayi` — `939c5f87…`; Deploy cPanel **#1206** run `37069634704` SUCCESS |
| PR #476 | **MERGED / DEPLOYED** | `/self` PERSONEL görsel shell (AppShell) + 120/158 `calisan_kapsami` `DIS_KAYNAK` canlı düzeltme + Harici Personel terminoloji + canonical kapanış — `817a1e72…`; Deploy cPanel **#1207** run `37077642885` SUCCESS |
| PR #496 | **MERGED / DEPLOYED** | `feat(qr): GPS geofence audit on QR scan (v1, non-blocking)` + migration **097** — `14dc7fa9…`; Deploy cPanel **#1228** run `37365978799` SUCCESS |
| PR #507 | **MERGED / DEPLOYED** | UX: Anlık personel durumu, şube çerçeveleri, tek süreç araması, footer — `83a51701…`; Deploy cPanel **#1240** SUCCESS |
| Attendance / QR phase | **CLOSED** | QR attendance + saha rollout + puantaj doğrulaması + personel go-live tamamlandı |

PRODUCTION_MUTATION_THIS_PIN: 0 (personel/yetki veri mutation yok). Migration **097** apply ayrı canonical run `37391130248`. Önceki POST_PR476 pin: 2 (personel 120 + 158 `calisan_kapsami` `IC_PERSONEL` → `DIS_KAYNAK` canlı düzeltme; readback doğrulandı) — aşağıdaki business truth tablosu geçerli.

## Hard-closed (do not reopen without new contradiction)

- PR #271 / #272 / #274 / #299 / #300 / #301: CLOSED (historical deploy pins kept in older archive notes only)
- PR #326: MERGED + migrations 090/091 APPLIED — do not treat as OPEN/pending
- PR #395–#402: MERGED / CI PASS / DEPLOYED as applicable — visual/QR pilot/self-service sweep CLOSED
- PR #439–#441: MERGED / DEPLOYED — attendance anomaly/correction UX, çalışma geçmişi toplamları, anomaly threshold env override CLOSED
- PR #470: MERGED / DEPLOYED (Deploy #1201) — UI correction batch CLOSED
- PR #472: MERGED / DEPLOYED (Deploy #1203) — self-service closure CLOSED
- PR #475: MERGED / DEPLOYED (Deploy #1206) — "bugun personel durumu" şube seçimi grid + sade şube detayı CLOSED
- PR #476: MERGED / DEPLOYED (Deploy #1207) — `/self` PERSONEL görsel shell + 120/158 `DIS_KAYNAK` canlı düzeltme + Harici Personel terminoloji CLOSED
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
| 120 İsmail Özcan | Harici Personel; şube **11 Şenay Mobilya** + SGK işveren **3 Şenay Mobilya** + çalışma lokasyonu **5 Fabrika/Karabük** | **DÜZELTİLDİ** — canlı `calisan_kapsami` `IC_PERSONEL` idi; `DIS_KAYNAK` olarak düzeltildi (readback doğrulandı). Şube/SGK/lokasyon/AKTIF değişmedi |
| 158 Salih Efe | Harici Personel; şube **11 Şenay Mobilya** + SGK işveren **3 Şenay Mobilya** + çalışma lokasyonu **5 Fabrika/Karabük** | **DÜZELTİLDİ** — canlı `calisan_kapsami` `IC_PERSONEL` idi; `DIS_KAYNAK` olarak düzeltildi (readback doğrulandı). Şube/SGK/lokasyon/AKTIF değişmedi |
| 219 Doğu Berkan Atmaca | Medisa personeli; şube **6 Medisa İstanbul** + SGK işveren **1 Medisa** + çalışma lokasyonu **3 İstanbul** | **NO ACTION** — Karyapı'dan Medisa'ya transfer zaten yansımış; Karyapı state'i yok |
| `serhan.kose` (user 9) | `rol: GENEL_YONETICI`, `durum: AKTIF` — `sinemH` (user 110) ile aynı yetki modeli | **NO ACTION** — hedef rol/seviye zaten canlıda; tek tek şube/company grant üretilmedi |

219 ve `serhan.kose` "cross-company problem" / "eksik erişim" olarak bekleyen iş DEĞİLDİR; canlı doğrulama business truth'u zaten karşılıyor. 120/158 için canlı `calisan_kapsami` yanlıştı (`IC_PERSONEL`) ve canonical personel update owner (`PUT /personeller/{id}`) üzerinden `DIS_KAYNAK` olarak düzeltildi. Production mutation (personel/yetki) = **2** (120 + 158).

## Terminology lock (2026-10-03)

Personel sınıfı için **tek canonical Türkçe terim**: **Harici Personel** (karşılığı: **Dahili Personel**).
"Dış Kaynak", "Dış Kaynak Çalışan", "External Worker", "External Personnel" kullanıcıya / canonical business truth'a **YANSITILMAZ**.
Internal enum/storage identifier `DIS_KAYNAK` (ve `IC_PERSONEL`) geriye dönük uyumluluk için **DEĞİŞMEDEN** kalır; görünen ad daima "Harici Personel".
Harici Personel olması Fabrika/Karabük'te görevli olmasına engel DEĞİLDİR; Fabrika'da çalışması da onu Dahili Personel yapmaz (iki ayrı eksen).

## Historical note (2026-10-02/03 + POST_PR476 prior pins — SUPERSEDED)

Previous pin (POST_PR472) claimed: PRODUCTION tip **096**, PENDING **0**, DEPLOY `6b7dbba6…` (#472 / Deploy #1203), LAST_MERGED_PR **472**.
Previous pin (POST_PR475) claimed: DEPLOY `939c5f87…` (#475 / Deploy #1206), LAST_MERGED_PR **475**.
Obsolete after #476 merge (SHA `817a1e72…`), Deploy cPanel **#1207** run `37077642885` SUCCESS; migration **096** APPLIED (production tip **096**); migration **095** APPLIED; migration **094** APPLIED (NO_EVENT_DAY live).
Previous pin (POST_PR476) claimed: CODE/PROD tip **096 / 096**, DEPLOY `817a1e72…` (#476 / Deploy #1207 run `37077642885`), LAST_MERGED_PR **476**.
Obsolete after #496 merge (SHA `14dc7fa9…`), Deploy cPanel **#1228** run `37365978799` SUCCESS; migration **097** APPLIED (Apply #56 run `37391130248`; readback Ops #90 run `37391796990` PROD_TIP **097**).
Previous pin (POST_PR496) claimed: DEPLOY `14dc7fa9…` (#496 / Deploy #1228), LAST_MERGED_PR **496**.
Obsolete after #507 merge (SHA `83a51701…`), Deploy cPanel **#1240** SUCCESS; migration tip **097 / 097**, pending **0** (unchanged).

Archive identity strings (not current gates):
PREPARER_HISTORICAL: sedanurB
APPROVER_HISTORICAL: Sinem Hamaloğlu
TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE _(historical BM gap label; code owner shipped — not an open tip)_

NEXT_GATE: NONE — no open technical / product / operational gate. D/E residual'lar CLOSED / NON-GOAL (146). 120/158 `calisan_kapsami` `DIS_KAYNAK` olarak canlı düzeltildi; 219 + `serhan.kose` canlı readback ile doğrulandı (değişmedi).
