CODE_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: 9b4aac7919100c5421544824b0401e5046ab621e
CODE_MAIN_SHA: 63f8c9052ca15c3f311e158b41b0afda09d2b874

# Live / code pin (2026-09-07 hosting-incident local completion — SELECT-only; mutation=0)

CODE_MAIN: `63f8c905` = PR **#274 MERGED** (CI PASS). Deploy of that head is **HELD**.
LIVE production SHA: `9b4aac79` (last successful deploy; older than CODE_MAIN).
HOSTING: EXTERNAL_PROVIDER_INCIDENT / CONTROL_PLANE_DEGRADED — no credential mutation yet.
Migration tip live + code: **087** / pending **EMPTY**.

## Hard-closed (do not reopen without new contradiction)

PR_271: CLOSED
PR_272: CLOSED
PR_274: MERGED / CI PASS / DEPLOY_HELD_ON_HOSTING_INCIDENT (do not reopen code unless concrete regression)
MIGRATION_087: APPLIED / tip=087 / pending EMPTY
PERSONNEL_202_208_HISTORICAL_EXIT: CLOSED_CONFIRMED
A2_LOCATION_160_211: CLOSED (Sedanur Bulut / Zeynep Günal → calisma_lokasyonu_id=5 applied prior; reopen YOK)
MEDISA_WORK_LOCATION_CATALOG_MAPPING: APPLIED (7/7)
MEDISA_APPROVED_USER_GRANTS: APPLIED
KARYAPI_ROLLOUT: INTENTIONAL_DEFER
SENAY_ROLLOUT: INTENTIONAL_DEFER
QR_SELF_SERVICE: INTENTIONAL_DEFER
ACL_SUBE_MUHASEBE_ROWS: 0 intentional (restriction disabled while empty)

## A1 — SGK period policy sube 12 / 13

SGK_PERIOD_OWNER_RUNTIME: SgkSirketPolitikaReadService::resolveForPeriod (branch-scoped; no company inheritance)
SGK_PERIOD_OWNER_WRITE: SgkSirketPolitikaWriteService::import → submit → approve (dual-control via SgkKararPaketiAuthz)
A1_STATUS: PAUSED_PENDING_HOST_RECOVERY_AND_FORMAL_ACTOR_SELECTION
A1_CLASSIFICATION: PRODUCTION_CONFIG_WAITING
A1_CAN_BE_DONE_WITH_EXISTING_OWNER: YES (after host recovery + explicit approval)
BRANCH_12_CURRENT: NO_APPROVED_POLICY (until A1 apply)
BRANCH_13_CURRENT: NO_APPROVED_POLICY (until A1 apply)
LOCKED_POLITIKA_HASH_12: 43e3a75e2c3f4c5f6eef72b2036d9498c4c847c9594923bab9e61691da4559af
LOCKED_POLITIKA_HASH_13: c155365fb2670836ff6388248fd755634b832454872ed441efa8b94372d4b4bb
PREPARER: sedanurB (actor_identity VERIFIED; prepare YES; approve NO; needs explicit user_subeler 12/13)
APPROVER: TBD from candidates 343 / 220 / 017 / 349 after live RO verify (005/004 Şenay-linked EXCLUDED unless new evidence)
APPLIED_THIS_TURN: NO
NO_APPLY_PLAN: ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json
APPROVER_RO_PROBE: ops/sgk/a1-approver-candidate-ro-probe.sql + scripts/ops/a1-approver-candidate-ro-probe.mjs

### Exact recovery sequence (after hosting recovers) — NO execute now

1. verify PR274 live (LIVE_SHA == CODE_MAIN `63f8c905…`)
2. read-only approver identification (candidates 343/220/017/349)
3. user chooses approver
4. explicit user approval for config writes
5. add sedanurB explicit 12/13
6. create/verify/bind approver actor identity if needed
7. add approver explicit 12/13
8. dual-control preflight
9. import 12 (hash lock 43e3a75e…)
10. submit 12
11. different actor approve 12
12. import 13 (hash lock c155365f…)
13. submit 13
14. different actor approve 13
15. exact readback
16. A1 CLOSED

## Residuals requiring business decision (not local code)

PERSONEL_212: BUSINESS_DECISION_REQUIRED (null sube + null location; no auto target)
NULL_LOCATION_HR_RESIDUALS: BUSINESS_DECISION_REQUIRED (remaining after A2 close)
CROSS_COMPANY_AXIS_PAIRS 120/158/219: INTENTIONAL_DEFER / DEFERRED_REVIEW (not DATA_CONTRADICTION)
SUBE_YONETICISI coverage gaps Medisa 1,5,6,12,13: BUSINESS_DECISION_REQUIRED
PERSONNEL_DECISION_PACK: ops/organization-mapping/personnel-residual-decision-pack.json

## FK / integrity (last authoritative RO inventory; not re-probed this turn)

FK_REFERENCE_INTEGRITY: PASS (all INVALID_REF=0 at inventory `34057486092` / related probes)
TECHNICAL_ANA_SISTEM: KAPALI
BUG_COUNT: 0
PRODUCTION_MUTATION_THIS_PIN: 0
