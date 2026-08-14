# 121 — Pack6 Production Rollout (065 + taxonomy + locked branches + mapping preview)

**Tür:** Production ops evidence (docs sync).
**Tarih:** 2026-08-14
**Canonical main SHA:** `e92be2b957f0a3c2e91b5dae5dd703cde4cc1bb4`

**Scope:** Migration `065` apply + verified Bölüm/Birim/Pozisyon reference seed + locked system branch model + MRK display rename + branch SGK ownership. Personnel org FK apply = **NOT** executed. Real import = **NO**.

---

## Evidence summary

| Item | Value |
| --- | --- |
| MAIN_HEAD | `e92be2b957f0a3c2e91b5dae5dd703cde4cc1bb4` |
| MAIN_CI | **PASS** — run `31753872106` |
| AUTO_DEPLOY | **PASS** — run `31754070858` (same SHA) |
| CODE_MIGRATION_TIP | **065** |
| PRODUCTION_TIP_BEFORE | **064** |
| PRODUCTION_TIP_AFTER | **065** |
| DB_VERSION | `10.6.21-MariaDB-cll-lve` |
| DB_NAME | `karmotor_medisa` |
| BACKUP_CREATED | **YES** (fresh; not reused) |
| BACKUP_VERIFY | **PASS** |
| BACKUP_SIZE | `457173` bytes |
| BACKUP_SHA256 | `25200b3b70ec24183404c601388c3c168e4a5f985ce1e35d3fc4779e4c59fbd7` |
| BACKUP_RETAINED_OUTSIDE_WEBROOT | **YES** |
| MIGRATION_065 | **PASS** |
| ORG_STRUCTURE_SCHEMA_READY | **TRUE** |
| APPLICATION_CODE_CHANGED | **NO** |
| SQL_MIGRATION_FILE_CHANGED | **NO** |
| PERSONNEL_ORG_APPLY | **NOT_AUTHORIZED** |
| PERSONNEL_IMPORT | **NO** |

Credentials / unredacted hosts / PII are **not** recorded here.

---

## Taxonomy seed (references only)

| Metric | Value |
| --- | --- |
| BOLUM_SOURCE_DISTINCT | 22 |
| BOLUM_SEEDED | 6 (EXACT vs existing production Departman after Turkish-fold match to `Uretim`) |
| BOLUM_TOTAL_PROD | 6 |
| BOLUM_BLOCKED | 16 (`UNRESOLVED_PARENT` — source Departman values absent from production `departmanlar` which only has `Uretim` / `Depolama`) |
| BIRIM_SOURCE_DISTINCT | 33 |
| BIRIM_SEEDED | 14 |
| BIRIM_TOTAL_PROD | 14 |
| BIRIM_BLOCKED | 19 |
| POZISYON_SOURCE_DISTINCT | 12 |
| POZISYON_SEEDED | 12 |
| POZISYON_TOTAL_PROD | 12 |
| POZISYON_BLOCKED | 0 |

No DELETE. Existing reference names not overwritten. Unique contracts preserved.

---

## Locked branch model

| Metric | Value |
| --- | --- |
| MRK_BEFORE | `MRK` / Merkez |
| MRK_AFTER | `MRK` / Medisa |
| MRK_CODE_CHANGED | **NO** |
| BRANCH_LOCKED_TARGET_COUNT | 10 |
| BRANCH_TARGET_PRESENT_COUNT | 10 |
| BRANCH_CREATED_COUNT | 8 |
| BRANCH_CONFLICT_COUNT | 0 |
| BRANCH_OWNER_MEDISA_COUNT | 5 |
| BRANCH_OWNER_KARYAPI_COUNT | 4 |
| BRANCH_OWNER_SENAY_COUNT | 1 |
| BRANCH_OWNER_UNRESOLVED_COUNT | 0 |
| AUTHORIZATION_OWNER | `personeller.sube_id` |
| SUBE_SCOPE_CHANGED | **NO** |

Created codes: `MDS-KYS`, `MDS-ANK`, `MDS-IST`, `KRP`, `KRP-ANK`, `KRP-KYS`, `KRP-IST`, `SNY`. Existing `GRS` retained.

---

## Personnel mapping preview (read-only)

Production population for this gate: **4** personnel (fixture/smoke set). None match private HR TC/sicil identity join (`tc_source_hit=0`).

| Metric | Value |
| --- | --- |
| PERSONEL_TOTAL | 4 |
| SGK_EXACT_COUNT | 4 |
| SGK_AMBIGUOUS_COUNT | 0 |
| SGK_UNMAPPED_COUNT | 0 |
| SGK_EXISTING_CONFLICT_COUNT | 0 |
| SGK_COVERAGE_PERCENT | 100 (via branch owner; no personnel write) |
| PERSONEL_KODU_PROD_MATCH_COUNT | 0 |
| PERSONEL_KODU_PROD_CONFLICT_COUNT | 0 |
| PERSONEL_KODU_SICIL_DECISION | **INSUFFICIENT_OVERLAP** (do not lock yet against current production set) |
| LOCATION_* / BOLUM_* / BIRIM_* / POZISYON_* exact | 0 (identity unresolved) |
| SAFE_SGK_UPDATE_COUNT | 4 |
| SAFE_LOCATION_UPDATE_COUNT | 0 |
| SAFE_BOLUM_UPDATE_COUNT | 0 |
| SAFE_BIRIM_UPDATE_COUNT | 0 |
| SAFE_POZISYON_UPDATE_COUNT | 0 |
| FULL_ORG_EXACT_PERSON_COUNT | 0 |
| ANY_ORG_AMBIGUITY_COUNT | 4 |
| PERSONEL_*_CHANGED_COUNT | **0** (protect-hash unchanged) |

Private apply-candidate pack retained outside repo (exact SGK proposals only for current 4 rows).

### Import readiness (recomputed; no import)

| Metric | Value |
| --- | --- |
| SOURCE_POPULATION_XLSX | 136 |
| SOURCE_POPULATION_XLS | 142 |
| PRODUCTION_PERSONEL_TOTAL | 4 |
| IDENTITY_EXACT_ROWS (prod↔source) | 0 |
| FULL_IMPORT_READY_ROWS | 0 |
| IMPORT_BLOCKED_ROWS | 4 (current prod set) / source still data-gated |
| IMPORT_READINESS_PERCENT | 0 (prod join) — source remains `USER_GATED` |
| AMBIGUOUS_NAME_SPLIT_ROWS | 29 |
| SGK_DIGER_SOURCE_ROWS | 13 |
| SGK_DIGER_RESOLVED_BY_AUTHORITATIVE_MAPPING | 0 on current prod population (no identity join); branch owner is authoritative once personel↔branch is known |
| DEPARTMAN_CONFLICT_ROOT_CAUSE | **SOURCE_FILES_DIFFERENT_GENERATIONS** (private XLS↔XLSX TC-join: agree 0 / conflict 50; production Departman catalog also far smaller than source taxonomy) |
| DEPARTMAN_CONFLICT_COUNT | 50 (private source-to-source; not applied) |

---

## Live read smoke

| Check | Result |
| --- | --- |
| health | 200 |
| auth smoke-read | 200 |
| personeller list | 200 |
| personeller detail | 200 |
| `/referans/departmanlar|bolumler|birimler|gorevler|pozisyonlar|personel-tipleri` | 200 |
| `/yonetim/subeler` | 200 |
| `/personeller/import/references.csv` Pack6 types | **PASS** — contains `BOLUM`, `BIRIM`, `POZISYON`, `SGK_ISVEREN`, `CALISMA_LOKASYONU` |
| RETENTION_FEATURE_ENABLED | **NO** |
| OPEN_DESTROY_GATE_COUNT | 0 |
| REAL_DESTRUCTION | **NO** |
| TEMP_OPS_FILES_REMOVED | **YES** |

---

## Gap status (do not over-close)

| ID | Status after this rollout |
| --- | --- |
| `MG-ORG-ATTR-ROLL-001` | **OPS_ROLLOUT** — production schema ready (`065`); personnel FK apply still `USER_GATED` |
| `MG-OPS-ORG-001` | **PARTIAL→ADVANCED** — locked 10-branch model + ownership complete; taxonomy partial (Departman catalog gap); personnel mapping still gated |
| `MG-ORG-LOC-001` | remains **OPS_ROLLOUT** / `USER_GATED` until personnel FK apply |
| `MG-IMPORT-DATA-001` | remains **USER_GATED** (source completion + identity overlap) |
| `MG-OPS-PERSONEL-001` | remains **USER_GATED** |

---

## Recommended next user action

**“4 personelin doğrulanmış `sgk_isveren_id` alanını (branch owner EXACT) production’a yazalım mı?”**

Location / Bölüm / Birim / Pozisyon exact apply is **not** ready for the current production personnel set (no TC/sicil overlap with private HR sources). Broader import remains blocked until identity overlap and Departman catalog completion.
