CODE_MIGRATION_TIP: 100
PRODUCTION_MIGRATION_TIP: 099
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 099
FRESH_PRODUCTION_MIGRATION_READBACK: MIGRATION_099_APPLY_POSTCHECK_PASS

# 110 — Master Closure / Gap Registry — SUPERSEDED

**STATUS: SUPERSEDED (2026-09-25 POST_PR402; tips/SHA refreshed POST_PR520)**
**Active backlog owner:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](146-post-pr402-canonical-backlog.md)
**Canonical tips/SHA pin:** [`CURRENT_STATE.md`](../../CURRENT_STATE.md)

This file is retained as a **historical archive** of the 2026-09-20 SGK employer-period recovery pin. It is **not** the authority for open work, pending migrations, or live deploy SHA.

| Alan | Archive value (2026-09-20) | Current truth (see CURRENT_STATE / 146) |
| --- | --- | --- |
| CODE_MIGRATION_TIP | 091 | **100** (`100_user_yetki_istisnalari.sql` yalnız kodda; canlıya uygulanmadı) |
| PRODUCTION_MIGRATION_TIP | 089 (stale) | **099** APPLIED (postcheck kanıtı) |
| PRODUCTION_MIGRATION_PENDING | 2 (`090`+`091`) | **1** (`100`, ayrı onaylı apply bekler) |
| PR #326 | OPEN (stale) | **MERGED** |
| PRODUCTION_DEPLOY_SHA | `d96182a2` (#301) | `f14ddbe8` (#520); Deploy cPanel #1253 run `37968702102` SUCCESS |
| FRESH_READBACK | BLOCKED_EXTERNAL_GITHUB_ACTIONS_BILLING | ACTIONS_APPLY_099_37911294869 (postcheck PASS) |

## Migration durumu (current — mirrored for sync tests)

| Alan | Değer |
| --- | --- |
| CODE_MIGRATION_TIP | **099** |
| PRODUCTION_MIGRATION_TIP | **099** |
| LAST_VERIFIED_PRODUCTION_MIGRATION_TIP | **099** (postcheck kanıtı; bağımsız ledger readback yapılmadı) |
| FRESH_PRODUCTION_MIGRATION_READBACK | **ACTIONS_APPLY_099_37911294869** |
| PRODUCTION_MIGRATION_PENDING | **0** |
| Migration 087 | **APPLIED** |
| Migration 088 | **APPLIED** |
| Migration 089 | **APPLIED** |
| Migration 090 | **APPLIED** (Actions `35781766535`) |
| Migration 091 | **APPLIED** (Actions `35791415567`) |
| Migration 092 | **APPLIED** (Actions `36367311876`; backup VERIFIED; readback VERIFIED) |
| Migration 093 | **APPLIED** (Apply `36491356202`; `093_attendance_anomaly_notification_dedupe.sql`; worker SUCCEEDED; backup `medisa-pre-093-36491356202-1-20260928-223004.sql`; readback VERIFIED) |
| Migration 094 | **APPLIED** (`094_attendance_no_event_day.sql`) |
| Migration 095 | **APPLIED** (`095_personel_cinsiyet.sql`) |
| Migration 096 | **APPLIED** (Apply `37021807281`; `096_personel_bordro_okumalari.sql`; worker SUCCEEDED; backup `medisa-pre-096-370***807281-1-20261002-144504.sql`; readback run `37022115475` VERIFIED) |
| Migration 097 | **APPLIED** (Apply #56 `37391130248`; `097_qr_attendance_location_audit.sql`; worker completed; backup `medisa-pre-097-37391130248-1-20261006-000005.sql` readback VERIFIED; readback Ops #90 `37391796990` PROD_TIP 097, pending 0) |
| Migration 098 | **APPLIED** (Apply #57 `37686359952`; preflight `37899949011` PROD_TIP 098) |
| Migration 099 | **APPLIED** (APPLY `37911294869`; `099_user_kalici_silme_auditleri.sql`; worker SUCCEEDED; backup readback VERIFIED; postcheck PASS) |
| CODE_MAIN_SHA / PRODUCTION_DEPLOY_SHA | `f14ddbe897bf8e64b35d9320cfac55cb427b1758` (last product deploy cPanel #1253 SUCCESS, #520; docs-only merges do not advance pin) |
| PR #326 | **MERGED** |
| PR #402 | **MERGED / DEPLOYED** |
| PR #439–#441 | **MERGED / DEPLOYED** |
| PR #470 / #472 | **MERGED / DEPLOYED** |
| PR #475 / #476 | **MERGED / DEPLOYED** |
| PR #496 | **MERGED / DEPLOYED** |
| PR #507–#520 | **MERGED / DEPLOYED** (#513 docs-only) |

## Preserved business locks (still true; not reopen)

A1_SUPERSEDED_BY: SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION
A1_12_13: SUPERSEDED
SGK_REPORTING_PERIOD_OWNER: SGK_ISVEREN
REPORTING_PERIOD_IS_MANAGEMENT_CHOICE: NO
REPORTING_PERIOD_CANONICAL_AXIS: SGK_ISVEREN
BRANCH_SPECIFIC_PERIOD_REQUIRED: NO
A1_12_13_TARGET_FIX: NO — 12/13 branch-specific period rows are NOT the target fix.

USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY
BRANCH_MANAGER_OWNER: sube_sorumlu_yoneticiler
TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE _(historical label for BM durable owner shipping era; not an open code tip)_

PREPARER_HISTORICAL: sedanurB
APPROVER_HISTORICAL: Sinem Hamaloğlu

## What moved to 146

All former “pending / waiting / intentional defer / business decision” rows that are still real residuals are consolidated and reclassified in **146**. Do not add new open items here.

Former false-pending items removed from active tracking:

- Migration 090/091 claimed as code-only pending apply
- PR #326 OPEN / merge-deploy-apply waiting
- Deploy pin `d96182a2` as current live
- Billing blocker as current apply gate
- QR self-service as undifferentiated INTENTIONAL_DEFER (core LIVE; only D/E residuals remain)
- Migration 093 NOT_APPLIED (superseded POST_PR441 — production tip 093)

## Historical narrative

Full 2026-09-20 prose (hosting recovery, loc5 tables, A1 dual-control archive, NEXT_GATE merge-326-then-apply) lives in git history prior to POST_PR402 consolidation. Re-read that revision only as archive evidence — not as current ops plan.
