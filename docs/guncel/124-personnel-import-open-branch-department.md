# 124 — Personnel Import Open Branch↔Department Contract (Pack7B)

**Tür:** Import contract fix (no production personnel mutation).
**Tarih:** 2026-08-14
**Base main SHA:** `fec987b2672d07f1b6c74477548e881b6c9a1524` (PR #162 merged)

**Scope:** Align personnel import dry-run / apply / reference export with the already-open manual Personel CREATE/UPDATE model. **No** database migration. **No** `sube_departmanlar` data change. **No** personnel import apply in production.

Historical pre-fix dry-run evidence remains in `docs/guncel/123-personnel-import-data-readiness.md` (40 valid / 58 `PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI`). This document does not rewrite that result.

---

## Pack7A root cause

`sube_departmanlar` was a sparse smoke-era matrix (3 pairs). Canonical personnel use 18 distinct Şube↔Departman combinations.

Import treated:

- matrix empty → OPEN
- matrix non-empty → STRICT

Those 3 leftover rows put import into strict mode. Manual CREATE/UPDATE already allowed any ACTIVE Şube + any ACTIVE Departman. That create/import asymmetry was a bug.

---

## Locked decision: OPEN_BRANCH_DEPARTMENT

MEDISA personnel model:

- Şube and Departman are independent personnel attributes.
- Any ACTIVE Şube + any ACTIVE Departman is a valid personnel combination.
- No prior `sube_departmanlar` row is required to create or import personnel.
- Şube↔Departman has **no** title / unvan / pozisyon requirement.
- `personeller.sube_id` remains the personnel authorization owner.
- `sube_departmanlar` is **not** an authorization boundary for personnel import.

After Pack7B:

| Path | Contract |
| --- | --- |
| Manual CREATE | ACTIVE Şube + ACTIVE Departman → allowed |
| Manual UPDATE | ACTIVE Şube + ACTIVE Departman → allowed |
| Import dry-run | same |
| Import apply | same analyze contract |
| Import reference export | same open model (`bagli_sube = TUM_YETKILI_SUBELER`) |

`PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI` is removed from runtime. Branch scope, active FK resolution, identity, duplicate, SGK/location/org-structure, and manifest/hash behavior are unchanged.

---

## Matrix retained for other owners

`sube_departmanlar` is **not** deleted and is **not** seeded to match canonical pairs.

It remains in use for:

1. Yönetim → Şube configuration / `departman_ids`
2. `YonetimController::replaceSubeDepartmanlar`
3. Revizyon `BOLUM_YONETICISI` department filtering

Pack7B does **not** redesign Revizyon scope, payroll scope, puantaj scope, SGK scope, retention scope, `user_subeler`, or `SubeScope`.

---

## Expected post-deploy dry-run (model only)

Using the known Pack7 dataset, relation-only blockers should fall to **0**. Identity-complete rows previously blocked only by the pair gate (27) become theoretically valid, so the valid ceiling is **67**.

The real 122-row production dry-run count is accepted only after merge + deploy. This PR does not claim a production result.

---

## Production safety (coding phase)

| Gate | Result |
| --- | --- |
| PRODUCTION_DB_CHANGED | **NO** |
| PRODUCTION_PERSONNEL_CHANGED | **NO** |
| PERSONNEL_IMPORT | **NO** |
| PRODUCTION_SUBE_DEPARTMAN_CHANGED | **NO** |
| MIGRATION | **NONE** |
| TITLE_REQUIREMENT | **NONE** |
