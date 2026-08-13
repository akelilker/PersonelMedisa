# 120 — Org Structure Pack6 (native Bölüm / Birim / Pozisyon + branch SGK owner)

**Tür:** Code/schema/docs package (NO production write).
**Tarih:** 2026-08-14
**Baseline main:** `c840e180275d47a136092f41802986e724e9d863`

---

## Locked business decisions

| Decision | Value |
| --- | --- |
| CENTRAL_MEDISA_DISPLAY_NAME | **Medisa** |
| CENTRAL_MEDISA_CODE | **MRK** (retained) |
| MERKEZ_LABEL_USED | **NO** (target model) |
| LOSSY_COLLAPSE | **NO** |

### PERSONNEL_ORG_FIELDS

| Business | Canonical storage |
| --- | --- |
| Departman | `departman_id` (native existing) |
| Bölüm | `bolum_id` (**NEW**) |
| Birim | `birim_id` (**NEW**) |
| Unvan | `gorev_id` (existing owner — no `unvan_id`) |
| Pozisyon | `pozisyon_id` (**NEW**, flat) |
| Personel Tipi | `personel_tipi_id` (native existing) |

`MG-ORG-ATTR-001` = **CLOSED** (user decision locked).

---

## Code delivery

| Item | Value |
| --- | --- |
| MIGRATION_065 | `api/migrations/065_personel_org_structure.sql` |
| CODE_MIGRATION_TIP | **065** |
| PRODUCTION_MIGRATION_TIP | **064** (unchanged by this PR) |
| PRODUCTION_065_APPLIED | **NO** |
| PROD_COMPAT_064 | **YES** — readiness owner `PersonelOrgStructureSchema` |
| Error code | `ORG_STRUCTURE_SCHEMA_NOT_READY` (409) |
| Pack5 gate | `ORG_LOCATION_SCHEMA_NOT_READY` **not weakened** |
| `subeler.sgk_isveren_id` | branch COMPANY / SGK employer owner |
| AUTHORIZATION_OWNER | `personeller.sube_id` only (`SUBE_SCOPE_CHANGED = NO`) |

Pre-065:

- list/detail/create/update/import without Pack6 fields continue
- blank optional CSV headers `bolum`/`birim`/`pozisyon` → legacy behavior
- explicit API write (incl. `null`) of Pack6 IDs → 409 fail-closed

Post-065:

- hierarchy: Bölüm ⊂ Departman; Birim ⊂ Bölüm; Pozisyon independent
- import hierarchical exact resolution + manifest includes resolved IDs
- reference export includes `BOLUM` / `BIRIM` / `POZISYON` with parent context

---

## Future production rollout (PREPARE ONLY — NOT EXECUTED)

See ops runbook section in this PR docs sync / private readiness artifact.

| STEP | Action |
| --- | --- |
| 1 | Fresh DB backup |
| 2 | Apply migration `065` |
| 3 | Verify schema readiness |
| 4 | Seed taxonomy (Bölüm/Birim/Pozisyon) from verified inventory |
| 5 | Complete/update system branch set |
| 6 | Set `subeler.sgk_isveren_id` ownership |
| 7 | Rename MRK display `Merkez` → `Medisa` (keep id/code) |
| 8 | Read-only mapping preview |
| 9 | Apply exact personnel FK mapping (separate auth) |
| 10 | Real personel import (separate auth) |

### Locked branch inventory (future)

**MEDISA:** Medisa (`MRK`), Giresun (`GRS`), Medisa Kayseri (`MDS-KYS`), Medisa Ankara (`MDS-ANK`), Medisa İstanbul (`MDS-IST`)

**KARYAPI:** Karyapı (`KRP`), Karyapı Ankara (`KRP-ANK`), Karyapı Kayseri (`KRP-KYS`), Karyapı İstanbul (`KRP-IST`)

**OTHER:** Şenay Mobilya (`SNY`)

Branch ownership after Step 6: MRK/GRS/MDS-* → MEDISA; KRP-* → KARYAPI; SNY → SENAY_MOBILYA.

---

## Gap status after Pack6 code

| ID | Status |
| --- | --- |
| MG-ORG-ATTR-001 | **CLOSED** |
| MG-ORG-ATTR-ROLL-001 | **OPS_ROLLOUT** / `USER_GATED` (code ready; prod 065 not applied) |
| MG-OPS-ORG-001 | **PARTIAL** / `USER_GATED` |
| MG-ORG-LOC-001 | **OPS_ROLLOUT** / `USER_GATED` |
| MG-IMPORT-MAP-001 | business mapping CLOSED for org attrs; remaining → `MG-IMPORT-DATA-001` |
| MG-IMPORT-DATA-001 | `USER_GATED` source-data gaps |
| MG-OPS-PERSONEL-001 | `USER_GATED` |
| CODE_GAP_COUNT | **0** |

---

## Import mapping catch-up (business vs data)

| Topic | Status |
| --- | --- |
| Departman/Bölüm/Birim/Unvan/Pozisyon mapping | **CLOSED** (business) |
| Sicil (`Personel Kodu` → `sicil_no`) | recommend lock if uniqueness proven in private analysis; else data blocker |
| Ad/Soyad split | no blind final-space split; completion workflow |
| Grup → personel_tipi | only exact approved equivalents |
| `SGK Dosyası=Diğer` | remains blocker unless other evidence |
| Şube | locked branch model; not inferred from Lokasyon |

Private aggregates / readiness percent live **outside** public repo (no PII).

---

## Parallel P0/P1 readiness (read-only)

| ID | Code complete? | Can batch after Pack6 065 without more code? | Risk |
| --- | --- | --- | --- |
| MG-OPS-SGK-CAT-001 | mostly (fail-closed code) | ops verify/seed only | medium — catalog integrity |
| MG-OPS-UBGT-001 | engine/schema yes | calendar seed ops | medium |
| MG-OPS-POLICY-001 | form/runbook yes | live parameter approval | medium |
| MG-SGK-1514-001 | preview only | **needs business decision** | high if wrong period |
| MG-OPS-BIND-001 | schema `056` yes | binding rollout | medium |
| MG-OPS-QR-001 | pipeline CLOSED | employee rollout | medium |

Recommended post-Pack6 batch: migration 065 + taxonomy seed + branch set/ownership + MRK rename + mapping preview — **still USER_GATED**; do not auto-group SGK-1514 without decision.
