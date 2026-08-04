# ADR: Separate formal actor identity from incomplete personnel master

## Status

Accepted (Architecture Decision B) — S98 dual-control release path

## Context

S98 requires formal named humans for prepare/approve dual-control. Production `personeller` is not an established canonical personnel source of truth: row count is low, several rows match test/smoke patterns, and real institutional master evidence is not proven. Binding formal SGK actors to `users.personel_id → personeller.id` would force fake, incomplete, or privileged personnel rows solely to open dual-control accounts — rejected.

This decision does **not** prevent `personeller` from becoming canonical later. It only prevents the current S98 release from depending on an unproven master.

## Decision

- Formal SGK actor identity owner is `actor_identities`.
- User link owner is `users.actor_identity_id` (nullable unique FK).
- Runtime same-person enforcement uses `actor_identity_id` only (not name matching, not personnel master).
- Multi-account defense: DB unique index on `users.actor_identity_id` plus runtime fail-closed conflict deny.
- Scope remains `user_subeler` (explicit; empty scope is deny).
- Roles remain least-privilege: `IK_BORDRO` prepare-only, `SGK_KARAR_ONAY_YETKILISI` approve-only; legitimate individual `GENEL_YONETICI` prepare+approve capability unchanged; generic shared usernames denied.
- PII minimization: registry holds operational code, display/normalized name, status, verification source; no TC, sicil, phone, address, wage, bank, health, or birth date.
- Production actor rows are never seeded from repository migrations or fixtures.

## Actor identity owner

Table: `actor_identities`

- `status`: `PENDING` | `VERIFIED` | `REVOKED` (default `PENDING` — fail-closed until human verification)
- `verification_source`: `HUMAN_CONFIRMED` | `PERSONEL_LINKED` | `MIGRATED` (source alone does not verify)
- Formal prepare/approve requires linked user + `VERIFIED` identity

## Personel bridge (optional)

`actor_identities.personel_id` is an optional future FK (`ON DELETE SET NULL`, unique nullable). It does not gate formal actor readiness. When personnel master becomes canonical, reconciliation is human-controlled; automatic name backfill is forbidden; ambiguous matches stay fail-closed.

## Same-person enforcement

Canonical owner: `actor_identity_id`.

Approve must differ from preparer on both user id and actor identity id. Payload actor/user/identity fields are untrusted; only authenticated session actor is used.

## Scope separation

| Concern | Owner |
| --- | --- |
| Identity | `actor_identities` |
| Login / role | `users` |
| Branch scope | `user_subeler` |

## Consequences

- Migration `048` (unmerged / unapplied) rewritten pre-merge for actor identity (no `users.personel_id`).
- Personnel source-of-truth gates are out of S98 scope; actor identity gates replace them for dual-control readiness.
- Provisioning of exact human identities, named accounts, roles, and scope is a separate gated production phase (ops package only; no real names in repository).
- Backlog: sustainable actor identity admin/CRUD UI is not required for this PR and remains a future ownership gap.

## Future reconciliation

When institutional personnel SoT is proven:

1. Human-controlled link of existing `actor_identities` to `personeller`
2. Keep same-person owner as `actor_identity_id`
3. Do not auto-backfill from names
