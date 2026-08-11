# 104 — S2A/S2B/S2C Annual Leave Entitlement Adjustment

**Branch:** `feat/annual-leave-entitlement-adjustments`  
**Date:** 2026-08-11 (S2C hardening)
**Status:** IMPLEMENTED (staged) — business decisions locked; S2C review blockers closed

## Locked decisions

| ID | Choice | Effect |
| --- | --- | --- |
| **D1** | **B** — `IK_SORUMLUSU` + `GENEL_YONETICI` via `yillik_izin_hak_duzeltme.manage` | GY/IK write; BY/MUH/SYS/PERSONEL denied |
| **D2** | **A** — no approval workflow; create/reverse immediately effective | No `status` column; no approve permission |
| **D3** | Remaining **zero-floor preserved** | `remaining = max(raw_remaining, 0)`; ledger may store signed deltas |

## Semantic owners (S2C correction)

S2B initially locked `LEGAL_ENTITLEMENT_SEMANTIC = CURRENT_SERVICE_YEAR_BAND` as the **full balance entitlement owner**. That was incorrect for production balance and is corrected here before rollout.

| Concept | Semantic | Field |
| --- | --- | --- |
| **ANNUAL_BAND_OWNER** | `CURRENT_SERVICE_YEAR_BAND` | `mevcut_yillik_hak_gun` / Owner A `yillik_izin_gun` |
| **BALANCE_LEGAL_OWNER** | `CUMULATIVE_STATUTORY_ACCRUAL_AS_OF_REFERENCE_DATE` | `birikmis_yasal_hak_gun` |

Compatibility: `yasal_hak_gun` === `birikmis_yasal_hak_gun` (cumulative). Do **not** treat it as the current-year band.

Statutory annual band rule itself is unchanged (`izin-hesap-motoru.ts` / `YillikIzinHakEdisService::hesaplaYillikIzinGun`):

- completed service years 1–5 → 14
- >5 and <15 → 20
- 15+ → 26
- age ≤18 or ≥50 → minimum 20 (evaluated **per accrual anniversary**)

Cumulative accrual = sum of annual band at each completed service-year anniversary ≤ `referans_tarih`. Pre-first anniversary = 0. No partial-year prorating. No DB rows for statutory accrual.

## Review blockers closed (S2C)

| Blocker | Root cause | Fix |
| --- | --- | --- |
| **A PERIOD_MISMATCH** | Current-year band − lifetime used leave | Cumulative statutory as-of reference − used as-of reference |
| **B REFERANS_TARIH** | Ledger `netSum` and usage ignored reference date | `netSumAsOf` / used-leave as-of filter; single resolved reference date |

## As-of contract

`referans_tarih` is the authoritative AS-OF business date for **all** balance components:

- statutory accrual: anniversaries ≤ reference
- manual ledger: `effective_date` ≤ reference (`netSumAsOf`)
- used annual leave: calendar days ≤ reference
- absent reference → resolve today once at service boundary
- explicit malformed reference → **422 VALIDATION_ERROR** (no silent today)

### Reversal effective-date semantic

`TERS_KAYIT` copies the original row `effective_date`. Original row remains immutable; `created_at` records when the correction was entered. AS-OF balance therefore **restates** business history from the original effective date (`RESTATEMENT_FROM_ORIGINAL_EFFECTIVE_DATE`).

## Migration

- **055** `api/migrations/055_yillik_izin_hak_duzeltmeleri.sql` — append-only ledger (`DEVIR`, `EK_HAK`, `DUZELTME`, `TERS_KAYIT`)
- Index `idx_yihd_personel_effective (personel_id, effective_date, id)` supports as-of sum
- **Staged only** — not applied to production
- 052/053/054 remain immutable predecessors
- No cumulative statutory columns; no mutable balance fields; no legal ledger seed

## Server owners

| Concern | Owner |
| --- | --- |
| Annual band (Owner A) | `YillikIzinHakEdisService::hesaplaYillikIzinGun` / `hesaplaIzinHakEdis` |
| Cumulative accrual (Owner B) | `YillikIzinHakEdisService::hesaplaBirikmisYasalHak` |
| Used leave as-of | `YillikIzinKullanimService` |
| Manual adjustment ledger | `YillikIzinHakDuzeltmeLedgerService` (`netSum` history; `netSumAsOf` balance) |
| Effective balance read model | `YillikIzinBakiyeService` |
| HTTP surface | `YillikIzinHakDuzeltmeController` + `Router` |

Balance contract:

```text
raw_remaining = birikmis_yasal_hak_gun + manuel_duzeltme_gun(as-of) − kullanilan_gun(as-of)
remaining       = max(raw_remaining, 0)   // D3
                = null if used unresolved (calendar fail-closed for dates ≤ reference only)
```

## Authz

- `yillik_izin_hak_duzeltme.manage` on GY + IK only
- `SureclerController` drift fix preserved: `RolePermissions::assert($user, 'surecler.create')`

## UI / routes

- Write surface: **Süreç** only — `YillikIzinHakDuzeltmePanel` (not Kayıt first-create tab)
- Read surface: Personel Kartı — server `yillik-izin-bakiye` with Bu Yıl / Birikmiş / Manuel / Kullanılan / Kalan
- Future-dated ledger rows may appear in history as **İleri tarihli**; excluded from balance until effective
- Routes: `GET/POST …/yillik-izin-hak-duzeltmeleri`, `POST …/ters-kayit`, `GET …/yillik-izin-bakiye` — **no DELETE**, no `kalan_izin` overwrite API

## Invariants

- No mutable `kalan_izin` field writes
- No fake `YILLIK_IZIN` süreç rows for entitlement adjustment
- Personel Kartı remains read-only for adjustments
- Append-only ledger; reversal via compensating `TERS_KAYIT`
- DEVIR remains manual opening/historical correction — **not** automatic legal accrual
- PRODUCTION_BACKFILL = NO; MIGRATION_055_APPLIED = NO until explicit rollout gate
