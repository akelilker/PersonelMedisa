# 142 — Branch manager assignment semantics correction

**Tür:** Kanonik ürün/teknik ayrım kaydı.
**Faz:** `BRANCH_MANAGER_ASSIGNMENT_SEMANTICS_CORRECTION_GATE`
**Tarih:** 2026-09-07
**Mutation:** 0 (production write yok; migration 088 code-only pending tip)

## Verdict

```
USER_SUBELER_SEMANTIC = ACCESS_SCOPE_ONLY
BRANCH_MANAGER_TECHNICAL_STATUS = TECHNICAL_GAP_LOCAL_FIXABLE → closed locally by migration 088
CANONICAL_OWNER_AFTER = sube_sorumlu_yoneticiler (+ OrganizasyonService replace)
```

Prior claim that `YonetimController.replaceUserSubeler` alone is the durable
branch-manager owner is **retracted**. `user_subeler` is the shared branch
**access / visibility / formal SGK scope** axis.

## Evidence

| Surface | Meaning |
| --- | --- |
| `user_subeler` schema | `(user_id, sube_id)` only — no manager type |
| `OrgScope::SUBE_ASSIGNMENT_ROLES` | `SUBE_YONETICISI`, `MUHASEBE`, smoke — access fail-closed |
| UI `YonetimSubeScopeField` | Label **Şube Yetkisi** (access), not “Sorumlu Yönetici” |
| Docs 101/140 | Explicit branch **scope** axis |
| A1 / `SgkKararPaketiAuthz` | Formal SGK scope from DB `user_subeler` (unchanged) |
| No prior dedicated manager table | TECHNICAL_GAP |

Assigning `user_subeler = [1,5,6,13]` means **A) this user may access these branches**
(for roles that consume the table). It does **not** canonically mean “responsible
branch manager of these branches”.

## Role independence

| Question | Answer |
| --- | --- |
| PRIMARY_ROLE_CHANGE_REQUIRED | **NO** (with `sube_sorumlu_yoneticiler`) |
| MULTI_ROLE_REQUIRED | **NO** |
| USER_SUBELER_SCOPE_SIDE_EFFECT | **NO** for manager assignment writes |
| MANAGER_FACT_DURABLE | **YES** via `sube_sorumlu_yoneticiler` |

PRIMARY_ROLE_CHANGE_REQUIRED = NO
MULTI_ROLE_REQUIRED = NO

Sinem may keep `GENEL_YONETICI` / `BOLUM_YONETICISI` (or other eligible primary role)
and be recorded as responsible manager of Fabrika/Ankara/İstanbul/Sakarya without
demotion and without narrowing GY empty-scope behaviour via forced `user_subeler`
fills for manager semantics.

## Cardinality (business + schema)

| Rule | Supported |
| --- | --- |
| BRANCH_CAN_HAVE_ZERO_MANAGER | YES (empty rows) |
| BRANCH_CAN_HAVE_ONE_MANAGER | YES |
| BRANCH_CAN_HAVE_MULTIPLE_MANAGERS | YES |
| ONE_PERSON_CAN_MANAGE_MULTIPLE_BRANCHES | YES |
| PERSONNEL_HOME_INDEPENDENT | YES |
| PHYSICAL_LOCATION_INDEPENDENT | YES |

## Canonical owner after fix

- Table: `sube_sorumlu_yoneticiler (sube_id, user_id)`
- Service: `SubeSorumluYoneticiSchema`
- Write path: `OrganizasyonService` create/update sube payload `sorumlu_yonetici_user_ids`
- UI: Şube form **Sorumlu Yönetici(ler)** (separate from **Şube Yetkisi**)
- Authz: OrgScope / `user_subeler` **unchanged** (no global permission expansion)

## Separation lock

```
PERSONNEL_HOME_BRANCH
  != PHYSICAL_WORK_LOCATION
  != MANAGED_BRANCH_ASSIGNMENTS   ← sube_sorumlu_yoneticiler
  != USER_ACCESS_BRANCH_SCOPE     ← user_subeler
```

A1 formal SGK explicit scope may still legitimately use `user_subeler`.
Do not conflate SGK formal branch scope with branch manager responsibility.

A1_SCOPE_MODEL_UNCHANGED = YES

## Business truth map (unchanged; no apply)

Fabrika→Sinem; Giresun→Halil; Kayseri→Kübra[surname unknown]; İzmir→Halil;
Ankara/İstanbul/Sakarya temporary/central→Sinem.

Encode future applies into `sube_sorumlu_yoneticiler`, **not** as manager-meaning
`user_subeler` rows.

## Next gate

Hosting recovery → live preimage → user-approved apply of migration 088 + manager rows.
