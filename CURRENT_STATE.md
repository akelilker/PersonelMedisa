CODE_MIGRATION_TIP: 095
PRODUCTION_MIGRATION_TIP: 095
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 095
FRESH_PRODUCTION_MIGRATION_READBACK: MIGRATION_095_APPLIED
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: b01216d5e94a3269dc57eca0f316703aaf26d54c
CODE_MAIN_SHA: b01216d5e94a3269dc57eca0f316703aaf26d54c
LAST_MERGED_PR: 470
ACTIVE_BACKLOG_OWNER: docs/guncel/146-post-pr402-canonical-backlog.md

# PersonelMedisa — Canonical State Pin (POST_PR470)

**Aktif residual backlog otoritesi:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](docs/guncel/146-post-pr402-canonical-backlog.md)
**110:** SUPERSEDED for active backlog (historical archive only).
**Do not reopen** PRs #395–#402 without new concrete contradiction.

## Live / code pin (2026-10-01 POST_PR470)

| Alan | Değer | Kanıt |
| --- | --- | --- |
| CODE / PROD migration tip | **095 / 095** | Migration **095** (`095_personel_cinsiyet.sql`) **APPLIED / SUCCESS**. **PENDING: 0** |
| Migration 094 | **APPLIED** | `094_attendance_no_event_day.sql` production'da geçmiş; sonrasında 095 uygulanmış |
| LAST_VERIFIED production tip | **095** | Migration 095 production APPLIED |
| PRODUCTION_DEPLOY_SHA | `b01216d5…` | Deploy cPanel **#1201** run **`36913900426`** SUCCESS (merge **#470**) |
| CODE_MAIN_SHA | `b01216d5…` | `origin/main` == last merged PR **#470** |
| PR #470 | **MERGED / DEPLOYED** | UI correction batch (Level 2+ back nav, Personel Kartı missing-info gateway, duplicate CTA cleanup, Vazgeç fix, /self gateway polish) |
| Attendance / QR phase | **CLOSED** | QR attendance + saha rollout + puantaj doğrulaması + personel go-live tamamlandı |

PRODUCTION_MUTATION_THIS_PIN: 0 (docs-only sync).

## Hard-closed (do not reopen without new contradiction)

- PR #271 / #272 / #274 / #299 / #300 / #301: CLOSED (historical deploy pins kept in older archive notes only)
- PR #326: MERGED + migrations 090/091 APPLIED — do not treat as OPEN/pending
- PR #395–#402: MERGED / CI PASS / DEPLOYED as applicable — visual/QR pilot/self-service sweep CLOSED
- PR #439–#441: MERGED / DEPLOYED — attendance anomaly/correction UX, çalışma geçmişi toplamları, anomaly threshold env override CLOSED
- PR #470: MERGED / DEPLOYED (Deploy #1201) — UI correction batch CLOSED
- A1 12/13 branch-specific SGK period: **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION**
- REPORTING_PERIOD_CANONICAL_AXIS: SGK_ISVEREN
- BRANCH_SPECIFIC_PERIOD_REQUIRED: NO
- 12/13 branch-specific period rows are NOT the target fix.
- Loc5 / 160/211 / personnel master remediation APPLIED sets: CLOSED
- Branch-manager product model: LOCKED (`USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY`; `BRANCH_MANAGER_OWNER: sube_sorumlu_yoneticiler`)
- QR core S3C–S3F + pilot readiness doc: CLOSED
- BL-QR-ANOMALY core (unresolved anomaly read + amir correction + cron scan + migration 093 dedupe): **CLOSED** (residuals → 146 only)
- BL-NO-EVENT-DAY: **CLOSED / LIVE** — migration 094 APPLIED (production'da geçmiş, sonrasında 095)
- BL-QR-PILOT-OPS: **PASS / CLOSED** — fiziksel pilot + saha rollout tamam; Attendance/QR phase CLOSED

## Active residuals → 146 only

Do **not** track open work in this file. Open IDs live in 146:

- D) form-hint · Karyapı/Şenay (DEFERRED) · device/offline/geofence/NFC non-goals
- E) `BL-CROSS-COMPANY` (defer)

## Historical note (2026-09-29 POST_PR441 pin — SUPERSEDED)

Previous pin claimed: PRODUCTION tip **094**, PENDING **0**, DEPLOY `0ef84447…`, LAST_MERGED_PR **441**.
Obsolete after #470 merge (SHA `b01216d5`), Deploy cPanel **#1201** run `36913900426`, migration **095** APPLIED (production tip **095**); migration **094** APPLIED (NO_EVENT_DAY live).

Archive identity strings (not current gates):
PREPARER_HISTORICAL: sedanurB
APPROVER_HISTORICAL: Sinem Hamaloğlu
TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE _(historical BM gap label; code owner shipped — not an open tip)_

NEXT_GATE: attendance/QR phase CLOSED — no open gate. Residuals: D/E defer-only (146). No merge/deploy/migration apply in this docs-only turn.
