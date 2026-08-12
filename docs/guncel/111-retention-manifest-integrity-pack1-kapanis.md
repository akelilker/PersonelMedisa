# 111 — Retention Manifest Integrity Pack 1 (kapanış)

**Branch:** `fix/retention-manifest-integrity`  
**Gaps closed:** `MG-RET-MAN-001`, `MG-RET-S3F-001`  
**Not closed:** `MG-RET-PHYS-001` (physical destruction executor — intentional)
**Hardening:** PR #150 follow-up (decision_hash recompute + required schema)

---

## Summary

| Item | Result |
| --- | --- |
| Manifest creator coverage | **15/15** (coverageMap + category MySQL creator tests) |
| S3F ledger → ONAY_AUDIT fingerprint | **Wired** + **decision_hash recomputed/verified** before FP |
| JSON snapshot tamper | **Fail-closed** (`ARCHIVE_SOURCE_INTEGRITY_CHANGED`) |
| Schema-required lifecycle | **Fail-closed** (`requireManifestSideEffect`) |
| Migration | **NO** (053 schema sufficient) |
| Production write / migration apply / backfill run | **NO** |
| Physical destruction | **NOT_IMPLEMENTED** (fail-closed) |

---

## S3F integrity (hardening)

1. Ledger row loaded server-side by `ledger_id`.
2. `QrPuantajCandidateDecisionLedgerService::verifyDecisionHash` recomputes from canonical material including:
   - `candidate_snapshot`
   - `before_puantaj_snapshot`
   - `after_puantaj_snapshot`
   - and other decision-hash fields
3. Verify false → retention resolve throws `ARCHIVE_SOURCE_INTEGRITY_CHANGED` (never OK).
4. Only after verify: retention fingerprint uses verified `decision_hash` + typed identity material.
5. Decision txn + manifest mint are **atomic**: missing `arsiv_manifestleri` → `SCHEMA_NOT_READY` → rollback (no ledger commit).

---

## Schema-required Pack1 lifecycle contract

| Owner | Missing 053 behavior |
| --- | --- |
| S3F decide | rollback (no ledger) |
| Puantaj mühür/reseal | rollback |
| Haftalık kapanış | rollback |
| Bordro KESINLESTI | rollback (MySQL host) |
| SGK snapshot create | rollback (MySQL host) |
| ISTEN_AYRILMA | rollback (no Pre-053 swallow) |

`runIfSchemaReady` is **deprecated** for Pack1 lifecycle wires.
Isolated SQLite payroll unit runners use `isLifecycleRetentionHost` (sqlite ≠ retention host) — explicit test isolation, not production soften.

---

## Manifest create matrix

| Category | Trigger | Create point |
| --- | --- | --- |
| PERSONEL_OZLUK / ISE_GIRIS_CIKIS | TERMINATION_DATE | `createPersonelLifecycleManifests` |
| PERSONEL_BELGE / IZIN / RAPOR / IS_KAZASI / DISIPLIN | TERMINATION_DATE | `createTerminationScopedManifests` |
| OLAY / SAVUNMA | TERMINATION_DATE | `disiplin_vakalar` |
| PUANTAJ (+ parent ONAY_AUDIT) | PERIOD_CLOSURE | mühür / reseal |
| BORDRO (+ parent ONAY_AUDIT) | PERIOD_CLOSURE | KESINLESTI |
| SGK_EKSIK_GUN | PERIOD_CLOSURE | snapshot create |
| FAZLA_CALISMA / SERBEST_ZAMAN | PERIOD_CLOSURE | haftalık KAPANDI |
| ONAY_AUDIT (S3F typed) | PERIOD_CLOSURE | decision append (same txn) |

Post-termination source mutation → integrity CHANGED / missing current lifecycle; baseline not overwritten.

---

## Backfill

Historical sources sealed/decided before this wiring may lack manifests.  
**OPS_BACKFILL** — dry-run / explicit apply / audit. Not run in this pack.

---

## Public repo

No PII, production credentials, or raw business records.
