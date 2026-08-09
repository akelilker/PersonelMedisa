# I13 Kullanıcı Alan Paritesi ve Schema Kararı

## Status

**PROPOSED** — I13-A decision-only (docs). Runtime kod, migration, API davranışı, production yazma yok.

**Base main:** `733f98092da9f6106e4d5cb3220259b8128fb39d`

## Context

Yönetim → Kullanıcılar yüzeyinin frontend contract’ı (`YonetimKullanici` / upsert payload) demo API ile geniş alan setini destekler; real API/DB ise dar bir auth+scope çekirdeğini saklar. Amaç “UI’daki her alanı DB’ye eklemek” değildir. Her alan için gerçek owner, persistence ihtiyacı, PII etkisi, auth etkisi ve SoT bağımlılığı kararı üretilir.

İncelenen alanlar: `username`, `password` / `password_hash`, `ad_soyad`, `telefon`, `kullanici_tipi`, `rol`, `personel_id`, `personel_ad_soyad`, `sube_ids`, `varsayilan_sube_id`, `durum`, `notlar`, `actor_identity_id`.

## Current frontend contract

Kaynaklar:

- `src/types/yonetim.ts` — `YonetimKullanici`, `UpsertYonetimKullaniciPayload`
- `src/lib/yonetim/kullanici-api-contract.ts` — real-mode sanitizer
- `src/api/yonetim.api.ts` — response normalize (extended alanları okur)
- `src/features/yonetim/pages/YonetimPaneliPage.tsx` — form; real modda tip/personel/telefon/notlar gizlenir
- `src/api/mock-demo.ts` — demo full persist

| Alan | FE model | Real UI edit | Real request |
| --- | --- | --- | --- |
| username | optional on read; required create | yes | sent |
| password | write-only upsert | yes | sent when set |
| ad_soyad | required | yes | sent |
| telefon | optional | hidden in real | **stripped** by sanitizer |
| kullanici_tipi | required | hidden in real (payload forced `HARICI`) | **stripped** |
| rol | required | yes | sent |
| personel_id | optional | hidden in real | **stripped** |
| personel_ad_soyad | read-only derived | n/a | n/a |
| sube_ids | required array | yes | sent |
| varsayilan_sube_id | required nullability | yes | **sent** (not stripped) |
| durum | required | yes | sent |
| notlar | optional | hidden in real | **stripped** |
| actor_identity_id | **not in Yönetim kullanici contract** | no | no |

Sanitizer sabitleri (`REAL_API_UNSUPPORTED_KULLANICI_FIELDS`): `telefon`, `personel_id`, `notlar`, `kullanici_tipi`.

## Current real API contract

Kaynak: `api/src/Controllers/YonetimController.php`

**Request accept (create/update):** `username`, `password` (create zorunlu; update opsiyonel), `ad_soyad`, `rol`, `durum`, `sube_ids`, `varsayilan_sube_id`.

**Response map (`mapKullaniciRow`):**

- DB’den: `id`, `username`, `ad_soyad`, `rol`, `durum`
- Scope join: `sube_ids` (`user_subeler`, `ORDER BY sube_id ASC`)
- Request echo / fallback: `varsayilan_sube_id` (aşağıdaki gap)
- Synthetic nulls: `telefon = null`, `personel_id = null`, `personel_ad_soyad = null`, `notlar = null`
- Role inference (not persisted): `kullanici_tipi = (rol === 'GENEL_YONETICI' ? 'HARICI' : 'IC_PERSONEL')`

Auth runtime:

- `LoginController`: `users` + `user_subeler ORDER BY sube_id ASC`; `active_sube_id = SubeScope::resolveInitialActiveSubeId($subeIds)` → listedeki ilk id
- `AuthMiddleware`: session user + `actor_identity_id` (users kolonu varsa)
- `SubeScope`: allowed ids from session; initial active = first allowed id

## Current DB contract

Migration `001_initial_schema.sql`:

```text
users (
  id, username, password_hash, ad_soyad,
  rol ENUM(...), durum ENUM('AKTIF','PASIF'),
  created_at, updated_at
)
user_subeler (
  user_id, sube_id  -- PK only; no default flag, no sort column
)
```

Later users changes:

- `041_auth_smoke_readonly_role.sql` — `users.rol` ENUM widen (`AUTH_SMOKE_READONLY`, `PATRON`)
- `048_sgk_dual_control_actor_roles.sql` — `actor_identities` + `users.actor_identity_id` (nullable unique FK)

**No migration adds** `users.telefon`, `users.personel_id`, `users.notlar`, `users.kullanici_tipi`, or `users.varsayilan_sube_id`.

`PROD_SCHEMA_DIRECT_EVIDENCE = UNAVAILABLE` (local read-only DB credentials yok; SHOW COLUMNS çalıştırılmadı). Karar migration/repo şeması + PHP runtime kanıtına dayanır; production direct evidence decision’ı bloklamaz.

## Field parity matrix

| FIELD | UI_PRESENT | DEMO_API_SUPPORT | REAL_REQUEST_SUPPORT | REAL_RESPONSE_SUPPORT | DB_STORAGE | AUTH_RUNTIME_USE | BUSINESS_RUNTIME_USE | SOURCE_OF_TRUTH | PII | CURRENT_STATUS | PROPOSED_DECISION | WHY |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| username | yes | yes | yes | yes | `users.username` | login lookup | admin identity | users | low | FULL_PARITY | KEEP_AS_IS | Auth login key |
| password / password_hash | write-only UI | demo local | password write | never hash | `users.password_hash` | verify | none | users | secret | FULL_PARITY (write-only) | KEEP_AS_IS | Hash only; response never returns password/hash |
| ad_soyad | yes | yes | yes | yes | `users.ad_soyad` | login payload | display | users | PII name | FULL_PARITY | KEEP_AS_IS | Operational display name on account |
| telefon | demo UI only | yes | no (sanitized) | always null | none on users | no | no proven | n/a | PII phone | RESPONSE_SYNTHETIC + WRITE_SANITIZED | DEFER_WITH_GATE | No runtime owner; PII expansion without business use |
| kullanici_tipi | demo UI; real hidden | yes | no (sanitized) | role-inferred | none | no | no proven independent | synthetic from rol | low | RESPONSE_DERIVED | DEFER_WITH_GATE | Inference ≠ business truth; do not persist as migration fiction |
| rol | yes | yes | yes | yes | `users.rol` | JWT / permissions | all gated surfaces | users | low | FULL_PARITY | KEEP_AS_IS | Permission owner |
| personel_id | demo UI; real hidden | yes | no (sanitized) | always null | none on users | no | none for dual-control | forbidden as formal SoT | link risk | RESPONSE_SYNTHETIC + WRITE_SANITIZED | DEFER_WITH_GATE | Gate = institutional personel SoT accepted; separate from actor_identity |
| personel_ad_soyad | derived display | demo derive | n/a | always null | none | no | display only if link | derive from personeller if linked | PII name | RESPONSE_SYNTHETIC | DERIVE | Never separate storage; only from approved future link |
| sube_ids | yes | yes | yes | yes | `user_subeler` | scope load | SubeScope | user_subeler | low | FULL_PARITY | KEEP_AS_IS | Explicit branch scope owner |
| varsayilan_sube_id | yes | yes (persisted in memory) | yes (validated + reorder attempt) | echo on mutate / ASC fallback on list | **not persisted** | login uses ASC first id | UX default branch | missing | low | PARTIAL_NOT_PERSISTED | PERSIST | Only proven operational gap with real request surface |
| durum | yes | yes | yes | yes | `users.durum` | login AKTIF gate | admin | users | low | FULL_PARITY | KEEP_AS_IS | Account enablement |
| notlar | demo UI only | yes | no (sanitized) | always null | none | no | no proven owner | n/a | free-text PII risk | RESPONSE_SYNTHETIC + WRITE_SANITIZED | DEFER_WITH_GATE | Convenience note; no operational owner |
| actor_identity_id | not in Yönetim form | n/a | n/a (Yönetim) | via AuthMiddleware when column present | `users.actor_identity_id` | SGK dual-control same-person | formal actor | actor_identities + users FK | minimized registry | AUTH_ONLY (relative to Yönetim UI) | KEEP_AS_IS | ADR-0001 formal identity owner; out of this UI parity package |

## Hidden varsayilan_sube_id persistence gap

**VARSAYILAN_SUBE_STATUS = PARTIAL_API_SUPPORT_BUT_NOT_PERSISTED**

Proven on current main:

1. **API accepts** `varsayilan_sube_id` on create/update; validates membership in `sube_ids`; AUTH_SMOKE_READONLY exact-one-sube contract intact.
2. **Create/update** call `normalizeSubeIdsWithVarsayilan` so default is first in the in-memory array before `replaceUserSubeler`.
3. **DB stores only** `(user_id, sube_id)` — no default column; insert order is not a durable sort key.
4. **Fresh GET list** reloads `ORDER BY user_id ASC, sube_id ASC` and maps `varsayilan_sube_id` to **minimum sube_id** (first ASC), not the previously selected default (unless they coincide).
5. **Create/update response** can echo the request default via `mapKullaniciRow(..., $varsayilanSubeId)` — **response-only illusion**, not durable storage.
6. **Fresh login** loads `ORDER BY sube_id ASC` and sets `active_sube_id` to that first id (`SubeScope::resolveInitialActiveSubeId`). Selected default is **not** preserved across logout/login when multiple branches exist and default ≠ min(id).

Therefore:

| Check | Result |
| --- | --- |
| VARSAYILAN_SUBE_REQUEST_SUPPORTED | YES |
| VARSAYILAN_SUBE_DB_PERSISTED | NO |
| VARSAYILAN_SUBE_SURVIVES_FRESH_GET | NO (unless default == min scoped id) |
| VARSAYILAN_SUBE_SURVIVES_LOGIN | NO (same) |
| DEFAULT_BRANCH_GAP | CONFIRMED |

## ADR-0001 actor identity constraint

`docs/adr/0001-separate-formal-actor-identity-from-personnel-master.md` (Accepted):

- **FORMAL_ACTOR_IDENTITY_OWNER = `actor_identity_id`**
- Do **not** replace with `personel_id`
- Do **not** use `personel_id` for SGK dual control
- Do **not** auto-match user ↔ personel by name
- Do **not** bind existing users to test/smoke personel IDs
- Optional future personnel bridge lives on `actor_identities.personel_id`, human-controlled

I13 consequence: `users.personel_id` (if ever added) is a **separate optional business link**, not formal identity. This decision package does **not** introduce it.

## Options considered

### A — Full parity now

Persist: `telefon`, `kullanici_tipi`, `personel_id`, `notlar`, `varsayilan_sube_id`.

Rejected for I13-B default path:

- PII expansion (`telefon`, free-text `notlar`) without proven runtime consumers
- `personel_id` creates SoT / ADR-0001 confusion and backfill pressure
- `kullanici_tipi` has no independent business meaning proven beyond role heuristic
- Operational value concentrated in default branch only

### B — Minimal safe parity

**Preferred.**

Persist only the proven operational field: **`varsayilan_sube_id`**.

Keep existing durable core: `username`, `password_hash`, `ad_soyad`, `rol`, `durum`, `sube_ids` (`user_subeler`), `actor_identity_id`.

Defer unsupported convenience/profile fields until concrete business need is proven and gated.

### C — Extended user profile table

Separate profile table for phone/notes/type/link.

Rejected for now: architecture aesthetics without proven multi-owner profile requirements; adds join/migration complexity for deferred fields.

## Proposed decision

**PROPOSED_OPTION = B — Minimal safe parity**

Approve for a future **I13-B** implementation package (not this PR):

1. Persist `varsayilan_sube_id` durably (recommended owner: nullable `users.varsayilan_sube_id` FK to `subeler`, constrained to membership in `user_subeler` at write time).
2. Keep Yönetim real UI stripped of deferred profile fields until a later gated package.
3. Leave `actor_identity_id` ownership unchanged (ADR-0001).
4. Do not invent classifications or personnel links for existing rows.

## Field-by-field disposition

| Field | Decision |
| --- | --- |
| username | KEEP_AS_IS |
| password / password_hash | KEEP_AS_IS (request password write-only; DB hash; response never password/hash) |
| ad_soyad | KEEP_AS_IS |
| rol | KEEP_AS_IS |
| durum | KEEP_AS_IS |
| sube_ids | KEEP_AS_IS (`user_subeler` owner) |
| varsayilan_sube_id | **PERSIST** (I13-B candidate) |
| telefon | DEFER_WITH_GATE (need proven runtime business use) |
| kullanici_tipi | DEFER_WITH_GATE (do not treat role inference as canonical; prefer defer over independent persist unless proven) |
| personel_id | DEFER_WITH_GATE (gate = institutional personel SoT explicitly accepted) |
| personel_ad_soyad | DERIVE only from approved future personel link; never separate storage |
| notlar | DEFER_WITH_GATE (need operational owner) |
| actor_identity_id | KEEP_AS_IS (formal identity owner) |

## Default branch invariant

Future I13-B (if approved) must implement:

- `varsayilan_sube_id IS NULL` **OR**
- `varsayilan_sube_id` belongs to the same user’s `user_subeler`.

Recommended runtime resolution (document now; implement later):

1. Persisted valid default → use it
2. Exactly one allowed branch + no default → that branch
3. Multiple allowed + no default → deterministic runtime fallback (e.g. min `sube_id`)
4. Fallback must **not** pretend it was a persisted user choice in admin GET semantics (distinguish “resolved active” vs “stored default” if needed)
5. If scope update removes current default → clear it unless a new valid default is explicitly supplied

**AUTH_SMOKE_READONLY** exact-one-sube contract remains intact: if default is set, it must equal the sole scoped sube.

No implementation in I13-A.

## Existing-user migration/backfill rule

Explicitly **reject**:

- `users.ad_soyad` ↔ `personeller` name matching
- “all non-GENEL users = IC_PERSONEL” as migration truth
- automatic `personel_id` inference
- automatic actor_identity / personel reconciliation

Existing rows must **not** receive invented classifications.

For `varsayilan_sube_id` backfill (I13-B):

- Prefer leave **NULL** for multi-scope users (honest “no persisted default”)
- Optional deterministic fill only when `COUNT(user_subeler)=1` (that sole sube) — still a runtime-compatible default, not a fabricated preference history
- Never invent phone / tip / personel / notes from demo heuristics

## Security and PII implications

- Persisting default branch: low PII; operational scope preference only
- Deferring `telefon` / `notlar`: avoids expanding stored PII surface without consumers
- Deferring `personel_id`: avoids coupling login accounts to incomplete personnel master and dual-control confusion
- Password remains write-only; hash never in API responses
- Actor identity registry PII minimization (ADR-0001) unchanged

## Rejected alternatives

- Full parity migration now (Option A)
- Extended profile table for aesthetics (Option C)
- Treating `user_subeler` insert order as durable default (already false under `ORDER BY sube_id ASC`)
- Using response-echo of `varsayilan_sube_id` as “done”
- Replacing `actor_identity_id` with `personel_id`
- Demo-driven backfill of tip/personel/phone onto production users

## Future implementation package

**NEXT_IMPLEMENTATION = I13B_APPROVED_USER_FIELD_PARITY_IMPLEMENTATION** (only after this ADR is accepted)

If `varsayilan_sube_id` approved:

| Concern | Future owner (expected) |
| --- | --- |
| FUTURE_SCHEMA_OWNER | likely `users.varsayilan_sube_id` nullable FK → `subeler` (+ write-time membership check vs `user_subeler`); evidence-backed alternative only if schema discovery disproves |
| FUTURE_BACKEND | `YonetimController` map/load/persist; `LoginController` (+ possibly AuthMiddleware session) to honor persisted default for `active_sube_id` |
| FUTURE_FRONTEND | keep sanitizer strips for deferred fields; stop treating default as “already real-complete”; adjust any UX that assumes list GET preserves choice |
| FUTURE_TESTS | create/update persistence; fresh GET; fresh login; scope-removal clears invalid default; AUTH_SMOKE_READONLY regression |

Create **none** of these runtime changes in I13-A.

## Acceptance gates

I13-A (this package):

- [x] Discovery against exact main base
- [x] Decision doc committed
- [x] Draft PR only
- [ ] Exact-head CI SUCCESS (PR workflow)
- [x] `MIGRATION_CREATED = NO`
- [x] `BACKEND_RUNTIME_CHANGED = NO`
- [x] `FRONTEND_RUNTIME_CHANGED = NO`
- [x] `API_RUNTIME_CHANGED = NO`
- [x] `PRODUCTION_WRITE = NO`
- [x] `FULL_E2E_RERUN = NO`
- [x] `I13_MERGED = NO` / `I13_DEPLOYED = NO`

I13-B (future, after user accepts this ADR):

- Migration + backend + login resolution + focused tests
- No automatic personnel/name backfill
- AUTH_SMOKE_READONLY intact

**NEXT_ACTION = USER_REVIEW_I13_SCHEMA_DECISION**
