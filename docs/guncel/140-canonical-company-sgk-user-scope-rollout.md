# 140 — Canonical company / SGK user-scope rollout inventory

**Tür:** Rollout inventory + live readback. Historical PLAN pins below are preserved; **current/live** values are explicit.

## Canonical axes

| Axis | Table / field | Semantics |
| --- | --- | --- |
| Branch | `user_subeler` | Explicit physical branches only |
| Company | `user_sirketler` | Live `subeler` of granted companies (current + future); no duplicate `user_subeler` required |
| SGK / payroll | `user_sgk_isverenler` | Filters on `personeller.sgk_isveren_id`; never grants physical branch access |
| Identity binding | `users.personel_id` | PERSONEL self-service identity only — not manager authz |

Owner: `OrgScope` (+ `HrWriteScope` for `IK_PERSONELI` write companies). Session: `AuthMiddleware` / `LoginController`.

## Live production inventory (2026-09-07 hosting-incident pin)

| Object | Live state | Evidence |
| --- | --- | --- |
| `PRODUCTION_MIGRATION_TIP` | **087** | apply `34033315991`; inventory pending=0 |
| LIVE deploy SHA | `9b4aac7919100c5421544824b0401e5046ab621e` | Last successful deploy (PR274 not live) |
| CODE_MAIN SHA | `63f8c9052ca15c3f311e158b41b0afda09d2b874` | PR #274 MERGED / CI PASS / DEPLOY_HELD |
| `user_sirketler` | **3** | inventory `orginv-34057486092-1` |
| `user_sgk_isverenler` | **3** | same |
| `user_subeler` | **31** | MUHASEBE Medisa branch scope included |
| Work locations mapped | **7/7** | Medisa catalog mapping APPLIED |
| `sube_muhasebe_yetkilileri` | **0 rows** | ACL restriction DISABLED (intentional) |
| Karyapı / Şenay operational rollout | **INTENTIONAL_DEFER** | do not invent grants |
| A2 160/211 location | **CLOSED** | do not reopen |
| A1 SGK period 12/13 | **PAUSED_PENDING_HOST_RECOVERY_AND_SINEM_LIVE_VERIFY** | preparer `sedanurB` + approver Sinem Hamaloğlu locked; live verify + no apply while hosting incident |

Historical PLAN-only pins that said grants=0 / tip=086 / locations deferred are **not** current live truth.

## Approved Medisa user-scope (APPLIED — do not reopen)

| User | Live intent | Notes |
| --- | --- | --- |
| `ilkerA` | keep `GENEL_YONETICI` | No unnecessary explicit company/SGK grants required |
| `serhan.kose` | `GENEL_YONETICI` | Same |
| `sedanurB` | `IK_SORUMLUSU` + Medisa company/SGK | Applied |
| `zeynepG` | Medisa company/SGK | Applied |
| `muhasebe` | Medisa company/SGK + Medisa branches | Applied (incl. 12/13) |

## Explicit non-goals

- Location → authorization axis
- Silent Karyapı/Şenay → Medisa personnel overwrite
- QR / PERSONEL account late-phase as Priority A
- Reopening PR #271 / migration 087 without new contradiction

## Related residuals (Priority A — see CURRENT_STATE)

- SGK period policy missing for Medisa sube **12/13** (`NO_APPROVED_POLICY`)
- Personnel `calisma_lokasyonu_id` NULL = 16 (2 AUTO NO-APPLY candidates)
- Personnel `sube_id` NULL = 1 (personel 212)
