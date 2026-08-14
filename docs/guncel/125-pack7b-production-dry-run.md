# 125 — Pack7B Production Dry-Run Verification (Pack7C)

**Tür:** Production read/dry-run evidence (docs sync, non-PII aggregates only).
**Tarih:** 2026-08-14
**Deployed main SHA:** `2439ceed2cfd7e9a46f8b8e09400f2a2488b6c3b` (PR #163 merged)
**Main CI:** PASS ([run 31780779394](https://github.com/akelilker/PersonelMedisa/actions/runs/31780779394))
**Auto Deploy:** PASS ([run 31781206042](https://github.com/akelilker/PersonelMedisa/actions/runs/31781206042), Deploy cPanel #784)

**Scope:** Re-run the locked 122-row canonical personnel CSV against live `POST /personeller/import/dry-run` after Pack7B deploy. **No** personnel mutation. **No** import apply. **No** `sube_departmanlar` mutation. **No** migration. **No** application code change in this pack.

Historical pre-fix dry-run remains in `docs/guncel/123-personnel-import-data-readiness.md` (40 valid / 58 `PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI`). Pack7B contract is `docs/guncel/124-personnel-import-open-branch-department.md`.

---

## Input lock

| Item | Value |
| --- | --- |
| Canonical workbook SHA256 | `C449594165BF27F338D0D295D771CB54F5AA002EE86A2B8B989075498416806F` |
| Canonical denominator | **122** |
| Dry-run CSV SHA256 | `2e12407cb678bc3fe4e886ae7581bb76825fe7dfa6c18fd4c6fc59dd876d9764` |
| CSV vs Pack7 candidate | **EXACT MATCH** (no identity repair this phase) |

---

## Pack7B production acceptance

| Metric | Pre-fix (Pack7) | Post-deploy (Pack7C) |
| --- | --- | --- |
| `PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI` rows | **58** | **0** |
| DRY_RUN_VALID | **40** | **67** |
| DRY_RUN_INVALID | **82** | **55** |
| DRY_RUN_CAN_APPLY | false | **false** |
| personel_write | false | **false** |

RELATION_GATE_REGRESSION = **NO**  
PACK7B_PRODUCTION_ACCEPTANCE = **PASS**

All **67** identity-complete staging rows are now dry-run `GECERLI` (the previous 27 relation-only blockers are gone). Valid count equals the Pack7B theoretical ceiling. Remaining invalid rows are identity `PERSONEL_IMPORT_EKSIK_ALAN` only.

Live import reference export: **10** ACTIVE DEPARTMAN rows, all `bagli_sube = TUM_YETKILI_SUBELER`, `kullanilabilir = EVET`. No mapped-pair restriction. Runtime payload contains no `PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI`.

---

## Error distribution (122-row production dry-run)

| Code | Rows |
| --- | --- |
| PERSONEL_IMPORT_EKSIK_ALAN | **55** |

Codes **not** present: `PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI`, `PERSONEL_IMPORT_REFERANS_BULUNAMADI`, `PERSONEL_IMPORT_REFERANS_BELIRSIZ`, `PERSONEL_IMPORT_SUBE_SCOPE_IHLALI`, `PERSONEL_IMPORT_TC_MEVCUT`, `PERSONEL_IMPORT_SICIL_MEVCUT`.

---

## Remaining identity blockers (staging, unchanged)

One person may have multiple missing fields.

| Blocker | Rows |
| --- | --- |
| SICIL_BLOCKED_ROWS | **24** |
| NAME_BLOCKED_ROWS | **23** |
| DOGUM_BLOCKED_ROWS | **5** |
| TELEFON_BLOCKED_ROWS | **26** |
| UNIQUE_IDENTITY_BLOCKED_PERSONNEL | **55** |

| Metric | Value |
| --- | --- |
| FULL_PERSONNEL_IMPORT_READY_ROWS | **67** |
| FULL_PERSONNEL_IMPORT_BLOCKED_ROWS | **55** |
| FULL_PERSONNEL_IMPORT_READINESS_PERCENT | **54.92** |

Private user-resolution workbook was **not** rewritten: identity blocker counts/details did not change. Org-pair failures are no longer import blockers.

---

## Org reference readiness

Candidate resolution remains **122/122** for SGK, Şube, location, Departman, Bölüm, Birim, Unvan, Pozisyon, Personel tipi.

ORG_REFERENCE_READINESS_PERCENT = **100**

Production dry-run emitted **zero** reference-resolution error codes.

---

## Authorization / open-model safety

| Gate | Result |
| --- | --- |
| SUBE_SCOPE_STILL_ACTIVE | **YES** (import reference SUBE usable: GY **10** vs `BOLUM_YONETICISI` **2**) |
| AUTHORIZATION_OWNER | `personeller.sube_id` |
| SUBE_DEPARTMAN_AUTH_BOUNDARY | **NO** |
| `sube_departmanlar` pair count | **3** (unchanged; sparse matrix still present) |

Revizyon behavior was not modified in this phase.

---

## Production safety

| Gate | Result |
| --- | --- |
| PRODUCTION_PERSONEL_COUNT_BEFORE | **4** |
| PRODUCTION_PERSONEL_COUNT_AFTER | **4** |
| PRODUCTION_PERSONNEL_CHANGED | **NO** |
| PERSONNEL_IMPORT apply | **NO** |
| PRODUCTION_DB_WRITE | **NO** |
| SUBE_DEPARTMAN_ROW_COUNT_CHANGED | **NO** |
| PRODUCTION_TIP_CHANGED | **NO** |
| MIGRATION | **NONE** |

`DRY_RUN_CAN_APPLY = false`. Even if it were true, this phase does **not** authorize import apply.

---

## Recommended next user action

1. Complete remaining identity fields in the private resolution workbook (sicil 24, ambiguous names 23, doğum 5, telefon 26).
2. Re-run production dry-run after identity completion.
3. Personnel import apply remains a **separate explicit authorization** (`MG-OPS-PERSONEL-001`). Pack7C does not grant it.

**FINAL_STATUS:** **PASS** — Pack7B open branch↔department contract verified on production dry-run; import apply still not authorized.
