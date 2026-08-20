# Core data security, offline correctness, and telemetry semantics

Canonical decisions for PersonelMedisa frontend + API hardening (branch `feat/core-data-security-hardening`).

## 1. Protected cache ownership

`AppData` carries `ownerFingerprint` derived from `getActorFingerprint(session)` (`userId|role|sorted sube_ids`). Actor mismatch on load/ensure **purges** protected cache (`medisa_app_data`) and resets in-memory cache. Sync queue is **not** wiped on session flip; items remain actor-scoped via `ownerFingerprint` filtering so same-actor re-auth can resume. Cross-user cache reuse and cross-user queue replay are denied.

## 2. Offline queue state machine

Queue items are stateful: `PENDING | PROCESSING | FAILED_RETRYABLE | BLOCKED_AUTH | BLOCKED_PERMISSION | CONFLICT | DEAD_LETTER`.

- `401` → `BLOCKED_AUTH` — after same-actor successful re-auth / `loadDataFromServer`, resume to `PENDING` (stable `item.id` = `Idempotency-Key`)
- `403` → `BLOCKED_PERMISSION` — **no** automatic retry after login; requires permission/business resolution
- `409/422` → `CONFLICT`
- retryable transport/5xx → `FAILED_RETRYABLE` until `MAX_SYNC_ATTEMPTS` → `DEAD_LETTER`
- success → item is **pruned** from queue (no permanent COMPLETED payload retention)
- stale `PROCESSING` (age ≥ `STALE_PROCESSING_MS`) → `FAILED_RETRYABLE` for same-key replay
- queue write failure (quota): PENDING→PROCESSING fail-closed (no dispatch); never silent-ignore on state transitions

## 3. Mutation idempotency (server ledger)

Frontend: offline queue item `id` → `Idempotency-Key` header. Transport **never mints** keys.

Backend owner: `OfflineMutationIdempotencyService` + migration `070_offline_mutation_idempotency.sql` table `offline_mutation_idempotency`.

- Identity: authenticated `actor_user_id` + `operation_scope` + `Idempotency-Key` (UNIQUE)
- Scopes: `personeller.create|update:{id}`, `surecler.*`, `bildirimler.*`, `finans.*`, `puantaj.upsert:{personel_id}:{tarih}`
- `payload_hash` over allowlisted canonical business payload (no raw body / password / token storage)
- same hash → idempotent replay (re-read entity by result locator)
- different hash → HTTP 409 `IDEMPOTENCY_KEY_CONFLICT` (fail-closed)
- claim + business mutation + complete in the **same DB transaction**; concurrent same-key → business mutation once
- Auth/login/read-only endpoints are not wrapped

## 4. Safe HTTP retry / failover

- **SAFE** (`GET`/`HEAD`, default method): bounded attempts (max 3), base-candidate failover on network failure / exhausted transient retries / `404` discovery.
- **UNSAFE** (`POST`/`PUT`/`PATCH`/`DELETE`): pin first base; **no** blind cross-base retry after uncertain network; **no** mutation `404`→other-base replay.
- Transient read statuses only: `408`, `429`, `502`, `503`, `504`. `Retry-After` honored with a hard delay cap.

## 5. Timeout / abort semantics

All requests use AbortController timeout (default 30s, overridable via `timeoutMs`). External `signal` is composed, not overwritten.

- Timeout → `ApiRequestError` `status=0` `code=REQUEST_TIMEOUT`
- Caller abort → `REQUEST_ABORTED` (not classified as timeout; does not trigger safe retry)

## 6. Realtime PERSONEL normalization

`parsePersonelRealtimePayload` maps allowlisted fields including org ids/names. `mergePersonelCanonical` merges sparse patches onto existing detail/list rows so undefined org fields are **retained**.

## 7. Cache fallback policy

`fetchWithCacheMerge` fail-closes on `401/403/404/409/422` and `REQUEST_ABORTED` (no stale mask). Transport/offline/5xx may return existing cache or empty list-shaped fallbacks for known key prefixes.

## 8. Demo auth production invariant

`isRealBackendOnlyMode()` is always true in production builds. Demo login / HTML demo fallback is denied. Non-production demo fallback remains gated and never treats auth-shaped invalid roles as demo success.

## 9. Telemetry privacy contract (diagnostic ≠ audit)

Owner: `src/logging/client-telemetry.ts` (+ `error-logger.ts` local buffer) and `ClientTelemetryController`.

- Client telemetry is **diagnostic only** — never an authoritative security/business audit trail.
- Server log `actor_user_id` is derived **only** from `AuthMiddleware::authenticate` (never from body).
- Client must **not** send `user_id` / `actor_user_id` (rejected: `TELEMETRY_CLIENT_ACTOR_FORBIDDEN`).
- Non-authoritative context only: `client_ui_profile`, `client_active_sube_id` (validated against auth sube scope when scope is non-empty).
- **Allowed wire fields**: event_type, fingerprint, codes, templates, status/method, app meta, attempt_count, client_* context.
- **Prohibited**: passwords, tokens, Authorization, Cookie, request/response bodies, TC/ad/telefon/maaş and other HR payloads.
- Central delivery: authenticated `POST /client-telemetry` (strict PHP allowlist, body size limits, `error_log` only — no DB). Pre-auth events stay in a bounded privacy-safe client buffer until login flush.
- Dedupe window + global rate limit; recursion mute on delivery failure. Schema v3 purges legacy unsafe local stores on startup.

## 10. Error recovery architecture

- **Root** `ErrorBoundary` (`rootLevel`) wraps providers → minimal safe fallback + reload (no blank screen).
- **Routes** `ErrorBoundary` inside providers → recoverable fallback; **Ana ekrana don** clears `hasError` and hard-navigates home so the tree remounts (Link-only lock forbidden).
- `GlobalErrorTelemetry` captures `window.onerror` / `unhandledrejection` with non-Error normalization via the same privacy-safe logger/sender.
- React `error_stack` and `component_stack` are stored separately (sanitized); UI never shows stacks.
