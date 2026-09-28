CODE_MIGRATION_TIP: 092
PRODUCTION_MIGRATION_TIP: 091
LAST_VERIFIED_PRODUCTION_MIGRATION_TIP: 091
FRESH_PRODUCTION_MIGRATION_READBACK: ACTIONS_APPLY_EVIDENCE_2026_09_22
PRODUCTION_MIGRATION_PENDING: 1
PRODUCTION_DEPLOY_SHA: 5c6ae7734d394022ea1ff65c5683da91c095e0eb
CODE_MAIN_SHA: 5c6ae7734d394022ea1ff65c5683da91c095e0eb
LAST_MERGED_PR: 402
ACTIVE_BACKLOG_OWNER: docs/guncel/146-post-pr402-canonical-backlog.md

# PersonelMedisa — Canonical State Pin (POST_PR402)

**Aktif residual backlog otoritesi:** [`docs/guncel/146-post-pr402-canonical-backlog.md`](docs/guncel/146-post-pr402-canonical-backlog.md)
**110:** SUPERSEDED for active backlog (historical archive only).
**Do not reopen** PRs #395–#402 without new concrete contradiction.

## Live / code pin (2026-09-25 POST_PR402_BACKLOG_CONSOLIDATION)

| Alan | Değer | Kanıt |
| --- | --- | --- |
| CODE / PROD migration tip | **092 / 091** | Filesystem tip 092; production 092 not applied. Apply cPanel migrations `35781766535` (090) + `35791415567` (091) SUCCESS |
| LAST_VERIFIED production tip | **091** | 091 run: preflight `GATE_PROD_TIP=090` then apply; backup `medisa-pre-091-…` VERIFIED |
| PRODUCTION_DEPLOY_SHA | `5c6ae773…` | Deploy cPanel run `36070333362` SUCCESS (#402) |
| CODE_MAIN_SHA | `5c6ae773…` | `origin/main` == last merged PR #402 |
| Migration 090 | **APPLIED** | Run `35781766535`; worker completed + backup VERIFIED |
| Migration 091 | **APPLIED** | Run `35791415567`; backup VERIFIED |
| PR #326 | **MERGED** | `8e137f2c` @ 2026-09-22 — SGK employer reporting-period owner |
| PR #402 | **MERGED / DEPLOYED** | Self-service mobile PWA polish |

PRODUCTION_MUTATION_THIS_PIN: 0 (docs/registry consolidation only).

## Hard-closed (do not reopen without new contradiction)

- PR #271 / #272 / #274 / #299 / #300 / #301: CLOSED (historical deploy pins kept in older archive notes only)
- PR #326: MERGED + migrations 090/091 APPLIED — do not treat as OPEN/pending
- PR #395–#402: MERGED / CI PASS / DEPLOYED as applicable — visual/QR pilot/self-service sweep CLOSED
- A1 12/13 branch-specific SGK period: **SUPERSEDED_BY_SGK_EMPLOYER_PERIOD_OWNER_CORRECTION**
- REPORTING_PERIOD_CANONICAL_AXIS: SGK_ISVEREN
- BRANCH_SPECIFIC_PERIOD_REQUIRED: NO
- 12/13 branch-specific period rows are NOT the target fix.
- Loc5 / 160/211 / personnel master remediation APPLIED sets: CLOSED
- Branch-manager product model: LOCKED (`USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY`; `BRANCH_MANAGER_OWNER: sube_sorumlu_yoneticiler`)
- QR core S3C–S3F + pilot readiness doc: CLOSED (remaining = product D + ops E in 146)

## Active residuals → 146 only

Do **not** track open work in this file. Classes and IDs live in 146:

- D) form-hint · QR device binding · offline QR · anomaly→revision UX · Kayseri Kübra · name203 · Karyapı/Şenay
- E) QR pilot ops ticks · Sinem/Halil live verify · BM assignment write · cross-company defer · stale branch delete
- B) `origin/cline/fw8ry7m8` safe-to-delete evidence (unique commits vs main = 0)

## Historical note (2026-09-20 pin — SUPERSEDED)

Previous pin claimed: PRODUCTION tip **089**, PENDING **090+091**, PR_326 OPEN, DEPLOY `d96182a2`, FRESH_READBACK `BLOCKED_EXTERNAL_GITHUB_ACTIONS_BILLING`, PHASE `SGK_EMPLOYER_REPORTING_PERIOD_CODE_CLOSE_BEFORE_ACTIONS_RECOVERY`.
Those claims are **obsolete** as active truth after #326 merge + 090/091 apply + #402 deploy. Historical narrative (A1 archive hashes, preparer/approver names, loc5 apply tables) remains in git history and in superseded `docs/guncel/110-master-closure-gap-registry.md`.

Archive identity strings (not current gates):
PREPARER_HISTORICAL: sedanurB
APPROVER_HISTORICAL: Sinem Hamaloğlu
TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE _(historical BM gap label; code owner shipped — not an open tip)_

NEXT_GATE: user decisions (146 D) + ops approvals (146 E). No merge/deploy/migration apply in this consolidation turn.
