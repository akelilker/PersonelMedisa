# Core data security, offline correctness, and telemetry semantics

Canonical decisions for PersonelMedisa frontend core hardening (branch `feat/core-data-security-hardening`).

## 1. Protected cache ownership

`AppData` and sync-queue items carry `ownerFingerprint` derived from `getActorFingerprint(session)` (`userId|role|sorted sube_ids`). Actor mismatch on load/ensure **purges** protected localStorage (`medisa_app_data`, `medisa_sync_queue`) and resets in-memory cache. Cross-user cache reuse is denied.

## 2. Offline queue state machine

Queue items are stateful: `PENDING | PROCESSING | COMPLETED | FAILED_RETRYABLE | BLOCKED_AUTH | CONFLICT | DEAD_LETTER`.

- `401/403` → `BLOCKED_AUTH` (no silent delete)
- `409/422` → `CONFLICT`
- retryable transport/5xx → `FAILED_RETRYABLE` until `MAX_SYNC_ATTEMPTS` → `DEAD_LETTER`
- success → `COMPLETED`

Quota failure on enqueue returns `"quota-error"` to the caller.

## 3. Mutation idempotency

Frontend owner: offline queue item `id` passed as `idempotencyKey` → `Idempotency-Key` header. Transport **never mints** keys. Backend ledger for generic offline replay is not introduced in this phase; domain-specific idempotency tables elsewhere remain unchanged. No new migration for this plumbing.

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

## 9. Telemetry privacy contract

Owner: `src/logging/client-telemetry.ts` (+ `error-logger.ts` local buffer).

- **Allowed**: event_type, fingerprint, codes, templates, status/method, app meta, opaque user_id / active_sube_id / ui_profile, attempt_count.
- **Prohibited**: passwords, tokens, Authorization, Cookie, request/response bodies, TC/ad/telefon/maaş and other HR payloads.
- Central delivery: authenticated `POST /client-telemetry` (strict PHP allowlist, body size limits, `error_log` only — no DB). Pre-auth events stay in a bounded privacy-safe client buffer until login flush.
- Dedupe window + global rate limit; recursion mute on delivery failure. Schema v3 purges legacy `medisa_client_errors` / `medisa_client_api_fails` on startup.

## 10. Error recovery architecture

- **Root** `ErrorBoundary` (`rootLevel`) wraps providers → minimal safe fallback + reload (no blank screen).
- **Routes** `ErrorBoundary` inside providers → recoverable fallback; **Ana ekrana don** clears `hasError` and hard-navigates home so the tree remounts (Link-only lock forbidden).
- `GlobalErrorTelemetry` captures `window.onerror` / `unhandledrejection` with non-Error normalization via the same privacy-safe logger/sender.
- React `error_stack` and `component_stack` are stored separately (sanitized); UI never shows stacks.
