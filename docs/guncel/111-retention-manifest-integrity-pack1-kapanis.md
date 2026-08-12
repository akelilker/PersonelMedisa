# 111 — Retention Manifest Integrity Pack 1 (kapanış)

**Branch:** `fix/retention-manifest-integrity`  
**Gaps closed:** `MG-RET-MAN-001`, `MG-RET-S3F-001`  
**Not closed:** `MG-RET-PHYS-001` (physical destruction executor — intentional)

---

## Summary

| Item | Result |
| --- | --- |
| Manifest creator coverage | **15/15** (`coverageMap`) |
| S3F ledger → ONAY_AUDIT fingerprint | **Wired** (typed `QR_PUANTAJ_CANDIDATE_DECISION`) |
| Migration | **NO** (053 schema sufficient) |
| Production write / migration apply / backfill run | **NO** |
| Physical destruction | **NOT_IMPLEMENTED** (fail-closed) |

---

## Manifest create matrix (new wiring)

| Category | Trigger | Create point |
| --- | --- | --- |
| PERSONEL_OZLUK / ISE_GIRIS_CIKIS | TERMINATION_DATE | `createPersonelLifecycleManifests` (existing) |
| PERSONEL_BELGE / IZIN / RAPOR / IS_KAZASI / DISIPLIN | TERMINATION_DATE | `createTerminationScopedManifests` via ISTEN_AYRILMA |
| OLAY / SAVUNMA | TERMINATION_DATE | `disiplin_vakalar` at ISTEN_AYRILMA |
| PUANTAJ (+ parent ONAY_AUDIT) | PERIOD_CLOSURE | `PuantajController` mühür / reseal |
| BORDRO (+ parent ONAY_AUDIT) | PERIOD_CLOSURE | `BordroOnIzlemeService::kesinlestir` |
| SGK_EKSIK_GUN | PERIOD_CLOSURE | `MaasHesaplamaSnapshotService` create (non-idempotent) |
| FAZLA_CALISMA / SERBEST_ZAMAN | PERIOD_CLOSURE | `HaftalikKapanisController::create` |
| ONAY_AUDIT (S3F typed) | PERIOD_CLOSURE (candidate_date) | `QrPuantajCandidateDecisionService::appendDecision` |

---

## S3F fingerprint material (server ledger row)

`id`, `personel_id`, `sube_id`, `candidate_date`, `candidate_hash`, `decision_type`, `decision_reason`, `puantaj_id`, `algorithm_version`, `interval_algorithm_version`, `decision_algorithm_version`, `decided_by_user_id`, `request_nonce`, `supersedes_decision_id`, `previous_decision_hash`, `decision_hash`, `created_at` (normalized).

Identity: `onay_audit:qr_pc_decision:{id}:parent:PUANTAJ:dh:{decision_hash}`

---

## Backfill

Historical sources sealed/decided before this wiring may lack manifests.  
**OPS_BACKFILL** required for production historical rows — dry-run / explicit apply / audit.  
Not implemented as auto migration; not run in this pack.

---

## Public repo

No PII, production credentials, or raw business records.
