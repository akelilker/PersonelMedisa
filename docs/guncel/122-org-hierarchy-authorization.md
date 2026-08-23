# 122 — Organizational hierarchy authorization

**Tür:** Auth contract (code + schema). **NO production write.**

## Hierarchy

```
GENEL_YONETICI / SISTEM_YONETICISI  (global)
  → SUBE_YONETICISI                 (user_subeler)
  → BOLUM_YONETICISI                (user_bolumler)
  → BIRIM_AMIRI                     (user_birimler)
  → PERSONEL                        (users.personel_id)
```

Functional branch-scoped: `IK_SORUMLUSU`, `MUHASEBE` via `user_subeler`.

## Permission ≠ scope

| Layer | Owner | Answers |
| --- | --- | --- |
| Permission | `RolePermissions` (BE) / `role-permissions.ts` (FE UX) | What can this role do? |
| Scope | `OrgScope` (`api/src/Scope/OrgScope.php`) | On which org records? |

`SubeScope` remains a thin facade over `OrgScope`.

## Empty assignment policy

| Role | Empty required assignment |
| --- | --- |
| GENEL_YONETICI, SISTEM_YONETICISI | Unrestricted (global) |
| SUBE_YONETICISI, IK_SORUMLUSU, MUHASEBE | **Deny** (fail-closed) |
| BOLUM_YONETICISI | Unit assign (`user_bolumler`) preferred; **legacy `user_subeler` fallback** when unit empty; both empty → **Deny** |
| BIRIM_AMIRI | Unit assign (`user_birimler`) preferred; **legacy `user_subeler` fallback** when unit empty; both empty → **Deny** |
| PERSONEL | Bound `personel_id` only |

## Deploy transition (STAGE A)

Deploy cPanel does **not** apply migrations. Code must remain safe when 071 is absent:

- `UserOrgAssignmentSchema::isReady` gates all `user_bolumler` / `user_birimler` SQL
- Missing tables → empty unit ids (no crash); BOLUM/BIRIM keep legacy branch scope if `user_subeler` present
- `SUBE_YONETICISI` writes → 409 `SCHEMA_NOT_READY` until ENUM widened

### Ops rollout

| Stage | Action |
| --- | --- |
| A | Ship code + migration file; existing BOLUM/BIRIM with branch scope continue |
| B | Apply migration 071 via Apply cPanel migrations; populate `user_bolumler` / `user_birimler` |
| C | Once all operational BOLUM/BIRIM have unit rows, remove legacy sube fallback in a follow-up |

## Schema (071)

- ENUM widen: `SUBE_YONETICISI`
- Tables: `user_bolumler`, `user_birimler`
- No user remaps / no assignment backfill

## Schema note

Pack6: `bolumler ⊂ departmanlar` (catalog). Personnel carry `sube_id` / `bolum_id` / `birim_id`. Auth for bölüm/birim uses those FKs + assignment tables — not a `bolumler.sube_id` parent column.
