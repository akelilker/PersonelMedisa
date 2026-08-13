# 117 — Final Code Gap Pack5 Closure

**Tür:** Code closure evidence (Pack5 revised).
**Branch:** `fix/final-code-gap-pack5`
**BASELINE_MAIN:** `ebc4e183c532992e5b07cdc09045ba9f950371af`
**Production migration apply:** **NO**
**Real org seed:** **NO**
**Real personnel import:** **NO**
**Backfill / retention enable / real destruction:** **NO**

## PACK5_RESULT

| Item | Result |
| --- | --- |
| YEAR_POLICY | **ROLLING_12_MONTH_ACTUAL_DATE_V1** |
| ISO_WEEK_YEAR_AS_270H_OWNER | **NO** |
| WHOLE_CROSS_YEAR_WEEK_ASSIGNMENT | **NO** |
| ACTUAL_DATE_PROVENANCE | **YES** (migration `063`, new snapshots) |
| ROLLING_12_MONTH_HARD_GUARD | **YES** |
| LEGAL_CHARACTER | **CONSERVATIVE_COMPANY_COMPLIANCE_POLICY** |
| Org location schema (`064`) | **Code complete** |
| Production 063/064 applied | **NO** |
| Real org seed | **NO** |

## Track A — Year-crossing OT

- Owner: `FazlaCalismaYillikLimitService`
- Weekly FM amount: existing weekly motor unchanged
- Provenance: chronological daily walk after 2700 net minutes; excess minutes only on actual dates
- Persist (when `063` ready): `fazla_calisma_tarih_dagilimi_json` + `fazla_calisma_tarih_dagilim_policy`
- Hard guard: rolling 12 months (16200 dk); personel lock sentinel `yil=0`
- Legacy (pre-063 / missing JSON): conservative week-overlap inclusion; **no** invented daily split
- Snapshot `yil` / `hafta_no`: ISO week **identity/display only** — not 270h compliance source
- Annual report aggregate tagged `ISO_WEEK_YEAR_DISPLAY` + `compliance_owner=ROLLING_12_MONTH_NOT_THIS_AGGREGATE`

## Track B — Org location

- Tables: `sgk_isverenler`, `calisma_lokasyonlari`
- `personeller.sgk_isveren_id`, `personeller.calisma_lokasyonu_id` (nullable)
- `subeler` remains SYSTEM BRANCH owner; `sube_id` preserved; SubeScope unchanged
- Pre-064: explicit new-field write → `409 ORG_LOCATION_SCHEMA_NOT_READY` (no mutation)
- Import: optional `sgk_isveren` / `calisma_lokasyonu`; blank → NULL; exact unique resolve; row_hash includes resolved IDs
- NO auto inference / backfill / real seed

## Gap reclassification

| ID | Was | Now | Metadata |
| --- | --- | --- | --- |
| `MG-OT-YEAR-POL-001` | BUSINESS_DECISION_REQUIRED | **CLOSED** | `ROLLING_12_MONTH_ACTUAL_DATE_V1` |
| `MG-OT-YEAR-PATH-001` | CODE_GAP | **CLOSED** | single rolling owner |
| `MG-ORG-LOC-001` | CODE_GAP | **OPS_ROLLOUT** | `USER_GATED` (prod `064` unapplied; real org seed not performed) |

`CODE_GAP_COUNT = 0` (product code gaps for Pack5 targets).

Still open (not Pack5 claims):
- `MG-OPS-ORG-001` OPS_ROLLOUT USER_GATED (real org seed)
- `MG-IMPORT-MAP-001` BUSINESS_DECISION_REQUIRED
- `MG-ORG-ATTR-001` BUSINESS_DECISION_REQUIRED

## Migration tips

| Tip | Value |
| --- | --- |
| Code migration tip | **064** |
| Production migration tip | **058** |
| Production applied | **NO** |
| New migrations | `063_fazla_calisma_actual_date_provenance.sql`, `064_personel_org_location_model.sql` |

## Non-claims

This document does **not** claim production schema apply, real org seed, personnel import rollout, retention enable, or merge/deploy.
