CODE_MIGRATION_TIP: 093
PRODUCTION_MIGRATION_TIP: 093
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 093
FRESH_PRODUCTION_MIGRATION_READBACK: DEPLOY_CPANEL_36543499484
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: 0ef844475a3523b3e54215994a053cd27534c575
CODE_MAIN_SHA: 0ef844475a3523b3e54215994a053cd27534c575
LAST_MERGED_PR: 441
ACTIVE_BACKLOG_OWNER: docs/guncel/146-post-pr402-canonical-backlog.md

# PersonelMedisa — Canonical State Pin (POST_PR441)

**Aktif residual backlog otoritesi:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](docs/guncel/146-post-pr402-canonical-backlog.md)
**110:** SUPERSEDED for active backlog (historical archive only).
**Do not reopen** PRs #395–#402 without new concrete contradiction.

## Live / code pin (2026-09-29 POST_PR441)

| Alan | Değer | Kanıt |
| --- | --- | --- |
| CODE / PROD migration tip | **093 / 093** | Filesystem tip 093; production tip 093 (migration `093_attendance_anomaly_notification_dedupe.sql` applied with PR **#439** rollout). **PENDING: 0** |
| LAST_VERIFIED production tip | **093** | Deploy cPanel run `36543499484` SUCCESS (merge #441); readback `DEPLOY_CPANEL_36543499484` |
| PRODUCTION_DEPLOY_SHA | `0ef84447…` | Deploy cPanel run `36543499484` SUCCESS (#441) |
| CODE_MAIN_SHA | `0ef84447…` | `origin/main` == last merged PR **#441** |
| PR #439 | **MERGED / DEPLOYED** | Attendance anomaly detection + amir correction flow; migration **093** APPLIED |
| PR #440 | **MERGED / DEPLOYED** | Çalışma Geçmişi aylık onaylı toplam saat + gün bazlı puantaj/QR toplamı |
| PR #441 | **MERGED / DEPLOYED** | `MEDISA_ATTENDANCE_ANOMALY_THRESHOLD_MINUTES` test override; **production default 180**; prod cron env yok |
| Attendance anomaly cron | **INSTALLED** | `*/5 * * * *` → `/usr/local/bin/ea-php81 /home/karmotor/public_html/personelmedisa/api/bin/attendance-anomaly-scan.php` (not generic `/usr/local/bin/php`) |
| Migration 090–092 | **APPLIED** | Historical Actions apply evidence (`35781766535`, `35791415567`, `36367311876`) |

PRODUCTION_MUTATION_THIS_PIN: 0 (docs/registry consolidation only).

## Hard-closed (do not reopen without new contradiction)

- PR #271 / #272 / #274 / #299 / #300 / #301: CLOSED (historical deploy pins kept in older archive notes only)
- PR #326: MERGED + migrations 090/091 APPLIED — do not treat as OPEN/pending
- PR #395–#402: MERGED / CI PASS / DEPLOYED as applicable — visual/QR pilot/self-service sweep CLOSED
- PR #439–#441: MERGED / DEPLOYED — attendance anomaly/correction UX, çalışma geçmişi toplamları, anomaly threshold env override CLOSED
- A1 12/13 branch-specific SGK period: **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION**
- REPORTING_PERIOD_CANONICAL_AXIS: SGK_ISVEREN
- BRANCH_SPECIFIC_PERIOD_REQUIRED: NO
- 12/13 branch-specific period rows are NOT the target fix.
- Loc5 / 160/211 / personnel master remediation APPLIED sets: CLOSED
- Branch-manager product model: LOCKED (`USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY`; `BRANCH_MANAGER_OWNER: sube_sorumlu_yoneticiler`)
- QR core S3C–S3F + pilot readiness doc: CLOSED
- BL-QR-ANOMALY core (unresolved anomaly read + amir correction + cron scan + migration 093 dedupe): **CLOSED** (residuals → 146 only)

## Active residuals → 146 only

Do **not** track open work in this file. Classes and IDs live in 146:

- D) `NO_EVENT_DAY` · `POST_THRESHOLD_REENTRY` · form-hint · Karyapı/Şenay (DEFERRED) · device/offline/geofence/NFC non-goals
- E) QR pilot field validation (physical iPhone + kiosk) · attendance cron runtime tick observe · Sinem/Halil live verify · stale branch delete

## Historical note (2026-09-25 POST_PR402 pin — SUPERSEDED)

Previous pin claimed: PRODUCTION tip **092**, PENDING **1** (`093` not applied), DEPLOY `5c6ae773`, LAST_MERGED_PR **402**.
Those claims are **obsolete** after #439–#441 merge + deploy `36543499484` + production tip **093**.

Archive identity strings (not current gates):
PREPARER_HISTORICAL: sedanurB
APPROVER_HISTORICAL: Sinem Hamaloğlu
TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE _(historical BM gap label; code owner shipped — not an open tip)_

NEXT_GATE: user decisions (146 D) + ops observations (146 E). No merge/deploy/migration apply in this consolidation turn.
