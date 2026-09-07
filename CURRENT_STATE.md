CODE_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: 9b4aac7919100c5421544824b0401e5046ab621e
CODE_MAIN_SHA: 63f8c9052ca15c3f311e158b41b0afda09d2b874

# Live / code pin (2026-09-07 BUSINESS_TRUTH_AND_BRANCH_MANAGER_MODEL_LOCK — SELECT-only; mutation=0)

CODE_MAIN: `63f8c905` = PR **#274 MERGED** (CI PASS). Deploy of that head is **HELD**.
LIVE production SHA: `9b4aac79` (last successful deploy; older than CODE_MAIN).
HOSTING: EXTERNAL_PROVIDER_INCIDENT / CONTROL_PLANE_DEGRADED — no credential mutation yet.
Migration tip live + code: **087** / pending **EMPTY**.
PHASE: BUSINESS_TRUTH_AND_BRANCH_MANAGER_MODEL_LOCK
APPLIED_THIS_TURN: NO

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
A1_STATUS: PAUSED_PENDING_HOST_RECOVERY_AND_SINEM_LIVE_VERIFY
A1_CLASSIFICATION: PRODUCTION_CONFIG_WAITING
A1_CAN_BE_DONE_WITH_EXISTING_OWNER: YES (after host recovery + live Sinem verify + explicit approval)
A1_PAIR_STATUS: BUSINESS_LOCKED_TEMPORARY_ASSIGNMENT
BRANCH_12_CURRENT: NO_APPROVED_POLICY (until A1 apply)
BRANCH_13_CURRENT: NO_APPROVED_POLICY (until A1 apply)
LOCKED_POLITIKA_HASH_12: 43e3a75e2c3f4c5f6eef72b2036d9498c4c847c9594923bab9e61691da4559af
LOCKED_POLITIKA_HASH_13: c155365fb2670836ff6388248fd755634b832454872ed441efa8b94372d4b4bb
PREPARER: sedanurB (role IK_SORUMLUSU; actor_identity VERIFIED; prepare YES; approve NO; needs explicit user_subeler 12/13)
APPROVER: Sinem Hamaloğlu (intended formal SGK approver role/model = BOLUM_YONETICISI; temporary business assignment; changeable later)
APPROVER_LIVE_IDENTITY: VERIFY_LIVE_REQUIRED (do not guess username/user_id/personel_id/role/actor/scopes from hosting-blocked live)
APPROVER_LOCAL_KEYS_HISTORICAL: user_id=110 / personel_id=173 / last closed username=sinemH — re-verify live before any write
APPLIED_THIS_TURN: NO
NO_APPLY_PLAN: ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json
APPROVER_RO_PROBE: ops/sgk/a1-approver-candidate-ro-probe.sql + scripts/ops/a1-approver-candidate-ro-probe.mjs
CANONICAL_MODEL_DOC: docs/guncel/141-business-truth-and-branch-manager-model-lock.md

### Exact recovery sequence (after hosting recovers) — NO execute now

1. verify PR274 live (LIVE_SHA == CODE_MAIN `63f8c905…`)
2. live verify Sinem exact identity/account/role (keys + actor + scopes)
3. verify Sinem BOLUM_YONETICISI approval eligibility for formal SGK
4. explicit user approval for actor/scope config
5. add sedanurB explicit branch 12/13
6. create/verify/bind Sinem actor identity if required
7. add Sinem explicit branch 12/13
8. dual-control readiness
9. import 12 (hash lock 43e3a75e…)
10. submit 12
11. Sinem approve 12
12. import 13 (hash lock c155365f…)
13. submit 13
14. Sinem approve 13
15. readback
16. A1 CLOSED

No rerun unless payload/preimage changes.

## Physical work location business truth (NO APPLY)

TARGET_LOCATION: calisma_lokasyonu_id = 5 (Fabrika / Karabük)
NO_APPLY_PREIMAGE: ops/organization-mapping/fabrika-karabuk-loc5-no-apply-preimage-plan.json

BUSINESS_TRUTH_RESOLVED / loc5 (pending future apply + live preimage guard):
200, 201, 203, 204, 205, 206, 209, 210, 212, 217

CLOSED_DO_NOT_REOPEN:
160 Sedanur Bulut → loc5
211 Zeynep Günal → loc5

NAME_CORRECTION_203 (NO APPLY): wrong display known MUHAMMED IRAKLI → Muhammed Mahmud
Schema fields only: personeller.ad / personeller.soyad (ad="Muhammed", soyad="Mahmud")
Plan: ops/organization-mapping/personel-203-name-correction-no-apply.json

## Branch manager — canonical product rule (LOCKED)

Assignment is authorization/responsibility via `users.rol=SUBE_YONETICISI` + `user_subeler`.
It is NOT personnel branch / physical location / company / SGK / departman / bolum / birim / gorev / pozisyon transfer.

PERSONNEL_HOME_BRANCH != PHYSICAL_WORK_LOCATION != MANAGED_BRANCH_ASSIGNMENTS

- Branch MAY have zero managers (not mandatory).
- Person MAY manage branch(es) different from home branch.
- Person MAY work at Fabrika/Karabük and manage other branches.
- Same person MAY manage multiple branches.
- Assigning manager of branch X MUST NOT mutate personel.sube_id / calisma_lokasyonu_id / company / sgk / org fields.
- MUST NOT invoke permanent branch transfer / imply physical presence at managed branch.
- General product rule for all branches (not Fabrika/GM special-case).

TECHNICAL_STATUS: ALREADY_SUPPORTED (no runtime code change this phase)
NO_APPLY_ASSIGNMENT_PLAN: ops/organization-mapping/branch-manager-assignment-no-apply-plan.json

### Medisa branch manager business map (NO WRITE)

| Branch | sube_id | Manager assignment intent |
| --- | --- | --- |
| Fabrika/Karabük | 1 | Sinem Hamaloğlu |
| Giresun | 2 | Halil Şenay |
| Kayseri | 4 | Kübra [SURNAME UNKNOWN — DO NOT GUESS] |
| İzmir | 12 | Halil Şenay |
| Ankara | 5 | no dedicated local; temporary/central = Sinem Hamaloğlu |
| İstanbul | 6 | no dedicated local; temporary/central = Sinem Hamaloğlu |
| Sakarya | 13 | no dedicated local; temporary/central = Sinem Hamaloğlu |

temporary/central manager uses the same canonical managed-branch assignment model if assigned.
Do not create fake local personnel transfer. Do not force a local manager.

## Residuals after business-truth lock

LOCATION_TARGETS_LOCKED_NO_APPLY: 200/201/203/204/205/206/209/210/212/217
CLOSED_DO_NOT_REOPEN: 160/211 (+ 202/208 historical exit)
CROSS_COMPANY_SEMANTICALLY_VALID_DEFER: 120 / 158 / 219
KAYSERI_MANAGER_IDENTITY: BUSINESS_IDENTITY_DECISION_REQUIRED (Kübra surname unresolved)
SINEM_LIVE_ACCOUNT: VERIFY_LIVE_REQUIRED
HALIL_LIVE_MANAGED_BRANCHES: VERIFY_LIVE_REQUIRED
NULL_LOCATION_HR_OTHER: do not force loc5 unless explicitly covered; refresh RO after host recovery
PERSONNEL_DECISION_PACK: ops/organization-mapping/personnel-residual-decision-pack.json

## FK / integrity (last authoritative RO inventory; not re-probed this turn)

FK_REFERENCE_INTEGRITY: PASS (all INVALID_REF=0 at inventory `34057486092` / related probes)
TECHNICAL_ANA_SISTEM: KAPALI
BUG_COUNT: 0
PRODUCTION_MUTATION_THIS_PIN: 0
DEPLOY: NONE
NEXT_GATE: HOSTING_RECOVERY_THEN_LIVE_PREIMAGE_VERIFY_AND_USER_APPROVED_APPLY
