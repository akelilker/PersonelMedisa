# 108 — S3E QR Interval → Daily Puantaj Evidence Candidate (Read Model)

**Branch:** `feat/qr-puantaj-candidate-projection`  
**Baseline main:** `3b47f09c22346498449e54a9d20612dfca2a7155` (S3D closed / PR #146 merged)  
**Status:** Draft implementation — **no production write / no migration 058 / no merge / no deploy**

---

## Purpose

Recomputable **operational read model**: QR intervals → daily puantaj **evidence candidates** compared against existing canonical `gunluk_puantaj`.

```
qr_attendance_events → QR_INTERVAL_V1 → QR_PUANTAJ_CANDIDATE_V1 → compare canonical (read-only)
```

**Candidate ≠ canonical puantaj.** No apply. No decision persistence. No candidate DB table.

---

## Why no migration 058

S3D intervals and S3E candidates are deterministic / rebuildable from:

- raw events
- interval derivation (`QR_INTERVAL_V1`)
- existing canonical puantaj rows
- period state

Persistence deferred until S3F proves review/apply/audit need.

---

## Algorithm version

| Layer | Version |
|-------|---------|
| Interval derivation | `QR_INTERVAL_V1` (unchanged) |
| Daily candidate | `QR_PUANTAJ_CANDIDATE_V1` |

Owners:

| Concern | Owner |
|---------|-------|
| Pure daily grouping + classification | `QrPuantajCandidateProjectionService` |
| DB/read orchestration | `QrPuantajCandidateReadService` |
| Operational HTTP | `PuantajController::qrAdaylari` |
| Interval + boundary load (reuse) | `QrAttendanceIntervalReadService::loadEventsWithBoundaryContext` |

---

## Grouping anchor

Daily grouping uses **`interval.entry_local_date`** as `candidate_date` only.

This is a **candidate grouping anchor**, not canonical payroll/day attribution authority.

---

## Classifications

| Classification | Meaning |
|----------------|---------|
| `READY_SINGLE_INTERVAL` | One complete interval, zero anomaly, single branch, no cross-midnight |
| `REVIEW_MULTIPLE_INTERVALS` | Multiple complete intervals same anchor day — **not collapsed** to one span |
| `REVIEW_CROSS_MIDNIGHT` | Overnight pair under entry anchor — review required |
| `REVIEW_ANOMALY` | `MISSING_GIRIS` / `MISSING_CIKIS` / `BRANCH_MISMATCH` |
| `REVIEW_MULTIPLE_BRANCHES` | Complete intervals span multiple branches |

### Multiple intervals (critical)

Example `08:00–12:00` + `13:00–17:00`:

- `interval_count = 2`
- `qr_matched_seconds = 28800` (8h matched)
- `first_entry_at` / `last_exit_at` exposed in QR block
- **No** proposed canonical single span `08:00–17:00` (would falsely imply 9h worked span)

### Cross-midnight

Example `23:00 GIRIS → 07:00 CIKIS`:

- Anchored on entry local date
- `REVIEW_CROSS_MIDNIGHT`
- Evidence times may be shown; **no** auto canonical mapping

### No QR evidence

Empty raw history → `items = []`, `summary.qr_evidence_days = 0`.  
**No** absence / devamsızlık inference.

---

## Comparison status (separate from classification)

| Status | Meaning |
|--------|---------|
| `NO_CANONICAL_ROW` | No `gunluk_puantaj` row; safe single-interval proposal may exist |
| `MATCHES_CANONICAL_TIME` | Proposed HH:MM matches canonical (normalized) |
| `DIFFERS_CANONICAL_TIME` | Times differ — **no winner selected** |
| `NO_SAFE_TIME_PROPOSAL` | Multi-interval / anomaly / etc. |
| `PERIOD_REQUIRES_REVISION` | Sealed/locked period + change needed |
| `APPROVED_CORRECTION_PRESENT` | Active approved `GIRIS_CIKIS_DUZELTME` on date |

Time compare: QR local `HH:MM` vs canonical `VARCHAR` time (seconds ignored).

---

## Period / revision (read-only)

Period owner: **`PuantajDonemPeriodService`**

Read metadata owner: **`PuantajDonemPeriodService::resolveCanonicalWriteContext`** (parity mirror of `assertCanonicalWriteAllowed`).

### Period fields (separated)

| Field | Meaning |
|-------|---------|
| `state` | `ACIK` / `SEALED` / `REOPEN_PENDING` / `REOPENED` |
| `period_write_locked` | Raw period lock (`SEALED` / `REOPEN_PENDING` only) |
| `canonical_write_open` | Whether canonical upsert would be allowed **right now** |
| `canonical_write_block_code` | `PERIOD_LOCKED` or `ACTIVE_SNAPSHOT_MUST_BE_CANCELLED` or null |
| `revision_required` | **Candidate-level** — not equal to `period_write_locked` |

**Important:** `period_write_locked != revision_required`.

### Canonical write parity

| State | `period_write_locked` | `canonical_write_open` | `canonical_write_block_code` |
|-------|----------------------|------------------------|------------------------------|
| ACIK | NO | YES | null |
| SEALED | YES | NO | `PERIOD_LOCKED` |
| REOPEN_PENDING | YES | NO | `PERIOD_LOCKED` |
| REOPENED (no active snapshot) | NO | YES | null |
| REOPENED (active payroll snapshot) | NO | NO | `ACTIVE_SNAPSHOT_MUST_BE_CANCELLED` |

REOPENED + active snapshot: period is not “write locked”, but canonical write is still blocked until snapshot cancelled. **No revision hint** — reopen already exists.

### Candidate-level `revision_required`

True only when:

- safe single-interval proposal exists, **and**
- canonical mutation is implied (`PERIOD_REQUIRES_REVISION` comparison), **and**
- `period_write_locked == YES`

False when: canonical already matches, no safe proposal, anomaly/multi-interval/cross-midnight, approved correction present, or REOPENED+snapshot block.

Hints (`PUANTAJ_GIRIS_CIKIS_DUZELTME` / `GIRIS_CIKIS_DUZELTME`) only when `revision_required == true`.

### `future_action = DIRECT_PUANTAJ_REVIEW`

Only when canonical review is needed, `canonical_write_open == YES`, safe proposal exists, no correction ambiguity. Never when period locked, snapshot blocked, already matching, or no safe proposal.

- **No** revision/correction writes; **no** `correctionUret`

---

## Correction overlay discovery

| Item | Finding |
|------|---------|
| Revision owner | `RevizyonController` |
| Correction write owner | `RevizyonController::correctionUret` |
| Payroll overlay | `MaasHesaplamaCorrectionProjectionService` applies **`toplam_net_dakika`**, not entry/exit |
| Entry/exit effective overlay | **No reusable server-side owner** |
| S3E behavior | Flag `APPROVED_CORRECTION_PRESENT`; do not reinterpret corrections |

Frontend `revizyon-correction-overlay` is client-side for display; not authoritative for server candidate compare.

---

## Explicit non-goals

- `gunluk_puantaj` INSERT/UPDATE
- Late/early (`gec_kalma_dakika`, `erken_cikis_dakika`) from QR alone
- Absence / overtime / discipline / payroll effect
- `hareket_durumu`, `gun_tipi`, `dayanak`, `hesap_etkisi` writes
- Authoritative vardiya/shift schedule (no dedicated shift model found; `beklenen_giris_saati` on puantaj row is per-day canonical field only)

---

## Operational API

`GET /puantaj/qr-adaylari/{personelId}?from=YYYY-MM-DD&to=YYYY-MM-DD`

| Item | Value |
|------|-------|
| Permission | `puantaj.view` |
| Scope | Load personel → `SubeScope::assertPersonelAccess` |
| Default range | Current calendar month |
| Max range | **62** inclusive days |
| Self `/me` | **Not** exposed — manager/HR operational surface |

Roles with `puantaj.view`: `GENEL_YONETICI`, `BOLUM_YONETICISI`, `MUHASEBE`, `BIRIM_AMIRI`, `IK_SORUMLUSU`, `SISTEM_YONETICISI` (not `PERSONEL`).

---

## `auto_applicable` semantics

S3E **never applies**. `auto_applicable = true` only means structurally safe for a **future** S3F policy:

- exactly one complete interval
- zero anomaly
- no cross-midnight
- one branch
- valid proposed times
- no correction ambiguity
- canonical row must not differ

---

## UI

Operational UI deferred (optional). Backend correctness is gate. No Apply/Onay buttons in S3E.

Self-service QR history unchanged (`/me/qr-araliklari`).

---

## S3F handoff

S3F (`S3F_QR_PUANTAJ_CANDIDATE_REVIEW_APPLY`) will decide:

- human review owner
- apply permission
- open-period direct update vs sealed-period revision
- audit/persistence / migration 058 necessity

---

## Tests

- `tests/php/S3EQrPuantajCandidateTestRunner.php` (pure)
- `tests/php/S3EQrPuantajPeriodContextMysqlTestRunner.php` (MariaDB period parity + candidate semantics)
- `tests/unit/s3e-qr-puantaj-candidate.source.test.ts`
- S3D + S3C targeted regression runners

---

## Related

- [107 — S3D QR interval derivation](./107-s3d-qr-interval-derivation.md)
- [106 — S3C dynamic QR foundation](./106-s3c-dynamic-qr-attendance-foundation.md)
