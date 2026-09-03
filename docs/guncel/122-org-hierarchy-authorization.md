# 122 — Organizational hierarchy authorization

**Tür:** Auth contract (code + schema). **NO production write.**

## Hierarchy

```
GENEL_YONETICI / SISTEM_YONETICISI  (global)
  → SUBE_YONETICISI                 (user_subeler)
    → BOLUM_YONETICISI              (user_bolumler)
    → BIRIM_AMIRI                   (user_birimler)
    → PERSONEL                      (users.personel_id)
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
| IK_SORUMLUSU, IK_PERSONELI | Organisation-wide **read** from role (assignment may not narrow). `IK_PERSONELI` **write** narrowed by `user_sirketler` via `HrWriteScope` |
| SUBE_YONETICISI | Requires `user_subeler`; empty → **Deny** |
| MUHASEBE | Requires at least one of `user_subeler` / `user_sirketler` / `user_sgk_isverenler`; empty → **Deny** |
| BOLUM_YONETICISI | Requires `user_bolumler`; empty → **Deny**. `user_subeler` is **not** a fallback |
| BIRIM_AMIRI | Requires `user_birimler`; empty → **Deny**. `user_subeler` is **not** a fallback |
| PERSONEL | Bound `personel_id` only |

Legacy `user_subeler` fallback for BOLUM/BIRIM was removed after canonical unit assignment rollout (all active BY/BA had unit rows). Write path (`YonetimController::assertRoleOrgAssignments`) also requires at least one bolum (BY) or birim (BA) assignment.

## Schema readiness

- `UserOrgAssignmentSchema::isReady` gates all `user_bolumler` / `user_birimler` SQL
- Missing tables → empty unit ids (no crash); BOLUM/BIRIM with empty unit → **Deny**
- `SUBE_YONETICISI` writes → 409 `SCHEMA_NOT_READY` until ENUM widened (migration 071)

## Schema (071)

- ENUM widen: `SUBE_YONETICISI`
- Tables: `user_bolumler`, `user_birimler`
- No user remaps / no assignment backfill

## Schema note

Pack6: `bolumler ⊂ departmanlar` (catalog). Personnel carry `sube_id` / `bolum_id` / `birim_id`. Auth for bölüm/birim uses those FKs + assignment tables — not a `bolumler.sube_id` parent column.
