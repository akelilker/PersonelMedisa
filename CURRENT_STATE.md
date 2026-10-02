CODE_MIGRATION_TIP: 096
PRODUCTION_MIGRATION_TIP: 096
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 096
FRESH_PRODUCTION_MIGRATION_READBACK: MIGRATION_096_APPLIED
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: 6b7dbba6e54886c503c04438d9e4ecd60faae3e8
CODE_MAIN_SHA: 6b7dbba6e54886c503c04438d9e4ecd60faae3e8
LAST_MERGED_PR: 472
ACTIVE_BACKLOG_OWNER: docs/guncel/146-post-pr402-canonical-backlog.md
PIN_SHA_BASELINE: LAST_PRODUCT_MERGE_DEPLOY
DOCS_ONLY_CLOSURE_THIS_PIN: YES

# PersonelMedisa — Canonical State Pin (POST_PR472 + migration 096 closure)

**Aktif residual backlog otoritesi:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](docs/guncel/146-post-pr402-canonical-backlog.md)
**110:** SUPERSEDED for active backlog (historical archive only).
**Do not reopen** PRs #395–#402 without new concrete contradiction.

## Live / code pin (2026-10-02 POST_PR472)

| Alan | Değer | Kanıt |
| --- | --- | --- |
| CODE / PROD migration tip | **096 / 096** | Migration **096** (`096_personel_bordro_okumalari.sql`) **APPLIED / SUCCESS** (canonical apply run `37021807281`); production tip **096**. **PENDING: 0** |
| Migration 094 | **APPLIED** | `094_attendance_no_event_day.sql` production'da geçmiş |
| Migration 095 | **APPLIED** | `095_personel_cinsiyet.sql` production APPLIED |
| Migration 096 | **APPLIED / SUCCESS** | `096_personel_bordro_okumalari.sql` — additive bordro "Okudum" audit (seed/backfill yok); canonical apply run `37021807281` SUCCESS; worker SUCCEEDED; backup `medisa-pre-096-370***807281-1-20261002-144504.sql` readback VERIFIED; post-apply readback run `37022115475` PROD_TIP **096** |
| LAST_VERIFIED production tip | **096** | Post-apply readback run `37022115475` + worker diagnostics run `37023928949` (PROCESSING_COUNT=0, WORKER_BUSY=NO) |
| PRODUCTION_DEPLOY_SHA | `6b7dbba6…` | Last **product** deploy marker — Deploy cPanel **#1203** run **`37018359986`** SUCCESS (merge **#472**); ops `deployed_sha` gate |
| CODE_MAIN_SHA | `6b7dbba6…` | Same as **PRODUCTION_DEPLOY_SHA** — last **product** merge baseline (**#472**); docs-only state PRs (**#471**, **#473**) do **not** advance `LAST_MERGED_PR` / SHA pins |
| PR #470 | **MERGED / DEPLOYED** | UI correction batch (Level 2+ back nav, Personel Kartı missing-info gateway, duplicate CTA cleanup, Vazgeç fix, /self gateway polish) |
| PR #472 | **MERGED / DEPLOYED** | Self-service closure (PR470 follow-up) — `6b7dbba6…`; Deploy cPanel **#1203** run `37018359986` SUCCESS |
| Attendance / QR phase | **CLOSED** | QR attendance + saha rollout + puantaj doğrulaması + personel go-live tamamlandı |

PRODUCTION_MUTATION_THIS_PIN: 0 (docs-only closure; migration **096** apply ayrı canonical run `37021807281`). Merge **#473** may retrigger Deploy cPanel on `main` without advancing SHA pins (POST_PR471 precedent).

## Hard-closed (do not reopen without new contradiction)

- PR #271 / #272 / #274 / #299 / #300 / #301: CLOSED (historical deploy pins kept in older archive notes only)
- PR #326: MERGED + migrations 090/091 APPLIED — do not treat as OPEN/pending
- PR #395–#402: MERGED / CI PASS / DEPLOYED as applicable — visual/QR pilot/self-service sweep CLOSED
- PR #439–#441: MERGED / DEPLOYED — attendance anomaly/correction UX, çalışma geçmişi toplamları, anomaly threshold env override CLOSED
- PR #470: MERGED / DEPLOYED (Deploy #1201) — UI correction batch CLOSED
- PR #472: MERGED / DEPLOYED (Deploy #1203) — self-service closure CLOSED
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

## Active residuals → 146 only

Do **not** track open work in this file. Open IDs live in 146:

- D) form-hint · Karyapı/Şenay (DEFERRED) · device/offline/geofence/NFC non-goals
- E) `BL-CROSS-COMPANY` (defer)

## Historical note (2026-09-29 POST_PR441 pin — SUPERSEDED)

Previous pin claimed: PRODUCTION tip **094**, PENDING **0**, DEPLOY `0ef84447…`, LAST_MERGED_PR **441**.
Obsolete after #472 merge (SHA `6b7dbba6`), Deploy cPanel **#1203** run `37018359986`, migration **096** APPLIED (production tip **096**); migration **095** APPLIED; migration **094** APPLIED (NO_EVENT_DAY live).

Archive identity strings (not current gates):
PREPARER_HISTORICAL: sedanurB
APPROVER_HISTORICAL: Sinem Hamaloğlu
TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE _(historical BM gap label; code owner shipped — not an open tip)_

NEXT_GATE: attendance/QR phase CLOSED — no open gate. Residuals: D/E defer-only (146). No merge/deploy/migration apply in this docs-only turn.
