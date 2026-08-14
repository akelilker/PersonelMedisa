# 122 — Canonical HR Catalog Completion + Import Staging Catch-up (Pack6B)

**Tür:** Production ops evidence (docs sync).
**Tarih:** 2026-08-14
**Canonical main SHA (docs merge base):** `214e93f47c7ae1c6ee6c1eaac15586c9d3d5a88d` (PR #160 merged)
**Code baseline (unchanged):** `e92be2b957f0a3c2e91b5dae5dd703cde4cc1bb4`

**Scope:** Complete missing canonical Departman / Unvan(Görev) / Personel Tipi / Bölüm / Birim reference rows from the approved 122-person workbook. Rebuild private import staging. **No** personnel mutation. **No** import apply. **No** application/migration code change.

---

## Canonical source lock

| Item | Value |
| --- | --- |
| Workbook | `PersonelMedisa_Personel_Duzenlenmis_2026-08-12.xlsx` |
| SHA256 | `C449594165BF27F338D0D295D771CB54F5AA002EE86A2B8B989075498416806F` |
| CANONICAL_SOURCE_ROWS | **122** |
| Role | PRIMARY_SOURCE_OF_TRUTH |
| Older XLS/XLSX | SECONDARY_VALIDATION_ONLY |

### Distinct counts (recomputed)

| Taxonomy | Distinct |
| --- | --- |
| DEPARTMAN | 9 |
| BOLUM | 22 |
| BIRIM | 32 |
| UNVAN (→ gorevler) | 37 |
| POZISYON | 12 |
| PERSONEL_TIPI | 3 |

---

## Production backup (fresh)

| Item | Value |
| --- | --- |
| BACKUP_CREATED | **YES** (not reused from Pack6/`121`) |
| BACKUP_VERIFY | **PASS** |
| BACKUP_SIZE | `386164` bytes |
| BACKUP_SHA256 | `6FDAA501EE0FCC38EE009CE739B2D26D34C535CA5025BFB13D87F1A2B2D78785` |
| BACKUP_RETAINED_OUTSIDE_WEBROOT | **YES** |
| Basename | `karmotor_medisa_pre_pack6b_20260814-005548.sql` |

---

## Catalog seed results (references only)

| Domain | Canonical distinct | Existing match | Inserted | Canonical resolved | Unresolved |
| --- | --- | --- | --- | --- | --- |
| DEPARTMAN | 9 | 1 (normalized `Üretim`↔`Uretim`) | 8 | **9** | 0 |
| UNVAN / GOREV | 37 | 0 | 37 | **37** | 0 |
| PERSONEL_TIPI | 3 | 0 | 3 | **3** | 0 |
| POZISYON | 12 | 12 | 0 | **12** | 0 |
| BOLUM | 22 | 6 | 16 | **22** | 0 |
| BIRIM | 32 | 14 | 18 | **32** | 0 |

### Production row counts (after)

| Table | Before | After |
| --- | --- | --- |
| departmanlar | 2 | 10 (legacy `Depolama` retained) |
| gorevler | 2 | 39 (legacy Operator / Depo Sorumlusu retained) |
| personel_tipleri | 2 | 5 (legacy Tam Zamanli / Sozlesmeli retained) |
| pozisyonlar | 12 | 12 |
| bolumler | 6 | 22 |
| birimler | 14 | 32 |
| personeller | 4 | **4** |

Normalization used for matching only. New rows use workbook display names. Existing legacy display names not rewritten. No deletes.

### Hierarchy integrity

| Metric | Value |
| --- | --- |
| ORPHAN_BOLUM_COUNT | **0** |
| ORPHAN_BIRIM_COUNT | **0** |
| CANONICAL_HIERARCHY_CONFLICT_COUNT | **0** |

---

## Smoke personnel policy

| Item | Value |
| --- | --- |
| SMOKE_PERSONNEL_ORG_UPDATE | **SKIPPED_BY_POLICY** |
| Reason | Current 4 production rows are fixture/smoke — not the 122 HR population |
| PERSONEL_ORG_FIELDS_CHANGED | **NO** |
| PROTECT_HASH | unchanged (`7e1fd31f…1d3fb0`) |

---

## 122-row org reference coverage (staging; no import)

| Category | Exact rows |
| --- | --- |
| SGK_REFERENCE_EXACT_ROWS | **122** |
| SUBE_REFERENCE_EXACT_ROWS | **122** |
| LOCATION_REFERENCE_EXACT_ROWS | **122** |
| DEPARTMAN_REFERENCE_EXACT_ROWS | **122** |
| BOLUM_REFERENCE_EXACT_ROWS | **122** |
| BIRIM_REFERENCE_EXACT_ROWS | **122** |
| UNVAN_REFERENCE_EXACT_ROWS | **122** |
| POZISYON_REFERENCE_EXACT_ROWS | **122** |
| PERSONEL_TIPI_REFERENCE_EXACT_ROWS | **122** |
| ORG_REFERENCE_READINESS_PERCENT | **100** |

Unresolved distinct values: **none** (all org reference categories).

Branch mapping applied: `Medisa (Merkez Karabük)` → production `MRK` / Medisa.

---

## Identity / full import readiness (separate from org refs)

| Metric | Value |
| --- | --- |
| TC_READY_ROWS | 122 |
| SICIL_READY_ROWS | **0** (workbook has no `sicil_no` column) |
| AD_READY_ROWS | 122 |
| SOYAD_READY_ROWS | 122 |
| AMBIGUOUS_NAME_SPLIT_ROWS | **23** (recomputed; combined Ad Soyad only) |
| DOGUM_READY_ROWS | 107 |
| TELEFON_READY_ROWS | 87 |
| ISE_GIRIS_READY_ROWS | 122 |
| FULL_PERSONNEL_IMPORT_READY_ROWS | **0** |
| FULL_PERSONNEL_IMPORT_BLOCKED_ROWS | **122** |
| FULL_PERSONNEL_IMPORT_READINESS_PERCENT | **0** |

### Actual remaining data blockers (aggregate)

- `MISSING_SICIL` — 122
- `AMBIGUOUS_NAME_SPLIT` — 23
- `MISSING_OR_INVALID_DOGUM_TARIHI` — 15
- `MISSING_OR_INVALID_TELEFON` — 35

Historical secondary-source conflict `SOURCE_FILES_DIFFERENT_GENERATIONS` is **not** a current blocker for org taxonomy (canonical workbook resolves references).

Private staging retained outside repo under `Documents/medisa-ops-tmp/personel-import-122/` (may contain PII).

---

## Dry-run

| Item | Value |
| --- | --- |
| DRY_RUN | **BLOCKED_DATA_COMPLETION** |
| Reason | Cannot construct validator-safe CSV without inventing `sicil_no` (and other incomplete fields) |
| DRY_RUN_CAN_APPLY | **NO** |

No validator weakening.

---

## Live read smoke

| Check | Result |
| --- | --- |
| health | 200 |
| `/auth/smoke-read` (AUTH_SMOKE_READONLY) | 200 |
| personeller list | 200 |
| `/referans/departmanlar|bolumler|birimler|gorevler|pozisyonlar|personel-tipleri` | 200 |
| `/yonetim/subeler` | 200 |
| `/personeller/import/references.csv` | 200 — Pack6 types present (`BOLUM`/`BIRIM`/`POZISYON`/`SGK_ISVEREN`/`CALISMA_LOKASYONU`); new Unvan + Personel Tipi values present |
| Note | Import CSV Departman rows remain sube-junction scoped (legacy links); full departman catalog visible on `/referans/departmanlar` (10) |

---

## Mutation / code proof

| Item | Value |
| --- | --- |
| PERSONEL_ROW_COUNT_CHANGED | **NO** |
| PERSONEL_ORG_FIELDS_CHANGED | **NO** |
| PERSONNEL_IMPORT | **NO** |
| APPLICATION_CODE_CHANGED | **NO** |
| MIGRATION_FILE_CHANGED | **NO** |
| PRODUCTION_TIP | **065** (unchanged) |
| TEMP_OPS_FILES_REMOVED | **YES** |
| BACKUP_RETAINED_OUTSIDE_WEBROOT | **YES** |
| PRIVATE_STAGING_RETAINED | **YES** |

---

## Gap reclassification

| ID | Status after Pack6B |
| --- | --- |
| `MG-OPS-ORG-001` | **CLOSED_REFERENCE_ROLLOUT** — canonical 122-row org taxonomy fully resolves in production catalogs; personnel mapping/import remain separate |
| `MG-ORG-ATTR-ROLL-001` | **CLOSED_REFERENCE_ROLLOUT** — production reference catalogs complete for Pack6 taxonomy |
| `MG-ORG-LOC-001` | still **OPS_ROLLOUT** / `USER_GATED` (personel FK apply/import not done) |
| `MG-IMPORT-DATA-001` | **USER_GATED_DATA_COMPLETION** — real blockers: sicil + ambiguous name split + missing birth/phone subsets |
| `MG-OPS-PERSONEL-001` | **USER_GATED** |

---

## Recommended next user action

1. Complete source `sicil_no` (and optional secondary XLS crosswalk preview if needed) + resolve ambiguous Ad Soyad splits + fill missing birth/phone.
2. Only after identity completion: authorize dry-run CSV construction / then personnel import gate (`MG-OPS-PERSONEL-001`).
3. Do **not** treat the 4 smoke personnel as the 122-row rollout denominator.
