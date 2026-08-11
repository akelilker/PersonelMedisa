# 104 — S2A Annual Leave Manual Entitlement / Opening Balance Discovery

**Branch:** `feat/annual-leave-entitlement-adjustments`  
**Date:** 2026-08-11  
**Base:** `origin/main` @ `d1ca95d` (S1 merge ancestor)  
**Status:** DISCOVERY COMPLETE — implementation blocked on business decisions  
**PR #142:** untouched (docs-only draft)

## 1. Problem

Statutory annual leave is deterministic. Production still needs auditable manual corrections for:

- opening / transfer balance from prior systems (`devir`)
- company-granted extra entitlement above legal minimum
- administrative correction
- reversal of a wrong prior adjustment

**Forbidden anti-pattern:** mutable `kalan_izin` / remaining-balance overwrite.

## 2. Ownership graph (current code)

| Concern | Owner | Evidence |
| --- | --- | --- |
| LEGAL_ENTITLEMENT_OWNER | FE pure fn `hesaplaIzinHakEdis` | `src/services/izin-hesap-motoru.ts` |
| USED_LEAVE_OWNER | FE pure fn over `surecler` (`IZIN` + `YILLIK_IZIN`, non-`IPTAL`) | same file |
| CURRENT_BALANCE_OWNER | FE `hesaplaIzinBakiye` = legal − used, clamp ≥ 0 | same file |
| FRONTEND_DISPLAY_OWNER | `PersonelIzinOzetSection` | personel kartı Genel |
| BACKEND_BALANCE_OWNER_BEFORE | **NONE** | no PHP leave-balance service/route |
| WRITE_OWNER (usage) | Süreç create/update (`IZIN`/`YILLIK_IZIN`) | `SureclerController` + Kayıt ve Süreç |
| AUDIT_OWNER (usage) | süreç row + existing actor patterns | `surecler` table |
| AUTHZ_OWNER (usage) | mixed: matrix vs hardcoded create gate | see §5 |

`FRONTEND_ONLY_LEAVE_CALC = YES`

Docs lock leave totals as system-derived, not form-entered (`docs/guncel/02-mvp-veri-kapsami.md` §4). Personel Kartı remains read-only surface (`10-yuzey-gorev-sinirlari.md`).

## 3. Root cause

The gap is **missing entitlement-layer ledger**, not a broken statutory formula.

Today:

```text
remaining = max(legal_entitlement − used_from_surecler, 0)
```

There is no place to record “+8 opening transfer” without faking `YILLIK_IZIN` consumption or editing hire/DOB inputs.

`REAL_ROOT_CAUSE = NO_SERVER_OWNED_ENTITLEMENT_ADJUSTMENT_LEDGER`

## 4. Legal calc status

Statutory bands in `izin-hesap-motoru.ts` match `04-hesap-motoru-kurallari.md` §10:

- &lt;1 year → 0  
- 1–5 → 14  
- &gt;5 &lt;15 → 20  
- 15+ → 26  
- age ≤18 or ≥50 → min 20  

`LEGAL_CALC_DEFECT_FOUND = NO`  
Do not change legal formula in S2A.

## 5. Authz discovery — not proven for entitlement adjustments

### What exists

- No permission named like `izin.*` / `yillik_izin_hak.*`
- Leave **usage** is written as süreç under generic `surecler.*`
- `RolePermissions` matrix grants `surecler.create` / `personeller.update` to:
  - `GENEL_YONETICI`
  - `BOLUM_YONETICISI`
  - `IK_SORUMLUSU`
- `MUHASEBE` matrix is read-narrowed (S1): no `surecler.create`

### Drift (evidence only — not a product decision)

`SureclerController::assertCreateRole` still hardcodes:

```text
GENEL_YONETICI, BOLUM_YONETICISI, MUHASEBE
```

and does **not** include `IK_SORUMLUSU`, despite the S1 matrix. This is existing süreç-create drift; it must **not** be reused as silent proof that MUHASEBE owns leave entitlement adjustments.

### Canonical role contracts (S1)

- `IK_SORUMLUSU` = operational IK (personel/süreç ops, SGK prepare) — closest HR candidate, **but no explicit “entitlement adjustment” grant**
- `GENEL_YONETICI` / `BOLUM_YONETICISI` = management süreç writers
- `MUHASEBE` = external accountant read — **must not gain HR write by inference**
- `SISTEM_YONETICISI` = technical admin — **SYSTEM_ADMIN_BUSINESS_WRITE = NO**
- `PERSONEL` = zero business write

`ROLE_OWNER_PROVEN = NO`  
`ROLE_OWNER_DECISION_REQUIRED = YES`  
`APPROVAL_WORKFLOW_PROVEN = NO`  
`APPROVAL_DECISION_REQUIRED = YES`

## 6. Precision / negative balance

| Topic | Current | S2A implication |
| --- | --- | --- |
| LEAVE_ENTITLEMENT_PRECISION | integer days | keep integer; no half-day invent |
| NEGATIVE_BALANCE_CURRENT_BEHAVIOR | `Math.max(..., 0)` clamp on remaining | preserve unless product reopens |
| NEGATIVE_BALANCE_POLICY_DECISION_REQUIRED | **NO for ledger itself** if display clamp preserved | ledger may store signed days; UI remaining can stay clamped |

## 7. Target contract (proposed — not implemented)

```text
LEGAL_CALCULATED_ENTITLEMENT
+ SUM(approved/manual adjustments)
- CANONICAL_USED_ANNUAL_LEAVE
= EFFECTIVE_REMAINING_ENTITLEMENT
  (null if used leave unresolved due to calendar fail-closed)
```

Invariants:

- `DIRECT_REMAINING_OVERRIDE = NO`
- append-only ledger (no hard delete)
- opening / extra / correction / reversal supported as signed adjustments
- do not fake `YILLIK_IZIN` süreç rows to change entitlement
- Personel Kartı stays READ ONLY
- write surface = Süreç / personnel operational flow (reuse, no 4th edit surface)
- server-authoritative effective balance (do not bolt ledger onto FE-only motor)
- calendar fail-closed preserved (`kullanilan_gun = null` ⇒ remaining unresolved)

### Proposed table (name TBD to match repo style)

Conceptual columns:

- `id`
- `personel_id`
- `gun_delta` (signed int; zero forbidden)
- `kategori` (opening/devir, company_extra, correction, reversal — exact ENUM after decision)
- `aciklama` (required)
- `effective_date`
- `status` **only if approval workflow is chosen**
- `created_by` user/actor
- `created_at`
- `reverses_id` (nullable FK to same table)
- no production backfill

Migration tip would be **055** (052/053/054 immutable). **Not authored in this PR** until write owner is decided.

### Proposed permissions (names illustrative)

Only after owner decision:

- `yillik_izin_hak_duzeltme.manage` (create/reverse)
- optional `yillik_izin_hak_duzeltme.approve` **iff** approval is required

Do not reuse `personeller.update` / `surecler.create` blindly (salary/personel/süreç blast radius).

### UI wording preference

Prefer: **İzin Hak Düzeltmesi / Devir Kaydı**  
Avoid: **Kalan İzin = …**

`IZIN_ENGINE_FUTURE_ROLE` (proposed): keep `izin-hesap-motoru.ts` as pure legal + used utility / preview; production effective balance from server read model.

## 8. Decisions needed (blocking)

### D1 — Who may create entitlement adjustments?

**CURRENT_EVIDENCE**

- No dedicated izin-hak permission exists.
- Docs: leave totals are system-derived, not user-entered.
- S1: `IK_SORUMLUSU` is operational IK; `MUHASEBE` is mali read; system admin is not business writer.
- Süreç create gate currently drifts from the permission matrix.

**OPTIONS**

| Option | Create roles |
| --- | --- |
| A | `IK_SORUMLUSU` only |
| B | `IK_SORUMLUSU` + `GENEL_YONETICI` |
| C | `IK_SORUMLUSU` + `GENEL_YONETICI` + `BOLUM_YONETICISI` |
| D | Other explicit list (user-defined) |

**RECOMMENDED_DEFAULT:** **B** — IK operational owner + GY oversight; exclude MUHASEBE / SISTEM_YONETICISI / PERSONEL / BIRIM_AMIRI.

**IMPACT:** drives new permission matrix, API guards, UI visibility, tests.

### D2 — Is dual-control approval required?

**CURRENT_EVIDENCE:** No leave-entitlement approval workflow in code/docs. SGK dual-control is a different domain.

**OPTIONS**

| Option | Behavior |
| --- | --- |
| A | Single-step create → immediately effective |
| B | Create draft → approve by second role (e.g. GY) |
| C | Create by IK → auto-effective under threshold; approve above threshold |

**RECOMMENDED_DEFAULT:** **A** for v1 (simpler, auditable ledger + reversal). Add approval only if user requires dual control.

**IMPACT:** presence/absence of `status` + approve permission + UI states.

### D3 — Confirm negative remaining presentation stays clamped

Not blocking if we preserve current clamp. Confirm explicitly so S2B/S2A impl does not reopen display semantics.

## 9. Explicit non-goals (this phase)

- No production write / migration apply / merge / deploy
- No PR #142 edits
- No PERSONEL self-service / users↔personel binding / QR
- No fake yıllık izin süreçleri for balance
- No legal formula rewrite
- No remaining-balance overwrite field

## 10. Implementation gate

Proceed to migration 055 + BE/FE/authz/tests **only after** D1 (and D2 if dual-control chosen) are answered.

Until then:

`S2A_STATUS = BLOCKED_BUSINESS_DECISION`
