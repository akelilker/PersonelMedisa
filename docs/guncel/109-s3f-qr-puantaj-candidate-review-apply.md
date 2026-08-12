# 109 — S3F QR Puantaj Candidate Review + Controlled Apply V1

**Branch:** `feat/qr-puantaj-candidate-review-apply`  
**Baseline main:** `cfc633769888263e0f3600acaa36f855bca551c4` (S3E closed / PR #147)  
**Status:** DRAFT PR — **production migration 058 NOT applied**

---

## Purpose

Human review + append-only decision audit for QR daily puantaj evidence candidates, with a **narrow** direct apply path for existing canonical rows only.

```
QR_INTERVAL_V1 → QR_PUANTAJ_CANDIDATE_V1 → candidate_hash
  → human decision (APPLY_EXISTING | KEEP_CANONICAL | REOPEN_REVIEW)
  → qr_puantaj_candidate_decision_ledger (058)
  → optional narrow giris/cikis mutation on existing gunluk_puantaj
```

---

## Why migration 058 exists

S3E candidates were recomputable (no persistence). S3F introduces **human decision + apply audit**, which requires an append-only ledger.

| Migration | Role |
|-----------|------|
| 057 | Raw QR attendance events (immutable tip before S3F) |
| **058** | `qr_puantaj_candidate_decision_ledger` |

052–057 remain immutable. **Do not apply 058 to production** until explicit later approval + fresh pre-058 backup.

---

## Decision ledger

Table: `qr_puantaj_candidate_decision_ledger`

- Append-only (no UPDATE/DELETE business API)
- Unique idempotency: `(decided_by_user_id, request_nonce)`
- FK `ON DELETE RESTRICT` / `ON UPDATE RESTRICT`
- Server `decision_hash` (SHA-256) over material decision fields; `previous_decision_hash` chains same personel+date+candidate_hash

### Decision types (v1)

| Type | Canonical write? |
|------|------------------|
| `APPLY_EXISTING` | Yes — giris/cikis only (+ workflow reset) |
| `KEEP_CANONICAL` | No |
| `REOPEN_REVIEW` | No — only after KEEP on same hash |

No `AUTO_APPLY`, `CREATE_PUANTAJ`, `CREATE_REVISION`, `FIX_RAW_EVENT`.

---

## Candidate hash / stale protection

- Server-owned `candidate_hash` (`QR_CANDIDATE_HASH_V1`) on candidate GET
- Client **must not** compute it; must send it on karar
- Action endpoint recomputes candidate **inside transaction** after locks
- Mismatch → `409 QR_CANDIDATE_STALE` (no ledger insert, no puantaj write)

Hash covers material decision state (personel, date, algorithms, classification, comparison, proposed times, canonical id/times/state/kontrol/updated_at, period write context, correction flag, source event ids/counts). Cosmetic UI labels excluded.

---

## APPLY_EXISTING policy

Allowed only when **all**:

- `READY_SINGLE_INTERVAL`
- existing `gunluk_puantaj` row
- `DIFFERS_CANONICAL_TIME`
- safe proposed giris/cikis
- `canonical_write_open`
- no approved `GIRIS_CIKIS_DUZELTME` ambiguity
- review state permits apply (not KEEP-active)
- dependent-field guard PASS
- `puantaj.update` + SubeScope

### No-row prohibition

`NO_CANONICAL_ROW` → no INSERT. Code: `QR_APPLY_REQUIRES_EXISTING_PUANTAJ_ROW`.  
Capability: `MANUAL_PUANTAJ_CREATE_REVIEW_REQUIRED`. UI may navigate to normal puantaj edit — QR does not author gun_tipi / hareket / dayanak / hesap / late-early / OT / absence.

### Dependent-field guard

Changing entry/exit without authoritative shift-based recalculation would stale derived fields. Guard blocks apply if any of these is non-null:

- `gec_kalma_dakika`, `erken_cikis_dakika`
- `gercek_mola_dakika`, `hesaplanan_mola_dakika`
- `net_calisma_suresi_dakika`, `gunluk_brut_sure_dakika`
- `tatil_donemi_brut_calisma_dakika`, `tatil_donemi_ara_dinlenme_dakika`, `tatil_donemi_net_calisma_dakika`

Code: `QR_APPLY_DEPENDENT_FIELDS_REQUIRE_MANUAL_REVIEW`  
No silent clear / no invented recalculation.

### Canonical mutation (narrow)

May change only:

- `giris_saati`, `cikis_saati`
- `kontrol_durumu` → `BEKLIYOR`
- `state` → `ACIK`
- `muhur_id` → `NULL`

Does **not** change gun_tipi, hareket_durumu, dayanak, hesap_etkisi, beklenen_*, kaynak, aciklama, SGK/holiday classification, or derived duration fields.

---

## KEEP_CANONICAL / REOPEN_REVIEW

- KEEP: existing row + ready single + safe proposed + differs (or period revision required); reason mandatory (5–1000); **no** puantaj write; allowed while ACIK / SEALED / REOPEN_PENDING / REOPENED
- Same `candidate_hash` KEEP → apply disabled until `REOPEN_REVIEW`
- New QR/canonical material → new hash → old KEEP does not suppress

---

## Period / revision

| Situation | APPLY | KEEP |
|-----------|-------|------|
| SEALED / REOPEN_PENDING | No — `REVISION_REQUIRED` | Yes (if structural) |
| REOPENED, no active snapshot | Yes (if else OK) | Yes |
| REOPENED + active payroll snapshot | No — `ACTIVE_SNAPSHOT_MUST_BE_CANCELLED` | Yes (if structural) |

No automatic revision create/submit/approve. Existing revizyon workflow remains owner. UI may link to revizyon merkezi.

Approved correction present → no apply / keep / reopen competing decision.

---

## Permissions

| Action | Permission |
|--------|------------|
| Candidate GET + karar history | `puantaj.view` |
| APPLY / KEEP / REOPEN | `puantaj.update` |

No new permission. RolePermissions matrix **unchanged**.

`puantaj.update` roles: **GENEL_YONETICI**, **BOLUM_YONETICISI** only.  
No apply authority for IK_SORUMLUSU, BIRIM_AMIRI, MUHASEBE, SISTEM_YONETICISI, PERSONEL.

---

## API

- `GET /puantaj/qr-adaylari/{personelId}` — S3E owner + `candidate_hash` + `review` overlay
- `POST /puantaj/qr-adaylari/{personelId}/{candidateDate}/karar` — body allow-list: `action`, `candidate_hash`, `request_nonce`, `gerekce`
- `GET /puantaj/qr-adaylari/{personelId}/{candidateDate}/kararlar` — audit history

Idempotency: same user+nonce+payload → `idempotent: true`. Different payload same nonce → `409 IDEMPOTENCY_CONFLICT`.

Locks: period `acquireForDate` + canonical row `FOR UPDATE`; APPLY re-asserts `assertCanonicalWriteAllowed`.

---

## Retention

Preferred classification: **`ONAY_AUDIT`** with `parent_category = PUANTAJ`.

Compatible with existing `RetentionCategories` / `RetentionPeriodTriggerResolver` (ONAY_AUDIT inherits parent period-closure trigger). No new retention category. No legal-hold change.

**RETENTION_GAP (documented, not invented):** Phase-C `ArchiveManifestService` manifest creator is still only wired for PERSONEL_OZLUK / ISE_GIRIS_CIKIS; ONAY_AUDIT (and this ledger table) are not yet included in archive fingerprint manifests. Policy/trigger classification is valid; destruction executor work is out of scope. Do not claim full archive coverage for this table yet.

---

## No inference

S3F does **not** calculate late/early, absence, overtime, discipline, payroll, SGK, salary snapshot, annual leave, or mutate raw QR events.

---

## Owners

| Concern | Owner |
|---------|-------|
| Hash | `QrPuantajCandidateHashService` |
| Review overlay / gates | `QrPuantajCandidateDecisionPolicy` |
| Ledger | `QrPuantajCandidateDecisionLedgerService` |
| Narrow apply mutation | `QrPuantajCandidateApplyService` |
| Orchestration | `QrPuantajCandidateDecisionService` |
| HTTP | `PuantajController::qrAdaylari` / `qrAdayKarar` / `qrAdayKararlar` |
| UI | `QrPuantajAdayiSection` on `GunlukPuantajPage` |

Algorithm versions: `QR_PUANTAJ_CANDIDATE_V1`, `QR_INTERVAL_V1`, decision `QR_PUANTAJ_DECISION_V1`.

---

## Production

**NOT applied.** Ends at draft PR + exact-head CI. No prod DB write / backup-yet / merge / deploy.
