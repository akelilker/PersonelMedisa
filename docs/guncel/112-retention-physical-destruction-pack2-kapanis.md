# 112 — Retention Physical Destruction Pack 2 (kapanış / partial)

**Branch:** `fix/retention-physical-destruction`  
**Gap:** `MG-RET-PHYS-001` — **still CODE_GAP** (framework + executable handlers shipped; mandatory policy categories unresolved)  
**HTTP defer:** `MG-DEF-RET-HTTP-001` — **CLOSED** (evaluate + execute routes added)  
**Baseline main:** `636615aec88f9562b640da34f701c0ae362b4258`

---

## Summary

| Item | Result |
| --- | --- |
| Executor framework | **Shipped** (`PhysicalDestructionService` + handler registry) |
| Handler version | `RETENTION_PHYSICAL_V1` |
| Feature flag | `retention_physical_destruction_enabled` / `MEDISA_RETENTION_PHYSICAL_DESTRUCTION_ENABLED` — **default OFF** |
| Migration | `059_retention_physical_destruction_execution.sql` (additive evidence table) |
| Production migration apply | **NO** |
| Production feature enable | **NO** |
| Production data write / anonymize | **NO** |
| Plan / execute split | Plan (PII-free) + `plan_hash` SHA256 gate |
| Idempotency | `retention_imha_executionlari` UNIQUE(`imha_talep_id`) |
| MG-RET-PHYS-001 | **CODE_GAP** (policy blockers remain) |

---

## Execution contract

1. `GET /retention/imha-talepleri/{id}/evaluate` — eligibility + plan (no physical mutation)
2. `POST /retention/imha-talepleri/{id}/execute` — body: `expected_plan_hash`, `execution_nonce`, `confirmation=DESTROY_APPROVED_REQUEST`
3. Gates (all fail-closed): auth `retention.destruction.execute` + GENEL_YONETICI, feature flag, APPROVED request, maturity, legal hold, source fingerprint, manifest integrity, snapshots, plan hash, request `FOR UPDATE`, execution UNIQUE

---

## Category matrix (Pack 2)

| Category | Mode | Status |
| --- | --- | --- |
| ISE_GIRIS_CIKIS | DELETE_ROWS (`qr_attendance_events`) | **SUPPORTED** |
| PERSONEL_BELGE | DELETE_FILE_AND_METADATA | **SUPPORTED** |
| PERSONEL_OZLUK | ANONYMIZE_FIELDS + last-stage gate | **SUPPORTED** |
| IZIN | DELETE_ROWS (surec) | **SUPPORTED** |
| OLAY | ANONYMIZE_FIELDS (vaka olay) | **SUPPORTED** |
| SAVUNMA | COMPOSITE (savunma fields + linked belge) | **SUPPORTED** |
| ONAY_AUDIT (typed `qr_pc_decision`) | DELETE_ROWS chain leaf-first | **SUPPORTED** |
| ONAY_AUDIT (generic parent) | POLICY | **POLICY_DECISION_REQUIRED** |
| PUANTAJ | POLICY | **POLICY_DECISION_REQUIRED** |
| BORDRO | POLICY | **POLICY_DECISION_REQUIRED** |
| SGK_EKSIK_GUN | POLICY | **POLICY_DECISION_REQUIRED** |
| FAZLA_CALISMA | POLICY | **POLICY_DECISION_REQUIRED** |
| SERBEST_ZAMAN | POLICY | **POLICY_DECISION_REQUIRED** |
| DISIPLIN | POLICY | **POLICY_DECISION_REQUIRED** |
| RAPOR | POLICY | **POLICY_DECISION_REQUIRED** |
| IS_KAZASI | POLICY | **POLICY_DECISION_REQUIRED** |

Fail-closed code for policy: `DESTRUCTION_HANDLER_POLICY_UNRESOLVED`.

---

## Post-destruction manifest semantics

Live source may return `TARGET_NOT_FOUND` after destroy.  
Durable proof is `retention_imha_executionlari` (`DESTROYED_AS_APPROVED`).  
Do not force live integrity recompute against absent source when EXECUTED evidence exists.  
Request/audit/execution rows are never deleted by handlers.

---

## Ops runbook (NOT executed this round)

1. Exact SHA + CI green  
2. Production DB backup  
3. Approve apply of migration `059`  
4. Schema read-back  
5. Feature remains OFF  
6. Separate human dual-control enablement  
7. Real business destruction only after enable + dual step  

---

## Public repo

No PII, production credentials, or raw business records in fixtures/docs.
