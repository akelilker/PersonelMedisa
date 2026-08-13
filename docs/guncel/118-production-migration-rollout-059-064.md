# 118 — Production Migration Rollout 059 → 064

**Tür:** Production schema rollout evidence (docs sync).
**Tarih:** 2026-08-13
**Canonical main SHA:** `8b5a5955080bd2dfe21569480154ac4a76d5d199`

**Scope:** DB migrations `059`–`064` only. No application code change in this ops event. No seed / backfill / import / retention enable / physical destruction.

---

## Evidence summary

| Item | Value |
| --- | --- |
| MAIN_SHA | `8b5a5955080bd2dfe21569480154ac4a76d5d199` |
| MAIN_CI | **PASS** — run `31736934621` |
| AUTO_DEPLOY | **PASS** — run `31737170752` |
| CODE_MIGRATION_TIP | **064** |
| PRODUCTION_TIP_BEFORE | **058** |
| PRODUCTION_TIP_AFTER | **064** |
| DB_VERSION | `10.6.21-MariaDB-cll-lve` |
| DB_NAME | `karmotor_medisa` |
| BACKUP_CREATED | **YES** |
| BACKUP_VERIFY | **PASS** |
| BACKUP_SIZE | `446641` bytes |
| BACKUP_SHA256 | `04cd9dde89f66fcd1c01605c4795db8800d39ede5cd15b24eb37eb9eefdf10d0` |
| READ_ONLY_APP_SMOKE | **PASS** |

Backup stored outside webroot (ops path; not committed). Credentials / unredacted hosts are **not** recorded here.

---

## Migrations applied (exact order)

| Migration | Result |
| --- | --- |
| `059_retention_physical_destruction_execution.sql` | **PASS** |
| `060_retention_physical_destroy_trigger_gate.sql` | **PASS** |
| `061_serbest_zaman_kullanim_tahsisleri.sql` | **PASS** |
| `062_serbest_zaman_retention_destroy_gate.sql` | **PASS** |
| `063_fazla_calisma_actual_date_provenance.sql` | **PASS** |
| `064_personel_org_location_model.sql` | **PASS** |

---

## Post-rollout runtime / ops facts

| Flag / fact | Value |
| --- | --- |
| `RETENTION_SCHEMA_PRODUCTION_READY` | **YES** |
| `RETENTION_FEATURE_ENABLED` | **NO** |
| `REAL_DESTRUCTION_EXECUTED` | **NO** |
| `OPEN_DESTROY_GATE_COUNT` | **0** |
| `SERBEST_ZAMAN_ALLOCATION_SCHEMA_PRODUCTION_READY` | **YES** |
| `SERBEST_ZAMAN_REAL_BACKFILL` | **NO** |
| `OT_ACTUAL_DATE_PROVENANCE_SCHEMA_PRODUCTION_READY` | **YES** |
| `LEGACY_OT_BACKFILL` | **NO** |
| `ORG_LOCATION_SCHEMA_PRODUCTION_READY` | **YES** |
| `ORG_SEED` | **NO** |
| `ORG_BACKFILL` | **NO** |
| `PERSONEL_IMPORT` | **NO** |
| `sgk_isverenler` rows | **0** |
| `calisma_lokasyonlari` rows | **0** |
| `serbest_zaman_kullanim_tahsisleri` rows | **0** |
| `retention_imha_executionlari` rows | **0** |

---

## Gap status (do not over-close)

| ID | Status after rollout |
| --- | --- |
| `MG-OT-YEAR-POL-001` | **CLOSED** |
| `MG-OT-YEAR-PATH-001` | **CLOSED** |
| `MG-ORG-LOC-001` | **OPS_ROLLOUT** + `USER_GATED` (schema applied; real seed / personnel mapping still gated) |
| `MG-RET-PHYS-001` | **OPS_ROLLOUT** + `USER_GATED` (`059`/`060`/`062` applied; feature flag OFF; no real destroy) |
| `MG-SZ-6M-001` | **OPS_ROLLOUT** + `USER_GATED` (`061`/`062` schema rollout complete; ops follow-up remains) |

`CODE_GAP_COUNT = 0`

---

## Non-claims

This rollout does **not** authorize:

- retention physical destruction enablement
- real destruction execution
- SGK employer / work-location seed
- personnel org mapping / backfill
- CSV personnel import
