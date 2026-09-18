CODE_MIGRATION_TIP: 089
PRODUCTION_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_PENDING: 2
PRODUCTION_DEPLOY_SHA: d96182a2a4b4cb5e9e6d7c867d3486d7ac4061b2
CODE_MAIN_SHA: d96182a2a4b4cb5e9e6d7c867d3486d7ac4061b2

# Live / code pin (2026-09-15 PERSONELMEDISA_FINAL_CLEANUP — SELECT-only; mutation=0)

CODE_MAIN advanced #275→#301. LIVE after #301 deploy: `d96182a2` (Deploy cPanel run `34936838710` SUCCESS; `FINAL_SHA_GET=SUCCESS` parity; canlı bundle `index-DY8cYDBX.js` / `index-Dl13eR2G.css`; anonim `smoke:live` OK). Earlier live pins this sweep: `34b2fd30` (#300), `ef2c8db5` (#299), `eb8aa517`, `f5551160` (#277), `63f8c905` (PR274 recovery deploy).
HOSTING: RECOVERED (FTP/API healthy). Ordered merge/deploy sweep CLOSED through #301 — kuyrukta merge/deploy yok.
CODE tip **089** (`089_personel_legacy_account_activation.sql`); PRODUCTION tip **087**; pending **088** + **089**.
PHASE: PERSONEL_ACCOUNT_CANONICAL_UNIFICATION
APPLIED_THIS_TURN: NO (migration 089 yalniz code; production apply ayrı explicit onay ister)

## Hard-closed (do not reopen without new contradiction)

PR_271: CLOSED
PR_272: CLOSED
PR_274: MERGED / CI PASS / DEPLOYED_RECOVERY (63f8c905); do not reopen code unless concrete regression
PR_299: MERGED / CI PASS / DEPLOYED (ef2c8db5, run 34905632936) — multi-axis bulk preimage rebase; CLOSED, do not reopen
PR_300: MERGED / CI PASS / DEPLOYED (34b2fd30, run 34934712615) — bagli amir context via users.personel_id; CLOSED, do not reopen
PR_301: MERGED / CI PASS / DEPLOYED (d96182a2, run 34936838710) — bagli amir create-flow resolution via users.personel_id; CLOSED, do not reopen
PERSONNEL_MASTER_DATA_REMEDIATION: APPLIED_14_OF_14 (dry-run + apply + readback PASS; plan artifact consumed, residual apply yok)
MIGRATION_087: APPLIED
MIGRATION_088: CODE_ONLY_PENDING (pending apply)
PERSONNEL_202_208_HISTORICAL_EXIT: CLOSED_CONFIRMED
A2_LOCATION_160_211: APPLIED (Sedanur Bulut 160 / Zeynep Günal 211 → calisma_lokasyonu_id=5 + bagli_amir_id=110 under ef2c8db5 / PR #299; reopen YOK)
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

## Physical work location business truth (APPLIED — eski NO_APPLY notları uzlaştırıldı)

TARGET_LOCATION: calisma_lokasyonu_id = 5 (Fabrika / Karabük)
STATUS: APPLIED — eski "NO APPLY / pending future apply + live preimage guard" ifadeleri ARTIK GEÇERSİZ (2026-09-14/15 apply kanıtı).
HISTORICAL_NO_APPLY_PREIMAGE: ops/organization-mapping/fabrika-karabuk-loc5-no-apply-preimage-plan.json (tarihsel artifact; apply tamamlandı)
APPLY_EVIDENCE: dry-run + apply + bağımsız readback PASS; plan artifact'i (untracked, production/business veri içeriyordu) cleanup kapsamında silindi.

APPLIED_ROWS_14_OF_14: 143, 160, 173, 200, 201, 203, 204, 205, 206, 209, 210, 211, 213, 218
- run-1 12 satır @ `eb8aa517` (Deploy run 34889636597); residual 160/211 @ `ef2c8db5` (PR #299 deploy); failure_count 0
- 160 Sedanur Bulut: calisma_lokasyonu_id null → 5, bagli_amir_id null → 110, birim_id null → 24
- 211 Zeynep Günal: calisma_lokasyonu_id null → 5, bagli_amir_id 10 → 110, birim_id → 'İnsan Kaynakları' (RESOLVED_EXISTING)
- 210 FAHRİ TAYLAN MERCAN: calisma_lokasyonu_id 5 → 3 (İstanbul) — eski listedeki "210 → loc5 bekliyor" ifadesi YANLIŞTI
- 203 / 205 / 206 / 209: yalnız org alanları (departman/bolum/birim/gorev) — loc5 hedefi yoktu
- 143 / 213: calisan_kapsami IC_PERSONEL → DIS_KAYNAK (org/lokasyon değişimi yok; 213 sgk_isveren_id 1 DO_NOT_TOUCH)

CLOSED_DO_NOT_REOPEN (artık "pending apply" değil, APPLIED):
160 Sedanur Bulut → loc5 (APPLIED @ ef2c8db5)
211 Zeynep Günal → loc5 (APPLIED @ ef2c8db5)

NOT_APPLIED_BY_DESIGN: 212 (İlker AKEL), 217 (Görkem Vural) — truth dışı bulgu, dokunulmadı
NO_OP_NO_MUTATION: 214, 215, 216 (target = mevcut durum)

NAME_CORRECTION_203 (hâlâ PLAN_ONLY_NO_APPLY): kayıtlı görünen ad MUHAMMED IRAKLI → hedef Muhammed Mahmud
Schema fields only: personeller.ad / personeller.soyad (ad="Muhammed", soyad="Mahmud"); master-data paketinde 203 için yalnız org alanları apply edildi, ad/soyad mutasyonu yapılmadı
Plan: ops/organization-mapping/personel-203-name-correction-no-apply.json

## Branch manager — canonical product rule (LOCKED)

USER_SUBELER_SEMANTIC: ACCESS_SCOPE_ONLY
BRANCH_MANAGER_OWNER: sube_sorumlu_yoneticiler (OrganizasyonService / migration 088)
A1_SCOPE_MODEL: unchanged (`user_subeler` formal SGK scope)
Managed-branch responsibility is a separate durable business fact — NOT encoded as `user_subeler`.
Prior ALREADY_SUPPORTED / `replaceUserSubeler`-as-manager-owner claim is RETRACTED.
Canonical owner ships in PR **#278** (code tip **088**; production tip remains **087** until explicit apply).
It is NOT personnel branch / physical location / company / SGK / departman / bolum / birim / gorev / pozisyon transfer.

PERSONNEL_HOME_BRANCH != PHYSICAL_WORK_LOCATION != MANAGED_BRANCH_ASSIGNMENTS != GENERIC_BRANCH_ACCESS

- Branch MAY have zero managers (not mandatory).
- Person MAY manage branch(es) different from home branch.
- Person MAY work at Fabrika/Karabük and manage other branches.
- Same person MAY manage multiple branches.
- Assigning manager of branch X MUST NOT mutate personel.sube_id / calisma_lokasyonu_id / company / sgk / org fields / users.rol / user_subeler.
- MUST NOT invoke permanent branch transfer / imply physical presence at managed branch.
- General product rule for all branches (not Fabrika/GM special-case).
- No multi-role required; no forced demotion to SUBE_YONETICISI.

TECHNICAL_STATUS: TECHNICAL_GAP_LOCAL_FIXABLE → CLOSED_IN_CODE by PR #278 (088 pending production apply)
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

## Residuals after business-truth lock (2026-09-15 güncel)

PERSONNEL_MASTER_DATA_REMEDIATION: APPLIED_14_OF_14 — CLOSED (143/160/173/200/201/203/204/205/206/209/210/211/213/218)
LOCATION_TARGETS: APPLIED (200/201/203/204/205/206/209 reconciled; 210 → loc3 İstanbul; 212/217 NOT_APPLIED_BY_DESIGN; 214/215/216 NO-OP)
CLOSED_DO_NOT_REOPEN: 160/211 (APPLIED @ ef2c8db5 / PR #299) + 202/208 historical exit
CROSS_COMPANY_SEMANTICALLY_VALID_DEFER: 120 / 158 / 219
KAYSERI_MANAGER_IDENTITY: BUSINESS_IDENTITY_DECISION_REQUIRED (Kübra surname unresolved)
SINEM_LIVE_ACCOUNT: VERIFY_LIVE_REQUIRED
HALIL_LIVE_MANAGED_BRANCHES: VERIFY_LIVE_REQUIRED
NULL_LOCATION_HR_OTHER: do not force loc5 unless explicitly covered; hosting RECOVERED (2026-09-15) — gerekirse RO refresh
PERSONNEL_DECISION_PACK: ops/organization-mapping/personnel-residual-decision-pack.json

## FK / integrity (last authoritative RO inventory; not re-probed this turn)

FK_REFERENCE_INTEGRITY: PASS (all INVALID_REF=0 at inventory `34057486092` / related probes)
TECHNICAL_ANA_SISTEM: KAPALI
BUG_COUNT: 0
PRODUCTION_MUTATION_THIS_PIN: 0 (bu pin turu docs/cleanup only)
DEPLOY: d96182a2a4b4cb5e9e6d7c867d3486d7ac4061b2 (PR #301 otomatik deploy, Deploy run 34936838710 SUCCESS; deployed SHA == merge SHA)
NEXT_GATE: personnel/master-data remediation için AÇIK GATE YOK (#299/#300/#301 CLOSED); kalan açık işler A1 SGK (BUSINESS_LOCKED) ve identity doğrulamaları
