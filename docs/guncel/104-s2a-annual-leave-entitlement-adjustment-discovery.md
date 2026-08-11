# 104 — S2A/S2B Annual Leave Entitlement Adjustment

**Branch:** `feat/annual-leave-entitlement-adjustments`  
**Date:** 2026-08-11  
**Status:** IMPLEMENTED (staged) — business decisions locked

## Locked decisions

| ID | Choice | Effect |
| --- | --- | --- |
| **D1** | **B** — `IK_SORUMLUSU` + `GENEL_YONETICI` via `yillik_izin_hak_duzeltme.manage` | GY/IK write; BY/MUH/SYS/PERSONEL denied |
| **D2** | **A** — no approval workflow; create/reverse immediately effective | No `status` column; no approve permission |
| **D3** | Remaining **zero-floor preserved** | `remaining = max(raw_remaining, 0)`; ledger may store signed deltas |

## Semantic lock

- `LEGAL_ENTITLEMENT_SEMANTIC` = **current service-year annual entitlement band** (not cumulative lifetime total)
- `LEGAL_ENTITLEMENT_SEMANTIC_BLOCKER` = **NO**
- Statutory formula unchanged (`izin-hesap-motoru.ts` / `YillikIzinHakEdisService`)

## Migration

- **055** `api/migrations/055_yillik_izin_hak_duzeltmeleri.sql` — append-only ledger (`DEVIR`, `EK_HAK`, `DUZELTME`, `TERS_KAYIT`)
- **Staged only** — not applied to production
- 052/053/054 remain immutable predecessors

## Server owners (implemented)

| Concern | Owner |
| --- | --- |
| Legal entitlement calc | `YillikIzinHakEdisService` |
| Used leave from süreçler | `YillikIzinKullanimService` |
| Manual adjustment ledger | `YillikIzinHakDuzeltmeLedgerService` |
| Effective balance read model | `YillikIzinBakiyeService` |
| HTTP surface | `YillikIzinHakDuzeltmeController` + `Router` |

Balance contract:

```text
raw_remaining = legal_entitlement + manual_net − used_annual_leave
remaining       = max(raw_remaining, 0)   // D3
                = null if used unresolved (calendar fail-closed)
```

## Authz fixes

- `yillik_izin_hak_duzeltme.manage` on GY + IK only (FE `role-permissions.ts` + PHP `RolePermissions.php`)
- `SureclerController` drift fixed: `RolePermissions::assert($user, 'surecler.create')` (removed hardcoded MUHASEBE allowlist)

## UI / routes

- Write surface: Kayıt ve Süreç — `YillikIzinHakDuzeltmePanel` tile (`yillik_izin_hak_duzeltme.manage` gate)
- Read surface: Personel Kartı — `PersonelIzinOzetSection` fetches server `yillik-izin-bakiye` (no local `hesaplaIzinBakiye`)
- Routes: `GET/POST …/yillik-izin-hak-duzeltmeleri`, `POST …/ters-kayit`, `GET …/yillik-izin-bakiye` — **no DELETE**, no `kalan_izin` overwrite API

## Invariants (unchanged)

- No mutable `kalan_izin` field writes
- No fake `YILLIK_IZIN` süreç rows for entitlement adjustment
- Personel Kartı remains read-only for adjustments
- Append-only ledger; reversal via compensating `TERS_KAYIT`
