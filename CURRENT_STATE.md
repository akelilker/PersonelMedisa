CODE_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_TIP: 087
PRODUCTION_MIGRATION_PENDING: 0
PRODUCTION_DEPLOY_SHA: fcee67186f1ceaec86624ecbe745f51f56330bcd

# Live state pin (2026-09-06/07 evidence lock — SELECT-only; mutation=0)

Evidence sources: Deploy cPanel `34035970806` @ `fcee6718`; migration apply `34033315991` (087); inventory `orginv-34057486092-1` tip=087 pending=0; preflight PROD_TIP=087 PENDING=NONE; authenticated GET personnel/SGK policy readback.

PERSONEL_TOTAL: 153
PERSONEL_AKTIF: 144
PERSONEL_PASIF_ARCHIVE: 9
USERS_TOTAL: 147
PERSONEL_ROLE_USERS: 122 (all bound; unbound PERSONEL-role = 0)
ACTIVE_PERSONNEL_WITHOUT_ANY_USER_BINDING: 14 (exact ids 213–226; QR/self-service INTENTIONAL_DEFER — not Priority A)
USER_SIRKET_GRANTS: 3
USER_SGK_ISVEREN_GRANTS: 3
SUBE_MUHASEBE_ACL_ROW_COUNT: 0 (restriction DISABLED until operators seed)

## Hard-closed (do not reopen without new contradiction)

PR_271: CLOSED
MIGRATION_087: APPLIED / tip=087 / pending EMPTY
PR_270: CLOSED
MG_PERSONNEL_BULK_RECONCILIATION_PRODUCTION: CLOSED_CONFIRMED
PERSONNEL_202_208_HISTORICAL_EXIT: CLOSED_CONFIRMED
BORDRO_HAZIRLIK: CLOSED
MAAS_HESAPLAMA: CLOSED
MEDISA_WORK_LOCATION_CATALOG_MAPPING: APPLIED (7/7 lokasyon→şube; inventory orphan_lokasyon=0)
MEDISA_APPROVED_USER_GRANTS: APPLIED (sedanurB/zeynepG/muhasebe company+SGK; muhasebe Medisa branches)
KARYAPI_ROLLOUT: INTENTIONAL_DEFER
SENAY_ROLLOUT: INTENTIONAL_DEFER
QR_SELF_SERVICE: INTENTIONAL_DEFER

## A1 — SGK period policy sube 12 / 13

SGK_PERIOD_OWNER: SgkSirketPolitikaReadService::resolveForPeriod (branch-scoped; **no company inheritance**)
BRANCH_1_4_5_6_7_8_9_10_11: AY_1_SON_GUN / ONAYLANDI (control set still live)
BRANCH_12_IZMIR: RUNTIME_EFFECTIVE = NO_APPROVED_POLICY; revision inventory empty → TECHNICAL_GAP
BRANCH_13_SAKARYA: RUNTIME_EFFECTIVE = NO_APPROVED_POLICY; revision inventory empty → TECHNICAL_GAP
BUSINESS_DECISION: already 1_TO_MONTH_END / AY_1_SON_GUN for Medisa — seed/approve for 12/13 is ops gate, not reopen of company decision

## A2 / A3 residuals (exact fresh)

NULL_LOCATION_TOTAL: 16
NULL_LOCATION_BY_BRANCH: sube1=11, sube2=3, sube6=1, sube_null=1
AUTO_RESOLVABLE_LOCATION: 2 (personel_id 160 Sedanur Bulut, 211 Zeynep Günal → calisma_lokasyonu_id=5; NO-APPLY plan only)
HR_BUSINESS_DECISION_REQUIRED_LOCATION: 14
NULL_BRANCH_TOTAL: 1 (personel_id 212 İlker AKEL; location also NULL → HR_BUSINESS_DECISION_REQUIRED; CAN_APPLY=NO)
CROSS_COMPANY_AXIS_CONTRADICTION: 3
- 120 İsmail Özcan — branch 11 Şenay / SGK 3 / location 5→Medisa1
- 158 Salih Efe — branch 11 Şenay / SGK 3 / location 5→Medisa1
- 219 DOĞU BERKAN ATMACA — branch 10 Karyapı / SGK 1 / location 3→Medisa6
No silent Medisa overwrite; BUSINESS_DECISION_REQUIRED while company rollouts deferred.

NO_APPLY_PLAN: ops/organization-mapping/a1-a2-a3-no-apply-remediation-plan.json
PLAN_CAN_APPLY_COUNT: 2 (not applied this turn)

## FK matrix (fresh list API; INVALID_REF not join-probed)

| field | POPULATED | NULL | ACTIVE_NULL | PASIF_NULL |
| --- | ---: | ---: | ---: | ---: |
| sube_id | 152 | 1 | 1 | 0 |
| departman_id | 146 | 7 | 7 | 0 |
| bolum_id | 137 | 16 | 10 | 6 |
| birim_id | 137 | 16 | 10 | 6 |
| gorev_id | 141 | 12 | 12 | 0 |
| pozisyon_id | 139 | 14 | 8 | 6 |
| personel_tipi_id | 153 | 0 | 0 | 0 |
| sgk_isveren_id | 130 | 23 | 17 | 6 |
| calisma_lokasyonu_id | 137 | 16 | 12 | 4 |

EXPECTED_NULL / UNEXPECTED_NULL not asserted except where model already closed (DIS SGK non-financial / optional DIS org). Location/branch nulls above remain decision/remediation items.

## SUBE_YONETICISI (readback only)

MEDISA_BRANCHES: 1,2,4,5,6,12,13
BRANCHES_WITH_ACTIVE_SUBE_YONETICISI: 2 (user 040), 4 (user 381)
BRANCHES_WITHOUT_ACTIVE_SUBE_YONETICISI: 1,5,6,12,13
STATUS: BUSINESS_DECISION_REQUIRED (Priority B; not data cleanup)

## Priority rewrite (post evidence)

PRIORITY_A:
- A1 TECHNICAL_GAP — seed/approve AY_1_SON_GUN ONAYLANDI for Medisa sube 12 & 13
- A2 DATA_REMEDIATION_READY_FOR_APPROVAL (2 AUTO) + BUSINESS_DECISION_REQUIRED (14)
- A3 BUSINESS_DECISION_REQUIRED (personel 212)

PRIORITY_B:
- SUBE_YONETICISI remaining Medisa branches
- Cross-company contradiction residuals (120/158/219) when defer ends or purity required

PRIORITY_C:
- PERSONEL accounts / QR / self-service INTENTIONAL_DEFER
- Karyapı / Şenay company rollout INTENTIONAL_DEFER

ORG_MAPPING_INVENTORY_OWNER: OrganizationMappingInventoryReport (SELECT-only)
ORG_LOCATION_SUBE_MAPPING: APPLIED (catalog); personnel location fill = separate A2 gate
SGK_CATALOG_LIVE_VERIFY: CLOSED_CONFIRMED
UBGT_CALENDAR_LIVE_VERIFY: CLOSED_CONFIRMED
PAYROLL_POLICY_REVISION: 3 / 14/14 / HAFTA_TATILI_GUNLERI=0
TEKNIK_ANA_SISTEM: KAPALI
BUG_COUNT: 0
PRODUCTION_MUTATION_THIS_PIN: 0
APPLICATION_CODE_CHANGE_THIS_PIN: 0
