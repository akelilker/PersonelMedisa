# 126 — Pack7D Residual Personnel Identity Recovery

**Tür:** Private identity recovery + production dry-run evidence (docs sync, non-PII aggregates only).
**Tarih:** 2026-08-14
**Main SHA:** `a501bb6cbc280ac06a3375369ea7eb86044dfe42`
**Main CI:** PASS ([run 31783676214](https://github.com/akelilker/PersonelMedisa/actions/runs/31783676214))
**Auto Deploy:** PASS ([run 31783925062](https://github.com/akelilker/PersonelMedisa/actions/runs/31783925062), Deploy cPanel #785)

**Scope:** Exhaustive exact-TC join against local private HR files to recover remaining sicil / separate Ad-Soyad / doğum tarihi / telefon gaps. Rebuild private Pack7D candidate. Production `POST /personeller/import/dry-run` of all 122 rows **only** because readiness improved. **No** personnel mutation. **No** import apply. **No** reference mutation. **No** application code change.

Canonical workbook SHA256 remains `C449594165BF27F338D0D295D771CB54F5AA002EE86A2B8B989075498416806F` (not overwritten). Pack7 candidate CSV/xlsx left intact; Pack7D is a new version.

Join rule: 11-digit TC, exactly one canonical row, exactly one secondary row per source. No fuzzy-name join. No last-token surname heuristic.

---

## Recovery result

| Metric | Before (Pack7C) | After (Pack7D) |
| --- | --- | --- |
| FULL_READY | **67** | **76** |
| UNIQUE_BLOCKED | **55** | **46** |
| READINESS_PERCENT | 54.92 | **62.30** |
| SICIL blocked | 24 | **24** |
| NAME blocked | 23 | **17** |
| DOGUM blocked | 5 | **5** |
| TELEFON blocked | 26 | **20** |

| Field | Before | Exact recovered | Conflict | Not found | After |
| --- | --- | --- | --- | --- | --- |
| SICIL | 24 | **0** | 0 | 24 | **24** |
| AD/SOYAD | 23 | **6** | 0 | 17 | **17** |
| DOGUM_TARIHI | 5 | **0** | 0 | 5 | **5** |
| TELEFON | 26 | **6** | 0 | 20 | **20** |

NEWLY_COMPLETE_PERSONNEL = **9**

Name recoveries used exact TC → separate `Adı`/`Soyadı` columns on the Unicode employee list. Same-TC `.xls` codepage damage (missing dotted/dotless i) was not treated as a genuine identity disagreement when the `.xlsx` source agreed with itself. Recorded reason: `EXACT_TC_PREFER_XLSX_UNICODE_OVER_XLS_CODEPAGE`.

Phone recoveries accepted only values that normalize to Turkish mobile `05xxxxxxxxx` (import validator still only requires non-blank; Pack7D did not weaken that, and did not accept mangled `(0xx) xxx-xxxx` fragments).

Sicil: the 24 remaining TCs are absent from every discovered `Personel Kodu` / `Sicil` column. `Belge No` / `Sıra No` were not used.

Doğum tarihi: the 5 remaining TCs have no valid calendar date in any exact-TC source column labeled birth date.

---

## Local validator (current main contract)

`PersonelCanonicalValidator::validateImportAnaVeriRow` on the rebuilt 122-row candidate. Validation was not weakened.

| Metric | Value |
| --- | --- |
| LOCAL_TOTAL | **122** |
| LOCAL_VALID | **76** |
| LOCAL_INVALID | **46** |

Remaining required-field blockers (field occurrences; one person may miss several):

| Field | Rows |
| --- | --- |
| `sicil_no` | **24** |
| `telefon` | **20** |
| `ad` | **17** |
| `soyad` | **17** |
| `dogum_tarihi` | **5** |

Only error family: `PERSONEL_IMPORT_EKSIK_ALAN`. Org/reference IDs remain 122/122.

---

## Production dry-run

Executed because 9 of the previously 55 blocked personnel became newly complete. DRY-RUN ONLY. NO APPLY.

Pack7D CSV SHA256 = `f45f1749f7529bbd71efd963dbb3b8542f1f0c11fa6e53fdc0c596ed333a6339` (matches production `source_sha256`).

| Metric | Pack7C | Pack7D |
| --- | --- | --- |
| DRY_RUN_VALID | 67 | **76** |
| DRY_RUN_INVALID | 55 | **46** |
| DRY_RUN_CAN_APPLY | false | **false** |
| `PERSONEL_IMPORT_EKSIK_ALAN` rows | 55 | **46** |
| `yazma.personel_write` | false | **false** |

Codes **not** present: `PERSONEL_IMPORT_SUBE_DEPARTMAN_ILISKISI`, `PERSONEL_IMPORT_REFERANS_BULUNAMADI`, `PERSONEL_IMPORT_REFERANS_BELIRSIZ`, `PERSONEL_IMPORT_SUBE_SCOPE_IHLALI`, `PERSONEL_IMPORT_TC_MEVCUT`, `PERSONEL_IMPORT_SICIL_MEVCUT`.

---

## Production safety

| Gate | Result |
| --- | --- |
| PRODUCTION_PERSONEL_COUNT_BEFORE | **4** |
| PRODUCTION_PERSONEL_COUNT_AFTER | **4** |
| PRODUCTION_PERSONNEL_CHANGED | **NO** |
| PERSONNEL_IMPORT apply | **NO** |
| PRODUCTION_DB_WRITE | **NO** |
| MIGRATION | **NONE** |

---

## Private artifacts (not in git)

Workspace: `Documents/medisa-ops-tmp/personel-import-122/`

- `personel-import-122-enriched-pack7d.xlsx`
- `personel-import-122-dryrun-pack7d.csv`
- `identity-resolution-report-pack7d.json`
- `personel-import-122-user-resolution.xlsx` — **46** people, one row per person, masked TC (last 4), `Çözüm` + `Açıklama`

User workbook sort: 32 rows with 1 missing field, then 9 / 4 / 1 with 2 / 3 / 4 missing fields.

---

## Recommended next user action

1. Fill the private `personel-import-122-user-resolution.xlsx` (46 people only). Start at the top (single-field rows).
2. Remaining gaps that local files cannot supply: sicil **24**, separate ad/soyad **17**, telefon **20**, doğum tarihi **5**.
3. After İlker entry, rebuild candidate and re-run production dry-run. Import apply remains a **separate explicit authorization**.

**FINAL_STATUS:** **PARTIAL** — 9 additional personnel are now dry-run valid (67→76). 46 identity-blocked rows remain. Apply is not authorized.
