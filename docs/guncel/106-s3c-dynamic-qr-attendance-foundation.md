# 106 — S3C Dynamic Signed QR Attendance Foundation

**Branch:** `feat/dynamic-qr-attendance-foundation`  
**Baseline:** `origin/main` = `322cd732988c1a967ccdfa398cee6b209eedfe95` (S3B / PR #144 merged)  
**Status:** Implementation complete on draft PR — **no production secret / migration apply / merge / deploy**  
**PR #142:** Untouched

---

## Locked model (from S3A D1–D6)

| ID | Decision |
|----|----------|
| D1 | `DYNAMIC_SIGNED` |
| D2 | `EXPLICIT_GIRIS_CIKIS` |
| D3 | Cross-branch `DENY` |
| D4 | Terminated/PASIF self-service `DENY_ALL` |
| D5 | Missing-scan correction reuses `GIRIS_CIKIS_DUZELTME` (later) |
| D6 | `AUTHENTICATED_KIOSK`; TTL default 60s (30–120) |

Retention category for QR raw evidence: **`ISE_GIRIS_CIKIS`** (Medisa saklama politikası / TERMINATION_DATE). Not PUANTAJ.

---

## Migration 057

File: `api/migrations/057_qr_attendance_events.sql`

- Table: `qr_attendance_events` (append-only)
- ENUM: `GIRIS` \| `CIKIS`
- `occurred_at_utc DATETIME(6)` — **server only**
- UNIQUEs: `(user_id, request_nonce)`, `(user_id, qr_jti, event_type)`
- Indexes: personel/user/sube + occurred_at
- FKs RESTRICT → personeller / users / subeler
- No token table (stateless display tokens)
- No intervals / no gunluk_puantaj writes
- No backfill

052–056 immutable.

---

## Token contract

- Codec: `mqr1.<payload>.<hmac>` (HMAC-SHA256)
- Secret: `qr_signing_secret` via `medisa_config` (example placeholder only; never commit real secret)
- Payload: `v`, `sube_id`, `iat`, `exp`, `jti` (128-bit hex)
- No personel/user/TC/role in token
- Missing secret → QR endpoints `QR_CONFIG_NOT_READY` (503); rest of app OK
- TTL server-owned; invalid config → default 60

---

## Endpoints

| Method | Path | Permission |
|--------|------|------------|
| GET | `/qr-kiosk/token` | `yonetim-paneli.manage` |
| POST | `/me/qr-scan` | `self_service.qr.scan` |
| GET | `/me/qr-hareketleri` | `self_service.qr.events.view` |

No PUT/PATCH/DELETE for raw events. No admin other-person scan.

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
- Exact nonce retry → same row / idempotent success
- Nonce semantic mismatch → `QR_IDEMPOTENCY_CONFLICT`
- Same user+jti+event_type → one row
- Same jti different users → allowed
- Same user GIRIS+CIKIS same jti → allowed
- Display token is **not** globally single-use

---

## Retention hardening

- `PERSONEL_OZLUK` fingerprint unchanged (`computePersonelOzlukFingerprint`)
- `ISE_GIRIS_CIKIS` now uses `computeIseGirisCikisFingerprint` over QR rows (deterministic empty-state when 0 rows / table absent)
- Legal hold / TERMINATION_DATE trigger reused
- Physical destruction executor remains as existing Phase C state (not claimed complete by S3C)

---

## FE

- `/qr-kiosk` — authenticated rotating QR (`qrcode`)
- `/self/qr-okut` — camera scan (BarcodeDetector + dynamic `jsqr` fallback)
- `/self/qr-hareketleri` — raw history list
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
