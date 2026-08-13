# 119 — Org Reference Rollout (SGK employer + verified work locations)

**Tür:** Production reference-seed evidence (docs sync).
**Tarih:** 2026-08-13
**Canonical main SHA:** `d88fa7650a92b23dcbf6739b24fa0a3f5d8e9a4b`

**Scope:** Real SGK employer reference seed + VERIFIED work-location reference seed + read-only discovery / mapping **preview**. No personnel FK writes. No branch reassignment. No personel import. No application/SQL migration code change.

---

## Evidence summary

| Item | Value |
| --- | --- |
| MAIN_HEAD | `d88fa7650a92b23dcbf6739b24fa0a3f5d8e9a4b` |
| MAIN_CI | **PASS** — run `31743103972` |
| AUTO_DEPLOY | **PASS** — run `31743338870` |
| CODE_MIGRATION_TIP | **064** |
| PRODUCTION_MIGRATION_TIP | **064** |
| PROD_ORG_PREFLIGHT | **PASS** |
| ORG_BACKUP_CREATED | **YES** |
| ORG_BACKUP_VERIFY | **PASS** |
| ORG_BACKUP_SIZE | `455591` bytes |
| ORG_BACKUP_SHA256 | `4bcb807c83a0a5bc5858c265dbabea3049568a659f75977c9e8ea0fc9d0fb3ed` |
| LIVE_READ_SMOKE | **PASS** |

Fresh backup retained outside public webroot (ops path; not committed). Credentials / unredacted hosts / PII are **not** recorded here.

---

## Reference seed results

### SGK employers (`sgk_isverenler`)

| Item | Value |
| --- | --- |
| SGK_EMPLOYER_SEED | **APPLIED** |
| SGK_EMPLOYER_ROW_COUNT | **3** |
| SGK_EMPLOYER_CODES | `MEDISA`, `KARYAPI`, `SENAY_MOBILYA` |
| Display labels | Medisa / Karyapı / Şenay Mobilya (internal canonical labels) |
| Durum | all **AKTIF** |
| SGK_EMPLOYER_CONFLICTS | **0** |
| SGK_EMPLOYER_REAL_REFERENCE | **PRODUCTION_READY** |

### Work locations (`calisma_lokasyonlari`)

| Item | Value |
| --- | --- |
| WORK_LOCATION_SOURCE_FOUND | **YES** — private HR workbook `Medisa Personel Veri Listesi.xlsx` sheet `Personel Veri Bankası`, column **Lokasyon** (explicit) |
| WORK_LOCATION_VERIFIED_COUNT | **7** |
| WORK_LOCATION_AMBIGUOUS_COUNT | **0** |
| WORK_LOCATION_INFERRED_COUNT | **0** |
| WORK_LOCATION_SEED | **APPLIED** (verified only) |
| WORK_LOCATION_ROW_COUNT | **7** |
| Codes | `ANKARA`, `GIRESUN`, `ISTANBUL`, `IZMIR`, `KARABUK`, `KAYSERI`, `SAKARYA` |
| WORK_LOCATION_REAL_REFERENCE | **PRODUCTION_READY** (for verified inventory) |

**Not inferred from `subeler`.** Sakarya is a work-location reference only — not an SGK employer and not a system branch.

---

## Production branch inventory (non-PII)

| id | kod | ad | company map class |
| --- | --- | --- | --- |
| 1 | MRK | Merkez | **AMBIGUOUS** (bare Merkez — Medisa Merkez Karabük vs Karyapı Merkez Konya) |
| 2 | GRS | Giresun | **EXACT → MEDISA** |

Locked full branch set is **not** fully present in production (Medisa/Karyapı multi-city branches missing). Company discriminator is not on schema; shared city names cannot be invented.

`AMBIGUOUS_BRANCH_COMPANY_COUNT = 1` (branch id 1).

---

## Personnel mapping preview (NO WRITE)

| Metric | Value |
| --- | --- |
| PERSONNEL_ORG_MAPPING_APPLY | **NOT_AUTHORIZED** |
| PERSONEL_SUBE_CHANGED_COUNT | **0** |
| PERSONEL_SGK_ISVEREN_CHANGED_COUNT | **0** |
| PERSONEL_LOCATION_CHANGED_COUNT | **0** |
| SGK_MAPPING_EXACT_COUNT | **3** |
| SGK_MAPPING_AMBIGUOUS_COUNT | **1** |
| SGK_MAPPING_UNMAPPED_COUNT | **0** |
| SGK_MAPPING_COVERAGE_PERCENT | **75** |
| LOCATION_MAPPING_EXACT_COUNT | **0** |
| LOCATION_MAPPING_AMBIGUOUS_COUNT | **0** |
| LOCATION_MAPPING_MISSING_COUNT | **4** |
| LOCATION_MAPPING_COVERAGE_PERCENT | **0** |

Location preview: production personnel rows did not join to the private HR Lokasyon source by identity; missing source — **not** filled from system branch.

Invariant preserved: `AUTHORIZATION_OWNER = personeller.sube_id`. Preview never rewrites `sube_id`. Example independence: Merkez Karabük / Medisa / İzmir remains representable.

---

## Live read validation

| Check | Result |
| --- | --- |
| Personel list | **200** |
| Personel detail (existing ids) | **200** |
| Existing `sube_id` | unchanged |
| `sgk_isveren_id` / `calisma_lokasyonu_id` on personnel | remain **NULL** |
| `sgk_isveren_adi` / `calisma_lokasyonu_adi` | remain **null** until mapping |
| Import references export `SGK_ISVEREN` | **3** |
| Import references export `CALISMA_LOKASYONU` | **7** |
| Retention / destroy / payroll mutation | **NO** |

---

## Import / attr catch-up (analysis only)

### MG-IMPORT-MAP-001 — still BUSINESS_DECISION_REQUIRED

Source inspected (private, non-repo): `Medisa Personel Veri Listesi.xlsx` + `Personel Listesi.xls`.

| SOURCE_COLUMN | CANONICAL_FIELD | STATUS |
| --- | --- | --- |
| No | sicil_no | **AMBIGUOUS** — sequence vs sicil unclear |
| Personel Kodu (xls) | sicil_no | **EXACT** candidate (alternate source) |
| Ad Soyad | ad / soyad | **TRANSFORM_REQUIRED** — combined; trustworthy split source not proven |
| Lokasyon | calisma_lokasyonu | **EXACT** (optional org column) |
| Departman | departman | **EXACT** (taxonomy alignment still needed) |
| Bölüm | — | **MISSING** native / ATTR decision |
| Birim | — | **MISSING** native / ATTR decision |
| Unvan | gorev | **AMBIGUOUS** vs Pozisyon |
| Pozisyon | — | **MISSING** native / ATTR decision |
| Grup | personel_tipi | **TRANSFORM_REQUIRED** (Beyaz/Mavi Yaka ↔ tipi) |
| SGK Dosyası | sgk_isveren | **TRANSFORM_REQUIRED** (`Diğer` ambiguous; Karyapı sparse in Medisa sheet) |
| Şirket / şube | sube | **AMBIGUOUS** — no explicit company+branch column matching locked model |
| Cep Telefonu | telefon | **EXACT** candidate |
| Doğum Tarihi | dogum_tarihi | **EXACT** |
| İşe Giriş Tarihi | ise_giris_tarihi | **EXACT** |
| Ücret / SGK payroll cols | — | **FORBIDDEN** in master import |

Open business decisions remain enumerated under `MG-IMPORT-MAP-001` in `110`.

### MG-ORG-ATTR-001 — recommendation (awaiting user approval)

**Recommendation:** `OPTION_B_NATIVE_FIELDS_REQUIRED` for bölüm / birim / pozisyon.

Evidence (aggregates only): source carries distinct Departman / Bölüm / Birim / Unvan / Pozisyon. Unvan≠Pozisyon on a large share of rows; Bölüm and Birim are not equal to Departman. Collapsing into `departman`/`gorev` alone loses hierarchy.

Status remains **BUSINESS_DECISION_REQUIRED** until user approves.

---

## Gap status (do not over-close)

| ID | Status after this ops |
| --- | --- |
| `MG-OPS-ORG-001` | **OPS_ROLLOUT** — SGK + verified location references seeded; remaining blockers: bare `Merkez` company decision; locked system-branch set incomplete in production |
| `MG-ORG-LOC-001` | **OPS_ROLLOUT** + `USER_GATED` — personnel mapping **not** applied |
| `MG-IMPORT-MAP-001` | **BUSINESS_DECISION_REQUIRED** |
| `MG-ORG-ATTR-001` | **BUSINESS_DECISION_REQUIRED** |
| `MG-OPS-PERSONEL-001` | **USER_GATED** |

---

## Explicit non-actions

| Item | Value |
| --- | --- |
| APPLICATION_CODE_CHANGED | **NO** |
| SQL_MIGRATION_CHANGED | **NO** |
| NEW_MIGRATION | **NO** |
| PERSONEL_IMPORT | **NO** |
| PERSONNEL org FK bulk write | **NO** |
| Branch reassignment | **NO** |
| RETENTION_FEATURE_ENABLED | **NO** |
| REAL_DESTRUCTION | **NO** |
| DOC_MERGE | **NO** (docs PR only) |
