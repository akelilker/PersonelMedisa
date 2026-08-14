# 123 — Personnel Import Data Readiness (Pack7 Identity Enrichment + Dry-Run)

**Tür:** Private-data preparation evidence (docs sync, non-PII aggregates only).
**Tarih:** 2026-08-14
**Canonical main SHA:** `bf9de569c3d204a2d14d5ba3e07f70b398a6a69a`
**Main CI:** PASS ([run 31760746779](https://github.com/akelilker/PersonelMedisa/actions/runs/31760746779))
**Auto Deploy:** PASS ([run 31760938426](https://github.com/akelilker/PersonelMedisa/actions/runs/31760938426))
**Production tip:** `065`

**Scope:** Recover identity fields for the locked 122-row canonical workbook from verified secondary HR sources via **exact TC join only**. Build private enriched staging. Execute **real** `PersonelImportDryRunService` dry-run against production (read-only). **No** personnel mutation. **No** import apply. **No** application/migration code change.

---

## Canonical source lock (unchanged)

| Item | Value |
| --- | --- |
| Workbook | `PersonelMedisa_Personel_Duzenlenmis_2026-08-12.xlsx` |
| SHA256 | `C449594165BF27F338D0D295D771CB54F5AA002EE86A2B8B989075498416806F` |
| CANONICAL_SOURCE_ROWS | **122** |
| Role | PRIMARY_SOURCE_OF_TRUTH (immutable) |

Secondary sources used as evidence only (hashes verified):

| Source | SHA256 | Role |
| --- | --- | --- |
| `Personel Listesi.xls` | `50142B64A2CFD982196E6AA25DBF13612B3453CFC783348E0D44659B126027B0` | Personel Kodu → sicil, doğum, GSM/Tel |
| `Medisa Personel Veri Listesi.xlsx` | `490FE38469D499CBCD351E19FD8D33B90135E38ED23560324AC51AA1ABECAEF7` | Doğum, cep, Medisa Format GSM |
| `2026 personel savaş onaylı.xlsx` | `91EB68C0895C8D37EBB73E1D93A1C13914AC701229AEAAF25A6C62673249FB9F` | Name corroboration only |

Join rule: exact normalized 11-digit TC; no fuzzy name join.

---

## Pack6B baseline → Pack7 after

| Metric | Pack6B | Pack7 |
| --- | --- | --- |
| ORG_REFERENCE_READINESS | 100% (122/122) | **100% (122/122)** — no regression |
| TC_READY_ROWS | 122 | **122** |
| SICIL_READY_ROWS | 0 | **98** |
| AD_READY_ROWS | 122\* | **99** (\*23 ambiguous not authoritative) |
| SOYAD_READY_ROWS | 122\* | **99** |
| DOGUM_READY_ROWS | 107 | **117** |
| TELEFON_READY_ROWS | 87 | **96** |
| ISE_GIRIS_READY_ROWS | 122 | **122** |
| FULL_IMPORT_READY_ROWS | 0 | **67** |
| FULL_IMPORT_READINESS_% | 0 | **54.92** |

\*Pack6B counted heuristic Ad/Soyad for multi-token names; Pack7 refuses those 23 until a separate Ad/Soyad source exists.

---

## Sicil recovery

Old XLS `Personel Kodu` re-verified: nonblank **142**, unique **142**, duplicates **0**.

| Class | Count |
| --- | --- |
| SICIL_EXACT | **98** |
| SICIL_TC_NOT_FOUND | **24** |
| SICIL_MULTIPLE_SOURCE_MATCH | 0 |
| SICIL_BLANK | 0 |
| SICIL_DUPLICATE_PROPOSED | 0 |
| SICIL_CONFLICT_OTHER_SOURCE | 0 |

| Decision | Value |
| --- | --- |
| PERSONEL_KODU_TO_SICIL | **PARTIAL_VERIFIED** (98/122 exact 1:1; 24 canonical TC absent from old XLS) |
| DUPLICATE_SICIL_COUNT (among 122) | **0** |
| DUPLICATE_TC_COUNT | **0** |

---

## Name / doğum / telefon

| Field | Before blocked | Recovered | Conflict | Unresolved after |
| --- | --- | --- | --- | --- |
| Ambiguous Ad/Soyad | 23 | 0 exact (no separate Ad/Soyad column in secondary sources) | 0 | **23** |
| Doğum tarihi | 15 | **10** | 0 | **5** |
| Telefon | 35 | **9** | 0 | **26** |

`SEPARATE_AD_SOYAD_SOURCE_FOUND = NO` — heuristic last-token surname **not** accepted for the 23 ambiguous rows.

---

## Real dry-run (production API, write=false)

Endpoint: `POST /personeller/import/dry-run` via existing `PersonelImportDryRunService`.

CSV reference cells use **production catalog exact `ad` by Pack6B resolved IDs** (workbook aliases such as `Medisa Giresun` / `Medisa (Merkez Karabük)` / `Üretim` are not exact catalog names).

| Metric | Value |
| --- | --- |
| DRY_RUN_TOTAL | **122** |
| DRY_RUN_VALID | **40** |
| DRY_RUN_INVALID | **82** |
| DRY_RUN_CAN_APPLY | **false** |
| personel_write | **false** |
| salary_write | **false** |

### Top invalid reason codes

| Code | Count |
| --- | --- |
| PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI | 58 |
| PERSONEL_IMPORT_EKSIK_ALAN | 55 |

Of the 67 identity+org “full ready” staging rows: **40** dry-run valid; **27** blocked only by `PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI` (şube↔departman link gate).

---

## Production safety

| Gate | Result |
| --- | --- |
| PRODUCTION_PERSONNEL_CHANGED | **NO** (count remained 4 smoke/fixture rows) |
| PERSONNEL_IMPORT apply | **NO** |
| Smoke personnel used as identity targets | **NO** |

Private staging (outside repo): `Documents/medisa-ops-tmp/personel-import-122/`  
(`personel-import-122-enriched.xlsx`, `personel-import-122-dryrun.csv`, `identity-resolution-report.json`, `personel-import-122-user-resolution.xlsx`)

---

## Recommended next user action

1. Complete `personel-import-122-user-resolution.xlsx` for remaining sicil (24), ambiguous names (23), doğum (5), telefon (26).
2. Investigate / authorize şube↔departman link repairs for the 27 otherwise-complete rows failing `PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI` (catalog/link work — not personnel write).
3. Re-run dry-run after user resolution; only then consider import apply authorization.

**FINAL_STATUS:** **PARTIAL** — material identity recovery + real dry-run executed; full import not yet apply-ready.
