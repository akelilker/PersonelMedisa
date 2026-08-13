# 116 — Serbest Zaman Pack 4B Closure

**Tür:** Code closure evidence (historical checkpoint for Pack 4B).
**Branch:** `fix/serbest-zaman-pack4b-closure`
**Production migration apply:** **NO**
**Retention feature enable:** **NO**
**Real physical destruction:** **NO**

## PACK4B_RESULT

| Item | Result |
| --- | --- |
| Allocation-aware SERBEST physical destroy | **Completed (code)** |
| Legacy no-auto-backfill fail-closed | **Preserved** |
| Cross-scope allocation fail-closed | **`SERBEST_ZAMAN_CROSS_SCOPE_ALLOCATION_REMAINS`** |
| Migration `062` retention-gated allocation DELETE | **Code present** |
| 061 history / UPDATE hard-block | **Unchanged** |
| 6M deadline operational/report surface | **Completed (code)** |
| Warning window | **30 days** (operational only; not legal boundary) |
| Compliance mode | **WARNING_AND_OPERATIONAL_FOLLOWUP** |
| Payroll hard block | **NO** |
| Production migration apply | **NO** |
| Feature flag default | **OFF** |
| Real destruction executed | **NO** |

## Track A — SERBEST allocation-aware destroy

- Owner: `SerbestZamanDestructionHandler` + `SerbestZamanAllocationService` helpers
- Gate: `RetentionPhysicalDestroyGate::gatedCategories()` includes `SERBEST_ZAMAN`
- DDL: `062_serbest_zaman_retention_destroy_gate.sql` replaces hard DELETE with PREPARED+SERBEST_ZAMAN gated DELETE; UPDATE remains always blocked
- States: `LEGACY_UNALLOCATED` / `INVARIANT_BROKEN` → fail-closed; `ZERO` cancelled legacy does not blanket-block; `ALLOCATED` uses provenance; cross-scope → no mutation
- Plan determinism: optional `scope_fingerprint` (SHA-256) in plan hash for SERBEST only
- Execute: personel `FOR UPDATE` lock → re-resolve scope → fingerprint/count check → allocation DELETE → aktif → leaf-first events; shared haftalık/FM rows preserved

## Track B — 6 month ops/compliance

- Owner: `SerbestZamanDeadlineService`
- API: `GET /serbest-zaman/deadline-takip` (`raporlar.view` + `SubeScope`)
- UI: Raporlar → `serbest-zaman-takip` (`SerbestZamanTakipPage`)
- Expiry boundary: `referans_tarih <= son_kullanim_tarihi` → ACTIVE; `>` → EXPIRED
- LEGACY/INVARIANT → `ALLOCATION_UNRESOLVED` (no invented usable/expired minutes)
- No new `PayrollComplianceGuard` 6M hard blocker

## Gap reclassification

| ID | Was | Now | Metadata |
| --- | --- | --- | --- |
| `MG-RET-PHYS-001` | CODE_GAP | **OPS_ROLLOUT** | `USER_GATED` (prod apply + feature enable) |
| `MG-SZ-6M-001` | CODE_GAP | **OPS_ROLLOUT** | `USER_GATED` (schema rollout / ops follow-up) |

Remaining CODE_GAP (2): `MG-OT-YEAR-PATH-001`, `MG-ORG-LOC-001`.

## Migration tips

| Tip | Value |
| --- | --- |
| Code migration tip | **062** |
| Production migration tip | **058** |
| Production applied | **NO** |

## Non-claims

This document does **not** claim production-complete, live-enabled retention destruction, or production schema apply.
