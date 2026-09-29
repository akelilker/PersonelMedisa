CODE_MIGRATION_TIP: 093
PRODUCTION_MIGRATION_TIP: 093
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 093
FRESH_PRODUCTION_MIGRATION_READBACK: ACTIONS_APPLY_36491356202

# 110 — Master Closure / Gap Registry — SUPERSEDED

**STATUS: SUPERSEDED (2026-09-25 POST_PR402; tips/SHA refreshed POST_PR441)**
**Active backlog owner:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](146-post-pr402-canonical-backlog.md)
**Canonical tips/SHA pin:** [`CURRENT_STATE.md`](../../CURRENT_STATE.md)

This file is retained as a **historical archive** of the 2026-09-20 SGK employer-period recovery pin. It is **not** the authority for open work, pending migrations, or live deploy SHA.

| Alan | Archive value (2026-09-20) | Current truth (see CURRENT_STATE / 146) |
| --- | --- | --- |
| CODE_MIGRATION_TIP | 091 | **093** |
| PRODUCTION_MIGRATION_TIP | 089 (stale) | **093** APPLIED |
| PRODUCTION_MIGRATION_PENDING | 2 (`090`+`091`) | **0** |
| PR #326 | OPEN (stale) | **MERGED** |
| PRODUCTION_DEPLOY_SHA | `d96182a2` (#301) | `0ef84447` (#441); deploy run `36543499484` |
| FRESH_READBACK | BLOCKED_EXTERNAL_GITHUB_ACTIONS_BILLING | ACTIONS_APPLY_36491356202 |

## Migration durumu (current — mirrored for sync tests)

| Alan | Değer |
| --- | --- |
| CODE_MIGRATION_TIP | **093** |
| PRODUCTION_MIGRATION_TIP | **093** |
| LAST_VERIFIED_PRODUCTION_MIGRATION_TIP | **093** |
| FRESH_PRODUCTION_MIGRATION_READBACK | **ACTIONS_APPLY_36491356202** |
| PRODUCTION_MIGRATION_PENDING | **0** |
| Migration 087 | **APPLIED** |
| Migration 088 | **APPLIED** |
| Migration 089 | **APPLIED** |
| Migration 090 | **APPLIED** (Actions `35781766535`) |
| Migration 091 | **APPLIED** (Actions `35791415567`) |
| Migration 092 | **APPLIED** (Actions `36367311876`; backup VERIFIED; readback VERIFIED) |
| Migration 093 | **APPLIED** (Apply `36491356202`; `093_attendance_anomaly_notification_dedupe.sql`; worker SUCCEEDED; backup `medisa-pre-093-36491356202-1-20260928-223004.sql`; readback VERIFIED) |
| CODE_MAIN_SHA / PRODUCTION_DEPLOY_SHA | `0ef844475a3523b3e54215994a053cd27534c575` (deploy cPanel `36543499484`, #441) |
| PR #326 | **MERGED** |
| PR #402 | **MERGED / DEPLOYED** |
| PR #439–#441 | **MERGED / DEPLOYED** |

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
