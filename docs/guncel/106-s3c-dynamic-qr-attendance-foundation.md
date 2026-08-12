# 106 â€” S3C Dynamic Signed QR Attendance Foundation

**Branch:** `feat/dynamic-qr-attendance-foundation`
**Baseline:** `origin/main` = `0020f7dbf27322583785099258c8df687fbcb9ac` (S3B + PR #142 docs merge)
**Status:** S3C-R1 hardening on draft PR #145 â€” **no production secret / migration apply / merge / deploy**
**PR #142:** Merged on main (docs only); S3C does not rewrite `102` / `CURRENT_STATE` / `README`

---

## Locked model (from S3A D1â€“D6)

| ID | Decision |
|----|----------|
| D1 | `DYNAMIC_SIGNED` |
| D2 | `EXPLICIT_GIRIS_CIKIS` |
| D3 | Cross-branch `DENY` |
| D4 | Terminated/PASIF self-service `DENY_ALL` |
| D5 | Missing-scan correction reuses `GIRIS_CIKIS_DUZELTME` (later) |
| D6 | `AUTHENTICATED_KIOSK`; TTL default 60s (30â€“120) |

Retention category for QR raw evidence: **`ISE_GIRIS_CIKIS`** (Medisa saklama politikasÄ± / TERMINATION_DATE). Not PUANTAJ.

---

## Migration 057

File: `api/migrations/057_qr_attendance_events.sql`

- Table: `qr_attendance_events` (append-only)
- ENUM: `GIRIS` \| `CIKIS`
- `occurred_at_utc DATETIME(6)` â€” **server only**
- UNIQUEs: `(user_id, request_nonce)`, `(user_id, qr_jti, event_type)`
- Indexes: personel/user/sube + occurred_at
- FKs RESTRICT â†’ personeller / users / subeler
- No token table (stateless display tokens)
- No intervals / no gunluk_puantaj writes
- No backfill / no business manifest INSERT

052â€“056 immutable. 057 staged only (not production-applied).

---

## Token contract

- Codec: `mqr1.<payload>.<hmac>` (HMAC-SHA256)
- Secret: `qr_signing_secret` via `medisa_config` (example placeholder only; never commit real secret)
- Payload: `v`, `sube_id`, `iat`, `exp`, `jti` (128-bit hex)
- No personel/user/TC/role in token
- Missing secret â†’ QR endpoints `QR_CONFIG_NOT_READY` (503); rest of app OK
- TTL server-owned; invalid config â†’ default 60
- Expiry: `now >= exp` â†’ `QR_TOKEN_EXPIRED` (`exp == now` expired)

---

## Endpoints

| Method | Path | Permission |
|--------|------|------------|
| GET | `/qr-kiosk/token` | `yonetim-paneli.manage` |
| POST | `/me/qr-scan` | `self_service.qr.scan` |
| GET | `/me/qr-hareketleri` | `self_service.qr.events.view` |

No PUT/PATCH/DELETE for raw events. No admin other-person scan.

### History date contract (S3C-R1)

- Query `from` / `to` = **Europe/Istanbul business calendar YMD**
- Owner: `QrAttendanceEventService::businessDateRangeToUtc()`
- SQL: `occurred_at_utc >= fromUtc AND occurred_at_utc < toExclusiveUtc`
- `fromUtc` = Istanbul local midnight â†’ UTC (`DateTimeZone('Europe/Istanbul')`, no hardcoded `+03:00`)
- `toExclusiveUtc` = Istanbul `(to + 1 day)` midnight â†’ UTC
- Response `from`/`to` remain business YMD; max inclusive window 366 days

---

## Permissions

PERSONEL gains:

- `self_service.qr.scan`
- `self_service.qr.events.view`

Kiosk reuses `yonetim-paneli.manage` (GENEL_YONETICI / SISTEM_YONETICISI).
AUTH_SMOKE: no QR.

---

## Idempotency / replay

- Client `request_nonce` (UUID)
- Exact nonce retry â†’ same row / idempotent success
- Nonce semantic mismatch â†’ `QR_IDEMPOTENCY_CONFLICT`
- Same user+jti+event_type â†’ one row
- Same jti different users â†’ allowed
- Same user GIRIS+CIKIS same jti â†’ allowed
- Display token is **not** globally single-use

---

## Retention hardening

- `PERSONEL_OZLUK` fingerprint unchanged (`computePersonelOzlukFingerprint`)
- New PASIF transitions mint QR-aware ISE identity:
  `personel:{id}:ise_giris_cikis:termination:{date}` + QR raw fingerprint
- Pre-S3C legacy ISE identity remains discoverable for the **same termination lifecycle**:
  `personel:{id}:termination:{date}` with **ozluk** fingerprint semantics (no false missing-current / false CHANGED)
- QR fingerprint fields: id, personel_id, user_id, sube_id, event_type, occurred_at_utc, qr_version, qr_jti, qr_issued_at_utc, qr_expires_at_utc, `UNIX_TIMESTAMP(created_at)`, request_nonce
  Ordered `occurred_at_utc ASC, id ASC`
- Empty table vs missing table: distinct deterministic hashes (`:no_table` suffix when absent)
- No production backfill of manifests in 057
- Legal hold / TERMINATION_DATE trigger reused
- Physical destruction executor remains as existing Phase C state (not claimed complete by S3C)

---

## FE

- `/qr-kiosk` â€” authenticated rotating QR (`qrcode`)
- `/self/qr-okut` â€” camera scan (BarcodeDetector + dynamic `jsqr` fallback)
- `/self/qr-hareketleri` â€” raw history list
- No offline queue, no manual token paste, no optimistic success

## Dependencies

- `qrcode` (+ `@types/qrcode`)
- `jsqr` (dynamic import fallback)

---

## Explicit non-goals (S3D/S3E)

- Interval derivation
- Canonical puantaj write
- Missing-scan auto correction
- Production secret set / migration 057 apply / merge / deploy
