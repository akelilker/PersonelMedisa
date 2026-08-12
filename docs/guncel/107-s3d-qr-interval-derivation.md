# 107 — S3D QR Raw Events → Deterministic Interval Derivation

**Branch:** `feat/qr-attendance-interval-derivation`  
**Baseline main:** `db7df5d499d29845098bc7238b45d8279092aa50` (S3C closed / PR #145 merged)  
**Status:** **CLOSED PRODUCTION** (PR #146 merged @ `3b47f09c…`). S3E started on branch `feat/qr-puantaj-candidate-projection` — see [108](./108-s3e-qr-puantaj-candidate-projection.md).

---

## Purpose

Recomputable **read projection** of QR attendance intervals from append-only `qr_attendance_events`.

```
RAW (057) → QR_INTERVAL_V1 derivation → self read model
```

No persisted interval table. No `gunluk_puantaj` write. No late/early/overtime/revision write.

---

## Algorithm version

`QR_INTERVAL_V1`

Owners:

| Concern | Owner |
|---------|-------|
| Pure pairing | `QrAttendanceIntervalDerivationService::derive` |
| Range + previous/next context | `QrAttendanceIntervalReadService::listForSelf` |
| Business date → UTC | reuse `QrAttendanceEventService::businessDateRangeToUtc` |
| Schema gate | reuse `QrAttendanceSchema` / `QR_SCHEMA_NOT_READY` |

Ordering (canonical):

`occurred_at_utc ASC`, `id ASC`

Pairing identity: **`personel_id`** (not `user_id`).

---

## State machine (locked)

| State | Event | Result |
|-------|-------|--------|
| NONE + GIRIS | open = GIRIS |
| OPEN + CIKIS (same sube) | COMPLETE interval; open = NONE |
| OPEN + GIRIS | prior → `MISSING_CIKIS`; open = new GIRIS |
| NONE + CIKIS | `MISSING_GIRIS` |
| stream end + OPEN | `MISSING_CIKIS` |
| OPEN + CIKIS (different sube) | `BRANCH_MISMATCH`; both consumed; open = NONE |

No synthetic events. No auto-toggle. No max-duration auto-rejection. No midnight split.

---

## Cross-midnight

Explicit overnight pair stays one COMPLETE interval:

- `entry_local_date` / `exit_local_date`
- `spans_local_midnight = true`
- Canonical daily attribution is **out of scope** (S3E)

---

## Range / boundary context

Self range max: **366** inclusive business days (Europe/Istanbul).

Load:

1. in-range events
2. immediately previous raw event (`occurred_at_utc < from_utc`)
3. immediately next raw event (`occurred_at_utc >= to_exclusive_utc`)

Filter after derive:

- COMPLETE if `entry_local_date ∈ [from,to]`
- `MISSING_CIKIS` / `BRANCH_MISMATCH` if entry/local date in range
- `MISSING_GIRIS` if CIKIS local date in range

Prevents false orphan CIKIS when previous GIRIS is just outside the window.

---

## Self API

`GET /me/qr-araliklari?from=&to=`

- Permission: `self_service.qr.events.view` (PERSONEL)
- Identity: `SelfPersonelContext` only — no client `personel_id` / `user_id`
- Raw history `GET /me/qr-hareketleri` unchanged

Response highlights:

- `algorithm_version`
- `intervals[]` with `entry_event_id` / `exit_event_id` / `duration_seconds`
- `anomalies[]` with `correction_hint = GIRIS_CIKIS_DUZELTME`
- `summary` + `source_event_count` / `source_max_event_id`

UI copy: **“QR Eşleşmeleri” / “QR eşleşme süresi”** — not canonical “çalışılan süre”.

---

## Anomalies → future correction (discovery only)

| Field | Value |
|-------|-------|
| MISSING_SCAN_CORRECTION_OWNER | `RevizyonController` + weekly close revision tables |
| MISSING_SCAN_CORRECTION_TYPE | Revizyon tip `PUANTAJ_GIRIS_CIKIS_DUZELTME` → correction `GIRIS_CIKIS_DUZELTME` |
| CURRENT_APPROVAL_CHAIN | Create/submit by scoped managers; **approve / `correctionUret`** requires `revizyon.approve` (**GENEL_YONETICI**) |
| S3E_INTEGRATION_POINT | After ONAYLANDI + correction-uret overlay into puantaj candidate path — **not wired in S3D** |

S3D does not create/submit/approve/apply revisions.

---

## Explicit non-goals

- Migration 058 / interval DB table
- Canonical day allocation / midnight split
- Vardiya / schedule assumptions
- Late/early / absence / overtime / discipline effects
- Interval retention category (raw ISE_GIRIS_CIKIS unchanged)

---

## S3E handoff

S3E receives:

- complete intervals + anomalies
- raw event provenance ids
- local dates + duration seconds
- branch snapshots

S3E decides:

- daily attribution
- late/early / net work / absence / overtime
- candidate persistence
- revision effects

---

## Tests

- `tests/php/S3DQrIntervalDerivationTestRunner.php` (pure)
- `tests/php/S3DQrIntervalRangeMysqlTestRunner.php` (MariaDB + 057)
- `tests/unit/s3d-qr-interval-derivation.source.test.ts`
- S3C token / business-date / 057 runners as regression
